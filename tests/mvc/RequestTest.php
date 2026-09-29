<?php

use Jambura\Mvc\Request;
use Jambura\Mvc\RequestValidator;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase
{
    public function testReportsTheMethod(): void
    {
        $request = new Request(['method' => 'POST']);

        $this->assertSame('POST', $request->method());
        $this->assertTrue($request->isMethod('post'));
        $this->assertTrue($request->isMethod('GET', 'post'));
        $this->assertFalse($request->isMethod('get'));
    }

    public function testFindsHeadersWhateverTheCasing(): void
    {
        $request = new Request(['headers' => ['Content-Type' => 'application/json', 'X-Trace' => 'abc']]);

        $this->assertSame('abc', $request->header('x-trace'));
        $this->assertSame('abc', $request->header('X-TRACE'));
        $this->assertNull($request->header('X-Missing'));
        $this->assertSame(['Content-Type' => 'application/json', 'X-Trace' => 'abc'], $request->headers());
    }

    public function testReportsTheBodysMediaType(): void
    {
        $json = new Request(['headers' => ['Content-Type' => 'application/json; charset=utf-8']]);
        $form = new Request(['headers' => ['content-type' => 'application/x-www-form-urlencoded']]);
        $api = new Request(['headers' => ['Content-Type' => 'application/vnd.books+json']]);

        $this->assertSame('application/json', $json->mime());
        $this->assertTrue($json->isJson());
        $this->assertSame('application/x-www-form-urlencoded', $form->mime());
        $this->assertFalse($form->isJson());
        $this->assertTrue($api->isJson());
        $this->assertNull((new Request())->mime());
    }

    public function testReadsTheAcceptHeaderBestFirst(): void
    {
        $request = new Request(['headers' => ['Accept' => 'application/json;q=0.9, text/html, */*']]);

        $this->assertSame(['application/json', 'text/html', '*/*'], $request->accepts());
        $this->assertSame([], (new Request())->accepts());
    }

    public function testPullsTheBearerToken(): void
    {
        $this->assertSame(
            'abc.123',
            (new Request(['headers' => ['Authorization' => 'Bearer abc.123']]))->bearerToken()
        );
        $this->assertNull((new Request(['headers' => ['Authorization' => 'Basic abc']]))->bearerToken());
        $this->assertNull((new Request())->bearerToken());
    }

    public function testReadsTheQueryStringAndTheBody(): void
    {
        $request = new Request([
            'method' => 'POST',
            'query' => ['page' => '2', 'shelf' => 'fiction'],
            'body' => ['title' => 'Dune', 'shelf' => 'reference']
        ]);

        $this->assertSame('2', $request->query('page'));
        $this->assertSame(1, $request->query('missing', 1));
        $this->assertSame(['page' => '2', 'shelf' => 'fiction'], $request->query());
        $this->assertSame('Dune', $request->input('title'));
        $this->assertFalse($request->input('missing', false));
        $this->assertSame(['title' => 'Dune', 'shelf' => 'reference'], $request->input());
    }

    public function testAllMergesTheQueryAndTheBodyWithTheBodyWinning(): void
    {
        $request = new Request(['query' => ['shelf' => 'fiction', 'page' => '2'], 'body' => ['shelf' => 'reference']]);

        $this->assertSame(['shelf' => 'reference', 'page' => '2'], $request->all());
        $this->assertSame(['shelf' => 'reference'], $request->only(['shelf', 'absent']));
        $this->assertTrue($request->has('page'));
        $this->assertFalse($request->has('absent'));
    }

    public function testReportsTheRestOfTheRequest(): void
    {
        $request = new Request([
            'files' => ['scan' => ['name' => 'receipt.pdf']],
            'server' => ['REMOTE_ADDR' => '203.0.113.4', 'REQUEST_URI' => '/books/show?id=7'],
            'rawBody' => '{"id":7}'
        ]);

        $this->assertSame(['scan' => ['name' => 'receipt.pdf']], $request->files());
        $this->assertSame('203.0.113.4', $request->ip());
        $this->assertSame('books/show', $request->path());
        $this->assertSame('{"id":7}', $request->rawBody());
        $this->assertNull($request->jsonError());
        $this->assertNull($request->controller());
    }

    public function testFromGlobalsReadsTheServerAndQueryString(): void
    {
        $server = $_SERVER;
        $get = $_GET;
        $_SERVER = [
            'REQUEST_METHOD' => 'get',
            'REQUEST_URI' => '/books?page=2',
            'HTTP_X_TRACE' => 'abc',
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '198.51.100.7',
        ];
        $_GET = ['page' => '2'];

        try {
            $request = Request::fromGlobals();

            $this->assertSame('GET', $request->method());
            $this->assertSame('books', $request->path());
            $this->assertSame('2', $request->query('page'));
            $this->assertSame('abc', $request->header('X-Trace'));
            $this->assertSame('application/json', $request->mime());
            $this->assertSame('198.51.100.7', $request->ip());
            $this->assertSame('', $request->rawBody());
        } finally {
            $_SERVER = $server;
            $_GET = $get;
        }
    }

    public function testFailThrowsWhenThereIsNoRestControllerToAnswer(): void
    {
        $request = new Request(['method' => 'POST']);

        try {
            $request->fail(422, 'Validation failed', ['isbn' => ['Bad ISBN']]);
            $this->fail('Expected a jamexRequestInvalid');
        } catch (jamexRequestInvalid $e) {
            $this->assertSame('Validation failed', $e->getMessage());
            $this->assertSame(422, $e->status());
            $this->assertSame(['isbn' => ['Bad ISBN']], $e->fields());
            $this->assertInstanceOf(jamex::class, $e);
        }
    }

    public function testValidateReturnsAValidatorBoundToThisRequest(): void
    {
        $request = new Request(['method' => 'POST']);

        $validator = $request->validate();

        $this->assertInstanceOf(RequestValidator::class, $validator);
        $this->assertSame($validator, $validator->method('post'));   // checked, and chainable
    }
}
