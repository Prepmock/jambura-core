<?php

use Jambura\LLM\LLMException;
use Jambura\LLM\Prompt;
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

    public function testANewPromptCarriesNoDocumentsOrOptions()
    {
        $prompt = new Prompt();

        $this->assertSame([], $prompt->getDocuments());
        $this->assertSame([], $prompt->getOptions());
    }
}
