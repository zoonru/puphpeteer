<?php

/** Assertions ported from Puppeteer. Copyright Google Inc.
 * SPDX-License-Identifier: Apache-2.0
 * See tests/Support/assets/LICENSE.
 */

declare(strict_types=1);

require_once __DIR__ . '/../Support/BrowserTestCase.php';
require_once __DIR__ . '/../Support/ProxyServer.php';

final class ProxyContextTest extends BrowserTestCase
{
    /**
     * Upstream scenario: test/src/proxy.spec.ts::request proxy > in incognito browser context > should proxy requests when configured at context level
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/proxy.spec.ts#L186 Upstream test
     */
    public function testInIncognitoBrowserContextShouldProxyRequestsWhenConfiguredAtContextLevel(): void
    {
        $this->assertContextProxy(false);
    }

    /**
     * Upstream scenario: test/src/proxy.spec.ts::request proxy > in incognito browser context > should respect proxy bypass list when configured at context level
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/proxy.spec.ts#L208 Upstream test
     */
    public function testInIncognitoBrowserContextShouldRespectProxyBypassListWhenConfiguredAtContextLevel(): void
    {
        $this->assertContextProxy(true);
    }

    private function assertContextProxy(bool $bypass): void
    {
        $proxy = new ProxyServer();
        // Chromium bypasses loopback implicitly. Disable that rule for a portable fixture.
        $bypassList = ['<-loopback>'];
        if ($bypass) {
            $bypassList[] = '127.0.0.1';
        }
        $context = null;
        try {
            $context = $this->browser->createBrowserContext([
                'proxyServer' => $proxy->url(), 'proxyBypassList' => $bypassList,
            ])->await();
            $page = $context->newPage()->await();
            $url = $this->url('/empty.html');
            $statuses = [];
            $session = $this->session($page);
            $observer = $session->observe('Network.responseReceived', static function (array $event) use (&$statuses, $url): void {
                if ($event['response']['url'] === $url) {
                    $statuses[] = $event['response']['status'];
                }
            });
            try {
                self::assertNotNull($page->goto($url)->await());
                self::assertCount(1, $statuses);
                self::assertGreaterThanOrEqual(200, $statuses[0]);
                self::assertLessThan(300, $statuses[0]);
                self::assertSame($bypass ? [] : [$url], $proxy->urls);
            } finally {
                $session->off('Network.responseReceived', $observer);
            }
        } finally {
            $context?->close()->await();
            $proxy->close();
        }
    }
}
