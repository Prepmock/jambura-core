<?php
namespace Jambura\LLM;

/**
 * A named chain of steps that runs one prompt-shaped job.
 *
 * A pipeline is defined once, at bootstrap, and run as often as you like. Every
 * step is registered under a name, and routes put those names in order. A run
 * feeds a prompt through one route and hands back the Context the steps filled
 * in:
 *
 *     Pipeline::make('mates_receipt')
 *         ->configure(['currency' => 'CAD'])
 *         ->gatekeeper('check_attachment', AttachmentGuard::class)
 *         ->preprocessor('save_attachments', FileStorage::class)
 *         ->model('read_receipt', \AIModel\Claude::class)
 *         ->route('default', ['check_attachment', 'save_attachments', 'read_receipt']);
 *
 *     $prompt = Prompt::create()
 *         ->setType('receipt')
 *         ->setTask('Extract the total and the vendor.');
 *
 *     $context = Pipeline::use('mates_receipt')
 *         ->followRoute('default')
 *         ->feed($prompt, ['attachments' => $files]);
 *
 *     $context->get('response');
 *
 * Each verb registers a step of one kind, and the kind decides both the
 * interface the step class implements and what the pipeline does with what the
 * step returns:
 *
 * | Verb           | Target                  | Its return value                    |
 * |----------------|-------------------------|-------------------------------------|
 * | gatekeeper()   | a Gatekeeper: allows()  | false stops the run                 |
 * | preprocessor() | a Preprocessor: process() | an array adds values to the Context |
 * | model()        | an LLM adapter class    | the reply is stored as 'response'   |
 * | step()         | a Step: handle()        | an array adds values to the Context |
 *
 * model() is the one verb that needs no class of your own. It takes one of the
 * adapters the framework already has - a subclass of Jambura\LLM - and sends the
 * run's prompt through it, which is the whole of what a model step does:
 *
 *     ->model('process_document', \AIModel\Claude::class)
 *
 * Every verb takes the step's name first, the way route() and make() do, because
 * that name is the step's identity: it is what routes refer to. The method to
 * call is never named here - it comes from the interface the step class
 * implements - so a step class has one entry point and a definition reads as a
 * list of named stages.
 *
 * A step can also be a closure, for glue too small to deserve a class. A closure
 * takes the Context and follows the same return rules as its verb:
 *
 *     ->gatekeeper('positive_total', fn (Context $c) => $c->get('total') > 0)
 *
 * feed() takes a Prompt and nothing else, for the same reason LLM::prompt()
 * does: a run always carries the structured prompt, and no step has to guess
 * what a loose string was meant to be. Data that is not part of the prompt -
 * uploads, ids, records - travels beside it as the run's values.
 *
 * Errors are `Jambura\LLM\LLMException`, and every one of them is a mistake in
 * the definition or the call: an unknown pipeline or route, a name used twice, a
 * step class that does not implement its verb's interface, or a route naming a
 * step that was never registered.
 */
class Pipeline
{
    /**
     * Step kinds, as registered by the verbs of the same name.
     */
    const GATEKEEPER = 'gatekeeper';
    const PREPROCESSOR = 'preprocessor';
    const MODEL = 'model';
    const STEP = 'step';

    /**
     * The interface a step class of each kind implements, and the method the
     * pipeline calls on it. A model step has no interface: its target is an LLM
     * adapter, which the pipeline calls through LLM::use()->prompt().
     */
    private const CONTRACTS = [
        self::GATEKEEPER => [Gatekeeper::class, 'allows'],
        self::PREPROCESSOR => [Preprocessor::class, 'process'],
        self::STEP => [Step::class, 'handle'],
    ];

    /**
     * Defined pipelines, keyed by name.
     * @var array<string, Pipeline>
     */
    private static array $pipelines = [];

    /**
     * This pipeline's name, as passed to make().
     * @var string
     */
    private string $name;

    /**
     * Registered steps, keyed by step name. Each holds the step's kind, its
     * target - a step class name, an object, a closure, or an adapter class for
     * a model step - and whether that target is called directly rather than
     * through its interface.
     * @var array<string, array{kind: string, target: mixed, callable: bool}>
     */
    private array $steps = [];

    /**
     * Objects built for class-name steps, keyed by step name, so a step class is
     * constructed once per pipeline rather than once per run.
     * @var array<string, object>
     */
    private array $instances = [];

    /**
     * Routes, keyed by name, each a list of step names in the order they run.
     * @var array<string, string[]>
     */
    private array $routes = [];

    /**
     * Settings from configure(), passed to every run's Context.
     * @var array<string, mixed>
     */
    private array $settings = [];

    /**
     * Route the next feed() will follow, as chosen by followRoute().
     * @var string|null
     */
    private ?string $route = null;

    /**
     * Pipelines are created by make() and fetched by use().
     */
    private function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Defines a pipeline under a name, and returns it so the verbs can chain.
     *
     * Call this once, where the application boots. Defining the same name twice
     * throws rather than quietly replacing a definition other code is using;
     * forgetPipelines() clears the registry when a test needs to redefine one.
     *
     * @param string $name the pipeline's name, as use() will ask for it
     *
     * @throws LLMException if a pipeline of that name is already defined
     */
    public static function make(string $name): static
    {
        if (isset(self::$pipelines[$name])) {
            throw new LLMException("Pipeline '$name' is already defined");
        }
        return self::$pipelines[$name] = new static($name);
    }

    /**
     * Returns a pipeline that make() defined earlier.
     *
     * The pipeline is shared, so followRoute() and configure() on it affect
     * every later run. That is what makes `Pipeline::use('x')->followRoute('y')
     * ->feed($prompt)` cheap to call from a controller, a command or a job.
     *
     * @param string $name the name passed to make()
     *
     * @throws LLMException if no pipeline of that name has been defined
     */
    public static function use(string $name): static
    {
        if (!isset(self::$pipelines[$name])) {
            throw new LLMException(
                "Pipeline '$name' is not defined. Call " . self::class . "::make('$name') first"
            );
        }
        return self::$pipelines[$name];
    }

    /**
     * Whether a pipeline of this name has been defined.
     */
    public static function isDefined(string $name): bool
    {
        return isset(self::$pipelines[$name]);
    }

    /**
     * Drops every defined pipeline.
     *
     * Meant for tests, which define pipelines per test case, and for a
     * long-running worker that rebuilds its definitions.
     */
    public static function forgetPipelines(): void
    {
        self::$pipelines = [];
    }

    /**
     * This pipeline's name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Adds settings that every run of this pipeline can read.
     *
     * Settings are the pipeline's fixed configuration, such as a currency or a
     * token budget. Steps read them with `$context->setting('currency')`.
     * Calling configure() again merges, so a later call can override one setting
     * without repeating the rest.
     *
     * @param array<string, mixed> $settings
     * @return $this
     */
    public function configure(array $settings): static
    {
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
     * Registers a step that can stop the run.
     *
     * The class implements Gatekeeper, and its allows() decides whether the rest
     * of the route happens: returning false stops the run, and feed() returns
     * the Context with wasStopped() true and stoppedAt() naming this step.
     * Stopping is not an error, so nothing is thrown.
     *
     * @param string                 $name   the step's name, as routes refer to it
     * @param callable|object|string $target a class implementing Gatekeeper, an
     *                                       object of one, or a closure
     * @return $this
     *
     * @throws LLMException if the name is already used, or the class does not
     *                      implement Gatekeeper
     */
    public function gatekeeper(string $name, callable|object|string $target): static
    {
        return $this->step($name, $target, self::GATEKEEPER);
    }

    /**
     * Registers a step that prepares what the model needs.
     *
     * The class implements Preprocessor, and its process() gathers or reshapes:
     * saving uploads, looking up a customer, fetching documents. What belongs in
     * the prompt goes into the prompt's context sections
     * (`$context->prompt()->addContext(...)`), and the array it returns holds
     * what the run needs but the model does not.
     *
     * @param string                 $name   the step's name, as routes refer to it
     * @param callable|object|string $target a class implementing Preprocessor,
     *                                       an object of one, or a closure
     * @return $this
     *
     * @throws LLMException if the name is already used, or the class does not
     *                      implement Preprocessor
     */
    public function preprocessor(string $name, callable|object|string $target): static
    {
        return $this->step($name, $target, self::PREPROCESSOR);
    }

    /**
     * Registers the step that sends the run's prompt to a model.
     *
     * The target is one of the framework's adapters - a concrete subclass of
     * Jambura\LLM - and the pipeline does the call itself:
     *
     *     \Jambura\LLM::use($adapter)->prompt($context->prompt());
     *
     * So a model step needs no class of your own. The prompt it sends is the one
     * fed to feed() plus whatever the preprocessors added to it, the adapter
     * decides the format and order, and the reply is written to the Context as
     * 'response'.
     *
     * The adapter is registered with Jambura\LLM here, so a pipeline definition
     * does not need a separate registerModels() call for it.
     *
     * Work around the call belongs in its own step: parse or validate the reply
     * in a step() after this one, and prepare the prompt in a preprocessor
     * before it.
     *
     * @param string $name    the step's name, as routes refer to it
     * @param string $adapter an adapter class name, for example
     *                        \AIModel\Claude::class
     * @return $this
     *
     * @throws LLMException if the name is already used, or the class is not a
     *                      concrete Jambura\LLM adapter
     */
    public function model(string $name, string $adapter): static
    {
        if (isset($this->steps[$name])) {
            throw new LLMException("Pipeline '{$this->name}' already has a step called '$name'");
        }
        if (!class_exists($adapter)) {
            throw new LLMException("Step '$name' names the adapter class $adapter, which does not exist");
        }
        if (!is_subclass_of($adapter, \Jambura\LLM::class)) {
            throw new LLMException(
                "Step '$name' is a model step, so $adapter must be an adapter extending "
                . \Jambura\LLM::class . '. Use step() for work that is not a model call'
            );
        }
        \Jambura\LLM::registerModels([$adapter]);

        $this->steps[$name] = ['kind' => self::MODEL, 'target' => $adapter, 'callable' => false];
        return $this;
    }

    /**
     * Registers a step with no special handling of its return value.
     *
     * This is the primitive the other verbs wrap: gatekeeper(), preprocessor()
     * and model() each call it with a different $kind. Use step() directly for
     * work that is none of those, such as filing a result or notifying someone.
     * The class implements Step, and its handle() does the work.
     *
     * @param string                 $name   the step's name, as routes refer to it
     * @param callable|object|string $target a class implementing the kind's
     *                                       interface, an object of one, or a
     *                                       closure
     * @param string                 $kind   one of the kind constants
     * @return $this
     *
     * @throws LLMException if the kind is unknown, the name is already used, or
     *                      the target does not implement the kind's interface
     */
    public function step(string $name, callable|object|string $target, string $kind = self::STEP): static
    {
        if (!isset(self::CONTRACTS[$kind])) {
            throw new LLMException(
                "Unknown step kind '$kind'. Use one of: " . implode(', ', array_keys(self::CONTRACTS))
            );
        }

        $isCallable = !is_string($target) && !(is_object($target) && !$target instanceof \Closure);
        if (isset($this->steps[$name])) {
            throw new LLMException("Pipeline '{$this->name}' already has a step called '$name'");
        }
        if (!$isCallable) {
            $this->checkContract($target, $kind, $name);
        }

        $this->steps[$name] = ['kind' => $kind, 'target' => $target, 'callable' => $isCallable];
        return $this;
    }

    /**
     * Names an ordered list of steps that a run can follow.
     *
     * A pipeline can hold several routes over the same steps, for example a
     * 'default' route and a 'retry' route that skips the expensive parts. The
     * step names are checked when the route runs, not here, so steps and routes
     * can be declared in any order.
     *
     * Defining a route twice replaces it, which keeps a definition file editable
     * without worrying about order.
     *
     * @param string   $name  route name, as followRoute() will ask for it
     * @param string[] $steps step names, in the order they should run
     * @return $this
     */
    public function route(string $name, array $steps): static
    {
        $this->routes[$name] = array_values($steps);
        return $this;
    }

    /**
     * Chooses the route the next feed() will follow.
     *
     * The choice sticks to the pipeline, so a later feed() without another
     * followRoute() runs the same route again.
     *
     * @param string $name a route name given to route()
     * @return $this
     *
     * @throws LLMException if the pipeline has no route of that name
     */
    public function followRoute(string $name): static
    {
        if (!isset($this->routes[$name])) {
            throw new LLMException(
                "Pipeline '{$this->name}' has no route '$name'. Defined routes: "
                . ($this->routes ? implode(', ', array_keys($this->routes)) : 'none')
            );
        }
        $this->route = $name;
        return $this;
    }

    /**
     * Runs the chosen route over this prompt and returns the finished Context.
     *
     * The run carries the Prompt, and $values carries everything that is not
     * part of the prompt: uploaded files, a user id, a record id. Steps reach
     * the prompt with $context->prompt() and the values with $context->get().
     *
     * The prompt is copied first, so the steps add to the run's own prompt and
     * the object the caller passed in is left as it was. That keeps a Prompt
     * safe to build once and feed to several pipelines.
     *
     * Steps run in the route's order until the route ends or a gatekeeper stops
     * the run. The route is the one followRoute() chose, or 'default' when it
     * exists and nothing was chosen. Every name in the route is checked before
     * the first step runs, so a route with a typo in it does no work at all.
     *
     * @param Prompt               $prompt the prompt this run sends
     * @param array<string, mixed> $values data the run needs that is not part of
     *                                     the prompt
     * @return Context the finished run: its prompt, its values including
     *                 'response' from a model step, and what happened
     *
     * @throws LLMException if no route was chosen and there is no 'default'
     *                      route, or the route names a step that does not exist
     */
    public function feed(Prompt $prompt, array $values = []): Context
    {
        $route = $this->route ?? (isset($this->routes['default']) ? 'default' : null);
        if ($route === null) {
            throw new LLMException(
                "Pipeline '{$this->name}' has no route to follow. Call followRoute(), "
                . "or define a 'default' route"
            );
        }

        $names = $this->routes[$route];
        $unknown = array_diff($names, array_keys($this->steps));
        if ($unknown) {
            throw new LLMException(
                "Route '$route' of pipeline '{$this->name}' names steps that were never "
                . 'registered: ' . implode(', ', $unknown)
            );
        }

        $context = new Context(clone $prompt, $values, $this->settings);
        foreach ($names as $name) {
            $result = $this->callStep($name, $context);
            $context->markRan($name);

            $kind = $this->steps[$name]['kind'];
            if ($kind === self::GATEKEEPER && $result === false) {
                $context->markStopped($name);
                return $context;
            }
            if (is_array($result)) {
                $context->merge($result);
            } elseif ($kind === self::MODEL && is_string($result)) {
                $context->set('response', $result);
            }
        }
        return $context;
    }

    /**
     * The steps registered so far, as name => kind.
     *
     * For tests, and for showing what a pipeline is made of.
     *
     * @return array<string, string>
     */
    public function definedSteps(): array
    {
        return array_map(fn (array $step) => $step['kind'], $this->steps);
    }

    /**
     * The routes defined so far, as route name => step names.
     *
     * @return array<string, string[]>
     */
    public function definedRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Calls one step with the run's Context and returns what it returned.
     *
     * A model step sends the run's prompt through its adapter. A closure is
     * called directly. A class name or object is called through the method its
     * kind's interface declares, and a class name is built once and kept for
     * later runs.
     *
     * @return mixed whatever the step returned
     */
    private function callStep(string $name, Context $context): mixed
    {
        $step = $this->steps[$name];
        if ($step['kind'] === self::MODEL) {
            return \Jambura\LLM::use($step['target'])->prompt($context->prompt());
        }
        if ($step['callable']) {
            return ($step['target'])($context);
        }
        if (!isset($this->instances[$name])) {
            $this->instances[$name] = is_string($step['target']) ? new $step['target']() : $step['target'];
        }
        $method = self::CONTRACTS[$step['kind']][1];
        return $this->instances[$name]->$method($context);
    }

    /**
     * Checks that a step's class implements the interface its kind requires.
     *
     * Runs at registration, so a class that is missing its interface, or was
     * registered under the wrong verb, fails where the pipeline is defined.
     *
     * @param object|string $target class name or object
     *
     * @throws LLMException if the class does not exist or does not implement the
     *                      kind's interface
     */
    private function checkContract(object|string $target, string $kind, string $name): void
    {
        [$interface, $method] = self::CONTRACTS[$kind];
        if (is_string($target) && !class_exists($target)) {
            throw new LLMException("Step '$name' names the class $target, which does not exist");
        }
        if (!is_a($target, $interface, true)) {
            $class = is_object($target) ? get_class($target) : $target;
            throw new LLMException(
                "Step '$name' is registered as a $kind, so $class must implement $interface "
                . "($method(Context)). Pass a closure instead for a step too small for a class"
            );
        }
    }
}
