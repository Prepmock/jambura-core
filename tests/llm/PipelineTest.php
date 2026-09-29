<?php

use Jambura\LLM\Context;
use Jambura\LLM\Gatekeeper;
use Jambura\LLM\LLMException;
use Jambura\LLM\Pipeline;
use Jambura\LLM\Preprocessor;
use Jambura\LLM\Prompt;
use Jambura\LLM\Step;
use PHPUnit\Framework\TestCase;

class AttachmentGate implements Gatekeeper
{
    public function allows(Context $context)
    {
        $attachments = $context->get('attachments', []);
        return $attachments ? ['attachment_count' => count($attachments)] : false;
    }
}

class FileStore implements Preprocessor
{
    public function process(Context $context): array
    {
        return ['saved' => count($context->get('attachments', [])), 'currency' => $context->setting('currency')];
    }
}

class VendorLookup implements Preprocessor
{
    public function process(Context $context): array
    {
        $context->prompt()->addContext('retrieved', 'Vendor: Acme');
        return ['vendor' => 'Acme'];
    }
}

class ResponseFiler implements Step
{
    public function handle(Context $context)
    {
        return ['filed' => strlen((string) $context->get('response'))];
    }
}

class CountedStep implements Step
{
    public static $built = 0;

    public function __construct()
    {
        self::$built++;
    }

    public function handle(Context $context)
    {
        return ['built' => self::$built];
    }
}

class StatefulStep implements Step
{
    /** @var string */
    private $tag;

    public function __construct($tag)
    {
        $this->tag = $tag;
    }

    public function handle(Context $context)
    {
        return ['tag' => $this->tag];
    }
}

class NotAStep
{
}

/**
 * An adapter that hands back the prompt it was given, so a test can see exactly
 * what the pipeline sent.
 */
class EchoModel extends \Jambura\LLM
{
    protected function send($formattedPrompt, Prompt $prompt)
    {
        return $formattedPrompt;
    }
}

class PipelineTest extends TestCase
{
    protected function setUp(): void
    {
        Pipeline::forgetPipelines();
        \Jambura\LLM::forgetModels();
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

        $context = Pipeline::use('receipts')
            ->followRoute('default')
            ->feed($this->prompt(), ['attachments' => ['a.pdf', 'b.pdf']]);

        $this->assertSame(
            ['check_attachment', 'save_attachments', 'read_receipt', 'file_response'],
            $context->ranSteps()
        );
        $this->assertSame(2, $context->get('attachment_count'));
        $this->assertSame(2, $context->get('saved'));
        $this->assertSame('CAD', $context->get('currency'));
        $this->assertSame('<task>Extract the total.</task>', $context->get('response'));
        $this->assertSame(strlen('<task>Extract the total.</task>'), $context->get('filed'));
        $this->assertFalse($context->wasStopped());
    }

    public function testAGatekeeperReturningFalseStopsTheRun(): void
    {
        $this->receiptPipeline();

        $context = Pipeline::use('receipts')->followRoute('default')->feed($this->prompt(), ['attachments' => []]);

        $this->assertSame(['check_attachment'], $context->ranSteps());
        $this->assertTrue($context->wasStopped());
        $this->assertSame('check_attachment', $context->stoppedAt());
        $this->assertNull($context->get('response'));
    }

    public function testFeedCopiesThePromptSoTheCallersPromptIsUnchanged(): void
    {
        Pipeline::make('receipts')
            ->preprocessor('attach_vendor', VendorLookup::class)
            ->route('default', ['attach_vendor']);
        $prompt = $this->prompt();

        $context = Pipeline::use('receipts')->feed($prompt);

        $this->assertSame(['retrieved' => ['Vendor: Acme']], $context->prompt()->getContext());
        $this->assertSame([], $prompt->getContext());
        $this->assertNotSame($prompt, $context->prompt());
    }

    public function testWhatAPreprocessorAddsToThePromptIsWhatTheAdapterSends(): void
    {
        Pipeline::make('receipts')
            ->preprocessor('attach_vendor', VendorLookup::class)
            ->model('read_receipt', EchoModel::class)
            ->route('default', ['attach_vendor', 'read_receipt']);

        $context = Pipeline::use('receipts')->feed($this->prompt());

        $this->assertSame(
            "<context>\n  <retrieved>\n    <item>Vendor: Acme</item>\n  </retrieved>\n</context>\n"
            . '<task>Extract the total.</task>',
            $context->get('response')
        );
    }

    public function testAModelStepRegistersItsAdapterWithTheLlmRegistry(): void
    {
        Pipeline::make('receipts')->model('read_receipt', EchoModel::class);

        $this->assertInstanceOf(EchoModel::class, \Jambura\LLM::use(EchoModel::class));
    }

    public function testAModelStepNeedsAnAdapterClass(): void
    {
        $pipeline = Pipeline::make('receipts');

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('must be an adapter extending Jambura\LLM');
        $pipeline->model('read_receipt', FileStore::class);
    }

    public function testAModelStepNamingAMissingClassThrows(): void
    {
        $pipeline = Pipeline::make('receipts');

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('names the adapter class AIModel\Nope, which does not exist');
        $pipeline->model('read_receipt', 'AIModel\Nope');
    }

    public function testAStepClassMustImplementItsVerbsInterface(): void
    {
        $pipeline = Pipeline::make('receipts');

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('must implement Jambura\LLM\Gatekeeper (allows(Context))');
        $pipeline->gatekeeper('check_attachment', NotAStep::class);
    }

    public function testAStepRegisteredUnderTheWrongVerbThrows(): void
    {
        $pipeline = Pipeline::make('receipts');

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('is registered as a gatekeeper, so FileStore must implement');
        $pipeline->gatekeeper('save_attachments', FileStore::class);
    }

    public function testAnUnknownStepKindThrows(): void
    {
        $pipeline = Pipeline::make('receipts');

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage("Unknown step kind 'critic'");
        $pipeline->step('review', function (Context $context) {
                return null;
            }, 'critic');
    }

    public function testAStepCanReplaceThePromptForLaterSteps(): void
    {
        Pipeline::make('receipts')
            ->preprocessor('trim', function (Context $context) {
                $context->setPrompt(Prompt::create()->setTask('Trimmed task'));
                return [];
            })
            ->model('read_receipt', EchoModel::class)
            ->route('default', ['trim', 'read_receipt']);

        $context = Pipeline::use('receipts')->feed($this->prompt());

        $this->assertSame('<task>Trimmed task</task>', $context->get('response'));
    }

    public function testConfigureMergesAndReachesTheSteps(): void
    {
        $pipeline = Pipeline::make('receipts')
            ->configure(['currency' => 'USD', 'token_budget' => 8000])
            ->configure(['currency' => 'CAD'])
            ->preprocessor('save_attachments', FileStore::class)
            ->route('default', ['save_attachments']);

        $this->assertSame(['currency' => 'CAD', 'token_budget' => 8000], $pipeline->settings());
        $this->assertSame('CAD', $pipeline->feed($this->prompt())->get('currency'));
    }

    public function testFeedFallsBackToTheDefaultRoute(): void
    {
        Pipeline::make('receipts')
            ->model('read_receipt', EchoModel::class)
            ->route('default', ['read_receipt']);

        $this->assertSame(
            '<task>Extract the total.</task>',
            Pipeline::use('receipts')->feed($this->prompt())->get('response')
        );
    }

    public function testFeedWithoutAnyRouteToFollowThrows(): void
    {
        Pipeline::make('receipts')->model('read_receipt', EchoModel::class);

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage("Pipeline 'receipts' has no route to follow");
        Pipeline::use('receipts')->feed($this->prompt());
    }

    public function testTheChosenRouteSticksForLaterRuns(): void
    {
        Pipeline::make('receipts')
            ->model('read_receipt', EchoModel::class)
            ->step('quick', function (Context $context) {
                return ['skipped' => true];
            })
            ->route('default', ['read_receipt'])
            ->route('quick', ['quick']);

        $pipeline = Pipeline::use('receipts')->followRoute('quick');

        $this->assertTrue($pipeline->feed($this->prompt())->get('skipped'));
        $this->assertTrue(Pipeline::use('receipts')->feed($this->prompt())->get('skipped'));
    }

    public function testFollowingAnUnknownRouteThrowsAndNamesTheRoutesThereAre(): void
    {
        Pipeline::make('receipts')
            ->model('read_receipt', EchoModel::class)
            ->route('default', ['read_receipt']);

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage("has no route 'retry'. Defined routes: default");
        Pipeline::use('receipts')->followRoute('retry');
    }

    public function testARouteNamingAnUnregisteredStepThrowsBeforeAnythingRuns(): void
    {
        $ran = [];
        Pipeline::make('receipts')
            ->step('first', function (Context $context) use (&$ran) {
                $ran[] = 'first';
                return null;
            })
            ->route('default', ['first', 'typo']);

        try {
            Pipeline::use('receipts')->feed($this->prompt());
            $this->fail('Expected an LLMException');
        } catch (LLMException $e) {
            $this->assertStringContainsString('names steps that were never registered: typo', $e->getMessage());
        }
        $this->assertSame([], $ran);
    }

    public function testTwoStepsCannotShareAName(): void
    {
        $pipeline = Pipeline::make('receipts')->preprocessor('save_attachments', FileStore::class);

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage("already has a step called 'save_attachments'");
        $pipeline->preprocessor('save_attachments', FileStore::class);
    }

    public function testClosuresCanStandInForAnyVerb(): void
    {
        Pipeline::make('receipts')
            ->gatekeeper('positive_total', function (Context $context) {
                return $context->get('total') > 0;
            })
            ->preprocessor('look', function (Context $context) {
                return ['task' => $context->prompt()->getTask()];
            })
            ->route('default', ['positive_total', 'look']);

        $allowed = Pipeline::use('receipts')->feed($this->prompt(), ['total' => 42]);
        $this->assertSame('Extract the total.', $allowed->get('task'));
        $this->assertFalse($allowed->wasStopped());

        $stopped = Pipeline::use('receipts')->feed($this->prompt(), ['total' => 0]);
        $this->assertSame('positive_total', $stopped->stoppedAt());
    }

    public function testAnObjectStepIsUsedAsGiven(): void
    {
        Pipeline::make('receipts')
            ->step('stamp', new StatefulStep('receipts-v2'))
            ->route('default', ['stamp']);

        $this->assertSame('receipts-v2', Pipeline::use('receipts')->feed($this->prompt())->get('tag'));
    }

    public function testAStepClassIsBuiltOnceAcrossRuns(): void
    {
        Pipeline::make('receipts')
            ->step('touch', CountedStep::class)
            ->route('default', ['touch']);

        Pipeline::use('receipts')->feed($this->prompt());
        $context = Pipeline::use('receipts')->feed($this->prompt());

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
            'file_response' => Pipeline::STEP,
        ], $pipeline->definedSteps());
        $this->assertSame(
            ['default' => ['check_attachment', 'save_attachments', 'read_receipt', 'file_response']],
            $pipeline->definedRoutes()
        );
    }

    private function receiptPipeline(): Pipeline
    {
        return Pipeline::make('receipts')
            ->configure(['currency' => 'CAD'])
            ->gatekeeper('check_attachment', AttachmentGate::class)
            ->preprocessor('save_attachments', FileStore::class)
            ->model('read_receipt', EchoModel::class)
            ->step('file_response', ResponseFiler::class)
            ->route('default', ['check_attachment', 'save_attachments', 'read_receipt', 'file_response']);
    }

    private function prompt(): Prompt
    {
        return Prompt::create()->setType('receipt')->setTask('Extract the total.');
    }
}
