<?php

/** Assertions ported from Puppeteer. Copyright Google Inc.
 * SPDX-License-Identifier: Apache-2.0
 * See tests/Support/assets/LICENSE.
 */

declare(strict_types=1);

require_once __DIR__ . '/../Support/BrowserTestCase.php';

use Nesk\Puphpeteer\Browser;
use Nesk\Puphpeteer\Page;
use Nesk\Puphpeteer\Puppeteer;
use Nesk\Puphpeteer\Internal\Connection;

final class ConnectionLifecycleTest extends BrowserTestCase
{
    /**
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/launcher.spec.ts#L612 Upstream test
     */
    public function testConnectMultipleTimesToTheSameBrowser(): void
    {
        $other = $this->connect();
        $context = $other->createBrowserContext()->await();
        try {
            self::assertSame(56, $context->newPage()->await()->evaluate('7 * 8')->await());
        } finally {
            $context->close()->await();
            $other->disconnect()->await();
        }
        self::assertSame(42, $this->context->newPage()->await()->evaluate('7 * 6')->await());
    }

    /**
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/launcher.spec.ts#L652 Upstream test
     */
    public function testConnectToBrowserWithNoPageTargets(): void
    {
        $connection = $this->connection($this->browser);
        foreach ($connection->send('Target.getTargets')->await()['targetInfos'] as $target) {
            if ($target['type'] === 'page') {
                $connection->send('Target.closeTarget', ['targetId' => $target['targetId']])->await();
            }
        }
        $other = $this->connect();
        try {
            self::assertFalse($this->connection($other)->isClosed());
            $pages = array_filter($connection->send('Target.getTargets')->await()['targetInfos'], static fn (array $target): bool => $target['type'] === 'page');
            self::assertSame([], array_values($pages));
        } finally {
            $other->disconnect()->await();
        }
    }

    /**
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/launcher.spec.ts#L758 Upstream test
     */
    public function testReconnectToDisconnectedBrowserPreservesFramesAndEvaluation(): void
    {
        $this->page->goto($this->url('/frames/nested-frames.html'))->await();
        $target = self::property($this->page, 'targetId');
        $this->browser->disconnect()->await();
        $other = $this->connect();
        try {
            $page = $this->attach($other, $target);
            $tree = $this->session($page)->send('Page.getFrameTree')->await()['frameTree'];
            $frames = [];
            $walk = function (array $tree, int $depth = 0) use (&$walk, &$frames): void {
                $frame = $tree['frame'];
                $frames[] = str_repeat('    ', $depth) . str_replace($this->server->url(''), 'http://localhost:<PORT>', $frame['url']) . (empty($frame['name']) ? '' : ' (' . $frame['name'] . ')');
                foreach ($tree['childFrames'] ?? [] as $child) {
                    $walk($child, $depth + 1);
                }
            };
            $walk($tree);
            self::assertSame([
                'http://localhost:<PORT>/frames/nested-frames.html',
                '    http://localhost:<PORT>/frames/two-frames.html (2frames)',
                '        http://localhost:<PORT>/frames/frame.html (uno)',
                '        http://localhost:<PORT>/frames/frame.html (dos)',
                '    http://localhost:<PORT>/frames/frame.html (aframe)',
            ], $frames);
            self::assertSame(56, $page->evaluate('7 * 8')->await());
        } finally {
            // The context outlives the disconnected client and is disposed using its new connection.
            $this->connection($other)->send('Target.disposeBrowserContext', ['browserContextId' => self::property($this->context, 'id')])->await();
            $other->disconnect()->await();
        }
    }

    /**
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/launcher.spec.ts#L795 Upstream test
     */
    public function testConnectToSamePageSimultaneously(): void
    {
        $other = $this->connect();
        try {
            $page = $this->attach($other, self::property($this->page, 'targetId'));
            $first = $this->page->evaluate('7 * 8');
            $second = $page->evaluate('7 * 6');
            self::assertSame(56, $first->await());
            self::assertSame(42, $second->await());
        } finally {
            $other->disconnect()->await();
        }
        self::assertSame(42, $this->page->evaluate('42')->await());
    }

    /**
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/launcher.spec.ts#L674 Upstream test
     */
    public function testConnectAcceptsInsecureCertificates(): void
    {
        $other = $this->connect(['acceptInsecureCerts' => true]);
        $context = $other->createBrowserContext()->await();
        try {
            $response = $context->newPage()->await()->goto($this->httpsUrl('/empty.html'))->await();
            self::assertNotNull($response);
            $security = self::property($response, 'securityDetails');
            self::assertSame(200, self::property($response, 'status'));
            self::assertNotEmpty($security);
            self::assertStringStartsWith('TLS ', $security['protocol']);
        } finally {
            $context->close()->await();
            $other->disconnect()->await();
        }
    }

    private function connect(array $options = []): Browser
    {
        return (new Puppeteer())->connect(['browserWSEndpoint' => self::$endpoint] + $options)->await();
    }

    private function connection(Browser $browser): Connection
    {
        return self::property($browser, 'connection');
    }

    /** Browser.pages/Target.page are excluded; attach only as test setup. */
    private function attach(Browser $browser, string $targetId): Page
    {
        $connection = $this->connection($browser);
        $result = $connection->send('Target.attachToTarget', ['targetId' => $targetId, 'flatten' => true])->await();
        $page = new Page($connection->session($result['sessionId']), $targetId);
        $page->initialize();
        return $page;
    }
}
