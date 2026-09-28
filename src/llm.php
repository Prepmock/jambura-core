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
     * @var array
     */
    private static $models = [];

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
     *                      or has an invalid $order or $format
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
                $value = $prompt->getContext();
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
