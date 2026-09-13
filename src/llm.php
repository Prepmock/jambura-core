<?php
namespace Jambura;

use Jambura\LLM\LLMException;
use Jambura\LLM\Prompt;

/**
 * Registry of model adapters, and the contract each adapter implements.
 *
 * Register adapters once, then resolve one by class name:
 *
 *     \Jambura\LLM::registerModels([\AIModel\Claude::class, \AIModel\Deepseek::class]);
 *     \Jambura\LLM::use(\AIModel\Claude::class)->prompt($prompt);
 *
 * Each adapter is built once, on its first use(), and reused after that.
 */
abstract class LLM
{
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
     * Adapters are built by use(), never with `new`.
     */
    final protected function __construct()
    {
    }

    /**
     * Registers adapter classes so use() can resolve them.
     *
     * Checks each class up front, so a typo fails at registration rather than
     * on the first prompt. Registering a class again keeps its instance.
     *
     * @param string[] $classes adapter class names
     *
     * @throws LLMException if a class does not exist or is not a concrete LLM
     */
    public static function registerModels(array $classes): void
    {
        foreach ($classes as $class) {
            $class = ltrim($class, '\\');
            if (!class_exists($class)) {
                throw new LLMException("Model class $class does not exist");
            }
            if (!is_subclass_of($class, self::class) || (new \ReflectionClass($class))->isAbstract()) {
                throw new LLMException("Model class $class must be a concrete subclass of " . self::class);
            }
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
        return $this->send($this->serialize($prompt));
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
     * Builds the request body this adapter's API expects from a prompt.
     *
     * @return array the request body, before JSON encoding
     */
    abstract public function serialize(Prompt $prompt): array;

    /**
     * Sends a request body built by serialize() and returns the reply text.
     *
     * @param array $payload the output of serialize()
     *
     * @throws LLMException if the call fails or returns no text
     */
    abstract protected function send(array $payload): string;
}
