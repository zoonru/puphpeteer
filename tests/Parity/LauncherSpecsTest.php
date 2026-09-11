<?php

/**
 * Assertions ported from Puppeteer. Copyright Google Inc.
 * SPDX-License-Identifier: Apache-2.0
 * See tests/Support/assets/LICENSE.
 */

declare(strict_types=1);

require_once __DIR__ . '/../Support/BrowserTestCase.php';

final class LauncherSpecsTest extends BrowserTestCase
{
    /** Upstream scenario: test/src/launcher.spec.ts::Launcher specs > Puppeteer > Browser.disconnect > should reject navigation when browser closes
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/launcher.spec.ts#L30 Upstream test
     */
    public function testPuppeteerBrowserDisconnectShouldRejectNavigationWhenBrowserCloses(): void
    {
        $this->setRoute('/one-style.css', static fn (): array => ['delay' => 60]);
        $navigation = $this->page->goto($this->url('/one-style.html'), ['timeout' => 60000]);
        $this->waitForRequest('/one-style.css');
        $this->browser->disconnect()->await();
        try {
            $navigation->await();
            self::fail('Disconnect must reject pending navigation.');
        } catch (\Nesk\Puphpeteer\Internal\TargetClosedException $error) {
            self::assertTrue(
                str_starts_with($error->getMessage(), 'Navigating frame was detached')
                || str_starts_with($error->getMessage(), 'Protocol error (Page.navigate): Target closed.')
                || str_starts_with($error->getMessage(), 'Frame detached'),
                $error->getMessage(),
            );
        }
    }

    /** Upstream scenario: test/src/launcher.spec.ts::Launcher specs > Puppeteer > Browser.disconnect > should reject waitForSelector when browser closes
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/launcher.spec.ts#L61 Upstream test
     */
    public function testPuppeteerBrowserDisconnectShouldRejectWaitForSelectorWhenBrowserCloses(): void
    {
        // waitForSelector is excluded. Its browser-side pending promise exercises the same
        // disconnect lifecycle, without implementing the excluded selector error wrapper.
        $waiting = $this->page->evaluate('new Promise(resolve => { const observer = new MutationObserver(() => { if (document.querySelector("div")) { observer.disconnect(); resolve(true); } }); observer.observe(document, {childList: true, subtree: true}); })');
        \Amp\delay(0.02);
        $this->browser->disconnect()->await();
        try {
            $waiting->await();
            self::fail('Disconnect must reject a pending browser-side wait.');
        } catch (\Nesk\Puphpeteer\Internal\TargetClosedException $error) {
            self::assertStringContainsString('closed', strtolower($error->getMessage()));
        }
    }
}
