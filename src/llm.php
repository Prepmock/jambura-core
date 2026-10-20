<?php
namespace Jambura;

use Jambura\LLM\CacheThreadStore;
use Jambura\LLM\Format;
use Jambura\LLM\LLMException;
use Jambura\LLM\Prompt;
use Jambura\LLM\ThreadStore;

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
     * @var array
     */
    private static $models = [];

    /**
     * Where chat() keeps conversations, or null until one is asked for. Global
     * rather than per adapter, because a history belongs to the thread id and
     * not to whichever model happened to answer last.
     * @var ThreadStore|null
     */
    private static $threadStore = null;

    /**
     * Model id sent to the provider's API.
     * @var string
     */
    protected $model = '';

    /**
     * Format handlePrompt() renders the prompt in, one of the Format constants.
     * @var string
     */
    protected $format = Format::XML;

    /**
     * Sections handlePrompt() renders before the task, in this order. Leave a
     * section out to keep it out of the formatted prompt, for example a role
     * that send() puts in the API's system field.
     * @var string[]
     */
    protected $order = self::SECTIONS;

    /**
     * How many of a thread's most recent messages chat() sends with each prompt.
     * Older messages stay in the store; they are simply not sent.
     *
     * Messages, not tokens: a token budget wants a compressor to fall back on, and
     * that is not written yet. Set it to 0 for an adapter that should answer
     * each question on its own.
     * @var int
     */
    protected $keepMessages = 10;

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
    protected $renderMessages = true;

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
     *                      or has an invalid $order, $format or $keepMessages
     */
    public static function registerModels(array $classes)
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
            self::checkFormat($class, $defaults['format']);
            self::checkKeepMessages($class, $defaults['keepMessages']);
            self::checkRenderMessages($class, $defaults);

            $key = strtolower($class);
            if (!array_key_exists($key, self::$models)) {
                self::$models[$key] = null;
            }
        }
    }

    /**
     * Returns the adapter for a registered class, building it on first use.
     *
     * @param string $class adapter class name
     * @return LLM
     *
     * @throws LLMException if the class has not been registered
     */
    public static function use($class)
    {
        $class = ltrim($class, '\\');
        $key = strtolower($class);
        if (!array_key_exists($key, self::$models)) {
            throw new LLMException(
                "Model class $class is not registered. Call " . self::class . '::registerModels() first'
            );
        }
        if (self::$models[$key] === null) {
            self::$models[$key] = new $class();
        }
        return self::$models[$key];
    }

    /**
     * Unregisters every adapter and drops the built instances.
     */
    public static function forgetModels()
    {
        self::$models = [];
    }

    /**
     * Tells chat() where to keep conversations.
     *
     * One line, where the application boots:
     *
     *     \Jambura\LLM::storeThreadsIn(new DatabaseThreadStore());
     *
     * Calling it again replaces the store. Leave it out entirely and chat()
     * falls back on CacheThreadStore, which works with no wiring but keeps every
     * history in one cache file — read what that costs before relying on it.
     *
     * @return void
     */
    public static function storeThreadsIn(ThreadStore $store)
    {
        self::$threadStore = $store;
    }

    /**
     * Drops the configured store, so the next chat() falls back on the default.
     *
     * Here for tests, which need each case to start from a known store.
     *
     * @return void
     */
    public static function forgetThreadStore()
    {
        self::$threadStore = null;
    }

    /**
     * The store conversations are kept in, building the default on first ask.
     *
     * Here so that a caller can read or drop a conversation - chat() returns
     * only the reply - without the framework growing pass-through methods for
     * what the store already does:
     *
     *     \Jambura\LLM::threads()->read($threadId)->messages();
     *     \Jambura\LLM::threads()->forget($threadId);
     *
     * @return ThreadStore
     */
    public static function threads()
    {
        if (self::$threadStore === null) {
            self::$threadStore = new CacheThreadStore();
        }
        return self::$threadStore;
    }

    /**
     * Sends a prompt to the model and returns its text reply.
     *
     * @return string
     *
     * @throws LLMException if the prompt has no task, or the API call fails
     */
    final public function prompt(Prompt $prompt)
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
     * @return string
     *
     * @throws LLMException if the thread id is blank, the prompt has no task,
     *                      the store returns a message with an unknown role, or
     *                      the API call fails
     */
    final public function chat(Prompt $prompt, $threadId)
    {
        $threadId = $this->checkThreadId($threadId);
        $store = self::threads();

        // A copy, so that the caller's prompt is left as it was and calling this
        // twice cannot load the same history twice.
        $copy = clone $prompt;
        $copy->loadConversation($store->read($threadId)->recent($this->keepMessages));

        $reply = $this->prompt($copy);

        $store->append($threadId, [
            ['role' => Prompt::USER, 'text' => $copy->getTask(), 'author' => null],
            ['role' => Prompt::ASSISTANT, 'text' => $reply, 'author' => null],
        ]);

        return $reply;
    }

    /**
     * Overrides the adapter's default model id.
     *
     * @param string $model
     * @return $this
     */
    public function setModel($model)
    {
        $this->model = $model;
        return $this;
    }

    /**
     * @return string
     */
    public function getModel()
    {
        return $this->model;
    }

    /**
     * Renders the sections in $order, then the task, in $format.
     *
     * Empty sections are left out, and the prompt's type is never rendered.
     * Override this only for a format the Format class does not cover.
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
     * @return string
     *
     * @throws LLMException if $order or $format is invalid
     */
    protected function handlePrompt(Prompt $prompt)
    {
        self::checkOrder(static::class, $this->order);
        self::checkFormat(static::class, $this->format);

        $sections = [];
        foreach ($this->order as $name) {
            if ($name === 'role') {
                $value = $prompt->getRole();
            } elseif ($name === 'context') {
                $value = $this->renderMessages
                    ? $this->contextWithConversation($prompt)
                    : $prompt->getContext();
            } else {
                $value = $prompt->getInstructions();
            }
            if ($value !== null && $value !== '' && $value !== []) {
                $sections[$name] = $value;
            }
        }
        $sections['task'] = $prompt->getTask();

        if ($this->format === Format::JSON) {
            return self::toJson($sections);
        }
        if ($this->format === Format::TEXT) {
            return self::toText($sections);
        }
        return self::toXml($sections);
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
     * @return string
     *
     * @throws LLMException if the call fails or returns no text
     */
    abstract protected function send($formattedPrompt, Prompt $prompt);

    /**
     * The prompt's context, with its messages rendered into `conversation`.
     *
     * Messages are appended after anything the caller put in that section itself,
     * which leaves a hand-written summary of an older conversation sitting in
     * front of the messages it summarises.
     *
     * @return array section name => items
     */
    private function contextWithConversation(Prompt $prompt)
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
            $speaker = isset($message['author']) ? $message['author'] : null;
            $spoken[] = ($speaker === null || trim($speaker) === '' ? ucfirst($message['role']) : $speaker)
                . ': ' . $message['text'];
        }
        $existing = isset($context['conversation']) ? $context['conversation'] : [];
        $context['conversation'] = array_merge($existing, $spoken);

        return $context;
    }

    /**
     * @param string $threadId
     * @return string
     *
     * @throws LLMException if the thread id is blank
     */
    private function checkThreadId($threadId)
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
     * @param string $class
     * @param array  $defaults the class's default property values
     *
     * @throws LLMException if messages are rendered but `context` is not ordered
     */
    private static function checkRenderMessages($class, array $defaults)
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
     * @param string $class
     * @param int    $keepMessages
     *
     * @throws LLMException if $keepMessages is negative
     */
    private static function checkKeepMessages($class, $keepMessages)
    {
        if ($keepMessages < 0) {
            throw new LLMException(
                "$class::\$keepMessages cannot be negative. Use 0 to send no history"
            );
        }
    }

    /**
     * @param string $class
     * @param array  $order
     *
     * @throws LLMException if $order names anything but SECTIONS, or repeats one
     */
    private static function checkOrder($class, array $order)
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
     * @param string $class
     * @param mixed  $format
     *
     * @throws LLMException if $format is not one of the Format constants
     */
    private static function checkFormat($class, $format)
    {
        if (!in_array($format, Format::ALL, true)) {
            throw new LLMException(
                "$class::\$format is not a known format. Use a " . Format::class
                . ' constant: ' . implode(', ', Format::ALL)
            );
        }
    }

    /**
     * @param array $sections section name => value, task last
     * @return string
     */
    private static function toXml(array $sections)
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

    /**
     * @param string $text
     * @return string
     */
    private static function escapeXml($text)
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array $sections section name => value, task last
     * @return string
     *
     * @throws LLMException if the sections cannot be encoded
     */
    private static function toJson(array $sections)
    {
        $json = json_encode($sections, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new LLMException('Could not render the prompt as JSON: ' . json_last_error_msg());
        }
        return $json;
    }

    /**
     * @param array $sections section name => value, task last
     * @return string
     */
    private static function toText(array $sections)
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
