<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Support\Puppeteer;

use Amp\Socket\ResourceServerSocket;
use Amp\Socket\Socket;
use RuntimeException;
use Throwable;

use function Amp\async;
use function Amp\delay;
use function Amp\Socket\listen;

/** Local deterministic HTTP fixture; concurrent connections allow cancellation tests. */
final class HostHttpServer
{
    public readonly string $url;
    public array $requests = [];
    private readonly ResourceServerSocket $server;
    /** @var array<int, Socket> */
    private array $sockets = [];

    public function __construct()
    {
        $this->server = listen('127.0.0.1:0');
        $this->url = 'http://' . (string) $this->server->getAddress();
        async(function (): void {
            try {
                while ($socket = $this->server->accept()) {
                    $id = spl_object_id($socket);
                    $this->sockets[$id] = $socket;
                    async(function () use ($socket, $id): void {
                        try {
                            $this->respond($socket);
                        } catch (Throwable) {
                            // Aborted clients deliberately disconnect while a response is pending.
                        } finally {
                            $socket->close();
                            unset($this->sockets[$id]);
                        }
                    })->ignore();
                }
            } catch (Throwable) {
                // Closing the fixture stops the accept loop.
            }
        })->ignore();
    }

    private function respond(Socket $socket): void
    {
        $input = '';
        while (!str_contains($input, "\r\n\r\n")) {
            $input .= $socket->read() ?? throw new RuntimeException('Client disconnected');
        }
        [$head, $body] = explode("\r\n\r\n", $input, 2) + [1 => ''];
        [$line, $headers] = explode("\r\n", $head, 2) + [1 => ''];
        [$method, $path] = explode(' ', $line);
        preg_match('/content-length:\s*(\d+)/i', $headers, $length);
        $size = (int) ($length[1] ?? 0);
        while (strlen($body) < $size) {
            $body .= $socket->read() ?? throw new RuntimeException('Client disconnected');
        }
        $this->requests[] = ['method' => $method, 'path' => $path, 'body' => $body, 'headers' => $headers];
        if ('/slow' === $path) {
            delay(0.3);
        }
        if ('/loop' === $path || str_starts_with($path, '/cross?')) {
            parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
            $location = '/loop' === $path ? '/loop' : (string) ($query['url'] ?? throw new RuntimeException('Missing redirect target'));
            $socket->write("HTTP/1.1 302 Redirect\r\nLocation: $location\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");

            return;
        }
        if (str_starts_with($path, '/redirect')) {
            $status = '/redirect307' === $path ? 307 : 302;
            $socket->write("HTTP/1.1 $status Redirect\r\nLocation: /echo\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");

            return;
        }
        if ('/split' === $path || '/slow-body' === $path) {
            $socket->write("HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n");
            foreach (str_split("\xef\xbb\xbfПривет € 😀") as $byte) {
                if ('/slow-body' === $path) {
                    delay(0.05);
                } else {
                    delay(0.001);
                }
                $socket->write("1\r\n" . $byte . "\r\n");
            }
            $socket->write("0\r\n\r\n");

            return;
        }
        if ('/drop' === $path) {
            $socket->write("HTTP/1.1 200 OK\r\nContent-Length: 20\r\nConnection: close\r\n\r\nabc");

            return;
        }
        $status = '/status' === $path ? 404 : ('/empty' === $path ? 204 : 200);
        preg_match('/authorization:\s*([^\r\n]+)/i', $headers, $authorization);
        preg_match('/x-latin:\s*([^\r\n]+)/i', $headers, $latin);
        $body = match ($path) {
            '/echo' => json_encode(['method' => $method, 'body' => $body, 'authorization' => $authorization[1] ?? null, 'latinHex' => bin2hex($latin[1] ?? '')], JSON_THROW_ON_ERROR),
            '/binary' => str_repeat("\0\xff\x80A", 40000),
            '/empty' => '',
            default => '{"value":42}',
        };
        $socket->write("HTTP/1.1 $status Result\r\nX-Latin: \xe9\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . ('HEAD' === $method ? '' : $body));
    }

    public function close(): void
    {
        $this->server->close();
        foreach ($this->sockets as $socket) {
            $socket->close();
        }
        $this->sockets = [];
    }
}
