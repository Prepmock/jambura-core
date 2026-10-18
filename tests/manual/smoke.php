<?php
/**
 * End-to-end check of Router::route() + display(): real files, real include(),
 * real instantiation.
 *
 *     php tests/manual/smoke.php
 *
 * Not a PHPUnit test, and deliberately outside the suite: Router needs
 * JAMBURA_CONTROLLERS and DEFAULT_PAGE defined, and constants cannot be
 * undefined once set, so a test that defines them leaks into every other test
 * in the process. RouteTest covers the resolving; this covers the dispatching.
 *
 * The controllers below are stubs rather than jController subclasses, so this
 * exercises routing without dragging in assets, cache and session.
 */

require __DIR__ . '/../../vendor/autoload.php';

$base = sys_get_temp_dir() . '/jambura-smoke-' . uniqid() . '/';
mkdir($base, 0777, true);

define('JAMBURA_CONTROLLERS', $base);
define('DEFAULT_PAGE', 'home');
define('JAMBURA_MOD', 'PROD');                 // not DEV, so showErrorPage takes the array branch

class Logger { public static function error($message) {} }   // normally the application's

function controller(string $path, string $class, array $actions): void
{
    $file = JAMBURA_CONTROLLERS . $path . '.php';
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0777, true);
    }
    $body = "<?php\nclass $class {\n";
    foreach ($actions as $action) {
        $body .= "    public function $action() { echo '$class::$action'; }\n";
    }
    $body .= "    public function end() {}\n}\n";
    file_put_contents($file, $body);
}

controller('one', 'Controller_one', ['action_index']);
controller('two', 'Controller_two', ['action_ask']);
controller('nest/deep', 'Controller_nest_deep', ['action_index']);
controller('nest/moved', 'Controller_moved', ['action_index']);      // old class name
controller('books', 'Controller_books', ['action_show']);
controller('sh', 'Controller_sh', ['action_x']);
controller('sh/x', 'Controller_sh_x', ['action_index']);             // shadows action_x
controller('three', 'Controller_three', ['action_index']);
controller('four', 'Controller_four', ['action_index']);
controller('errpage', 'Controller_errpage', ['action_show']);

// Router include()s rather than include_once, so each case below needs its own
// controller file: one process cannot dispatch the same one twice.

function dispatch(array $query): string
{
    $_GET = $query;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/';
    ob_start();
    try {
        $router = (new Jambura\Mvc\Router())->route();
        if ($router) {
            $router->display();
        }
        return ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        return get_class($e) . ': ' . $e->getMessage();
    }
}

$cases = [
    'r, index action'          => [['r' => 'one'], 'Controller_one::action_index'],
    'r, named action'          => [['r' => 'two/ask'], 'Controller_two::action_ask'],
    'r, nested controller'     => [['r' => 'nest/deep'], 'Controller_nest_deep::action_index'],
    'r, old class name'        => [['r' => 'nest/moved'], 'Controller_moved::action_index'],
    'r, longest path wins'     => [['r' => 'sh/x'], 'Controller_sh_x::action_index'],
    'controller + action'      => [['controller' => 'books', 'action' => 'show'], 'Controller_books::action_show'],
    'r beats controller'       => [['r' => 'three', 'controller' => 'books'], 'Controller_three::action_index'],
    'traversal rejected'       => [['r' => '../../etc/passwd'], 'jamexBadController: Invalid controller segment : ..'],
    'traversal via controller' => [['controller' => '../x'], 'jamexBadController: Invalid controller segment : ..'],
    'newline rejected'         => [['r' => "one\n"], "jamexBadController: Invalid controller segment : one\n"],
    'missing controller'       => [['r' => 'nope'], 'jamexBadController: Invalid controller file : nope'],
    'unknown action'           => [['r' => 'four/zzz'], 'jamexBadAction: Action: action_zzz does not exist'],
];

$failed = 0;
foreach ($cases as $name => [$query, $expected]) {
    $got = dispatch($query);
    $ok = $got === $expected;
    $failed += $ok ? 0 : 1;
    printf("%-26s %s\n", $name, $ok ? 'ok' : "FAIL\n    expected " . json_encode($expected) . "\n    got      " . json_encode($got));
}

// showErrorPage's array form must survive a stale `r` from the failed request.
$_GET = ['r' => 'one'];
ob_start();
Jambura\Mvc\Router::showErrorPage(['errpage', 'show'], new Exception('boom'));
$out = ob_get_clean();
$ok = $out === 'Controller_errpage::action_show';
$failed += $ok ? 0 : 1;
printf("%-26s %s\n", 'error page past stale r', $ok ? 'ok' : 'FAIL got ' . json_encode($out));

echo $failed ? "\n$failed FAILED\n" : "\nall ok\n";
exit($failed ? 1 : 0);
