<?php

use Jambura\LLM\Context;
use Jambura\LLM\Prompt;
use PHPUnit\Framework\TestCase;

class ContextTest extends TestCase
{
    public function testStartsFromThePromptValuesAndSettingsItIsGiven(): void
    {
        $prompt = Prompt::create()->setTask('Extract the total.');
        $context = new Context($prompt, ['attachments' => ['a.pdf']], ['currency' => 'CAD']);

        $this->assertSame($prompt, $context->prompt());
        $this->assertSame(['a.pdf'], $context->get('attachments'));
        $this->assertSame('CAD', $context->setting('currency'));
        $this->assertSame(['attachments' => ['a.pdf']], $context->all());
        $this->assertSame(['currency' => 'CAD'], $context->settings());
    }

    public function testThePromptCanBeAddedToInPlace(): void
    {
        $context = new Context(Prompt::create()->setTask('Extract the total.'));
        $context->prompt()->addContext('retrieved', 'Vendor: Acme');

        $this->assertSame(['retrieved' => ['Vendor: Acme']], $context->prompt()->getContext());
    }

    public function testSetPromptReplacesThePrompt(): void
    {
        $context = new Context(Prompt::create()->setTask('First'));
        $replacement = Prompt::create()->setTask('Second');

        $context->setPrompt($replacement);

        $this->assertSame($replacement, $context->prompt());
        $this->assertSame('Second', $context->prompt()->getTask());
    }

    public function testGetAndSettingFallBackToTheDefault(): void
    {
        $context = new Context(Prompt::create()->setTask('Extract the total.'));

        $this->assertNull($context->get('response'));
        $this->assertSame('none', $context->get('response', 'none'));
        $this->assertSame('USD', $context->setting('currency', 'USD'));
    }

    public function testSetAndMergeWriteValues(): void
    {
        $context = new Context(Prompt::create()->setTask('Extract the total.'), ['a' => 1]);
        $context->set('b', 2)->merge(['a' => 'overwritten', 'c' => 3]);

        $this->assertSame(['a' => 'overwritten', 'b' => 2, 'c' => 3], $context->all());
    }

    public function testHasIsTrueForAValueSetToNull(): void
    {
        $context = new Context(Prompt::create()->setTask('Extract the total.'));

        $this->assertFalse($context->has('reply'));
        $context->set('reply', null);
        $this->assertTrue($context->has('reply'));
    }

    public function testTracksWhichStepsRanAndWhereItStopped(): void
    {
        $context = new Context(Prompt::create()->setTask('Extract the total.'));
        $this->assertSame([], $context->ranSteps());
        $this->assertFalse($context->wasStopped());
        $this->assertNull($context->stoppedAt());

        $context->markRan('check_attachment');
        $context->markStopped('check_attachment');

        $this->assertSame(['check_attachment'], $context->ranSteps());
        $this->assertTrue($context->wasStopped());
        $this->assertSame('check_attachment', $context->stoppedAt());
    }
}
