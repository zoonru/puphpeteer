<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Internal;

use Amp\Future;
use Nesk\Puphpeteer\Value\BigInt;
use Nesk\Puphpeteer\Value\UndefinedValue;
use function Amp\async;

/** CDP evaluation and the PHP / JavaScript value boundary.
 * @internal
 * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/packages/puppeteer-core/src/cdp/ExecutionContext.ts
 */
final class Evaluation
{
    /** @param list<mixed> $args @return Future<mixed> */
    public static function run(Session $session, int $contextId, string $source, array $args): Future
    {
        return async(static function () use ($session, $contextId, $source, $args): mixed {
            $function = self::functionSource($source);
            $params = ['returnByValue' => true, 'awaitPromise' => true, 'userGesture' => true];
            if ($function !== null) {
                $method = 'Runtime.callFunctionOn';
                $params['executionContextId'] = $contextId;
                $params['functionDeclaration'] = self::sourceUrl($function);
                $params['arguments'] = array_map(static function (mixed $arg) use ($session, $contextId): array|\stdClass {
                    if ($arg instanceof RemoteObject) {
                        if ($arg->session !== $session || $arg->contextId !== $contextId) {
                            throw new \InvalidArgumentException('JSHandles can be evaluated only in the context they were created!');
                        }
                        if ($arg->isDisposed()) {
                            throw new \InvalidArgumentException('JSHandle is disposed!');
                        }
                        return ['objectId' => $arg->objectId];
                    }
                    return self::argument($arg);
                }, $args);
            } else {
                $method = 'Runtime.evaluate';
                $params['contextId'] = $contextId;
                $params['expression'] = self::sourceUrl($source);
            }
            try {
                $response = $session->send($method, $params)->await();
            } catch (ProtocolException $error) {
                if (str_contains($error->getMessage(), 'Object reference chain is too long')
                    || str_contains($error->getMessage(), "Object couldn't be returned by value")) {
                    return UndefinedValue::Value;
                }
                if (str_contains($error->getMessage(), 'Cannot find context with specified id')
                    || str_contains($error->getMessage(), 'Inspected target navigated or closed')) {
                    throw new EvaluationException('Execution context was destroyed, most likely because of a navigation.');
                }
                throw $error;
            }
            if (isset($response['exceptionDetails']) && is_array($response['exceptionDetails'])) {
                throw self::exception($response['exceptionDetails']);
            }
            if (!isset($response['result']) || !is_array($response['result'])) {
                throw new \UnexpectedValueException('Runtime evaluation response has no result');
            }
            return self::value($response['result']);
        });
    }

    /** Function source is a PHP convenience; other strings retain expression semantics. */
    private static function functionSource(string $source): ?string
    {
        $source = trim($source);
        if (preg_match('/^(?:async\s+)?function\b/', $source)
            || preg_match('/^(?:async\s+)?(?:[\w$]+|\([\s\S]*?\))\s*=>/', $source)) {
            return $source;
        }
        // Method shorthand from an object literal needs the function keyword in CDP.
        if (preg_match('/^(async\s+)?([\w$]+\s*\([^)]*\)\s*\{)/', $source, $matches)) {
            $async = $matches[1] ?? '';
            return $async . 'function ' . substr($source, strlen($async));
        }
        return null;
    }

    private static function sourceUrl(string $source): string
    {
        return preg_match('/^[\t ]*\/\/[#@] sourceURL=/m', $source)
            ? $source : $source . "\n//# sourceURL=pptr:internal\n";
    }

    /** @return array<string,mixed>|\stdClass */
    private static function argument(mixed $value): array|\stdClass
    {
        if ($value === UndefinedValue::Value) {
            return new \stdClass();
        }
        if ($value instanceof BigInt) {
            return ['unserializableValue' => $value->value . 'n'];
        }
        if (is_float($value)) {
            $special = match (true) {
                is_nan($value) => 'NaN',
                $value === INF => 'Infinity',
                $value === -INF => '-Infinity',
                $value === 0.0 && fdiv(1.0, $value) === -INF => '-0',
                default => null,
            };
            if ($special !== null) {
                return ['unserializableValue' => $special];
            }
        }
        return ['value' => self::jsonValue($value)];
    }

    /** Match JSON.stringify for nested values; reject cycles and unsupported PHP values. */
    private static function jsonValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 512) {
            throw new \InvalidArgumentException('Recursive objects are not allowed, or argument nesting exceeds 512 levels.');
        }
        if ($value instanceof BigInt) {
            throw new \InvalidArgumentException('BigInt can only be passed as a top-level argument');
        }
        if ($value === UndefinedValue::Value || (is_float($value) && !is_finite($value))) {
            return null;
        }
        if ($value instanceof \JsonSerializable) {
            return self::jsonValue($value->jsonSerialize(), $depth + 1);
        }
        if (is_object($value)) {
            if (!$value instanceof \stdClass) {
                throw new \InvalidArgumentException('Evaluation arguments must be JSON values, stdClass, or JsonSerializable');
            }
            $properties = [];
            foreach (get_object_vars($value) as $key => $item) {
                if ($item !== UndefinedValue::Value) {
                    $properties[$key] = self::jsonValue($item, $depth + 1);
                }
            }
            return (object) $properties;
        }
        if (is_array($value)) {
            $result = [];
            $list = array_is_list($value);
            foreach ($value as $key => $item) {
                if ($list || $item !== UndefinedValue::Value) {
                    $result[$key] = self::jsonValue($item, $depth + 1);
                }
            }
            return $list ? $result : (object) $result;
        }
        if (is_resource($value)) {
            throw new \InvalidArgumentException('Resources cannot be passed to JavaScript');
        }
        return $value;
    }

    /** @param array<array-key,mixed> $remote */
    private static function value(array $remote): mixed
    {
        $special = $remote['unserializableValue'] ?? null;
        if (is_string($special)) {
            if (($remote['type'] ?? null) === 'bigint') {
                return new BigInt(substr($special, 0, -1));
            }
            return match ($special) {
                '-0' => -0.0, 'NaN' => NAN, 'Infinity' => INF, '-Infinity' => -INF,
                default => throw new \UnexpectedValueException('Unsupported JavaScript value: ' . $special),
            };
        }
        return array_key_exists('value', $remote) ? $remote['value'] : UndefinedValue::Value;
    }

    /** @param array<array-key,mixed> $details */
    private static function exception(array $details): EvaluationException
    {
        $remote = $details['exception'] ?? null;
        $text = is_string($details['text'] ?? null) ? $details['text'] : 'JavaScript evaluation failed';
        if (!is_array($remote)) {
            return new EvaluationException($text);
        }
        $value = self::value($remote);
        $description = $remote['description'] ?? null;
        $name = is_string($remote['className'] ?? null) ? $remote['className'] : 'Error';
        if (is_string($description)) {
            $message = explode("\n    at ", $description, 2)[0];
            if (str_starts_with($message, $name . ': ')) {
                $message = substr($message, strlen($name) + 2);
            }
            return new EvaluationException($message, $name, $value, $description);
        }
        $message = match (true) {
            $value === UndefinedValue::Value => 'undefined',
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value), $value instanceof \Stringable => (string) $value,
            default => $text,
        };
        return new EvaluationException($message, $name, $value);
    }
}
