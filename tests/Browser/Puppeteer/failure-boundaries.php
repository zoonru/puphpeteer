<?php

declare(strict_types=1);
require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use Nesk\Puphpeteer\JsFunction;
use Nesk\Puphpeteer\Puppeteer\Puppeteer;
use Nesk\Puphpeteer\RemoteObject;
use Nesk\Puphpeteer\Tests\Support\Shared\ProcessRunner;

use function Amp\async;

function boundaryCheck(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

$browser = (new Puppeteer())->launch([
    'headless' => true,
    'slowMo' => 20,
    'defaultViewport' => ['width' => 913, 'height' => 617, 'deviceScaleFactor' => 2],
    'args' => ['--user-agent=PuPHPeteer-Test/1.0'],
]);
try {
    $page = $browser->newPage();
    $actual = $page->evaluate(new JsFunction('() => [innerWidth, innerHeight, devicePixelRatio, navigator.userAgent]'));
    boundaryCheck($actual === [913, 617, 2, 'PuPHPeteer-Test/1.0'], 'Launch options did not reach Chrome: ' . json_encode($actual));
    $start = hrtime(true);
    for ($i = 0; $i < 3; ++$i) {
        $page->evaluate('42');
    }
    boundaryCheck((hrtime(true) - $start) / 1e6 >= 45, 'slowMo was ignored');
} finally {
    $browser->close();
}

foreach (['socket', 'crash'] as $failure) {
    $browser = (new Puppeteer())->launch(['protocolTimeout' => 10000]);
    $client = (new ReflectionProperty(RemoteObject::class, 'client'))->getValue($browser);
    $owner = (new ReflectionProperty($client, 'browserProcess'))->getValue($client);
    $profile = (new ReflectionProperty($owner, 'temporaryProfile'))->getValue($owner);
    $process = (new ReflectionProperty($owner, 'process'))->getValue($owner);
    try {
        $page = $browser->newPage();
        $pending = [async(fn () => $page->evaluate('new Promise(() => {})')), async(fn () => $page->evaluate('new Promise(() => {})'))];
        Amp\delay(0.05);
        if ('crash' === $failure) {
            $process->kill();
        } else {
            (new ReflectionProperty($client, 'socket'))->getValue($client)->close();
        }
        foreach ($pending as $future) {
            try {
                $future->await(new TimeoutCancellation(2));
                throw new LogicException('Pending operation survived transport failure');
            } catch (CancelledException $error) {
                throw new RuntimeException('Operation hung after transport failure', previous: $error);
            } catch (RuntimeException) {
            }
        }
        boundaryCheck($client->isClosed(), 'Transport must be closed');
        foreach (['pending', 'callbacks', 'timers', 'objects', 'streams'] as $name) {
            boundaryCheck([] === (new ReflectionProperty($client, $name))->getValue($client), 'Registry retained after failure: ' . $name);
        }
        if ('socket' === $failure) {
            $reconnected = (new Puppeteer())->connect(['browserWSEndpoint' => $browser->wsEndpoint()]);
            try {
                boundaryCheck(42 === $reconnected->newPage()->evaluate('42'), 'New connection cannot use surviving Chrome');
            } finally {
                $reconnected->close();
            }
        }
    } finally {
        $browser->close();
    }
    boundaryCheck(!$process->isRunning() && !is_dir($profile), 'Owned process/profile retained after failure');
}
$profile = sys_get_temp_dir() . '/puphpeteer-user-profile-' . bin2hex(random_bytes(8));
mkdir($profile, 0700);
file_put_contents($profile . '/user-sentinel', 'keep');
try {
    $browser = (new Puppeteer())->launch(['userDataDir' => $profile]);
    $browser->newPage()->evaluate('42');
    $browser->close();
    boundaryCheck('keep' === file_get_contents($profile . '/user-sentinel'), 'User profile was deleted');
} finally {
    ProcessRunner::removeDirectory($profile);
}
echo "Launch options, slowMo, transport loss and Chrome crash PASS\n";
