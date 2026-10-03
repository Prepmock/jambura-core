<?php
namespace Jambura\Mvc;

/**
 * Checks an array of data against a set of rules.
 *
 * The rule vocabulary is the one Jambura\Mvc\Model::validation() already uses,
 * so a rule reads the same in a model and in a controller. It reports; it never
 * sends or throws, which is what makes it usable outside a controller:
 *
 *     $validator = Validator::make($payload, [
 *         'email'  => 'required',
 *         'age'    => ['int', ['between', 13, 120]],
 *         'isbn'   => ['required', ['regex', '/^[0-9-]{10,17}$/', 'That ISBN does not look right']],
 *         'shelf'  => ['in', ['fiction', 'reference']],
 *     ]);
 *
 *     if ($validator->fails()) {
 *         print_r($validator->errors());   // ['email' => ['email is required']]
 *     }
 *
 * A field's rules are a bare rule name, or a list of rules where each is a name
 * or an array of a name and its arguments. An array whose first element is a
 * rule that takes arguments is read as one rule, so `['in', ['a', 'b']]` and
 * `['required', ['min', 1]]` both mean what they look like.
 *
 * A field that is absent is only an error when 'required' says so: other rules
 * are skipped for it, and it is left out of validated().
 */
class Validator
{
    /**
     * Rules that take arguments. An array of rules starting with one of these is
     * read as a single rule rather than as a list.
     */
    private const ARGUMENT_RULES = ['regex', 'function', 'in', 'min', 'max', 'between', 'length'];

    /**
     * Errors found, as field name => messages.
     * @var array<string, string[]>
     */
    private array $errors = [];

    /**
     * The declared fields that were present and passed, as field => value.
     * @var array<string, mixed>
     */
    private array $validated = [];

    /**
     * @param array<string, mixed> $data  the values to check
     * @param array<string, mixed> $rules field name => rules
     * @param object|null          $owner the object a ['function', ...] rule
     *                                    calls its method on, usually the
     *                                    controller
     */
    private function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly ?object $owner = null
    ) {
        $this->check();
    }

    /**
     * Checks $data against $rules and returns the result.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $rules field name => rules
     * @param object|null          $owner the object ['function', ...] rules call
     */
    public static function make(array $data, array $rules, ?object $owner = null): static
    {
        return new static($data, $rules, $owner);
    }

    /**
     * Whether every rule was satisfied.
     */
    public function passes(): bool
    {
        return $this->errors === [];
    }

    /**
     * Whether any rule was broken.
     */
    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Everything that was wrong, as field name => messages.
     *
     * @return array<string, string[]>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * The first message, for a caller that only reports one.
     */
    public function firstError(): ?string
    {
        foreach ($this->errors as $messages) {
            return reset($messages) ?: null;
        }
        return null;
    }

    /**
     * The declared fields that were present, and passed.
     *
     * Only fields the rules named, so the result is safe to hand to
     * Model::add() without a caller sneaking in extra columns.
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        return $this->validated;
    }

    /**
     * Turns a set of rules into a JSON Schema object.
     *
     * The same rules that check a request describe it, which is what lets an
     * action be published as an MCP tool without declaring its shape twice.
     * Only what JSON Schema can express is translated:
     *
     * | Rule                      | Becomes                                   |
     * |---------------------------|-------------------------------------------|
     * | required                  | the field listed in `required`             |
     * | int, number, bool         | type integer, number, boolean              |
     * | email                     | type string, format email                  |
     * | in                        | enum, with the type when the values agree  |
     * | min, max, between         | minimum / maximum                          |
     * | length                    | type string, minLength / maxLength         |
     * | regex                     | pattern, and its message as a description  |
     *
     * A `function` rule has no schema equivalent and is left out; it still runs
     * when the request is checked. A field with no type rule is described as a
     * string, since that is how a value arrives in a query string or a form
     * body. `additionalProperties` is false, matching validated(), which keeps
     * only the fields the rules named.
     *
     * @param array<string, mixed> $rules field name => rules
     * @return array a JSON Schema object, dialect 2020-12
     */
    public static function jsonSchema(array $rules): array
    {
        $properties = [];
        $required = [];

        foreach ($rules as $field => $fieldRules) {
            $property = [];
            foreach (self::normalize($fieldRules) as $rule) {
                $args = array_slice($rule, 1);
                switch ($rule[0]) {
                    case 'required':
                        $required[] = $field;
                        break;
                    case 'int':
                        $property['type'] = 'integer';
                        break;
                    case 'number':
                        $property['type'] = 'number';
                        break;
                    case 'bool':
                        $property['type'] = 'boolean';
                        break;
                    case 'email':
                        $property['type'] = 'string';
                        $property['format'] = 'email';
                        break;
                    case 'in':
                        $allowed = array_values(is_array($args[0] ?? null) ? $args[0] : $args);
                        $property['enum'] = $allowed;
                        $type = self::enumType($allowed);
                        if ($type !== null) {
                            $property['type'] = $type;
                        }
                        break;
                    case 'min':
                        $property['minimum'] = $args[0];
                        break;
                    case 'max':
                        $property['maximum'] = $args[0];
                        break;
                    case 'between':
                        $property['minimum'] = $args[0];
                        $property['maximum'] = $args[1];
                        break;
                    case 'length':
                        $property['type'] = 'string';
                        $property['minLength'] = $args[0];
                        if (isset($args[1])) {
                            $property['maxLength'] = $args[1];
                        }
                        break;
                    case 'regex':
                        $pattern = self::ecmaPattern($args[0]);
                        if ($pattern !== null) {
                            $property['pattern'] = $pattern;
                        }
                        if (isset($args[1])) {
                            $property['description'] = $args[1];
                        }
                        break;
                }
            }
            $property['type'] ??= 'string';
            $properties[$field] = $property;
        }

        $schema = ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
        if ($required) {
            $schema['required'] = $required;
        }
        return $schema;
    }

    /**
     * The JSON Schema type every value of an enum shares, or null when they differ.
     *
     * @param array $values
     */
    private static function enumType(array $values): ?string
    {
        $types = [];
        foreach ($values as $value) {
            if (is_bool($value)) {
                $types['boolean'] = true;
            } elseif (is_int($value)) {
                $types['integer'] = true;
            } elseif (is_float($value)) {
                $types['number'] = true;
            } elseif (is_string($value)) {
                $types['string'] = true;
            } else {
                return null;
            }
        }
        return count($types) === 1 ? array_key_first($types) : null;
    }

    /**
     * Turns a PCRE pattern into one JSON Schema can use, or null when it cannot.
     *
     * JSON Schema patterns are ECMA-262: no delimiters and no flags. The
     * delimiters are stripped here, and a pattern carrying flags is refused
     * rather than mistranslated - /x/i would silently become case-sensitive,
     * which is worse than advertising no pattern at all. The rule still runs
     * when the request is checked either way.
     */
    private static function ecmaPattern(string $pattern): ?string
    {
        if (strlen($pattern) < 2) {
            return null;
        }
        $open = $pattern[0];
        $close = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'][$open] ?? $open;
        $end = strrpos($pattern, $close);
        if ($end === false || $end === 0) {
            return null;
        }
        // Anything after the closing delimiter is flags, which have no equivalent.
        return substr($pattern, $end + 1) === '' ? substr($pattern, 1, $end - 1) : null;
    }

    /**
     * Runs every field's rules, collecting errors rather than stopping at the first.
     */
    private function check(): void
    {
        foreach ($this->rules as $field => $rules) {
            $rules = self::normalize($rules);
            $present = $this->isPresent($field);

            if (self::requires($rules)) {
                if (!$present) {
                    $this->errors[$field][] = "$field is required";
                    continue;
                }
            } elseif (!$present) {
                continue;
            }

            $value = $this->data[$field];
            foreach ($rules as $rule) {
                if ($rule[0] === 'required') {
                    continue;
                }
                $result = $this->apply($rule, $field, $value);
                if ($result !== true) {
                    $this->errors[$field][] = $result;
                }
            }
            if (!isset($this->errors[$field])) {
                $this->validated[$field] = $value;
            }
        }
    }

    /**
     * Whether a field arrived with something in it.
     *
     * An empty string counts as absent, because an empty form field is how a
     * browser sends "nothing".
     */
    private function isPresent(string $field): bool
    {
        if (!array_key_exists($field, $this->data)) {
            return false;
        }
        $value = $this->data[$field];
        return $value !== null && $value !== '' && $value !== [];
    }

    /**
     * Turns a field's rules into a list of [name, ...arguments].
     *
     * @param mixed $rules a rule name, one rule with arguments, or a list of rules
     * @return array<int, array>
     */
    private static function normalize(mixed $rules): array
    {
        if (is_string($rules)) {
            return [[$rules]];
        }
        if (!is_array($rules) || $rules === []) {
            return [];
        }
        if (is_string($rules[0] ?? null) && in_array($rules[0], self::ARGUMENT_RULES, true)) {
            return [array_values($rules)];
        }
        return array_map(
            fn (mixed $rule) => is_string($rule) ? [$rule] : array_values((array) $rule),
            array_values($rules)
        );
    }

    /**
     * Whether 'required' is among a field's rules.
     *
     * @param array<int, array> $rules
     */
    private static function requires(array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($rule[0] === 'required') {
                return true;
            }
        }
        return false;
    }

    /**
     * Applies one rule to one value.
     *
     * @param array $rule  [name, ...arguments]
     * @return true|string true when it passed, the message when it did not
     */
    private function apply(array $rule, string $field, mixed $value): bool|string
    {
        $name = $rule[0];
        $args = array_slice($rule, 1);

        switch ($name) {
            case 'int':
                return filter_var($value, FILTER_VALIDATE_INT) !== false
                    ?: "$field must be a whole number";
            case 'number':
                return is_numeric($value) ?: "$field must be a number";
            case 'bool':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null
                    ?: "$field must be true or false";
            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) !== false
                    ?: "$field must be an email address";
            case 'regex':
                return preg_match($args[0], (string) $value) === 1
                    ?: ($args[1] ?? "$field is not in the right format");
            case 'in':
                $allowed = is_array($args[0] ?? null) ? $args[0] : $args;
                return in_array($value, $allowed)
                    ?: "$field must be one of: " . implode(', ', $allowed);
            case 'min':
                return is_numeric($value) && $value >= $args[0]
                    ?: "$field must be at least {$args[0]}";
            case 'max':
                return is_numeric($value) && $value <= $args[0]
                    ?: "$field must be at most {$args[0]}";
            case 'between':
                return is_numeric($value) && $value >= $args[0] && $value <= $args[1]
                    ?: "$field must be between {$args[0]} and {$args[1]}";
            case 'length':
                $length = mb_strlen((string) $value);
                $max = $args[1] ?? null;
                if ($length < $args[0] || ($max !== null && $length > $max)) {
                    return $max === null
                        ? "$field must be at least {$args[0]} characters"
                        : "$field must be between {$args[0]} and $max characters";
                }
                return true;
            case 'function':
                return $this->callOwner($args[0], $field, $value);
            default:
                throw new \InvalidArgumentException("Unknown validation rule '$name' for '$field'");
        }
    }

    /**
     * Calls a ['function', ...] rule on the owner.
     *
     * The method is called with the value and every value being checked, and may
     * be protected, as Model's validation callbacks are. Returning false gives a
     * default message; returning a string uses it.
     *
     * @return true|string
     */
    private function callOwner(string $method, string $field, mixed $value): bool|string
    {
        if ($this->owner === null) {
            throw new \LogicException(
                "The '$field' rule calls $method(), but the validator was given no object to call it on"
            );
        }
        if (!method_exists($this->owner, $method)) {
            throw new \LogicException(
                "The '$field' rule calls $method(), which " . get_class($this->owner) . ' does not have'
            );
        }

        $call = new \ReflectionMethod($this->owner, $method);
        $call->setAccessible(true);
        $result = $call->invoke($this->owner, $value, $this->data);

        if ($result === true) {
            return true;
        }
        return is_string($result) ? $result : "$field is not valid";
    }
}
