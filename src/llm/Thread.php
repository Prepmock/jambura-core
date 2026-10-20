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
     * The conversation's id.
     * @var string
     */
    private $id;

    /**
     * Messages, oldest first.
     * @var array
     */
    private $messages;

    /**
     * @param string $id       the conversation's id, as the application chose it
     * @param array  $messages each ['role' => string, 'text' => string,
     *                         'author' => string|null], oldest first
     */
    public function __construct($id, array $messages = [])
    {
        $this->id = $id;
        $this->messages = $messages;
    }

    /**
     * The conversation's id.
     *
     * @return string
     */
    public function id()
    {
        return $this->id;
    }

    /**
     * Every message in the conversation, oldest first.
     *
     * @return array
     */
    public function messages()
    {
        return $this->messages;
    }

    /**
     * The same conversation with only its most recent messages.
     *
     * A window rather than a truncation: the store keeps everything, and this is
     * what an adapter chooses to send. A count of zero or less gives an empty
     * thread, which is how a caller asks for no history at all.
     *
     * @param int $count how many of the most recent messages to keep
     * @return static
     */
    public function recent($count)
    {
        return new static($this->id, $count > 0 ? array_slice($this->messages, -$count) : []);
    }

    /**
     * Whether anything has been said yet.
     *
     * @return bool
     */
    public function isEmpty()
    {
        return $this->messages === [];
    }

    /**
     * How many messages the conversation holds.
     *
     * @return int
     */
    public function count()
    {
        return count($this->messages);
    }
}
