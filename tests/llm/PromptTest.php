<?php

use Jambura\LLM\LLMException;
use Jambura\LLM\Prompt;
use Jambura\LLM\Thread;
use PHPUnit\Framework\TestCase;

class PromptTest extends TestCase
{
    public function testHoldsEachPartSeparately()
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

    public function testContextKeepsSectionOrderAndOmitsEmptySections()
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

    public function testNewPromptIsEmpty()
    {
        $prompt = new Prompt();

        $this->assertNull($prompt->getType());
        $this->assertNull($prompt->getRole());
        $this->assertSame([], $prompt->getContext());
        $this->assertSame([], $prompt->getInstructions());
        $this->assertNull($prompt->getTask());
    }

    public function testAddContextWithNoItemsChangesNothing()
    {
        $prompt = Prompt::create()->addContext('static');

        $this->assertSame([], $prompt->getContext());
    }

    public function testAddContextRejectsAnUnknownSection()
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage("Unknown context section 'history'");
        Prompt::create()->addContext('history', 'x');
    }

    public function testCarriesDocumentsInTheOrderTheyWereAdded()
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
    public function testADocumentPathIsNotResolvedOrOpened()
    {
        $prompt = Prompt::create()->addDocument('/no/such/file.pdf');

        $this->assertSame('/no/such/file.pdf', $prompt->getDocuments()[0]['path']);
    }

    public function testADocumentNeedsAPath()
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('A document needs a path');
        Prompt::create()->addDocument('   ');
    }

    public function testOptionsAreNamedAndOverwritable()
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
    public function testGetOptionDistinguishesAFalsyValueFromAnAbsentOne()
    {
        $prompt = Prompt::create()->setOption('temperature', 0.0);

        $this->assertSame(0.0, $prompt->getOption('temperature', 0.7));
        $this->assertSame(0.7, $prompt->getOption('top_p', 0.7));
        $this->assertNull($prompt->getOption('top_p'));
    }

    public function testAnOptionNeedsAName()
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('An option needs a name');
        Prompt::create()->setOption('', 1);
    }

    public function testMessagesAreKeptInTheOrderTheyWereSpoken()
    {
        $prompt = Prompt::create()
            ->addMessage(Prompt::USER, 'What is my leave balance?')
            ->addMessage(Prompt::ASSISTANT, 'You have 12 days.')
            ->addMessage(Prompt::USER, 'And carried over?');

        $this->assertSame([
            ['role' => 'user', 'text' => 'What is my leave balance?', 'author' => null],
            ['role' => 'assistant', 'text' => 'You have 12 days.', 'author' => null],
            ['role' => 'user', 'text' => 'And carried over?', 'author' => null],
        ], $prompt->getMessages());
    }

    /**
     * Messages are not context. A prompt carrying a conversation still renders an
     * empty context section, so a reducer or an adapter reading one does not
     * find the other.
     */
    public function testMessagesAreNotContext()
    {
        $prompt = Prompt::create()->addMessage(Prompt::USER, 'Hello');

        $this->assertSame([], $prompt->getContext());
    }

    public function testAMessageNeedsAKnownRole()
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage("Unknown message role 'system'. Use one of: user, assistant");
        Prompt::create()->addMessage('system', 'You are an analyst');
    }

    /**
     * The role is checked; the text is not. An empty reply is a thing a model
     * can do, and refusing it here would throw after the call already succeeded.
     */
    public function testAMessageAcceptsEmptyText()
    {
        $prompt = Prompt::create()->addMessage(Prompt::ASSISTANT, '');

        $this->assertSame([['role' => 'assistant', 'text' => '', 'author' => null]], $prompt->getMessages());
    }

    public function testAMessageCarriesTheAuthorWhoSpokeIt()
    {
        $prompt = Prompt::create()->addMessage(Prompt::USER, 'What is the ETA?', 'Kamrul');

        $this->assertSame(
            [['role' => 'user', 'text' => 'What is the ETA?', 'author' => 'Kamrul']],
            $prompt->getMessages()
        );
    }

    public function testLoadConversationPutsAThreadInFrontOfMessagesAlreadyAdded()
    {
        $prompt = Prompt::create()->setTask('And customs?');
        $prompt->addMessage(Prompt::USER, 'Added by hand', 'Laltu');

        $prompt->loadConversation(new Thread('thread-1', [
            ['role' => 'user', 'text' => 'ETA?', 'author' => 'Kamrul'],
            ['role' => 'assistant', 'text' => '14:00', 'author' => null],
        ]));

        $this->assertSame([
            ['role' => 'user', 'text' => 'ETA?', 'author' => 'Kamrul'],
            ['role' => 'assistant', 'text' => '14:00', 'author' => null],
            ['role' => 'user', 'text' => 'Added by hand', 'author' => 'Laltu'],
        ], $prompt->getMessages());
    }

    public function testLoadConversationNeedsNoStoreOrFilesystem()
    {
        $prompt = Prompt::create()->setTask('x')->loadConversation(new Thread('thread-1'));

        $this->assertSame([], $prompt->getMessages());
    }

    /**
     * It loads in front of what is there rather than replacing it, so loading
     * twice loads twice - which is why chat() works on a copy.
     */
    public function testLoadingTheSameThreadTwiceLoadsItTwice()
    {
        $thread = new Thread('thread-1', [['role' => 'user', 'text' => 'ETA?', 'author' => null]]);
        $prompt = Prompt::create()->setTask('And customs?');

        $prompt->loadConversation($thread)->loadConversation($thread);

        $this->assertCount(2, $prompt->getMessages());
    }

    public function testLoadConversationRejectsAThreadWithAnUnknownRole()
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage("Unknown message role 'system'");
        Prompt::create()->setTask('x')->loadConversation(
            new Thread('thread-1', [['role' => 'system', 'text' => 'nope', 'author' => null]])
        );
    }

    public function testForgetConversationLeavesTheRestOfThePromptAlone()
    {
        $prompt = Prompt::create()
            ->setRole('Analyst')
            ->addContext('static', 'Port rules')
            ->setTask('And customs?');
        $prompt->addMessage(Prompt::USER, 'ETA?');

        $prompt->forgetConversation();

        $this->assertSame([], $prompt->getMessages());
        $this->assertSame('Analyst', $prompt->getRole());
        $this->assertSame(['static' => ['Port rules']], $prompt->getContext());
        $this->assertSame('And customs?', $prompt->getTask());
    }

    public function testANewPromptCarriesNoDocumentsOrOptions()
    {
        $prompt = new Prompt();

        $this->assertSame([], $prompt->getDocuments());
        $this->assertSame([], $prompt->getOptions());
        $this->assertSame([], $prompt->getMessages());
    }
}
