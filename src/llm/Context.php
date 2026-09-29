<?php
namespace Jambura\LLM;

/**
 * The state one pipeline run carries from step to step.
 *
 * Pipeline::feed() builds a Context from the Prompt it is fed, the run's own
 * values and the pipeline's configure() settings, passes it to every step in
 * the route, and returns it to the caller. Each step reads what earlier steps
 * left here and adds its own, so a Context is both the working state of a run
 * and its result.
 *
 * Three things live here, and they are deliberately separate:
 *
 * - The Prompt, reached with prompt(). It is the structured prompt the model
 *   step will send, and preprocessors add to its context sections rather than
 *   assembling text of their own.
 * - Values, the run's working data: uploads, a customer record, a parsed
 *   result. Steps read and write them with get(), set() and merge().
 * - Settings, the pipeline's fixed configure() options, read-only here.
 */
class Context
{
    /**
     * The prompt this run is building and will send.
     * @var Prompt
     */
    private $prompt;

    /**
     * Values the run has collected so far, keyed by name.
     * @var array<string, mixed>
     */
    private $values;

    /**
     * The pipeline's configure() settings.
     * @var array<string, mixed>
     */
    private $settings;

    /**
     * Aliases of the steps that have run, in the order they ran.
     * @var string[]
     */
    private $ran = [];

    /**
     * Alias of the gatekeeper that stopped the run, or null if none did.
     * @var string|null
     */
    private $stoppedAt = null;

    /**
     * @param Prompt               $prompt   the prompt the run will send
     * @param array<string, mixed> $values   data the run starts with
     * @param array<string, mixed> $settings the pipeline's configure() settings
     */
    public function __construct(Prompt $prompt, array $values = [], array $settings = [])
    {
        $this->prompt = $prompt;
        $this->values = $values;
        $this->settings = $settings;
    }

    /**
     * The prompt this run is building.
     *
     * Returned as it stands, so a preprocessor can add to it in place:
     *
     *     $context->prompt()->addContext('retrieved', $vendor->summary());
     *
     * Pipeline::feed() copies the caller's Prompt before the run, so adding to
     * it here never changes the object the caller passed in. A model step sends
     * this prompt, usually with LLM::use(...)->prompt($context->prompt()).
     */
    public function prompt(): Prompt
    {
        return $this->prompt;
    }

    /**
     * Replaces the prompt for the steps that come after this one.
     *
     * For a step that builds a new Prompt rather than adding to this one, such
     * as a filter that returns a trimmed copy.
     *
     * @return $this
     */
    public function setPrompt(Prompt $prompt)
    {
        $this->prompt = $prompt;
        return $this;
    }

    /**
     * Returns one value the run has collected.
     *
     * @param string $key     value name, for example 'input' or 'response'
     * @param $default returned when nothing has set that value
     * @return mixed the value, or $default
     */
    public function get(string $key, $default = null)
    {
        return $this->values[$key] ?? $default;
    }

    /**
     * Sets one value for the steps that run after this one.
     *
     * Use it from a step that writes a single value. A step that writes several
     * can return an array instead, and the pipeline merges that for it.
     *
     * @return $this
     */
    public function set(string $key, $value)
    {
        $this->values[$key] = $value;
        return $this;
    }

    /**
     * Adds several values at once, overwriting any key that is already set.
     *
     * The pipeline calls this with whatever a step returns as an array.
     *
     * @param array<string, mixed> $values
     * @return $this
     */
    public function merge(array $values)
    {
        $this->values = array_merge($this->values, $values);
        return $this;
    }

    /**
     * Whether a value has been set, even if it was set to null.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * Every value the run has collected.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Returns one of the pipeline's configure() settings.
     *
     * @param string $key     setting name, for example 'currency'
     * @param $default returned when the pipeline did not configure it
     * @return mixed
     */
    public function setting(string $key, $default = null)
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * Every setting the pipeline was configured with.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return $this->settings;
    }

    /**
     * Aliases of the steps that ran, in order.
     *
     * Shows the path a run took, ending at the gatekeeper that stopped it if
     * one did. Useful in logs and tests.
     *
     * @return string[]
     */
    public function ranSteps(): array
    {
        return $this->ran;
    }

    /**
     * Alias of the gatekeeper that stopped the run, or null if the whole route ran.
     */
    public function stoppedAt(): ?string
    {
        return $this->stoppedAt;
    }

    /**
     * Whether a gatekeeper stopped the run before the route finished.
     */
    public function wasStopped(): bool
    {
        return $this->stoppedAt !== null;
    }

    /**
     * Records that a step has run.
     *
     * Called by Pipeline as it works through a route. Steps do not call this.
     *
     * @param string $alias the step's alias
     */
    public function markRan(string $alias): void
    {
        $this->ran[] = $alias;
    }

    /**
     * Records the gatekeeper that stopped the run.
     *
     * Called by Pipeline when a gatekeeper returns false. A step stops a run by
     * returning false, not by calling this.
     *
     * @param string $alias the gatekeeper's alias
     */
    public function markStopped(string $alias): void
    {
        $this->stoppedAt = $alias;
    }
}
