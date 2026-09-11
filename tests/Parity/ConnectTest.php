<?php

/** Assertions ported from Puppeteer. Copyright Google Inc.
 * SPDX-License-Identifier: Apache-2.0
 * See tests/Support/assets/LICENSE.
 */

declare(strict_types=1);

use Nesk\Puphpeteer\Puppeteer;

require_once __DIR__ . '/../Support/BrowserTestCase.php';

final class ConnectTest extends BrowserTestCase
{
    /**
     * Upstream scenario: test/src/connect.spec.ts::Puppeteer.connect > should be able to connect using browserUrl, with and without trailing slash
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/connect.spec.ts#L11 Upstream test
     */
    public function testShouldBeAbleToConnectUsingBrowserUrlWithAndWithoutTrailingSlash(): void
    {
        $endpoint = parse_url(self::$endpoint);
        $url = 'http://' . $endpoint['host'] . ':' . $endpoint['port'];
        foreach ([$url, $url . '/'] as $browserURL) {
            $browser = (new Puppeteer())->connect(['browserURL' => $browserURL])->await();
            try {
                $context = $browser->createBrowserContext()->await();
                try {
                    $page = $context->newPage()->await();
                    self::assertSame(56, $page->evaluate('() => 7 * 8')->await());
                } finally {
                    $context->close()->await();
                }
            } finally {
                $browser->disconnect()->await();
            }
        }
    }

    /**
     * Upstream scenario: test/src/connect.spec.ts::Puppeteer.connect > should throw when using both browserWSEndpoint and browserURL
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/connect.spec.ts#L40 Upstream test
     */
    public function testShouldThrowWhenUsingBothBrowserWSEndpointAndBrowserURL(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Exactly one of browserWSEndpoint, browserURL, transport or channel must be passed to puppeteer.connect');
        (new Puppeteer())->connect([
            'browserURL' => 'http://127.0.0.1:21222',
            'browserWSEndpoint' => 'ws://127.0.0.1:21222/devtools/browser/',
        ])->await();
    }

    /**
     * Upstream scenario: test/src/connect.spec.ts::Puppeteer.connect > should throw when trying to connect to non-existing browser
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/connect.spec.ts#L58 Upstream test
     */
    public function testShouldThrowWhenTryingToConnectToNonExistingBrowser(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $error);
        self::assertIsResource($socket, $error);
        $url = 'http://' . stream_socket_get_name($socket, false);
        fclose($socket);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to fetch browser webSocket URL from');
        (new Puppeteer())->connect(['browserURL' => $url])->await();
    }

}
