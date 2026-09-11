<?php

declare(strict_types=1);

use Amp\Socket\BindContext;
use Amp\Socket\Certificate;
use Amp\Socket\ServerTlsContext;
use Amp\Socket\Socket;
use Amp\Socket\ResourceServerSocket;
use function Amp\async;
use function Amp\delay;
use function Amp\Socket\listen;

/** Small concurrent HTTP fixture server; routes belong to each test instance. */
final class FixtureServer
{
    private ResourceServerSocket $http;
    private ResourceServerSocket $https;
    private array $routes = [];
    private array $requests = [];
    private array $clients = [];
    private string $certificate;
    private \Amp\DeferredCancellation $cancellation;

    public function __construct()
    {
        $this->cancellation = new \Amp\DeferredCancellation();
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'localhost'], $key);
        $cert = openssl_csr_sign($csr, null, $key, 1);
        openssl_x509_export($cert, $pem);
        openssl_pkey_export($key, $private);
        $this->certificate = tempnam(sys_get_temp_dir(), 'puphpeteer-cert-');
        file_put_contents($this->certificate, $pem . $private);
        $this->http = listen('127.0.0.1:0');
        $tls = (new ServerTlsContext())->withDefaultCertificate(new Certificate($this->certificate));
        $this->https = listen('127.0.0.1:0', (new BindContext())->withTlsContext($tls));
        foreach ([$this->http, $this->https] as $server) {
            async(function () use ($server): void {
                while ($socket = $server->accept()) {
                    $id = spl_object_id($socket);
                    $this->clients[$id] = $socket;
                    async(function () use ($socket, $server, $id): void {
                        try {
                            if ($server === $this->https) {
                                $socket->setupTls();
                            }
                            $this->respond($socket);
                        } catch (Throwable) {
                            // A navigation can intentionally close a socket or reject TLS.
                        } finally {
                            $socket->close();
                            unset($this->clients[$id]);
                        }
                    })->ignore();
                }
            })->ignore();
        }
    }

    public function url(string $path, bool $tls = false): string
    {
        return ($tls ? 'https://' : 'http://') . ($tls ? $this->https : $this->http)->getAddress() . $path;
    }

    public function route(string $path, callable $handler): void
    {
        $this->routes[$path] = $handler;
    }

    public function requests(string $path): array
    {
        return $this->requests[$path] ?? [];
    }

    public function close(): void
    {
        $this->cancellation->cancel();
        $this->http->close();
        $this->https->close();
        foreach ($this->clients as $socket) {
            $socket->close();
        }
        unlink($this->certificate);
    }

    private function respond(Socket $socket): void
    {
        $buffer = '';
        while (!str_contains($buffer, "\r\n\r\n")) {
            $chunk = $socket->read();
            if ($chunk === null) {
                return;
            }
            $buffer .= $chunk;
        }
        $lines = explode("\r\n", $buffer);
        [$method, $target] = explode(' ', array_shift($lines));
        $path = parse_url($target, PHP_URL_PATH);
        $headers = [];
        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower($name)] = trim($value);
            }
        }
        $request = ['method' => $method, 'path' => $path, 'headers' => $headers];
        $this->requests[$path][] = $request;
        $response = isset($this->routes[$path]) ? ($this->routes[$path])($request) : $this->defaultResponse($path);
        if (($response['delay'] ?? 0) > 0) {
            delay($response['delay'], cancellation: $this->cancellation->getCancellation());
        }
        $status = $response['status'] ?? 200;
        $body = $response['body'] ?? '';
        $responseHeaders = ($response['headers'] ?? []) + ['Content-Type' => 'text/html; charset=utf-8'];
        $responseHeaders['Content-Length'] = (string) strlen($body);
        $responseHeaders['Connection'] = 'close';

        $output = "HTTP/1.1 {$status} Response\r\n";
        foreach ($responseHeaders as $name => $value) {
            $output .= "$name: $value\r\n";
        }
        $socket->write($output . "\r\n" . ($method === 'HEAD' ? '' : $body));
    }

    private function defaultResponse(string $path): array
    {
        if (preg_match('~^/redirect/([123])\.html$~', $path, $match)) {
            return ['status' => 302, 'headers' => ['Location' => $match[1] === '3' ? '/empty.html' : '/redirect/' . ((int) $match[1] + 1) . '.html']];
        }
        if ($path === '/hang') {
            return ['delay' => 60];
        }
        if (in_array($path, ['/204', '/404-error', '/500-error', '/not-found'], true)) {
            return ['status' => $path === '/204' ? 204 : ($path === '/500-error' ? 500 : 404)];
        }
        if ($path === '/frames/204.html') {
            return ['body' => '<iframe src="/204"></iframe>'];
        }
        if (str_starts_with($path, '/slow/')) {
            return ['delay' => 0.2, 'body' => 'done'];
        }
        $file = realpath(__DIR__ . '/assets' . $path);
        if ($file !== false && str_starts_with($file, __DIR__ . '/assets/') && is_file($file)) {
            return ['body' => file_get_contents($file), 'headers' => ['Content-Type' => match (pathinfo($file, PATHINFO_EXTENSION)) {
                'css' => 'text/css', 'js' => 'text/javascript', 'png' => 'image/png', default => 'text/html; charset=utf-8',
            }]];
        }
        return ['status' => 404];
    }
}
