<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer;

/**
 * [upstream-generated]
 * upstream-id: class:Browser
 * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/packages/puppeteer-core/src/api/Browser.ts#L349 Upstream
 * [/upstream-generated]
 */
class Browser
{
    /**
     * [upstream-generated]
     * upstream-id: Browser.createBrowserContext
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/packages/puppeteer-core/src/api/Browser.ts#L385 Upstream
     * @param array{downloadBehavior?: array{downloadPath?: string, policy: "deny"|"allow"|"allowAndName"|"default"}, proxyBypassList?: list<string>, proxyServer?: string} $options
     * @return \Amp\Future<\Nesk\Puphpeteer\BrowserContext>
     * [/upstream-generated]
     */
    public function createBrowserContext(array $options = array()): \Amp\Future
    {
        throw new \LogicException('NotImplemented: Browser.createBrowserContext');
    }
    /**
     * [upstream-generated]
     * upstream-id: Browser.disconnect
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/packages/puppeteer-core/src/api/Browser.ts#L542 Upstream
     * @return \Amp\Future<null>
     * [/upstream-generated]
     */
    public function disconnect(): \Amp\Future
    {
        throw new \LogicException('NotImplemented: Browser.disconnect');
    }
}
