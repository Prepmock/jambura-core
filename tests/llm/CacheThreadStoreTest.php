<?php

use Jambura\LLM;
use Jambura\LLM\CacheThreadStore;
use Jambura\LLM\LLMException;
use Jambura\LLM\Prompt;
use PHPUnit\Framework\TestCase;

class CacheChatModel extends LLM
{
    /** @var array each ['role' => string, 'text' => string, 'author' => string|null] */
    public $received = [];

    protected function send($formattedPrompt, Prompt $prompt)
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
    /** @var string */
    private $path;

    /** @var string */
    private $previousPath;

    /** @var string */
    private $previousName;

    /** @var CacheThreadStore */
    private $store;

    protected function setUp()
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

    protected function tearDown()
    {
        $cache = jCache::init();
        $cache->eraseAll();
        $cache->setCachePath($this->previousPath);
        $cache->setCache($this->previousName);

        $files = glob($this->path . '*');
        foreach ($files ? $files : [] as $file) {
            unlink($file);
        }
        if (is_dir($this->path)) {
            rmdir($this->path);
        }

        LLM::forgetThreadStore();
    }

    public function testAnUnusedThreadHoldsNoMessages()
    {
        $this->assertSame([], $this->store->read('thread-1')->messages());
    }

    public function testAppendedMessagesComeBackInOrder()
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

    public function testThreadsDoNotSeeEachOther()
    {
        $this->store->append('thread-1', [['role' => Prompt::USER, 'text' => 'One', 'author' => null]]);
        $this->store->append('thread-2', [['role' => Prompt::USER, 'text' => 'Two', 'author' => null]]);

        $this->assertSame(
            [['role' => 'user', 'text' => 'One', 'author' => null]],
            $this->store->read('thread-1')->messages()
        );
        $this->assertSame(
            [['role' => 'user', 'text' => 'Two', 'author' => null]],
            $this->store->read('thread-2')->messages()
        );
    }

    public function testForgetDropsOneThreadAndLeavesTheRest()
    {
        $this->store->append('thread-1', [['role' => Prompt::USER, 'text' => 'One', 'author' => null]]);
        $this->store->append('thread-2', [['role' => Prompt::USER, 'text' => 'Two', 'author' => null]]);

        $this->store->forget('thread-1');

        $this->assertSame([], $this->store->read('thread-1')->messages());
        $this->assertSame(
            [['role' => 'user', 'text' => 'Two', 'author' => null]],
            $this->store->read('thread-2')->messages()
        );
    }

    /**
     * jCache::erase() throws on a key it does not hold. Forgetting a thread
     * nobody has used has to stay quiet, or clearing up means first asking
     * whether there is anything to clear.
     *
     * Another thread is stored first on purpose: erase() only reaches its throw
     * once the cache file exists, so against an empty cache this would pass with
     * the guard taken out.
     */
    public function testForgettingAnUnusedThreadLeavesTheCacheAlone()
    {
        $this->store->append('thread-1', [['role' => Prompt::USER, 'text' => 'One', 'author' => null]]);

        $this->store->forget('never-used');

        $this->assertSame([], $this->store->read('never-used')->messages());
        $this->assertSame(
            [['role' => 'user', 'text' => 'One', 'author' => null]],
            $this->store->read('thread-1')->messages()
        );
    }

    /**
     * The cache file is a shared namespace. Anything in there that is not a message
     * is dropped rather than handed to an adapter as one.
     */
    public function testMalformedEntriesAreIgnored()
    {
        jCache::init()->store('jambura.chat.thread-1', [
            ['role' => 'user', 'text' => 'Kept', 'author' => null],
            ['role' => 'system', 'text' => 'Unknown role', 'author' => null],
            ['role' => 'user'],
            ['role' => 'user', 'text' => ['not', 'a', 'string']],
            'not a message at all',
        ], 0);

        $this->assertSame(
            [['role' => 'user', 'text' => 'Kept', 'author' => null]],
            $this->store->read('thread-1')->messages()
        );
    }

    /**
     * An author written by something else is kept when it is a string and
     * dropped when it is not, so a malformed one cannot reach a rendered prompt.
     */
    public function testAnAuthorIsKeptWhenItIsAStringAndDroppedWhenItIsNot()
    {
        jCache::init()->store('jambura.chat.thread-1', [
            ['role' => 'user', 'text' => 'Named', 'author' => 'Kamrul'],
            ['role' => 'user', 'text' => 'Not named', 'author' => ['an', 'array']],
        ], 0);

        $this->assertSame([
            ['role' => 'user', 'text' => 'Named', 'author' => 'Kamrul'],
            ['role' => 'user', 'text' => 'Not named', 'author' => null],
        ], $this->store->read('thread-1')->messages());
    }

    public function testAHistoryThatHasRunOutIsNotReturned()
    {
        $store = new CacheThreadStore(null, 60);
        $store->append('thread-1', [['role' => Prompt::USER, 'text' => 'One', 'author' => null]]);
        $this->assertSame(
            [['role' => 'user', 'text' => 'One', 'author' => null]],
            $store->read('thread-1')->messages()
        );

        $this->ageCacheEntry('jambura.chat.thread-1', 120);

        // jCache::get() reads straight past an expiry, so this is the isAvailable()
        // guard in stored() doing the work.
        $this->assertSame([], $store->read('thread-1')->messages());
    }

    public function testANegativeTimeToLiveIsRejected()
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('A chat history time to live cannot be negative');
        new CacheThreadStore(null, -1);
    }

    /**
     * The point of shipping a default: chat() works with no storeThreadsIn() line
     * at all.
     */
    public function testChatWorksWithNoStoreConfigured()
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
     *
     * @param string $key
     * @param int    $seconds
     */
    private function ageCacheEntry($key, $seconds)
    {
        $file = jCache::init()->getCacheDir();
        $data = json_decode(file_get_contents($file), true);
        $data[$key]['time'] -= $seconds;
        file_put_contents($file, json_encode($data));
    }
}
