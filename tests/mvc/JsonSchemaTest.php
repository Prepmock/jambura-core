<?php

use Jambura\Mvc\RequestValidator;
use Jambura\Mvc\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JsonSchemaTest extends TestCase
{
    public function testDescribesAWholeRuleSet(): void
    {
        $schema = Validator::jsonSchema([
            'title'  => 'required',
            'isbn'   => ['required', ['regex', '/^[0-9-]{10,17}$/', 'That ISBN does not look right']],
            'copies' => ['required', 'int', ['min', 1]],
            'shelf'  => ['in', ['fiction', 'reference']],
            'note'   => [['length', 2, 60]],
        ]);

        $this->assertSame([
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'isbn' => [
                    'pattern' => '^[0-9-]{10,17}$',
                    'description' => 'That ISBN does not look right',
                    'type' => 'string',
                ],
                'copies' => ['type' => 'integer', 'minimum' => 1],
                'shelf' => ['enum' => ['fiction', 'reference'], 'type' => 'string'],
                'note' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 60],
            ],
            'additionalProperties' => false,
            'required' => ['title', 'isbn', 'copies'],
        ], $schema);
    }

    public static function ruleProvider(): array
    {
        return [
            'int' => ['int', ['type' => 'integer']],
            'number' => ['number', ['type' => 'number']],
            'bool' => ['bool', ['type' => 'boolean']],
            'email' => ['email', ['type' => 'string', 'format' => 'email']],
            'no type rule defaults to string' => ['required', ['type' => 'string']],
            'min' => [['min', 1], ['minimum' => 1, 'type' => 'string']],
            'max' => [['max', 10], ['maximum' => 10, 'type' => 'string']],
            'between' => [['between', 13, 120], ['minimum' => 13, 'maximum' => 120, 'type' => 'string']],
            'length with a maximum' => [['length', 2, 6], ['type' => 'string', 'minLength' => 2, 'maxLength' => 6]],
            'length without one' => [['length', 2], ['type' => 'string', 'minLength' => 2]],
            'enum of strings' => [['in', ['a', 'b']], ['enum' => ['a', 'b'], 'type' => 'string']],
            'enum of integers' => [['in', [1, 2]], ['enum' => [1, 2], 'type' => 'integer']],
            'enum of mixed values falls back to string' => [['in', [1, 'b']], ['enum' => [1, 'b'], 'type' => 'string']],
            'a function rule says nothing' => [['function', 'shelfExists'], ['type' => 'string']],
        ];
    }

    #[DataProvider('ruleProvider')]
    public function testEachRule(mixed $rule, array $expected): void
    {
        $schema = Validator::jsonSchema(['field' => is_string($rule) ? $rule : [$rule]]);

        $this->assertSame($expected, $schema['properties']['field']);
    }

    public function testStripsPcreDelimitersFromAPattern(): void
    {
        foreach (['/^a$/' => '^a$', '#^a$#' => '^a$', '~^a$~' => '^a$', '{^a$}' => '^a$'] as $pcre => $ecma) {
            $schema = Validator::jsonSchema(['field' => [['regex', $pcre]]]);
            $this->assertSame($ecma, $schema['properties']['field']['pattern'], $pcre);
        }
    }

    public function testLeavesOutAPatternItCannotTranslate(): void
    {
        // Flags have no JSON Schema equivalent: /x/i would silently become
        // case-sensitive, so no pattern is advertised at all.
        $schema = Validator::jsonSchema(['field' => [['regex', '/^a$/i']]]);

        $this->assertArrayNotHasKey('pattern', $schema['properties']['field']);
        $this->assertSame(['type' => 'string'], $schema['properties']['field']);
    }

    public function testNoRulesMeansATooltakingNoArguments(): void
    {
        $this->assertSame(
            ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            Validator::jsonSchema([])
        );
    }

    public function testAValidatorDescribesEveryOneOfItsSchemas(): void
    {
        $spec = (new RequestValidator())
            ->method('post')
            ->roles(['librarian', 'admin'])
            ->schema(['title' => 'required'])
            ->schema(['copies' => ['required', 'int']]);

        $this->assertSame([
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'copies' => ['type' => 'integer'],
            ],
            'additionalProperties' => false,
            'required' => ['title', 'copies'],
        ], $spec->jsonSchema());
        $this->assertSame(['POST'], $spec->requiredMethods());
        $this->assertSame(['librarian', 'admin'], $spec->requiredRoles());
    }

    public function testASpecWithoutMethodOrRolesReportsNone(): void
    {
        $spec = (new RequestValidator())->schema(['title' => 'required']);

        $this->assertSame([], $spec->requiredMethods());
        $this->assertSame([], $spec->requiredRoles());
    }
}
