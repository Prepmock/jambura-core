<?php
namespace Jambura\LLM;

/**
 * What has been said in one conversation.
 *
 * A plain value object: an id and its messages, oldest first. A ThreadStore
 * reads one, and Prompt::loadConversation() copies it onto a prompt.
 *
 * It stores nothing and reaches nothing. That is deliberate: it is what lets
 * Prompt take a conversation without acquiring a dependency on where
 * conversations live, and it keeps a prompt constructable in a test from two
 * lines with no store behind it.
 */
class Thread
{
    /**
     * @param string $id       the conversation's id, as the application chose it
     * @param array  $messages list<array{role: string, text: string, author?: string|null}>,
     *                         oldest first
     */
    public function __construct(
        private readonly string $id,
        private readonly array $messages = []
    ) {
    }

    /**
     * The conversation's id.
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Every message in the conversation, oldest first.
     *
     * @return array
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * The same conversation with only its most recent messages.
     *
     * A window rather than a truncation: the store keeps everything, and this is
     * what an adapter chooses to send. A count of zero or less gives an empty
     * thread, which is how a caller asks for no history at all.
     */
    public function recent(int $count): static
    {
        return new static($this->id, $count > 0 ? array_slice($this->messages, -$count) : []);
    }

    /**
     * Whether anything has been said yet.
     */
    public function isEmpty(): bool
    {
        return $this->messages === [];
    }

    /**
     * How many messages the conversation holds.
     */
    public function count(): int
    {
        return count($this->messages);
    }
}
