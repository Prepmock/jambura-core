<?php
namespace Jambura\LLM;

/**
 * A model-agnostic prompt.
 *
 * Holds each part of a prompt separately so that every model adapter can
 * serialize it in the format its API expects. Carries no knowledge of any
 * particular model.
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
    private ?string $type = null;

    /**
     * Who the model should act as.
     * @var string|null
     */
    private ?string $role = null;

    /**
     * Context items keyed by section name, see CONTEXT_SECTIONS.
     * @var array<string, string[]>
     */
    private array $context;

    /**
     * Instructions, one per item.
     * @var string[]
     */
    private array $instructions = [];

    /**
     * The task the model is asked to do. Adapters always render it last.
     * @var string|null
     */
    private ?string $task = null;

    public function __construct()
    {
        $this->context = array_fill_keys(self::CONTEXT_SECTIONS, []);
    }

    /**
     * Starts a new prompt, for chaining without wrapping `new` in brackets.
     *
     * @return static
     */
    public static function create(): static
    {
        return new static();
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;
        return $this;
    }

    /**
     * Adds items to one context section.
     *
     * @param string $section one of CONTEXT_SECTIONS
     * @param string ...$items context items to append to the section
     *
     * @throws LLMException if the section is not one of CONTEXT_SECTIONS
     */
    public function addContext(string $section, string ...$items): static
    {
        if (!array_key_exists($section, $this->context)) {
            throw new LLMException(
                "Unknown context section '$section'. Use one of: " . implode(', ', self::CONTEXT_SECTIONS)
            );
        }
        array_push($this->context[$section], ...$items);
        return $this;
    }

    public function addInstruction(string ...$instructions): static
    {
        array_push($this->instructions, ...$instructions);
        return $this;
    }

    public function setTask(string $task): static
    {
        $this->task = $task;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    /**
     * Returns the context sections that have items, in CONTEXT_SECTIONS order.
     *
     * @return array<string, string[]>
     */
    public function getContext(): array
    {
        return array_filter($this->context);
    }

    /**
     * @return string[]
     */
    public function getInstructions(): array
    {
        return $this->instructions;
    }

    public function getTask(): ?string
    {
        return $this->task;
    }
}
