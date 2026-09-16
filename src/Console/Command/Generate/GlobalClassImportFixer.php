<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Console\Command\Generate;

use Override;
use PhpCsFixer\Fixer\FixerInterface;
use PhpCsFixer\Fixer\Import\GlobalNamespaceImportFixer;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use SplFileInfo;

/** Protect PHPDoc string literals from the upstream global-import type parser. */
final class GlobalClassImportFixer implements FixerInterface
{
    private FixerInterface $delegate;

    public function __construct()
    {
        $delegate = new GlobalNamespaceImportFixer();
        $delegate->configure(['import_classes' => true]);
        $this->delegate = $delegate;
    }

    /** @psalm-pure */
    #[Override]
    public function getName(): string
    {
        return 'Puphpeteer/global_class_import';
    }

    #[Override]
    public function getDefinition(): FixerDefinitionInterface
    {
        return $this->delegate->getDefinition();
    }

    #[Override]
    public function getPriority(): int
    {
        return $this->delegate->getPriority();
    }

    #[Override]
    public function isCandidate(Tokens $tokens): bool
    {
        return $this->delegate->isCandidate($tokens);
    }

    #[Override]
    public function supports(SplFileInfo $file): bool
    {
        return $this->delegate->supports($file);
    }

    #[Override]
    public function isRisky(): bool
    {
        return $this->delegate->isRisky();
    }

    #[Override]
    public function fix(SplFileInfo $file, Tokens $tokens): void
    {
        $literals = [];
        foreach ($tokens as $index => $token) {
            if (!$token->isGivenKind(T_DOC_COMMENT)) {
                continue;
            }
            $content = preg_replace_callback(
                <<<'REGEX'
                ~(["'])(?:\\.|(?!\1)[^\\])*\1~s
                REGEX,
                static function (array $match) use (&$literals): string {
                    $key = "'PuphpeteerLiteral" . count($literals) . "'";
                    $literals[$key] = $match[0];

                    return $key;
                },
                $token->getContent(),
            );
            $tokens[$index] = new Token([T_DOC_COMMENT, is_string($content) ? $content : $token->getContent()]);
        }
        try {
            $this->delegate->fix($file, $tokens);
        } finally {
            foreach ($tokens as $index => $token) {
                if ($token->isGivenKind(T_DOC_COMMENT)) {
                    $tokens[$index] = new Token([T_DOC_COMMENT, strtr($token->getContent(), $literals)]);
                }
            }
        }
    }
}
