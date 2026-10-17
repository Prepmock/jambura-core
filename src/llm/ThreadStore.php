<?php
namespace Jambura\LLM;

/**
 * Where LLM::chat() keeps what has already been said.
 *
 * The framework cannot know an application's storage, so it asks for this and is
 * handed one at bootstrap:
 *
 *     \Jambura\LLM::storeThreadsIn(new DatabaseThreadStore());
 *
 * A store holds messages against a thread id and nothing else. In particular it
 * records no adapter class: a conversation answered by one model and escalated to
 * another is one history, which is the point of a model-agnostic Prompt.
 *
 * Implementations are free to expire, trim or shard however they like. The only
 * thing chat() counts on is that append() is append-only, so that two writers on
 * one thread id interleave their messages rather than losing them wholesale.
 */
interface ThreadStore
{
    /**
     * The conversation stored under a thread id.
     *
     * A thread id nothing has been stored under yet reads as an empty Thread
     * rather than throwing: that is what starting a new conversation looks like.
     */
    public function read(string $threadId): Thread;

    /**
     * Appends messages to a thread id, creating it if it is new.
     *
     * @param array $messages list<array{role: string, text: string, author?: string|null}>,
     *                        oldest first
     */
    public function append(string $threadId, array $messages): void;

    /**
     * Drops every message stored under a thread id.
     *
     * Forgetting a thread id that holds nothing is not an error, so a caller
     * clearing up need not first ask whether there was anything to clear.
     */
    public function forget(string $threadId): void;
}
