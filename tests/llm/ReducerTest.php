<?php

use Jambura\LLM;
use Jambura\LLM\ContextFilter;
use Jambura\LLM\LLMException;
use Jambura\LLM\Prompt;
use Jambura\LLM\PromptRejected;
use Jambura\LLM\Reducer;
use PHPUnit\Framework\TestCase;

/**
 * Records that it ran, so a test can assert which reducers a prompt met and in
 * what order. Changes the prompt too, so the version that reaches send() can be
 * told from the one the caller kept.
 */
class TraceReducer implements Reducer
{
    /** @var string[] names of the reducers that have run */
    public static array $ran = [];

    /**
     * @param string[] $types
     */
    public function __construct(private string $name, private array $types = [])
    {
    }

    public function types(): array
    {
        return $this->types;
    }

    public function reduce(Prompt $prompt): Prompt
    {
        self::$ran[] = $this->name;
        return $prompt->addInstruction("ran {$this->name}");
    }
}

/** Registered by class name, so it must build with no arguments. */
class NoArgsReducer implements Reducer
{
    public function types(): array
    {
        return [];
    }

    public function reduce(Prompt $prompt): Prompt
    {
        return $prompt->addInstruction('ran NoArgsReducer');
    }
}

class NeedsArgsReducer implements Reducer
{
    public function __construct(private string $setting)
    {
    }

    public function types(): array
    {
        return [];
    }

    public function reduce(Prompt $prompt): Prompt
    {
        return $prompt;
    }
}

abstract class AbstractReducer implements Reducer
{
}

class BlankTypeReducer implements Reducer
{
    public function types(): array
    {
        return ['receipt', ' '];
    }

    public function reduce(Prompt $prompt): Prompt
    {
        return $prompt;
    }
}

class RefusingReducer implements Reducer
{
    public function types(): array
    {
        return [];
    }

    public function reduce(Prompt $prompt): Prompt
    {
        throw new PromptRejected('nothing here worth asking a model');
    }
}

/** Returns a prompt with the task missing, which the pipeline must catch. */
class TaskLosingReducer implements Reducer
{
    public function types(): array
    {
        return [];
    }

    public function reduce(Prompt $prompt): Prompt
    {
        return Prompt::create()->setRole('Analyst');
    }
}

class NotAReducer
{
}

/** Keeps whatever prompt reached send(), after the reducers had it. */
class ReducerSpyModel extends LLM
{
    public ?Prompt $received = null;

    protected function send(string $formattedPrompt, Prompt $prompt): string
    {
        $this->received = $prompt;
        return 'reply';
    }
}

class ReducerTest extends TestCase
{
    protected function setUp(): void
    {
        LLM::forgetModels();
        LLM::forgetReducers();
        TraceReducer::$ran = [];
    }

    protected function tearDown(): void
    {
        // Reducers are global, so leaving one registered would quietly rewrite
        // the prompts every other test file sends.
        LLM::forgetReducers();
    }

    // ---------------------------------------------------------------- registration

    public function testRegistersAClassNameAndAnInstance(): void
    {
        LLM::registerReducers([NoArgsReducer::class, new TraceReducer('instance')]);

        $reducers = LLM::reducers();
        $this->assertCount(2, $reducers);
        $this->assertInstanceOf(NoArgsReducer::class, $reducers[0]);
        $this->assertInstanceOf(TraceReducer::class, $reducers[1]);
    }

    public function testLeadingBackslashInAClassNameIsIgnored(): void
    {
        LLM::registerReducers(['\NoArgsReducer']);

        $this->assertInstanceOf(NoArgsReducer::class, LLM::reducers()[0]);
    }

    public function testRejectsAClassThatDoesNotExist(): void
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('Reducer class NoSuchReducer does not exist');

        LLM::registerReducers(['NoSuchReducer']);
    }

    public function testRejectsAClassThatIsNotAReducer(): void
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('must be a concrete implementation of');

        LLM::registerReducers([NotAReducer::class]);
    }

    public function testRejectsAnAbstractReducer(): void
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('must be a concrete implementation of');

        LLM::registerReducers([AbstractReducer::class]);
    }

    public function testRejectsAClassNameThatNeedsConstructorArguments(): void
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('register it as an instance');

        LLM::registerReducers([NeedsArgsReducer::class]);
    }

    public function testRejectsSomethingThatIsNeitherClassNameNorReducer(): void
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('must be a class name or an instance of');

        LLM::registerReducers([42]);
    }

    public function testRejectsABlankPromptType(): void
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('must return prompt type names');

        LLM::registerReducers([BlankTypeReducer::class]);
    }

    public function testRegisteringAppendsRatherThanReplacing(): void
    {
        LLM::registerReducers([new TraceReducer('first')]);
        LLM::registerReducers([new TraceReducer('second')]);

        $this->assertCount(2, LLM::reducers());
    }

    public function testForgetReducersEmptiesTheRegistry(): void
    {
        LLM::registerReducers([NoArgsReducer::class]);
        LLM::forgetReducers();

        $this->assertSame([], LLM::reducers());
    }

    // ---------------------------------------------------------------- routing

    public function testAReducerWithNoTypesRunsForEveryPrompt(): void
    {
        LLM::registerReducers([new TraceReducer('all')]);

        $this->send(Prompt::create()->setType('receipt')->setTask('Extract'));
        $this->send(Prompt::create()->setTask('Extract'));

        $this->assertSame(['all', 'all'], TraceReducer::$ran);
    }

    public function testAReducerRunsOnlyForTheTypesItNames(): void
    {
        LLM::registerReducers([
            new TraceReducer('receipts', ['receipt']),
            new TraceReducer('voyages', ['voyage-summary']),
        ]);

        $this->send(Prompt::create()->setType('receipt')->setTask('Extract'));

        $this->assertSame(['receipts'], TraceReducer::$ran);
    }

    public function testAPromptWithNoTypeMeetsOnlyTheReducersThatRunForEverything(): void
    {
        LLM::registerReducers([
            new TraceReducer('receipts', ['receipt']),
            new TraceReducer('all'),
        ]);

        $this->send(Prompt::create()->setTask('Extract'));

        $this->assertSame(['all'], TraceReducer::$ran);
    }

    public function testATypeNoReducerNamesPassesThroughUntouched(): void
    {
        LLM::registerReducers([new TraceReducer('receipts', ['receipt'])]);

        $reached = $this->send(Prompt::create()->setType('greeting')->setTask('Say hello'));

        $this->assertSame([], TraceReducer::$ran);
        $this->assertSame([], $reached->getInstructions());
    }

    public function testOneReducerMayNameSeveralTypes(): void
    {
        LLM::registerReducers([new TraceReducer('both', ['receipt', 'invoice'])]);

        $this->send(Prompt::create()->setType('invoice')->setTask('Extract'));

        $this->assertSame(['both'], TraceReducer::$ran);
    }

    // ---------------------------------------------------------------- order

    public function testReducersRunInRegistrationOrder(): void
    {
        LLM::registerReducers([
            new TraceReducer('first'),
            new TraceReducer('second', ['receipt']),
            new TraceReducer('third'),
        ]);

        $reached = $this->send(Prompt::create()->setType('receipt')->setTask('Extract'));

        $this->assertSame(['first', 'second', 'third'], TraceReducer::$ran);
        $this->assertSame(['ran first', 'ran second', 'ran third'], $reached->getInstructions());
    }

    public function testEachReducerIsHandedWhatTheLastOneReturned(): void
    {
        LLM::registerReducers([
            new TraceReducer('first'),
            new TraceReducer('second'),
        ]);

        $reached = $this->send(Prompt::create()->setTask('Extract'));

        $this->assertSame(['ran first', 'ran second'], $reached->getInstructions());
    }

    // ---------------------------------------------------------------- short-circuiting

    public function testARejectionStopsTheCall(): void
    {
        LLM::registerReducers([new RefusingReducer()]);

        $this->expectException(PromptRejected::class);
        $this->expectExceptionMessage('nothing here worth asking a model');

        $this->send(Prompt::create()->setTask('Extract'));
    }

    public function testARejectionNamesTheReducerThatRefused(): void
    {
        LLM::registerReducers([new RefusingReducer()]);

        try {
            $this->send(Prompt::create()->setTask('Extract'));
            $this->fail('Expected the prompt to be rejected');
        } catch (PromptRejected $rejection) {
            $this->assertSame(RefusingReducer::class, $rejection->reducer());
        }
    }

    public function testARejectionIsAlsoAnLLMException(): void
    {
        LLM::registerReducers([new RefusingReducer()]);

        $this->expectException(LLMException::class);

        $this->send(Prompt::create()->setTask('Extract'));
    }

    public function testReducersAfterARejectionDoNotRun(): void
    {
        LLM::registerReducers([
            new TraceReducer('before'),
            new RefusingReducer(),
            new TraceReducer('after'),
        ]);

        try {
            $this->send(Prompt::create()->setTask('Extract'));
        } catch (PromptRejected) {
            // asserted below
        }

        $this->assertSame(['before'], TraceReducer::$ran);
    }

    public function testARejectedPromptNeverReachesTheModel(): void
    {
        LLM::registerReducers([new RefusingReducer()]);
        LLM::registerModels([ReducerSpyModel::class]);
        $model = LLM::use(ReducerSpyModel::class);

        try {
            $model->prompt(Prompt::create()->setTask('Extract'));
        } catch (PromptRejected) {
            // asserted below
        }

        $this->assertNull($model->received);
    }

    // ---------------------------------------------------------------- the caller's prompt

    public function testTheCallersPromptIsLeftUnchanged(): void
    {
        LLM::registerReducers([new ContextFilter(['receipt' => ['static']])]);

        $prompt = Prompt::create()
            ->setType('receipt')
            ->setTask('Extract')
            ->addContext('static', 'Port rules')
            ->addContext('conversation', 'Earlier chatter');

        $reached = $this->send($prompt);

        $this->assertSame(
            ['static' => ['Port rules'], 'conversation' => ['Earlier chatter']],
            $prompt->getContext(),
            "the caller's prompt should still hold everything it was given"
        );
        $this->assertSame(['static' => ['Port rules']], $reached->getContext());
        $this->assertNotSame($prompt, $reached);
    }

    public function testTheSamePromptCanBeSentTwice(): void
    {
        LLM::registerReducers([new TraceReducer('all')]);

        $prompt = Prompt::create()->setTask('Extract');
        $first = $this->send($prompt);
        LLM::forgetModels();
        $second = $this->send($prompt);

        // Each run gets its own copy, so the second is not the first's leftovers.
        $this->assertSame(['ran all'], $first->getInstructions());
        $this->assertSame(['ran all'], $second->getInstructions());
        $this->assertSame([], $prompt->getInstructions());
    }

    public function testWithNoReducersTheModelIsHandedTheCallersOwnPrompt(): void
    {
        $prompt = Prompt::create()->setTask('Extract');

        $this->assertSame($prompt, $this->send($prompt));
    }

    // ---------------------------------------------------------------- guards

    public function testAReducerMayNotDropTheTask(): void
    {
        LLM::registerReducers([new TaskLosingReducer()]);

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('TaskLosingReducer::reduce() returned a prompt with no task');

        $this->send(Prompt::create()->setTask('Extract'));
    }

    public function testAPromptWithNoTaskFailsBeforeAnyReducerRuns(): void
    {
        LLM::registerReducers([new TraceReducer('all')]);

        try {
            $this->send(Prompt::create()->setType('receipt'));
            $this->fail('Expected a prompt with no task to be refused');
        } catch (LLMException $e) {
            $this->assertSame('A prompt needs a task', $e->getMessage());
        }

        $this->assertSame([], TraceReducer::$ran);
    }

    /**
     * Sends a prompt through a spy adapter and returns the prompt that reached it.
     */
    private function send(Prompt $prompt): Prompt
    {
        LLM::registerModels([ReducerSpyModel::class]);
        $model = LLM::use(ReducerSpyModel::class);
        $model->prompt($prompt);

        return $model->received;
    }
}
