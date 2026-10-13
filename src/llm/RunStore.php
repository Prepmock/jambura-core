<?php
namespace Jambura\LLM;

/**
 * Where run state is kept between requests.
 *
 * A PHP process ends with the request, and an agent's definition is rebuilt
 * from bootstrap every time. What may have to outlive the request is a run, so
 * a store keeps a run's toArray() state under its id, and the caller passes
 * that id back to pick it up again:
 *
 *     $store->save($run->id(), $run->toArray());
 *
 *     // a later request, or a worker
 *     $state = $store->load($runId);
 *     $run = $state === null ? null : Run::fromArray($state);
 *
 * There are three tiers of run, and a store is what the second and third add:
 *
 * - In-request: one run, start to finish, inside the request. No store.
 * - Resumable: the run is saved after each step and resumed later by id.
 * - Background: a worker runs it, and the web request that started it polls
 *   the store for its status.
 *
 * An implementation stores the array as it is given and returns it unchanged;
 * it does not interpret it. One that stores JSON encodes with
 * JSON_PRESERVE_ZERO_FRACTION, so a float such as a temperature of 0.0 does
 * not come back as an integer. InMemoryRunStore is the one for tests.
 */
interface RunStore
{
    /**
     * Returns the state saved under this id, or null if there is none.
     *
     * @return array<string, mixed>|null
     */
    public function load(string $runId): ?array;

    /**
     * Saves a run's state under its id, replacing what was saved before.
     *
     * @param array<string, mixed> $state the output of Run::toArray()
     */
    public function save(string $runId, array $state): void;

    /**
     * Removes the state saved under this id. Forgetting an unknown id does
     * nothing.
     */
    public function forget(string $runId): void;
}
