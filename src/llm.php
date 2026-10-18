<?php
namespace Jambura;

use Jambura\LLM\CacheThreadStore;
use Jambura\LLM\ThreadStore;
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
     * rather than per adapter, because a history belongs to the thread id and
     * not to whichever model happened to answer last.
     * @var ThreadStore|null
     */
    private static ?ThreadStore $threadStore = null;

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
     * How many of a thread's most recent messages chat() sends with each prompt.
     * Older messages stay in the store; they are simply not sent.
     *
     * Messages, not tokens: a token budget wants a compressor to fall back on, and
     * that is not written yet. Set it to 0 for an adapter that should answer
     * each question on its own.
     * @var int
     */
    protected int $keepMessages = 10;

    /**
     * Whether handlePrompt() renders the prompt's messages into the `conversation`
     * context section.
     *
     * Leave it on for an adapter whose API takes one block of text: it is what
     * lets an adapter written before messages existed still see a conversation.
     * Turn it off in an adapter that reads getMessages() in send() and builds a
     * message list, or the history goes to the model twice — once as messages
     * and once as prose — at twice the tokens.
     * @var bool
     */
    protected bool $renderMessages = true;

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
     *                      or has an invalid $order or $keepMessages
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
            self::checkKeepMessages($class, $defaults['keepMessages']);
            self::checkRenderMessages($class, $defaults);
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
     *     \Jambura\LLM::storeThreadsIn(new DatabaseChatStore());
     *
     * Calling it again replaces the store. Leave it out entirely and chat()
     * falls back on CacheThreadStore, which works with no wiring but keeps every
     * history in one cache file — read what that costs before relying on it.
     */
    public static function storeThreadsIn(ThreadStore $store): void
    {
        self::$threadStore = $store;
    }

    /**
     * Drops the configured store, so the next chat() falls back on the default.
     *
     * Here for tests, which need each case to start from a known store.
     */
    public static function forgetThreadStore(): void
    {
        self::$threadStore = null;
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
     * Answers a prompt with the conversation stored under a thread id, and
     * stores the exchange.
     *
     *     $reply = \Jambura\LLM::use(\AIModel\Deepseek::class)->chat($prompt, $threadId);
     *
     * This is prompt() with history either side of it: the stored messages go onto
     * a copy of the prompt, prompt() renders and sends it exactly as it always
     * does, and the question and reply are appended afterwards. An adapter's
     * $format, $order and send() do not change, and need not know chat() exists.
     *
     * A thread id nothing has been stored under simply starts a new history,
     * so there is no start-or-resume decision for a caller to get wrong. The id
     * is the caller's to choose: a user id, a chat row id, a uuid handed to the
     * browser.
     *
     * The prompt the caller holds is never touched — the messages go onto a copy —
     * so one prompt can be chatted twice without collecting history as it goes.
     *
     * Nothing is stored unless the call succeeds. If send() throws, neither the
     * question nor a reply is kept, so a retry on the same thread id does not
     * find half an exchange waiting for it.
     *
     * What is stored is the task and the reply, and only those. Messages the caller
     * added to the prompt by hand are sent, after the stored history, but are
     * not themselves written to the store: they belong to this call.
     *
     * @param string $threadId the conversation this prompt belongs to
     *
     * @throws LLMException if the thread id is blank, the prompt has no task,
     *                      the store returns a message with an unknown role, or
     *                      the API call fails
     */
    final public function chat(Prompt $prompt, string $threadId): string
    {
        $threadId = $this->checkThreadId($threadId);
        $store = self::threads();

        // A copy, so that the caller's prompt is left as it was and calling this
        // twice cannot load the same history twice.
        $copy = (clone $prompt)->loadConversation($store->read($threadId)->recent($this->keepMessages));

        $reply = $this->prompt($copy);

        $store->append($threadId, [
            ['role' => Prompt::USER, 'text' => $copy->getTask(), 'author' => null],
            ['role' => Prompt::ASSISTANT, 'text' => $reply, 'author' => null],
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
     * Any messages on the prompt are rendered into the `conversation` context
     * section, so that an adapter which ignores getMessages() still sees what was
     * said. Set $renderMessages to false in an adapter that reads the messages itself.
     *
     * Note what this ties together: an adapter whose $order leaves out `context`
     * renders no conversation either, and if it does not read getMessages(), chat()
     * quietly becomes prompt() with extra bookkeeping. Leave `context` out only
     * when send() handles the messages.
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
                'context' => $this->renderMessages ? $this->contextWithConversation($prompt) : $prompt->getContext(),
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
    /**
     * The store conversations are kept in, building the default on first ask.
     *
     * Here so that a caller can read or drop a conversation - chat() returns
     * only the reply - without the framework growing pass-through methods for
     * what the store already does:
     *
     *     LLM::threads()->read($threadId)->messages();
     *     LLM::threads()->forget($threadId);
     */
    public static function threads(): ThreadStore
    {
        return self::$threadStore ??= new CacheThreadStore();
    }


    /**
     * The prompt's context, with its messages rendered into `conversation`.
     *
     * Messages are appended after anything the caller put in that section itself,
     * which leaves a hand-written summary of an older conversation sitting in
     * front of the messages it summarises.
     *
     * @return array<string, string[]>
     */
    private function contextWithConversation(Prompt $prompt): array
    {
        $context = $prompt->getContext();
        $messages = $prompt->getMessages();
        if (!$messages) {
            return $context;
        }

        $spoken = [];
        foreach ($messages as $message) {
            // The author when there is one, so a group conversation reads as who
            // said what; the role otherwise, so the model can tell its own lines.
            $speaker = $message['author'] ?? null;
            $spoken[] = ($speaker === null || trim($speaker) === '' ? ucfirst($message['role']) : $speaker)
                . ': ' . $message['text'];
        }
        $context['conversation'] = array_merge($context['conversation'] ?? [], $spoken);

        return $context;
    }

    /**
     * @throws LLMException if the thread id is blank
     */
    private function checkThreadId(string $threadId): string
    {
        if (trim($threadId) === '') {
            throw new LLMException('A chat needs a thread id');
        }
        return $threadId;
    }

    /**
     * Rejects an adapter that would render a conversation nowhere.
     *
     * handlePrompt() renders messages into the `conversation` context section, so
     * an adapter that renders them while leaving `context` out of $order sends no
     * conversation at all - and if it does not read getMessages() in send()
     * either, chat() quietly becomes prompt() while the store keeps growing.
     * That is a definition mistake, so it fails where the adapter is registered.
     *
     * @param array $defaults the class's default property values
     *
     * @throws LLMException if messages are rendered but `context` is not ordered
     */
    private static function checkRenderMessages(string $class, array $defaults): void
    {
        if ($defaults['renderMessages'] && !in_array('context', $defaults['order'], true)) {
            throw new LLMException(
                "$class renders messages into the `context` section, but its \$order leaves "
                . 'context out, so a conversation would go nowhere. Order context, or set '
                . '$renderMessages to false and read getMessages() in send()'
            );
        }
    }

    /**
     * @throws LLMException if $keepMessages is negative
     */
    private static function checkKeepMessages(string $class, int $keepMessages): void
    {
        if ($keepMessages < 0) {
            throw new LLMException(
                "$class::\$keepMessages cannot be negative. Use 0 to send no history"
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
