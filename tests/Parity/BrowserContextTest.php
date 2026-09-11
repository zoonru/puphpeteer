<?php

/** Assertions ported from Puppeteer. Copyright Google Inc.
 * SPDX-License-Identifier: Apache-2.0
 * See tests/Support/assets/LICENSE.
 */

declare(strict_types=1);

use Nesk\Puphpeteer\BrowserContext;

require_once __DIR__ . '/../Support/BrowserTestCase.php';

require_once __DIR__ . '/../Support/ContextAssertions.php';

final class BrowserContextTest extends BrowserTestCase
{
    use ContextAssertions;

    /**
     * Upstream scenario: test/src/browsercontext.spec.ts::BrowserContext > should create new context
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/browsercontext.spec.ts#L38 Upstream test
     */
    public function testShouldCreateNewContext(): void
    {
        $before = $this->contextIds();
        $context = $this->browser->createBrowserContext()->await();
        try {
            self::assertCount(count($before) + 1, $this->contextIds());
            self::assertContains($this->contextId($context), $this->contextIds());
        } finally {
            $context->close()->await();
        }
        self::assertSame($before, $this->contextIds());
    }

    /**
     * Upstream scenario: test/src/browsercontext.spec.ts::BrowserContext > should close all belonging targets once closing context
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/browsercontext.spec.ts#L51 Upstream test
     */
    public function testShouldCloseAllBelongingTargetsOnceClosingContext(): void
    {
        $before = count($this->targets());
        $context = $this->browser->createBrowserContext()->await();
        try {
            $page = $context->newPage()->await();
            self::assertCount($before + 1, $this->targets());
            self::assertCount(1, $this->targets($this->contextId($context)));
        } finally {
            $context->close()->await();
        }
        self::assertCount($before, $this->targets());
        self::assertCount(0, $this->targets($this->contextId($context)));
    }

    /**
     * Upstream scenario: test/src/browsercontext.spec.ts::BrowserContext > should not be able to close default context
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/browsercontext.spec.ts#L24 Upstream test
     */
    public function testShouldNotBeAbleToCloseDefaultContext(): void
    {
        $context = new BrowserContext($this->browserConnection(), '');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be closed');
        $context->close()->await();
    }

    /**
     * Upstream scenario: test/src/browsercontext.spec.ts::BrowserContext > should isolate localStorage and cookies
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/browsercontext.spec.ts#L155 Upstream test
     */
    public function testShouldIsolateLocalStorageAndCookies(): void
    {
        $before = $this->contextIds();
        $contexts = [];
        try {
            $contexts[] = $first = $this->browser->createBrowserContext()->await();
            $contexts[] = $second = $this->browser->createBrowserContext()->await();
            self::assertCount(0, $this->targets($this->contextId($first)));
            self::assertCount(0, $this->targets($this->contextId($second)));
            $page1 = $first->newPage()->await();
            $page1->goto($this->server->url('/empty.html'))->await();
            $page1->evaluate("() => {localStorage.setItem('name', 'page1'); document.cookie = 'name=page1';}")->await();
            self::assertCount(1, $this->targets($this->contextId($first)));
            self::assertCount(0, $this->targets($this->contextId($second)));
            $page2 = $second->newPage()->await();
            $page2->goto($this->server->url('/empty.html'))->await();
            $page2->evaluate("() => {localStorage.setItem('name', 'page2'); document.cookie = 'name=page2';}")->await();
            self::assertSame([$this->targetId($page1)], array_column($this->targets($this->contextId($first)), 'targetId'));
            self::assertSame([$this->targetId($page2)], array_column($this->targets($this->contextId($second)), 'targetId'));
            self::assertSame('page1', $page1->evaluate("() => localStorage.getItem('name')")->await());
            self::assertSame('name=page1', $page1->evaluate('() => document.cookie')->await());
            self::assertSame('page2', $page2->evaluate("() => localStorage.getItem('name')")->await());
            self::assertSame('name=page2', $page2->evaluate('() => document.cookie')->await());
        } finally {
            \Amp\Future\await(array_map(static fn (BrowserContext $context) => $context->close(), $contexts));
        }
        self::assertSame($before, $this->contextIds());
    }

}
