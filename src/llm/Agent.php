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
 * agent, or a name used twice.
 */
class Agent
{
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
}
