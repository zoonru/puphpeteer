<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer;

/**
 * [upstream-generated]
 * upstream-id: class:Page
 * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/packages/puppeteer-core/src/api/Page.ts#L697 Upstream
 * [/upstream-generated]
 */
class Page
{
    /**
     * [upstream-generated]
     * upstream-id: Page.evaluate
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/packages/puppeteer-core/src/api/Page.ts#L2330 Upstream
     * @param string $pageFunction
     * @param mixed ...$args
     * @return \Amp\Future<mixed>
     * [/upstream-generated]
     */
    public function evaluate(string $pageFunction, mixed ...$args): \Amp\Future
    {
        throw new \LogicException('NotImplemented: Page.evaluate');
    }
    /**
     * [upstream-generated]
     * upstream-id: Page.goto
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/packages/puppeteer-core/src/api/Page.ts#L1755 Upstream
     * @param string $url
     * @param array{referer?: string, referrerPolicy?: string, signal?: \Amp\Cancellation, timeout?: int|float, waitUntil?: "load"|"domcontentloaded"|"networkidle0"|"networkidle2"|list<"load"|"domcontentloaded"|"networkidle0"|"networkidle2">} $options
     * @return \Amp\Future<null|\Nesk\Puphpeteer\HTTPResponse>
     * [/upstream-generated]
     */
    public function goto(string $url, array $options = array()): \Amp\Future
    {
        throw new \LogicException('NotImplemented: Page.goto');
    }
}
