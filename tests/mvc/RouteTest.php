<?php

use Jambura\Mvc\Request;
use Jambura\Mvc\Route;
use PHPUnit\Framework\TestCase;

class RouteTest extends TestCase
{
    /**
     * A controller directory of its own, so these tests need no constants and
     * no controllers on the project's disk.
     */
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/jambura-route-' . uniqid() . '/';
        mkdir($this->base, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree(rtrim($this->base, '/'));
    }

    public function testResolvesOneSegmentToItsIndexAction(): void
    {
        $this->controller('books');

        $route = Route::of('books', $this->base);

        $this->assertSame(['books'], $route->segments());
        $this->assertSame($this->base . 'books.php', $route->file());
        $this->assertSame('Controller_books', $route->className());
        $this->assertSame('action_index', $route->action());
        $this->assertTrue($route->exists());
    }

    public function testTakesTheRemainderAsTheActionWhenOnlyTheParentExists(): void
    {
        $this->controller('api');

        $route = Route::of('api/ask', $this->base);

        $this->assertSame(['api'], $route->segments());
        $this->assertSame($this->base . 'api.php', $route->file());
        $this->assertSame('Controller_api', $route->className());
        $this->assertSame('action_ask', $route->action());
        $this->assertTrue($route->exists());
    }

    public function testPrefersTheNestedControllerOverAnActionOfTheSameName(): void
    {
        $this->controller('api');
        $this->controller('api/ask');

        $route = Route::of('api/ask', $this->base);

        $this->assertSame(['api', 'ask'], $route->segments());
        $this->assertSame($this->base . 'api/ask.php', $route->file());
        $this->assertSame('Controller_api_ask', $route->className());
        $this->assertSame('action_index', $route->action());
    }

    public function testResolvesAnActionOnANestedController(): void
    {
        $this->controller('api/books');

        $route = Route::of('api/books/show', $this->base);

        $this->assertSame(['api', 'books'], $route->segments());
        $this->assertSame('Controller_api_books', $route->className());
        $this->assertSame('action_show', $route->action());
    }

    public function testTakesTheLongestPathThatExists(): void
    {
        $this->controller('a');
        $this->controller('a/b');
        $this->controller('a/b/c');

        $this->assertSame(['a', 'b', 'c'], Route::of('a/b/c', $this->base)->segments());
        $this->assertSame(['a', 'b'], Route::of('a/b/z', $this->base)->segments());
        $this->assertSame('action_z', Route::of('a/b/z', $this->base)->action());
    }

    public function testIgnoresSegmentsPastTheAction(): void
    {
        $this->controller('books');

        $route = Route::of('books/show/7', $this->base);

        $this->assertSame(['books'], $route->segments());
        $this->assertSame('action_show', $route->action());
    }

    public function testIgnoresLeadingTrailingAndDoubledSlashes(): void
    {
        $this->controller('books');

        foreach (['/books', 'books/', '/books/', '//books//'] as $path) {
            $this->assertSame(['books'], Route::of($path, $this->base)->segments(), $path);
            $this->assertSame('action_index', Route::of($path, $this->base)->action(), $path);
        }

        $this->assertSame('action_show', Route::of('books//show', $this->base)->action());
    }

    public function testReportsAMissingControllerRatherThanThrowing(): void
    {
        $route = Route::of('nope', $this->base);

        $this->assertFalse($route->exists());
        $this->assertSame($this->base . 'nope.php', $route->file());
        $this->assertSame('Controller_nope', $route->className());
    }

    public function testAMissingNestedControllerKeepsTheWholePath(): void
    {
        $route = Route::of('api/ask', $this->base);

        $this->assertFalse($route->exists());
        $this->assertSame(['api', 'ask'], $route->segments());
        $this->assertSame($this->base . 'api/ask.php', $route->file());
    }

    public function testRejectsEverySegmentShapeThatIsNotAName(): void
    {
        $this->controller('books');

        $shapes = [
            '..',
            '../secret',
            'api/../../etc/passwd',
            '../../etc/passwd',
            'books.php',
            'books.',
            '.hidden',
            "books\0",
            "books\0.php",
            "books\n",
            "books\nshow",
            'api\ask',
            'books show',
            'books;id',
            'books|id',
            'books$x',
            "books'",
            'books"',
            'books<x>',
            '%2e%2e',
            'bo%6fks',
            'books?x',
            'books#x',
            'books&x',
            'café',
        ];

        foreach ($shapes as $shape) {
            $this->assertRejected($shape);
        }
    }

    public function testRejectsAPathThatNamesNoController(): void
    {
        $this->assertRejected('');
        $this->assertRejected('/');
        $this->assertRejected('///');
    }

    public function testReadsTheSingleParameterForm(): void
    {
        $this->controller('api/ask');

        $route = Route::fromRequest(new Request(query: ['r' => 'api/ask']), $this->base);

        $this->assertSame(['api', 'ask'], $route->segments());
        $this->assertSame('action_index', $route->action());
    }

    public function testKeepsTheControllerAndActionForm(): void
    {
        $this->controller('books');

        $route = Route::fromRequest(
            new Request(query: ['controller' => 'books', 'action' => 'show']),
            $this->base
        );

        $this->assertSame(['books'], $route->segments());
        $this->assertSame($this->base . 'books.php', $route->file());
        $this->assertSame('action_show', $route->action());
        $this->assertTrue($route->exists());
    }

    public function testTheControllerAndActionFormIsNotResolved(): void
    {
        // The caller said which is which. A books/show.php appearing later must
        // not move ?controller=books&action=show onto it.
        $this->controller('books');
        $this->controller('books/show');

        $route = Route::fromRequest(
            new Request(query: ['controller' => 'books', 'action' => 'show']),
            $this->base
        );

        $this->assertSame(['books'], $route->segments());
        $this->assertSame('action_show', $route->action());
    }

    public function testDefaultsTheActionInTheControllerForm(): void
    {
        $this->controller('books');

        $route = Route::fromRequest(new Request(query: ['controller' => 'books']), $this->base);

        $this->assertSame('action_index', $route->action());
    }

    public function testTheControllerFormReachesANestedController(): void
    {
        $this->controller('api/ask');

        $route = Route::fromRequest(new Request(query: ['controller' => 'api/ask']), $this->base);

        $this->assertSame(['api', 'ask'], $route->segments());
        $this->assertSame('Controller_api_ask', $route->className());
        $this->assertTrue($route->exists());
    }

    public function testValidatesTheControllerQueryParameter(): void
    {
        $this->expectException(\jamexBadController::class);

        Route::fromRequest(new Request(query: ['controller' => '../../etc/passwd']), $this->base);
    }

    public function testTheSingleParameterFormWins(): void
    {
        $this->controller('api');
        $this->controller('books');

        $route = Route::fromRequest(
            new Request(query: ['r' => 'api', 'controller' => 'books', 'action' => 'show']),
            $this->base
        );

        $this->assertSame(['api'], $route->segments());
        $this->assertSame('action_index', $route->action());
    }

    public function testHasNoRouteWhenTheRequestNamesNone(): void
    {
        $this->assertNull(Route::fromRequest(new Request(), $this->base));
        $this->assertNull(Route::fromRequest(new Request(query: ['r' => '']), $this->base));
        $this->assertNull(Route::fromRequest(new Request(query: ['r' => '/']), $this->base));
        $this->assertNull(Route::fromRequest(new Request(query: ['controller' => '']), $this->base));
        $this->assertNull(Route::fromRequest(new Request(query: ['action' => 'show']), $this->base));
    }

    public function testAnEmptySingleParameterFallsBackToTheControllerForm(): void
    {
        $this->controller('books');

        $route = Route::fromRequest(
            new Request(query: ['r' => '', 'controller' => 'books', 'action' => 'show']),
            $this->base
        );

        $this->assertSame(['books'], $route->segments());
        $this->assertSame('action_show', $route->action());
    }

    /**
     * Asserts a path is refused before it can reach an include().
     */
    private function assertRejected(string $path): void
    {
        try {
            Route::of($path, $this->base);
        } catch (\jamexBadController $e) {
            $this->addToAssertionCount(1);
            return;
        }

        $this->fail('Expected ' . json_encode($path) . ' to be rejected');
    }

    /**
     * Puts an empty controller file at a path under the temporary directory.
     */
    private function controller(string $path): void
    {
        $file = $this->base . $path . '.php';
        $directory = dirname($file);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($file, "<?php\n");
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}
