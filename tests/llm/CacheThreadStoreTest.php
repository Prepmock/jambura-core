<?php

use Jambura\LLM;
use Jambura\LLM\CacheThreadStore;
use Jambura\LLM\LLMException;
use Jambura\LLM\Prompt;
use PHPUnit\Framework\TestCase;

class CacheChatModel extends LLM
{
    /** @var list<array{role: string, text: string}> */
    public array $received = [];

    protected function send(string $formattedPrompt, Prompt $prompt): string
    {
        $this->received = $prompt->getMessages();
        return 'reply';
    }
}

/**
 * Covers the store chat() falls back on when nothing is wired up.
 *
 * jCache is a process-wide singleton writing to a file, so each case points it
 * at a directory of its own and clears up after itself.
 */
class CacheThreadStoreTest extends TestCase
{
    private string $path;
    private string $previousPath;
    private string $previousName;
    private CacheThreadStore $store;

    protected function setUp(): void
    {
        $cache = jCache::init();
        $this->previousPath = $cache->getCachePath();
        $this->previousName = $cache->getCache();

        $this->path = sys_get_temp_dir() . '/jambura-chat-test-' . getmypid() . '/';
        $cache->setCachePath($this->path);
        $cache->setCache('chat-test');

        $this->store = new CacheThreadStore();

        LLM::forgetModels();
        LLM::forgetThreadStore();
        LLM::registerModels([CacheChatModel::class]);
    }

    protected function tearDown(): void
    {
        $cache = jCache::init();
        $cache->eraseAll();
        $cache->setCachePath($this->previousPath);
        $cache->setCache($this->previousName);

        foreach (glob($this->path . '*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->path)) {
            rmdir($this->path);
        }

        LLM::forgetThreadStore();
    }

    public function testAnUnusedThreadHoldsNoTurns(): void
    {
        $this->assertSame([], $this->store->read('thread-1')->messages());
    }

    public function testAppendedTurnsComeBackInOrder(): void
    {
        $this->store->append('thread-1', [
            ['role' => Prompt::USER, 'text' => 'What is my leave balance?', 'author' => null],
            ['role' => Prompt::ASSISTANT, 'text' => 'You have 12 days.', 'author' => null],
        ]);
        $this->store->append('thread-1', [
            ['role' => Prompt::USER, 'text' => 'And carried over?', 'author' => null],
        ]);

        $this->assertSame([
            ['role' => 'user', 'text' => 'What is my leave balance?', 'author' => null],
            ['role' => 'assistant', 'text' => 'You have 12 days.', 'author' => null],
            ['role' => 'user', 'text' => 'And carried over?', 'author' => null],
        ], $this->store->read('thread-1')->messages());
    }

    public function testThreadsDoNotSeeEachOther(): void
    {
        $this->store->append('thread-1', [['role' => Prompt::USER, 'text' => 'One', 'author' => null]]);
        $this->store->append('thread-2', [['role' => Prompt::USER, 'text' => 'Two', 'author' => null]]);

        $this->assertSame([['role' => 'user', 'text' => 'One', 'author' => null]], $this->store->read('thread-1')->messages());
        $this->assertSame([['role' => 'user', 'text' => 'Two', 'author' => null]], $this->store->read('thread-2')->messages());
    }

    public function testForgetDropsOneThreadAndLeavesTheRest(): void
    {
        $this->store->append('thread-1', [['role' => Prompt::USER, 'text' => 'One', 'author' => null]]);
        $this->store->append('thread-2', [['role' => Prompt::USER, 'text' => 'Two', 'author' => null]]);

        $this->store->forget('thread-1');

        $this->assertSame([], $this->store->read('thread-1')->messages());
        $this->assertSame([['role' => 'user', 'text' => 'Two', 'author' => null]], $this->store->read('thread-2')->messages());
    }

    /**
     * jCache::erase() throws on a key it does not hold. Forgetting a session
     * nobody has used has to stay quiet, or clearing up means first asking
     * whether there is anything to clear.
     *
     * Another session is stored first on purpose: erase() only reaches its throw
     * once the cache file exists, so against an empty cache this would pass with
     * the guard taken out.
     */
    public function testForgettingAnUnusedThreadLeavesTheCacheAlone(): void
    {
        $this->store->append('thread-1', [['role' => Prompt::USER, 'text' => 'One', 'author' => null]]);

        $this->store->forget('never-used');

        $this->assertSame([], $this->store->read('never-used')->messages());
        $this->assertSame([['role' => 'user', 'text' => 'One', 'author' => null]], $this->store->read('thread-1')->messages());
    }

    /**
     * The cache file is a shared namespace. Anything in there that is not a message
     * is dropped rather than handed to an adapter as one.
     */
    public function testMalformedEntriesAreIgnored(): void
    {
        jCache::init()->store('jambura.chat.thread-1', [
            ['role' => 'user', 'text' => 'Kept', 'author' => null],
            ['role' => 'system', 'text' => 'Unknown role', 'author' => null],
            ['role' => 'user'],
            ['role' => 'user', 'text' => ['not', 'a', 'string']],
            'not a message at all',
        ], 0);

        $this->assertSame([['role' => 'user', 'text' => 'Kept', 'author' => null]], $this->store->read('thread-1')->messages());
    }

    public function testAHistoryThatHasRunOutIsNotReturned(): void
    {
        $store = new CacheThreadStore(ttl: 60);
        $store->append('thread-1', [['role' => Prompt::USER, 'text' => 'One', 'author' => null]]);
        $this->assertSame([['role' => 'user', 'text' => 'One', 'author' => null]], $store->read('thread-1')->messages());

        $this->ageCacheEntry('jambura.chat.thread-1', 120);

        // jCache::get() reads straight past an expiry, so this is the isAvailable()
        // guard in messages() doing the work.
        $this->assertSame([], $store->read('thread-1')->messages());
    }

    public function testANegativeTimeToLiveIsRejected(): void
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('A chat history time to live cannot be negative');
        new CacheThreadStore(ttl: -1);
    }

    /**
     * The point of shipping a default: chat() works with no storeThreadsIn() line
     * at all.
     */
    public function testChatWorksWithNoStoreConfigured(): void
    {
        $model = LLM::use(CacheChatModel::class);

        $model->chat(Prompt::create()->setTask('What is my leave balance?'), 'thread-1');
        $this->assertSame([], $model->received);

        $model->chat(Prompt::create()->setTask('And carried over?'), 'thread-1');

        $this->assertSame([
            ['role' => 'user', 'text' => 'What is my leave balance?', 'author' => null],
            ['role' => 'assistant', 'text' => 'reply', 'author' => null],
        ], $model->received);
        $this->assertCount(4, LLM::threads()->read('thread-1')->messages());
    }

    /**
     * Backdates a cache entry so an expiry can be tested without sleeping.
     */
    private function ageCacheEntry(string $key, int $seconds): void
    {
        $file = jCache::init()->getCacheDir();
        $data = json_decode(file_get_contents($file), true);
        $data[$key]['time'] -= $seconds;
        file_put_contents($file, json_encode($data));
    }
}
