<?php

use Jambura\LLM\Context;
use PHPUnit\Framework\TestCase;

class ContextTest extends TestCase
{
    public function testStartsFromTheValuesAndSettingsItIsGiven(): void
    {
        $context = new Context(['input' => 'a receipt'], ['currency' => 'CAD']);

        $this->assertSame('a receipt', $context->get('input'));
        $this->assertSame('CAD', $context->setting('currency'));
        $this->assertSame(['input' => 'a receipt'], $context->all());
        $this->assertSame(['currency' => 'CAD'], $context->settings());
    }

    public function testGetAndSettingFallBackToTheDefault(): void
    {
        $context = new Context();

        $this->assertNull($context->get('response'));
        $this->assertSame('none', $context->get('response', 'none'));
        $this->assertSame('USD', $context->setting('currency', 'USD'));
    }

    public function testSetAndMergeWriteValues(): void
    {
        $context = new Context(['a' => 1]);
        $context->set('b', 2)->merge(['a' => 'overwritten', 'c' => 3]);

        $this->assertSame(['a' => 'overwritten', 'b' => 2, 'c' => 3], $context->all());
    }

    public function testHasIsTrueForAValueSetToNull(): void
    {
        $context = new Context();

        $this->assertFalse($context->has('reply'));
        $context->set('reply', null);
        $this->assertTrue($context->has('reply'));
    }

    public function testTracksWhichStepsRanAndWhereItStopped(): void
    {
        $context = new Context();
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
