<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer;

/**
 * [upstream-generated]
 * upstream-id: class:ConnectionTransport
 * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/packages/puppeteer-core/src/common/ConnectionTransport.ts#L10 Upstream
 * [/upstream-generated]
 */
class ConnectionTransport
{
    /** @param \Closure(string):void $sender @param \Closure():void $closer */
    public function __construct(private \Closure $sender, private \Closure $closer) {}

    /**
     * [upstream-generated]
     * upstream-id: ConnectionTransport.close
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/packages/puppeteer-core/src/common/ConnectionTransport.ts#L12 Upstream
     * @return void
     * [/upstream-generated]
     */
    public function close(): void
    {
        ($this->closer)();
    }
    /**
     * [upstream-generated]
     * upstream-id: ConnectionTransport.onclose
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/packages/puppeteer-core/src/common/ConnectionTransport.ts#L14 Upstream
     * @var (callable():void)
     * [/upstream-generated]
     */
    public mixed $onclose;
    /**
     * [upstream-generated]
     * upstream-id: ConnectionTransport.onmessage
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/packages/puppeteer-core/src/common/ConnectionTransport.ts#L13 Upstream
     * @var (callable(string):void)
     * [/upstream-generated]
     */
    public mixed $onmessage;
    /**
     * [upstream-generated]
     * upstream-id: ConnectionTransport.send
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/packages/puppeteer-core/src/common/ConnectionTransport.ts#L11 Upstream
     * @param string $message
     * @return void
     * [/upstream-generated]
     */
    public function send(string $message): void
    {
        ($this->sender)($message);
    }
}
