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
    /** @var \Amp\Future<null>|null */
    private ?\Amp\Future $closing = null;

    /** @param array<string, mixed> $options @internal */
    public function __construct(private Internal\Connection $connection, private string $id, private array $options = [])
    {
    }

    /**
     * [upstream-generated]
     * upstream-id: BrowserContext.close
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/packages/puppeteer-core/src/api/BrowserContext.ts#L256 Upstream
     * @return \Amp\Future<null>
     * [/upstream-generated]
     */
    public function close(): \Amp\Future
    {
        return $this->closing ??= \Amp\async(function () {
            if ($this->id === '') {
                throw new \RuntimeException('Default browser context cannot be closed');
            }
            $this->connection->send('Target.disposeBrowserContext', ['browserContextId' => $this->id])->await();
            return null;
        });
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
        return \Amp\async(function () use ($options): Page {
            if ($this->closing !== null) {
                throw new \RuntimeException('Browser context closed');
            }
            $params = ['url' => 'about:blank', 'browserContextId' => $this->id];
            if (isset($options['background'])) {
                $params['background'] = $options['background'];
            }
            if (($options['type'] ?? 'tab') === 'window') {
                $targets = $this->connection->send('Target.getTargets')->await();
                /** @var list<array<string, mixed>> $targetInfos */
                $targetInfos = $targets['targetInfos'];
                foreach ($targetInfos as $info) {
                    if (($info['browserContextId'] ?? null) === $this->id) {
                        $params['newWindow'] = true;
                        break;
                    }
                }
                if (isset($options['windowBounds'])) {
                    $params += $options['windowBounds'];
                }
            }
            $target = $this->connection->send('Target.createTarget', $params)->await();
            $targetId = (string) $target['targetId'];
            try {
                $attached = $this->connection->send('Target.attachToTarget', ['targetId' => $targetId, 'flatten' => true])->await();
                $session = $this->connection->session((string) $attached['sessionId']);
                $page = new Page($session, $targetId, $this->options);
                $page->initialize();
                /** @psalm-suppress TypeDoesNotContainType A concurrent Fiber may close the context while initialization awaits CDP. */
                if ($this->closing !== null) {
                    throw new \RuntimeException('Browser context closed');
                }
                return $page;
            } catch (\Throwable $error) {
                try {
                    $this->connection->send('Target.closeTarget', ['targetId' => $targetId])->await();
                } catch (\Throwable) {
                    // The context or connection may already have closed the target.
                }
                throw $error;
            }
        });
    }
}
