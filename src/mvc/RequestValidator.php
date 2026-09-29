<?php
namespace Jambura\Mvc;

/**
 * Checks a request before a controller acts on it.
 *
 * Four links, each checking one thing: the HTTP method, the caller's roles, the
 * shape of the payload, and anything else you write yourself. Write them in the
 * order you want them checked - method, roles, schema, checks is the house
 * order, cheapest first, so a caller without permission never learns whether
 * their payload was valid.
 *
 * A validator either has a request or it does not, and that is the only
 * difference between the two ways of using it.
 *
 * **Bound to a request**, from Request::validate(), every link is checked the
 * moment it is called, and the first failure ends the request:
 *
 *     $book = $this->request()->validate()
 *         ->method('post')
 *         ->roles(['librarian', 'admin'])
 *         ->schema(['isbn' => ['required', ['regex', '/^[0-9-]{10,17}$/', 'Bad ISBN']]])
 *         ->check([$this, 'withinQuota'])
 *         ->validated();
 *
 * **Built by hand**, a validator only records its links, so it is a reusable
 * spec for what a request must look like:
 *
 *     class BookRequests
 *     {
 *         public static function create()
 *         {
 *             return (new RequestValidator())
 *                 ->method('post')
 *                 ->roles(['librarian', 'admin'])
 *                 ->schema(['title' => 'required', 'isbn' => 'required']);
 *         }
 *     }
 *
 *     $book = $this->request()->validate(BookRequests::create())->validated();
 *
 * Running a spec copies its links, leaving the spec as it was, so one spec
 * serves every request and both styles combine: pass specs to validate() and
 * carry on chaining after them.
 *
 * schema() merges and check() appends, so a spec can carry the shared fields and
 * an action can add its own. method() and roles() replace, since there is only
 * one answer to each.
 *
 * How a failure is reported is the request's business, not the validator's: a
 * Rest controller sends the status, anything else throws jamexRequestInvalid.
 */
class RequestValidator
{
    /**
     * Link kinds, in the order they are usually written.
     */
    private const METHOD = 'method';
    private const ROLES = 'roles';
    private const SCHEMA = 'schema';
    private const CHECK = 'check';

    /**
     * How the application finds the caller's roles.
     * @var callable|null
     */
    private static $rolesResolver = null;

    /**
     * The request being checked, or null for a spec that only records.
     * @var Request|null
     */
    private $request = null;

    /**
     * The links added so far, as [kind, argument], in the order they were added.
     * @var array<int, array{0: string, 1: mixed}>
     */
    private $links = [];

    /**
     * Values that passed a schema, gathered across every schema() call.
     * @var array<string, mixed>
     */
    private $validated = [];

    /**
     * Tells the validator how to find the caller's roles.
     *
     * Call it once, where the application boots. The resolver returns the roles
     * of whoever is signed in, or null when nobody is, which roles() answers
     * with 401 rather than 403:
     *
     *     RequestValidator::resolveRolesUsing(function () {
     *         return isset($_SESSION['user']) ? $_SESSION['user']['roles'] : null;
     *     });
     *
     * @param callable $resolver returns string[]|string|null
     */
    public static function resolveRolesUsing(callable $resolver): void
    {
        self::$rolesResolver = $resolver;
    }

    /**
     * Forgets the roles resolver. Meant for tests.
     */
    public static function forgetRolesResolver(): void
    {
        self::$rolesResolver = null;
    }

    /**
     * The roles of whoever made this request, or null when nobody is signed in.
     *
     * @return string[]|null
     *
     * @throws \RuntimeException if the application never wired a resolver
     */
    public static function rolesOf(Request $request): ?array
    {
        if (self::$rolesResolver === null) {
            throw new \RuntimeException(
                'No roles resolver: call ' . self::class . '::resolveRolesUsing() where the application boots'
            );
        }
        $roles = (self::$rolesResolver)($request);
        if ($roles === null) {
            return null;
        }
        return is_array($roles) ? array_values($roles) : [$roles];
    }

    /**
     * Binds this validator to a request, so later links are checked at once.
     *
     * Called by Request::validate(). Binding a spec you built by hand would make
     * it run, so specs are passed to validate() instead of bound.
     *
     * @return $this
     */
    public function bindTo(Request $request)
    {
        $this->request = $request;
        return $this;
    }

    /**
     * Runs another validator's links on this one, leaving that one untouched.
     *
     * @param RequestValidator $spec a validator built by hand
     * @return $this
     */
    public function apply(RequestValidator $spec)
    {
        foreach ($spec->links as [$kind, $argument]) {
            $this->add($kind, $argument);
        }
        return $this;
    }

    /**
     * Requires the request to use one of these methods.
     *
     * Any casing will do, so method('post') and method('POST') are the same. A
     * request with another method is refused with 405 and an Allow header saying
     * which methods this action takes.
     *
     * Replaces any earlier method() on this validator.
     *
     * @return $this
     */
    public function method(string ...$methods)
    {
        return $this->add(self::METHOD, array_map('strtoupper', $methods));
    }

    /**
     * Requires the caller to hold at least one of these roles.
     *
     * Takes a list or separate arguments: roles(['admin', 'user']) and
     * roles('admin', 'user') are the same. The roles come from the resolver
     * resolveRolesUsing() was given: null means nobody is signed in, answered
     * with 401, and a signed-in caller without a matching role gets 403.
     *
     * This is authorization. Rest::authenticate() stays authentication - whether
     * the caller is who they say they are.
     *
     * Replaces any earlier roles() on this validator.
     *
     * @param array<int, string>|string ...$roles
     * @return $this
     */
    public function roles(...$roles)
    {
        $flat = [];
        foreach ($roles as $role) {
            foreach ((array) $role as $one) {
                $flat[] = $one;
            }
        }
        return $this->add(self::ROLES, $flat);
    }

    /**
     * Requires the payload to satisfy these rules.
     *
     * The rules are Validator's, which are Model::validation()'s, so they read
     * the same wherever they appear. Fields are read from the query string and
     * the body together, so a rule does not care how the value arrived.
     *
     * Every field is checked, not just the first to fail, and the failure is a
     * 422 listing the messages per field. A JSON body that would not decode is a
     * 400 instead, since no field could be read from it.
     *
     * Merges with any earlier schema() on this validator, and each call checks
     * its own fields, so a spec's schema and an action's extra fields both apply.
     *
     * @param array<string, mixed> $rules field name => rules
     * @return $this
     */
    public function schema(array $rules)
    {
        return $this->add(self::SCHEMA, $rules);
    }

    /**
     * Adds a rule of your own.
     *
     * The check is a controller method as [$this, 'method'] - protected is fine,
     * since it is called through reflection - a closure, an object implementing
     * RequestCheck, or the class name of one. It is called with the Request and
     * returns true to pass, false for a plain 422, a string for a 422 with that
     * message, or an array such as ['status' => 429, 'error' => 'Quota used up']
     * to choose the status.
     *
     * The argument is deliberately untyped: a [$object, 'method'] pair naming a
     * protected method is not `callable` outside the class that owns it, so a
     * callable type here would reject the very shape a controller passes for its
     * own protected method. Anything unusable is refused below instead.
     *
     * Appends, so a spec's checks and an action's checks all run.
     *
     * @param $check
     * @return $this
     */
    public function check($check)
    {
        return $this->add(self::CHECK, $check);
    }

    /**
     * The fields that passed every schema on this validator.
     *
     * Only the fields the schemas named, so the result is safe to hand to
     * Model::add() without a caller adding columns of their own.
     *
     * @return array<string, mixed>
     *
     * @throws \LogicException if this validator has no request to read
     */
    public function validated(): array
    {
        if ($this->request === null) {
            throw new \LogicException(
                'validated() needs a request: pass this validator to Request::validate() first'
            );
        }
        return $this->validated;
    }

    /**
     * The links this validator holds, as [kind, argument] pairs.
     *
     * For tests, and for a future describe() that renders an API's contract.
     *
     * @return array<int, array{0: string, 1: mixed}>
     */
    public function definedLinks(): array
    {
        return $this->links;
    }

    /**
     * Records a link, and checks it now when there is a request.
     *
     * @return $this
     */
    private function add(string $kind, $argument)
    {
        if ($kind === self::METHOD || $kind === self::ROLES) {
            $this->links = array_values(array_filter(
                $this->links,
                function (array $link) use ($kind) {
                    return $link[0] !== $kind;
                }
            ));
        }
        $this->links[] = [$kind, $argument];

        if ($this->request !== null) {
            $this->run($kind, $argument);
        }
        return $this;
    }

    /**
     * Checks one link against the bound request.
     */
    private function run(string $kind, $argument): void
    {
        switch ($kind) {
            case self::METHOD:
                $this->runMethod($argument);
                return;
            case self::ROLES:
                $this->runRoles($argument);
                return;
            case self::SCHEMA:
                $this->runSchema($argument);
                return;
            default:
                $this->runCheck($argument);
        }
    }

    /**
     * @param string[] $methods
     */
    private function runMethod(array $methods): void
    {
        if (!$this->request->isMethod(...$methods)) {
            $this->request->fail(405, 'Method not allowed', [], ['Allow' => implode(', ', $methods)]);
        }
    }

    /**
     * @param string[] $wanted
     */
    private function runRoles(array $wanted): void
    {
        $held = $this->request->roles();
        if ($held === null) {
            $this->request->fail(401, 'Authentication required');
            return;
        }
        if (array_intersect($wanted, $held) === []) {
            $this->request->fail(403, 'You may not do that');
        }
    }

    /**
     * @param array<string, mixed> $rules
     */
    private function runSchema(array $rules): void
    {
        if ($this->request->jsonError() !== null) {
            $this->request->fail(400, 'The request body could not be read: ' . $this->request->jsonError());
            return;
        }

        $validator = Validator::make($this->request->all(), $rules, $this->request->controller());
        if ($validator->fails()) {
            $this->request->fail(422, 'Validation failed', $validator->errors());
            return;
        }
        $this->validated = array_merge($this->validated, $validator->validated());
    }

    /**
     * @param $check
     */
    private function runCheck($check): void
    {
        $result = $this->callCheck($check);
        if ($result === true) {
            return;
        }

        $status = 422;
        $message = 'The request was refused';
        $fields = [];
        if (is_string($result)) {
            $message = $result;
        } elseif (is_array($result)) {
            $status = $result['status'] ?? $status;
            $message = $result['error'] ?? $message;
            $fields = $result['fields'] ?? [];
        }
        $this->request->fail($status, $message, $fields);
    }

    /**
     * Calls a check, whichever shape it was given in.
     *
     * A [$object, 'method'] pair is called through reflection, so a protected
     * controller method works as a check.
     *
     * @param $check
     * @return mixed what the check returned
     */
    private function callCheck($check)
    {
        if ($check instanceof RequestCheck) {
            return $check->passes($this->request);
        }
        if (is_string($check) && class_exists($check) && is_subclass_of($check, RequestCheck::class)) {
            return (new $check())->passes($this->request);
        }
        if (is_array($check) && count($check) === 2 && is_object($check[0])) {
            $call = new \ReflectionMethod($check[0], $check[1]);
            $call->setAccessible(true);
            return $call->invoke($check[0], $this->request);
        }
        if (is_callable($check)) {
            return $check($this->request);
        }
        throw new \InvalidArgumentException(
            'A check must be a callable, a ' . RequestCheck::class . ', or the class name of one'
        );
    }
}
