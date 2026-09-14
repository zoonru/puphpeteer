<?php
declare(strict_types=1);
namespace Nesk\Puphpeteer\Tests\Support;

use Amp\ByteStream\ReadableStream;
use Nesk\Puphpeteer\Client;
use Nesk\Puphpeteer\JsFunction;
use Nesk\Puphpeteer\Page;

/** Deterministic indexed blocks expose missing, duplicated and reordered chunks. */
final class LargePayload
{
    public const UNIT = 'Ab9éЖ😀_Z';
    public const SUFFIX = "\0\"\\\r\n\tEND";
    public const HTML_START = '<!DOCTYPE html><html><head></head><body style="display: none;">';
    public const HTML_END = '</body></html>';
    public const SOURCE = <<<'JS'
    (size, binary = false) => {
      const unit = 'Ab9éЖ😀_Z';
      if (!binary) {
        const blocks = [];
        for (let offset = 0; offset < size; offset += 4096) {
          const length = Math.min(4096, size - offset);
          const header = (offset / 4096).toString(16).padStart(8, '0') + ':';
          if (length < header.length) { blocks.push(header.slice(0, length)); continue; }
          const remaining = length - header.length;
          blocks.push(header + unit.repeat(Math.floor(remaining / 13)) + 'x'.repeat(remaining % 13));
        }
        return blocks.join('');
      }
      const bytes = new Uint8Array(size);
      const pattern = Uint8Array.from({length: 256}, (_, i) => (i * 31) % 256);
      for (let offset = 0; offset < size; offset += 256) bytes.set(pattern.subarray(0, Math.min(256, size - offset)), offset);
      for (let offset = 0; offset < size; offset += 4096) {
        const index = offset / 4096;
        for (let i = 0; i < Math.min(4, size - offset); i++) bytes[offset + i] = (index >>> (24 - i * 8)) & 255;
      }
      return bytes;
    }
    JS;

    /** @psalm-pure */
    public static function expected(int $size, bool $binary = false): string
    {
        $data = '';
        $pattern = implode('', array_map(static fn(int $i): string => chr(($i * 31) % 256), range(0, 255)));
        for ($offset = 0; $offset < $size; $offset += 4096) {
            $length = min(4096, $size - $offset);
            if ($binary) { $block = pack('N', intdiv($offset, 4096)) . substr(str_repeat($pattern, 16), 4); }
            else {
                $header = sprintf('%08x:', intdiv($offset, 4096));
                $remaining = max(0, $length - strlen($header));
                $block = $header . str_repeat(self::UNIT, intdiv($remaining, 13)) . str_repeat('x', $remaining % 13);
            }
            $data .= substr($block, 0, $length);
        }
        return $data;
    }

    /** Load the shipped guest with a test-only producer; no browser or mocked codec. */
    public static function client(): Client
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/resources/puppeteer.js');
        if ($source === false || preg_match('/var ([\w$]+)=new Map,([\w$]+)=new WeakMap,([\w$]+)=0,([\w$]+)=0,/', $source, $match) !== 1) {
            throw new \RuntimeException('Cannot instrument guest object registry');
        }
        $source = preg_replace('/globalThis\.__quickjsDispatch\s*=/', 'globalThis.__payloadObjects=' . $match[1] . ';globalThis.__quickjsDispatch=', $source, 1);
        $file = tempnam(sys_get_temp_dir(), 'quickjs-large-');
        if ($file === false || $source === null) { throw new \RuntimeException('Cannot create guest fixture'); }
        file_put_contents($file, $source);
        try { $client = new Client($file); }
        finally { unlink($file); }
        $js = (new \ReflectionProperty($client, 'js'))->getValue($client);
        $js->eval('globalThis.__payloadMake = (' . self::SOURCE . ');');
        $js->eval(<<<'JS'
        globalThis.__payloadObjects.set(-1, {
          prepare(size) { this.text = __payloadMake(size); this.binary = __payloadMake(size, true); },
          invoke(fn, ...args) { return fn(this.text, ...args); },
          bytes() { return this.binary; },
          stream(chunkSize) {
            const data = this.binary;
            let offset = 0;
            return new ReadableStream({pull(controller) {
              if (offset === data.length) { controller.close(); return; }
              const end = Math.min(offset + chunkSize, data.length);
              controller.enqueue(data.subarray(offset, end));
              offset = end;
            }}, {highWaterMark: 0});
          },
        });
        JS);
        return $client;
    }

    public static function preparePage(Page $page, int $size): void
    {
        $page->setContent(self::HTML_START . self::HTML_END);
        $page->evaluate(new JsFunction('(size) => { globalThis.__payloadText = (' . self::SOURCE . ')(size); document.body.textContent = globalThis.__payloadText; }'), $size);
    }

    /** @return array{bytes:int, sha256:string, chunks:int, max_chunk:int} */
    public static function digest(string|ReadableStream $value): array
    {
        if (is_string($value)) { return ['bytes' => strlen($value), 'sha256' => hash('sha256', $value), 'chunks' => 1, 'max_chunk' => strlen($value)]; }
        $hash = hash_init('sha256');
        $bytes = $chunks = $maximum = 0;
        try {
            while (($chunk = $value->read()) !== null) {
                $length = strlen($chunk);
                if ($length > 65536) { throw new \RuntimeException('Stream chunk exceeds 64 KiB'); }
                hash_update($hash, $chunk);
                $bytes += $length; $chunks++; $maximum = max($maximum, $length);
            }
        } finally { $value->close(); }
        return ['bytes' => $bytes, 'sha256' => hash_final($hash), 'chunks' => $chunks, 'max_chunk' => $maximum];
    }

    /** @psalm-pure */
    public static function verify(array $actual, string $expected, string $label): void
    {
        if ($actual['bytes'] !== strlen($expected) || $actual['sha256'] !== hash('sha256', $expected)) {
            throw new \RuntimeException($label . ': payload corrupted (length or SHA-256 mismatch)');
        }
    }
}
