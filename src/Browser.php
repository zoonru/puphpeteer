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
    /** @param array<string, mixed> $options @internal */
    public function __construct(private Internal\Connection $connection, private array $options = [])
    {
    }

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
        return \Amp\async(function () use ($options): BrowserContext {
            $params = [];
            if (isset($options['proxyServer'])) {
                $params['proxyServer'] = $options['proxyServer'];
            }
            if (isset($options['proxyBypassList'])) {
                $params['proxyBypassList'] = implode(',', $options['proxyBypassList']);
            }
            $result = $this->connection->send('Target.createBrowserContext', $params)->await();
            $id = (string) $result['browserContextId'];
            try {
                if (isset($options['downloadBehavior'])) {
                    $download = $options['downloadBehavior'];
                    $params = ['browserContextId' => $id, 'behavior' => $download['policy']];
                    if (isset($download['downloadPath'])) {
                        $params['downloadPath'] = $download['downloadPath'];
                    }
                    $this->connection->send('Browser.setDownloadBehavior', $params)->await();
                }
                return new BrowserContext($this->connection, $id, $this->options);
            } catch (\Throwable $error) {
                try {
                    $this->connection->send('Target.disposeBrowserContext', ['browserContextId' => $id])->await();
                } catch (\Throwable) {
                    // Preserve the initialization error if the browser already disconnected.
                }
                throw $error;
            }
        });
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
        try {
            $this->connection->close();
            return \Amp\Future::complete(null);
        } catch (\Throwable $error) {
            return \Amp\Future::error($error);
        }
    }
}
