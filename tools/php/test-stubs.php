<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

$input = stream_get_contents(STDIN);
if ($input === false) {
    throw new RuntimeException('Cannot read test synchronization input');
}

/** @var list<array{path:string,source:?string,template:string,trusted:list<string>}> $requests */
$requests = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
$parser = (new ParserFactory())->createForNewestSupportedVersion();
$printer = new Standard();
$finder = new NodeFinder();
$result = [];
foreach ($requests as $request) {
    $original = $request['source'];
    $old = $parser->parse($original ?? $request['template']) ?? [];
    $tokens = $parser->getTokens();
    $cloner = new NodeTraverser(new CloningVisitor());
    $nodes = $cloner->traverse($old);
    $expectedName = basename($request['path'], '.php');
    $class = $finder->findFirst($nodes, static fn(\PhpParser\Node $node): bool => $node instanceof Class_ && $node->name?->toString() === $expectedName);
    if (!$class instanceof Class_) {
        throw new RuntimeException('Expected a PHP test class in ' . $request['path']);
    }
    $template = $parser->parse($request['template']) ?? [];
    $methods = $finder->findInstanceOf($template, ClassMethod::class);
    $changed = false;
    foreach ($methods as $method) {
        $name = $method->name->toString();
        $existing = $class->getMethod($name);
        if ($existing === null) {
            if ($class->getTraitUses() !== []) {
                throw new RuntimeException('Cannot append a test method before reviewing trait methods: ' . $request['path'] . '::' . $name);
            }
            $class->stmts[] = $method;
            $changed = true;
        } else {
            if (!in_array($name, $request['trusted'], true) && $existing->getDocComment()?->getText() !== $method->getDocComment()?->getText()) {
                throw new RuntimeException('Existing method has no matching upstream scenario: ' . $request['path'] . '::' . $name);
            }

            $templateDoc = $method->getDocComment()?->getText() ?? '';
            if (preg_match('~@see (https://github\.com/\S+) Upstream test~', $templateDoc, $match)) {
                $doc = $existing->getDocComment()?->getText() ?? '/** */';
                $line = '@see ' . $match[1] . ' Upstream test';
                $updated = preg_match('~@see https://github\.com/\S+ Upstream test~', $doc)
                    ? preg_replace('~@see https://github\.com/\S+ Upstream test~', $line, $doc)
                    : preg_replace('~\s*\*/$~', "\n * $line\n */", $doc);
                if ($updated !== null && $updated !== $doc) {
                    $existing->setDocComment(new \PhpParser\Comment\Doc($updated));
                    $changed = true;
                }
            }

        }
    }
    $result[] = ['path' => $request['path'], 'content' => $original === null ? $request['template'] : ($changed ? $printer->printFormatPreserving($nodes, $old, $tokens) : null)];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
