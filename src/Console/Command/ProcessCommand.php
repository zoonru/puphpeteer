<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressIndicator;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Common process handling for the project CLI. */
abstract class ProcessCommand extends Command
{
    protected readonly string $root;
    protected readonly string $phpBinary;
    protected bool $jsonOutput = false;

    public function __construct(?string $name = null)
    {
        $php = getenv('PHP_BIN');
        $this->phpBinary = (is_string($php) && $php !== '') ? $php : PHP_BINARY;
        parent::__construct($name);
        $this->root = dirname(__DIR__, 3);
    }

    #[\Override]
    public function getDefinition(): InputDefinition
    {
        $definition = parent::getDefinition();
        if (!$definition->hasOption('json')) {
            $definition->addOption(new InputOption('json', null, InputOption::VALUE_NONE, 'Вывести результат одной JSON-строкой'));
        }
        return $definition;
    }

    #[\Override]
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->jsonOutput = $input->getOption('json') === true;
    }

    /**
     * @param list<string> $command
     * @param array<string,string> $environment
     * @param (\Closure(string, ?ProgressIndicator):void)|null $onLine
     * @return array{code:int,stdout:string,stderr:string,elapsed:float}
     */
    protected function runProcess(
        SymfonyStyle $io,
        array $command,
        array $environment = [],
        string $message = 'Выполняется…',
        bool $indicator = true,
        ?\Closure $onLine = null,
    ): array {
        $started = microtime(true);
        $pipes = [];
        $processEnvironment = getenv();
        $processEnvironment = is_array($processEnvironment) ? array_replace($processEnvironment, $environment) : $environment;
        $process = proc_open(
            $command,
            [0 => ['file', 'php://stdin', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            $processEnvironment,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Не удалось запустить: ' . implode(' ', $command));
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $pending = '';
        $code = 1;
        $progress = $indicator && !$this->jsonOutput ? new ProgressIndicator($io) : null;
        $progress?->start($message);

        try {
            while (true) {
                $read = [];
                if (!feof($pipes[1])) { $read[] = $pipes[1]; }
                if (!feof($pipes[2])) { $read[] = $pipes[2]; }
                if ($read !== []) {
                    $write = $except = [];
                    @stream_select($read, $write, $except, 0, 100_000);
                    foreach ($read as $stream) {
                        $chunk = stream_get_contents($stream);
                        if ($chunk === false || $chunk === '') { continue; }
                        if ($stream === $pipes[1]) {
                            $stdout .= $chunk;
                            $pending .= $chunk;
                            while (($position = strpos($pending, "\n")) !== false) {
                                $line = rtrim(substr($pending, 0, $position), "\r");
                                $pending = substr($pending, $position + 1);
                                $onLine?->__invoke($line, $progress);
                            }
                        } else {
                            $stderr .= $chunk;
                        }
                    }
                }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $stdout .= stream_get_contents($pipes[1]) ?: '';
                    $stderr .= stream_get_contents($pipes[2]) ?: '';
                    $code = $status['exitcode'];
                    break;
                }
                $progress?->advance();
            }
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closedCode = proc_close($process);
            if ($code === -1 && $closedCode >= 0) { $code = $closedCode; }
        }

        $progress?->finish($code === 0 ? 'Готово' : 'Ошибка');
        if (!$this->jsonOutput && ($code !== 0 || $io->isVerbose())) {
            $text = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));
            if ($text !== '') {
                $io->writeln($code === 0 ? '<fg=gray>' . $this->truncate($text) . '</>' : '<error>' . $text . '</error>');
            }
        }

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr, 'elapsed' => microtime(true) - $started];
    }

    /** @param array<string,mixed> $data */
    protected function writeJson(OutputInterface $output, array $data): void
    {
        $output->writeln((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @psalm-pure */
    private function truncate(string $text): string
    {
        $lines = preg_split('/\R/', $text) ?: [];
        if (count($lines) > 24) { $lines = array_slice($lines, -24); array_unshift($lines, '…'); }
        return implode("\n", array_map(static fn(string $line): string => '  ' . $line, $lines));
    }

    /** @psalm-suppress MissingPureAnnotation @return list<string> */
    protected function phpCommand(string ...$arguments): array
    {
        return array_values([$this->phpBinary, ...$arguments]);
    }
}
