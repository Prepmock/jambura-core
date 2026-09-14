<?php
namespace Jambura;

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
     *                      or has an invalid $order
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
            self::checkOrder($class, $reflection->getDefaultProperties()['order']);
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
     * @throws LLMException if $order is invalid
     */
    protected function handlePrompt(Prompt $prompt): string
    {
        self::checkOrder(static::class, $this->order);

        $sections = [];
        foreach ($this->order as $name) {
            $value = match ($name) {
                'role' => $prompt->getRole(),
                'context' => $prompt->getContext(),
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
