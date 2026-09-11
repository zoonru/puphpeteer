<?php

declare(strict_types=1);

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Socket\ResourceServerSocket;
use Amp\Socket\Socket;
use function Amp\async;
use function Amp\Socket\listen;

/** HTTP forwarding proxy used to observe actual browser requests. */
final class ProxyServer
{
    private ResourceServerSocket $server;
    private array $clients = [];
    public array $urls = [];

    public function __construct()
    {
        $this->server = listen('127.0.0.1:0');
        async(function (): void {
            while ($socket = $this->server->accept()) {
                $id = spl_object_id($socket);
                $this->clients[$id] = $socket;
                async(function () use ($socket, $id): void {
                    try {
                        $this->forward($socket);
                    } finally {
                        $socket->close();
                        unset($this->clients[$id]);
                    }
                })->ignore();
            }
        })->ignore();
    }

    public function url(): string
    {
        return 'http://' . $this->server->getAddress();
    }

    public function close(): void
    {
        $this->server->close();
        foreach ($this->clients as $socket) {
            $socket->close();
        }
    }

    private function forward(Socket $socket): void
    {
        $buffer = '';
        while (!str_contains($buffer, "\r\n\r\n")) {
            $chunk = $socket->read();
            if ($chunk === null) {
                return;
            }
            $buffer .= $chunk;
        }
        [$method, $url] = explode(' ', strtok($buffer, "\r\n"));
        if ($method !== 'GET' || !str_starts_with($url, 'http://')) {
            $socket->write("HTTP/1.1 405 Method Not Allowed\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
            return;
        }
        $this->urls[] = $url;
        $response = HttpClientBuilder::buildDefault()->request(new Request($url));
        $body = $response->getBody()->buffer();
        $socket->write('HTTP/1.1 ' . $response->getStatus() . " OK\r\nContent-Type: text/html\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
    }
}
