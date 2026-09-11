<?php

/** Assertions ported from Puppeteer. Copyright Google Inc.
 * SPDX-License-Identifier: Apache-2.0
 * See tests/Support/assets/LICENSE.
 */

declare(strict_types=1);


require_once __DIR__ . '/../Support/BrowserTestCase.php';

require_once __DIR__ . '/../Support/ContextAssertions.php';

final class PageCreationTest extends BrowserTestCase
{
    use ContextAssertions;

    /**
     * Upstream scenario: test/src/page.spec.ts::Page > Page.newPage > should open pages in a new window
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/page.spec.ts#L35 Upstream test
     */
    public function testPageNewPageShouldOpenPagesInANewWindow(): void
    {
        $page = $this->context->newPage(['type' => 'window'])->await();
        self::assertSame('about:blank', $page->evaluate('() => location.href')->await());
        self::assertContains($this->targetId($page), array_column($this->targets(), 'targetId'));
        self::assertSame($this->contextId($this->context), $this->targetInfo($page)['browserContextId']);
    }

    /**
     * Upstream scenario: test/src/page.spec.ts::Page > Page.newPage > should open pages in a new window at the specified position
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/page.spec.ts#L45 Upstream test
     */
    public function testPageNewPageShouldOpenPagesInANewWindowAtTheSpecifiedPosition(): void
    {
        $page = $this->context->newPage([
            'type' => 'window',
            'windowBounds' => ['left' => 50, 'top' => 50, 'width' => 750, 'height' => 550],
        ])->await();
        self::assertContains($this->targetId($page), array_column($this->targets(), 'targetId'));
        self::assertSame($this->contextId($this->context), $this->targetInfo($page)['browserContextId']);
        self::assertSame(['width' => 750, 'height' => 550], $page->evaluate('() => ({width: outerWidth, height: outerHeight})')->await());
    }

    /**
     * Upstream scenario: test/src/page.spec.ts::Page > Page.newPage > should open pages in a new window in maximized state
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/page.spec.ts#L63 Upstream test
     */
    public function testPageNewPageShouldOpenPagesInANewWindowInMaximizedState(): void
    {
        $page = $this->context->newPage([
            'type' => 'window', 'windowBounds' => ['windowState' => 'maximized'],
        ])->await();
        self::assertContains($this->targetId($page), array_column($this->targets(), 'targetId'));
        self::assertSame($this->contextId($this->context), $this->targetInfo($page)['browserContextId']);
        // Upstream's 800x600 is the Linux headless screen. macOS uses the host display.
        $size = $page->evaluate('() => ({width: outerWidth, height: outerHeight, availableWidth: screen.availWidth, availableHeight: screen.availHeight})')->await();
        $window = $this->browserConnection()->send('Browser.getWindowForTarget', ['targetId' => $this->targetId($page)])->await();
        self::assertSame('maximized', $window['bounds']['windowState']);
        self::assertSame($window['bounds']['width'], $size['width']);
        self::assertSame($window['bounds']['height'], $size['height']);
        self::assertGreaterThanOrEqual($size['availableWidth'], $size['width']);
        self::assertGreaterThanOrEqual($size['availableHeight'], $size['height']);
    }

    /**
     * Upstream scenario: test/src/page.spec.ts::Page > Page.newPage > should create a background page
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/page.spec.ts#L82 Upstream test
     */
    public function testPageNewPageShouldCreateABackgroundPage(): void
    {
        $page = $this->context->newPage(['background' => true])->await();
        self::assertSame('hidden', $page->evaluate('() => document.visibilityState')->await());
    }

}
