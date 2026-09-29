<?php
namespace Jambura\Mvc;

/**
 * Everything a controller can ask about the request it is answering.
 *
 * Read-only: it reports the request and changes nothing. Get one inside a
 * controller with $this->request(), and build one in a test with named
 * arguments:
 *
 *     $request = new Request(method: 'POST', body: ['isbn' => '978-0441013593']);
 *
 * The body is read whatever the method: form encoding is parsed for PUT and
 * DELETE as well as POST, and a JSON body is decoded. That is what makes
 * input() work where Rest::put() and Rest::delete() used to return false.
 *
 * validate() starts a RequestValidator bound to this request, which is how a
 * controller checks the method, the caller's roles, the payload and anything
 * else before it does any work.
 */
class Request
{
    /**
     * Header names lower-cased, mapped to the names as they arrived.
     * @var array<string, string>|null
     */
    private ?array $headerNames = null;

    /**
     * @param string               $method  the HTTP method, upper-cased
     * @param array<string, mixed> $query   the query string
     * @param array<string, mixed> $body    the decoded body
     * @param array<string, string> $headers request headers as they arrived
     * @param array                $files   uploaded files, as $_FILES
     * @param array                $server  server and environment values
     * @param string               $rawBody the body before decoding
     * @param string|null          $jsonError why a JSON body could not be read
     * @param Controller|null      $controller the controller being served, which
     *                                         decides how a failure is reported
     */
    public function __construct(
        private readonly string $method = 'GET',
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $headers = [],
        private readonly array $files = [],
        private readonly array $server = [],
        private readonly string $rawBody = '',
        private readonly ?string $jsonError = null,
        private readonly ?Controller $controller = null
    ) {
    }

    /**
     * Builds the request from PHP's superglobals and the request body.
     *
     * @param Controller|null $controller the controller being served
     */
    public static function fromGlobals(?Controller $controller = null): static
    {
        $server = $_SERVER;
        $method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : self::headersFrom($server);
        $raw = $method === 'GET' ? '' : (string) file_get_contents('php://input');

        $body = $_POST;
        $jsonError = null;
        if (self::isJsonType(self::typeFrom($headers, $server)) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            } else {
                $body = [];
                $jsonError = json_last_error() === JSON_ERROR_NONE
                    ? 'The JSON body is not an object'
                    : json_last_error_msg();
            }
        } elseif ($body === [] && $raw !== '') {
            parse_str($raw, $body);
        }

        return new static(
            method: $method,
            query: $_GET,
            body: $body,
            headers: $headers,
            files: $_FILES,
            server: $server,
            rawBody: $raw,
            jsonError: $jsonError,
            controller: $controller
        );
    }

    /**
     * The HTTP method, upper-cased: 'GET', 'POST', 'PUT', 'DELETE'.
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * Whether the request used any of these methods, whatever their casing.
     */
    public function isMethod(string ...$methods): bool
    {
        foreach ($methods as $method) {
            if (strtoupper($method) === $this->method) {
                return true;
            }
        }
        return false;
    }

    /**
     * The body's media type, without its parameters: 'application/json'.
     */
    public function mime(): ?string
    {
        return self::typeFrom($this->headers, $this->server);
    }

    /**
     * Whether the body is JSON, by its content type.
     */
    public function isJson(): bool
    {
        return self::isJsonType($this->mime());
    }

    /**
     * Why a JSON body could not be read, or null when there was nothing wrong.
     *
     * A schema check fails with 400 when this is set, since no field can be read
     * from a body that would not decode.
     */
    public function jsonError(): ?string
    {
        return $this->jsonError;
    }

    /**
     * The media types the caller will accept, best first.
     *
     * @return string[]
     */
    public function accepts(): array
    {
        $accept = $this->header('Accept');
        if ($accept === null || trim($accept) === '') {
            return [];
        }
        $types = [];
        foreach (explode(',', $accept) as $part) {
            $type = trim(explode(';', $part)[0]);
            if ($type !== '') {
                $types[] = $type;
            }
        }
        return $types;
    }

    /**
     * One header, found whatever the casing, or null when it was not sent.
     */
    public function header(string $name): ?string
    {
        if ($this->headerNames === null) {
            $this->headerNames = [];
            foreach ($this->headers as $header => $value) {
                $this->headerNames[strtolower($header)] = $header;
            }
        }
        $key = $this->headerNames[strtolower($name)] ?? null;
        return $key === null ? null : $this->headers[$key];
    }

    /**
     * Every header, as it arrived.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * The token out of an `Authorization: Bearer ...` header.
     */
    public function bearerToken(): ?string
    {
        $header = (string) $this->header('Authorization');
        return preg_match('/^Bearer\s+(.+)$/i', trim($header), $found) === 1 ? $found[1] : null;
    }

    /**
     * The request path, without the query string: 'books/show'.
     */
    public function path(): string
    {
        $path = (string) parse_url($this->server['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return trim($path, '/');
    }

    /**
     * A query string value, or the whole query string when given no name.
     *
     * @return mixed the value, $default, or array<string, mixed>
     */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->query : ($this->query[$key] ?? $default);
    }

    /**
     * A body value, or the whole body when given no name.
     *
     * Reads a form-encoded or JSON body, whatever the method.
     *
     * @return mixed the value, $default, or array<string, mixed>
     */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->body : ($this->body[$key] ?? $default);
    }

    /**
     * The query string and the body together, the body winning a clash.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    /**
     * Only these values out of all(), leaving out the ones that were not sent.
     *
     * @param string[] $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    /**
     * Whether a value arrived, in the query string or the body.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /**
     * Uploaded files, in $_FILES' shape.
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * The caller's address, as the server reported it.
     */
    public function ip(): ?string
    {
        return $this->server['REMOTE_ADDR'] ?? null;
    }

    /**
     * The body before it was decoded.
     */
    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * The caller's roles, from the resolver the application wired at bootstrap.
     *
     * Null means nobody is signed in, which a roles() check answers with 401.
     *
     * @return string[]|null
     */
    public function roles(): ?array
    {
        return RequestValidator::rolesOf($this);
    }

    /**
     * The controller being served, or null for a request built by hand.
     */
    public function controller(): ?Controller
    {
        return $this->controller;
    }

    /**
     * Starts a validator for this request, optionally running specs first.
     *
     * The validator is bound to this request, so every link is checked as it is
     * called and the first failure ends the request. Specs built by hand with
     * `new RequestValidator()` are replayed here, in the order they recorded
     * their links, and are left untouched so they can be reused:
     *
     *     $book = $this->request()->validate(BookRequests::create())
     *         ->check([$this, 'withinQuota'])
     *         ->validated();
     *
     * @param RequestValidator ...$specs validators to run before any further links
     */
    public function validate(RequestValidator ...$specs): RequestValidator
    {
        $validator = (new RequestValidator())->bindTo($this);
        foreach ($specs as $spec) {
            $validator->apply($spec);
        }
        return $validator;
    }

    /**
     * Reports that the request cannot be answered, and stops handling it.
     *
     * A Rest controller sends the status and exits, as sendError() does. Anything
     * else - a plain controller, or a request built in a test - throws
     * jamexRequestInvalid carrying the status and the messages.
     *
     * Called by RequestValidator; a check reports a failure by returning, not by
     * calling this.
     *
     * @param int    $status  the HTTP status
     * @param string $message what was wrong
     * @param array<string, string[]> $fields  per-field messages from a schema
     * @param array<string, string>   $headers headers to send with the failure
     *
     * @throws \jamexRequestInvalid unless a Rest controller answers instead
     */
    public function fail(int $status, string $message, array $fields = [], array $headers = []): void
    {
        if (!headers_sent()) {
            foreach ($headers as $name => $value) {
                header("$name: $value");
            }
        }
        if ($this->controller instanceof Rest) {
            $this->controller->sendValidationError($status, $message, $fields);
        }
        throw new \jamexRequestInvalid($message, $status, $fields);
    }

    /**
     * Rebuilds request headers from $_SERVER, where getallheaders() is missing.
     *
     * @param array $server
     * @return array<string, string>
     */
    private static function headersFrom(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length'] as $key => $name) {
            if (isset($server[$key])) {
                $headers[$name] = $server[$key];
            }
        }
        return $headers;
    }

    /**
     * The body's media type from the headers, falling back to $_SERVER.
     *
     * @param array<string, string> $headers
     */
    private static function typeFrom(array $headers, array $server): ?string
    {
        $type = null;
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'content-type') {
                $type = $value;
                break;
            }
        }
        $type ??= $server['CONTENT_TYPE'] ?? null;
        return $type === null ? null : strtolower(trim(explode(';', $type)[0]));
    }

    /**
     * Whether a media type means JSON, including the +json suffixes.
     */
    private static function isJsonType(?string $type): bool
    {
        return $type !== null && ($type === 'application/json' || str_ends_with($type, '+json'));
    }
}
