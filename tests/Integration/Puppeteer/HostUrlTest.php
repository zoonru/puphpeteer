<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Integration\Puppeteer;

use Nesk\Puphpeteer\JsFunction;
use Nesk\Puphpeteer\JsRuntime;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HostUrlTest extends TestCase
{
    public function testCookieUrlParsingInQuickJs(): void
    {
        $runtime = new JsRuntime();
        self::assertSame([
            'https://xn--e1afmkfd.xn--p1ai:8443',
            'xn--e1afmkfd.xn--p1ai',
            '8443',
            '/cookies',
            'https://xn--e1afmkfd.xn--p1ai',
            'http://[::1]:8080',
            'https://example.com/next?q=1',
        ], $runtime->run(new JsFunction(<<<'JS'
            () => {
                const url = new URL('https://user:password@пример.рф:8443/cookies');
                return [url.origin, url.hostname, url.port, url.pathname,
                    url.origin.replace(`:${url.port}`, ''),
                    new URL('http://[::1]:8080/').origin,
                    new URL('../next?q=1', 'https://example.com/path/page').href];
            }
            JS)));
    }

    public function testSearchParametersAndInvalidUrls(): void
    {
        $runtime = new JsRuntime();
        self::assertSame(['https://example.com/?q=a+b&tag=one&tag=two', 'a b', ['one', 'two'], false, null], $runtime->run(new JsFunction(<<<'JS'
            () => {
                const url = new URL('https://example.com:443/');
                url.searchParams.set('q', 'a b');
                url.searchParams.append('tag', 'one');
                url.searchParams.append('tag', 'two');
                const params = new URLSearchParams(url.search);
                return [url.href, params.get('q'), params.getAll('tag'),
                    URL.canParse('invalid'), URL.parse('invalid')];
            }
            JS)));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TypeError');
        $runtime->run(new JsFunction('() => new URL("invalid")'));
    }
}
