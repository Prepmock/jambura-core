<?php
namespace Jambura\LLM;

/**
 * A RunStore that keeps state in an array for as long as the object lives.
 *
 * Meant for tests, and for code that wants the RunStore shape without a
 * database or cache behind it. Nothing outlives the process:
 *
 *     $store = new InMemoryRunStore();
 *     $store->save($run->id(), $run->toArray());
 *     $copy = Run::fromArray($store->load($run->id()));
 */
class InMemoryRunStore implements RunStore
{
    /**
     * Saved states, keyed by run id.
     * @var array<string, array<string, mixed>>
     */
    private array $runs = [];

    /**
     * Returns the state saved under this id, or null if there is none.
     *
     * @return array<string, mixed>|null
     */
    public function load(string $runId): ?array
    {
        return $this->runs[$runId] ?? null;
    }

    /**
     * Saves a run's state under its id, replacing what was saved before.
     *
     * @param array<string, mixed> $state the output of Run::toArray()
     */
    public function save(string $runId, array $state): void
    {
        $this->runs[$runId] = $state;
    }

    /**
     * Removes the state saved under this id. Forgetting an unknown id does
     * nothing.
     */
    public function forget(string $runId): void
    {
        unset($this->runs[$runId]);
    }
}
