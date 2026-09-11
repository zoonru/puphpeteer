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
        throw new \LogicException('NotImplemented: Puppeteer.connect');
    }
    /**
     * [upstream-generated]
     * upstream-id: Puppeteer.constructor
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/packages/puppeteer-core/src/common/Puppeteer.ts#L108 Upstream
     * [/upstream-generated]
     */
    public function __construct()
    {
        throw new \LogicException('NotImplemented: Puppeteer.constructor');
    }
}
