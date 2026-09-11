<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Internal;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\TimeoutCancellation;
use Nesk\Puphpeteer\HTTPResponse;

/** One navigation owns its event subscriptions and cancellation lifetime. */
final class Navigation
{
    /** @var array<string, int> */
    private array $listeners = [];
    private ?string $requestId = null;
    private string $requestUrl = '';
    private ?HTTPResponse $response = null;
    private bool $responseComplete = false;
    private bool $sameDocument = false;
    private ?\Throwable $failure = null;

    public function __construct(private PageSession $state)
    {
    }

    /** @param array{referer?: string, referrerPolicy?: string, signal?: Cancellation, timeout?: int|float, waitUntil?: string|list<string>} $options */
    public function goto(string $url, array $options, float $timeout): ?HTTPResponse
    {
        $expected = [];
        $waitUntil = $options['waitUntil'] ?? 'load';
        foreach (is_string($waitUntil) ? [$waitUntil] : $waitUntil as $event) {
            $expected[] = match ($event) {
                'load' => 'load', 'domcontentloaded' => 'DOMContentLoaded',
                'networkidle0' => 'networkIdle', 'networkidle2' => 'networkAlmostIdle',
                default => throw new \InvalidArgumentException('Unknown value for options.waitUntil: ' . $event),
            };
        }
        if ($timeout < 0) {
            throw new \InvalidArgumentException('Navigation timeout must not be negative');
        }
        $timer = $timeout > 0 ? new TimeoutCancellation($timeout / 1000.0) : null;
        $signal = $options['signal'] ?? null;
        $cancellation = $timer !== null && $signal !== null ? new CompositeCancellation($timer, $signal) : ($timer ?? $signal);
        $this->state->assertOpen();
        $initialLoader = $this->state->frames[$this->state->frameId]['loader'] ?? '';
        $cancellation?->throwIfRequested();
        $this->observe('Page.navigatedWithinDocument', function (array $event): void {
            if ($event['frameId'] === $this->state->frameId) {
                $this->sameDocument = true;
            }
        });
        $this->observe('Network.requestWillBeSent', function (array $event): void {
            if (($event['type'] ?? '') === 'Document' && ($event['frameId'] ?? '') === $this->state->frameId) {
                $this->requestId = (string) $event['requestId'];
                /** @var array<string, mixed> $request */
                $request = $event['request'];
                $this->requestUrl = (string) $request['url'] . (string) ($request['urlFragment'] ?? '');
                $this->response = null;
                $this->responseComplete = false;
            }
        });
        $this->observe('Network.responseReceived', function (array $event): void {
            if (($event['requestId'] ?? null) === $this->requestId) {
                /** @var array<string, mixed> $response */
                $response = $event['response'];
                $response['url'] = $this->requestUrl;
                $this->response = new HTTPResponse($response);
                $this->responseComplete = true;
            }
        });
        $this->observe('Network.loadingFailed', function (array $event) use ($url): void {
            if (($event['requestId'] ?? null) === $this->requestId && ($event['errorText'] ?? '') !== 'net::ERR_HTTP_RESPONSE_CODE_FAILURE') {
                $this->failure = new \RuntimeException((string) ($event['errorText'] ?? 'Navigation failed') . ' at ' . $url);
            }
        });
        try {
            $params = ['url' => $url, 'frameId' => $this->state->frameId];
            if (isset($options['referer'])) {
                $params['referrer'] = $options['referer'];
            }
            if (isset($options['referrerPolicy'])) {
                $params['referrerPolicy'] = $options['referrerPolicy'];
            }
            $result = $this->state->session->send('Page.navigate', $params, $cancellation)->await();
            if (isset($result['errorText']) && $result['errorText'] !== 'net::ERR_HTTP_RESPONSE_CODE_FAILURE') {
                throw new \RuntimeException((string) $result['errorText'] . ' at ' . $url);
            }
            $loaderId = isset($result['loaderId']) ? (string) $result['loaderId'] : null;
            while (true) {
                $this->state->assertOpen();
                if ($this->failure !== null) {
                    throw $this->failure;
                }
                $frame = $this->state->frames[$this->state->frameId] ?? null;
                $navigated = $loaderId !== null ? ($frame['loader'] ?? $initialLoader) !== $initialLoader : $this->sameDocument;
                if ($navigated && $this->state->lifecycleComplete($expected)
                    && ($this->requestId === null || $this->responseComplete)) {
                    return $this->response;
                }
                $this->state->change()->await($cancellation);
            }
        } catch (CancelledException $error) {
            if ($signal?->isRequested()) {
                throw $error;
            }
            if ($timer?->isRequested()) {
                throw new NavigationTimeoutException('Navigation timeout of ' . (string) $timeout . ' ms exceeded', 0, $error);
            }
            throw $error;
        } finally {
            foreach ($this->listeners as $event => $id) {
                $this->state->session->off($event, $id);
            }
            $this->listeners = [];
        }
    }

    /** @param \Closure(array<string, mixed>): void $callback */
    private function observe(string $event, \Closure $callback): void
    {
        $this->listeners[$event] = $this->state->session->observe($event, function (array $event) use ($callback): void {
            $callback($event);
            $this->state->notify();
        });
    }
}
