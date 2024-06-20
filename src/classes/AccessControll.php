<?php
namespace Jambura\Classes;
class AccessControl
{
    private $rules;
    private $controller;
    public static function init()
    {
        return new self();
    }

    /**
     * Set the access control rules for this instance.
     *
     * @param array $rules The access control rules.
     *
     * @return $this
     */
    public function setRules($rules)
    {
        $this->rules = $rules;

        return $this;
    }

    /**
     * Checks whether the specified action has access.
     *
     * @param string $action The action to check access for.
     *
     * @throws \jamexBadMethod When access is denied (HTTP 401).
     *
     * @return bool True if access is granted; otherwise, false.
     */
    public function checkAccess($action)
    {
        $accessRules = $this->rules;

        if (array_key_exists($action, $accessRules)) {
            if (!$this->isAllowed($action)) {
                throw new \jamexBadMethod('Access denied', 401);
            }

            return true;
        }

        if (array_key_exists('*', $accessRules)) {
            $rule = $accessRules['*'];

            if (isset($rule['allow']) && $rule['allow'] == 'all') {
                return true;
            } else {
                throw new \jamexBadMethod('Access denied', 401);
            }
        }
    }

    /**
     * Checks if the specified action is allowed.
     *
     * @param string $action The action to check.
     *
     * @return bool True if the action is allowed; otherwise, false.
     */
    private function isAllowed($action)
    {
        $accessRule = $this->rules;
        $accessMethod = $this->getActionMethod($action);

        if (!$this->isArray($action)) {
            if ($accessMethod === 'all') {
                return true;
            }

            return $accessMethod();
        } else {
            $params = $accessRule[$action]['allow'][1];

            if (!$accessMethod(...$params)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sets the controller name.
     *
     * @param string $controller The controller name.
     *
     * @return $this
     */
    public function setController($controller)
    {
        $this->controller = "Controller_" . $controller;
        return $this;
    }

    /**
     * Gets the action method for the specified action.
     *
     * @param string $action The action to get the method for.
     *
     * @return string The action method name.
     */
    public function getActionMethod($action)
    {
        $accessRule = isset($this->rules[$action]['allow']) ? $this->rules[$action]['allow'] : $this->rules[$action]['deny'];
        if (getType($accessRule) === 'string') {
            if ($accessRule === 'all') {
                return 'all';
            }

            if (preg_match('/::/', $accessRule)) {
                return $accessRule;
            } else {
                return $this->controller . '::' . $accessRule;
            }
        } else {
            $accessMethod = $accessRule[0];

            if (preg_match('/::/', $accessMethod)) {
                return $accessMethod;
            } else {
                return $this->controller . '::' . $accessMethod;
            }
        }
    }

    /**
     * Checks if the specified action's rule is an array.
     *
     * @param string $action The action to check.
     *
     * @return bool True if the rule is an array; otherwise, false.
     */
    public function isArray($action)
    {
        $rule = isset($this->rules[$action]['allow']) ? $this->rules[$action]['allow'] : $this->rules[$action]['deny'];
        return (getType($rule) === 'array');
    }
}
