<?php
namespace Jambura\Mvc;

class Router
{
    private $controller;
    private $action;

    public function route($namespace = '')
    {
        // Route resolves; this method only dispatches. Segment validation lives
        // there too, so nothing unchecked reaches the include() below.
        $route = Route::fromRequest(Request::fromGlobals());

        if ($route === null) {
            header('location:/' . DEFAULT_PAGE);
            return;
        }

        if (!$route->exists()) {
            // FIXME rather than throwing exception this should display a
            // default 404 page.
            throw new \jamexBadController('Invalid controller file : ' . implode('/', $route->segments()));
        }

        include($route->file());

        $this->action = $route->action();
        $class = $this->classIn($route, $namespace);
        $this->controller = new $class();
        return $this;
    }

    /**
     * The controller class the included file turned out to hold.
     *
     * api/ask.php is expected to hold Controller_api_ask, falling back to
     * Controller_ask when it does not, so a controller that moves into a
     * directory keeps working without being renamed. For a controller that is
     * not nested the two names are the same, so nothing changes for one.
     *
     * No autoloading: the file has just been included, so the class is either
     * there or the name is wrong.
     */
    private function classIn(Route $route, $namespace)
    {
        $class = $namespace . $route->className();
        if (class_exists($class, false)) {
            return $class;
        }

        $segments = $route->segments();
        $fallback = $namespace . 'Controller_' . end($segments);

        return class_exists($fallback, false) ? $fallback : $class;
    }

    public function display()
    {
        if ($this->controller) {
            if (!method_exists($this->controller, $this->action)) {
                throw new \jamexBadAction("Action: $this->action does not exist");

            }
            $action = $this->action;
            $this->controller->$action();
            $this->controller->end();
        }
    }

    public static function showErrorPage($page, \Exception $e, $forceErrorReport = false)
    {
        \Logger::error($e->getMessage());
        if (JAMBURA_MOD == 'DEV' || $forceErrorReport) {
            $whoops = new \Whoops\Run;
            $whoops->allowQuit(false);
            $whoops->writeToOutput(false);
            $whoops->pushHandler(new \Whoops\Handler\PrettyPageHandler);
            $html = $whoops->handleException($e);

            echo $html;
            return;
        }

        if (is_array($page)) {
            // The failed request's `r` is still in $_GET, and `r` beats
            // controller/action - without this the error page routes straight
            // back to the controller that just threw.
            unset($_GET['r']);
            $_GET['controller'] = $page[0];
            $_GET['action'] = $page[1];

            try {
                (new \jRouter())->route()->display();
            } catch (\Exception $e) {
                \jRouter::showErrorPage('', $e, true);
            }
        } else {
            include($page);
        }
    }
}
