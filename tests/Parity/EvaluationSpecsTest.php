<?php

/**
 * Assertions ported from Puppeteer. Copyright Google Inc.
 * SPDX-License-Identifier: Apache-2.0
 * See tests/Support/assets/LICENSE.
 */

declare(strict_types=1);

use Nesk\Puphpeteer\Value\BigInt;
use Nesk\Puphpeteer\Value\UndefinedValue;
use Nesk\Puphpeteer\Internal\EvaluationException;

require_once __DIR__ . '/../Support/BrowserTestCase.php';

final class EvaluationSpecsTest extends BrowserTestCase
{
    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should accept "null" as one of multiple parameters
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L326 Upstream test
     */
    public function testPageEvaluateShouldAcceptNullAsOneOfMultipleParameters(): void
    {
        self::assertTrue($this->page->evaluate('(a, b) => Object.is(a, null) && Object.is(b, "foo")', null, 'foo')->await());
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should accept a string
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L394 Upstream test
     */
    public function testPageEvaluateShouldAcceptAString(): void
    {
        self::assertSame(3, $this->page->evaluate('1 + 2')->await());
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should accept a string with comments
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L406 Upstream test
     */
    public function testPageEvaluateShouldAcceptAStringWithComments(): void
    {
        self::assertSame(7, $this->page->evaluate("2 + 5;\n// do some math!")->await());
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should accept a string with semi colons
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L400 Upstream test
     */
    public function testPageEvaluateShouldAcceptAStringWithSemiColons(): void
    {
        self::assertSame(6, $this->page->evaluate('1 + 5;')->await());
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should accept element handle as an argument
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L412 Upstream test
     */
    public function testPageEvaluateShouldAcceptElementHandleAsAnArgument(): void
    {
        $this->page->evaluate('() => document.body.innerHTML = "<section>42</section>"')->await();
        $element = $this->element('document.querySelector("section")');
        try {
            self::assertSame('42', $this->page->evaluate('e => e.textContent', $element)->await());
        } finally {
            $element->dispose()->await();
        }
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should await promise
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L173 Upstream test
     */
    public function testPageEvaluateShouldAwaitPromise(): void
    {
        self::assertSame(56, $this->page->evaluate('() => Promise.resolve(8 * 7)')->await());
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should evaluate in the page context
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L102 Upstream test
     */
    public function testPageEvaluateShouldEvaluateInThePageContext(): void
    {
        $this->page->goto($this->url('/global-var.html'))->await();
        self::assertSame(123, $this->page->evaluate('globalVar')->await());
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should modify global environment
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L94 Upstream test
     */
    public function testPageEvaluateShouldModifyGlobalEnvironment(): void
    {
        $this->page->evaluate('() => globalThis.globalVar = 123')->await();
        self::assertSame(123, $this->page->evaluate('globalVar')->await());
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should not throw an error when evaluation does a navigation
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L469 Upstream test
     */
    public function testPageEvaluateShouldNotThrowAnErrorWhenEvaluationDoesANavigation(): void
    {
        $this->page->goto($this->url('/one-style.html'))->await();
        self::assertSame([42], $this->page->evaluate('() => { window.location = "/empty.html"; return [42]; }')->await());
        $this->waitForRequest('/empty.html');
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should properly serialize null fields
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L338 Upstream test
     */
    public function testPageEvaluateShouldProperlySerializeNullFields(): void
    {
        self::assertSame([], $this->page->evaluate('() => ({a: undefined})')->await());
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should reject promise with exception
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L214 Upstream test
     */
    public function testPageEvaluateShouldRejectPromiseWithException(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('notExistingObject');
        $this->page->evaluate('() => notExistingObject.property')->await();
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should replace symbols with undefined
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L108 Upstream test
     * CDP expectation: upstream marks the BiDi assertion as FAIL; check CDP serialization here.
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/TestExpectations.json#L393
     */
    public function testPageEvaluateShouldReplaceSymbolsWithUndefined(): void
    {
        self::assertSame(UndefinedValue::Value, $this->page->evaluate('() => [Symbol("foo4"), "foo"]')->await());
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should return -0
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L294 Upstream test
     */
    public function testPageEvaluateShouldReturn0(): void
    {
        self::assertSame(-INF, fdiv(1.0, $this->page->evaluate('() => -0')->await()));
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should return -Infinity
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L310 Upstream test
     */
    public function testPageEvaluateShouldReturnInfinity284ea479(): void
    {
        self::assertSame(-INF, $this->page->evaluate('() => -Infinity')->await());
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should return BigInt
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L278 Upstream test
     */
    public function testPageEvaluateShouldReturnBigInt(): void
    {
        $result = $this->page->evaluate('() => BigInt(42)')->await();
        self::assertInstanceOf(BigInt::class, $result);
        self::assertSame('42', $result->value);
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should return complex objects
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L268 Upstream test
     */
    public function testPageEvaluateShouldReturnComplexObjects(): void
    {
        $object = (object) ['foo' => 'bar!'];
        $result = $this->page->evaluate('a => a', $object)->await();
        self::assertNotSame($object, $result);
        self::assertSame(['foo' => 'bar!'], $result);
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should return Infinity
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L302 Upstream test
     */
    public function testPageEvaluateShouldReturnInfinity379ff679(): void
    {
        self::assertSame(INF, $this->page->evaluate('() => Infinity')->await());
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should return NaN
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L286 Upstream test
     */
    public function testPageEvaluateShouldReturnNaN(): void
    {
        self::assertNan($this->page->evaluate('() => NaN')->await());
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should return promise as empty object
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L356 Upstream test
     */
    public function testPageEvaluateShouldReturnPromiseAsEmptyObject(): void
    {
        self::assertSame(['promise' => []], $this->page->evaluate('() => ({promise: new Promise(resolve => setTimeout(resolve, 1000))})')->await());
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should return properly serialize objects with unknown type fields
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L506 Upstream test
     * CDP expectation: upstream marks the BiDi assertion as FAIL; check CDP serialization here.
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/TestExpectations.json#L393
     */
    public function testPageEvaluateShouldReturnProperlySerializeObjectsWithUnknownTypeFields(): void
    {
        $this->page->evaluate('async () => { const image = document.createElement("img"); image.src = "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=="; document.body.append(image); await image.decode(); }')->await();
        self::assertSame(['a' => 'foo', 'b' => []], $this->page->evaluate('async () => ({a: "foo", b: await createImageBitmap(document.querySelector("img"))})')->await());
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should return RegEx
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L318 Upstream test
     * CDP expectation: upstream marks the BiDi assertion as FAIL; check CDP serialization here.
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/TestExpectations.json#L393
     */
    public function testPageEvaluateShouldReturnRegEx(): void
    {
        self::assertSame([], $this->page->evaluate('() => /(.*)/')->await());
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should return undefined for non-serializable objects
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L347 Upstream test
     */
    public function testPageEvaluateShouldReturnUndefinedForNonSerializableObjects(): void
    {
        self::assertSame(UndefinedValue::Value, $this->page->evaluate('() => window')->await());
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should simulate a user gesture
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L459 Upstream test
     */
    public function testPageEvaluateShouldSimulateAUserGesture(): void
    {
        self::assertTrue($this->page->evaluate('() => { document.body.appendChild(document.createTextNode("test")); document.execCommand("selectAll"); return document.execCommand("copy"); }')->await());
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should support thrown numbers as error messages
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L242 Upstream test
     */
    public function testPageEvaluateShouldSupportThrownNumbersAsErrorMessages(): void
    {
        try {
            $this->page->evaluate('() => { throw 100500; }')->await();
            self::fail('Expected JavaScript to throw.');
        } catch (EvaluationException $error) {
            self::assertSame(100500, $error->value);
        }
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should support thrown platform objects as error messages
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L255 Upstream test
     */
    public function testPageEvaluateShouldSupportThrownPlatformObjectsAsErrorMessages(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('some DOMException message');
        $this->page->evaluate('() => { throw new DOMException("some DOMException message"); }')->await();
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should support thrown strings as error messages
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L229 Upstream test
     */
    public function testPageEvaluateShouldSupportThrownStringsAsErrorMessages(): void
    {
        try {
            $this->page->evaluate('() => { throw "qwerty"; }')->await();
            self::fail('Expected JavaScript to throw.');
        } catch (EvaluationException $error) {
            self::assertSame('qwerty', $error->value);
        }
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should throw error with detailed information on exception inside promise
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L490 Upstream test
     */
    public function testPageEvaluateShouldThrowErrorWithDetailedInformationOnExceptionInsidePromise(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('Error in promise');
        $this->page->evaluate('() => new Promise(() => { throw new Error("Error in promise"); })')->await();
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should throw if elementHandles are from other frames
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L440 Upstream test
     */
    public function testPageEvaluateShouldThrowIfElementHandlesAreFromOtherFrames(): void
    {
        $this->page->evaluate('url => new Promise(resolve => { const frame = document.createElement("iframe"); frame.id = "frame1"; frame.src = url; frame.onload = resolve; document.body.appendChild(frame); })', $this->url('/empty.html'))->await();
        $tree = $this->cdp('Page.getFrameTree');
        $frameId = $tree['frameTree']['childFrames'][0]['frame']['id'];
        $world = $this->cdp('Page.createIsolatedWorld', ['frameId' => $frameId, 'worldName' => 'test-handle']);
        $contextId = $world['executionContextId'];
        $remote = $this->cdp('Runtime.evaluate', ['expression' => 'document.body', 'contextId' => $contextId]);
        $element = new \Nesk\Puphpeteer\Internal\RemoteObject($this->cdpSession(), $contextId, $remote['result']['objectId']);
        try {
            $this->expectException(\Throwable::class);
            $this->expectExceptionMessage('JSHandles can be evaluated only in the context they were created');
            $this->page->evaluate('body => body?.innerHTML', $element)->await();
        } finally {
            $element->dispose()->await();
        }
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should throw if underlying element was disposed
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L422 Upstream test
     */
    public function testPageEvaluateShouldThrowIfUnderlyingElementWasDisposed(): void
    {
        $this->page->evaluate('() => document.body.innerHTML = "<section>39</section>"')->await();
        $element = $this->element('document.querySelector("section")');
        $element->dispose()->await();
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('JSHandle is disposed');
        $this->page->evaluate('e => e.textContent', $element)->await();
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should throw when evaluation triggers reload
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L156 Upstream test
     */
    public function testPageEvaluateShouldThrowWhenEvaluationTriggersReload(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('Execution context was destroyed');
        $this->page->evaluate('() => { location.reload(); return new Promise(() => {}); }')->await();
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should transfer -0
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L40 Upstream test
     */
    public function testPageEvaluateShouldTransfer0(): void
    {
        self::assertSame(-INF, fdiv(1.0, $this->page->evaluate('a => a', -0.0)->await()));
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should transfer -Infinity
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L56 Upstream test
     */
    public function testPageEvaluateShouldTransferInfinityd3fd6298(): void
    {
        self::assertSame(-INF, $this->page->evaluate('a => a', -INF)->await());
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should transfer 100Mb of data from page to node.js
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L481 Upstream test
     */
    public function testPageEvaluateShouldTransfer100MbOfDataFromPageToNodeJs(): void
    {
        $result = $this->page->evaluate('() => Array(100 * 1024 * 1024 + 1).join("a")')->await();
        self::assertIsString($result);
        self::assertSame(100 * 1024 * 1024, strlen($result));
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should transfer arrays
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L64 Upstream test
     */
    public function testPageEvaluateShouldTransferArrays(): void
    {
        self::assertSame([1, 2, 3], $this->page->evaluate('a => a', [1, 2, 3])->await());
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should transfer arrays as arrays, not objects
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L75 Upstream test
     */
    public function testPageEvaluateShouldTransferArraysAsArraysNotObjects(): void
    {
        self::assertTrue($this->page->evaluate('a => Array.isArray(a)', [1, 2, 3])->await());
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should transfer BigInt
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L24 Upstream test
     */
    public function testPageEvaluateShouldTransferBigInt(): void
    {
        $result = $this->page->evaluate('a => a', new BigInt('42'))->await();
        self::assertInstanceOf(BigInt::class, $result);
        self::assertSame('42', $result->value);
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should transfer Infinity
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L48 Upstream test
     */
    public function testPageEvaluateShouldTransferInfinityf640b461(): void
    {
        self::assertSame(INF, $this->page->evaluate('a => a', INF)->await());
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should transfer NaN
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L32 Upstream test
     */
    public function testPageEvaluateShouldTransferNaN(): void
    {
        self::assertNan($this->page->evaluate('a => a', NAN)->await());
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should transfer RegEx
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L86 Upstream test
     * CDP expectation: upstream marks the BiDi assertion as FAIL; check CDP serialization here.
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/TestExpectations.json#L393
     */
    public function testPageEvaluateShouldTransferRegEx(): void
    {
        // JavaScript RegExp JSON serialization supplies {} to CDP.
        $regexp = new \stdClass();
        self::assertSame(UndefinedValue::Value, $this->page->evaluate('a => "Hello World!".match(a)[1]', $regexp)->await());
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should work
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L16 Upstream test
     */
    public function testPageEvaluateShouldWork(): void
    {
        self::assertSame(21, $this->page->evaluate('() => 7 * 3')->await());
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should work for circular object
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L370 Upstream test
     * CDP expectation: upstream marks the BiDi assertion as FAIL; check CDP serialization here.
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/TestExpectations.json#L393
     */
    public function testPageEvaluateShouldWorkForCircularObject(): void
    {
        $result = $this->page->evaluate('() => { const a = {c: 5, d: {foo: "bar"}}; const b = {a}; a.b = b; return a; }')->await();
        self::assertSame(UndefinedValue::Value, $result);
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should work from-inside an exposed function
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L193 Upstream test
     */
    public function testPageEvaluateShouldWorkFromInsideAnExposedFunction(): void
    {
        // Install the excluded exposeFunction API through CDP. The PHP callback
        // must evaluate while the original evaluation is still awaiting its result.
        $session = $this->cdpSession();
        $this->cdp('Runtime.addBinding', ['name' => 'callControllerBinding']);
        $this->page->evaluate('() => { globalThis.callController = (a, b) => new Promise(resolve => { globalThis.resolveController = resolve; callControllerBinding(JSON.stringify([a, b])); }); }')->await();
        $binding = $session->waitFor('Runtime.bindingCalled', fn (array $event): bool => $event['name'] === 'callControllerBinding');
        $callback = \Amp\async(function () use ($binding): void {
            $event = $binding->await(new \Amp\TimeoutCancellation(10));
            [$a, $b] = json_decode($event['payload'], true, 512, JSON_THROW_ON_ERROR);
            $result = $this->page->evaluate('(a, b) => a * b', $a, $b)->await();
            $this->page->evaluate('result => globalThis.resolveController(result)', $result)->await();
        });
        self::assertSame(27, $this->page->evaluate('async () => globalThis.callController(9, 3)')->await(new \Amp\TimeoutCancellation(10)));
        $callback->await();
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should work with function shorthands
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L117 Upstream test
     */
    public function testPageEvaluateShouldWorkWithFunctionShorthands(): void
    {
        self::assertSame(3, $this->page->evaluate('sum(a, b) { return a + b; }', 1, 2)->await());
        self::assertSame(8, $this->page->evaluate('async mult(a, b) { return a * b; }', 2, 4)->await());
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should work with function shorthands and nested arrow functions
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L132 Upstream test
     */
    public function testPageEvaluateShouldWorkWithFunctionShorthandsAndNestedArrowFunctions(): void
    {
        self::assertSame(3, $this->page->evaluate('sum(a, b) { const _arrow = () => {}; _arrow(); return a + b; }', 1, 2)->await());
    }

    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should work with unicode chars
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L143 Upstream test
     */
    public function testPageEvaluateShouldWorkWithUnicodeChars(): void
    {
        self::assertSame(42, $this->page->evaluate('a => a["中文字符"]', ['中文字符' => 42])->await());
    }
    /** Upstream scenario: test/src/evaluation.spec.ts::Evaluation specs > Page.evaluate > should work right after framenavigated
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/evaluation.spec.ts#L181 Upstream test
     */
    public function testPageEvaluateShouldWorkRightAfterFramenavigated(): void
    {
        $evaluation = null;
        // Page.evaluate uses the main frame; subscribe directly to its navigation
        // event while the public event API is outside the current scope.
        $observer = $this->onCdp('Page.frameNavigated', function (array $event) use (&$evaluation): void {
            if (!isset($event['frame']['parentId'])) {
                $evaluation = $this->page->evaluate('() => 6 * 7');
            }
        });
        try {
            $this->page->goto($this->url('/empty.html'))->await();
            self::assertInstanceOf(\Amp\Future::class, $evaluation);
            self::assertSame(42, $evaluation->await());
        } finally {
            $this->offCdp('Page.frameNavigated', $observer);
        }
    }

    /** Create the excluded selector API's handle through the same CDP session. */
    private function element(string $expression): \Nesk\Puphpeteer\Internal\RemoteObject
    {
        $session = $this->cdpSession();
        $created = $session->waitFor('Runtime.executionContextCreated', static fn (array $event): bool => ($event['context']['auxData']['isDefault'] ?? false) === true);
        $this->cdp('Runtime.disable');
        $this->cdp('Runtime.enable');
        $contextId = $created->await(new \Amp\TimeoutCancellation(10))['context']['id'];
        $remote = $this->cdp('Runtime.evaluate', ['expression' => $expression, 'contextId' => $contextId]);
        return new \Nesk\Puphpeteer\Internal\RemoteObject($session, $contextId, $remote['result']['objectId']);
    }
}
