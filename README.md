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

Composer autoloads everything the framework ships: `Jambura\Mvc\*`, `Jambura\LLM\*` and
`AIModel\*` by PSR-4, the `Jambura\LLM` class by classmap, the global
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
| `jStack`, `jQueue` | wrappers returned by `findAll('stack' / 'queue')`. **Not autoloaded:** require `src/data-structure/jdatastructures.php` and the class file before using them |

`Router::showErrorPage($page, $exception)` logs through a `\Logger` class your
application must provide. With `JAMBURA_MOD` set to `'DEV'` it renders the exception with
[filp/whoops](https://github.com/filp/whoops), which is not a dependency of this package,
so require it yourself if you use this.

## LLM adapters

`Jambura\LLM` sends a structured prompt to any registered model adapter. Your code
builds one `Jambura\LLM\Prompt` and never formats text for a particular model. Each
adapter turns the prompt into the request its own API expects.

```php
use Jambura\LLM;
use Jambura\LLM\Prompt;

// Once, at bootstrap. A class that doesn't exist or isn't an adapter throws here.
LLM::registerModels([
    AIModel\Claude::class,
    AIModel\Deepseek::class,
]);

$prompt = Prompt::create()
    ->setType('voyage-summary')                  // for routing; never sent to the model
    ->setRole('You are a shipping analyst.')
    ->addContext('static', 'Port rules: ...')
    ->addContext('retrieved', $eta, $berth)      // static, retrieved, dynamic or conversation
    ->addInstruction('Be brief.', 'Cite the context you use.')
    ->setTask('Summarize the delay for the charterer.');

$reply = LLM::use(AIModel\Claude::class)->prompt($prompt);   // the reply text
```

- `prompt()` only takes a `Prompt`. It throws `Jambura\LLM\LLMException` when the prompt
  has no task, the API call fails, or the model declines the request.
- `use()` builds an adapter on first use and returns that same instance after. Using a
  class that was never registered throws. `forgetModels()` empties the registry.
- Every adapter puts the task last, and leaves out empty context sections.
- `serialize($prompt)` returns the request body an adapter would send, without calling
  the API.

| Adapter | Default model | Needs | Sends |
|---|---|---|---|
| `AIModel\Claude` | `claude-opus-5` | `composer require anthropic-ai/sdk guzzlehttp/guzzle` (the SDK needs a PSR-18 HTTP client; Guzzle is one), plus `ANTHROPIC_API_KEY` or `setClient()` | the role as the system prompt; context, instructions and task as XML sections |
| `AIModel\Deepseek` | `deepseek-v4-pro` | the curl extension, plus `DEEPSEEK_API_KEY` or `setApiKey()` | the role as the system message; context, instructions and task as a JSON document |

`setModel()` changes either adapter's model, and Claude also has `setMaxTokens()`
(default 16000). The Claude adapter opts into Anthropic's server-side fallbacks, so a
request Claude declines is retried on a fallback model before anything throws.

To add a model, extend `Jambura\LLM`, implement `serialize(Prompt $prompt): array` and
`send(array $payload): string`, then register the class. `use()` builds adapters through
a final, protected constructor, so give an adapter its defaults as property values.

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

- `Rest::put()` and `Rest::delete()` always return `false`. `setRequestPayload()` parses
  the body into properties that were never declared, so the values are lost.
- `Router::route()` does not validate the controller name before including it. Sanitize
  `$_GET['controller']` first, as the bootstrap example does.

## License

MIT © 2023 Prepmock Online Inc.
