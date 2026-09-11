<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer;

/**
 * [upstream-generated]
 * upstream-id: class:BrowserContext
 * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/packages/puppeteer-core/src/api/BrowserContext.ts#L111 Upstream
 * [/upstream-generated]
 */
class BrowserContext
{
    /**
     * [upstream-generated]
     * upstream-id: BrowserContext.close
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/packages/puppeteer-core/src/api/BrowserContext.ts#L256 Upstream
     * @return \Amp\Future<null>
     * [/upstream-generated]
     */
    public function close(): \Amp\Future
    {
        throw new \LogicException('NotImplemented: BrowserContext.close');
    }
    /**
     * [upstream-generated]
     * upstream-id: BrowserContext.newPage
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/packages/puppeteer-core/src/api/BrowserContext.ts#L240 Upstream
     * @param array{type?: "tab", background?: bool}|array{type: "window", windowBounds?: array{height?: int|float, left?: int|float, top?: int|float, width?: int|float, windowState?: "normal"|"minimized"|"maximized"|"fullscreen"}, background?: bool} $options
     * @return \Amp\Future<\Nesk\Puphpeteer\Page>
     * [/upstream-generated]
     */
    public function newPage(array $options = array()): \Amp\Future
    {
        throw new \LogicException('NotImplemented: BrowserContext.newPage');
    }
}
