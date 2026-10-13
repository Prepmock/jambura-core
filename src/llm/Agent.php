<?php
namespace Jambura\LLM;

/**
 * A named agent: a model, a role and instructions, defined once and run from
 * anywhere.
 *
 * An agent is defined where the application boots, and used from a controller,
 * a command or a queue job:
 *
 *     // index.php
 *     Agent::make('researcher')
 *         ->model(\AIModel\Claude::class)
 *         ->role('You are a research assistant for mill operations.')
 *         ->instructions('Cite the context you use.', 'Be brief.')
 *         ->configure(['max_steps' => 6, 'token_budget' => 20000]);
 *
 *     // a controller, a command, a queue job
 *     $answer = Agent::use('researcher')->ask('What changed in the shipping rules?');
 *
 * The definition is shared and a run is not. Agent::use() hands back the one
 * definition every caller shares, so an Agent holds configuration only and
 * nothing about an execution. Everything that belongs to one execution - the
 * prompt, the turns, the trace, the status - lives on a Run that is created
 * per call and never shared.
 *
 * Definitions are code, rebuilt from bootstrap on every request, so they diff,
 * review and deploy like the rest of the application.
 *
 * Errors are `Jambura\LLM\LLMException`, and every one of them is a mistake in
 * the definition or the call, raised where the agent is defined: an unknown
 * agent, a name used twice, a model class that is not an adapter, or a setting
 * the agent does not know.
 */
class Agent
{
    /**
     * Settings configure() accepts. They are recorded for the step loop to
     * enforce once adapters can return tool calls.
     */
    const SETTINGS = ['max_steps', 'token_budget'];

    /**
     * Defined agents, keyed by name.
     * @var array<string, Agent>
     */
    private static array $agents = [];

    /**
     * This agent's name, as passed to make().
     * @var string
     */
    private string $name;

    /**
     * Adapter class the agent's prompts are sent through, as given to model().
     * @var string|null
     */
    private ?string $model = null;

    /**
     * Who the model should act as, as given to role().
     * @var string|null
     */
    private ?string $role = null;

    /**
     * Instructions every run's prompt carries, in the order they were given.
     * @var string[]
     */
    private array $instructions = [];

    /**
     * Settings from configure(), keyed by name, see SETTINGS.
     * @var array<string, mixed>
     */
    private array $settings = [];

    /**
     * Tool names from tools(), recorded for the tool registry to resolve.
     * @var string[]
     */
    private array $tools = [];

    /**
     * Agents are created by make() and fetched by use().
     */
    private function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Defines an agent under a name, and returns it so the definition can chain.
     *
     * Call this once, where the application boots. Defining the same name twice
     * throws rather than quietly replacing a definition other code is using;
     * forgetAgents() clears the registry when a test needs to redefine one.
     *
     * @param string $name the agent's name, as use() will ask for it
     *
     * @throws LLMException if an agent of that name is already defined
     */
    public static function make(string $name): static
    {
        if (isset(self::$agents[$name])) {
            throw new LLMException("Agent '$name' is already defined");
        }
        return self::$agents[$name] = new static($name);
    }

    /**
     * Returns an agent that make() defined earlier.
     *
     * The agent is shared, which is safe because it holds only its definition:
     * every call made through it starts a Run of its own.
     *
     * @param string $name the name passed to make()
     *
     * @throws LLMException if no agent of that name has been defined
     */
    public static function use(string $name): static
    {
        if (!isset(self::$agents[$name])) {
            throw new LLMException(
                "Agent '$name' is not defined. Call " . self::class . "::make('$name') first"
            );
        }
        return self::$agents[$name];
    }

    /**
     * Whether an agent of this name has been defined.
     */
    public static function isDefined(string $name): bool
    {
        return isset(self::$agents[$name]);
    }

    /**
     * Drops every defined agent.
     *
     * Meant for tests, which define agents per test case, and for a
     * long-running worker that rebuilds its definitions.
     */
    public static function forgetAgents(): void
    {
        self::$agents = [];
    }

    /**
     * This agent's name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Chooses the adapter this agent's prompts are sent through.
     *
     * The adapter is one of the framework's - a concrete subclass of
     * Jambura\LLM - as Pipeline::model() takes, and it is registered with
     * Jambura\LLM here, so an agent definition does not need a separate
     * registerModels() call for it. Calling model() again replaces the adapter.
     *
     * @param string $adapter an adapter class name, for example
     *                        \AIModel\Claude::class
     * @return $this
     *
     * @throws LLMException if the class does not exist or is not a concrete
     *                      Jambura\LLM adapter
     */
    public function model(string $adapter): static
    {
        if (!class_exists($adapter)) {
            throw new LLMException("Agent '{$this->name}' names the adapter class $adapter, which does not exist");
        }
        if (!is_subclass_of($adapter, \Jambura\LLM::class)) {
            throw new LLMException(
                "Agent '{$this->name}' sends its prompts through a model, so $adapter must be an adapter extending "
                . \Jambura\LLM::class
            );
        }
        \Jambura\LLM::registerModels([$adapter]);

        $this->model = $adapter;
        return $this;
    }

    /**
     * Sets who the model should act as.
     *
     * It becomes the role of every prompt this agent sends. Calling role()
     * again replaces it.
     *
     * @return $this
     */
    public function role(string $role): static
    {
        $this->role = $role;
        return $this;
    }

    /**
     * Adds instructions every prompt this agent sends will carry.
     *
     * Calling instructions() again appends, the way Prompt::addInstruction()
     * does, so the order they are given in is the order the model reads them.
     *
     * @return $this
     */
    public function instructions(string ...$instructions): static
    {
        array_push($this->instructions, ...$instructions);
        return $this;
    }

    /**
     * Adds settings that bound this agent's runs.
     *
     * Only the names in SETTINGS are accepted: max_steps and token_budget. They
     * are recorded now for the step loop to enforce once it exists; a
     * single-shot run makes one model call and does not read them yet. Calling
     * configure() again merges, so a later call can override one setting
     * without repeating the rest.
     *
     * @param array<string, mixed> $settings
     * @return $this
     *
     * @throws LLMException if a setting is not one of SETTINGS
     */
    public function configure(array $settings): static
    {
        $unknown = array_diff(array_keys($settings), self::SETTINGS);
        if ($unknown) {
            throw new LLMException(
                "Agent '{$this->name}' has no setting " . implode(', ', $unknown)
                . '. Use one of: ' . implode(', ', self::SETTINGS)
            );
        }
        $this->settings = array_merge($this->settings, $settings);
        return $this;
    }

    /**
     * The settings configure() has collected.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return $this->settings;
    }

    /**
     * Names the tools this agent may call.
     *
     * The names are recorded and not yet used: tool calling arrives once
     * adapters can return tool calls, and the tool registry will resolve these
     * names then. Calling tools() again appends.
     *
     * @return $this
     */
    public function tools(string ...$tools): static
    {
        array_push($this->tools, ...$tools);
        return $this;
    }

    /**
     * The tool names tools() has recorded, in the order they were given.
     *
     * @return string[]
     */
    public function definedTools(): array
    {
        return $this->tools;
    }
}
