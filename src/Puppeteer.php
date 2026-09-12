<?php

declare(strict_types=1);
namespace Nesk\Puphpeteer;

use Amp\TimeoutCancellation;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Nesk\Puphpeteer\Internal\BrowserProcess;

/** Public entry point. All browser operations automatically await the internal transport. */
final class Puppeteer
{
    /** @var array<string,array> */
    private array $plugins = [];

    /**
     * Register a factory from the compiled plugin registry for future browsers.
     * @psalm-external-mutation-free
     */
    public function use(string $name, array $options = []): self
    {
        $name = preg_replace('/^puppeteer-extra-plugin-/', '', $name) ?? $name;
        if ($name === '') { throw new \InvalidArgumentException('Plugin name cannot be empty'); }
        $this->plugins[$name] = $options;
        return $this;
    }

    /** @return array */
    private function preparePlugins(Client $client, array $options, string $mode): array
    {
        $definitions = [];
        foreach ($this->plugins as $name => $configuration) { $definitions[] = ['name' => $name, 'options' => $configuration]; }
        $timeout = $mode === 'launch' ? ($options['timeout'] ?? 30000) : ($options['protocolTimeout'] ?? ((float) ($this->options['read_timeout'] ?? 180) * 1000.0));
        $cancellation = $timeout > 0 ? new TimeoutCancellation($timeout / 1000) : null;
        $prepared = $client->call(0, 'preparePlugins', [$definitions, $options, $mode], cancellation: $cancellation)->await();
        if (!is_array($prepared)) { throw new \UnexpectedValueException('Plugin options must be an array'); }
        return $prepared;
    }

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
    private function connectClient(array $options, ?Client $client = null): array
    {
        if ($client === null && $this->plugins !== []) {
            $client = new Client($this->options['bundle'] ?? null);
            try { $options = $this->preparePlugins($client, $options, 'connect'); }
            catch (\Throwable $error) { $client->close(); throw $error; }
        }
        try {
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
            $client ??= new Client($this->options['bundle'] ?? null);
            $browser = $client->connect($endpoint, $options)->await();
            return [$client, $browser];
        } catch (\Throwable $error) { $client?->close(); throw $error; }
    }

    /** @param array{executablePath?:string,headless?:bool|'shell',args?:list<string>,ignoreDefaultArgs?:bool|list<string>,userDataDir?:string,env?:array<string,string>,dumpio?:bool,devtools?:bool,timeout?:int|float,defaultViewport?:array|null,protocolTimeout?:int|float,slowMo?:int|float,acceptInsecureCerts?:bool,ignoreHTTPSErrors?:bool} $options */
    public function launch(array $options = []): Browser
    {
        self::validateOptions($options, ['executablePath', 'headless', 'args', 'ignoreDefaultArgs', 'userDataDir', 'env', 'dumpio', 'devtools', 'timeout', 'defaultViewport', 'protocolTimeout', 'slowMo', 'acceptInsecureCerts', 'ignoreHTTPSErrors']);
        $client = null;
        $process = null;
        try {
            if ($this->plugins !== []) {
                $client = new Client($this->options['bundle'] ?? null);
                $options = $this->preparePlugins($client, $options, 'launch');
                self::validateOptions($options, ['executablePath', 'headless', 'args', 'ignoreDefaultArgs', 'userDataDir', 'env', 'dumpio', 'devtools', 'timeout', 'defaultViewport', 'protocolTimeout', 'slowMo', 'acceptInsecureCerts', 'ignoreHTTPSErrors']);
            }
            $process = new BrowserProcess($options['executablePath'] ?? $this->executablePath(), $options);
            $connectOptions = array_intersect_key($options, array_flip(['defaultViewport', 'protocolTimeout', 'slowMo', 'acceptInsecureCerts', 'ignoreHTTPSErrors']));
            [$client, $browser] = $this->connectClient(['browserWSEndpoint' => $process->endpoint, ...$connectOptions], $client);
            $client->ownBrowser($process);
            return $browser;
        } catch (\Throwable $error) {
            $client?->close();
            $process?->close();
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
