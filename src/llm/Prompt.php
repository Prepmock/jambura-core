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
}
