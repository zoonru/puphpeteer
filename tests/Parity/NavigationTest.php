<?php

/**
 * Assertions ported from Puppeteer. Copyright Google Inc.
 * SPDX-License-Identifier: Apache-2.0
 * See tests/Support/assets/LICENSE.
 */

declare(strict_types=1);

require_once __DIR__ . '/../Support/BrowserTestCase.php';

final class NavigationTest extends BrowserTestCase
{
    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should disable timeout when its set to 0
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L300 Upstream test
     */
    public function testPageGotoShouldDisableTimeoutWhenItsSetTo0(): void
    {
        $loaded = false;
        $listener = $this->onCdp('Page.loadEventFired', static function () use (&$loaded): void { $loaded = true; });
        try {
            $this->page->goto($this->url('/grid.html'), ['timeout' => 0, 'waitUntil' => ['load']])->await();
            self::assertTrue($loaded);
        } finally {
            $this->offCdp('Page.loadEventFired', $listener);
        }
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should fail when exceeding default maximum navigation timeout
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L260 Upstream test
     */
    public function testPageGotoShouldFailWhenExceedingDefaultMaximumNavigationTimeout(): void
    {
        $this->setPageTimeout('defaultNavigationTimeout', 1);
        $this->assertNavigationTimeout([]);
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should fail when exceeding default maximum timeout
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L273 Upstream test
     */
    public function testPageGotoShouldFailWhenExceedingDefaultMaximumTimeout(): void
    {
        $this->setPageTimeout('defaultTimeout', 1);
        $this->assertNavigationTimeout([]);
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should fail when exceeding maximum navigation timeout
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L246 Upstream test
     */
    public function testPageGotoShouldFailWhenExceedingMaximumNavigationTimeout(): void
    {
        $this->assertNavigationTimeout(['timeout' => 1]);
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should fail when main resources failed to load
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L233 Upstream test
     */
    public function testPageGotoShouldFailWhenMainResourcesFailedToLoad(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assertStringContainsString('net::ERR_CONNECTION_REFUSED', $this->navigationError('http://' . $address . '/non-existing-url')->getMessage());
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should fail when navigating and show the url at the error message
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L569 Upstream test
     */
    public function testPageGotoShouldFailWhenNavigatingAndShowTheUrlAtTheErrorMessage(): void
    {
        $url = $this->httpsUrl('/redirect/1.html');
        self::assertStringContainsString($url, $this->navigationError($url)->getMessage());
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should fail when navigating to bad SSL
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L196 Upstream test
     */
    public function testPageGotoShouldFailWhenNavigatingToBadSSL(): void
    {
        $events = [];
        $ids = [];
        foreach (['Network.requestWillBeSent', 'Network.loadingFinished', 'Network.loadingFailed'] as $event) {
            $ids[$event] = $this->onCdp($event, static function () use (&$events, $event): void { $events[] = $event; });
        }
        try {
            self::assertMatchesRegularExpression('/net::ERR_CERT_(INVALID|AUTHORITY_INVALID)/', $this->navigationError($this->httpsUrl('/empty.html'))->getMessage());
            self::assertSame(['Network.requestWillBeSent', 'Network.loadingFailed'], $events);
        } finally {
            foreach ($ids as $event => $id) { $this->offCdp($event, $id); }
        }
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should fail when navigating to bad SSL after redirects
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L222 Upstream test
     */
    public function testPageGotoShouldFailWhenNavigatingToBadSSLAfterRedirects(): void
    {
        $this->setRoute('/redirect/1.html', fn () => ['status' => 302, 'headers' => ['location' => $this->httpsUrl('/redirect/2.html')]]);
        self::assertMatchesRegularExpression('/net::ERR_CERT_(INVALID|AUTHORITY_INVALID)/', $this->navigationError($this->url('/redirect/1.html'))->getMessage());
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should fail when navigating to bad url
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L179 Upstream test
     */
    public function testPageGotoShouldFailWhenNavigatingToBadUrl(): void
    {
        self::assertMatchesRegularExpression('/invalid argument|Cannot navigate to invalid URL/i', $this->navigationError('asdfasdf')->getMessage());
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should fail when server returns 204
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L89 Upstream test
     */
    public function testPageGotoShouldFailWhenServerReturns204(): void
    {
        $this->setRoute('/empty.html', static fn () => ['status' => 204]);
        self::assertStringContainsString('net::ERR_ABORTED', $this->navigationError($this->url('/empty.html'))->getMessage());
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should navigate to about:blank
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L50 Upstream test
     */
    public function testPageGotoShouldNavigateToAboutBlank(): void
    {
        self::assertNull($this->page->goto('about:blank')->await());
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should navigate to dataURL and fire dataURL requests
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L536 Upstream test
     */
    public function testPageGotoShouldNavigateToDataURLAndFireDataURLRequests(): void
    {
        $url = 'data:text/html,<div>yo</div>';
        $requests = [];
        $id = $this->onCdp('Network.requestWillBeSent', static function (array $event) use (&$requests): void {
            if (($event['type'] ?? null) === 'Document') {
                $requests[] = $event['request']['url'] . ($event['request']['urlFragment'] ?? '');
            }
        });
        try {
            $response = $this->page->goto($url)->await();
            self::assertSame(200, $this->responseField($response, 'status'));
            self::assertSame($url, $this->responseField($response, 'url'));
            self::assertSame([$url], $requests);
        } finally { $this->offCdp('Network.requestWillBeSent', $id); }
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should navigate to empty page with domcontentloaded
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L107 Upstream test
     */
    public function testPageGotoShouldNavigateToEmptyPageWithDomcontentloaded(): void
    {
        $response = $this->page->goto($this->url('/empty.html'), ['waitUntil' => 'domcontentloaded'])->await();
        self::assertSame(200, $this->responseField($response, 'status'));
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should navigate to empty page with networkidle0
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L152 Upstream test
     */
    public function testPageGotoShouldNavigateToEmptyPageWithNetworkidle0(): void
    {
        $response = $this->page->goto($this->url('/empty.html'), ['waitUntil' => 'networkidle0'])->await();
        self::assertSame(200, $this->responseField($response, 'status'));
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should navigate to empty page with networkidle2
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L171 Upstream test
     */
    public function testPageGotoShouldNavigateToEmptyPageWithNetworkidle2(): void
    {
        $response = $this->page->goto($this->url('/empty.html'), ['waitUntil' => 'networkidle2'])->await();
        self::assertSame(200, $this->responseField($response, 'status'));
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should navigate to page with iframe and networkidle0
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L160 Upstream test
     */
    public function testPageGotoShouldNavigateToPageWithIframeAndNetworkidle0(): void
    {
        $response = $this->page->goto($this->url('/frames/one-frame.html'), ['waitUntil' => 'networkidle0'])->await();
        self::assertSame(200, $this->responseField($response, 'status'));
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should navigate to URL with hash and fire requests without hash
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L549 Upstream test
     */
    public function testPageGotoShouldNavigateToURLWithHashAndFireRequestsWithoutHash(): void
    {
        $url = $this->url('/empty.html') . '#hash';
        $requests = [];
        $id = $this->onCdp('Network.requestWillBeSent', static function (array $event) use (&$requests): void {
            if (($event['type'] ?? null) === 'Document') {
                $requests[] = $event['request']['url'] . ($event['request']['urlFragment'] ?? '');
            }
        });
        try {
            $response = $this->page->goto($url)->await();
            self::assertSame(200, $this->responseField($response, 'status'));
            self::assertSame($url, $this->responseField($response, 'url'));
            self::assertSame([$url], $requests);
        } finally { $this->offCdp('Network.requestWillBeSent', $id); }
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should not leak listeners during bad navigation
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L498 Upstream test
     */
    public function testPageGotoShouldNotLeakListenersDuringBadNavigation(): void
    {
        $before = $this->listenerCount();
        for ($i = 0; $i < 20; ++$i) { $this->navigationError('asdf'); }
        self::assertSame($before, $this->listenerCount());
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should not leak listeners during navigation
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L482 Upstream test
     */
    public function testPageGotoShouldNotLeakListenersDuringNavigation(): void
    {
        $before = $this->listenerCount();
        for ($i = 0; $i < 20; ++$i) { $this->page->goto($this->url('/empty.html'))->await(); }
        self::assertSame($before, $this->listenerCount());
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should not leak listeners during navigation of 11 pages
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L516 Upstream test
     */
    public function testPageGotoShouldNotLeakListenersDuringNavigationOf11Pages(): void
    {
        $before = $this->listenerCount();
        $tasks = [];
        for ($i = 0; $i < 20; ++$i) {
            $tasks[] = \Amp\async(function (): void {
                $page = $this->context->newPage()->await();
                $page->goto($this->url('/empty.html'))->await();
                $session = $this->readProperty($page, 'session');
                $closed = new \Amp\DeferredFuture();
                $session->observe('__session_closed', static function () use ($closed): void { $closed->complete(); });
                $session->send('Page.close')->await();
                $closed->getFuture()->await(new \Amp\TimeoutCancellation(5));
            });
        }
        \Amp\Future\await($tasks);
        self::assertSame($before, $this->listenerCount());
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should not throw an error for a 404 response with an empty body
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L353 Upstream test
     */
    public function testPageGotoShouldNotThrowAnErrorForA404ResponseWithAnEmptyBody(): void
    {
        $this->setRoute('/error', static fn () => ['status' => 404, 'body' => '']);
        $response = $this->page->goto($this->url('/error'))->await();
        self::assertSame(404, $this->responseField($response, 'status'));
        self::assertFalse($this->responseOk($response));
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should not throw an error for a 500 response with an empty body
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L365 Upstream test
     */
    public function testPageGotoShouldNotThrowAnErrorForA500ResponseWithAnEmptyBody(): void
    {
        $this->setRoute('/error', static fn () => ['status' => 500, 'body' => '']);
        $response = $this->page->goto($this->url('/error'))->await();
        self::assertSame(500, $this->responseField($response, 'status'));
        self::assertFalse($this->responseOk($response));
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should prioritize default navigation timeout over default timeout
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L286 Upstream test
     */
    public function testPageGotoShouldPrioritizeDefaultNavigationTimeoutOverDefaultTimeout(): void
    {
        $this->setPageTimeout('defaultTimeout', 0);
        $this->setPageTimeout('defaultNavigationTimeout', 1);
        $this->assertNavigationTimeout([]);
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should return last response in redirect chain
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L377 Upstream test
     */
    public function testPageGotoShouldReturnLastResponseInRedirectChain(): void
    {
        $this->redirectChain();
        $response = $this->page->goto($this->url('/redirect/1.html'))->await();
        self::assertTrue($this->responseOk($response));
        self::assertSame($this->url('/empty.html'), $this->responseField($response, 'url'));
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should return response when page changes its URL after load
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L56 Upstream test
     */
    public function testPageGotoShouldReturnResponseWhenPageChangesItsURLAfterLoad(): void
    {
        $response = $this->page->goto($this->url('/historyapi.html'))->await();
        self::assertSame(200, $this->responseField($response, 'status'));
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should return response when page replaces its state during load
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L62 Upstream test
     */
    public function testPageGotoShouldReturnResponseWhenPageReplacesItsStateDuringLoad(): void
    {
        $url = $this->url('/historyapi-replaceState.html');
        $response = $this->page->goto($url, ['waitUntil' => 'networkidle2'])->await();
        self::assertSame(200, $this->responseField($response, 'status'));
        self::assertSame($url, $this->page->evaluate('location.href')->await());
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should send referer
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L581 Upstream test
     */
    public function testPageGotoShouldSendReferer(): void
    {
        $this->page->goto($this->url('/grid.html'), ['referer' => 'http://google.com/'])->await();
        self::assertSame('http://google.com/', $this->requestHeaders('/grid.html')['referer'] ?? null);
        self::assertSame($this->url('/grid.html'), $this->requestHeaders('/digits/1.png')['referer'] ?? null);
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should send referer policy
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L600 Upstream test
     */
    public function testPageGotoShouldSendRefererPolicy(): void
    {
        $this->page->goto($this->url('/empty.html'), ['referrerPolicy' => 'origin'])->await();
        self::assertArrayNotHasKey('referer', $this->requestHeaders('/empty.html'));
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should wait for network idle to succeed navigation
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L387 Upstream test
     */
    public function testPageGotoShouldWaitForNetworkIdleToSucceedNavigation(): void
    {
        $initial = new \Amp\DeferredFuture();
        $second = new \Amp\DeferredFuture();
        foreach (['a', 'b', 'c', 'd'] as $suffix) {
            $gate = $suffix === 'd' ? $second : $initial;
            $this->setRoute('/fetch-request-' . $suffix . '.js', static function () use ($gate): array {
                $gate->getFuture()->await();
                return ['status' => 404, 'body' => 'File not found'];
            });
        }
        $loaded = $this->session()->waitFor('Page.loadEventFired', null, new \Amp\TimeoutCancellation(5));
        $navigation = $this->page->goto($this->url('/networkidle.html'), ['waitUntil' => 'networkidle0']);
        try {
            $loaded->await();
            self::assertFalse($navigation->isComplete());
            foreach (['a', 'b', 'c'] as $suffix) { $this->waitForRequest('/fetch-request-' . $suffix . '.js'); }
            self::assertFalse($navigation->isComplete());
            $initial->complete();
            $this->waitForRequest('/fetch-request-d.js');
            self::assertFalse($navigation->isComplete());
            $second->complete();
            self::assertTrue($this->responseOk($navigation->await()));
        } finally {
            if (!$initial->isComplete()) { $initial->complete(); }
            if (!$second->isComplete()) { $second->complete(); }
        }
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should work
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L26 Upstream test
     */
    public function testPageGotoShouldWork(): void
    {
        $url = $this->url('/empty.html');
        $this->page->goto($url)->await();
        self::assertSame($url, $this->page->evaluate('location.href')->await());
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should work when a page redirects on DOMContentLoaded
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L332 Upstream test
     */
    public function testPageGotoShouldWorkWhenAPageRedirectsOnDOMContentLoaded(): void
    {
        $response = $this->page->goto($this->url('/client-redirect-DOMContentLoaded.html'))->await();
        self::assertTrue($this->responseOk($response));
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should work when navigating to 404
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L346 Upstream test
     */
    public function testPageGotoShouldWorkWhenNavigatingTo404(): void
    {
        $this->setRoute('/error', static fn () => ['status' => 404, 'body' => '']);
        $response = $this->page->goto($this->url('/error'))->await();
        self::assertSame(404, $this->responseField($response, 'status'));
        self::assertFalse($this->responseOk($response));
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should work when navigating to a URL with a client redirect
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L323 Upstream test
     */
    public function testPageGotoShouldWorkWhenNavigatingToAURLWithAClientRedirect(): void
    {
        $response = $this->page->goto($this->url('/client-redirect.html'))->await();
        self::assertTrue($this->responseOk($response));
        // Upstream CDP returns the destination response; its original assertion targets BiDi.
        // https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/TestExpectations.json#L1330
        self::assertSame($this->url('/empty.html'), $this->responseField($response, 'url'));
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should work when navigating to data url
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L340 Upstream test
     */
    public function testPageGotoShouldWorkWhenNavigatingToDataUrl(): void
    {
        self::assertTrue($this->responseOk($this->page->goto('data:text/html,hello')->await()));
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should work when navigating to valid url
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L316 Upstream test
     */
    public function testPageGotoShouldWorkWhenNavigatingToValidUrl(): void
    {
        $response = $this->page->goto($this->url('/empty.html'))->await();
        self::assertTrue($this->responseOk($response));
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should work when page calls history API in beforeunload
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L115 Upstream test
     */
    public function testPageGotoShouldWorkWhenPageCallsHistoryAPIInBeforeunload(): void
    {
        $this->page->goto($this->url('/empty.html'))->await();
        $this->page->evaluate('() => { window.addEventListener("beforeunload", () => history.replaceState(null, "initial", location.href)); }')->await();
        self::assertSame(200, $this->responseField($this->page->goto($this->url('/grid.html'))->await(), 'status'));
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should work when reload causes history API in beforeunload
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L131 Upstream test
     */
    public function testPageGotoShouldWorkWhenReloadCausesHistoryAPIInBeforeunload(): void
    {
        $this->page->goto($this->url('/empty.html'))->await();
        $this->page->evaluate('() => { window.addEventListener("beforeunload", () => history.replaceState(null, "initial", location.href)); }')->await();
        $loaded = $this->session()->waitFor('Page.loadEventFired', null, new \Amp\TimeoutCancellation(5));
        $this->cdp('Page.reload');
        $loaded->await();
        self::assertSame(1, $this->page->evaluate('() => 1')->await());
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should work with anchor navigation
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L32 Upstream test
     */
    public function testPageGotoShouldWorkWithAnchorNavigation(): void
    {
        foreach (['', '#foo', '#bar'] as $fragment) {
            $url = $this->url('/empty.html') . $fragment;
            $this->page->goto($url)->await();
            self::assertSame($url, $this->page->evaluate('location.href')->await());
        }
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should work with redirects
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L42 Upstream test
     */
    public function testPageGotoShouldWorkWithRedirects(): void
    {
        $this->redirectChain();
        $this->page->goto($this->url('/redirect/1.html'))->await();
        self::assertSame($this->url('/empty.html'), $this->page->evaluate('location.href')->await());
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should work with self requesting page
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L562 Upstream test
     */
    public function testPageGotoShouldWorkWithSelfRequestingPage(): void
    {
        $response = $this->page->goto($this->url('/self-request.html'))->await();
        self::assertSame(200, $this->responseField($response, 'status'));
        self::assertStringContainsString('self-request.html', $this->responseField($response, 'url'));
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should work with subframes return 204
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L74 Upstream test
     */
    public function testPageGotoShouldWorkWithSubframesReturn204(): void
    {
        $this->setRoute('/frames/frame.html', static fn () => ['status' => 204]);
        self::assertNotNull($this->page->goto($this->url('/frames/one-frame.html'))->await());
    }

    /** Upstream scenario: test/src/navigation.spec.ts::with network events disabled > should work
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L1003 Upstream test
     */
    public function testShouldWorkWithNetworkEventsDisabled(): void
    {
        $browser = (new \Nesk\Puphpeteer\Puppeteer())->connect(['browserWSEndpoint' => self::$endpoint, 'networkEnabled' => false])->await();
        $context = $browser->createBrowserContext()->await();
        try {
            $page = $context->newPage()->await();
            $url = $this->url('/empty.html');
            self::assertNull($page->goto($url)->await());
            self::assertSame($url, $page->evaluate('location.href')->await());
            $tree = $this->session($page)->send('Page.getFrameTree')->await();
            self::assertSame($url, $tree['frameTree']['frame']['url']);
        } finally {
            $context->close()->await();
            $browser->disconnect()->await();
        }
    }

    private function navigationError(string $url, array $options = []): Throwable
    {
        try { $this->page->goto($url, $options)->await(); }
        catch (Throwable $error) { return $error; }
        self::fail('Navigation unexpectedly succeeded: ' . $url);
    }

    private function assertNavigationTimeout(array $options): void
    {
        $this->setRoute('/hang', static fn () => ['delay' => 60, 'body' => '']);
        $error = $this->navigationError($this->url('/hang'), $options);
        self::assertInstanceOf(\Nesk\Puphpeteer\Internal\NavigationTimeoutException::class, $error);
        self::assertStringContainsString('Navigation timeout of 1 ms exceeded', $error->getMessage());
    }

    private function setPageTimeout(string $property, int $milliseconds): void
    {
        (new ReflectionProperty($this->page, $property))->setValue($this->page, $milliseconds);
    }

    private function readProperty(object $object, string $property): mixed
    {
        return (new ReflectionProperty($object, $property))->getValue($object);
    }

    private function responseField(?object $response, string $field): mixed
    {
        self::assertNotNull($response);
        return $this->readProperty($response, $field);
    }

    private function responseOk(?object $response): bool
    {
        $status = $this->responseField($response, 'status');
        return $status === 0 || ($status >= 200 && $status <= 299);
    }

    private function redirectChain(): void
    {
        foreach (['1' => '/redirect/2.html', '2' => '/redirect/3.html', '3' => '/empty.html'] as $number => $target) {
            $this->setRoute('/redirect/' . $number . '.html', static fn () => ['status' => 302, 'headers' => ['location' => $target]]);
        }
    }

    private function listenerCount(): int
    {
        $session = $this->readProperty($this->page, 'session');
        $connection = $this->readProperty($session, 'connection');
        $count = 0;
        foreach ($this->readProperty($connection, 'sessions') as $active) {
            foreach (['observers', 'waiters'] as $property) {
                foreach ($this->readProperty($active, $property) as $listeners) { $count += count($listeners); }
            }
        }
        return $count;
    }
}
