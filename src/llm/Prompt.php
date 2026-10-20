<?php
namespace Jambura\LLM;

/**
 * A model-agnostic prompt.
 *
 * Holds each part of a prompt separately so that each model adapter can have
 * it rendered in the order and format its model works best with. Carries no
 * knowledge of any particular model.
 */
class Prompt
{
    /**
     * Named context sections, in the order adapters render them.
     */
    const CONTEXT_SECTIONS = ['static', 'retrieved', 'dynamic', 'conversation'];

    /**
     * Roles a message can be spoken by. The caller asks as USER, the model answers
     * as ASSISTANT; there is no system role, because that is what setRole() is.
     */
    const USER = 'user';
    const ASSISTANT = 'assistant';

    /**
     * Every role addMessage() accepts.
     */
    const ROLES = [self::USER, self::ASSISTANT];

    /**
     * What kind of prompt this is, for routing before it reaches a model.
     * Adapters do not send it.
     * @var string|null
     */
    private $type = null;

    /**
     * Who the model should act as.
     * @var string|null
     */
    private $role = null;

    /**
     * Context items keyed by section name, see CONTEXT_SECTIONS.
     * @var array
     */
    private $context;

    /**
     * Instructions, one per item.
     * @var string[]
     */
    private $instructions = [];

    /**
     * The task the model is asked to do. Adapters always render it last.
     * @var string|null
     */
    private $task = null;

    /**
     * What has already been said, oldest first. Each message is
     * ['role' => string, 'text' => string, 'author' => string|null].
     *
     * Kept apart from the context sections because an adapter over a chat API
     * wants these as real messages with roles, not as prose: send() receives the
     * Prompt, so it can read getMessages() and map them onto whatever its API calls
     * a message list. For adapters that do not, handlePrompt() renders them into
     * the `conversation` context section instead, so nothing is lost.
     *
     * @var array
     */
    private $messages = [];

    /**
     * Files the model should read, each ['path' => string, 'media_type' => string|null].
     *
     * Not text, so handlePrompt() never renders these — an adapter reads them in
     * send() and puts them wherever its API takes a document or an image.
     *
     * They ride on the prompt rather than on the adapter because use() hands out
     * one shared instance per model: per-call state left on an adapter would
     * reach whoever prompts it next.
     *
     * @var array
     */
    private $documents = [];

    /**
     * Per-call generation settings for the adapter to pass on, such as a token
     * ceiling or a sampling temperature. Named rather than typed, because each
     * provider spells them differently — an adapter reads the ones it knows and
     * ignores the rest.
     *
     * Carried, never rendered, same as documents.
     *
     * @var array
     */
    private $options = [];

    public function __construct()
    {
        $this->context = array_fill_keys(self::CONTEXT_SECTIONS, []);
    }

    /**
     * Starts a new prompt, for chaining without wrapping `new` in brackets.
     *
     * @return static
     */
    public static function create()
    {
        return new static();
    }

    /**
     * @param string $type
     * @return $this
     */
    public function setType($type)
    {
        $this->type = $type;
        return $this;
    }

    /**
     * @param string $role
     * @return $this
     */
    public function setRole($role)
    {
        $this->role = $role;
        return $this;
    }

    /**
     * Adds items to one context section.
     *
     * @param string $section one of CONTEXT_SECTIONS
     * @param string ...$items context items to append to the section
     * @return $this
     *
     * @throws LLMException if the section is not one of CONTEXT_SECTIONS
     */
    public function addContext($section, ...$items)
    {
        if (!array_key_exists($section, $this->context)) {
            throw new LLMException(
                "Unknown context section '$section'. Use one of: " . implode(', ', self::CONTEXT_SECTIONS)
            );
        }
        foreach ($items as $item) {
            $this->context[$section][] = $item;
        }
        return $this;
    }

    /**
     * @param string ...$instructions
     * @return $this
     */
    public function addInstruction(...$instructions)
    {
        foreach ($instructions as $instruction) {
            $this->instructions[] = $instruction;
        }
        return $this;
    }

    /**
     * @param string $task
     * @return $this
     */
    public function setTask($task)
    {
        $this->task = $task;
        return $this;
    }

    /**
     * Appends a message to the conversation.
     *
     * Messages are appended in the order they were spoken, oldest first:
     *
     *     $prompt->addMessage(Prompt::USER, 'What is my leave balance?')
     *            ->addMessage(Prompt::ASSISTANT, 'You have 12 days.');
     *
     * The role is checked because it is one of two fixed values and a typo would
     * otherwise reach the model as a role it does not know. The text is not: it
     * is data, and an empty reply is the adapter's to refuse, not this object's.
     *
     * The author names who spoke, for a conversation with more than one person
     * in it. Leave it out for a two-party chat, where the role says enough.
     *
     * LLM::chat() calls this for you. Call it by hand to give a one-shot
     * prompt() some history without storing any.
     *
     * @param string      $role   one of ROLES
     * @param string      $text   what was said
     * @param string|null $author who said it, or null for the role alone
     * @return $this
     *
     * @throws LLMException if the role is not one of ROLES
     */
    public function addMessage($role, $text, $author = null)
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new LLMException(
                "Unknown message role '$role'. Use one of: " . implode(', ', self::ROLES)
            );
        }
        $this->messages[] = ['role' => $role, 'text' => $text, 'author' => $author];
        return $this;
    }

    /**
     * Puts a conversation's messages onto this prompt, in front of any already here.
     *
     * The thread is older than anything a caller has just added, so it goes
     * first and the caller's messages follow:
     *
     *     $prompt->loadConversation($store->read($threadId)->recent(10));
     *
     * Nothing is read from storage here - a Thread is a value object that
     * already holds its messages - which is what keeps a prompt free of any
     * dependency on where conversations live.
     *
     * It loads in front of what is there rather than replacing it, so calling it
     * twice loads the history twice. LLM::chat() works on a copy of the caller's
     * prompt for that reason.
     *
     * @return $this
     *
     * @throws LLMException if the thread holds a message with an unknown role
     */
    public function loadConversation(Thread $thread)
    {
        $added = $this->messages;
        $this->messages = [];

        foreach (array_merge($thread->messages(), $added) as $message) {
            $this->addMessage(
                isset($message['role']) ? $message['role'] : '',
                isset($message['text']) ? $message['text'] : '',
                isset($message['author']) ? $message['author'] : null
            );
        }
        return $this;
    }

    /**
     * Drops every message, leaving the rest of the prompt alone.
     *
     * The counterpart to loadConversation(), for a caller that wants to send the
     * same prompt without the conversation it was carrying.
     *
     * @return $this
     */
    public function forgetConversation()
    {
        $this->messages = [];
        return $this;
    }

    /**
     * Adds a file for the model to read.
     *
     * The path is kept as given and nothing is opened here — a prompt should be
     * constructable and assertable without touching a filesystem. The adapter
     * reads the file in send() and fails there if it cannot.
     *
     * Leave $mediaType out when the adapter can infer it; pass it when the file's
     * extension would mislead, or when the API insists on being told.
     *
     * @param string      $path
     * @param string|null $mediaType
     * @return $this
     *
     * @throws LLMException if the path is not a non-empty string
     */
    public function addDocument($path, $mediaType = null)
    {
        if (!is_string($path) || trim($path) === '') {
            throw new LLMException('A document needs a path');
        }
        $this->documents[] = ['path' => $path, 'media_type' => $mediaType];
        return $this;
    }

    /**
     * Sets a generation setting for the adapter to pass to its API.
     *
     * Setting the same name again replaces it, so a caller can override a default
     * without having to know whether one was set.
     *
     * @param string $name
     * @param mixed  $value
     * @return $this
     *
     * @throws LLMException if the name is not a non-empty string
     */
    public function setOption($name, $value)
    {
        if (!is_string($name) || trim($name) === '') {
            throw new LLMException('An option needs a name');
        }
        $this->options[$name] = $value;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @return string|null
     */
    public function getRole()
    {
        return $this->role;
    }

    /**
     * Returns the context sections that have items, in CONTEXT_SECTIONS order.
     *
     * @return array
     */
    public function getContext()
    {
        return array_filter($this->context);
    }

    /**
     * @return string[]
     */
    public function getInstructions()
    {
        return $this->instructions;
    }

    /**
     * @return string|null
     */
    public function getTask()
    {
        return $this->task;
    }

    /**
     * What has already been said, oldest first.
     *
     * @return array each ['role' => string, 'text' => string, 'author' => string|null]
     */
    public function getMessages()
    {
        return $this->messages;
    }

    /**
     * Files for the model to read, in the order they were added.
     *
     * @return array each ['path' => string, 'media_type' => string|null]
     */
    public function getDocuments()
    {
        return $this->documents;
    }

    /**
     * One generation setting, or $default when it was never set.
     *
     * @param string $name
     * @param mixed  $default
     * @return mixed
     */
    public function getOption($name, $default = null)
    {
        return array_key_exists($name, $this->options) ? $this->options[$name] : $default;
    }

    /**
     * @return array setting name => value
     */
    public function getOptions()
    {
        return $this->options;
    }
}
