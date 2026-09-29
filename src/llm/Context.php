<?php
namespace Jambura\LLM;

/**
 * The state one pipeline run carries from step to step.
 *
 * Pipeline::feed() builds a Context from the input it is given and the
 * pipeline's configure() settings, passes it to every step in the route, and
 * returns it to the caller. Each step reads what earlier steps left here and
 * adds its own values, so a Context is both the working state of a run and its
 * result.
 *
 * Values and settings are kept apart: values are written during the run,
 * settings are the pipeline's fixed configuration and are read-only here.
 */
class Context
{
    /**
     * Values the run has collected so far, keyed by name.
     * @var array<string, mixed>
     */
    private array $values;

    /**
     * The pipeline's configure() settings.
     * @var array<string, mixed>
     */
    private array $settings;

    /**
     * Aliases of the steps that have run, in the order they ran.
     * @var string[]
     */
    private array $ran = [];

    /**
     * Alias of the gatekeeper that stopped the run, or null if none did.
     * @var string|null
     */
    private ?string $stoppedAt = null;

    /**
     * @param array<string, mixed> $values   the input the run starts with
     * @param array<string, mixed> $settings the pipeline's configure() settings
     */
    public function __construct(array $values = [], array $settings = [])
    {
        $this->values = $values;
        $this->settings = $settings;
    }

    /**
     * Returns one value the run has collected.
     *
     * @param string $key     value name, for example 'input' or 'response'
     * @param mixed  $default returned when nothing has set that value
     * @return mixed the value, or $default
     */
    public function get(string $key, mixed $default = null): mixed
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
    public function set(string $key, mixed $value): static
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
    public function merge(array $values): static
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
     * @param mixed  $default returned when the pipeline did not configure it
     * @return mixed
     */
    public function setting(string $key, mixed $default = null): mixed
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
