<?php

declare(strict_types=1);

use Nesk\Puphpeteer\JsFunction;
use Nesk\Puphpeteer\Puppeteer\Connection;
use Nesk\Puphpeteer\Puppeteer\Locator;
use Nesk\Puphpeteer\Puppeteer\Puppeteer;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

function verify(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

$puppeteer = new Puppeteer();
$locatorClass = $puppeteer->getStaticClass(Locator::class);
$puppeteer->registerCustomQueryHandler('testid', ['queryOne' => new JsFunction('(root, selector) => root.querySelector(`[data-testid="${selector}"]`)')]);
verify(['testid'] === $puppeteer->customQueryHandlerNames(), 'Static method before launch');
$browser = $puppeteer->launch();
try {
    $page = $browser->newPage();
    $page->setContent('<button data-testid="submit" onclick="this.textContent = \'clicked\'">ready</button>');
    verify(null !== $page->querySelector('testid/submit'), 'Custom handler in the browser runtime');
    $page->setDefaultTimeout(3000);
    $locator = $page->locator('testid/submit');
    verify(null !== $page->waitForSelector('testid/submit'), 'Direct waitForSelector');
    verify(null !== $locator->waitHandle(), 'Pre-race waitHandle');
    $locator->click();
    verify($locator instanceof Locator, 'Typed locator');
    verify($locatorClass === $page->getStaticClass(Locator::class), 'Constructor identity and prelaunch runtime reuse');
    $locatorClass->race([$page->locator('#missing'), $locator])->setTimeout(3000)->click();
    verify('clicked' === $page->evaluate('document.querySelector("button").textContent'), 'Static race with automatic await');
    $locator->race([$locator])->click();
    $page->locator(new JsFunction('() => document.querySelector("button")'))->click();
    $locator->filter(new JsFunction('element => element.textContent === "clicked"'))->click();
    verify('clicked' === $locator->map(new JsFunction('element => element.textContent'))->wait(), 'Locator JS mapper');
    verify(null !== $locator->waitHandle(), 'Locator handle hydration');
    $page->bridgeTestProperty = ['value' => 42];
    verify(['value' => 42] === $page->bridgeTestProperty, 'Remote property write and read');
    try {
        $page->__set('keyboard', null);
        throw new LogicException('Readonly remote property was writable');
    } catch (RuntimeException $error) {
        verify(str_contains($error->getMessage(), 'not writable'), 'Readonly JS property error');
    }
    $session = $page->createCDPSession();
    $connection = $page->getStaticClass(Connection::class)->fromSession($session);
    verify($connection instanceof Connection, 'Static factory hydration');
    verify($connection === $connection->fromSession($session), 'Static call through initialized instance');
    $session->detach();
    $other = (new Puppeteer())->connect(['browserWSEndpoint' => $browser->wsEndpoint()]);
    try {
        try {
            $other->getStaticClass(Locator::class)->race([$locator]);
            throw new LogicException('Cross-runtime locator was accepted');
        } catch (InvalidArgumentException $error) {
            verify(str_contains($error->getMessage(), 'another client'), 'Reject cross-runtime static arguments');
        }
    } finally {
        $other->disconnect();
    }
    $puppeteer->unregisterCustomQueryHandler('testid');
    verify([] === $puppeteer->customQueryHandlerNames(), 'Unregister static handler');
    $puppeteer->registerCustomQueryHandler('another', ['queryOne' => new JsFunction('(root, selector) => root.querySelector(selector)')]);
    $puppeteer->clearCustomQueryHandlers();
    verify([] === $puppeteer->customQueryHandlerNames(), 'Clear static handlers');
} finally {
    $browser->close();
}
echo "Static classes, query handlers, Locator and writable properties PASS\n";
