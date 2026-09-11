<?php

declare(strict_types=1);
namespace Nesk\Puphpeteer;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Nesk\Puphpeteer\Internal\BrowserProcess;

/** Public entry point. All browser operations automatically await the internal transport. */
final class Puppeteer
{
    /**
     * @param array{bundle?:string,read_timeout?:int|float} $options
     * @psalm-mutation-free
     */
    public function __construct(private array $options = [])
    {
        self::validateOptions($options, ['bundle', 'read_timeout']);
    }

    /** @param array{browserWSEndpoint?:string,browserURL?:string,defaultViewport?:array|null,protocolTimeout?:int|float,slowMo?:int|float,acceptInsecureCerts?:bool,ignoreHTTPSErrors?:bool,targetFilter?:\Closure|JsFunction,isPageTarget?:\Closure|JsFunction,protocol?:string,capabilities?:array} $options */
    public function connect(array $options): Browser
    {
        return $this->connectClient($options)[1];
    }

    /** @return array{Client,Browser} */
    private function connectClient(array $options): array
    {
        self::validateOptions($options, ['browserWSEndpoint', 'browserURL', 'defaultViewport', 'protocolTimeout', 'slowMo', 'acceptInsecureCerts', 'ignoreHTTPSErrors', 'targetFilter', 'isPageTarget', 'protocol', 'capabilities']);
        if (isset($options['browserWSEndpoint']) === isset($options['browserURL'])) {
            throw new \InvalidArgumentException('Provide exactly one of browserWSEndpoint or browserURL');
        }
        $endpoint = $options['browserWSEndpoint'] ?? null;
        if ($endpoint === null) {
            $response = HttpClientBuilder::buildDefault()->request(new Request(rtrim($options['browserURL'], '/') . '/json/version'));
            if ($response->getStatus() !== 200) { throw new \RuntimeException('Cannot resolve browserURL: HTTP ' . $response->getStatus()); }
            $version = json_decode($response->getBody()->buffer(), true, 512, JSON_THROW_ON_ERROR);
            $endpoint = $version['webSocketDebuggerUrl'] ?? null;
        }
        if (!is_string($endpoint) || !preg_match('~^wss?://~', $endpoint)) {
            throw new \InvalidArgumentException('Invalid browser WebSocket endpoint');
        }
        unset($options['browserURL'], $options['browserWSEndpoint']);
        if (isset($options['ignoreHTTPSErrors'])) {
            $options['acceptInsecureCerts'] ??= $options['ignoreHTTPSErrors'];
            unset($options['ignoreHTTPSErrors']);
        }
        if (isset($this->options['read_timeout'])) { $options['protocolTimeout'] ??= (float) $this->options['read_timeout'] * 1000.0; }
        $client = new Client($this->options['bundle'] ?? null);
        try { $browser = $client->connect($endpoint, $options)->await(); }
        catch (\Throwable $error) { $client->close(); throw $error; }
        return [$client, $browser];
    }

    /** @param array{executablePath?:string,headless?:bool|'shell',args?:list<string>,ignoreDefaultArgs?:bool|list<string>,userDataDir?:string,env?:array<string,string>,dumpio?:bool,devtools?:bool,timeout?:int|float,defaultViewport?:array|null,protocolTimeout?:int|float,slowMo?:int|float,acceptInsecureCerts?:bool,ignoreHTTPSErrors?:bool} $options */
    public function launch(array $options = []): Browser
    {
        self::validateOptions($options, ['executablePath', 'headless', 'args', 'ignoreDefaultArgs', 'userDataDir', 'env', 'dumpio', 'devtools', 'timeout', 'defaultViewport', 'protocolTimeout', 'slowMo', 'acceptInsecureCerts', 'ignoreHTTPSErrors']);
        $process = new BrowserProcess($options['executablePath'] ?? $this->executablePath(), $options);
        try {
            $connectOptions = array_intersect_key($options, array_flip(['defaultViewport', 'protocolTimeout', 'slowMo', 'acceptInsecureCerts', 'ignoreHTTPSErrors']));
            [$client, $browser] = $this->connectClient(['browserWSEndpoint' => $process->endpoint, ...$connectOptions]);
            $client->ownBrowser($process);
            return $browser;
        } catch (\Throwable $error) {
            $process->close();
            throw $error;
        }
    }

    public function executablePath(): string
    {
        return Internal\BrowserExecutable::resolve(dirname(__DIR__));
    }

    /** @return list<string> */
    public function defaultArgs(array $options = []): array
    {
        self::validateOptions($options, ['headless', 'devtools', 'ignoreDefaultArgs', 'args']);
        return BrowserProcess::defaultArgs($options);
    }

    /**
     * @param list<string> $supported
     * @psalm-pure
     */
    private static function validateOptions(array $options, array $supported): void
    {
        $unknown = array_diff(array_keys($options), $supported);
        if ($unknown !== []) { throw new \InvalidArgumentException('Unsupported Puppeteer options: ' . implode(', ', $unknown)); }
    }
}
