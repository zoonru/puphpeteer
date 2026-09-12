<?php

declare(strict_types=1);

namespace Zoon\Puphpeteer\Tooling;

use InvalidArgumentException;

/**
 * @psalm-import-type ClassSpec from Synchronizer
 * @psalm-pure
 */
final class Request
{
    /**
     * @return array{root:string,classes:list<ClassSpec>}
     * @psalm-pure
     */
    public static function decode(string $json): array
    {
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value) || !is_string($value['root'] ?? null) || !is_array($value['classes'] ?? null) || !array_is_list($value['classes'])) {
            throw new InvalidArgumentException('Request requires root:string and classes:list');
        }
        foreach ($value['classes'] as $class) {
            if (!is_array($class)) {
                throw new InvalidArgumentException('Each class must be an object');
            }
            self::strings($class, ['name', 'fqcn', 'file']);
            self::optionalStrings($class, ['extends']);
            if (!is_array($class['members'] ?? null) || !array_is_list($class['members'])) {
                throw new InvalidArgumentException('Class members must be a list');
            }
            self::lines($class);
            self::templates($class);
            foreach ($class['members'] as $member) {
                if (!is_array($member)) {
                    throw new InvalidArgumentException('Member must be an object');
                }
                self::strings($member, ['id', 'name', 'kind']);
                self::optionalStrings($member, ['returnType', 'returnDocType', 'type', 'docType', 'jsName']);
                self::booleans($member, ['static']);
                self::lines($member);
                self::templates($member);
                $parameters = $member['parameters'] ?? [];
                if (!is_array($parameters) || !array_is_list($parameters)) {
                    throw new InvalidArgumentException('Parameters must be a list');
                }
                foreach ($parameters as $parameter) {
                    if (!is_array($parameter)) {
                        throw new InvalidArgumentException('Parameter must be an object');
                    }
                    self::strings($parameter, ['name']);
                    self::optionalStrings($parameter, ['type', 'docType']);
                    self::booleans($parameter, ['optional', 'variadic']);
                }
            }
        }
        /** @var list<ClassSpec> $classes Validated structurally above; field semantics are validated during AST creation. */
        $classes = $value['classes'];
        return ['root' => $value['root'], 'classes' => $classes];
    }

    /**
     * @param array<array-key,mixed> $value
     * @param list<string> $keys

     * @psalm-pure
     */
    private static function strings(array $value, array $keys): void
    {
        foreach ($keys as $key) {
            if (!is_string($value[$key] ?? null) || $value[$key] === '') {
                throw new InvalidArgumentException($key . ' must be a nonempty string');
            }
        }
    }

    /**
     * @param array<array-key,mixed> $value
     * @param list<string> $keys

     * @psalm-pure
     */
    private static function optionalStrings(array $value, array $keys): void
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $value) && !is_string($value[$key])) {
                throw new InvalidArgumentException($key . ' must be a string');
            }
        }
    }

    /**
     * @param array<array-key,mixed> $value
     * @param list<string> $keys

     * @psalm-pure
     */
    private static function booleans(array $value, array $keys): void
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $value) && !is_bool($value[$key])) {
                throw new InvalidArgumentException($key . ' must be boolean');
            }
        }
    }

    /** @param array<array-key,mixed> $value
     * @psalm-pure
     */
    private static function templates(array $value): void
    {
        $templates = $value['templates'] ?? [];
        if (!is_array($templates) || !array_is_list($templates)) {
            throw new InvalidArgumentException('templates must be a list');
        }
        foreach ($templates as $template) {
            if (!is_array($template)) {
                throw new InvalidArgumentException('Template must be an object');
            }
            self::strings($template, ['name']);
            self::optionalStrings($template, ['constraint', 'default']);
        }
    }

    /** @param array<array-key,mixed> $value
     * @psalm-pure
     */
    private static function lines(array $value): void
    {
        if (!array_key_exists('generatedDocLines', $value)) {
            return;
        }
        if (!is_array($value['generatedDocLines']) || !array_is_list($value['generatedDocLines'])) {
            throw new InvalidArgumentException('generatedDocLines must be a list of strings');
        }
        foreach ($value['generatedDocLines'] as $line) {
            if (!is_string($line)) {
                throw new InvalidArgumentException('generatedDocLines must be a list of strings');
            }
        }
    }
}
