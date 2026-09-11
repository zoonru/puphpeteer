<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LauncherSpecsTest extends TestCase
{
    /** Upstream scenario: test/src/launcher.spec.ts::Launcher specs > Puppeteer > Browser.disconnect > should reject navigation when browser closes
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/launcher.spec.ts#L30 Upstream test
     */
    public function testPuppeteerBrowserDisconnectShouldRejectNavigationWhenBrowserCloses(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/launcher.spec.ts::Launcher specs > Puppeteer > Browser.disconnect > should reject waitForSelector when browser closes
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/launcher.spec.ts#L61 Upstream test
     */
    public function testPuppeteerBrowserDisconnectShouldRejectWaitForSelectorWhenBrowserCloses(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }
}
