<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Internal;

use Amp\DeferredCancellation;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Interceptor\FollowRedirects;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use League\Uri\Http;
use RuntimeException;
use Throwable;

/** @internal HTTP response ownership and cancellation stay on the PHP host. */
final class HostHttp
{
    private readonly HttpClient $client;
    /** @var array<int, object{cancellation: DeferredCancellation, response: ?Response, remainder: ?string, reading: bool}> */
    private array $requests = [];

    public function __construct()
    {
        // Fetch redirects are method-sensitive; Amp's generic redirect policy differs.
        $this->client = (new HttpClientBuilder())->followRedirects(0)->retry(0)->build();
    }

    public function begin(int $token): void
    {
        if (isset($this->requests[$token])) {
            throw new RuntimeException('Duplicate HTTP request');
        }
        $this->requests[$token] = (object) ['cancellation' => new DeferredCancellation(), 'response' => null, 'remainder' => null, 'reading' => false];
    }

    public function start(int $token, array $options): array
    {
        $state = $this->requests[$token] ?? throw new RuntimeException('HTTP request closed');
        $cancellation = $state->cancellation->getCancellation();
        try {
            $method = $options['method'];
            $body = $options['body'] ?? '';
            $headers = [];
            foreach ($options['headers'] as [$name, $value]) {
                $headers[$name][] = $value;
            }
            $uri = Http::new($options['url']);
            $redirects = 0;
            while (true) {
                $cancellation->throwIfRequested();
                if (!in_array($uri->getScheme(), ['http', 'https'], true) || '' !== $uri->getUserInfo()) {
                    throw new RuntimeException('Invalid redirect URL');
                }
                $request = new Request($uri, $method, $body);
                $request->setHeaders($headers);
                $request->setBodySizeLimit(0);
                $request->setTransferTimeout(0);
                $response = $this->client->request($request, $cancellation);
                $status = $response->getStatus();
                $location = $response->getHeader('location');
                if (!in_array($status, [301, 302, 303, 307, 308], true) || null === $location || 'manual' === $options['redirect']) {
                    break;
                }
                $response->getBody()->close();
                if ('error' === $options['redirect'] || $redirects >= 20) {
                    throw new RuntimeException('HTTP redirect rejected');
                }
                $next = FollowRedirects::resolve($uri, Http::new($location))->withFragment('');
                if ($uri->getScheme() !== $next->getScheme() || $uri->getAuthority() !== $next->getAuthority()) {
                    unset($headers['authorization'], $headers['cookie'], $headers['proxy-authorization']);
                }
                if ((303 === $status && 'HEAD' !== $method) || (in_array($status, [301, 302], true) && 'POST' === $method)) {
                    $method = 'GET';
                    $body = '';
                    unset($headers['content-type'], $headers['content-length'], $headers['content-encoding'], $headers['content-language'], $headers['content-location']);
                }
                $uri = $next;
                ++$redirects;
            }
            $cancellation->throwIfRequested();
            $noBody = 'HEAD' === $method || in_array($status, [204, 205, 304], true);
            if ($noBody) {
                $response->getBody()->close();
                unset($this->requests[$token]);
            } else {
                $state->response = $response;
            }
            $pairs = [];
            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    // HTTP header values are Latin-1 ByteStrings, not UTF-8 payloads.
                    $utf8 = preg_replace_callback('/[\x80-\xff]/', static function (array $match): string {
                        $byte = ord($match[0]);

                        return chr(0xC0 | ($byte >> 6)) . chr(0x80 | ($byte & 63));
                    }, $value);
                    $pairs[] = [$name, $utf8];
                }
            }

            return ['status' => $status, 'statusText' => $response->getReason(), 'headers' => $pairs,
                'url' => (string) $uri, 'redirected' => $redirects > 0, 'noBody' => $noBody];
        } catch (Throwable $error) {
            $this->close($token);
            throw $error;
        }
    }

    public function read(int $token): ?string
    {
        $state = $this->requests[$token] ?? throw new RuntimeException('HTTP response closed');
        $response = $state->response ?? throw new RuntimeException('HTTP response closed');
        if ($state->reading) {
            throw new RuntimeException('Concurrent HTTP body reads');
        }
        $cancellation = $state->cancellation->getCancellation();
        $state->reading = true;
        try {
            $cancellation->throwIfRequested();
            $chunk = $state->remainder ?? $response->getBody()->read($cancellation);
            if (null === $chunk) {
                $this->close($token);

                return null;
            }
            if (strlen($chunk) > 65536) {
                $state->remainder = substr($chunk, 65536);
                $chunk = substr($chunk, 0, 65536);
            } else {
                $state->remainder = null;
            }

            return "\xff" . $chunk;
        } catch (Throwable $error) {
            $this->close($token);
            throw $error;
        } finally {
            $state->reading = false;
        }
    }

    public function close(int $token): void
    {
        $state = $this->requests[$token] ?? null;
        unset($this->requests[$token]);
        $state?->cancellation->cancel();
        $state?->response?->getBody()->close();
    }

    public function closeAll(): void
    {
        foreach (array_keys($this->requests) as $token) {
            $this->close($token);
        }
    }
}
