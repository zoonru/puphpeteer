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
    private Internal\PageSession $state;
    private int $defaultTimeout = 30000;
    private ?int $defaultNavigationTimeout = null;

    /** @param array<string, mixed> $options @internal */
    public function __construct(private Internal\Session $session, private string $targetId, private array $options = [])
    {
        $this->state = new Internal\PageSession($session);
    }

    /** @internal */
    public function initialize(): void
    {
        $this->session->send('Page.enable')->await();
        $tree = $this->session->send('Page.getFrameTree')->await();
        /** @var array<string, mixed> $frameTree */
        $frameTree = $tree['frameTree'];
        $this->state->recordTree($frameTree);
        $this->session->send('Page.setLifecycleEventsEnabled', ['enabled' => true])->await();
        $this->session->send('Runtime.enable')->await();
        if ($this->options['networkEnabled'] ?? true) {
            $this->session->send('Network.enable')->await();
        }
        $viewport = array_key_exists('defaultViewport', $this->options) ? $this->options['defaultViewport'] : ['width' => 800, 'height' => 600];
        if (is_array($viewport)) {
            $this->session->send('Emulation.setDeviceMetricsOverride', [
                'width' => $viewport['width'], 'height' => $viewport['height'],
                'deviceScaleFactor' => $viewport['deviceScaleFactor'] ?? 1,
                'mobile' => $viewport['isMobile'] ?? false,
                'screenOrientation' => ($viewport['isLandscape'] ?? false)
                    ? ['angle' => 90, 'type' => 'landscapePrimary']
                    : ['angle' => 0, 'type' => 'portraitPrimary'],
            ])->await();
            $this->session->send('Emulation.setTouchEmulationEnabled', ['enabled' => $viewport['hasTouch'] ?? false])->await();
        }
    }

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
        return \Amp\async(function () use ($pageFunction, $args): mixed {
            $timeout = (float) ($this->options['protocolTimeout'] ?? 180000);
            $cancellation = $timeout > 0 ? new \Amp\TimeoutCancellation($timeout / 1000.0) : null;
            $context = $this->state->executionContext($cancellation);
            return Internal\Evaluation::run($this->session, $context, $pageFunction, array_values($args))->await();
        });
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
        return \Amp\async(function () use ($url, $options): ?HTTPResponse {
            $timeout = $options['timeout'] ?? $this->defaultNavigationTimeout ?? $this->defaultTimeout;
            return (new Internal\Navigation($this->state))->goto($url, $options, (float) $timeout);
        });
    }
}
