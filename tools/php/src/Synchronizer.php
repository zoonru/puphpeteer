<?php

declare(strict_types=1);

namespace Zoon\Puphpeteer\Tooling;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use RuntimeException;

/**
 * Synchronizes declarations without loading client classes or executing their code.
 * Returned files are proposals: writing belongs to the updater.
 *
 * @psalm-type Parameter = array{name:string,type?:string,docType?:string,optional?:bool,default?:mixed,variadic?:bool}
 * @psalm-type Template = array{name:string,constraint?:string,default?:string}
 * @psalm-type Member = array{id:string,name:string,kind:string,static?:bool,parameters?:list<Parameter>,returnType?:string,returnDocType?:string,type?:string,docType?:string,generatedDocLines?:list<string>,templates?:list<Template>}
 * @psalm-type ClassSpec = array{name:string,fqcn:string,file:string,members:list<Member>,generatedDocLines?:list<string>,templates?:list<Template>}
 * @psalm-type Diagnostic = array{code:string,componentId:string,symbolId:string,phpPath:string,phpLine:int,expected:mixed,actual:mixed}
 */
final class Synchronizer
{
    private Parser $parser;
    private Standard $printer;
    /** @var list<Diagnostic> */
    private array $diagnostics = [];
    private ?SourceIndex $sourceIndex = null;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->printer = new Standard();
    }

    /**
     * @param list<ClassSpec> $classes
     * @param list<string> $knownMemberIds
     * @return array{diagnostics:list<Diagnostic>,files:list<array{path:string,content:string,changed:bool}>}
     */
    public function synchronize(string $root, array $classes, array $knownMemberIds): array
    {
        $this->diagnostics = [];
        $root = realpath($root);
        if ($root === false) {
            throw new RuntimeException('Project root does not exist');
        }
        $files = [];
        $seen = [];
        $destinations = [];
        foreach ($classes as $spec) {
            foreach (['file:' . strtolower($spec['file']), 'class:' . strtolower($spec['fqcn'])] as $key) {
                $destinations[$key] = ($destinations[$key] ?? 0) + 1;
            }
        }
        $sourcePaths = array_column($classes, 'file');
        if (is_dir($root . '/src')) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $sourcePaths[] = substr($file->getPathname(), strlen(rtrim($root, '/')) + 1);
                }
            }
        }
        foreach ($sourcePaths as $relative) {
            $this->path($root, $relative);
        }
        $this->sourceIndex = new SourceIndex($root, array_values(array_unique($sourcePaths)));
        foreach ($classes as $spec) {
            $relative = $spec['file'];
            $path = $this->path($root, $relative);
            if ($destinations['file:' . strtolower($relative)] > 1 || $destinations['class:' . strtolower($spec['fqcn'])] > 1) {
                $this->report('contract.conflict', $spec['name'], $relative, 0, 'unique class and file mapping', $spec['fqcn']);
                continue;
            }
            $seen[$relative] = true;
            $source = is_file($path) ? file_get_contents($path) : false;
            try {
                $content = $this->classFile($spec, $source === false ? null : $source);
                if ($content !== null) {
                    $files[] = ['path' => $relative, 'content' => $content, 'changed' => $content !== $source];
                }
            } catch (\PhpParser\Error $error) {
                $this->report('contract.conflict', $spec['name'], $relative, $error->getStartLine(), 'parseable PHP declaration', $error->getMessage());
            }
        }
        // Old tracked declarations are retained, even after their whole class disappears.
        foreach (array_unique($sourcePaths) as $relative) {
            $path = $this->path($root, $relative);
            if (!is_file($path)) {
                continue;
            }
            $source = file_get_contents($path);
            if ($source === false) {
                throw new RuntimeException('Cannot read ' . $path);
            }
            try {
                $nodes = $this->parser->parse($source) ?? [];
                $members = (new NodeFinder())->find($nodes, static fn(Node $node): bool => $node instanceof Stmt\ClassMethod || $node instanceof Stmt\Property);
                foreach ($members as $member) {
                    $id = $this->trackedId($member);
                    if ($id !== null && !in_array($id, $knownMemberIds, true)) {
                        $this->report('contract.obsolete', $id, $relative, $member->getStartLine(), null, 'retained declaration');
                    }
                }
            } catch (\PhpParser\Error $error) {
                if (!isset($seen[$relative])) {
                    $this->report('contract.conflict', '', $relative, $error->getStartLine(), 'parseable PHP', $error->getMessage());
                }
            }
        }
        usort($this->diagnostics, static fn(array $a, array $b): int => [$a['phpPath'], $a['symbolId'], $a['code']] <=> [$b['phpPath'], $b['symbolId'], $b['code']]);
        usort($files, static fn(array $a, array $b): int => $a['path'] <=> $b['path']);
        return ['diagnostics' => $this->diagnostics, 'files' => $files];
    }

    /** @param ClassSpec $spec */
    private function classFile(array $spec, ?string $source): ?string
    {
        $fqcn = trim($spec['fqcn'], '\\');
        $parts = explode('\\', $fqcn);
        $shortName = array_pop($parts);
        $this->identifier($shortName);
        foreach ($parts as $part) {
            $this->identifier($part);
        }
        $namespace = implode('\\', $parts);
        if ($source === null) {
            $source = "<?php\n\ndeclare(strict_types=1);\n\n" . ($namespace === '' ? '' : "namespace $namespace;\n\n") . "class $shortName\n{\n}\n";
        }
        $old = $this->parser->parse($source) ?? [];
        $tokens = $this->parser->getTokens();
        $nodes = (new NodeTraverser(new CloningVisitor()))->traverse($old);
        $classes = [];
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Namespace_) {
                foreach ($node->stmts as $statement) {
                    if ($statement instanceof Stmt\Class_ && $statement->name !== null) {
                        $classes[($node->name === null ? '' : $node->name->toString() . '\\') . $statement->name->name] = $statement;
                    }
                }
            } elseif ($node instanceof Stmt\Class_ && $node->name !== null) {
                $classes[$node->name->name] = $node;
            }
        }
        $class = $classes[$fqcn] ?? null;
        if ($class === null) {
            $this->report('contract.conflict', $spec['name'], $spec['file'], 0, $fqcn, array_keys($classes));
            return null;
        }
        $classLines = array_merge($this->templateLines($spec['templates'] ?? []), $spec['generatedDocLines'] ?? []);
        $hasClassMetadata = isset($spec['templates']) || isset($spec['generatedDocLines']) || $this->trackedId($class) !== null;
        if ($hasClassMetadata) {
            $this->updateDoc($class, 'class:' . $spec['name'], $classLines);
        }
        $mappedNames = [];
        foreach ($spec['members'] as $member) {
            $key = ($member['kind'] === 'property' ? '$' . $member['name'] : strtolower($member['name']));
            $mappedNames[$key] = ($mappedNames[$key] ?? 0) + 1;
        }
        foreach ($spec['members'] as $member) {
            $key = ($member['kind'] === 'property' ? '$' . $member['name'] : strtolower($member['name']));
            if ($mappedNames[$key] > 1) {
                $this->report('contract.conflict', $member['id'], $spec['file'], 0, 'unique PHP member mapping', $member['name']);
                continue;
            }
            try {
                $expected = $this->declaration($member);
            } catch (UnrepresentableDeclaration $error) {
                $this->report('contract.unsupported', $member['id'], $spec['file'], 0, 'unambiguous PHP declaration', $error->getMessage());
                continue;
            }
            if ($expected === null) {
                $this->report('contract.unsupported', $member['id'], $spec['file'], 0, 'method or property', $member['kind']);
                continue;
            }
            $current = $member['kind'] === 'property' ? $class->getProperty($member['name']) : $class->getMethod($member['name']);
            if ($current === null && $this->sourceIndex?->hasTraitComposition($fqcn)) {
                $this->report('contract.conflict', $member['id'], $spec['file'], $class->getStartLine(), 'resolved PHP trait composition', 'a trait may already provide this member');
                continue;
            }
            $resolved = $this->sourceIndex?->findMember($fqcn, $member['name'], $member['kind'] === 'property');
            if ($current === null && $resolved !== null && $resolved['inherited']) {
                $inherited = $resolved['member'];
                if ($this->signature($inherited) !== $this->signature($expected)) {
                    $this->report('contract.conflict', $member['id'], $resolved['path'], $inherited->getStartLine(), $this->signature($expected), $this->signature($inherited));
                }
                foreach ($this->docLines($member) as $line) {
                    if ($this->requiresAnnotation($line, $member) && !str_contains($inherited->getDocComment()?->getText() ?? '', $line)) {
                        $this->report('contract.annotations', $member['id'], $resolved['path'], $inherited->getStartLine(), $line, 'missing inherited annotation');
                    }
                }
                continue;
            }
            if ($current === null) {
                $class->stmts[] = $expected;
                continue;
            }
            $tracked = $this->trackedId($current);
            if ($tracked !== $member['id']) {
                $this->report('contract.conflict', $member['id'], $spec['file'], $current->getStartLine(), 'matching upstream-id marker', $tracked);
                continue;
            }
            if ($current instanceof Stmt\Property && count($current->props) !== 1) {
                $this->report('contract.conflict', $member['id'], $spec['file'], $current->getStartLine(), 'one property per declaration', count($current->props));
                continue;
            }
            if ($this->signature($resolved['member'] ?? $current) !== $this->signature($expected)) {
                if ($current instanceof Stmt\ClassMethod && $expected instanceof Stmt\ClassMethod) {
                    // Bodies, attributes and manual comments remain attached to the original node.
                    $parameters = $expected->params;
                    $originalParameters = [];
                    foreach ($current->params as $parameter) {
                        if ($parameter->var instanceof Node\Expr\Variable && is_string($parameter->var->name)) {
                            $originalParameters[$parameter->var->name] = $parameter;
                        }
                    }
                    foreach ($parameters as $parameter) {
                        $name = $parameter->var instanceof Node\Expr\Variable ? $parameter->var->name : null;
                        if (is_string($name) && isset($originalParameters[$name])) {
                            $original = $originalParameters[$name];
                            $parameter->attrGroups = $original->attrGroups;
                            $parameter->flags = $original->flags;
                            $parameter->setAttribute('comments', $original->getComments());
                            unset($originalParameters[$name]);
                        }
                    }
                    $promoted = array_filter($originalParameters, static fn(Node\Param $parameter): bool => $parameter->flags !== 0);
                    if ($promoted !== []) {
                        $this->report('contract.conflict', $member['id'], $spec['file'], $current->getStartLine(), 'manual migration of renamed/removed promoted properties', array_keys($promoted));
                        continue;
                    }
                    $current->params = $parameters;
                    $current->returnType = $expected->returnType;
                    $current->flags = ($current->flags & ~(\PhpParser\Modifiers::VISIBILITY_MASK | \PhpParser\Modifiers::STATIC)) | $expected->flags;
                    $current->byRef = $expected->byRef;
                } elseif ($current instanceof Stmt\Property && $expected instanceof Stmt\Property) {
                    $current->type = $expected->type;
                    $current->flags = ($current->flags & ~(\PhpParser\Modifiers::VISIBILITY_MASK | \PhpParser\Modifiers::STATIC)) | $expected->flags;
                }
            }
            $lines = $this->docLines($member);
            $this->updateDoc($current, $member['id'], $lines);
        }
        $content = $this->printer->printFormatPreserving($nodes, $old, $tokens);
        $this->parser->parse($content);
        return $this->lint($content, $spec) ? $content : null;
    }

    /** @param Member $member */
    private function declaration(array $member): Stmt\ClassMethod|Stmt\Property|null
    {
        $this->identifier($member['name']);
        $static = ($member['static'] ?? false) ? 'static ' : '';
        if ($member['kind'] === 'property') {
            $type = $this->type($member['type'] ?? 'mixed');
            $declaration = "public {$static}$type \${$member['name']};";
        } elseif ($member['kind'] === 'method' || $member['kind'] === 'constructor') {
            $parameters = [];
            foreach ($member['parameters'] ?? [] as $parameter) {
                $this->identifier($parameter['name']);
                $text = $this->type($parameter['type'] ?? 'mixed') . ' ' . (($parameter['variadic'] ?? false) ? '...' : '') . '$' . $parameter['name'];
                if (array_key_exists('default', $parameter)) {
                    if (!$this->literal($parameter['default'])) {
                        throw new RuntimeException('Defaults must contain only JSON literals');
                    }
                    $text .= ' = ' . var_export($parameter['default'], true);
                } elseif (($parameter['optional'] ?? false) && !($parameter['variadic'] ?? false)) {
                    throw new UnrepresentableDeclaration('Optional parameter needs an explicit default: ' . $member['id'] . '.' . $parameter['name']);
                }
                $parameters[] = $text;
            }
            $returns = $member['name'] === '__construct' ? '' : ': ' . $this->type($member['returnType'] ?? 'mixed');
            $message = var_export('NotImplemented: ' . $member['id'], true);
            $declaration = 'public ' . $static . 'function ' . $member['name'] . '(' . implode(', ', $parameters) . ")$returns { throw new \\LogicException($message); }";
        } else {
            return null;
        }
        $nodes = $this->parser->parse('<?php class GeneratedDeclaration { ' . $declaration . ' }');
        if ($nodes === null || !$nodes[0] instanceof Stmt\Class_) {
            throw new RuntimeException('Cannot construct declaration');
        }
        $node = $nodes[0]->stmts[0];
        if (!$node instanceof Stmt\ClassMethod && !$node instanceof Stmt\Property) {
            throw new RuntimeException('Unexpected declaration');
        }
        $this->updateDoc($node, $member['id'], $this->docLines($member));
        return $node;
    }

    /**
     * @param Member $member
     * @return list<string>
     */
    private function docLines(array $member): array
    {
        $lines = array_merge($this->templateLines($member['templates'] ?? []), $member['generatedDocLines'] ?? []);
        if ($member['kind'] === 'property') {
            $lines[] = '@var ' . ($member['docType'] ?? $member['type'] ?? 'mixed');
        } else {
            foreach ($member['parameters'] ?? [] as $parameter) {
                $lines[] = '@param ' . ($parameter['docType'] ?? $parameter['type'] ?? 'mixed') . ' ' . (($parameter['variadic'] ?? false) ? '...' : '') . '$' . $parameter['name'];
            }
            if ($member['name'] !== '__construct') {
                $lines[] = '@return ' . ($member['returnDocType'] ?? $member['returnType'] ?? 'mixed');
            }
        }
        foreach ($lines as $line) {
            if (str_contains($line, '*/') || str_contains($line, "\n") || str_contains($line, "\r")) {
                throw new RuntimeException('Generated annotations must be single safe lines');
            }
        }
        return $lines;
    }

    /**
     * @param list<Template> $templates
     * @return list<string>
     */
    private function templateLines(array $templates): array
    {
        $lines = [];
        foreach ($templates as $template) {
            $this->identifier($template['name']);
            $line = '@template ' . $template['name'];
            if (isset($template['constraint'])) {
                $line .= ' as ' . $template['constraint'];
            }
            if (isset($template['default'])) {
                $line .= ' = ' . $template['default'];
            }
            if (str_contains($line, '*/') || str_contains($line, "\n") || str_contains($line, "\r")) {
                throw new RuntimeException('Unsafe template annotation');
            }
            $lines[] = $line;
        }
        return $lines;
    }

    private function signature(Stmt\ClassMethod|Stmt\Property $node): string
    {
        $copy = clone $node;
        $copy->setAttribute('comments', []);
        $copy->setAttributes([]);
        $copy->flags &= \PhpParser\Modifiers::VISIBILITY_MASK | \PhpParser\Modifiers::STATIC;
        if ($copy instanceof Stmt\ClassMethod) {
            $copy->stmts = [];
            $copy->attrGroups = [];
            $copy->params = array_map(static function (Node\Param $parameter): Node\Param {
                $copy = clone $parameter;
                $copy->attrGroups = [];
                $copy->flags = 0;
                $copy->setAttribute('comments', []);
                return $copy;
            }, $copy->params);
        } else {
            $copy->props = array_map(static function (Node\PropertyItem $property): Node\PropertyItem {
                $new = clone $property;
                $new->default = null;
                return $new;
            }, $copy->props);
            $copy->attrGroups = [];
        }
        return $this->printer->prettyPrint([$copy]);
    }

    /** @param list<string> $lines */
    private function updateDoc(Node $node, string $id, array $lines): void
    {
        foreach (array_merge([$id], $lines) as $line) {
            if (str_contains($line, '*/') || str_contains($line, "\n") || str_contains($line, "\r")) {
                throw new RuntimeException('Unsafe generated documentation');
            }
        }
        $doc = $node->getDocComment()?->getText() ?? "/**\n */";
        $generated = " * [upstream-generated]\n * upstream-id: $id\n";
        foreach ($lines as $line) {
            $generated .= " * $line\n";
        }
        $generated .= ' * [/upstream-generated]';
        $pattern = '~^[ \t]*\* \[upstream-generated\].*?^[ \t]*\* \[/upstream-generated\]~ms';
        if (preg_match($pattern, $doc)) {
            $doc = preg_replace_callback($pattern, static fn(): string => $generated, $doc) ?? $doc;
        } else {
            $doc = preg_replace('~\s*\*/$~', "\n$generated\n */", $doc) ?? $doc;
        }
        $node->setDocComment(new Doc($doc));
    }

    /** @param Member $member */
    private function requiresAnnotation(string $line, array $member): bool
    {
        if (str_starts_with($line, '@return ')) {
            return ($member['returnDocType'] ?? $member['returnType'] ?? 'mixed') !== ($member['returnType'] ?? 'mixed');
        }
        if (str_starts_with($line, '@param ')) {
            foreach ($member['parameters'] ?? [] as $parameter) {
                if (str_ends_with($line, '$' . $parameter['name'])) {
                    return ($parameter['docType'] ?? $parameter['type'] ?? 'mixed') !== ($parameter['type'] ?? 'mixed');
                }
            }
        }
        if (str_starts_with($line, '@var ')) {
            return ($member['docType'] ?? $member['type'] ?? 'mixed') !== ($member['type'] ?? 'mixed');
        }
        return !str_starts_with($line, '@param ');
    }

    private function generatedDoc(Node $node): string
    {
        $doc = $node->getDocComment()?->getText() ?? '';
        preg_match('~\[upstream-generated\](.*?)\[/upstream-generated\]~s', $doc, $matches);
        return preg_replace('~\s*\*\s?~', "\n", $matches[1] ?? '') ?? '';
    }

    private function trackedId(Node $node): ?string
    {
        preg_match('~upstream-id: ([^\r\n]+)~', $this->generatedDoc($node), $matches);
        return isset($matches[1]) ? trim($matches[1]) : null;
    }

    private function identifier(string $name): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) {
            throw new RuntimeException('Invalid PHP identifier: ' . $name);
        }
    }

    private function type(string $type): string
    {
        if (!preg_match('/^[?(\\\\a-zA-Z_][\\\\a-zA-Z0-9_|&?()]*$/D', $type)) {
            throw new RuntimeException('Invalid PHP type: ' . $type);
        }
        return $type;
    }

    private function literal(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!$this->literal($item)) {
                    return false;
                }
            }
            return true;
        }
        return $value === null || is_scalar($value);
    }

    private function path(string $root, string $relative): string
    {
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\') || in_array('..', explode('/', $relative), true)) {
            throw new RuntimeException('Expected a safe relative PHP path');
        }
        $root = realpath($root);
        if ($root === false) {
            throw new RuntimeException('Project root does not exist');
        }
        $path = $root . '/' . $relative;
        $ancestor = $path;
        while (!file_exists($ancestor) && !is_link($ancestor)) {
            $ancestor = dirname($ancestor);
        }
        $resolved = realpath($ancestor);
        if ($resolved === false || ($resolved !== $root && !str_starts_with($resolved, $root . '/'))) {
            throw new RuntimeException('Mapped file escapes the project root');
        }
        return $path;
    }

    /** @param ClassSpec $spec */
    private function lint(string $content, array $spec): bool
    {
        $path = tempnam(sys_get_temp_dir(), 'puphpeteer-lint-');
        if ($path === false) {
            throw new RuntimeException('Cannot create PHP lint staging file');
        }
        try {
            if (file_put_contents($path, $content) === false) {
                throw new RuntimeException('Cannot write PHP lint staging file');
            }
            $process = proc_open([PHP_BINARY, '-l', $path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('Cannot start PHP syntax checker');
            }
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $code = proc_close($process);
            if ($code !== 0) {
                $this->report('contract.conflict', $spec['name'], $spec['file'], 0, 'PHP syntax accepted by php -l', str_replace($path, $spec['file'], trim($output === false ? '' : $output)));
                return false;
            }
            return true;
        } finally {
            unlink($path);
        }
    }

    private function report(string $code, string $id, string $path, int $line, mixed $expected, mixed $actual): void
    {
        $this->diagnostics[] = ['code' => $code, 'componentId' => 'PhpSynchronizer', 'symbolId' => $id, 'phpPath' => $path, 'phpLine' => max(0, $line), 'expected' => $expected, 'actual' => $actual];
    }
}
