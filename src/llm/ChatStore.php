<?php
namespace Jambura\LLM;

/**
 * Where LLM::chat() keeps what has already been said.
 *
 * The framework cannot know an application's storage, so it asks for this and
 * is handed one at bootstrap:
 *
 *     \Jambura\LLM::storeChatsIn(new DatabaseChatStore());
 *
 * A store holds turns against a session id and nothing else. In particular it
 * records no adapter class: a conversation answered by one model and escalated
 * to another is one history, which is the point of a model-agnostic Prompt.
 *
 * Implementations are free to expire, trim or shard however they like. The only
 * thing chat() counts on is that append() is append-only, so that two writers on
 * one session id interleave their turns rather than losing them wholesale.
 */
interface ChatStore
{
    /**
     * Every turn stored under a session id, oldest first.
     *
     * A session id nothing has been stored under yet returns [], rather than
     * throwing: that is what starting a new conversation looks like.
     *
     * @return list<array{role: string, text: string}>
     */
    public function turns(string $sessionId): array;

    /**
     * Appends turns to a session id, creating it if it is new.
     *
     * @param list<array{role: string, text: string}> $turns oldest first
     */
    public function append(string $sessionId, array $turns): void;

    /**
     * Drops every turn stored under a session id.
     *
     * Forgetting a session id that holds nothing is not an error, so a caller
     * clearing up need not first ask whether there was anything to clear.
     */
    public function forget(string $sessionId): void;
}
