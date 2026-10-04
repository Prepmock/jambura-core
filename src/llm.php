<?php
namespace Jambura;

use Jambura\LLM\CacheChatStore;
use Jambura\LLM\ChatStore;
use Jambura\LLM\Format;
use Jambura\LLM\LLMException;
use Jambura\LLM\Prompt;

/**
 * Registry of model adapters, and the base class each adapter extends.
 *
 * The framework ships no adapters. An application extends this class once per
 * model it uses, registers those classes, then resolves one by class name:
 *
 *     \Jambura\LLM::registerModels([\AIModel\Claude::class, \AIModel\Deepseek::class]);
 *     \Jambura\LLM::use(\AIModel\Claude::class)->prompt($prompt);
 *
 * An adapter implements send(), and may set $format and $order to change how
 * the prompt is rendered. Each adapter is built once, on its first use(), and
 * reused after that.
 */
abstract class LLM
{
    /**
     * Sections an adapter can list in $order. The task is not one of them:
     * it is always rendered, and always last.
     */
    const SECTIONS = ['role', 'context', 'instructions'];

    /**
     * Registered adapter classes, keyed by lower-cased class name. The value is
     * the adapter instance once use() has built it, null before that.
     * @var array<string, LLM|null>
     */
    private static array $models = [];

    /**
     * Where chat() keeps conversations, or null until one is asked for. Global
     * rather than per adapter, because a history belongs to the session id and
     * not to whichever model happened to answer last.
     * @var ChatStore|null
     */
    private static ?ChatStore $chatStore = null;

    /**
     * Model id sent to the provider's API.
     * @var string
     */
    protected string $model = '';

    /**
     * Format handlePrompt() renders the prompt in.
     * @var Format
     */
    protected Format $format = Format::Xml;

    /**
     * Sections handlePrompt() renders before the task, in this order. Leave a
     * section out to keep it out of the formatted prompt, for example a role
     * that send() puts in the API's system field.
     * @var string[]
     */
    protected array $order = self::SECTIONS;

    /**
     * How many of a session's most recent turns chat() sends with each prompt.
     * Older turns stay in the store; they are simply not sent.
     *
     * Turns, not tokens: a token budget wants a compressor to fall back on, and
     * that is not written yet. Set it to 0 for an adapter that should answer
     * each question on its own.
     * @var int
     */
    protected int $keepTurns = 10;

    /**
     * Whether handlePrompt() renders the prompt's turns into the `conversation`
     * context section.
     *
     * Leave it on for an adapter whose API takes one block of text: it is what
     * lets an adapter written before turns existed still see a conversation.
     * Turn it off in an adapter that reads getTurns() in send() and builds a
     * message list, or the history goes to the model twice — once as messages
     * and once as prose — at twice the tokens.
     * @var bool
     */
    protected bool $renderTurns = true;

    /**
     * Adapters are built by use(), never with `new`.
     */
    final protected function __construct()
    {
    }

    /**
     * Registers adapter classes so use() can resolve them.
     *
     * Checks each class up front, so a mistake fails at registration rather
     * than on the first prompt. Registering a class again keeps its instance.
     *
     * @param string[] $classes adapter class names
     *
     * @throws LLMException if a class does not exist, is not a concrete LLM,
     *                      or has an invalid $order or $keepTurns
     */
    public static function registerModels(array $classes): void
    {
        foreach ($classes as $class) {
            $class = ltrim($class, '\\');
            if (!class_exists($class)) {
                throw new LLMException("Model class $class does not exist");
            }
            $reflection = new \ReflectionClass($class);
            if (!$reflection->isSubclassOf(self::class) || $reflection->isAbstract()) {
                throw new LLMException("Model class $class must be a concrete subclass of " . self::class);
            }
            $defaults = $reflection->getDefaultProperties();
            self::checkOrder($class, $defaults['order']);
            self::checkKeepTurns($class, $defaults['keepTurns']);
            self::$models[strtolower($class)] ??= null;
        }
    }

    /**
     * Returns the adapter for a registered class, building it on first use.
     *
     * @param string $class adapter class name
     *
     * @throws LLMException if the class has not been registered
     */
    public static function use(string $class): LLM
    {
        $class = ltrim($class, '\\');
        $key = strtolower($class);
        if (!array_key_exists($key, self::$models)) {
            throw new LLMException(
                "Model class $class is not registered. Call " . self::class . '::registerModels() first'
            );
        }
        return self::$models[$key] ??= new $class();
    }

    /**
     * Unregisters every adapter and drops the built instances.
     */
    public static function forgetModels(): void
    {
        self::$models = [];
    }

    /**
     * Tells chat() where to keep conversations.
     *
     * One line, where the application boots:
     *
     *     \Jambura\LLM::storeChatsIn(new DatabaseChatStore());
     *
     * Calling it again replaces the store. Leave it out entirely and chat()
     * falls back on CacheChatStore, which works with no wiring but keeps every
     * history in one cache file — read what that costs before relying on it.
     */
    public static function storeChatsIn(ChatStore $store): void
    {
        self::$chatStore = $store;
    }

    /**
     * Drops the configured store, so the next chat() falls back on the default.
     *
     * Here for tests, which need each case to start from a known store.
     */
    public static function forgetChatStore(): void
    {
        self::$chatStore = null;
    }

    /**
     * Every turn stored under a session id, oldest first.
     *
     * chat() returns only the reply, which is what a caller usually wants to
     * print. This is for one that wants to render the whole thread.
     *
     * @return list<array{role: string, text: string}>
     *
     * @throws LLMException if the session id is blank
     */
    public static function chatTurns(string $sessionId): array
    {
        return self::chatStore()->turns(self::checkSessionId($sessionId));
    }

    /**
     * Drops a conversation, so the next chat() on that id starts fresh.
     *
     * @throws LLMException if the session id is blank
     */
    public static function forgetChat(string $sessionId): void
    {
        self::chatStore()->forget(self::checkSessionId($sessionId));
    }

    /**
     * Sends a prompt to the model and returns its text reply.
     *
     * @throws LLMException if the prompt has no task, or the API call fails
     */
    final public function prompt(Prompt $prompt): string
    {
        if ($prompt->getTask() === null || trim($prompt->getTask()) === '') {
            throw new LLMException('A prompt needs a task');
        }
        return $this->send($this->handlePrompt($prompt), $prompt);
    }

    /**
     * Answers a prompt with the conversation stored under a session id, and
     * stores the exchange.
     *
     *     $reply = \Jambura\LLM::use(\AIModel\Deepseek::class)->chat($prompt, $sessionId);
     *
     * This is prompt() with history either side of it: the stored turns go onto
     * a copy of the prompt, prompt() renders and sends it exactly as it always
     * does, and the question and reply are appended afterwards. An adapter's
     * $format, $order and send() do not change, and need not know chat() exists.
     *
     * A session id nothing has been stored under simply starts a new history,
     * so there is no start-or-resume decision for a caller to get wrong. The id
     * is the caller's to choose: a user id, a chat row id, a uuid handed to the
     * browser.
     *
     * The prompt the caller holds is never touched — the turns go onto a copy —
     * so one prompt can be chatted twice without collecting history as it goes.
     *
     * Nothing is stored unless the call succeeds. If send() throws, neither the
     * question nor a reply is kept, so a retry on the same session id does not
     * find half an exchange waiting for it.
     *
     * What is stored is the task and the reply, and only those. Turns the caller
     * added to the prompt by hand are sent, after the stored history, but are
     * not themselves written to the store: they belong to this call.
     *
     * @param string $sessionId the conversation this prompt belongs to
     *
     * @throws LLMException if the session id is blank, the prompt has no task,
     *                      the store returns a turn with an unknown role, or
     *                      the API call fails
     */
    final public function chat(Prompt $prompt, string $sessionId): string
    {
        $sessionId = self::checkSessionId($sessionId);
        $store = self::chatStore();

        // Turns the caller added by hand follow the stored history, because the
        // history is older: clearTurns() on the copy, then both in order.
        $added = $prompt->getTurns();
        $copy = (clone $prompt)->clearTurns();
        foreach ([...$this->recentTurns($store->turns($sessionId)), ...$added] as $turn) {
            $copy->addTurn($turn['role'], $turn['text']);
        }

        $reply = $this->prompt($copy);

        $store->append($sessionId, [
            ['role' => Prompt::USER, 'text' => $copy->getTask()],
            ['role' => Prompt::ASSISTANT, 'text' => $reply],
        ]);

        return $reply;
    }

    /**
     * Overrides the adapter's default model id.
     */
    public function setModel(string $model): static
    {
        $this->model = $model;
        return $this;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Renders the sections in $order, then the task, in $format.
     *
     * Empty sections are left out, and the prompt's type is never rendered.
     * Override this only for a format the Format enum does not cover.
     *
     * Any turns on the prompt are rendered into the `conversation` context
     * section, so that an adapter which ignores getTurns() still sees what was
     * said. Set $renderTurns to false in an adapter that reads the turns itself.
     *
     * Note what this ties together: an adapter whose $order leaves out `context`
     * renders no conversation either, and if it does not read getTurns(), chat()
     * quietly becomes prompt() with extra bookkeeping. Leave `context` out only
     * when send() handles the turns.
     *
     * @throws LLMException if $order is invalid
     */
    protected function handlePrompt(Prompt $prompt): string
    {
        self::checkOrder(static::class, $this->order);

        $sections = [];
        foreach ($this->order as $name) {
            $value = match ($name) {
                'role' => $prompt->getRole(),
                'context' => $this->renderTurns ? self::contextWithTurns($prompt) : $prompt->getContext(),
                'instructions' => $prompt->getInstructions(),
            };
            if ($value !== null && $value !== '' && $value !== []) {
                $sections[$name] = $value;
            }
        }
        $sections['task'] = $prompt->getTask();

        return match ($this->format) {
            Format::Xml => self::toXml($sections),
            Format::Json => json_encode(
                $sections,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
            Format::Text => self::toText($sections),
        };
    }

    /**
     * Sends the formatted prompt to the model and returns its reply text.
     *
     * Put $formattedPrompt into the request the model's API expects. $prompt
     * is there for sections left out of $order, such as a role the API takes
     * as a separate system field.
     *
     * @param string $formattedPrompt the output of handlePrompt()
     * @param Prompt $prompt          the prompt it was rendered from
     *
     * @throws LLMException if the call fails or returns no text
     */
    abstract protected function send(string $formattedPrompt, Prompt $prompt): string;

    /**
     * The store chat() uses, building the cache-backed default on first ask.
     */
    private static function chatStore(): ChatStore
    {
        return self::$chatStore ??= new CacheChatStore();
    }

    /**
     * The most recent $keepTurns turns, or all of them if there are fewer.
     *
     * @param list<array{role: string, text: string}> $turns oldest first
     *
     * @return list<array{role: string, text: string}>
     */
    private function recentTurns(array $turns): array
    {
        // Checked rather than left to array_slice, which reads an offset of -0
        // as 0 and would hand back the whole history for an adapter that asked
        // for none of it.
        if ($this->keepTurns === 0) {
            return [];
        }
        return count($turns) > $this->keepTurns ? array_slice($turns, -$this->keepTurns) : $turns;
    }

    /**
     * The prompt's context, with its turns rendered into `conversation`.
     *
     * Turns are appended after anything the caller put in that section itself,
     * which leaves a hand-written summary of an older conversation sitting in
     * front of the turns it summarises.
     *
     * @return array<string, string[]>
     */
    private static function contextWithTurns(Prompt $prompt): array
    {
        $context = $prompt->getContext();
        $turns = $prompt->getTurns();
        if (!$turns) {
            return $context;
        }

        $spoken = [];
        foreach ($turns as $turn) {
            $spoken[] = ucfirst($turn['role']) . ': ' . $turn['text'];
        }
        $context['conversation'] = array_merge($context['conversation'] ?? [], $spoken);

        return $context;
    }

    /**
     * @throws LLMException if the session id is blank
     */
    private static function checkSessionId(string $sessionId): string
    {
        if (trim($sessionId) === '') {
            throw new LLMException('A chat needs a session id');
        }
        return $sessionId;
    }

    /**
     * @throws LLMException if $keepTurns is negative
     */
    private static function checkKeepTurns(string $class, int $keepTurns): void
    {
        if ($keepTurns < 0) {
            throw new LLMException(
                "$class::\$keepTurns cannot be negative. Use 0 to send no history"
            );
        }
    }

    /**
     * @throws LLMException if $order names anything but SECTIONS, or repeats one
     */
    private static function checkOrder(string $class, array $order): void
    {
        $unknown = array_diff($order, self::SECTIONS);
        if ($unknown) {
            throw new LLMException(
                "$class::\$order has unknown sections: " . implode(', ', $unknown)
                . '. Use ' . implode(', ', self::SECTIONS) . '; the task is always rendered last'
            );
        }
        if (count($order) !== count(array_unique($order))) {
            throw new LLMException("$class::\$order lists a section more than once");
        }
    }

    /**
     * @param array $sections section name => value, task last
     */
    private static function toXml(array $sections): string
    {
        $lines = [];
        foreach ($sections as $name => $value) {
            if ($name === 'context') {
                $lines[] = '<context>';
                foreach ($value as $section => $items) {
                    $lines[] = "  <$section>";
                    foreach ($items as $item) {
                        $lines[] = '    <item>' . self::escapeXml($item) . '</item>';
                    }
                    $lines[] = "  </$section>";
                }
                $lines[] = '</context>';
            } elseif ($name === 'instructions') {
                $lines[] = '<instructions>';
                foreach ($value as $instruction) {
                    $lines[] = '  <instruction>' . self::escapeXml($instruction) . '</instruction>';
                }
                $lines[] = '</instructions>';
            } else {
                $lines[] = "<$name>" . self::escapeXml($value) . "</$name>";
            }
        }
        return implode("\n", $lines);
    }

    private static function escapeXml(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array $sections section name => value, task last
     */
    private static function toText(array $sections): string
    {
        $blocks = [];
        foreach ($sections as $name => $value) {
            if ($name === 'context') {
                foreach ($value as $section => $items) {
                    $blocks[] = "Context ($section):\n- " . implode("\n- ", $items);
                }
            } elseif ($name === 'instructions') {
                $blocks[] = "Instructions:\n- " . implode("\n- ", $value);
            } else {
                $blocks[] = ucfirst($name) . ":\n" . $value;
            }
        }
        return implode("\n\n", $blocks);
    }
}
