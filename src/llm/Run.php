<?php
namespace Jambura\LLM;

/**
 * One execution of an agent.
 *
 * Agent holds the definition every caller shares; a Run holds everything that
 * belongs to a single call and is never shared: the prompt it sent, the turns,
 * the step cursor, the trace, the budget spent, the status and the answer.
 * Agent::start() returns one:
 *
 *     $run = Agent::use('researcher')->start('What changed in the shipping rules?');
 *
 *     $run->status();   // Run::DONE
 *     $run->answer();   // the model's reply
 *     $run->trace();    // what happened, in order
 *
 * A run moves from pending to running, then to done or failed. Agent drives it
 * through markRunning(), recordTurn(), markDone() and markFailed(); callers
 * only read it.
 *
 * A run is state that may need to outlive the request that started it, so it
 * turns into a plain array and back:
 *
 *     $store->save($run->id(), $run->toArray());
 *     $run = Run::fromArray($store->load($runId));
 *
 * The id is generated here, so a caller that later resumes a run by id - from a
 * RunStore, in another request or a worker - needs nothing from the run but
 * that id.
 *
 * Errors are `Jambura\LLM\LLMException`: a status change the run's current
 * status does not allow, or state for fromArray() that is missing a part or
 * names an unknown status.
 */
class Run
{
    /**
     * Statuses, in the order a run moves through them. A run ends in DONE or
     * FAILED.
     */
    const PENDING = 'pending';
    const RUNNING = 'running';
    const DONE = 'done';
    const FAILED = 'failed';

    const STATUSES = [self::PENDING, self::RUNNING, self::DONE, self::FAILED];

    /**
     * The parts toArray() returns and fromArray() requires.
     */
    private const KEYS = [
        'id', 'agent', 'parent', 'status', 'prompt', 'turns', 'step', 'trace', 'spent', 'answer', 'error',
    ];

    /**
     * This run's id, 32 hex characters.
     * @var string
     */
    private string $id;

    /**
     * Name of the agent this run belongs to.
     * @var string
     */
    private string $agent;

    /**
     * Id of the run this one was delegated from. Always null until a
     * supervisor can nest runs; kept in the state so that needs no new shape.
     * @var string|null
     */
    private ?string $parent = null;

    /**
     * One of STATUSES.
     * @var string
     */
    private string $status = self::PENDING;

    /**
     * The prompt this run sends.
     * @var Prompt
     */
    private Prompt $prompt;

    /**
     * Model calls made so far, in order.
     * @var list<array{step: int, model: string, reply: string}>
     */
    private array $turns = [];

    /**
     * Index of the next step. A single-shot run ends at 1.
     * @var int
     */
    private int $step = 0;

    /**
     * Events in the order they happened, each with its event name, the step
     * it happened at and a microtime(true) timestamp.
     * @var list<array<string, mixed>>
     */
    private array $trace = [];

    /**
     * Budget spent so far. Tokens stay 0 until adapters report usage.
     * @var array{steps: int, tokens: int}
     */
    private array $spent = ['steps' => 0, 'tokens' => 0];

    /**
     * The final reply, once the run is done.
     * @var string|null
     */
    private ?string $answer = null;

    /**
     * The exception message, once the run has failed.
     * @var string|null
     */
    private ?string $error = null;

    /**
     * Starts a pending run with a new id.
     *
     * The run keeps the prompt it is given, so pass one that nobody else will
     * change; Agent passes its own copy.
     *
     * @param string $agent  the name of the agent the run belongs to
     * @param Prompt $prompt the prompt the run will send
     */
    public function __construct(string $agent, Prompt $prompt)
    {
        $this->id = bin2hex(random_bytes(16));
        $this->agent = $agent;
        $this->prompt = $prompt;
    }

    /**
     * Rebuilds a run from what toArray() returned.
     *
     * The id comes back with it, so a run saved in one request and loaded in
     * another is the same run.
     *
     * @param array<string, mixed> $state the output of toArray()
     *
     * @throws LLMException if a part is missing, the status is unknown, or the
     *                      prompt cannot be rebuilt
     */
    public static function fromArray(array $state): static
    {
        $missing = array_diff(self::KEYS, array_keys($state));
        if ($missing) {
            throw new LLMException('Run state is missing: ' . implode(', ', $missing));
        }
        if (!in_array($state['status'], self::STATUSES, true)) {
            throw new LLMException(
                "Unknown run status '{$state['status']}'. Use one of: " . implode(', ', self::STATUSES)
            );
        }

        $run = new static($state['agent'], Prompt::fromArray($state['prompt']));
        $run->id = $state['id'];
        $run->parent = $state['parent'];
        $run->status = $state['status'];
        $run->turns = $state['turns'];
        $run->step = $state['step'];
        $run->trace = $state['trace'];
        $run->spent = $state['spent'];
        $run->answer = $state['answer'];
        $run->error = $state['error'];
        return $run;
    }

    /**
     * Everything this run holds, as a plain array that fromArray() rebuilds it
     * from.
     *
     * Hand it to a RunStore to keep the run beyond this request. It holds only
     * strings, numbers, nulls and arrays, so it survives json_encode() as long
     * as the prompt's options do. Encode with JSON_PRESERVE_ZERO_FRACTION, or a
     * float such as a temperature of 0.0 comes back as the integer 0.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'agent' => $this->agent,
            'parent' => $this->parent,
            'status' => $this->status,
            'prompt' => $this->prompt->toArray(),
            'turns' => $this->turns,
            'step' => $this->step,
            'trace' => $this->trace,
            'spent' => $this->spent,
            'answer' => $this->answer,
            'error' => $this->error,
        ];
    }

    /**
     * This run's id, the one a RunStore keeps it under.
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Name of the agent this run belongs to.
     */
    public function agent(): string
    {
        return $this->agent;
    }

    /**
     * One of STATUSES.
     */
    public function status(): string
    {
        return $this->status;
    }

    /**
     * The prompt this run sends: the agent's role and instructions together
     * with the task or the caller's prompt.
     */
    public function prompt(): Prompt
    {
        return $this->prompt;
    }

    /**
     * Model calls made so far, in order, each with its step, the adapter class
     * and the reply.
     *
     * @return list<array{step: int, model: string, reply: string}>
     */
    public function turns(): array
    {
        return $this->turns;
    }

    /**
     * What happened, in order: 'started', one 'model_call' per turn, then
     * 'done' or 'failed'. Each event has its step and a microtime(true)
     * timestamp in 'at'.
     *
     * @return list<array<string, mixed>>
     */
    public function trace(): array
    {
        return $this->trace;
    }

    /**
     * Index of the next step, which is also the number of steps taken.
     */
    public function step(): int
    {
        return $this->step;
    }

    /**
     * Budget spent so far, as steps and tokens.
     *
     * Tokens stay 0 until adapters report usage.
     *
     * @return array{steps: int, tokens: int}
     */
    public function spent(): array
    {
        return $this->spent;
    }

    /**
     * The final reply, or null until the run is done.
     */
    public function answer(): ?string
    {
        return $this->answer;
    }

    /**
     * Why the run failed, or null unless it has.
     */
    public function error(): ?string
    {
        return $this->error;
    }

    /**
     * Records that the run has started.
     *
     * Called by Agent before the first model call. Callers do not call this.
     *
     * @throws LLMException if the run is not pending
     */
    public function markRunning(): void
    {
        $this->expectStatus(self::PENDING, 'start');
        $this->status = self::RUNNING;
        $this->addEvent('started');
    }

    /**
     * Records one model call and moves the step cursor on.
     *
     * Called by Agent after each reply. Callers do not call this.
     *
     * @param string $model the adapter class the prompt was sent through
     * @param string $reply what the model returned
     *
     * @throws LLMException if the run is not running
     */
    public function recordTurn(string $model, string $reply): void
    {
        $this->expectStatus(self::RUNNING, 'record a turn');
        $this->turns[] = ['step' => $this->step, 'model' => $model, 'reply' => $reply];
        $this->addEvent('model_call', ['model' => $model]);
        $this->step++;
        $this->spent['steps']++;
    }

    /**
     * Records the final answer and ends the run.
     *
     * Called by Agent. Callers do not call this.
     *
     * @throws LLMException if the run is not running
     */
    public function markDone(string $answer): void
    {
        $this->expectStatus(self::RUNNING, 'finish');
        $this->answer = $answer;
        $this->status = self::DONE;
        $this->addEvent('done');
    }

    /**
     * Records why the run failed and ends it.
     *
     * Called by Agent when the adapter throws, before the exception is
     * rethrown. Callers do not call this.
     *
     * @throws LLMException if the run is not running
     */
    public function markFailed(\Throwable $e): void
    {
        $this->expectStatus(self::RUNNING, 'fail');
        $this->error = $e->getMessage();
        $this->status = self::FAILED;
        $this->addEvent('failed', ['error' => $e->getMessage()]);
    }

    /**
     * @throws LLMException if the run is not in $status
     */
    private function expectStatus(string $status, string $action): void
    {
        if ($this->status !== $status) {
            throw new LLMException(
                "Run {$this->id} is {$this->status}, so it cannot $action; only a $status run can"
            );
        }
    }

    /**
     * @param array<string, mixed> $details extra fields for the event
     */
    private function addEvent(string $event, array $details = []): void
    {
        $this->trace[] = ['event' => $event, 'step' => $this->step, 'at' => microtime(true)] + $details;
    }
}
