<?php
namespace Jambura\Mvc;
use Jambura\Acl;

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
        $this->filterAccessControl();
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
            $this->data[$prefix . $key] = urldecode($value);
        }
    }

    public function render($view, $variables = array())
    {
        if (!file_exists($viewFile = JAMBURA_VIEWS . $view . '.php')) {
            throw new Exception('View file not found :' . $viewFile);
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

    protected function request($var)
    {
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
    
    protected function accessRules(): array
    {
        return [];
    }

    /**
     * Grants access to an action based on the controller provided access rules.
     *  
     * If access is granted, it proceeds to display the action.
     * If access is not granted, it displays an error code.
     * 
     * @return void
     */
    private function filterAccessControl()
    {
        // Show default index if no specific action is set 
        $action = isset($_GET['action']) ? $_GET['action'] : 'index';

        // Uses helper class to set the access rules and filter access
        Acl::init()
            ->setRules($this->accessRules())
            ->checkAccess($action);  
    }
}
