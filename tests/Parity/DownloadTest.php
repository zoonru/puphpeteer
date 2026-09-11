<?php

/**
 * Assertions ported from Puppeteer. Copyright Google Inc.
 * SPDX-License-Identifier: Apache-2.0
 * See tests/Support/assets/LICENSE.
 */

declare(strict_types=1);

require_once __DIR__ . '/../Support/BrowserTestCase.php';

final class DownloadTest extends BrowserTestCase
{
    /** Upstream scenario: test/src/download.spec.ts::Download > Browser.createBrowserContext > should download to configured location
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/download.spec.ts#L29 Upstream test
     */
    public function testBrowserCreateBrowserContextShouldDownloadToConfiguredLocation(): void
    {
        $directory = sys_get_temp_dir() . '/puphpeteer-download-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $context = $this->browser->createBrowserContext(['downloadBehavior' => ['policy' => 'allow', 'downloadPath' => $directory]])->await();
        try {
            $page = $context->newPage()->await();
            $page->goto($this->url('/download.html'))->await();
            $page->evaluate('document.querySelector("#download").click()')->await();
            $deadline = microtime(true) + 5;
            while (!is_file($directory . '/download.txt') && microtime(true) < $deadline) {
                \Amp\delay(0.01);
            }
            self::assertFileExists($directory . '/download.txt');
            self::assertSame('AABBCCDD', file_get_contents($directory . '/download.txt'));
        } finally {
            $context->close()->await();
            self::removeDirectory($directory);
        }
    }

    /** Upstream scenario: test/src/download.spec.ts::Download > Browser.createBrowserContext > should not download to location
     * @see https://github.com/puppeteer/puppeteer/blob/5d820f2872cdec366fbf6069d03ebc094d8b97f2/test/src/download.spec.ts#L45 Upstream test
     */
    public function testBrowserCreateBrowserContextShouldNotDownloadToLocation(): void
    {
        $directory = sys_get_temp_dir() . '/puphpeteer-download-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $context = $this->browser->createBrowserContext(['downloadBehavior' => ['policy' => 'deny', 'downloadPath' => $directory]])->await();
        try {
            $page = $context->newPage()->await();
            $page->goto($this->url('/download.html'))->await();
            $page->evaluate('document.querySelector("#download").click()')->await();
            // Upstream waitForFileExistence uses a one-second timeout for denial.
            $deadline = microtime(true) + 1;
            while (!is_file($directory . '/download.txt') && microtime(true) < $deadline) {
                \Amp\delay(0.01);
            }
            self::assertFileDoesNotExist($directory . '/download.txt');
        } finally {
            $context->close()->await();
            self::removeDirectory($directory);
        }
    }
}
