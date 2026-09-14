<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Nesk\Puphpeteer\JsFunction;
use Nesk\Puphpeteer\Puppeteer\Puppeteer;
use Nesk\Puphpeteer\Puppeteer\Target;

function pluginCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$puppeteer = (new Puppeteer())->use('stealth');
$browser = $puppeteer->launch(['headless' => true]);
try {
    $context = $browser->createBrowserContext();
    $page = $context->newPage();
    $page->goto('data:text/html,<title>plugins</title><iframe srcdoc="<p>frame</p>"></iframe>');
    $probe = new JsFunction('() => ({webdriver:navigator.webdriver, ua:navigator.userAgent, languages:[...navigator.languages], cores:navigator.hardwareConcurrency})');
    $value = $page->evaluate($probe);
    pluginCheck(false === $value['webdriver'], 'webdriver launch hook');
    pluginCheck(!str_contains($value['ua'], 'HeadlessChrome'), 'upstream user-agent evasion');
    pluginCheck($value['languages'] === ['en-US', 'en'], 'upstream languages evasion');
    pluginCheck(4 === $value['cores'], 'upstream hardwareConcurrency evasion');
    $frames = $page->frames();
    pluginCheck(2 === count($frames), 'iframe attached');
    pluginCheck(4 === $frames[1]->evaluate($probe)['cores'], 'frame receives document injection');

    // Native window.open creates its own target; waitForTarget + target.page()
    // must await plugin initialization before subsequent client navigation.
    $page->evaluate(new JsFunction('() => { window.open("about:blank", "plugin-popup"); }'));
    $target = $browser->waitForTarget(static fn (Target $target): bool => null !== $target->opener());
    $popup = $target->page();
    $popup->goto('data:text/html,<title>popup</title>');
    pluginCheck(4 === $popup->evaluate($probe)['cores'], 'popup receives evasion before client navigation');
    $popup->close();
    $context->close();

    // A separate QuickJS client applies beforeConnect and onBrowser hooks too.
    $connected = (new Puppeteer())->use('stealth', ['enabledEvasions' => ['navigator.hardwareConcurrency']])->connect(['browserWSEndpoint' => $browser->wsEndpoint()]);
    try {
        $connectedPage = $connected->newPage();
        $connectedPage->goto('data:text/html,<title>connected</title>');
        pluginCheck(4 === $connectedPage->evaluate($probe)['cores'], 'connect lifecycle');
        $connectedPage->close();
    } finally {
        $connected->disconnect();
    }
} finally {
    $browser->close();
}

try {
    (new Puppeteer())->use('not-bundled')->launch();
    throw new LogicException('Missing plugin accepted');
} catch (RuntimeException $error) {
    pluginCheck(str_contains($error->getMessage(), 'not bundled'), 'unknown plugin rejected before launch');
}
echo "Plugin launch/connect, pages, frames and popups PASS\n";
