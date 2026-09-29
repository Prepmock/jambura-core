<?php

use Jambura\Mvc\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ValidationOwner
{
    protected function shelfExists($value, array $data)
    {
        return in_array($value, ['fiction', 'reference'], true);
    }

    protected function withReason($value, array $data)
    {
        return 'That shelf is full';
    }

    protected function sawData($value, array $data)
    {
        return $data === ['shelf' => 'fiction', 'copies' => 2] ?: 'did not see the whole payload';
    }
}

class ValidatorTest extends TestCase
{
    public function testARequiredFieldMustArrive(): void
    {
        $validator = Validator::make([], ['title' => 'required']);

        $this->assertTrue($validator->fails());
        $this->assertFalse($validator->passes());
        $this->assertSame(['title' => ['title is required']], $validator->errors());
        $this->assertSame('title is required', $validator->firstError());
        $this->assertSame([], $validator->validated());
    }

    public function testAnEmptyStringCountsAsAbsent(): void
    {
        $this->assertTrue(Validator::make(['title' => ''], ['title' => 'required'])->fails());
    }

    public function testAnAbsentOptionalFieldIsSkippedEntirely(): void
    {
        $validator = Validator::make([], ['copies' => ['int', ['min', 1]]]);

        $this->assertTrue($validator->passes());
        $this->assertSame([], $validator->validated());
    }

    public function testValidatedHoldsOnlyTheDeclaredFieldsThatPassed(): void
    {
        $validator = Validator::make(
            ['title' => 'Dune', 'copies' => '3', 'sneaky' => 'DROP TABLE'],
            ['title' => 'required', 'copies' => ['required', 'int']]
        );

        $this->assertTrue($validator->passes());
        $this->assertSame(['title' => 'Dune', 'copies' => '3'], $validator->validated());
    }

    public static function ruleProvider(): array
    {
        return [
            'int passes' => ['42', 'int', true],
            'int fails' => ['4.2', 'int', 'copies must be a whole number'],
            'number passes' => ['4.2', 'number', true],
            'number fails' => ['many', 'number', 'copies must be a number'],
            'bool passes' => ['yes', 'bool', true],
            'bool fails' => ['maybe', 'bool', 'copies must be true or false'],
            'email passes' => ['a@b.co', 'email', true],
            'email fails' => ['a@b', 'email', 'copies must be an email address'],
            'min passes' => [5, ['min', 1], true],
            'min fails' => [0, ['min', 1], 'copies must be at least 1'],
            'max fails' => [11, ['max', 10], 'copies must be at most 10'],
            'between passes' => [13, ['between', 13, 120], true],
            'between fails' => [12, ['between', 13, 120], 'copies must be between 13 and 120'],
            'in passes' => ['fiction', ['in', ['fiction', 'reference']], true],
            'in fails' => ['attic', ['in', ['fiction', 'reference']], 'copies must be one of: fiction, reference'],
            'length passes' => ['abcd', ['length', 2, 6], true],
            'length too short' => ['a', ['length', 2, 6], 'copies must be between 2 and 6 characters'],
            'length minimum only' => ['a', ['length', 2], 'copies must be at least 2 characters'],
            'regex passes' => ['978-0441013593', ['regex', '/^[0-9-]{10,17}$/'], true],
            'regex default message' => ['nope', ['regex', '/^[0-9]+$/'], 'copies is not in the right format'],
            'regex custom message' => ['nope', ['regex', '/^[0-9]+$/', 'Digits only'], 'Digits only'],
        ];
    }

    #[DataProvider('ruleProvider')]
    public function testEachRule(mixed $value, mixed $rule, bool|string $expected): void
    {
        $validator = Validator::make(['copies' => $value], ['copies' => is_string($rule) ? [$rule] : [$rule]]);

        if ($expected === true) {
            $this->assertTrue($validator->passes(), $validator->firstError() ?? '');
        } else {
            $this->assertSame([$expected], $validator->errors()['copies']);
        }
    }

    public function testAFieldCollectsEveryBrokenRule(): void
    {
        $validator = Validator::make(['copies' => 'many'], ['copies' => ['required', 'int', ['min', 1]]]);

        $this->assertSame(
            ['copies must be a whole number', 'copies must be at least 1'],
            $validator->errors()['copies']
        );
    }

    public function testAFunctionRuleCallsTheOwnersProtectedMethod(): void
    {
        $owner = new ValidationOwner();

        $passes = Validator::make(['shelf' => 'fiction'], ['shelf' => ['function', 'shelfExists']], $owner);
        $fails = Validator::make(['shelf' => 'attic'], ['shelf' => ['function', 'shelfExists']], $owner);

        $this->assertTrue($passes->passes());
        $this->assertSame(['shelf is not valid'], $fails->errors()['shelf']);
    }

    public function testAFunctionRuleCanGiveItsOwnMessage(): void
    {
        $validator = Validator::make(
            ['shelf' => 'fiction'],
            ['shelf' => ['function', 'withReason']],
            new ValidationOwner()
        );

        $this->assertSame(['That shelf is full'], $validator->errors()['shelf']);
    }

    public function testAFunctionRuleSeesEveryValueBeingChecked(): void
    {
        $validator = Validator::make(
            ['shelf' => 'fiction', 'copies' => 2],
            ['shelf' => ['function', 'sawData']],
            new ValidationOwner()
        );

        $this->assertTrue($validator->passes(), $validator->firstError() ?? '');
    }

    public function testAFunctionRuleWithoutAnOwnerIsAProgrammingError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("The 'shelf' rule calls shelfExists()");
        Validator::make(['shelf' => 'fiction'], ['shelf' => ['function', 'shelfExists']]);
    }

    public function testAFunctionRuleNamingAMissingMethodIsAProgrammingError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('which ValidationOwner does not have');
        Validator::make(['shelf' => 'x'], ['shelf' => ['function', 'nope']], new ValidationOwner());
    }

    public function testAnUnknownRuleIsAProgrammingError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown validation rule 'colour' for 'title'");
        Validator::make(['title' => 'Dune'], ['title' => ['colour']]);
    }

    public function testARuleWithArgumentsNeedsNoExtraNesting(): void
    {
        $nested = Validator::make(['shelf' => 'attic'], ['shelf' => [['in', ['fiction']]]]);
        $flat = Validator::make(['shelf' => 'attic'], ['shelf' => ['in', ['fiction']]]);

        $this->assertSame($nested->errors(), $flat->errors());
    }
}
