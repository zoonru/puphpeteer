<?php

declare(strict_types=1);
require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Nesk\Puphpeteer\Puppeteer\Puppeteer;
use Amp\TimeoutCancellation;
use function Amp\async;

$endpoint = getenv('BROWSER_WS') ?: throw new RuntimeException('Set BROWSER_WS');
$parts = parse_url($endpoint);
parse_str($parts['query'] ?? '', $query);
$base = explode('?', $endpoint, 2)[0];
$invalid = $query;
$invalid['token'] = 'deliberately-invalid-token';
try {
    $browser = (new Puppeteer())->connect(['browserWSEndpoint' => $base . '?' . http_build_query($invalid), 'protocolTimeout' => 3000]);
    $browser->disconnect();
    throw new LogicException('Invalid token was accepted');
} catch (Amp\Websocket\Client\WebsocketConnectException $error) {
    if (!preg_match('/401|403/', $error->getMessage())) { throw $error; }
}
// The session must end before the longer client/CDP timeout.
$query['timeout'] = 2500;
$browser = (new Puppeteer())->connect(['browserWSEndpoint' => $base . '?' . http_build_query($query), 'protocolTimeout' => 10000]);
try {
    $page = $browser->newPage();
    if ($page->evaluate('42') !== 42) { throw new RuntimeException('Browserless request failed'); }
    $pending = async(fn() => $page->evaluate('new Promise(() => {})'));
    try { $pending->await(new TimeoutCancellation(6)); throw new LogicException('Server session timeout did not terminate pending call'); }
    catch (Amp\CancelledException $error) { throw new RuntimeException('Client deadline elapsed before Browserless timeout', previous: $error); }
    catch (RuntimeException $error) {
        if (!preg_match('/clos|disconnect|socket/i', $error->getMessage())) { throw $error; }
    }
    if ($browser->connected) { throw new RuntimeException('Expired browserless session remains connected'); }
} finally { $browser->disconnect(); }
echo "Browserless authentication and session timeout PASS\n";
