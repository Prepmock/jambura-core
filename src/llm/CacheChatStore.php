<?php
namespace Jambura\LLM;

/**
 * The ChatStore LLM::chat() falls back on, over jCache.
 *
 * It is here so that chat() works the moment you call it, with no bootstrap
 * line and no migration — enough to try a conversation out, and enough for a
 * single-process script.
 *
 * It is not enough for a busy application, and the reason is worth knowing.
 * jCache keeps every key in one JSON file and rewrites the whole file on each
 * store(), so two requests appending to the same session id at the same moment
 * do not interleave their turns: the second write is built from the state the
 * first had not finished writing, and a turn is lost outright. Elsewhere the
 * framework says last one wins; here, last one wins the whole file. Put a real
 * store behind LLM::storeChatsIn() before two users can share a session id.
 *
 * Whatever is read back is checked before it is trusted. The cache file is a
 * shared namespace that anything may write to, and a malformed entry would
 * otherwise surface as a type error deep inside handlePrompt().
 */
class CacheChatStore implements ChatStore
{
    /**
     * Prefixed so that chat histories cannot collide with whatever else the
     * application keeps in the same cache file.
     */
    private const PREFIX = 'jambura.chat.';

    private \jCache $cache;

    /**
     * @param \jCache|null $cache the cache to use, or null for the shared one
     * @param int          $ttl   seconds a history survives, 0 to never expire
     *
     * @throws LLMException if $ttl is negative
     */
    public function __construct(?\jCache $cache = null, private int $ttl = 0)
    {
        if ($ttl < 0) {
            throw new LLMException('A chat history time to live cannot be negative');
        }
        $this->cache = $cache ?? \jCache::init();
    }

    /**
     * @return list<array{role: string, text: string}>
     */
    public function turns(string $sessionId): array
    {
        $key = self::PREFIX . $sessionId;

        // isAvailable() first because jCache::get() reads straight past an
        // expiry; on its own it would hand back a history that has run out.
        if (!$this->cache->isAvailable($key)) {
            return [];
        }

        return self::clean($this->cache->get($key));
    }

    /**
     * @param list<array{role: string, text: string}> $turns oldest first
     */
    public function append(string $sessionId, array $turns): void
    {
        $turns = self::clean($turns);
        if (!$turns) {
            return;
        }

        $this->cache->store(
            self::PREFIX . $sessionId,
            array_merge($this->turns($sessionId), $turns),
            $this->ttl
        );
    }

    public function forget(string $sessionId): void
    {
        $key = self::PREFIX . $sessionId;

        // Checked first because jCache::erase() throws on a key it does not
        // hold, and forgetting a session nobody has used is not an error.
        if ($this->cache->get($key) === null) {
            return;
        }
        $this->cache->erase($key);
    }

    /**
     * Keeps the entries that are turns and drops anything else.
     *
     * @return list<array{role: string, text: string}>
     */
    private static function clean(mixed $turns): array
    {
        if (!is_array($turns)) {
            return [];
        }

        $clean = [];
        foreach ($turns as $turn) {
            if (
                is_array($turn)
                && isset($turn['role'], $turn['text'])
                && in_array($turn['role'], Prompt::ROLES, true)
                && is_string($turn['text'])
            ) {
                $clean[] = ['role' => $turn['role'], 'text' => $turn['text']];
            }
        }
        return $clean;
    }
}
