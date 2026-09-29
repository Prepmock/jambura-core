<?php
class jamex extends Exception {};
class jamexPageNotFound extends jamex {};
class jamexBadController extends jamexPageNotFound {};
class jamexBadAction extends jamexPageNotFound {};

/**
 * A request that failed validation, thrown where there is no JSON response to
 * send. A Rest controller answers with the status instead of throwing.
 */
class jamexRequestInvalid extends jamex
{
    /**
     * @var array<string, string[]> field name => messages, empty unless a schema failed
     */
    private $fields;

    /**
     * @param string $message  what was wrong
     * @param int    $status   the HTTP status a REST controller would have sent
     * @param array  $fields   field name => messages
     */
    public function __construct($message, $status = 422, array $fields = [])
    {
        parent::__construct($message, $status);
        $this->fields = $fields;
    }

    /**
     * The HTTP status this failure maps to.
     */
    public function status()
    {
        return $this->getCode();
    }

    /**
     * Per-field messages from a failed schema, empty for other failures.
     *
     * @return array<string, string[]>
     */
    public function fields()
    {
        return $this->fields;
    }
}
