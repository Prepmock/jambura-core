<?php
namespace Jambura;
class Acl
{
    private $rules;
    public static function init()
    {
        return new self();
    }

    // Set the rules as defined in the Controller
    public function setRules($rules)
    {
        $this->rules = $rules;

        return $this; 
    }

    // Checks whether the action has access to be viewed
    public function checkAccess($action)
    {
        $accessRules = $this->rules;
        $accessMethod = '';

        // Get the universal access method applicable for any action that has been defined in the controller
        if (array_key_exists('*', $accessRules)) {
            $accessMethod = $accessRules['*'][0] ?? '';
            $params = $accessRules['*']['params'] ?? '';
            $message = $accessRules['*']['message'] ?? 'Access Denied';

        }

        // Get the specific access method applicable for a certain action defined in the controller
        if (array_key_exists($action, $accessRules)) {
            $accessMethod = $accessRules[$action][0] ?? '';
            $params = $accessRules[$action]['params'] ?? '';
            $message = $accessRules[$action]['message'] ?? 'Access Denied';

        }

        // Validates access rules based on types of the access method        
        if (
            (
                gettype($accessMethod) === 'string' &&
                $accessMethod != '' &&
                !$accessMethod($params)
            ) ||
            (
                gettype($accessMethod) === 'object' &&
                $accessMethod != '' &&
                !$accessMethod->{$accessRules[$action][1]}($params)
            )
        ) {
            throw new jamexBadMethod($message, 401);
        }

    }
}
?>