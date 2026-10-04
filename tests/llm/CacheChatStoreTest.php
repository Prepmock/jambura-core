<?php

use Jambura\LLM;
use Jambura\LLM\CacheChatStore;
use Jambura\LLM\LLMException;
use Jambura\LLM\Prompt;
use PHPUnit\Framework\TestCase;

class CacheChatModel extends LLM
{
    /** @var list<array{role: string, text: string}> */
    public array $received = [];

    protected function send(string $formattedPrompt, Prompt $prompt): string
    {
        $this->received = $prompt->getTurns();
        return 'reply';
    }
}

/**
 * Covers the store chat() falls back on when nothing is wired up.
 *
 * jCache is a process-wide singleton writing to a file, so each case points it
 * at a directory of its own and clears up after itself.
 */
class CacheChatStoreTest extends TestCase
{
    private string $path;
    private string $previousPath;
    private string $previousName;
    private CacheChatStore $store;

    protected function setUp(): void
    {
        $cache = jCache::init();
        $this->previousPath = $cache->getCachePath();
        $this->previousName = $cache->getCache();

        $this->path = sys_get_temp_dir() . '/jambura-chat-test-' . getmypid() . '/';
        $cache->setCachePath($this->path);
        $cache->setCache('chat-test');

        $this->store = new CacheChatStore();

        LLM::forgetModels();
        LLM::forgetChatStore();
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

        LLM::forgetChatStore();
    }

    public function testAnUnusedSessionHoldsNoTurns(): void
    {
        $this->assertSame([], $this->store->turns('session-1'));
    }

    public function testAppendedTurnsComeBackInOrder(): void
    {
        $this->store->append('session-1', [
            ['role' => Prompt::USER, 'text' => 'What is my leave balance?'],
            ['role' => Prompt::ASSISTANT, 'text' => 'You have 12 days.'],
        ]);
        $this->store->append('session-1', [
            ['role' => Prompt::USER, 'text' => 'And carried over?'],
        ]);

        $this->assertSame([
            ['role' => 'user', 'text' => 'What is my leave balance?'],
            ['role' => 'assistant', 'text' => 'You have 12 days.'],
            ['role' => 'user', 'text' => 'And carried over?'],
        ], $this->store->turns('session-1'));
    }

    public function testSessionsDoNotSeeEachOther(): void
    {
        $this->store->append('session-1', [['role' => Prompt::USER, 'text' => 'One']]);
        $this->store->append('session-2', [['role' => Prompt::USER, 'text' => 'Two']]);

        $this->assertSame([['role' => 'user', 'text' => 'One']], $this->store->turns('session-1'));
        $this->assertSame([['role' => 'user', 'text' => 'Two']], $this->store->turns('session-2'));
    }

    public function testForgetDropsOneSessionAndLeavesTheRest(): void
    {
        $this->store->append('session-1', [['role' => Prompt::USER, 'text' => 'One']]);
        $this->store->append('session-2', [['role' => Prompt::USER, 'text' => 'Two']]);

        $this->store->forget('session-1');

        $this->assertSame([], $this->store->turns('session-1'));
        $this->assertSame([['role' => 'user', 'text' => 'Two']], $this->store->turns('session-2'));
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
    public function testForgettingAnUnusedSessionLeavesTheCacheAlone(): void
    {
        $this->store->append('session-1', [['role' => Prompt::USER, 'text' => 'One']]);

        $this->store->forget('never-used');

        $this->assertSame([], $this->store->turns('never-used'));
        $this->assertSame([['role' => 'user', 'text' => 'One']], $this->store->turns('session-1'));
    }

    /**
     * The cache file is a shared namespace. Anything in there that is not a turn
     * is dropped rather than handed to an adapter as one.
     */
    public function testMalformedEntriesAreIgnored(): void
    {
        jCache::init()->store('jambura.chat.session-1', [
            ['role' => 'user', 'text' => 'Kept'],
            ['role' => 'system', 'text' => 'Unknown role'],
            ['role' => 'user'],
            ['role' => 'user', 'text' => ['not', 'a', 'string']],
            'not a turn at all',
        ], 0);

        $this->assertSame([['role' => 'user', 'text' => 'Kept']], $this->store->turns('session-1'));
    }

    public function testAHistoryThatHasRunOutIsNotReturned(): void
    {
        $store = new CacheChatStore(ttl: 60);
        $store->append('session-1', [['role' => Prompt::USER, 'text' => 'One']]);
        $this->assertSame([['role' => 'user', 'text' => 'One']], $store->turns('session-1'));

        $this->ageCacheEntry('jambura.chat.session-1', 120);

        // jCache::get() reads straight past an expiry, so this is the isAvailable()
        // guard in turns() doing the work.
        $this->assertSame([], $store->turns('session-1'));
    }

    public function testANegativeTimeToLiveIsRejected(): void
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('A chat history time to live cannot be negative');
        new CacheChatStore(ttl: -1);
    }

    /**
     * The point of shipping a default: chat() works with no storeChatsIn() line
     * at all.
     */
    public function testChatWorksWithNoStoreConfigured(): void
    {
        $model = LLM::use(CacheChatModel::class);

        $model->chat(Prompt::create()->setTask('What is my leave balance?'), 'session-1');
        $this->assertSame([], $model->received);

        $model->chat(Prompt::create()->setTask('And carried over?'), 'session-1');

        $this->assertSame([
            ['role' => 'user', 'text' => 'What is my leave balance?'],
            ['role' => 'assistant', 'text' => 'reply'],
        ], $model->received);
        $this->assertCount(4, LLM::chatTurns('session-1'));
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
