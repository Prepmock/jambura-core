<?php

use Jambura\LLM\Context;
use Jambura\LLM\LLMException;
use Jambura\LLM\Pipeline;
use Jambura\LLM\Prompt;
use PHPUnit\Framework\TestCase;

class AttachmentGate
{
    public function check_attachment(Context $context)
    {
        return $context->get('attachments') ? ['attachment_count' => count($context->get('attachments'))] : false;
    }
}

class FileStore
{
    public function save_attachments(Context $context)
    {
        return ['saved' => count($context->get('attachments', [])), 'currency' => $context->setting('currency')];
    }
}

class ReceiptReader
{
    public function read_receipt(Context $context)
    {
        return 'read ' . $context->get('saved', 0) . ' file(s)';
    }
}

class ParsingReader
{
    public function read_receipt(Context $context)
    {
        return ['response' => 'parsed', 'tokens' => 12];
    }
}

class CountedStep
{
    public static int $built = 0;

    public function __construct()
    {
        self::$built++;
    }

    public function touch(Context $context)
    {
        return ['built' => self::$built];
    }
}

class StatefulStep
{
    public function __construct(private string $tag)
    {
    }

    public function stamp(Context $context)
    {
        return ['tag' => $this->tag];
    }
}

class NoSuchMethodStep
{
}

class PipelineTest extends TestCase
{
    protected function setUp(): void
    {
        Pipeline::forgetPipelines();
        CountedStep::$built = 0;
    }

    public function testMakeDefinesAPipelineThatUseReturns(): void
    {
        $pipeline = Pipeline::make('receipts');

        $this->assertSame('receipts', $pipeline->name());
        $this->assertSame($pipeline, Pipeline::use('receipts'));
        $this->assertTrue(Pipeline::isDefined('receipts'));
        $this->assertFalse(Pipeline::isDefined('invoices'));
    }

    public function testMakingTheSameNameTwiceThrows(): void
    {
        Pipeline::make('receipts');

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage("Pipeline 'receipts' is already defined");
        Pipeline::make('receipts');
    }

    public function testUsingAnUndefinedPipelineThrows(): void
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage("Pipeline 'receipts' is not defined");
        Pipeline::use('receipts');
    }

    public function testRunsTheRouteInOrderAndCollectsTheContext(): void
    {
        $this->receiptPipeline();

        $context = Pipeline::use('receipts')->followRoute('default')->feed([
            'attachments' => ['a.pdf', 'b.pdf'],
        ]);

        $this->assertSame(
            ['check_attachment', 'save_attachments', 'read_receipt'],
            $context->ranSteps()
        );
        $this->assertSame(2, $context->get('attachment_count'));
        $this->assertSame(2, $context->get('saved'));
        $this->assertSame('CAD', $context->get('currency'));
        $this->assertSame('read 2 file(s)', $context->get('response'));
        $this->assertFalse($context->wasStopped());
    }

    public function testAGatekeeperReturningFalseStopsTheRun(): void
    {
        $this->receiptPipeline();

        $context = Pipeline::use('receipts')->followRoute('default')->feed(['attachments' => []]);

        $this->assertSame(['check_attachment'], $context->ranSteps());
        $this->assertTrue($context->wasStopped());
        $this->assertSame('check_attachment', $context->stoppedAt());
        $this->assertNull($context->get('response'));
    }

    public function testAModelReturningAnArrayMergesInsteadOfWritingResponse(): void
    {
        Pipeline::make('receipts')
            ->model(ParsingReader::class, 'read_receipt')
            ->route('default', ['read_receipt']);

        $context = Pipeline::use('receipts')->feed('a receipt');

        $this->assertSame('parsed', $context->get('response'));
        $this->assertSame(12, $context->get('tokens'));
    }

    public function testConfigureMergesAndReachesTheSteps(): void
    {
        $pipeline = Pipeline::make('receipts')
            ->configure(['currency' => 'USD', 'token_budget' => 8000])
            ->configure(['currency' => 'CAD'])
            ->preprocessor(FileStore::class, 'save_attachments')
            ->route('default', ['save_attachments']);

        $this->assertSame(['currency' => 'CAD', 'token_budget' => 8000], $pipeline->settings());
        $this->assertSame('CAD', $pipeline->feed([])->get('currency'));
    }

    public function testFeedTurnsAStringIntoInputAndAPromptIntoPrompt(): void
    {
        $prompt = Prompt::create()->setTask('Summarize');
        Pipeline::make('receipts')
            ->step(fn (Context $context) => null, as: 'noop')
            ->route('default', ['noop']);

        $this->assertSame('a receipt', Pipeline::use('receipts')->feed('a receipt')->get('input'));
        $this->assertSame($prompt, Pipeline::use('receipts')->feed($prompt)->get('prompt'));
    }

    public function testFeedFallsBackToTheDefaultRoute(): void
    {
        Pipeline::make('receipts')
            ->model(ReceiptReader::class, 'read_receipt')
            ->route('default', ['read_receipt']);

        $this->assertSame('read 0 file(s)', Pipeline::use('receipts')->feed()->get('response'));
    }

    public function testFeedWithoutAnyRouteToFollowThrows(): void
    {
        Pipeline::make('receipts')->model(ReceiptReader::class, 'read_receipt');

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage("Pipeline 'receipts' has no route to follow");
        Pipeline::use('receipts')->feed();
    }

    public function testTheChosenRouteSticksForLaterRuns(): void
    {
        Pipeline::make('receipts')
            ->model(ReceiptReader::class, 'read_receipt')
            ->step(fn (Context $context) => ['skipped' => true], as: 'quick')
            ->route('default', ['read_receipt'])
            ->route('quick', ['quick']);

        $pipeline = Pipeline::use('receipts')->followRoute('quick');

        $this->assertTrue($pipeline->feed()->get('skipped'));
        $this->assertTrue(Pipeline::use('receipts')->feed()->get('skipped'));
    }

    public function testFollowingAnUnknownRouteThrowsAndNamesTheRoutesThereAre(): void
    {
        Pipeline::make('receipts')
            ->model(ReceiptReader::class, 'read_receipt')
            ->route('default', ['read_receipt']);

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage("has no route 'retry'. Defined routes: default");
        Pipeline::use('receipts')->followRoute('retry');
    }

    public function testARouteNamingAnUnregisteredStepThrowsBeforeAnythingRuns(): void
    {
        $ran = [];
        Pipeline::make('receipts')
            ->step(function (Context $context) use (&$ran) {
                $ran[] = 'first';
            }, as: 'first')
            ->route('default', ['first', 'typo']);

        try {
            Pipeline::use('receipts')->feed();
            $this->fail('Expected an LLMException');
        } catch (LLMException $e) {
            $this->assertStringContainsString('names steps that were never registered: typo', $e->getMessage());
        }
        $this->assertSame([], $ran);
    }

    public function testACallableStepNeedsAnAlias(): void
    {
        $pipeline = Pipeline::make('receipts');

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('A callable step needs an alias');
        $pipeline->step(fn (Context $context) => null);
    }

    public function testTwoStepsCannotShareAnAlias(): void
    {
        $pipeline = Pipeline::make('receipts')->preprocessor(FileStore::class, 'save_attachments');

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage("already has a step called 'save_attachments'");
        $pipeline->preprocessor(FileStore::class, 'save_attachments');
    }

    public function testAsRenamesTheStepForRoutes(): void
    {
        $pipeline = Pipeline::make('receipts')
            ->preprocessor(FileStore::class, 'save_attachments', as: 'store_files')
            ->route('default', ['store_files']);

        $this->assertSame(['store_files' => Pipeline::PREPROCESSOR], $pipeline->definedSteps());
        $this->assertSame(0, $pipeline->feed()->get('saved'));
    }

    public function testAStepNamingAMethodTheClassDoesNotHaveThrows(): void
    {
        $pipeline = Pipeline::make('receipts');

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('NoSuchMethodStep::run(), which does not exist');
        $pipeline->model(NoSuchMethodStep::class, 'run');
    }

    public function testACallableStepIsGivenTheContext(): void
    {
        Pipeline::make('receipts')
            ->preprocessor(fn (Context $context) => ['seen' => $context->get('input')], as: 'look')
            ->route('default', ['look']);

        $this->assertSame('a receipt', Pipeline::use('receipts')->feed('a receipt')->get('seen'));
    }

    public function testAnObjectStepIsUsedAsGiven(): void
    {
        Pipeline::make('receipts')
            ->preprocessor(new StatefulStep('receipts-v2'), 'stamp')
            ->route('default', ['stamp']);

        $this->assertSame('receipts-v2', Pipeline::use('receipts')->feed()->get('tag'));
    }

    public function testAStepClassIsBuiltOnceAcrossRuns(): void
    {
        Pipeline::make('receipts')
            ->preprocessor(CountedStep::class, 'touch')
            ->route('default', ['touch']);

        Pipeline::use('receipts')->feed();
        $context = Pipeline::use('receipts')->feed();

        $this->assertSame(1, CountedStep::$built);
        $this->assertSame(1, $context->get('built'));
    }

    public function testDefinedStepsAndRoutesDescribeThePipeline(): void
    {
        $pipeline = $this->receiptPipeline();

        $this->assertSame([
            'check_attachment' => Pipeline::GATEKEEPER,
            'save_attachments' => Pipeline::PREPROCESSOR,
            'read_receipt' => Pipeline::MODEL,
        ], $pipeline->definedSteps());
        $this->assertSame(
            ['default' => ['check_attachment', 'save_attachments', 'read_receipt']],
            $pipeline->definedRoutes()
        );
    }

    private function receiptPipeline(): Pipeline
    {
        return Pipeline::make('receipts')
            ->configure(['currency' => 'CAD'])
            ->gatekeeper(AttachmentGate::class, 'check_attachment')
            ->preprocessor(FileStore::class, 'save_attachments')
            ->model(ReceiptReader::class, 'read_receipt')
            ->route('default', ['check_attachment', 'save_attachments', 'read_receipt']);
    }
}
