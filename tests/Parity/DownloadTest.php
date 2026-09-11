<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DownloadTest extends TestCase
{
    /** Upstream scenario: test/src/download.spec.ts::Download > Browser.createBrowserContext > should download to configured location
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/download.spec.ts#L29 Upstream test
     */
    public function testBrowserCreateBrowserContextShouldDownloadToConfiguredLocation(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/download.spec.ts::Download > Browser.createBrowserContext > should not download to location
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/download.spec.ts#L45 Upstream test
     */
    public function testBrowserCreateBrowserContextShouldNotDownloadToLocation(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }
}
