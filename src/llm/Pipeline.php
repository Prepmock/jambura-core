<?php
namespace Jambura\LLM;

/**
 * A named chain of steps that runs one prompt-shaped job.
 *
 * A pipeline is defined once, at bootstrap, and run as often as you like. The
 * definition registers each step under an alias, and routes put those aliases
 * in order. A run feeds input through one route and hands back the Context the
 * steps filled in:
 *
 *     Pipeline::make('mates_receipt')
 *         ->configure(['currency' => 'CAD'])
 *         ->gatekeeper(AttachmentGuard::class, 'check_attachment')
 *         ->preprocessor(FileStorage::class, 'save_attachments')
 *         ->model(ReceiptReader::class, 'read_receipt')
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
 * The verb a step is registered with says what the pipeline does with what that
 * step returns:
 *
 * - gatekeeper() - returning false stops the run there, and feed() returns the
 *   Context as it stood. Anything else carries on.
 * - preprocessor() - returning an array adds those values to the Context.
 * - model() - returning a string writes it to the Context as 'response'.
 * - step() - the primitive the three above wrap, with no extra meaning.
 *
 * Every verb takes the same targets: a class name, an object, or a callable. A
 * class name is built once per pipeline with `new`, so such a step class must be
 * constructible without arguments; pass a ready-made object when the step needs
 * dependencies. Whatever the target, a step is called with the run's Context as
 * its only argument.
 *
 * feed() takes a Prompt and nothing else, for the same reason LLM::prompt()
 * does: a run always carries the structured prompt, and no step has to guess
 * what a loose string was meant to be. Data that is not part of the prompt -
 * uploads, ids, records - travels beside it as the run's values.
 *
 * Errors are `Jambura\LLM\LLMException`, and every one of them is a mistake in
 * the definition or the call: an unknown pipeline or route, a duplicate alias, a
 * route naming a step that was never registered, or a step class without the
 * method named.
 */
class Pipeline
{
    /**
     * Step kinds, as registered by the verbs of the same name. The kind decides
     * how feed() reads that step's return value.
     */
    const GATEKEEPER = 'gatekeeper';
    const PREPROCESSOR = 'preprocessor';
    const MODEL = 'model';
    const STEP = 'step';

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
     * Registered steps, keyed by alias. Each holds the step's kind, its target
     * (class name, object or callable) and the method to call on that target,
     * null for a callable.
     * @var array<string, array{kind: string, target: mixed, method: string|null}>
     */
    private array $steps = [];

    /**
     * Objects built for class-name steps, keyed by step alias, so a step class
     * is constructed once per pipeline rather than once per run.
     * @var array<string, object>
     */
    private array $instances = [];

    /**
     * Routes, keyed by name, each a list of step aliases in the order they run.
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
     * ->feed($input)` cheap to call from a controller, a command or a job.
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
     * hop limit. Steps read them with `$context->setting('currency')`. Calling
     * configure() again merges, so a later call can override one setting
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
     * Registers a step with no special handling of its return value.
     *
     * This is the primitive the other verbs wrap: gatekeeper(), preprocessor()
     * and model() all call it with a different $kind. Use step() directly for
     * work that is none of those things, such as writing to storage.
     *
     * The step is registered under an alias, and routes refer to it by that
     * alias. The alias defaults to the method name, which is why
     * `->preprocessor(FileStorage::class, 'save_attachments')` can be listed in
     * a route as 'save_attachments'. A callable has no method name, so it needs
     * an explicit $as.
     *
     * @param callable|object|string $target a class name to build, an object to
     *                                       call, or a callable taking the Context
     * @param string|null            $method method to call on $target; null when
     *                                       $target is a callable
     * @param string|null            $as     alias for routes; defaults to $method
     * @param string                $kind    one of the kind constants, which
     *                                       decides how feed() reads the return
     * @return $this
     *
     * @throws LLMException if the alias is missing or already used, or if
     *                      $target is a known class or object without $method
     */
    public function step(
        callable|object|string $target,
        ?string $method = null,
        ?string $as = null,
        string $kind = self::STEP
    ): static {
        $alias = $as ?? $method;
        if ($alias === null) {
            throw new LLMException(
                'A callable step needs an alias: pass $as so routes can name it'
            );
        }
        if (isset($this->steps[$alias])) {
            throw new LLMException("Pipeline '{$this->name}' already has a step called '$alias'");
        }
        if ($method !== null) {
            $this->checkMethod($target, $method, $alias);
        }

        $this->steps[$alias] = ['kind' => $kind, 'target' => $target, 'method' => $method];
        return $this;
    }

    /**
     * Registers a step that can stop the run.
     *
     * A gatekeeper decides whether the rest of the route should happen at all:
     * an upload with no attachment, a request over quota, a user without
     * permission. Returning false stops the run, and feed() returns the Context
     * with wasStopped() true and stoppedAt() naming this step. Returning an
     * array carries on and adds those values, and any other return carries on.
     *
     * Stopping is not an error, so nothing is thrown. Throw from the step
     * itself if the caller should handle a failure instead.
     *
     * @see step() for the $target, $method and $as arguments
     * @return $this
     */
    public function gatekeeper(callable|object|string $target, ?string $method = null, ?string $as = null): static
    {
        return $this->step($target, $method, $as, self::GATEKEEPER);
    }

    /**
     * Registers a step that adds to the Context before the model runs.
     *
     * A preprocessor gathers or reshapes what the model will need: saving
     * uploads, looking up a customer, fetching documents. Returning an array
     * merges those values into the Context, so the next steps can read them.
     *
     * When what it gathers belongs in the prompt, add it to the prompt's own
     * context sections rather than building text for the model step to paste
     * together:
     *
     *     $context->prompt()->addContext('retrieved', $vendor->summary());
     *
     * @see step() for the $target, $method and $as arguments
     * @return $this
     */
    public function preprocessor(callable|object|string $target, ?string $method = null, ?string $as = null): static
    {
        return $this->step($target, $method, $as, self::PREPROCESSOR);
    }

    /**
     * Registers the step that calls a model.
     *
     * A model step sends the run's prompt through an adapter, which is usually
     * one line:
     *
     *     return LLM::use(\AIModel\Claude::class)->prompt($context->prompt());
     *
     * The prompt is the one fed to feed(), plus whatever the preprocessors added
     * to it. Returning a string writes it to the Context as 'response';
     * returning an array merges it instead, for a step that also reports tokens
     * used or a parsed result.
     *
     * @see step() for the $target, $method and $as arguments
     * @return $this
     */
    public function model(callable|object|string $target, ?string $method = null, ?string $as = null): static
    {
        return $this->step($target, $method, $as, self::MODEL);
    }

    /**
     * Names an ordered list of steps that a run can follow.
     *
     * A pipeline can hold several routes over the same steps, for example a
     * 'default' route and a 'retry' route that skips the expensive parts. The
     * aliases are checked when the route runs, not here, so steps and routes
     * can be declared in any order.
     *
     * Defining a route twice replaces it, which keeps a definition file
     * editable without worrying about order.
     *
     * @param string   $name  route name, as followRoute() will ask for it
     * @param string[] $steps step aliases, in the order they should run
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
     * Runs the chosen route over this input and returns the finished Context.
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
     * exists and nothing was chosen. Every alias in the route is checked before
     * the first step runs, so a route with a typo in it does no work at all.
     *
     * @param Prompt               $prompt the prompt this run sends
     * @param array<string, mixed> $values data the run needs that is not part
     *                                     of the prompt
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

        $aliases = $this->routes[$route];
        $unknown = array_diff($aliases, array_keys($this->steps));
        if ($unknown) {
            throw new LLMException(
                "Route '$route' of pipeline '{$this->name}' names steps that were never "
                . 'registered: ' . implode(', ', $unknown)
            );
        }

        $context = new Context(clone $prompt, $values, $this->settings);
        foreach ($aliases as $alias) {
            $result = $this->callStep($alias, $context);
            $context->markRan($alias);

            $kind = $this->steps[$alias]['kind'];
            if ($kind === self::GATEKEEPER && $result === false) {
                $context->markStopped($alias);
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
     * The steps registered so far, as alias => kind.
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
     * The routes defined so far, as name => step aliases.
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
     * A callable is called directly. For a class name or object the method
     * registered with the step is called, and a class name is built once and
     * kept for later runs.
     *
     * @return mixed whatever the step returned
     */
    private function callStep(string $alias, Context $context): mixed
    {
        $step = $this->steps[$alias];
        if ($step['method'] === null) {
            return ($step['target'])($context);
        }
        if (!isset($this->instances[$alias])) {
            $target = $step['target'];
            $object = is_string($target) ? new $target() : $target;
            $this->checkMethod($object, $step['method'], $alias);
            $this->instances[$alias] = $object;
        }
        return $this->instances[$alias]->{$step['method']}($context);
    }

    /**
     * Checks that a step's target has the method the step names.
     *
     * Runs at registration when the class is already loaded, and otherwise the
     * first time the step runs, once the autoloader has had a chance.
     *
     * @param callable|object|string $target
     *
     * @throws LLMException if the method is missing
     */
    private function checkMethod(callable|object|string $target, string $method, string $alias): void
    {
        if (is_string($target) && !class_exists($target)) {
            return;
        }
        if (!method_exists($target, $method)) {
            $class = is_object($target) ? get_class($target) : $target;
            throw new LLMException("Step '$alias' calls $class::$method(), which does not exist");
        }
    }
}
