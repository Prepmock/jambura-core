<?php

use Jambura\Mvc\Mcp;
use Jambura\Mvc\Request;
use Jambura\Mvc\RequestValidator;
use PHPUnit\Framework\TestCase;

class McpTest extends TestCase
{
    protected function setUp(): void
    {
        Mcp::forgetTools();
        RequestValidator::forgetRolesResolver();
    }

    protected function tearDown(): void
    {
        Mcp::forgetTools();
        RequestValidator::forgetRolesResolver();
    }

    // --- transport ------------------------------------------------------

    public function testOnlyPostIsAllowed(): void
    {
        $answer = Mcp::respond(new Request(method: 'GET'));

        $this->assertSame(405, $answer['status']);
        $this->assertSame(['Allow' => 'POST'], $answer['headers']);
        $this->assertNull($answer['body']);
    }

    public function testAnOriginOutsideTheAllowListIsRefused(): void
    {
        Mcp::describe(['name' => 'test', 'version' => '1'], ['https://allowed.example']);

        $refused = Mcp::respond($this->request('ping', [], ['Origin' => 'https://evil.example']));
        $allowed = Mcp::respond($this->request('ping', [], ['Origin' => 'https://allowed.example']));

        $this->assertSame(403, $refused['status']);
        $this->assertSame('Origin not allowed', $refused['body']['error']['message']);
        $this->assertSame(200, $allowed['status']);
    }

    public function testABodyThatWouldNotDecodeIsAParseError(): void
    {
        $answer = Mcp::respond(new Request(
            method: 'POST',
            headers: ['Content-Type' => 'application/json'],
            rawBody: '{oops',
            jsonError: 'Syntax error'
        ));

        $this->assertSame(400, $answer['status']);
        $this->assertSame(Mcp::PARSE_ERROR, $answer['body']['error']['code']);
    }

    public function testSomethingThatIsNotJsonRpcIsRefused(): void
    {
        $answer = Mcp::respond($this->request('ping', [], [], ['jsonrpc' => '1.0']));

        $this->assertSame(400, $answer['status']);
        $this->assertSame(Mcp::INVALID_REQUEST, $answer['body']['error']['code']);
    }

    public function testANotificationIsAcceptedWithNoBody(): void
    {
        $answer = Mcp::respond($this->request('ping', [], [], ['id' => null]));

        $this->assertSame(202, $answer['status']);
        $this->assertNull($answer['body']);
    }

    // --- mirrored headers ----------------------------------------------

    public function testTheProtocolVersionHeaderIsRequired(): void
    {
        $answer = Mcp::respond($this->request('ping', [], ['MCP-Protocol-Version' => null]));

        $this->assertSame(400, $answer['status']);
        $this->assertSame(Mcp::HEADER_MISMATCH, $answer['body']['error']['code']);
        $this->assertStringContainsString('Missing MCP-Protocol-Version', $answer['body']['error']['message']);
    }

    public function testTheProtocolVersionHeaderMustMatchTheBody(): void
    {
        $answer = Mcp::respond($this->request('ping', [], ['MCP-Protocol-Version' => '2025-11-25']));

        $this->assertSame(400, $answer['status']);
        $this->assertSame(Mcp::HEADER_MISMATCH, $answer['body']['error']['code']);
    }

    public function testAnUnsupportedProtocolVersionSaysWhatIsSupported(): void
    {
        $answer = Mcp::respond($this->request(
            'ping',
            [],
            ['MCP-Protocol-Version' => '2025-11-25'],
            [],
            '2025-11-25'
        ));

        $this->assertSame(400, $answer['status']);
        $this->assertSame(Mcp::UNSUPPORTED_PROTOCOL_VERSION, $answer['body']['error']['code']);
        $this->assertSame(
            ['supported' => [Mcp::PROTOCOL_VERSION], 'requested' => '2025-11-25'],
            $answer['body']['error']['data']
        );
    }

    public function testTheMethodHeaderMustMatchTheBody(): void
    {
        $answer = Mcp::respond($this->request('ping', [], ['Mcp-Method' => 'tools/list']));

        $this->assertSame(400, $answer['status']);
        $this->assertSame(Mcp::HEADER_MISMATCH, $answer['body']['error']['code']);
    }

    public function testTheToolNameHeaderMustMatchTheBody(): void
    {
        $this->registerTool();

        $answer = Mcp::respond($this->request(
            'tools/call',
            ['name' => 'books.create', 'arguments' => []],
            ['Mcp-Name' => 'books.delete']
        ));

        $this->assertSame(400, $answer['status']);
        $this->assertSame(Mcp::HEADER_MISMATCH, $answer['body']['error']['code']);
    }

    public function testABase64EncodedToolNameHeaderIsDecodedBeforeComparing(): void
    {
        $this->registerTool();
        $encoded = '=?base64?' . base64_encode('books.create') . '?=';

        $answer = Mcp::respond($this->request(
            'tools/call',
            ['name' => 'books.create', 'arguments' => ['title' => 'Dune']],
            ['Mcp-Name' => $encoded]
        ));

        $this->assertSame(200, $answer['status']);
        $this->assertFalse($answer['body']['result']['isError']);
    }

    // --- _meta ----------------------------------------------------------

    public function testTheProtocolVersionInMetaIsRequired(): void
    {
        $answer = Mcp::respond($this->request('ping', ['_meta' => []]));

        $this->assertSame(400, $answer['status']);
        $this->assertSame(Mcp::INVALID_PARAMS, $answer['body']['error']['code']);
        $this->assertStringContainsString('protocolVersion', $answer['body']['error']['message']);
    }

    public function testTheClientCapabilitiesInMetaAreRequired(): void
    {
        $answer = Mcp::respond($this->request('ping', [
            '_meta' => [Mcp::META_PROTOCOL_VERSION => Mcp::PROTOCOL_VERSION],
        ]));

        $this->assertSame(400, $answer['status']);
        $this->assertSame(Mcp::INVALID_PARAMS, $answer['body']['error']['code']);
        $this->assertStringContainsString('clientCapabilities', $answer['body']['error']['message']);
    }

    // --- methods --------------------------------------------------------

    public function testAnUnknownMethodIs404(): void
    {
        $answer = Mcp::respond($this->request('resources/list'));

        $this->assertSame(404, $answer['status']);
        $this->assertSame(Mcp::METHOD_NOT_FOUND, $answer['body']['error']['code']);
    }

    public function testDiscoverReportsVersionsCapabilitiesAndIdentity(): void
    {
        Mcp::describe([
            'name' => 'ag-ai',
            'version' => '1.0',
            'instructions' => 'Ask about indexed documents.',
        ]);

        $answer = Mcp::respond($this->request('server/discover'));
        $result = $answer['body']['result'];

        $this->assertSame(200, $answer['status']);
        $this->assertSame('complete', $result['resultType']);
        $this->assertSame([Mcp::PROTOCOL_VERSION], $result['supportedVersions']);
        $this->assertSame('Ask about indexed documents.', $result['instructions']);
        $this->assertSame(['name' => 'ag-ai', 'version' => '1.0'], $result['_meta'][Mcp::META_SERVER_INFO]);

        // capabilities must encode as objects, not as empty arrays
        $this->assertStringContainsString(
            '"capabilities":{"tools":{}}',
            json_encode($answer['body'])
        );
    }

    public function testDiscoverSaysNothingAboutInstructionsWhenNoneWereGiven(): void
    {
        $this->assertArrayNotHasKey(
            'instructions',
            Mcp::respond($this->request('server/discover'))['body']['result']
        );
    }

    public function testPingAnswersWithTheServerName(): void
    {
        Mcp::describe(['name' => 'ag-ai', 'version' => '1.0']);

        $answer = Mcp::respond($this->request('ping'));

        $this->assertSame(200, $answer['status']);
        $this->assertSame('complete', $answer['body']['result']['resultType']);
        $this->assertSame(
            ['name' => 'ag-ai', 'version' => '1.0'],
            $answer['body']['result']['_meta'][Mcp::META_SERVER_INFO]
        );
    }

    public function testToolsListDescribesEachToolFromItsSpec(): void
    {
        $this->registerTool();

        $answer = Mcp::respond($this->request('tools/list'));
        $tools = $answer['body']['result']['tools'];

        $this->assertCount(1, $tools);
        $this->assertSame('books.create', $tools[0]['name']);
        $this->assertSame('Add a book to the catalogue', $tools[0]['description']);
        $this->assertSame('Create book', $tools[0]['title']);
        $this->assertSame([
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string'], 'copies' => ['type' => 'integer']],
            'additionalProperties' => false,
            'required' => ['title'],
        ], $tools[0]['inputSchema']);
    }

    public function testToolsListLeavesOutWhatTheCallersRolesWouldRefuse(): void
    {
        RequestValidator::resolveRolesUsing(fn () => ['reader']);
        Mcp::tool('books.read', 'Read a book', 'books', 'read', (new RequestValidator())->roles(['reader']));
        Mcp::tool(
            'books.destroy',
            'Delete a book',
            'books',
            'destroy',
            (new RequestValidator())->roles(['admin'])
        );

        $tools = Mcp::respond($this->request('tools/list'))['body']['result']['tools'];

        $this->assertSame(['books.read'], array_column($tools, 'name'));
    }

    public function testToolsListKeepsRegistrationOrder(): void
    {
        Mcp::tool('b.one', 'One', 'b', 'one', new RequestValidator());
        Mcp::tool('a.two', 'Two', 'a', 'two', new RequestValidator());

        $tools = Mcp::respond($this->request('tools/list'))['body']['result']['tools'];

        $this->assertSame(['b.one', 'a.two'], array_column($tools, 'name'));
    }

    // --- calling --------------------------------------------------------

    public function testACallRunsTheActionAndReturnsItsResponse(): void
    {
        $seen = [];
        $this->registerTool();
        Mcp::dispatchUsing(function (array $tool, Request $request) use (&$seen) {
            $seen = ['tool' => $tool, 'request' => $request];
            return ['status' => 201, 'response' => ['id' => 7]];
        });

        $answer = Mcp::respond($this->request(
            'tools/call',
            ['name' => 'books.create', 'arguments' => ['title' => 'Dune', 'copies' => 2]],
            ['Authorization' => 'Bearer abc.123']
        ));
        $result = $answer['body']['result'];

        $this->assertSame(200, $answer['status']);
        $this->assertSame('complete', $result['resultType']);
        $this->assertFalse($result['isError']);
        $this->assertSame(['id' => 7], $result['structuredContent']);
        $this->assertSame([['type' => 'text', 'text' => '{"id":7}']], $result['content']);

        // the action was handed a request built from the tool call
        $this->assertSame('books', $seen['tool']['controller']);
        $this->assertSame('create', $seen['tool']['action']);
        $this->assertSame('POST', $seen['request']->method());
        $this->assertSame(['title' => 'Dune', 'copies' => 2], $seen['request']->input());
        $this->assertSame('abc.123', $seen['request']->bearerToken());
    }

    public function testAnUnknownToolIsAProtocolError(): void
    {
        $this->registerTool();

        $answer = Mcp::respond($this->request(
            'tools/call',
            ['name' => 'books.nope', 'arguments' => []],
            ['Mcp-Name' => 'books.nope']
        ));

        $this->assertSame(400, $answer['status']);
        $this->assertSame(Mcp::INVALID_PARAMS, $answer['body']['error']['code']);
        $this->assertStringContainsString('Unknown tool: books.nope', $answer['body']['error']['message']);
    }

    public function testAToolTheCallerMayNotUseIsNotConfirmedToExist(): void
    {
        RequestValidator::resolveRolesUsing(fn () => ['reader']);
        Mcp::tool('books.destroy', 'Delete a book', 'books', 'destroy', (new RequestValidator())->roles(['admin']));

        $answer = Mcp::respond($this->request(
            'tools/call',
            ['name' => 'books.destroy', 'arguments' => []],
            ['Mcp-Name' => 'books.destroy']
        ));

        $this->assertSame(Mcp::INVALID_PARAMS, $answer['body']['error']['code']);
        $this->assertStringContainsString('Unknown tool', $answer['body']['error']['message']);
    }

    public function testAValidationFailureComesBackAsAToolError(): void
    {
        $this->registerTool();
        Mcp::dispatchUsing(function () {
            throw new jamexRequestInvalid('Validation failed', 422, ['title' => ['title is required']]);
        });

        $result = Mcp::respond($this->request(
            'tools/call',
            ['name' => 'books.create', 'arguments' => []]
        ))['body']['result'];

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('Validation failed', $result['content'][0]['text']);
        $this->assertStringContainsString('title: title is required', $result['content'][0]['text']);
    }

    public function testAnErrorStatusFromTheActionComesBackAsAToolError(): void
    {
        $this->registerTool();
        Mcp::dispatchUsing(fn () => ['status' => 429, 'response' => ['error' => 'Monthly quota used up']]);

        $result = Mcp::respond($this->request(
            'tools/call',
            ['name' => 'books.create', 'arguments' => []]
        ))['body']['result'];

        $this->assertTrue($result['isError']);
        $this->assertSame('Monthly quota used up', $result['content'][0]['text']);
    }

    public function testACallUsesTheMethodTheSpecRequires(): void
    {
        $method = null;
        Mcp::tool('books.replace', 'Replace a book', 'books', 'replace', (new RequestValidator())->method('put'));
        Mcp::dispatchUsing(function (array $tool, Request $request) use (&$method) {
            $method = $request->method();
            return ['status' => 200, 'response' => []];
        });

        Mcp::respond($this->request(
            'tools/call',
            ['name' => 'books.replace', 'arguments' => []],
            ['Mcp-Name' => 'books.replace']
        ));

        $this->assertSame('PUT', $method);
    }

    // --- registration ---------------------------------------------------

    public function testATooNameCannotBeRegisteredTwice(): void
    {
        $this->registerTool();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("MCP tool 'books.create' is already registered");
        $this->registerTool();
    }

    public function testAnIllegalToolNameIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("is not legal");
        Mcp::tool('books/create', 'Add a book', 'books', 'create', new RequestValidator());
    }

    // --- helpers --------------------------------------------------------

    private function registerTool(): void
    {
        Mcp::tool(
            'books.create',
            'Add a book to the catalogue',
            'books',
            'create',
            (new RequestValidator())->method('post')->schema([
                'title' => 'required',
                'copies' => ['int'],
            ]),
            'Create book'
        );
        Mcp::dispatchUsing(fn (array $tool, Request $request) => ['status' => 200, 'response' => ['ok' => true]]);
    }

    /**
     * An MCP request, with the headers and _meta the transport requires.
     */
    private function request(
        string $method,
        array $params = [],
        array $headers = [],
        array $envelope = [],
        string $metaVersion = Mcp::PROTOCOL_VERSION
    ): Request {
        if (!array_key_exists('_meta', $params)) {
            $params['_meta'] = [
                Mcp::META_PROTOCOL_VERSION => $metaVersion,
                Mcp::META_CLIENT_CAPABILITIES => [],
            ];
        }

        $body = ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params];
        foreach ($envelope as $key => $value) {
            if ($value === null && $key === 'id') {
                unset($body['id']);
                continue;
            }
            $body[$key] = $value;
        }

        $sent = [
            'Content-Type' => 'application/json',
            'MCP-Protocol-Version' => Mcp::PROTOCOL_VERSION,
            'Mcp-Method' => $method,
        ];
        if ($method === 'tools/call' && isset($params['name'])) {
            $sent['Mcp-Name'] = $params['name'];
        }
        foreach ($headers as $name => $value) {
            if ($value === null) {
                unset($sent[$name]);
                continue;
            }
            $sent[$name] = $value;
        }

        return new Request(method: 'POST', body: $body, headers: $sent);
    }
}
