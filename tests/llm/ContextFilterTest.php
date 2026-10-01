<?php

use Jambura\LLM\ContextFilter;
use Jambura\LLM\LLMException;
use Jambura\LLM\Prompt;
use PHPUnit\Framework\TestCase;

class ContextFilterTest extends TestCase
{
    public function testKeepsOnlyTheSectionsNamedForTheType(): void
    {
        $filter = new ContextFilter(['receipt' => ['static', 'retrieved']]);

        $reduced = $filter->reduce($this->fullPrompt('receipt'));

        $this->assertSame(
            ['static' => ['Port rules'], 'retrieved' => ['ETA 14:00']],
            $reduced->getContext()
        );
    }

    public function testAnEmptyListDropsEveryContextSection(): void
    {
        $filter = new ContextFilter(['greeting' => []]);

        $reduced = $filter->reduce($this->fullPrompt('greeting'));

        $this->assertSame([], $reduced->getContext());
    }

    public function testATypeTheMapDoesNotNameIsLeftAlone(): void
    {
        $filter = new ContextFilter(['receipt' => ['static']]);

        $reduced = $filter->reduce($this->fullPrompt('voyage-summary'));

        $this->assertCount(4, $reduced->getContext());
    }

    public function testAPromptWithNoTypeIsLeftAlone(): void
    {
        $filter = new ContextFilter(['receipt' => ['static']]);

        $reduced = $filter->reduce(Prompt::create()->setTask('Extract')->addContext('dynamic', 'Now'));

        $this->assertSame(['dynamic' => ['Now']], $reduced->getContext());
    }

    public function testLeavesEverythingButContextAsItWas(): void
    {
        $filter = new ContextFilter(['receipt' => []]);

        $prompt = $this->fullPrompt('receipt')
            ->setRole('Analyst')
            ->addInstruction('Be brief.')
            ->addDocument('/tmp/receipt.pdf', 'application/pdf')
            ->setOption('max_tokens', 8192);

        $reduced = $filter->reduce($prompt);

        $this->assertSame('receipt', $reduced->getType());
        $this->assertSame('Analyst', $reduced->getRole());
        $this->assertSame(['Be brief.'], $reduced->getInstructions());
        $this->assertSame('Extract', $reduced->getTask());
        $this->assertSame(
            [['path' => '/tmp/receipt.pdf', 'media_type' => 'application/pdf']],
            $reduced->getDocuments()
        );
        $this->assertSame(8192, $reduced->getOption('max_tokens'));
    }

    public function testTypesAreTheTypesTheMapNames(): void
    {
        $filter = new ContextFilter(['receipt' => ['static'], 'invoice' => []]);

        $this->assertSame(['receipt', 'invoice'], $filter->types());
    }

    public function testRejectsAnUnknownSectionWhenItIsBuilt(): void
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage("Unknown context section for 'receipt': history");

        new ContextFilter(['receipt' => ['static', 'history']]);
    }

    public function testRejectsABlankType(): void
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('must be a non-empty string');

        new ContextFilter([' ' => ['static']]);
    }

    public function testRejectsSectionsThatAreNotAList(): void
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage("sections kept for 'receipt' must be an array");

        new ContextFilter(['receipt' => 'static']);
    }

    public function testAnEmptyMapKeepsEveryPromptAsItIs(): void
    {
        $filter = new ContextFilter([]);

        $this->assertSame([], $filter->types());
        $this->assertCount(4, $filter->reduce($this->fullPrompt('receipt'))->getContext());
    }

    private function fullPrompt(string $type): Prompt
    {
        return Prompt::create()
            ->setType($type)
            ->setTask('Extract')
            ->addContext('static', 'Port rules')
            ->addContext('retrieved', 'ETA 14:00')
            ->addContext('dynamic', 'Now 13:00')
            ->addContext('conversation', 'Earlier chatter');
    }
}
