<?php

use Jambura\Mvc\Request;
use Jambura\Mvc\RequestCheck;
use Jambura\Mvc\RequestValidator;
use PHPUnit\Framework\TestCase;

class DuringOpeningHours implements RequestCheck
{
    public static bool $open = true;

    public function passes(Request $request)
    {
        return self::$open ?: ['status' => 503, 'error' => 'The library is closed'];
    }
}

class QuotaOwner
{
    public static int $seen = 0;

    protected function withinQuota(Request $request)
    {
        self::$seen++;
        return $request->input('copies', 1) <= 10
            ? true
            : ['status' => 429, 'error' => 'Monthly quota used up'];
    }
}

class RequestValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        RequestValidator::forgetRolesResolver();
        DuringOpeningHours::$open = true;
        QuotaOwner::$seen = 0;
    }

    protected function tearDown(): void
    {
        RequestValidator::forgetRolesResolver();
    }

    // --- method ---------------------------------------------------------

    public function testMethodPassesTheRightMethodWhateverTheCasing(): void
    {
        $request = new Request(method: 'POST');

        $this->assertInstanceOf(RequestValidator::class, $request->validate()->method('post'));
    }

    public function testMethodRefusesAnyOtherMethodWith405(): void
    {
        $request = new Request(method: 'GET');

        $failure = $this->failureFrom(fn () => $request->validate()->method('post', 'put'));

        $this->assertSame(405, $failure->status());
        $this->assertSame('Method not allowed', $failure->getMessage());
    }

    // --- roles ----------------------------------------------------------

    public function testRolesNeedsAResolverToHaveBeenWired(): void
    {
        $request = new Request();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No roles resolver');
        $request->validate()->roles(['admin']);
    }

    public function testRolesAnswers401WhenNobodyIsSignedIn(): void
    {
        RequestValidator::resolveRolesUsing(fn () => null);
        $request = new Request();

        $failure = $this->failureFrom(fn () => $request->validate()->roles(['admin']));

        $this->assertSame(401, $failure->status());
        $this->assertSame('Authentication required', $failure->getMessage());
    }

    public function testRolesAnswers403WhenTheCallerHoldsNoneOfThem(): void
    {
        RequestValidator::resolveRolesUsing(fn () => ['reader']);
        $request = new Request();

        $failure = $this->failureFrom(fn () => $request->validate()->roles(['librarian', 'admin']));

        $this->assertSame(403, $failure->status());
        $this->assertSame('You may not do that', $failure->getMessage());
    }

    public function testRolesPassesOnAnyOneOfThemAndTakesEitherShape(): void
    {
        RequestValidator::resolveRolesUsing(fn () => ['admin']);
        $request = new Request();

        $request->validate()->roles(['librarian', 'admin']);
        $request->validate()->roles('librarian', 'admin');
        $this->assertSame(['admin'], $request->roles());
    }

    public function testASingleRoleFromTheResolverIsTreatedAsAList(): void
    {
        RequestValidator::resolveRolesUsing(fn () => 'admin');

        $this->assertSame(['admin'], (new Request())->roles());
    }

    // --- schema ---------------------------------------------------------

    public function testSchemaPassesAndValidatedHoldsTheDeclaredFields(): void
    {
        $request = new Request(
            method: 'POST',
            query: ['page' => '2'],
            body: ['title' => 'Dune', 'isbn' => '978-0441013593', 'sneaky' => 'x']
        );

        $data = $request->validate()
            ->schema(['title' => 'required', 'isbn' => ['required', ['regex', '/^[0-9-]{10,17}$/']]])
            ->validated();

        $this->assertSame(['title' => 'Dune', 'isbn' => '978-0441013593'], $data);
    }

    public function testSchemaReadsFieldsFromTheQueryStringToo(): void
    {
        $request = new Request(query: ['id' => '7']);

        $this->assertSame(['id' => '7'], $request->validate()->schema(['id' => ['required', 'int']])->validated());
    }

    public function testSchemaAnswers422WithAMessagePerField(): void
    {
        $request = new Request(method: 'POST', body: ['isbn' => 'nope']);

        $failure = $this->failureFrom(fn () => $request->validate()->schema([
            'title' => 'required',
            'isbn' => [['regex', '/^[0-9-]{10,17}$/', 'That ISBN does not look right']],
        ]));

        $this->assertSame(422, $failure->status());
        $this->assertSame('Validation failed', $failure->getMessage());
        $this->assertSame([
            'title' => ['title is required'],
            'isbn' => ['That ISBN does not look right'],
        ], $failure->fields());
    }

    public function testABodyThatWouldNotDecodeAnswers400(): void
    {
        $request = new Request(
            method: 'POST',
            headers: ['Content-Type' => 'application/json'],
            rawBody: '{oops',
            jsonError: 'Syntax error'
        );

        $failure = $this->failureFrom(fn () => $request->validate()->schema(['title' => 'required']));

        $this->assertSame(400, $failure->status());
        $this->assertStringContainsString('Syntax error', $failure->getMessage());
    }

    public function testSeveralSchemasMergeIntoOneValidatedPayload(): void
    {
        $request = new Request(method: 'POST', body: ['title' => 'Dune', 'copies' => '3']);

        $data = $request->validate()
            ->schema(['title' => 'required'])
            ->schema(['copies' => ['required', 'int', ['min', 1]]])
            ->validated();

        $this->assertSame(['title' => 'Dune', 'copies' => '3'], $data);
    }

    // --- checks ---------------------------------------------------------

    public function testACheckCanBeAClosure(): void
    {
        $request = new Request(method: 'POST', body: ['from' => 'a', 'to' => 'a']);

        $failure = $this->failureFrom(fn () => $request->validate()->check(
            fn (Request $r) => $r->input('to') !== $r->input('from') ?: 'Shelves must differ'
        ));

        $this->assertSame(422, $failure->status());
        $this->assertSame('Shelves must differ', $failure->getMessage());
    }

    public function testACheckReturningFalseGetsADefaultMessage(): void
    {
        $failure = $this->failureFrom(fn () => (new Request())->validate()->check(fn (Request $r) => false));

        $this->assertSame(422, $failure->status());
        $this->assertSame('The request was refused', $failure->getMessage());
    }

    public function testACheckCanBeAProtectedMethodAndChooseItsStatus(): void
    {
        $owner = new QuotaOwner();
        $request = new Request(method: 'POST', body: ['copies' => 99]);

        $failure = $this->failureFrom(fn () => $request->validate()->check([$owner, 'withinQuota']));

        $this->assertSame(429, $failure->status());
        $this->assertSame('Monthly quota used up', $failure->getMessage());
        $this->assertSame(1, QuotaOwner::$seen);
    }

    public function testACheckCanBeARequestCheckObjectOrItsClassName(): void
    {
        $request = new Request();

        $request->validate()->check(new DuringOpeningHours())->check(DuringOpeningHours::class);

        DuringOpeningHours::$open = false;
        $failure = $this->failureFrom(fn () => $request->validate()->check(DuringOpeningHours::class));

        $this->assertSame(503, $failure->status());
        $this->assertSame('The library is closed', $failure->getMessage());
    }

    public function testACheckThatIsNeitherIsAProgrammingError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Request())->validate()->check('not_a_function_or_class');
    }

    // --- order and fail-fast --------------------------------------------

    public function testTheFirstFailingLinkEndsTheRequest(): void
    {
        $request = new Request(method: 'GET');

        // The schema would throw on its unknown rule if it were ever reached
        $failure = $this->failureFrom(fn () => $request->validate()
            ->method('post')
            ->schema(['title' => ['colour']]));

        $this->assertSame(405, $failure->status());
    }

    public function testMethodAndRolesReplaceWhileChecksAppend(): void
    {
        $spec = (new RequestValidator())
            ->method('get')
            ->method('post')
            ->check(fn (Request $r) => true)
            ->check(fn (Request $r) => true);

        $kinds = array_map(fn (array $link) => $link[0], $spec->definedLinks());

        $this->assertSame(['method', 'check', 'check'], $kinds);
        $this->assertSame([['POST']], array_values(array_map(
            fn (array $link) => $link[1],
            array_filter($spec->definedLinks(), fn (array $link) => $link[0] === 'method')
        )));
    }

    // --- specs ----------------------------------------------------------

    public function testASpecOnlyRecordsUntilItIsGivenARequest(): void
    {
        $spec = (new RequestValidator())->method('post')->schema(['title' => 'required']);

        $this->assertCount(2, $spec->definedLinks());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('validated() needs a request');
        $spec->validated();
    }

    public function testValidateRunsASpecAndThenGoesOnChaining(): void
    {
        $spec = (new RequestValidator())->method('post')->schema(['title' => 'required']);
        $request = new Request(method: 'POST', body: ['title' => 'Dune', 'copies' => '2']);

        $data = $request->validate($spec)
            ->schema(['copies' => ['required', 'int']])
            ->validated();

        $this->assertSame(['title' => 'Dune', 'copies' => '2'], $data);
    }

    public function testASpecThatFailsEndsTheRequestBeforeTheChainContinues(): void
    {
        $spec = (new RequestValidator())->method('post');
        $request = new Request(method: 'GET');

        $failure = $this->failureFrom(fn () => $request->validate($spec)->schema(['title' => 'required']));

        $this->assertSame(405, $failure->status());
    }

    public function testRunningASpecLeavesItUntouchedSoItIsReusable(): void
    {
        $spec = (new RequestValidator())->method('post')->schema(['title' => 'required']);
        $links = $spec->definedLinks();

        $first = new Request(method: 'POST', body: ['title' => 'Dune']);
        $second = new Request(method: 'POST', body: ['title' => 'Emma']);

        $this->assertSame(['title' => 'Dune'], $first->validate($spec)->validated());
        $this->assertSame(['title' => 'Emma'], $second->validate($spec)->validated());
        $this->assertSame($links, $spec->definedLinks());
    }

    public function testSeveralSpecsRunInTheOrderTheyAreGiven(): void
    {
        RequestValidator::resolveRolesUsing(fn () => ['admin']);
        $jsonOnly = (new RequestValidator())->check(fn (Request $r) => $r->isJson() ?: 'JSON only');
        $create = (new RequestValidator())->method('post')->roles(['admin']);
        $request = new Request(method: 'POST', headers: ['Content-Type' => 'application/json']);

        $this->assertInstanceOf(RequestValidator::class, $request->validate($jsonOnly, $create));

        $form = new Request(method: 'POST', headers: ['Content-Type' => 'text/plain']);
        $this->assertSame('JSON only', $this->failureFrom(fn () => $form->validate($jsonOnly, $create))->getMessage());
    }

    /**
     * Runs something that should refuse the request, and returns the failure.
     */
    private function failureFrom(callable $run): jamexRequestInvalid
    {
        try {
            $run();
        } catch (jamexRequestInvalid $e) {
            return $e;
        }
        $this->fail('Expected the request to be refused');
    }
}
