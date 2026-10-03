<?php
namespace Jambura\Mvc;

/**
 * Serves an application's actions as MCP tools.
 *
 * One endpoint speaks the Model Context Protocol, revision 2026-07-28, over
 * Streamable HTTP. Tools are registered where the application boots, each one
 * naming a controller action and the RequestValidator spec that already
 * describes it - so a tool's inputSchema is the validation rules, not a second
 * declaration that can drift from them:
 *
 *     Mcp::describe(['name' => 'ag-ai', 'version' => '1.0']);
 *     Mcp::tool('books.create', 'Add a book to the catalogue', 'books', 'create', BookRequests::create());
 *
 *     // in the controller the endpoint routes to
 *     Mcp::serve($this->request());
 *
 * Registration is per action and deliberate. Enabling the endpoint publishes
 * nothing: an action is a tool only when it is named here, because the
 * alternative is handing a model every destructive action an application has.
 *
 * What this implements: `tools/list`, `tools/call` and `ping`, answered as a
 * single JSON object per POST. What it does not: SSE streaming, subscriptions
 * and list-changed notifications, multi round-trip input requests, pagination,
 * resources, prompts, elicitation, the stdio transport, and the pre-2026
 * `initialize` handshake. A client asking for an older protocol version is told
 * which versions this server speaks rather than being guessed at.
 *
 * The protocol is stateless: every request carries its own protocol version and
 * client capabilities in `_meta`, and nothing is remembered between requests.
 */
class Mcp
{
    /**
     * The protocol revision this server implements.
     */
    const PROTOCOL_VERSION = '2026-07-28';

    /**
     * Every revision this server will answer.
     */
    const SUPPORTED_VERSIONS = [self::PROTOCOL_VERSION];

    /**
     * Reserved `_meta` keys this server reads and writes.
     */
    const META_PROTOCOL_VERSION = 'io.modelcontextprotocol/protocolVersion';
    const META_CLIENT_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';
    const META_SERVER_INFO = 'io.modelcontextprotocol/serverInfo';

    /**
     * Error codes. The first three are JSON-RPC's; the -320xx pair is MCP's.
     */
    const PARSE_ERROR = -32700;
    const INVALID_REQUEST = -32600;
    const METHOD_NOT_FOUND = -32601;
    const INVALID_PARAMS = -32602;
    const HEADER_MISMATCH = -32020;
    const UNSUPPORTED_PROTOCOL_VERSION = -32022;

    /**
     * Registered tools, keyed by tool name, in registration order.
     * @var array<string, array{description: string, title: string|null, controller: string, action: string, spec: RequestValidator}>
     */
    private static array $tools = [];

    /**
     * What this server calls itself, sent back in every result's `_meta`.
     * @var array<string, string>
     */
    private static array $serverInfo = ['name' => 'jambura', 'version' => '1.0'];

    /**
     * Origins allowed to reach the endpoint, or null to accept any.
     * @var string[]|null
     */
    private static ?array $origins = null;

    /**
     * How a tool call reaches an action. Null means the built-in dispatcher.
     * @var callable|null
     */
    private static $dispatcher = null;

    /**
     * Names this server, and optionally the origins it will answer.
     *
     * The origin allowlist exists to stop a web page in a browser from reaching
     * a local MCP endpoint through DNS rebinding. Leave it null for a server
     * that is only reachable server-side.
     *
     * @param array<string, string> $serverInfo 'name' and 'version'
     * @param string[]|null         $allowedOrigins exact Origin header values
     */
    public static function describe(array $serverInfo, ?array $allowedOrigins = null): void
    {
        self::$serverInfo = $serverInfo + self::$serverInfo;
        self::$origins = $allowedOrigins;
    }

    /**
     * Publishes one action as a tool.
     *
     * The description is what a model reads when deciding whether to call it, so
     * it is required and cannot be generated: write what the tool does and when
     * to use it. The spec supplies the tool's inputSchema, the method a call is
     * made with, and the roles a caller needs.
     *
     * @param string           $name        the tool name, as a model will call it,
     *                                      from letters, digits, '_', '-' and '.'
     * @param string           $description what the tool does
     * @param string           $controller  the controller holding the action
     * @param string           $action      the action name, without 'action_'
     * @param RequestValidator $spec        the spec that describes the request
     * @param string|null      $title       a human-readable name for a UI
     *
     * @throws \InvalidArgumentException if the name is taken or not a legal tool name
     */
    public static function tool(
        string $name,
        string $description,
        string $controller,
        string $action,
        RequestValidator $spec,
        ?string $title = null
    ): void {
        if (isset(self::$tools[$name])) {
            throw new \InvalidArgumentException("MCP tool '$name' is already registered");
        }
        if (!preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $name)) {
            throw new \InvalidArgumentException(
                "MCP tool name '$name' is not legal: use 1 to 128 of letters, digits, '_', '-' and '.'"
            );
        }

        self::$tools[$name] = [
            'description' => $description,
            'title' => $title,
            'controller' => $controller,
            'action' => $action,
            'spec' => $spec,
        ];
    }

    /**
     * The registered tools, keyed by name, in registration order.
     *
     * @return array<string, array>
     */
    public static function registeredTools(): array
    {
        return self::$tools;
    }

    /**
     * Forgets every tool and resets the server description. Meant for tests.
     */
    public static function forgetTools(): void
    {
        self::$tools = [];
        self::$origins = null;
        self::$dispatcher = null;
        self::$serverInfo = ['name' => 'jambura', 'version' => '1.0'];
    }

    /**
     * Replaces how a tool call reaches an action.
     *
     * The dispatcher is called with the tool's registration and the Request
     * built for the call, and returns ['status' => int, 'response' => array].
     * The built-in one includes the controller file and runs the action with
     * Rest's capture mode on; a test passes its own to assert what a tool call
     * would do without a controller directory.
     *
     * @param callable|null $dispatcher function (array $tool, Request $request): array
     */
    public static function dispatchUsing(?callable $dispatcher): void
    {
        self::$dispatcher = $dispatcher;
    }

    /**
     * Answers one MCP request, without sending anything.
     *
     * Returned rather than sent so the whole protocol is testable: serve() is
     * the half that touches headers and output.
     *
     * @param Request $request the request to answer
     * @return array{status: int, headers: array<string, string>, body: array|null}
     */
    public static function respond(Request $request): array
    {
        // This revision has no GET stream and no session to delete.
        if (!$request->isMethod('post')) {
            return self::http(405, null, ['Allow' => 'POST']);
        }

        $origin = $request->header('Origin');
        if ($origin !== null && self::$origins !== null && !in_array($origin, self::$origins, true)) {
            return self::http(403, self::errorBody(null, self::INVALID_REQUEST, 'Origin not allowed'));
        }

        if ($request->jsonError() !== null) {
            return self::http(400, self::errorBody(null, self::PARSE_ERROR, 'Parse error: ' . $request->jsonError()));
        }

        $envelope = $request->input();
        $id = $envelope['id'] ?? null;
        $method = $envelope['method'] ?? null;
        if (($envelope['jsonrpc'] ?? null) !== '2.0' || !is_string($method)) {
            return self::http(400, self::errorBody($id, self::INVALID_REQUEST, 'Not a JSON-RPC 2.0 request'));
        }

        // A notification carries no id and gets no body back.
        if ($id === null) {
            return self::http(202, null);
        }

        $params = is_array($envelope['params'] ?? null) ? $envelope['params'] : [];
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];

        if ($failure = self::checkHeaders($request, $method, $params, $meta, $id)) {
            return $failure;
        }
        if ($failure = self::checkMeta($meta, $id)) {
            return $failure;
        }

        switch ($method) {
            case 'ping':
                return self::http(200, self::resultBody($id, []));
            case 'tools/list':
                return self::http(200, self::resultBody($id, ['tools' => self::toolList($request)]));
            case 'tools/call':
                return self::call($request, $params, $id);
            default:
                return self::http(404, self::errorBody($id, self::METHOD_NOT_FOUND, "Method not found: $method"));
        }
    }

    /**
     * Answers one MCP request and sends it. Never returns.
     *
     * @param Request $request the request to answer
     */
    public static function serve(Request $request): void
    {
        $answer = self::respond($request);

        if (!headers_sent()) {
            http_response_code($answer['status']);
            foreach ($answer['headers'] as $name => $value) {
                header("$name: $value");
            }
            if ($answer['body'] !== null) {
                header('Content-Type: application/json');
            }
        }
        if ($answer['body'] !== null) {
            echo json_encode($answer['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    /**
     * Checks the headers this transport mirrors from the body.
     *
     * The transport copies the method, the tool name and the protocol version
     * into headers so proxies can route without reading the body. A server has
     * to check they agree with the body, or a proxy and this server could be
     * acting on different values.
     *
     * @return array|null the failure to return, or null when the headers agree
     */
    private static function checkHeaders(
        Request $request,
        string $method,
        array $params,
        array $meta,
        mixed $id
    ): ?array {
        $version = $request->header('MCP-Protocol-Version');
        if ($version === null) {
            return self::http(400, self::errorBody($id, self::HEADER_MISMATCH, 'Missing MCP-Protocol-Version header'));
        }
        if (isset($meta[self::META_PROTOCOL_VERSION]) && $meta[self::META_PROTOCOL_VERSION] !== $version) {
            return self::http(400, self::errorBody(
                $id,
                self::HEADER_MISMATCH,
                'Header mismatch: MCP-Protocol-Version does not match the protocol version in _meta'
            ));
        }
        if (!in_array($version, self::SUPPORTED_VERSIONS, true)) {
            return self::http(400, self::errorBody(
                $id,
                self::UNSUPPORTED_PROTOCOL_VERSION,
                "Unsupported protocol version: $version",
                ['supported' => self::SUPPORTED_VERSIONS]
            ));
        }

        $headerMethod = $request->header('Mcp-Method');
        if ($headerMethod === null || $headerMethod !== $method) {
            return self::http(400, self::errorBody(
                $id,
                self::HEADER_MISMATCH,
                'Header mismatch: Mcp-Method does not match the method in the body'
            ));
        }

        if ($method === 'tools/call') {
            $name = $params['name'] ?? null;
            $headerName = self::decodeHeaderValue($request->header('Mcp-Name'));
            if ($headerName === null || $headerName !== $name) {
                return self::http(400, self::errorBody(
                    $id,
                    self::HEADER_MISMATCH,
                    'Header mismatch: Mcp-Name does not match the tool name in the body'
                ));
            }
        }
        return null;
    }

    /**
     * Checks the `_meta` fields every request must carry.
     *
     * There is no handshake in this revision, so a request that leaves these
     * out has told the server nothing about what it speaks.
     *
     * @return array|null the failure to return, or null when the fields are there
     */
    private static function checkMeta(array $meta, mixed $id): ?array
    {
        if (!is_string($meta[self::META_PROTOCOL_VERSION] ?? null)) {
            return self::http(400, self::errorBody(
                $id,
                self::INVALID_PARAMS,
                'Missing _meta field: ' . self::META_PROTOCOL_VERSION
            ));
        }
        if (!array_key_exists(self::META_CLIENT_CAPABILITIES, $meta)) {
            return self::http(400, self::errorBody(
                $id,
                self::INVALID_PARAMS,
                'Missing _meta field: ' . self::META_CLIENT_CAPABILITIES
            ));
        }
        return null;
    }

    /**
     * The tools this caller may see, in registration order.
     *
     * The set may vary by the authorization on the request - credentials are
     * per-request input, not connection state - so a caller is never shown a
     * tool their roles would refuse.
     *
     * @return array<int, array>
     */
    private static function toolList(Request $request): array
    {
        $tools = [];
        foreach (self::$tools as $name => $tool) {
            if (!self::permits($tool['spec'], $request)) {
                continue;
            }
            $entry = ['name' => $name, 'description' => $tool['description']];
            if ($tool['title'] !== null) {
                $entry['title'] = $tool['title'];
            }
            $entry['inputSchema'] = $tool['spec']->jsonSchema();
            $tools[] = $entry;
        }
        return $tools;
    }

    /**
     * Runs a tool and turns what the action returned into a tool result.
     *
     * A failure inside the action is a tool error rather than a protocol error:
     * `isError` with the messages, which a model can read and correct. Only an
     * unknown tool is a protocol error, since no retry will fix it.
     */
    private static function call(Request $request, array $params, mixed $id): array
    {
        $name = $params['name'] ?? null;
        $tool = is_string($name) ? (self::$tools[$name] ?? null) : null;

        // An unknown name and a name this caller may not use answer the same
        // way: a tool they cannot see should not be confirmed to exist.
        if ($tool === null || !self::permits($tool['spec'], $request)) {
            return self::http(400, self::errorBody($id, self::INVALID_PARAMS, "Unknown tool: $name"));
        }

        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $methods = $tool['spec']->requiredMethods();
        $inner = new Request(
            method: $methods[0] ?? 'POST',
            body: $arguments,
            headers: array_filter(['Authorization' => $request->header('Authorization')]),
            server: ['CONTENT_TYPE' => 'application/json']
        );

        try {
            $answer = self::$dispatcher === null
                ? self::dispatch($tool, $inner)
                : (self::$dispatcher)($tool, $inner);
        } catch (\jamexRequestInvalid $e) {
            return self::http(200, self::resultBody($id, self::toolError($e->getMessage(), $e->fields())));
        } catch (\Exception $e) {
            return self::http(200, self::resultBody($id, self::toolError($e->getMessage())));
        }

        $status = $answer['status'] ?? 200;
        $response = is_array($answer['response'] ?? null) ? $answer['response'] : [];

        if ($status >= 400) {
            return self::http(200, self::resultBody($id, self::toolError(
                $response['error'] ?? "The call failed with status $status",
                is_array($response['fields'] ?? null) ? $response['fields'] : []
            )));
        }

        return self::http(200, self::resultBody($id, [
            'content' => [['type' => 'text', 'text' => self::encode($response)]],
            'structuredContent' => $response,
            'isError' => false,
        ]));
    }

    /**
     * Runs the action behind a tool, with Rest's capture mode on.
     *
     * Includes the controller file the way Router does, builds the controller
     * with the call's Request in place of the real one, runs the action, and
     * reads the response the controller would have sent.
     *
     * @return array{status: int, response: array}
     */
    private static function dispatch(array $tool, Request $inner): array
    {
        $file = JAMBURA_CONTROLLERS . $tool['controller'] . '.php';
        if (!file_exists($file)) {
            throw new \jamexBadController('Invalid controller file : ' . $tool['controller']);
        }
        include_once $file;

        $class = 'Controller_' . $tool['controller'];
        $action = 'action_' . $tool['action'];
        if (!is_subclass_of($class, Rest::class)) {
            throw new \InvalidArgumentException("$class must extend " . Rest::class . ' to be served as a tool');
        }
        if (!method_exists($class, $action)) {
            throw new \jamexBadAction("Action: $action does not exist");
        }

        Rest::captureNext($inner);
        $controller = new $class();
        try {
            $controller->$action();
            $controller->end();
        } catch (\jamexResponseReady $ready) {
            // sendError() in capture mode: the response is already on the controller.
        }
        return $controller->capturedResponse();
    }

    /**
     * Whether this caller holds the roles a spec requires.
     */
    private static function permits(RequestValidator $spec, Request $request): bool
    {
        $wanted = $spec->requiredRoles();
        if (!$wanted) {
            return true;
        }
        $held = $request->roles();
        return $held !== null && array_intersect($wanted, $held) !== [];
    }

    /**
     * A tool result that reports a failure the model can act on.
     *
     * @param array<string, string[]> $fields per-field messages from a schema
     */
    private static function toolError(string $message, array $fields = []): array
    {
        foreach ($fields as $field => $messages) {
            $message .= "\n- $field: " . implode('; ', (array) $messages);
        }
        return [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
        ];
    }

    /**
     * Wraps a result, with the resultType every result must carry and the
     * server's name for a client that wants to log who answered.
     */
    private static function resultBody(mixed $id, array $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => ['resultType' => 'complete']
                + $result
                + ['_meta' => [self::META_SERVER_INFO => self::$serverInfo]],
        ];
    }

    /**
     * Wraps an error.
     */
    private static function errorBody(mixed $id, int $code, string $message, array $data = []): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data) {
            $error['data'] = $data;
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }

    /**
     * @param array|null $body
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: array|null}
     */
    private static function http(int $status, ?array $body, array $headers = []): array
    {
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    /**
     * Decodes a header value the transport may have Base64-encoded.
     *
     * A tool name that is not plain ASCII arrives as `=?base64?...?=`, and has
     * to be decoded before it can be compared with the body.
     */
    private static function decodeHeaderValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('/^=\?base64\?(.*)\?=$/', $value, $found) !== 1) {
            return $value;
        }
        $decoded = base64_decode($found[1], true);
        return $decoded === false ? null : $decoded;
    }

    /**
     * JSON for a tool result's text block.
     */
    private static function encode(array $response): string
    {
        $json = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? '{}' : $json;
    }
}
