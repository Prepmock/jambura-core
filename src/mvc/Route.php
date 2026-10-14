<?php
namespace Jambura\Mvc;

/**
 * Where a request points: which controller file, which class, which action.
 *
 * Route only resolves. It includes nothing and instantiates nothing, so it can
 * be tested against a temporary directory and nothing else; Router does the
 * including and the building:
 *
 *     $route = Route::fromRequest($this->request());   // or Route::of('api/ask')
 *
 *     $route->segments();     // ['api', 'ask']
 *     $route->file();         // JAMBURA_CONTROLLERS . 'api/ask.php'
 *     $route->className();    // 'Controller_api_ask'
 *     $route->action();       // 'action_index'
 *     $route->exists();       // whether the file is there
 *
 * Two URL forms reach the same place, and `r` wins when both arrive:
 *
 *     index.php?r=api/ask
 *     index.php?r=books/show&id=7
 *     index.php?controller=books&action=show      (unchanged)
 *
 * The two forms are not resolved the same way, and that is deliberate. The
 * `controller`/`action` form says which is which, so it is taken at its word.
 * The `r` form is ambiguous - `api/ask` could be `ask.php` inside an `api`
 * directory, or an `ask` action on `api.php` - and is resolved by the longest
 * controller path that exists, the remainder being the action. One is_file()
 * per segment, no configuration:
 *
 *     api/ask        with api/ask.php present   -> api/ask,    action_index
 *     api/ask        with only api.php present  -> api,        action_ask
 *     books                                     -> books,      action_index
 *     api/books/show with api/books.php present -> api/books,  action_show
 *
 * So a fat api.php can be split into api/ask.php and api/tell.php without its
 * URLs changing. The footgun is the other direction: when api.php has an
 * action_ask() *and* api/ask.php exists, the file wins and the action becomes
 * unreachable, silently. Route cannot warn about it, because knowing an action
 * is there means including the file, which is the one thing Route will not do.
 *
 * Every segment must match [A-Za-z0-9_-]+, so `..`, a slash-escape or a null
 * byte throws jamexBadController instead of reaching include(). Applications
 * need no guard of their own.
 */
class Route
{
    /**
     * A segment names one controller or action, never a path.
     *
     * \A and \z rather than ^ and $: a bare $ also matches before a trailing
     * newline, which would let "books\n" through.
     */
    private const SEGMENT = '/\A[A-Za-z0-9_-]+\z/';

    /**
     * @param string[] $segments the segments naming the controller file
     * @param string   $action   the action name, without its `action_` prefix
     * @param string   $base     the directory holding controllers, with its
     *                           trailing slash, as JAMBURA_CONTROLLERS has
     */
    private function __construct(
        private readonly array $segments,
        private readonly string $action,
        private readonly string $base
    ) {
    }

    /**
     * Resolves a path - 'api/ask', 'books/show' - against the controllers.
     *
     * Extra segments are ignored: parameters travel in the query string, so
     * `books/show/7` is the `show` action on `books` and the 7 is dropped.
     *
     * @param string      $path the request path, with or without its slashes
     * @param string|null $base the controller directory, JAMBURA_CONTROLLERS
     *                          when not given
     *
     * @throws \jamexBadController if the path names no controller, or if any
     *                             segment is not [A-Za-z0-9_-]+
     */
    public static function of(string $path, ?string $base = null): static
    {
        $base ??= JAMBURA_CONTROLLERS;
        $segments = self::segmentsOf($path);

        if ($segments === []) {
            throw new \jamexBadController('Invalid controller file : ' . $path);
        }

        // Longest controller path that exists wins; what follows it is the
        // action. Walking down from the full path means the first hit is the
        // longest, so nested controllers beat an action of the same name.
        for ($length = count($segments); $length >= 1; $length--) {
            $prefix = array_slice($segments, 0, $length);
            if (is_file(self::fileOf($base, $prefix))) {
                return new static($prefix, $segments[$length] ?? 'index', $base);
            }
        }

        // Nothing on disk at any depth. The whole path names the controller,
        // so exists() is false and the error names what was asked for.
        return new static($segments, 'index', $base);
    }

    /**
     * Reads the route out of a request, or null when it names none.
     *
     * Null is a request for no controller at all, which Router answers with the
     * DEFAULT_PAGE redirect. `r` wins when both forms arrive, since a rewrite
     * plus a hand-written query string can produce both.
     *
     * @param Request     $request the request being answered
     * @param string|null $base    the controller directory, JAMBURA_CONTROLLERS
     *                             when not given
     *
     * @throws \jamexBadController if a segment is not [A-Za-z0-9_-]+
     */
    public static function fromRequest(Request $request, ?string $base = null): ?static
    {
        $path = $request->query('r');
        if (is_string($path) && trim($path, '/') !== '') {
            return static::of($path, $base);
        }

        $controller = $request->query('controller');
        if (!is_string($controller) || trim($controller, '/') === '') {
            return null;
        }

        $action = $request->query('action');

        // The caller already said which segment is the action, so this form is
        // taken at its word rather than resolved. Resolving it would let a new
        // books/show.php change where ?controller=books&action=show lands.
        //
        // The action is not checked against SEGMENT: it names a method, never a
        // path, and an unknown one is already a jamexBadAction from display().
        // Checking it here would turn today's 404 into a different exception.
        return new static(
            self::segmentsOf($controller),
            is_string($action) && $action !== '' ? $action : 'index',
            $base ?? JAMBURA_CONTROLLERS
        );
    }

    /**
     * The segments naming the controller file, without the action.
     *
     * For `r=api/ask` served by `api.php` that is `['api']`, not `['api','ask']`
     * - this is what was resolved, not what was asked for.
     *
     * @return string[]
     */
    public function segments(): array
    {
        return $this->segments;
    }

    /**
     * The controller file's path, whether or not it is there.
     */
    public function file(): string
    {
        return self::fileOf($this->base, $this->segments);
    }

    /**
     * The controller class the file is expected to hold.
     *
     * A nested controller joins its segments: `api/ask.php` holds
     * `Controller_api_ask`. Router falls back to `Controller_ask` when that is
     * not the name in the file, so a controller keeps working when it moves
     * into a directory.
     */
    public function className(): string
    {
        return 'Controller_' . implode('_', $this->segments);
    }

    /**
     * The action method's name, `action_index` when the path named none.
     */
    public function action(): string
    {
        return 'action_' . $this->action;
    }

    /**
     * Whether the controller file is there to be included.
     */
    public function exists(): bool
    {
        return is_file($this->file());
    }

    /**
     * The path a set of segments resolves to under a controller directory.
     *
     * @param string[] $segments
     */
    private static function fileOf(string $base, array $segments): string
    {
        return $base . implode('/', $segments) . '.php';
    }

    /**
     * Splits a path into validated segments.
     *
     * A leading, trailing or doubled slash names nothing and is dropped, so
     * '/books/' and 'books' are the same route.
     *
     * @return string[]
     * @throws \jamexBadController if a segment is not [A-Za-z0-9_-]+
     */
    private static function segmentsOf(string $path): array
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                continue;
            }
            if (!preg_match(self::SEGMENT, $segment)) {
                throw new \jamexBadController('Invalid controller segment : ' . $segment);
            }
            $segments[] = $segment;
        }
        return $segments;
    }
}
