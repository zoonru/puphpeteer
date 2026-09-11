<?php

declare(strict_types=1);

namespace Zoon\Puphpeteer\Tooling;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Reads inherited declarations and PHP import aliases without executing client code. */
final class SourceIndex
{
    /** @var array<string,array{class:Stmt\Class_,path:string}> */
    private array $classes = [];

    /** @param list<string> $paths */
    public function __construct(string $root, array $paths)
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        foreach ($paths as $path) {
            if (!is_file($root . '/' . $path)) {
                continue;
            }
            $code = file_get_contents($root . '/' . $path);
            if ($code === false) {
                throw new \RuntimeException('Cannot read ' . $path);
            }
            try {
                $nodes = $parser->parse($code) ?? [];
                $nodes = (new NodeTraverser(new NameResolver()))->traverse($nodes);
                foreach ((new NodeFinder())->findInstanceOf($nodes, Stmt\Class_::class) as $class) {
                    if ($class->name === null) {
                        continue;
                    }
                    $name = $class->namespacedName?->toString() ?? $class->name->name;
                    $this->classes[strtolower($name)] = ['class' => $class, 'path' => $path];
                }
            } catch (\PhpParser\Error) {
                // Synchronizer reports syntax errors separately, while preserving the original file.
            }
        }
    }

    /** Traits may provide or adapt members; unresolved composition must never be shadowed by a stub. */
    public function hasTraitComposition(string $fqcn): bool
    {
        $visited = [];
        while (isset($this->classes[strtolower(trim($fqcn, '\\'))])) {
            $key = strtolower(trim($fqcn, '\\'));
            if (isset($visited[$key])) {
                return false;
            }
            $visited[$key] = true;
            $class = $this->classes[$key]['class'];
            foreach ($class->stmts as $statement) {
                if ($statement instanceof Stmt\TraitUse) {
                    return true;
                }
            }
            if ($class->extends === null) {
                return false;
            }
            $fqcn = $class->extends->toString();
        }
        return false;
    }

    /** @return array{member:Stmt\ClassMethod|Stmt\Property,path:string,inherited:bool}|null */
    public function findMember(string $fqcn, string $name, bool $property): ?array
    {
        $visited = [];
        $inherited = false;
        while (isset($this->classes[strtolower(trim($fqcn, '\\'))])) {
            $key = strtolower(trim($fqcn, '\\'));
            if (isset($visited[$key])) {
                return null;
            }
            $visited[$key] = true;
            $entry = $this->classes[$key];
            $class = $entry['class'];
            $member = $property ? $class->getProperty($name) : $class->getMethod($name);
            if ($member !== null && (!$inherited || $member->isPublic())) {
                return ['member' => $member, 'path' => $entry['path'], 'inherited' => $inherited];
            }
            if ($class->extends === null) {
                return null;
            }
            $fqcn = $class->extends->toString();
            $inherited = true;
        }
        return null;
    }
}
