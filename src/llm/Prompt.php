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

    /**
     * Files the model should read.
     *
     * Not text, so handlePrompt() never renders these — an adapter reads them in
     * send() and puts them wherever its API takes a document or an image.
     *
     * They ride on the prompt rather than on the adapter because use() hands out
     * one shared instance per model: per-call state left on an adapter would
     * reach whoever prompts it next.
     *
     * @var list<array{path: string, media_type: string|null}>
     */
    private array $documents = [];

    /**
     * Per-call generation settings for the adapter to pass on, such as a token
     * ceiling or a sampling temperature. Named rather than typed, because each
     * provider spells them differently — an adapter reads the ones it knows and
     * ignores the rest.
     *
     * Carried, never rendered, same as documents.
     *
     * @var array<string, mixed>
     */
    private array $options = [];

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

    /**
     * Empties one or more context sections.
     *
     * The counterpart to addContext(), for a Reducer dropping the sections a
     * particular kind of prompt has no use for. Emptying a section that holds
     * nothing is not an error, so a reducer can name every section it does not
     * want without first checking which of them arrived.
     *
     * @param string ...$sections sections to empty, each one of CONTEXT_SECTIONS
     *
     * @throws LLMException if a section is not one of CONTEXT_SECTIONS
     */
    public function removeContext(string ...$sections): static
    {
        foreach ($sections as $section) {
            if (!array_key_exists($section, $this->context)) {
                throw new LLMException(
                    "Unknown context section '$section'. Use one of: " . implode(', ', self::CONTEXT_SECTIONS)
                );
            }
            $this->context[$section] = [];
        }
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
     * @throws LLMException if the path is blank
     */
    public function addDocument(string $path, ?string $mediaType = null): static
    {
        if (trim($path) === '') {
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
     * @throws LLMException if the name is blank
     */
    public function setOption(string $name, mixed $value): static
    {
        if (trim($name) === '') {
            throw new LLMException('An option needs a name');
        }
        $this->options[$name] = $value;
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

    /**
     * Files for the model to read, in the order they were added.
     *
     * @return list<array{path: string, media_type: string|null}>
     */
    public function getDocuments(): array
    {
        return $this->documents;
    }

    /**
     * One generation setting, or $default when it was never set.
     */
    public function getOption(string $name, mixed $default = null): mixed
    {
        // array_key_exists, not ??, so a setting deliberately set to null reads
        // back as null rather than silently becoming the default.
        return array_key_exists($name, $this->options) ? $this->options[$name] : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}
