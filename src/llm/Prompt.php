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
