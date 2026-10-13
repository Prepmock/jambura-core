<?php

use Jambura\LLM\InMemoryRunStore;
use Jambura\LLM\LLMException;
use Jambura\LLM\Prompt;
use Jambura\LLM\Run;
use PHPUnit\Framework\TestCase;

class RunTest extends TestCase
{
    public function testANewRunIsPendingWithItsOwnId(): void
    {
        $run = new Run('researcher', $this->prompt());
        $other = new Run('researcher', $this->prompt());

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $run->id());
        $this->assertNotSame($run->id(), $other->id());
        $this->assertSame('researcher', $run->agent());
        $this->assertSame(Run::PENDING, $run->status());
        $this->assertSame([], $run->turns());
        $this->assertSame([], $run->trace());
        $this->assertSame(0, $run->step());
        $this->assertSame(['steps' => 0, 'tokens' => 0], $run->spent());
        $this->assertNull($run->answer());
        $this->assertNull($run->error());
    }

    public function testARunMovesFromPendingThroughRunningToDone(): void
    {
        $run = new Run('researcher', $this->prompt());

        $run->markRunning();
        $this->assertSame(Run::RUNNING, $run->status());

        $run->recordTurn('AIModel\Claude', 'Pallets over 1t need a permit.');
        $run->markDone('Pallets over 1t need a permit.');

        $this->assertSame(Run::DONE, $run->status());
        $this->assertSame('Pallets over 1t need a permit.', $run->answer());
        $this->assertSame(
            [['step' => 0, 'model' => 'AIModel\Claude', 'reply' => 'Pallets over 1t need a permit.']],
            $run->turns()
        );
        $this->assertSame(1, $run->step());
        $this->assertSame(['steps' => 1, 'tokens' => 0], $run->spent());
        $this->assertSame(['started', 'model_call', 'done'], array_column($run->trace(), 'event'));
        $this->assertSame([0, 0, 1], array_column($run->trace(), 'step'));
        $this->assertSame('AIModel\Claude', $run->trace()[1]['model']);
    }

    public function testAFailedRunRecordsWhy(): void
    {
        $run = new Run('researcher', $this->prompt());
        $run->markRunning();

        $run->markFailed(new LLMException('provider down'));

        $this->assertSame(Run::FAILED, $run->status());
        $this->assertSame('provider down', $run->error());
        $this->assertNull($run->answer());
        $this->assertSame(['started', 'failed'], array_column($run->trace(), 'event'));
        $this->assertSame('provider down', $run->trace()[1]['error']);
    }

    public function testOnlyAPendingRunCanStart(): void
    {
        $run = new Run('researcher', $this->prompt());
        $run->markRunning();

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('is running, so it cannot start; only a pending run can');
        $run->markRunning();
    }

    public function testOnlyARunningRunCanFinish(): void
    {
        $run = new Run('researcher', $this->prompt());

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('is pending, so it cannot finish; only a running run can');
        $run->markDone('Too early.');
    }

    public function testAFinishedRunCannotFail(): void
    {
        $run = $this->finishedRun();

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('is done, so it cannot fail');
        $run->markFailed(new LLMException('late'));
    }

    public function testToArrayHoldsEveryPartOfTheRun(): void
    {
        $state = $this->finishedRun()->toArray();

        $this->assertSame(
            ['id', 'agent', 'parent', 'status', 'prompt', 'turns', 'step', 'trace', 'spent', 'answer', 'error'],
            array_keys($state)
        );
        $this->assertNull($state['parent']);
        $this->assertSame(Run::DONE, $state['status']);
        $this->assertSame($this->prompt()->toArray(), $state['prompt']);
    }

    public function testARunSurvivesToArrayAndFromArrayUnchanged(): void
    {
        $run = $this->finishedRun();
        $state = $run->toArray();

        $copy = Run::fromArray($state);

        $this->assertSame($state, $copy->toArray());
        $this->assertSame($run->id(), $copy->id());
        $this->assertSame(
            [
                ['path' => '/tmp/rules-v2.pdf', 'media_type' => 'application/pdf'],
                ['path' => '/tmp/scan.png', 'media_type' => null],
            ],
            $copy->prompt()->getDocuments()
        );
        $this->assertSame(
            ['max_tokens' => 1024, 'temperature' => 0.0, 'stop_sequences' => null],
            $copy->prompt()->getOptions()
        );
    }

    /**
     * The state is a plain array, so it can go to a cache or a database as
     * JSON and come back as the same run. JSON_PRESERVE_ZERO_FRACTION is what
     * keeps a temperature of 0.0 a float; without it, it comes back as int 0.
     */
    public function testARunSurvivesAJsonRoundTrip(): void
    {
        $state = $this->finishedRun()->toArray();

        $json = json_encode($state, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($state, Run::fromArray($decoded)->toArray());
    }

    public function testAFailedRunSurvivesTheRoundTripToo(): void
    {
        $run = new Run('researcher', $this->prompt());
        $run->markRunning();
        $run->markFailed(new LLMException('provider down'));
        $state = $run->toArray();

        $copy = Run::fromArray($state);

        $this->assertSame($state, $copy->toArray());
        $this->assertSame(Run::FAILED, $copy->status());
        $this->assertSame('provider down', $copy->error());
    }

    public function testFromArrayWithAMissingPartThrows(): void
    {
        $state = $this->finishedRun()->toArray();
        unset($state['trace'], $state['spent']);

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('Run state is missing: trace, spent');
        Run::fromArray($state);
    }

    public function testFromArrayWithAnUnknownStatusThrows(): void
    {
        $state = $this->finishedRun()->toArray();
        $state['status'] = 'paused';

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage("Unknown run status 'paused'. Use one of: pending, running, done, failed");
        Run::fromArray($state);
    }

    public function testTheMemoryStoreSavesAndLoadsARun(): void
    {
        $store = new InMemoryRunStore();
        $run = $this->finishedRun();

        $store->save($run->id(), $run->toArray());

        $this->assertSame($run->toArray(), $store->load($run->id()));
        $this->assertSame($run->toArray(), Run::fromArray($store->load($run->id()))->toArray());
    }

    public function testTheMemoryStoreReturnsNullForAnUnknownId(): void
    {
        $this->assertNull((new InMemoryRunStore())->load('no-such-run'));
    }

    public function testSavingAgainReplacesTheState(): void
    {
        $store = new InMemoryRunStore();
        $run = new Run('researcher', $this->prompt());
        $store->save($run->id(), $run->toArray());

        $run->markRunning();
        $store->save($run->id(), $run->toArray());

        $this->assertSame(Run::RUNNING, $store->load($run->id())['status']);
    }

    public function testForgetRemovesTheState(): void
    {
        $store = new InMemoryRunStore();
        $run = $this->finishedRun();
        $store->save($run->id(), $run->toArray());

        $store->forget($run->id());
        $store->forget('no-such-run');

        $this->assertNull($store->load($run->id()));
    }

    private function finishedRun(): Run
    {
        $run = new Run('researcher', $this->prompt());
        $run->markRunning();
        $run->recordTurn('AIModel\Claude', 'Pallets over 1t need a permit.');
        $run->markDone('Pallets over 1t need a permit.');
        return $run;
    }

    private function prompt(): Prompt
    {
        return Prompt::create()
            ->setType('research')
            ->setRole('You are a research assistant.')
            ->addContext('static', 'Mill operates two shifts.')
            ->addContext('retrieved', 'Shipping rules v2: pallets over 1t need a permit.')
            ->addInstruction('Cite the context you use.', 'Be brief.')
            ->setTask('What changed in the shipping rules?')
            ->addDocument('/tmp/rules-v2.pdf', 'application/pdf')
            ->addDocument('/tmp/scan.png')
            ->setOption('max_tokens', 1024)
            ->setOption('temperature', 0.0)
            ->setOption('stop_sequences', null);
    }
}
