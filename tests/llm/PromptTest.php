<?php

use Jambura\LLM\LLMException;
use Jambura\LLM\Prompt;
use PHPUnit\Framework\TestCase;

class PromptTest extends TestCase
{
    public function testHoldsEachPartSeparately(): void
    {
        $prompt = Prompt::create()
            ->setType('summary')
            ->setRole('Analyst')
            ->addInstruction('Be brief.')
            ->addInstruction('Cite sources.', 'Use metric units.')
            ->setTask('Summarize');

        $this->assertSame('summary', $prompt->getType());
        $this->assertSame('Analyst', $prompt->getRole());
        $this->assertSame(['Be brief.', 'Cite sources.', 'Use metric units.'], $prompt->getInstructions());
        $this->assertSame('Summarize', $prompt->getTask());
    }

    public function testContextKeepsSectionOrderAndOmitsEmptySections(): void
    {
        $prompt = Prompt::create()
            ->addContext('conversation', 'User asked about ETAs')
            ->addContext('static', 'Port rules', 'Berth list')
            ->addContext('conversation', 'Assistant replied');

        $this->assertSame([
            'static' => ['Port rules', 'Berth list'],
            'conversation' => ['User asked about ETAs', 'Assistant replied'],
        ], $prompt->getContext());
    }

    public function testNewPromptIsEmpty(): void
    {
        $prompt = new Prompt();

        $this->assertNull($prompt->getType());
        $this->assertNull($prompt->getRole());
        $this->assertSame([], $prompt->getContext());
        $this->assertSame([], $prompt->getInstructions());
        $this->assertNull($prompt->getTask());
    }

    public function testAddContextRejectsAnUnknownSection(): void
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage("Unknown context section 'history'");
        Prompt::create()->addContext('history', 'x');
    }

    public function testCarriesDocumentsInTheOrderTheyWereAdded(): void
    {
        $prompt = Prompt::create()
            ->addDocument('/tmp/receipt.pdf', 'application/pdf')
            ->addDocument('/tmp/scan.png');

        $this->assertSame([
            ['path' => '/tmp/receipt.pdf', 'media_type' => 'application/pdf'],
            ['path' => '/tmp/scan.png', 'media_type' => null],
        ], $prompt->getDocuments());
    }

    /**
     * Nothing is opened when the document is added: a prompt has to be
     * constructable in a test without a file behind it.
     */
    public function testADocumentPathIsNotResolvedOrOpened(): void
    {
        $prompt = Prompt::create()->addDocument('/no/such/file.pdf');

        $this->assertSame('/no/such/file.pdf', $prompt->getDocuments()[0]['path']);
    }

    public function testADocumentNeedsAPath(): void
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('A document needs a path');
        Prompt::create()->addDocument('   ');
    }

    public function testOptionsAreNamedAndOverwritable(): void
    {
        $prompt = Prompt::create()
            ->setOption('max_tokens', 4096)
            ->setOption('temperature', 0.0)
            ->setOption('max_tokens', 8192);

        $this->assertSame(['max_tokens' => 8192, 'temperature' => 0.0], $prompt->getOptions());
        $this->assertSame(8192, $prompt->getOption('max_tokens'));
    }

    /**
     * A temperature of 0.0 is the case that matters: read back with a truthiness
     * test it would look unset, and the caller would silently get the model's
     * default instead of the determinism it asked for.
     */
    public function testGetOptionDistinguishesAFalsyValueFromAnAbsentOne(): void
    {
        $prompt = Prompt::create()->setOption('temperature', 0.0);

        $this->assertSame(0.0, $prompt->getOption('temperature', 0.7));
        $this->assertSame(0.7, $prompt->getOption('top_p', 0.7));
        $this->assertNull($prompt->getOption('top_p'));
    }

    /**
     * And a setting deliberately set to null is still a setting. ?? would hand
     * back the default here.
     */
    public function testAnOptionSetToNullIsNotTreatedAsAbsent(): void
    {
        $prompt = Prompt::create()->setOption('stop_sequences', null);

        $this->assertNull($prompt->getOption('stop_sequences', ['END']));
        $this->assertSame(['stop_sequences' => null], $prompt->getOptions());
    }

    public function testAnOptionNeedsAName(): void
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('An option needs a name');
        Prompt::create()->setOption('', 1);
    }

    public function testANewPromptCarriesNoDocumentsOrOptions(): void
    {
        $prompt = new Prompt();

        $this->assertSame([], $prompt->getDocuments());
        $this->assertSame([], $prompt->getOptions());
        $this->assertSame([], $prompt->getTurns());
    }

    public function testTurnsAreKeptInTheOrderTheyWereSpoken(): void
    {
        $prompt = Prompt::create()
            ->addTurn(Prompt::USER, 'What is my leave balance?')
            ->addTurn(Prompt::ASSISTANT, 'You have 12 days.')
            ->addTurn(Prompt::USER, 'And carried over?');

        $this->assertSame([
            ['role' => 'user', 'text' => 'What is my leave balance?'],
            ['role' => 'assistant', 'text' => 'You have 12 days.'],
            ['role' => 'user', 'text' => 'And carried over?'],
        ], $prompt->getTurns());
    }

    /**
     * Turns are not context. A prompt carrying a conversation still renders an
     * empty context section, so a reducer or an adapter reading one does not
     * find the other.
     */
    public function testTurnsAreNotContext(): void
    {
        $prompt = Prompt::create()->addTurn(Prompt::USER, 'Hello');

        $this->assertSame([], $prompt->getContext());
    }

    public function testClearTurnsDropsEveryTurnAndNothingElse(): void
    {
        $prompt = Prompt::create()
            ->setTask('Summarize')
            ->addContext('conversation', 'Discussed last quarter')
            ->addTurn(Prompt::USER, 'Hello');

        $this->assertSame([], $prompt->clearTurns()->getTurns());
        $this->assertSame(['conversation' => ['Discussed last quarter']], $prompt->getContext());
        $this->assertSame('Summarize', $prompt->getTask());
    }

    public function testATurnNeedsAKnownRole(): void
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage("Unknown turn role 'system'. Use one of: user, assistant");
        Prompt::create()->addTurn('system', 'You are an analyst');
    }

    /**
     * The role is checked; the text is not. An empty reply is a thing a model
     * can do, and refusing it here would throw after the call already succeeded.
     */
    public function testATurnAcceptsEmptyText(): void
    {
        $prompt = Prompt::create()->addTurn(Prompt::ASSISTANT, '');

        $this->assertSame([['role' => 'assistant', 'text' => '']], $prompt->getTurns());
    }
}
