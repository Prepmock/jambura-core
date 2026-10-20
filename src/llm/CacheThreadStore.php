<?php
namespace Jambura\LLM;

/**
 * The ThreadStore LLM::chat() falls back on, over jCache.
 *
 * It is here so that chat() works the moment you call it, with no bootstrap
 * line and no migration — enough to try a conversation out, and enough for a
 * single-process script.
 *
 * It is not enough for a busy application, and the reason is worth knowing.
 * jCache keeps every key in one JSON file and rewrites the whole file on each
 * store(), so two requests appending to the same thread id at the same moment
 * do not interleave their messages: the second write is built from the state the
 * first had not finished writing, and a message is lost outright. Elsewhere the
 * framework says last one wins; here, last one wins the whole file. Put a real
 * store behind LLM::storeThreadsIn() before two users can share a thread id.
 *
 * Whatever is read back is checked before it is trusted. The cache file is a
 * shared namespace that anything may write to, and a malformed entry would
 * otherwise surface as a type error deep inside handlePrompt().
 */
class CacheThreadStore implements ThreadStore
{
    /**
     * Prefixed so that chat histories cannot collide with whatever else the
     * application keeps in the same cache file.
     */
    private const PREFIX = 'jambura.chat.';

    /**
     * @var \jCache
     */
    private $cache;

    /**
     * Seconds a history survives, 0 to never expire.
     * @var int
     */
    private $ttl;

    /**
     * @param \jCache|null $cache the cache to use, or null for the shared one
     * @param int          $ttl   seconds a history survives, 0 to never expire
     *
     * @throws LLMException if $ttl is negative
     */
    public function __construct(?\jCache $cache = null, $ttl = 0)
    {
        if ($ttl < 0) {
            throw new LLMException('A chat history time to live cannot be negative');
        }
        $this->cache = $cache === null ? \jCache::init() : $cache;
        $this->ttl = $ttl;
    }

    /**
     * @param string $threadId
     * @return Thread
     */
    public function read($threadId)
    {
        return new Thread($threadId, $this->stored($threadId));
    }

    /**
     * The messages held under a thread id, or none when it holds nothing.
     *
     * @param string $threadId
     * @return array
     */
    private function stored($threadId)
    {
        $key = self::PREFIX . $threadId;

        // isAvailable() first because jCache::get() reads straight past an
        // expiry; on its own it would hand back a history that has run out.
        if (!$this->cache->isAvailable($key)) {
            return [];
        }

        return $this->clean($this->cache->get($key));
    }

    /**
     * @param string $threadId
     * @param array  $messages each ['role' => string, 'text' => string,
     *                         'author' => string|null], oldest first
     * @return void
     */
    public function append($threadId, array $messages)
    {
        $messages = $this->clean($messages);
        if (!$messages) {
            return;
        }

        $this->cache->store(
            self::PREFIX . $threadId,
            array_merge($this->stored($threadId), $messages),
            $this->ttl
        );
    }

    /**
     * @param string $threadId
     * @return void
     */
    public function forget($threadId)
    {
        $key = self::PREFIX . $threadId;

        // Checked first because jCache::erase() throws on a key it does not
        // hold, and forgetting a thread nobody has used is not an error.
        if ($this->cache->get($key) === null) {
            return;
        }
        $this->cache->erase($key);
    }

    /**
     * Keeps the entries that are messages and drops anything else.
     *
     * An author is kept when it is a string and dropped otherwise, so a
     * malformed one cannot reach a rendered prompt.
     *
     * @param mixed $messages
     * @return array
     */
    private function clean($messages)
    {
        if (!is_array($messages)) {
            return [];
        }

        $clean = [];
        foreach ($messages as $message) {
            if (
                is_array($message)
                && isset($message['role'], $message['text'])
                && in_array($message['role'], Prompt::ROLES, true)
                && is_string($message['text'])
            ) {
                $clean[] = [
                    'role' => $message['role'],
                    'text' => $message['text'],
                    'author' => isset($message['author']) && is_string($message['author'])
                        ? $message['author']
                        : null,
                ];
            }
        }
        return $clean;
    }
}
