<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer;

/** @psalm-immutable */
final readonly class JsFunction
{
    /**
     * @param null|array{parameters:array,body:string,scope:array,async:bool} $definition Factory state; omit when passing source.
     * @psalm-mutation-free
     */
    public function __construct(public string $source, private ?array $definition = null) {}

    /** @psalm-pure */
    public static function createWithBody(string $body): self
    {
        return self::build([], $body, [], false);
    }

    /** @psalm-pure */
    public static function createWithParameters(array $parameters): self
    {
        return self::build($parameters, '', [], false);
    }

    /** @psalm-pure */
    public static function createWithScope(array $scope): self
    {
        return self::build([], '', $scope, false);
    }

    /** @psalm-pure */
    public static function createWithAsync(bool $isAsync = true): self
    {
        return self::build([], '', [], $isAsync);
    }

    /** Old create(body, scope) and create(parameters, body, scope) forms.
     * @psalm-pure
     */
    public static function create(array|string $parameters = [], string|array $body = '', array $scope = []): self
    {
        if (is_string($parameters)) {
            return self::build([], $parameters, is_array($body) ? $body : $scope, false);
        }
        if (!is_string($body)) {
            throw new \InvalidArgumentException('Function body must be a string');
        }
        return self::build($parameters, $body, $scope, false);
    }

    /** @psalm-mutation-free */
    public function body(string $body): self { return $this->with(['body' => $body]); }
    /** @psalm-mutation-free */
    public function parameters(array $parameters): self { return $this->with(['parameters' => $parameters]); }
    /** @psalm-mutation-free */
    public function scope(array $scope): self { return $this->with(['scope' => $scope]); }
    /** @psalm-mutation-free */
    public function async(bool $isAsync = true): self { return $this->with(['async' => $isAsync]); }

    /**
     * @param array{parameters?:array,body?:string,scope?:array,async?:bool} $changes
     * @psalm-mutation-free
     */
    private function with(array $changes): self
    {
        if ($this->definition === null) {
            throw new \LogicException('Use createWithBody/Parameters/Scope to build a function; raw source cannot be edited');
        }
        $definition = array_replace($this->definition, $changes);
        return self::build($definition['parameters'], $definition['body'], $definition['scope'], $definition['async']);
    }

    /** @psalm-pure */
    private static function build(array $parameters, string $body, array $scope, bool $async): self
    {
        $arguments = [];
        foreach ($parameters as $key => $value) {
            $name = is_int($key) ? $value : $key;
            self::identifier($name);
            $arguments[] = is_int($key) ? $name : $name . ' = ' . self::valueSource($value);
        }
        $variables = '';
        foreach ($scope as $name => $value) {
            self::identifier($name);
            $variables .= 'var ' . $name . ' = ' . self::valueSource($value) . ";\n";
        }
        $source = ($async ? 'async ' : '') . 'function(' . implode(', ', $arguments) . ") {\n" . $variables . $body . "\n}";
        return new self($source, ['parameters' => $parameters, 'body' => $body, 'scope' => $scope, 'async' => $async]);
    }

    /** @psalm-pure */
    private static function identifier(mixed $name): void
    {
        if (!is_string($name) || !preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/D', $name)) {
            throw new \InvalidArgumentException('Invalid JavaScript parameter or scope name');
        }
    }

    /** @psalm-pure */
    private static function valueSource(mixed $value): string
    {
        if ($value instanceof self) {
            return '(' . $value->source . ')';
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(', ', array_map(self::valueSource(...), $value)) . ']';
            }
            $entries = [];
            foreach ($value as $key => $item) {
                $entries[] = json_encode((string) $key, JSON_THROW_ON_ERROR) . ': ' . self::valueSource($item);
            }
            return '{' . implode(', ', $entries) . '}';
        }
        if ($value !== null && !is_scalar($value)) {
            throw new \InvalidArgumentException('Function scope/defaults support scalars, arrays and JsFunction; pass remote handles as evaluate arguments');
        }
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
