<?php
namespace Jambura\Mvc;

class Controller
{
    protected $loadTemplate = true;
    protected $template = DEFAULT_TEMPLATE;
    protected $layout = DEFAULT_LAYOUT;
    protected $cache = null;
    protected $data = array();

    private $getVars = array();
    private $postVars = array();
    private $requests = array();

    private $headers = null;

    /**
     * The request being answered, built the first time request() is called.
     * @var Request|null
     */
    private $request = null;

    public function __construct($api = false)
    {
        if ($api) {
            $this->parseApi = true;
        }

        $this->assets = new \jAssets();
        $this->cache = \jCache::init();
        // FIXME base controller should have been defined as an abstruct
        // class if this init is kept like this.
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }

        if (isset($_SESSION['refRequest'])) {
            $this->data['refRequest'] = $_SESSION['refRequest'];
            unset($_SESSION['refRequest']);
        }

        $this->data['jFlash'] = new \jFlash();
        $this->init();
    }

    public function __set($var, $value)
    {
        $this->data[$var] = $value;
    }

    public function __isset($name)
    {
        return isset($this->data[$name]);
    }

    public function __get($var)
    {
        if (preg_match('/^__/', $var)) {
            if (!isset($this->data[$var])) {
                $requestVar = preg_replace('/(^__)(.+)/', '${2}', $var);
                $this->data[$var] = $this->request($requestVar);
            }
            return $this->data[$var];
        }

        if (array_key_exists($var, $this->data)) {
            return $this->data[$var];
        }
    }

    protected function loadRequests($prefix = '__')
    {
        foreach ($_REQUEST as $key => $value) {
            if ($key == 'controller' || $key == 'action') {
                continue;
            }
            // Arrays pass through untouched (?ids[]=1&ids[]=2): urldecode() takes
            // only a string, and PHP 8 throws a TypeError where 7 warned and
            // returned null. $_REQUEST values are decoded already, so skipping
            // the call loses nothing.
            $this->data[$prefix . $key] = is_string($value) ? urldecode($value) : $value;
        }
    }

    public function render($view, $variables = array())
    {
        if (!file_exists($viewFile = JAMBURA_VIEWS . $view . '.php')) {
            throw new \Exception('View file not found :' . $viewFile);
        }

        if ($this->parseApi) {
            if (is_array($variables)) {
                return json_encode($variables);
            }
        } else {
            $variables = empty($variables) ? $this->data : array_merge($this->data, $variables);
            extract($variables);
            ob_start();
            if ($this->loadTemplate) {
                include(JAMBURA_TEMPLATES . $this->template . '/layouts/' . $this->layout . '/header.php');
            }
            include JAMBURA_VIEWS . $view . '.php';
            if ($this->loadTemplate) {
                include(JAMBURA_TEMPLATES . $this->template . '/layouts/' . $this->layout . '/footer.php');
            }
            $renderedView = ob_get_clean();
            echo $renderedView;
        }
        $this->end();
    }

    protected function get($var)
    {
        if (!isset($this->getVars[$var])) {
            if (!isset($_GET[$var])) {
                return false;
            }
            $this->getVars[$var] = $this->cleanRequest($_GET[$var]);
        }
        return $this->getVars[$var];
    }

    protected function post($var)
    {
        if (!isset($this->postVars[$var])) {
            if (!isset($_POST[$var])) {
                return false;
            }
            $this->postVars[$var] = $this->cleanRequest($_POST[$var]);
        }
        return $this->postVars[$var];
    }

    /**
     * Answers this request instead of the one in the superglobals.
     *
     * For the MCP layer, which runs an action against a request it built from a
     * tool call, and for tests that run an action without a web server.
     *
     * @return $this
     */
    public function withRequest(Request $request)
    {
        $this->request = $request;
        return $this;
    }

    /**
     * The request being answered, or one value out of $_REQUEST.
     *
     * Called with no argument it returns the Jambura\Mvc\Request for this
     * request, which reports the method, headers, body and the rest, and starts a
     * validator with validate():
     *
     *     $this->request()->method();
     *     $this->request()->validate()->method('post')->schema([...]);
     *
     * Called with a name it stays the old shortcut for $_REQUEST[$name],
     * returning false when it is not there. New code should prefer
     * $this->request()->input($name), which also reads a JSON body.
     *
     * @param string|null $var the value to read, or null for the Request
     * @return Request|mixed
     */
    protected function request($var = null)
    {
        if ($var === null) {
            if ($this->request === null) {
                $this->request = Request::fromGlobals($this);
            }
            return $this->request;
        }

        if (!isset($this->requests[$var])) {
            if (!isset($_REQUEST[$var])) {
                return false;
            }
            $this->requests[$var] = $this->cleanRequest($_REQUEST[$var]);
        }
        return $this->requests[$var];
    }

    private function cleanRequest($var)
    {
        // FIXME the below regex makes this impossible to use for strings like
        // passwords. We do want to verify the request but we need something better
        //return preg_replace('/[^-a-zA-Z0-9_@ \.\/\:\$]/', '', $var);
        return $var;
    }

    public function getRenderData()
    {
        return $this->data;
    }

    protected function header($name)
    {
        if (null === $this->headers) {
            $this->headers = getallheaders();
        }
        if (isset($this->headers[$name])) {
            return $this->headers[$name];
        }
        return false;
    }

    protected function redirect($url, $permanent = false)
    {
        if (headers_sent() === false) {
            $_SESSION['refRequest'] = $_REQUEST;
            header('Location: ' . $url, true, ($permanent === true) ? 301 : 302);
        }
        exit();
    }

    protected function refRequest($param)
    {
        if (!isset($this->refRequest[$param])) {
            return false;
        }

        return $this->refRequest[$param];
    }

    public function init()
    {
        // empty
    }

    public function end()
    {
        // empty
    }

    /**
     * Caches the rendered view and echoes the cached content.
     *
     * @param string $view          The view to render.
     * @param array  $cacheOptions  An array of cache options:
     *                  - 'key': The cache key to store the view content.
     *                  - 'expiry': The expiration time for the cached content.
     * @return void
     */
    public function cacheAndRender($view, $cacheOptions)
    {
        ob_start();
        $this->render($view);
        $response = ob_get_clean();

        $this->cache->store($cacheOptions['key'], $response, $cacheOptions['expiry']);
        echo $response;
    }
}
