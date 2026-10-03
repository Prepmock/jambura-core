# Jambura

A small PHP MVC framework: a query-string router, controllers with layouts and flash
messages, a REST controller base, and models built on [idiorm](https://github.com/Prepmock/idiorm).
There is no service container, no config files and no code generation. An application
defines a handful of constants and hands the request to the router.

## Requirements

- **PHP 8.1 or later** for 3.x. Use 2.x on PHP 7.
- **PDO**, with the driver for your database.
- For clean URLs, a web server that rewrites them onto `index.php` (see [Routing](#routing)).

## Installation

```bash
composer require prepmock/jambura-core:^3.0
```

Composer installs idiorm and Phinx along with the framework:

| jambura-core | idiorm | Phinx |
|---|---|---|
| **3.x** | `prepmock/idiorm` ^2.0 | ^0.16 |
| 2.x | `prepmock/idiorm` v1.0.0 | 0.11.6 |

Composer autoloads everything the framework ships: `Jambura\Mvc\*` and `Jambura\LLM\*` by
PSR-4, the `Jambura\LLM` class by classmap, the global
helpers (`jRouter`, `jController`, `jModel`, `jAssets`, `jFlash`, `jCache`, the `jamex*`
exceptions) and the `Jambura` bootstrap class as autoload files, and idiorm's `ORM` by
classmap. **Do not include files from `vendor/` by hand.** A second include of an
autoloaded file is a fatal "cannot declare class" error. The one exception is the
data-structure classes, which are not autoloaded (see [Helpers](#helpers)).

## How a request flows

```
index.php  →  Router::route()    reads ?controller=books&action=show
           →  includes JAMBURA_CONTROLLERS/books.php
           →  new Controller_books()     constructor sets up assets, cache, session, flash,
                                         then calls init()
           →  Router::display()  calls action_show(), then end()
```

A missing `action` means `action_index`. An unknown controller file throws
`jamexBadController`, and an unknown action throws `jamexBadAction`. Both extend
`jamexPageNotFound`.

## Bootstrapping an application

The framework reads its paths and defaults from constants, and your application defines
them:

| Constant | Read by | Meaning |
|---|---|---|
| `JAMBURA_CONTROLLERS` | Router | directory holding controller files |
| `JAMBURA_VIEWS` | Controller | directory holding view files |
| `JAMBURA_TEMPLATES` | Controller | directory holding templates and layouts |
| `DEFAULT_TEMPLATE`, `DEFAULT_LAYOUT` | Controller | the layout wrapped around every rendered view |
| `DEFAULT_PAGE` | Router | where a request with no controller is redirected |
| `ROOT` | jAssets | URL prefix put in front of asset paths (`''` at the web root) |
| `JAMBURA_MOD` | `Router::showErrorPage()` | `'DEV'` shows detailed errors; anything else does not |

A minimal `index.php`:

```php
<?php
require 'vendor/autoload.php';

define('ROOT', '');
define('JAMBURA_CONTROLLERS', 'app/controllers/');
define('JAMBURA_VIEWS', 'app/views/');
define('JAMBURA_TEMPLATES', 'templates/');
define('DEFAULT_TEMPLATE', 'default');
define('DEFAULT_LAYOUT', 'default');
define('DEFAULT_PAGE', 'home');
define('JAMBURA_MOD', 'DEV');

// Models are found by class name, so the application registers the autoloader that
// maps Model_books to a file. Controllers need none: the router includes them itself.
define('JAMBURA_MODS', 'app/models/');
define('JAMBURA_CLASSES', 'app/classes/');
spl_autoload_register(function ($class) {
    $file = strpos($class, 'Model_') === 0
        ? JAMBURA_MODS . substr($class, strlen('Model_')) . '.php'
        : JAMBURA_CLASSES . strtolower($class) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

ORM::configure('mysql:host=localhost;dbname=app;charset=utf8mb4');
ORM::configure('username', 'app');
ORM::configure('password', 'secret');

// The controller name becomes part of an include() path, and the router does not
// check it. Without this, ?controller=../../x includes a file from anywhere.
if (isset($_GET['controller']) && !preg_match('/^[A-Za-z0-9_-]+$/', $_GET['controller'])) {
    $_GET['controller'] = '';
}

try {
    // route() returns null after redirecting a request that names no controller.
    if ($router = (new Jambura\Mvc\Router())->route()) {
        $router->display();
    }
} catch (jamexPageNotFound $e) {
    http_response_code(404);
    include '404.html';
}
```

`Jambura::app()->setConfig($array)->routeRequest()->respond()` is an alternative that
defines the path and view constants from an array. It does not configure `ORM`, define
`JAMBURA_MOD` or register a model autoloader, so you still do those yourself. It also
defines its constants unconditionally, so don't combine it with the `define()`s above:
defining a constant twice raises a warning.

## Routing

The router only reads `$_GET['controller']` and `$_GET['action']`. Clean URLs are a
rewrite onto that form, for example in Apache:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^([a-zA-Z0-9_-]+)/?$ index.php?controller=$1 [L,QSA]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^([a-zA-Z0-9_-]+)/([a-zA-Z0-9_-]+)/?$ index.php?controller=$1&action=$2 [L,QSA]
```

`/books` then runs `Controller_books::action_index()`, and `/books/show?id=7` runs
`action_show()`.

## Controllers

A controller lives in `JAMBURA_CONTROLLERS/{name}.php`, is named `Controller_{name}`,
and extends `Jambura\Mvc\Controller` (or its alias `jController`). Its actions are public
`action_{name}()` methods.

```php
<?php
// app/controllers/books.php
class Controller_books extends Jambura\Mvc\Controller
{
    public function init()
    {
        // Runs at the end of the constructor, before the action. The base class's
        // init() is empty, so this is purely your hook — an auth gate belongs here.
    }

    public function action_index()
    {
        $this->title = 'Books';                                   // $title in the view
        $this->books = ORM::for_table('books')->find_array();     // $books in the view
        $this->assets->addCSS('templates/default/css/books.css');
        $this->render('books');                                    // app/views/books.php
    }

    public function action_show()
    {
        $id = (int) $this->__id;                                   // $_REQUEST['id'], or false
        $book = $id ? Jambura\Mvc\Model::factory('books', $id) : null;
        if (!$book || !$book->loaded()) {
            $this->jFlash->error('No such book');
            $this->redirect('/books');                             // exits
        }
        $this->render('book', ['book' => $book]);
    }
}
```

**Request data.** Reading any property that starts with `__` reads that request
variable: `$this->__id` is `$_REQUEST['id']`, and it is **`false`** when absent, so check
it before use. `get('x')`, `post('x')` and `header('Name')` do the same for `$_GET`,
`$_POST` and request headers.

**View data.** Assigning a property (`$this->title = ...`) stores it for the view.
`render('name', $extra)` extracts those values, plus `$extra`, into local variables and
includes `JAMBURA_VIEWS/name.php` between
`JAMBURA_TEMPLATES/{template}/layouts/{layout}/header.php` and `footer.php`. Views run
inside the controller, so `$this` works there too:
`<?php $this->assets->loadCSS('default'); ?>` in a header prints the stylesheet tags
queued with `addCSS()`.

- `$this->template` and `$this->layout` change the layout for one controller.
  `$this->loadTemplate = false` renders the bare view.
- `render()` calls `end()` and returns. After the action returns, the router calls
  `end()` again, so an `end()` override runs twice on a rendered page.
- `redirect($url, $permanent = false)` sends a 302 (or 301) and exits.
  `refRequest('x')` reads the previous request's parameters after a redirect.
- `cacheAndRender('books', ['key' => 'books', 'expiry' => 300])` renders and stores
  the output in `$this->cache` (a `jCache`). Check `$this->cache->isAvailable('books')`
  and echo `$this->cache->get('books')` to serve it.

## REST controllers

Extend `Jambura\Mvc\Rest` and implement `authenticate()`, which is abstract. `init()`
turns off the layout, records the request method, and calls `authenticate()`. A `false`
return, or an exception, answers `401` before any action runs.

```php
<?php
// app/controllers/booksapi.php
class Controller_booksapi extends Jambura\Mvc\Rest
{
    protected function authenticate()
    {
        return isset($_SESSION['user']);
    }

    public function action_list()
    {
        $this->checkRequestMethod('GET');                  // 405 for anything else

        if (!$this->__shelf) {
            $this->sendError(400, 'shelf is required');    // sends and exits
        }

        $this->response['data'] = ORM::for_table('books')
            ->where('shelf', $this->__shelf)
            ->find_array();
    }   // the router calls end(), which sends $this->response as JSON
}
```

- Put the result in `$this->response` and let `end()` send it. **Don't call `render()`**
  in a `Rest` controller: it sends headers and exits without a body.
- `$this->respCode` sets the status (default 200). `setResponseType('text/xml')`
  switches the formatter; JSON, XML and HTML are supported.
- `sendError($code, $message)` **adds** `error` to whatever `$this->response` already
  holds, then exits. Resolve what can fail before you put anything into it.
- The request body is read with `parse_str()`, so only form encoding is understood. For
  JSON, decode `file_get_contents('php://input')` yourself.

## Requests and validation

`$this->request()` gives a controller one read-only `Jambura\Mvc\Request` describing the
call it is answering:

```php
$r = $this->request();

$r->method();                   // 'POST'
$r->isMethod('post', 'put');    // any of them, whatever the casing
$r->mime();                     // 'application/json' - the body's media type
$r->isJson();
$r->accepts();                  // ['application/json', '*/*']
$r->header('Authorization');    // one header, any casing
$r->bearerToken();              // the token out of it
$r->path();                     // 'books/show'
$r->query('page', 1);           // query string, with a default
$r->input('isbn');              // the body: form-encoded or JSON, whichever arrived
$r->all();                      // query + body, the body winning a clash
$r->only(['title', 'isbn']);
$r->has('shelf');
$r->files();
$r->ip();
$r->roles();                    // from the resolver you wire at bootstrap
```

The body is read for every method, so `Rest::put()` and `Rest::delete()` now return values
instead of `false`, and a JSON body needs no decoding of your own.
`$this->request('id')` still works as the old shortcut for `$_REQUEST['id']`.

### Validating a request

`validate()` starts a validator with four links: the HTTP method, the caller's roles, the
shape of the payload, and anything else you write. Each is checked as you call it, and the
first failure ends the request:

```php
public function action_create()
{
    $book = $this->request()->validate()
        ->method('post')
        ->roles(['librarian', 'admin'])
        ->schema([
            'title'  => 'required',
            'isbn'   => ['required', ['regex', '/^[0-9-]{10,17}$/', 'That ISBN does not look right']],
            'copies' => ['required', 'int', ['min', 1]],
            'shelf'  => ['in', ['fiction', 'reference']],
        ])
        ->check([$this, 'withinQuota'])
        ->validated();          // only the fields the schema named

    $this->respCode = 201;
    $this->response['id'] = Jambura\Mvc\Model::factory('books')->add($book);
}
```

Write the links in the order you want them checked. Method, roles, schema, checks is the
house order: cheapest first, and a caller without permission never learns whether their
payload was valid.

### Reusable specs

A validator built with `new` has no request, so it only records its links. That makes it a
reusable description of what a request must look like:

```php
use Jambura\Mvc\RequestValidator;

class BookRequests
{
    public static function create()
    {
        return (new RequestValidator())
            ->method('post')
            ->roles(['librarian', 'admin'])
            ->schema(['title' => 'required', 'isbn' => 'required']);
    }
}
```

Hand it to `validate()`, and carry on chaining after it:

```php
$book = $this->request()
    ->validate(ApiDefaults::jsonOnly(), BookRequests::create())   // run in the order given
    ->schema(['copies' => ['required', 'int', ['min', 1]]])       // merges with their schema
    ->check([$this, 'withinQuota'])
    ->validated();
```

Running a spec copies its links and leaves the spec alone, so one spec serves every request.
`schema()` merges and `check()` appends; `method()` and `roles()` replace, since there is
only one answer to each.

Keep spec builders cheap: literals only. Anything needing a query belongs in a `function`
rule or a `check`, so it runs only when the link is reached.

```php
->schema(['shelf' => ['in', Shelf::allNames()]])       // queries on every request, even refused ones
->schema(['shelf' => ['function', 'shelfExists']])     // runs only if the schema is reached
```

### Schema rules

The rules are `Jambura\Mvc\Validator`'s, which are `Model::validation()`'s, so a rule reads
the same in a model and in a controller. Fields are read from the query string and the body
together.

| Rule | Passes when |
|---|---|
| `required` | the field arrived with something in it; an empty string counts as absent |
| `int`, `number`, `bool`, `email` | the value is one of those |
| `['regex', $pattern, $message]` | the pattern matches; the message is optional |
| `['in', ['a', 'b']]` | the value is one of the listed ones |
| `['min', 1]`, `['max', 10]`, `['between', 13, 120]` | the number is in range |
| `['length', 2, 60]` | the string's length is in range; the maximum is optional |
| `['function', 'shelfExists']` | your method returns true; return a string to use it as the message |

A `function` rule calls the controller, and may be protected, as a model's validation
callbacks are. It is given the value and every value being checked. Every field is checked,
not just the first to fail.

`Validator` works on its own too, wherever you have an array to check:

```php
$validator = Jambura\Mvc\Validator::make($payload, ['email' => ['required', 'email']]);
if ($validator->fails()) {
    print_r($validator->errors());   // ['email' => ['email is required']]
}
```

### Checks of your own

A check is a controller method, a closure, or a class implementing
`Jambura\Mvc\RequestCheck`. It gets the `Request`, and returns `true` to pass, `false` for a
plain 422, a string for a 422 with that message, or an array to choose the status:

```php
protected function withinQuota(Jambura\Mvc\Request $request)
{
    return Quota::remaining($request->roles()) > 0
        ? true
        : ['status' => 429, 'error' => 'Monthly quota used up'];
}

->check([$this, 'withinQuota'])                  // protected is fine
->check(new DuringOpeningHours())                 // implements RequestCheck
->check(fn (Request $r) => $r->input('to') !== $r->input('from') ?: 'Shelves must differ')
```

### Roles

The framework does not know what a user is, so the application says once, at bootstrap:

```php
// index.php
Jambura\Mvc\RequestValidator::resolveRolesUsing(function () {
    return isset($_SESSION['user']) ? $_SESSION['user']['roles'] : null;   // null = nobody signed in
});
```

`roles()` is authorization; `Rest::authenticate()` stays authentication. A caller with no
session gets 401, and a signed-in caller without a listed role gets 403.

### What a caller sees

| Status | When |
|---|---|
| 405 | the method is not allowed, with an `Allow` header naming the ones that are |
| 401 | the roles resolver reported nobody signed in |
| 403 | signed in, but holding none of the listed roles |
| 400 | a JSON body that would not decode |
| 422 | a schema failed, with `fields` naming the messages per field |
| yours | whatever status your own check asked for |

A `Rest` controller sends the status and stops, the way `sendError()` does:

```json
{"error": "Validation failed", "fields": {"isbn": ["That ISBN does not look right"]}}
```

A plain controller has no JSON to send, so the failure throws `jamexRequestInvalid`, whose
`status()` and `fields()` your error page can render. So does a `Request` built by hand,
which is what makes the whole thing testable without a web server.

## MCP: serving actions as tools

`Jambura\Mvc\Mcp` publishes actions an application already has as
[MCP](https://modelcontextprotocol.io) tools, so a model can call them with the same
validation, the same roles and the same schema as an HTTP caller. The validator spec from
[Requests and validation](#requests-and-validation) is the tool's `inputSchema` — a tool is
never described twice.

```php
// index.php
use Jambura\Mvc\Mcp;

Mcp::describe(['name' => 'ag-ai', 'version' => '1.0'], ['https://app.example']);

Mcp::tool(
    'books.create',                        // the name a model calls
    'Add a book to the catalogue',         // what a model reads when choosing a tool
    'books',                               // controller
    'create',                              // action, without action_
    BookRequests::create(),                // the spec: schema, method and roles
    'Create book'                          // optional title for a UI
);
```

```php
// app/controllers/mcp.php - the endpoint
class Controller_mcp extends Jambura\Mvc\Rest
{
    protected function authenticate()
    {
        return true;                       // the tools' own roles do the gating
    }

    public function action_index()
    {
        Jambura\Mvc\Mcp::serve($this->request());   // sends the answer and exits
    }
}
```

**Registration is deliberate.** Enabling the endpoint publishes nothing: an action becomes
a tool only where `tool()` names it. Anything else would hand a model every destructive
action the application has.

**A call runs the real action.** The tool's arguments become the request body, the method
comes from the spec's `method()`, and the caller's `Authorization` header is carried
through, so the roles resolver sees the same caller it would over HTTP. The action's
`$this->response` comes back as the tool's `structuredContent`, with the JSON also in a
text block.

**Roles filter the list, not just the call.** A caller is never shown a tool their roles
would refuse, and calling one they cannot see answers "unknown tool" rather than confirming
it exists.

**Two error channels, which is the point.** A validation failure is a *tool error* —
`isError` with the field messages — so a model can correct itself and retry. Only an
unknown tool or a malformed envelope is a protocol error.

### What is implemented

Revision **2026-07-28** over Streamable HTTP: `server/discover`, `tools/list`, `tools/call`
and `ping`, answered as one JSON object per POST.

`server/discover` is how a client learns the protocol versions and capabilities in one
request, and it is the only place a server declares its capabilities now that there is no
handshake to declare them in. Pass `instructions` to `describe()` to tell a model how to use
the server:

```php
Mcp::describe([
    'name' => 'ag-ai',
    'version' => '1.0',
    'instructions' => 'Ask about indexed documents; search before answering.',
]);
```

| | |
|---|---|
| Transport | POST only; GET and DELETE answer `405`. A notification answers `202` with no body |
| Stateless | no `initialize`, no sessions. Every request carries `_meta` with its protocol version and client capabilities; a request missing either gets `-32602` |
| Mirrored headers | `MCP-Protocol-Version`, `Mcp-Method`, and `Mcp-Name` on a call, each checked against the body. A mismatch is `400` with `-32020`; a Base64 `=?base64?…?=` name is decoded first |
| Versions | an unsupported version is `400` with `-32022` naming what this server speaks |
| Unknown method | `404` with `-32601` |
| Origin | checked against the allowlist `describe()` was given, `403` otherwise, which is what stops DNS rebinding reaching a local endpoint |

### What is not

SSE streaming, `subscriptions/listen` and list-changed notifications, multi round-trip
input requests, pagination, `resources/*`, `prompts/*`, elicitation, the stdio transport,
`outputSchema`, and the pre-2026 `initialize` handshake. A client asking for an older
revision is told which ones this server speaks rather than guessed at.

### Schemas

`Validator::jsonSchema()` translates what JSON Schema can express:

| Rule | Becomes |
|---|---|
| `required` | the field in `required` |
| `int`, `number`, `bool` | `type` integer, number, boolean |
| `email` | `type` string with `format: email` |
| `in` | `enum`, plus the type when the values agree |
| `min`, `max`, `between` | `minimum` / `maximum` |
| `length` | `type` string with `minLength` / `maxLength` |
| `regex` | `pattern`, delimiters stripped, and the rule's message as the property's `description` |

A field with no type rule is described as a string, since that is how a value arrives in a
query string or a form body. `additionalProperties` is `false`, matching `validated()`.

Two rules have no schema equivalent and are **not advertised**: `function` rules and
`check()`s. They still run on every call. A `regex` carrying flags is left out as well,
because JSON Schema patterns are ECMA-262 — `/x/i` would silently become case-sensitive,
which is worse than advertising no pattern.

### Capture mode

A REST controller sends its response and exits, so nothing in the process can read what it
answered. `Rest::captureNext($request)` makes the next controller answer that request and
collect its response instead: `sendResponse()` stores it, `sendError()` raises
`jamexResponseReady` rather than exiting, and `capturedResponse()` hands back the status and
payload. `Mcp` uses it to run a tool call, and it is what makes a controller's REST
responses testable at all.

## Models

A model wraps one table. It is named `Model_{name}`, lives where your autoloader finds
it, and should always set `$tableName`.

```php
<?php
// app/models/books.php
class Model_books extends Jambura\Mvc\Model
{
    protected $tableName = 'books';

    protected $relations = [
        // c2p: this table holds the foreign key
        'author'  => ['table' => 'authors', 'column' => 'author_id', 'direction' => 'c2p'],
        // p2c: another table points at this one; type is 1-1 or 1-M
        'reviews' => ['table' => 'reviews', 'column' => 'book_id', 'direction' => 'p2c', 'type' => '1-M'],
    ];

    protected function validation()
    {
        return [
            'isbn'  => ['regex', '/^[0-9-]{10,17}$/', 'That ISBN does not look right'],
            'title' => ['function', 'notBlank'],
        ];
    }

    protected function notBlank($value)
    {
        return trim((string) $value) !== '';
    }

    protected function beforeSave()
    {
        // runs before every insert and update
    }
}
```

```php
$book = Jambura\Mvc\Model::factory('books', 7);   // the row, and its relations
if ($book->loaded()) {
    echo $book->title;                  // false if there is no such column
    if ($book->author) {                // relations are idiorm objects, not models
        echo $book->author->name;
    }
    $book->title = 'Dune';              // validated, and the change is recorded
    $book->save();                      // the id, or false
    print_r($book->diff());             // ['title' => ['oldValue' => ..., 'newValue' => 'Dune']]
}

$isbn = Jambura\Mvc\Model::factory('books')->loadBy('isbn', '978-0441013593');

Jambura\Mvc\Model::factory('books')->add([
    'title'      => 'Dune',
    'created_at' => ['created_at', 'NOW()'],   // a two-item array is an SQL expression
]);
```

- `factory('books', $id)` takes the name after `Model_`, not the class name.
  `new Model_books($id)` is equivalent.
- **Check `loaded()` only after loading something.** A model made without an id
  (`factory('books')`) reports `loaded()` as true, because it holds a fresh, empty row.
- `loadBy($column, $value)` and the magic `loadBy{Column}($value)` return the model
  either way, so check `loaded()`. The magic form takes the column name verbatim from
  the method name: `loadByIsbn` queries `Isbn`.
- Inside a model, `$this->table` is the idiorm query, so custom finders chain on it.
  `find_many()` gives idiorm objects, not models.
- Validation runs on property assignment. A failed rule throws
  `Jambura\Mvc\JamburaValidationError`. `add()` assigns directly and skips validation.
- `findAll()` returns an array. `findAll('stack')` and `findAll('queue')` wrap it in
  `jStack` or `jQueue`.
- `isNewRecord()`, `isChanged()`, `set($column, $value)` (chainable), `set_expr()` and
  `delete()` round out the API.

## Helpers

| Class | What it does |
|---|---|
| `jAssets` | `$this->assets`: queue files with `in('footer')->addJS(...)` / `addCSS(...)`, print them with `loadJS('footer')` / `loadCSS('default')`. Adding a file that doesn't exist throws |
| `jFlash` | `$this->jFlash` and `$jFlash` in views: `success`, `error`, `warning` and `info` set a session message; `getMsg()`, `getType()` and `clear()` read it |
| `jCache` | `$this->cache`: a JSON file cache in `/tmp/`, with `store($key, $data, $seconds)`, `get`, `isAvailable`, `erase` and `eraseExpired` |
| `jRouter`, `jController`, `jModel` | global aliases for `Jambura\Mvc\Router`, `Controller` and `Model` |
| `jamex`, `jamexPageNotFound`, `jamexBadController`, `jamexBadAction` | the router's exceptions |
| `jamexRequestInvalid` | a request a validator refused, outside a REST controller: `status()` and `fields()` say why |
| `jStack`, `jQueue` | wrappers returned by `findAll('stack' / 'queue')`. **Not autoloaded:** require `src/data-structure/jdatastructures.php` and the class file before using them |

`Router::showErrorPage($page, $exception)` logs through a `\Logger` class your
application must provide. With `JAMBURA_MOD` set to `'DEV'` it renders the exception with
[filp/whoops](https://github.com/filp/whoops), which is not a dependency of this package,
so require it yourself if you use this.

## LLM adapters

`Jambura\LLM` gives every model the same interface. Your code builds one
`Jambura\LLM\Prompt` and hands it to a model adapter, and only the adapter knows how that
model's API wants the prompt formatted and called. The framework ships no adapters: you
write one class per model you use, and register it.

**A prompt** keeps its parts separate, so each adapter can format them its own way:

```php
use Jambura\LLM\Prompt;

$prompt = Prompt::create()
    ->setType('voyage-summary')                  // for routing; adapters don't send it
    ->setRole('You are a shipping analyst.')
    ->addContext('static', 'Port rules: ...')
    ->addContext('retrieved', $eta, $berth)      // static, retrieved, dynamic or conversation
    ->addInstruction('Be brief.', 'Cite the context you use.')
    ->setTask('Summarize the delay for the charterer.');
```

`getContext()` returns only the sections that have items, always in the order listed
above. An unknown section name throws `Jambura\LLM\LLMException`.

**An adapter** extends `Jambura\LLM` and implements `send()`. The framework formats the
prompt before `send()` runs, and two optional properties control how:

```php
<?php
namespace AIModel;

use Jambura\LLM;
use Jambura\LLM\Format;
use Jambura\LLM\Prompt;

class Example extends LLM
{
    protected string $model = 'example-large';

    // Format::Xml (the default), Format::Json or Format::Text.
    protected Format $format = Format::Json;

    // Sections rendered before the task, in this order. The default is
    // ['role', 'context', 'instructions']. This API takes the role separately.
    protected array $order = ['context', 'instructions'];

    protected function send(string $formattedPrompt, Prompt $prompt): string
    {
        // Put $formattedPrompt, and $prompt->getRole() since role isn't in $order, into
        // the request the model's API expects. Make the call with your HTTP client or the
        // provider's SDK, and return the reply text. Throw Jambura\LLM\LLMException when
        // the call fails.
    }
}
```

The same prompt in each format, with the default order:

```xml
<role>Analyst</role>
<context>
  <retrieved>
    <item>ETA 14:00</item>
  </retrieved>
</context>
<instructions>
  <instruction>Be brief.</instruction>
</instructions>
<task>Summarize.</task>
```

```json
{
    "role": "Analyst",
    "context": {
        "retrieved": ["ETA 14:00"]
    },
    "instructions": ["Be brief."],
    "task": "Summarize."
}
```

```
Role:
Analyst

Context (retrieved):
- ETA 14:00

Instructions:
- Be brief.

Task:
Summarize.
```

- **The task is always rendered last**, whatever `$order` says. `$order` can reorder
  `role`, `context` and `instructions`, or leave any of them out. Listing `task`, an
  unknown name or the same section twice throws `LLMException` when the class is
  registered.
- Empty sections are left out in every format, and the prompt's `type` is never rendered.
- XML and JSON escape the prompt's text, so a context item can't close a tag or end a
  string early. Text can't escape anything: an item containing `Task:` reads like a
  heading. Use Text only for content you trust.
- For a format the list doesn't cover, override `handlePrompt(Prompt $prompt): string`.
  Whatever it returns is passed to `send()`.

**Register adapters once**, at bootstrap, then use them anywhere:

```php
use Jambura\LLM;

LLM::registerModels([AIModel\Example::class, AIModel\Another::class]);

$reply = LLM::use(AIModel\Example::class)->prompt($prompt);
```

- `registerModels()` checks each class straight away. A class that doesn't exist, doesn't
  extend `Jambura\LLM`, is abstract, or has an invalid `$order` throws `LLMException` at
  bootstrap, not on the first prompt.
- `use()` builds an adapter the first time and returns that same instance after. Using a
  class that was never registered throws. `forgetModels()` empties the registry.
- `prompt()` only accepts a `Prompt`, throws if it has no task, and returns the reply from
  `send()`.
- `use()` builds adapters through a final, protected constructor, so `new` can't be used.
  Give an adapter its defaults as property values and its settings through setters.
  `setModel()` and `getModel()` are already there for the model id.

## LLM pipelines

`Jambura\LLM\Pipeline` puts a job's steps in order and runs them over one shared
`Jambura\LLM\Context`. Define a pipeline where the application boots, then run it from a
controller, a command or a queue job.

```php
use Jambura\LLM\Pipeline;

Pipeline::make('mates_receipt')
    ->configure(['currency' => 'CAD'])
    ->gatekeeper('check_attachment', AttachmentGuard::class)
    ->preprocessor('save_attachments', FileStorage::class)
    ->preprocessor('attach_vendor', VendorLookup::class)
    ->model('process_document', AIModel\Claude::class)
    ->step('file_receipt', ReceiptFiler::class)
    ->route('default', ['check_attachment', 'save_attachments', 'attach_vendor', 'process_document', 'file_receipt'])
    ->route('retry', ['process_document', 'file_receipt']);
```

```php
$prompt = Prompt::create()
    ->setType('receipt')
    ->setTask('Extract the total and the vendor.');

$context = Pipeline::use('mates_receipt')->followRoute('default')->feed($prompt, [
    'attachments' => $request->files('attachments'),
    'user_id'     => $userId,
]);

$context->get('response');   // the model's reply
$context->prompt();          // the prompt as the steps left it
$context->ranSteps();        // the steps that ran, in order
$context->wasStopped();      // true when a gatekeeper ended the run early
```

**The verbs.** Each registers one step. The step's name comes first, because that is what
routes refer to, and the kind decides both what the step must implement and what the
pipeline does with what it returns:

| Verb | The step is | It must | Its return value |
|---|---|---|---|
| `gatekeeper()` | a check that can stop the run | implement `Gatekeeper`: `allows(Context): bool\|array` | `false` stops the run; an array adds values |
| `preprocessor()` | preparation before the model | implement `Preprocessor`: `process(Context): array` | the array adds values to the context |
| `model()` | the model call | be an **adapter** - a `Jambura\LLM` subclass | the reply is stored as `response` |
| `step()` | anything else, such as filing the result | implement `Step`: `handle(Context): mixed` | an array adds values |

The method is never named in the definition: it comes from the interface, so a step class
has one entry point.

**A model step needs no class of your own.** It names one of your adapters, and the
pipeline sends the run's prompt through it:

```php
->model('process_document', AIModel\Claude::class)   // LLM::use(...)->prompt($context->prompt())
```

The adapter is registered with `Jambura\LLM` for you, and it decides the format and order
as always. Work around the call belongs in its own step: prepare the prompt in a
preprocessor before it, and parse or validate the reply in a `step()` after it.

**Steps work on the run's prompt.** A preprocessor adds what it found to the prompt's own
context sections rather than assembling text of its own:

```php
use Jambura\LLM\Context;
use Jambura\LLM\Preprocessor;

class VendorLookup implements Preprocessor
{
    public function process(Context $context): array
    {
        $vendor = Vendor::findBy($context->get('user_id'));
        $context->prompt()->addContext('retrieved', "Vendor: {$vendor->name}");

        return ['vendor_id' => $vendor->id];
    }
}
```

A step that would rather replace the prompt than add to it calls
`$context->setPrompt($other)`, which is what a filtering or trimming step does.

**Closures** stand in for any verb where a class would be too much, and take the same name
argument:

```php
->gatekeeper('positive_total', fn (Context $c) => $c->get('total') > 0)
->step('flag_for_human', fn (Context $c) => ['queued' => true])
```

They follow the same return rules, but can't be reused across pipelines and won't appear as
a named class in a stack trace.

**Routes.** `route()` names an order, and a pipeline can hold several over the same steps -
a `default` route and a `retry` route that skips the expensive parts. `followRoute()` picks
one and the choice sticks to the pipeline; `feed()` falls back to `default` when nothing was
chosen. Steps and routes can be declared in any order, and every name in a route is checked
before the first step runs, so a typo does no work.

**`feed()` takes a `Prompt` and nothing else**, for the same reason `LLM::prompt()` does: a
run always carries the structured prompt, and no step has to guess what a loose string was
meant to be. Anything that isn't part of the prompt - uploads, ids, records - travels beside
it as the run's values, the second argument. The prompt is copied before the run, so steps
add to the run's own copy and the object you passed stays as it was, ready to feed to
another pipeline.

**The context.** `prompt()` and `setPrompt()` reach the run's prompt; `get()`, `set()`,
`merge()`, `has()` and `all()` carry the run's values; `setting()` reads what `configure()`
was given. `ranSteps()`, `wasStopped()` and `stoppedAt()` say what happened, which is what
you log or assert on.

**Errors.** Everything throws `Jambura\LLM\LLMException`, and every case is a mistake in the
definition or the call: an unknown pipeline or route, a name used twice, a step class that
doesn't implement its verb's interface or a model step that isn't an adapter, or a route
naming a step that was never registered. A gatekeeper stopping a run is not one of them:
`feed()` returns the context with `wasStopped()` true, so the caller decides what that
means.

## Migrations

`robmorgan/phinx` is installed with the framework, so `vendor/bin/phinx` is available
to every application.

Phinx 0.16 changed two defaults: an implicit `id` is now `int unsigned`, and columns are
nullable unless told otherwise. Migrations written under Phinx 0.11 (jambura-core 2.x)
replay into a different schema under those defaults. They can even fail outright, once a
signed foreign key points at an unsigned id. Turn both off in `phinx.php`:

```php
'feature_flags' => [
    'unsigned_primary_keys' => false,
    'column_null_default'   => false,
],
```

## Upgrading from 2.x

- PHP 8.1 is the minimum.
- Remove any loop that includes `src/*.php` by hand. `src/jambura.php` is autoloaded
  now, and including it again is a fatal redeclaration.
- idiorm 2 no longer implements `Serializable`. Serializing a result set now works: it
  used to throw a `TypeError`.
- If you have existing migrations, add the Phinx feature flags above.
- `Model::each()` works again. It called PHP's removed `each()` and was fatal on PHP 8.

## Known issues

- `Router::route()` does not validate the controller name before including it. Sanitize
  `$_GET['controller']` first, as the bootstrap example does.

## License

MIT © 2023 Prepmock Online Inc.
