<?php

use Jambura\LLM\Thread;
use PHPUnit\Framework\TestCase;

class ThreadTest extends TestCase
{
    public function testCarriesAnIdAndItsMessages()
    {
        $thread = new Thread('thread-1', [
            ['role' => 'user', 'text' => 'ETA?', 'author' => 'Kamrul'],
            ['role' => 'assistant', 'text' => '14:00', 'author' => null],
        ]);

        $this->assertSame('thread-1', $thread->id());
        $this->assertCount(2, $thread->messages());
        $this->assertSame(2, $thread->count());
        $this->assertFalse($thread->isEmpty());
    }

    public function testAnUnusedThreadIsEmpty()
    {
        $thread = new Thread('thread-1');

        $this->assertSame([], $thread->messages());
        $this->assertTrue($thread->isEmpty());
        $this->assertSame(0, $thread->count());
    }

    public function testRecentKeepsTheNewestMessagesAndTheId()
    {
        $thread = $this->thread(5);

        $recent = $thread->recent(2);

        $this->assertSame(['four', 'five'], array_column($recent->messages(), 'text'));
        $this->assertSame('thread-1', $recent->id());
        $this->assertSame(5, $thread->count(), 'the original is left alone');
    }

    public function testRecentAsksForMoreThanThereIsAndGetsWhatThereIs()
    {
        $this->assertSame(3, $this->thread(3)->recent(10)->count());
    }

    /**
     * Zero is how an adapter asks for no history at all, and a negative is the
     * same question asked badly: neither may hand back the whole thread, which
     * is what array_slice() with an offset of -0 would do.
     */
    public function testRecentOfNoneIsEmpty()
    {
        $this->assertTrue($this->thread(3)->recent(0)->isEmpty());
        $this->assertTrue($this->thread(3)->recent(-1)->isEmpty());
    }

    /**
     * @param int $count
     * @return Thread
     */
    private function thread($count)
    {
        $words = ['one', 'two', 'three', 'four', 'five'];
        $messages = [];
        for ($i = 0; $i < $count; $i++) {
            $messages[] = ['role' => 'user', 'text' => $words[$i], 'author' => null];
        }
        return new Thread('thread-1', $messages);
    }
}
