<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Unit\Shared;

use Closure;
use Nesk\Puphpeteer\Console\Command\ProcessCommand;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ProcessCommandTest extends TestCase
{
    public function testDeliversFinalOutputExactlyOnceOnSuccessAndFailure(): void
    {
        $command = new class('process-probe') extends ProcessCommand {
            /**
             * @param list<string>         $arguments
             * @param Closure(string):void $onLine
             *
             * @return array{code:int,stdout:string,stderr:string,elapsed:float}
             */
            public function collect(array $arguments, Closure $onLine): array
            {
                $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());

                return $this->runProcess($io, $arguments, indicator: false, onLine: $onLine, displayOutput: false);
            }
        };
        $marker = tempnam(sys_get_temp_dir(), 'puphpeteer-progress-');
        if (false === $marker) {
            throw new RuntimeException('Cannot create process test marker');
        }
        try {
            foreach ([0, 7] as $exitCode) {
                unlink($marker);
                $lines = [];
                $source = <<<'CODE'
                    fwrite(STDOUT, "first\n");
                    fflush(STDOUT);
                    $deadline = microtime(true) + 5;
                    while (!file_exists($argv[1])) {
                        if (microtime(true) > $deadline) { exit(99); }
                        clearstatcache();
                        usleep(1000);
                    }
                    fwrite(STDOUT, "second\r\nlast");
                    fwrite(STDERR, "diagnostic\n");
                    exit((int) $argv[2]);
                    CODE;
                $result = $command->collect([PHP_BINARY, '-r', $source, $marker, (string) $exitCode], static function (string $line) use (&$lines, $marker): void {
                    $lines[] = $line;
                    if ('first' === $line) {
                        file_put_contents($marker, 'ready');
                        // Let the child exit while the first callback is still running.
                        usleep(100000);
                    }
                });
                self::assertSame($exitCode, $result['code']);
                self::assertSame("first\nsecond\r\nlast", $result['stdout']);
                self::assertSame("diagnostic\n", $result['stderr']);
                self::assertSame(['first', 'second', 'last'], $lines);
            }
        } finally {
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }
}
