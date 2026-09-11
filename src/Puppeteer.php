<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer;

/**
 * [upstream-generated]
 * upstream-id: class:Puppeteer
 * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/packages/puppeteer-core/src/common/Puppeteer.ts#L35 Upstream
 * [/upstream-generated]
 */
class Puppeteer
{
    /**
     * [upstream-generated]
     * upstream-id: Puppeteer.connect
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/packages/puppeteer-core/src/common/Puppeteer.ts#L122 Upstream
     * @param array{acceptInsecureCerts?: bool, browserURL?: string, browserWSEndpoint?: string, capabilities?: array{alwaysMatch?: array{acceptInsecureCerts?: bool, browserName?: string, browserVersion?: string, platformName?: string, proxy?: array{proxyType: "autodetect", ...<string, mixed>}|array{proxyType: "direct", ...<string, mixed>}|array{proxyType: "manual", httpProxy?: string, sslProxy?: string, noProxy?: list<string>, ...<string, mixed>}|array{proxyType: "manual", httpProxy?: string, sslProxy?: string, socksProxy: string, socksVersion: int|float, noProxy?: list<string>, ...<string, mixed>}|array{proxyType: "pac", proxyAutoconfigUrl: string, ...<string, mixed>}|array{proxyType: "system", ...<string, mixed>}, unhandledPromptBehavior?: array{alert?: "accept"|"dismiss"|"ignore", beforeUnload?: "accept"|"dismiss"|"ignore", confirm?: "accept"|"dismiss"|"ignore", default?: "accept"|"dismiss"|"ignore", file?: "accept"|"dismiss"|"ignore", prompt?: "accept"|"dismiss"|"ignore"}, ...<string, mixed>}, firstMatch?: list<array{acceptInsecureCerts?: bool, browserName?: string, browserVersion?: string, platformName?: string, proxy?: array{proxyType: "autodetect", ...<string, mixed>}|array{proxyType: "direct", ...<string, mixed>}|array{proxyType: "manual", httpProxy?: string, sslProxy?: string, noProxy?: list<string>, ...<string, mixed>}|array{proxyType: "manual", httpProxy?: string, sslProxy?: string, socksProxy: string, socksVersion: int|float, noProxy?: list<string>, ...<string, mixed>}|array{proxyType: "pac", proxyAutoconfigUrl: string, ...<string, mixed>}|array{proxyType: "system", ...<string, mixed>}, unhandledPromptBehavior?: array{alert?: "accept"|"dismiss"|"ignore", beforeUnload?: "accept"|"dismiss"|"ignore", confirm?: "accept"|"dismiss"|"ignore", default?: "accept"|"dismiss"|"ignore", file?: "accept"|"dismiss"|"ignore", prompt?: "accept"|"dismiss"|"ignore"}, ...<string, mixed>}>}, channel?: "chrome"|"chrome-beta"|"chrome-canary"|"chrome-dev", defaultViewport?: null|array{deviceScaleFactor?: int|float, hasTouch?: bool, height: int|float, isLandscape?: bool, isMobile?: bool, width: int|float}, downloadBehavior?: array{downloadPath?: string, policy: "deny"|"allow"|"allowAndName"|"default"}, handleDevToolsAsPage?: bool, headers?: array<string, string>, networkEnabled?: bool, protocol?: "cdp"|"webDriverBiDi", protocolTimeout?: int|float, slowMo?: int|float, targetFilter?: (callable(\Nesk\Puphpeteer\Target):bool), transport?: \Nesk\Puphpeteer\ConnectionTransport} $options
     * @return \Amp\Future<\Nesk\Puphpeteer\Browser>
     * [/upstream-generated]
     */
    public function connect(array $options): \Amp\Future
    {
        return \Amp\async(static function () use ($options): Browser {
            if (($options['protocol'] ?? 'cdp') !== 'cdp') {
                throw new \InvalidArgumentException('Only the CDP protocol is currently supported');
            }
            $sources = (int) isset($options['browserWSEndpoint']) + (int) isset($options['browserURL']) + (int) isset($options['transport']) + (int) isset($options['channel']);
            if ($sources !== 1) {
                throw new \InvalidArgumentException('Exactly one of browserWSEndpoint, browserURL, transport or channel must be passed to puppeteer.connect');
            }
            $timeout = (float) ($options['protocolTimeout'] ?? 180000) / 1000.0;
            if ($timeout < 0 || ($options['slowMo'] ?? 0) < 0) {
                throw new \InvalidArgumentException('Timeout and slowMo must not be negative');
            }
            if (isset($options['transport'])) {
                $connection = new Internal\Connection($options['transport'], $timeout);
            } else {
                $endpoint = isset($options['channel']) ? Internal\ChannelEndpoint::resolve($options['channel']) : ($options['browserWSEndpoint'] ?? null);
                if ($endpoint === null) {
                    $url = $options['browserURL'] ?? '';
                    $parts = parse_url($url);
                    if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['http', 'https'], true)) {
                        throw new \InvalidArgumentException('browserURL must be an absolute HTTP or HTTPS URL');
                    }
                    $url = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/json/version';
                    try {
                        $client = \Amp\Http\Client\HttpClientBuilder::buildDefault();
                        $request = new \Amp\Http\Client\Request($url);
                        $request->setTransferTimeout($timeout);
                        $response = $client->request($request);
                        if ($response->getStatus() !== 200) {
                            throw new \RuntimeException('HTTP ' . $response->getStatus());
                        }
                        $version = json_decode($response->getBody()->buffer(), true, 512, JSON_THROW_ON_ERROR);
                        if (!is_array($version) || !is_string($version['webSocketDebuggerUrl'] ?? null)) {
                            throw new \RuntimeException('Missing webSocketDebuggerUrl');
                        }
                        $endpoint = $version['webSocketDebuggerUrl'];
                    } catch (\Throwable $error) {
                        throw new \RuntimeException('Failed to fetch browser webSocket URL from ' . $url . ': ' . $error->getMessage(), 0, $error);
                    }
                }
                $connection = Internal\Connection::open($endpoint, $timeout, $options['headers'] ?? []);
            }
            try {
                $connection->setSlowMo((float) ($options['slowMo'] ?? 0) / 1000.0);
                $connection->send('Target.getBrowserContexts')->await();
                if ($options['acceptInsecureCerts'] ?? false) {
                    $connection->send('Security.setIgnoreCertificateErrors', ['ignore' => true])->await();
                }
                if (isset($options['downloadBehavior'])) {
                    $download = $options['downloadBehavior'];
                    $params = ['behavior' => $download['policy']];
                    if (isset($download['downloadPath'])) {
                        $params['downloadPath'] = $download['downloadPath'];
                    }
                    $connection->send('Browser.setDownloadBehavior', $params)->await();
                }
                return new Browser($connection, $options);
            } catch (\Throwable $error) {
                try {
                    $connection->close();
                } catch (\Throwable) {
                    // Keep the connection failure when a custom transport also fails to close.
                }
                throw $error;
            }
        });
    }
    /**
     * [upstream-generated]
     * upstream-id: Puppeteer.constructor
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/packages/puppeteer-core/src/common/Puppeteer.ts#L108 Upstream
     * [/upstream-generated]
     */
    public function __construct()
    {

    }
}
