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
}
