<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NavigationTest extends TestCase
{
    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should disable timeout when its set to 0
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L300 Upstream test
     */
    public function testPageGotoShouldDisableTimeoutWhenItsSetTo0(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should fail when exceeding default maximum navigation timeout
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L260 Upstream test
     */
    public function testPageGotoShouldFailWhenExceedingDefaultMaximumNavigationTimeout(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should fail when exceeding default maximum timeout
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L273 Upstream test
     */
    public function testPageGotoShouldFailWhenExceedingDefaultMaximumTimeout(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should fail when exceeding maximum navigation timeout
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L246 Upstream test
     */
    public function testPageGotoShouldFailWhenExceedingMaximumNavigationTimeout(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should fail when main resources failed to load
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L233 Upstream test
     */
    public function testPageGotoShouldFailWhenMainResourcesFailedToLoad(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should fail when navigating and show the url at the error message
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L569 Upstream test
     */
    public function testPageGotoShouldFailWhenNavigatingAndShowTheUrlAtTheErrorMessage(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should fail when navigating to bad SSL
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L196 Upstream test
     */
    public function testPageGotoShouldFailWhenNavigatingToBadSSL(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should fail when navigating to bad SSL after redirects
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L222 Upstream test
     */
    public function testPageGotoShouldFailWhenNavigatingToBadSSLAfterRedirects(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should fail when navigating to bad url
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L179 Upstream test
     */
    public function testPageGotoShouldFailWhenNavigatingToBadUrl(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should fail when server returns 204
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L89 Upstream test
     */
    public function testPageGotoShouldFailWhenServerReturns204(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should navigate to about:blank
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L50 Upstream test
     */
    public function testPageGotoShouldNavigateToAboutBlank(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should navigate to dataURL and fire dataURL requests
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L536 Upstream test
     */
    public function testPageGotoShouldNavigateToDataURLAndFireDataURLRequests(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should navigate to empty page with domcontentloaded
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L107 Upstream test
     */
    public function testPageGotoShouldNavigateToEmptyPageWithDomcontentloaded(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should navigate to empty page with networkidle0
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L152 Upstream test
     */
    public function testPageGotoShouldNavigateToEmptyPageWithNetworkidle0(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should navigate to empty page with networkidle2
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L171 Upstream test
     */
    public function testPageGotoShouldNavigateToEmptyPageWithNetworkidle2(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should navigate to page with iframe and networkidle0
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L160 Upstream test
     */
    public function testPageGotoShouldNavigateToPageWithIframeAndNetworkidle0(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should navigate to URL with hash and fire requests without hash
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L549 Upstream test
     */
    public function testPageGotoShouldNavigateToURLWithHashAndFireRequestsWithoutHash(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should not leak listeners during bad navigation
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L498 Upstream test
     */
    public function testPageGotoShouldNotLeakListenersDuringBadNavigation(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should not leak listeners during navigation
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L482 Upstream test
     */
    public function testPageGotoShouldNotLeakListenersDuringNavigation(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should not leak listeners during navigation of 11 pages
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L516 Upstream test
     */
    public function testPageGotoShouldNotLeakListenersDuringNavigationOf11Pages(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should not throw an error for a 404 response with an empty body
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L353 Upstream test
     */
    public function testPageGotoShouldNotThrowAnErrorForA404ResponseWithAnEmptyBody(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should not throw an error for a 500 response with an empty body
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L365 Upstream test
     */
    public function testPageGotoShouldNotThrowAnErrorForA500ResponseWithAnEmptyBody(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should prioritize default navigation timeout over default timeout
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L286 Upstream test
     */
    public function testPageGotoShouldPrioritizeDefaultNavigationTimeoutOverDefaultTimeout(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should return last response in redirect chain
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L377 Upstream test
     */
    public function testPageGotoShouldReturnLastResponseInRedirectChain(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should return response when page changes its URL after load
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L56 Upstream test
     */
    public function testPageGotoShouldReturnResponseWhenPageChangesItsURLAfterLoad(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should return response when page replaces its state during load
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L62 Upstream test
     */
    public function testPageGotoShouldReturnResponseWhenPageReplacesItsStateDuringLoad(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should send referer
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L581 Upstream test
     */
    public function testPageGotoShouldSendReferer(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should send referer policy
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L600 Upstream test
     */
    public function testPageGotoShouldSendRefererPolicy(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should wait for network idle to succeed navigation
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L387 Upstream test
     */
    public function testPageGotoShouldWaitForNetworkIdleToSucceedNavigation(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should work
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L26 Upstream test
     */
    public function testPageGotoShouldWork(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should work when a page redirects on DOMContentLoaded
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L332 Upstream test
     */
    public function testPageGotoShouldWorkWhenAPageRedirectsOnDOMContentLoaded(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should work when navigating to 404
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L346 Upstream test
     */
    public function testPageGotoShouldWorkWhenNavigatingTo404(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should work when navigating to a URL with a client redirect
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L323 Upstream test
     */
    public function testPageGotoShouldWorkWhenNavigatingToAURLWithAClientRedirect(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should work when navigating to data url
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L340 Upstream test
     */
    public function testPageGotoShouldWorkWhenNavigatingToDataUrl(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should work when navigating to valid url
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L316 Upstream test
     */
    public function testPageGotoShouldWorkWhenNavigatingToValidUrl(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should work when page calls history API in beforeunload
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L115 Upstream test
     */
    public function testPageGotoShouldWorkWhenPageCallsHistoryAPIInBeforeunload(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should work when reload causes history API in beforeunload
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L131 Upstream test
     */
    public function testPageGotoShouldWorkWhenReloadCausesHistoryAPIInBeforeunload(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should work with anchor navigation
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L32 Upstream test
     */
    public function testPageGotoShouldWorkWithAnchorNavigation(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should work with redirects
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L42 Upstream test
     */
    public function testPageGotoShouldWorkWithRedirects(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should work with self requesting page
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L562 Upstream test
     */
    public function testPageGotoShouldWorkWithSelfRequestingPage(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }

    /** Upstream scenario: test/src/navigation.spec.ts::navigation > Page.goto > should work with subframes return 204
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/navigation.spec.ts#L74 Upstream test
     */
    public function testPageGotoShouldWorkWithSubframesReturn204(): void
    {
        self::markTestIncomplete('Implement the upstream assertions.');
    }
}
