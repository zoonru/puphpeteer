<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Internal;

/** Discovers an already running Chrome channel through its debugging endpoint file.
 * @internal
 * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/packages/puppeteer-core/src/common/BrowserConnector.ts
 */
final class ChannelEndpoint
{
    public static function resolve(string $channel): string
    {
        $suffix = match ($channel) {
            'chrome' => '', 'chrome-beta' => ' Beta', 'chrome-dev' => ' Dev', 'chrome-canary' => ' Canary',
            default => throw new \InvalidArgumentException('Unknown Chrome channel: ' . $channel),
        };
        $homeDirectory = getenv('HOME') ?: getenv('USERPROFILE');
        if ($homeDirectory === false) {
            throw new \RuntimeException('Cannot determine the Chrome profile directory');
        }
        $directory = match (PHP_OS_FAMILY) {
            'Darwin' => $homeDirectory . '/Library/Application Support/Google/Chrome' . $suffix,
            'Windows' => (getenv('LOCALAPPDATA') ?: $homeDirectory . '/AppData/Local') . '/Google/Chrome' . ($suffix === ' Canary' ? ' SxS' : $suffix) . '/User Data',
            'Linux' => (getenv('CHROME_CONFIG_HOME') ?: getenv('XDG_CONFIG_HOME') ?: $homeDirectory . '/.config') . '/' . match ($channel) {
                'chrome' => 'google-chrome', 'chrome-beta' => 'google-chrome-beta',
                'chrome-dev' => 'google-chrome-unstable', 'chrome-canary' => 'google-chrome-canary',
            },
            default => throw new \RuntimeException('Unsupported Chrome platform: ' . PHP_OS_FAMILY),
        };
        $path = $directory . '/DevToolsActivePort';
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Could not find DevToolsActivePort for ' . $channel . ' at ' . $path);
        }
        return self::parse($contents);
    }

    public static function parse(string $contents): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $contents)), static fn (string $line): bool => $line !== ''));
        $port = $lines[0] ?? '';
        $path = $lines[1] ?? '';
        if (!preg_match('/^[0-9]+$/D', $port) || (int) $port < 1 || (int) $port > 65535
            || !preg_match('~^/devtools/browser/[A-Za-z0-9-]+$~D', $path)) {
            throw new \UnexpectedValueException('Invalid DevToolsActivePort contents');
        }
        return 'ws://localhost:' . (int) $port . $path;
    }
}
