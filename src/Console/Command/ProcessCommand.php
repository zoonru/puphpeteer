<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Console\Command;

use Amp\Process\Process;
use Closure;
use Override;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressIndicator;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function Amp\async;
use function Amp\ByteStream\buffer;

/** Common process handling for the project CLI. */
abstract class ProcessCommand extends Command
{
    protected readonly string $root;
    protected readonly string $phpBinary;
    protected bool $jsonOutput = false;

    public function __construct(?string $name = null)
    {
        $this->phpBinary = PHP_BINARY;
        parent::__construct($name);
        $this->root = dirname(__DIR__, 3);
    }

    #[Override]
    public function getDefinition(): InputDefinition
    {
        $definition = parent::getDefinition();
        if (!$definition->hasOption('json')) {
            $definition->addOption(new InputOption('json', null, InputOption::VALUE_NONE, 'Print the result as a single JSON line'));
        }

        return $definition;
    }

    #[Override]
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->jsonOutput = true === $input->getOption('json');
    }

    /**
     * @param list<string>                                    $command
     * @param array<string,string>                            $environment
     * @param (Closure(string, ?ProgressIndicator):void)|null $onLine
     *
     * @return array{code:int,stdout:string,stderr:string,elapsed:float}
     */
    protected function runProcess(
        SymfonyStyle $io,
        array $command,
        array $environment = [],
        string $message = 'Running…',
        bool $indicator = true,
        ?Closure $onLine = null,
        bool $displayOutput = true,
    ): array {
        $started = microtime(true);
        $processEnvironment = getenv();
        $processEnvironment = is_array($processEnvironment) ? array_replace($processEnvironment, $environment) : $environment;
        $process = Process::start($command, $this->root, $processEnvironment);
        $process->getStdin()->close();
        $stderrFuture = async(static fn (): string => buffer($process->getStderr()));
        $stdout = '';
        $pending = '';
        $progress = $indicator && !$this->jsonOutput ? new ProgressIndicator($io) : null;
        $progress?->start($message);

        try {
            while (null !== ($chunk = $process->getStdout()->read())) {
                $stdout .= $chunk;
                $pending .= $chunk;
                while (($position = strpos($pending, "\n")) !== false) {
                    $line = rtrim(substr($pending, 0, $position), "\r");
                    $pending = substr($pending, $position + 1);
                    $onLine?->__invoke($line, $progress);
                }
                $progress?->advance();
            }
            if ('' !== $pending) {
                $onLine?->__invoke(rtrim($pending, "\r"), $progress);
            }
            $code = $process->join();
            $stderr = $stderrFuture->await();
        } finally {
            if ($process->isRunning()) {
                $process->kill();
            }
        }

        $progress?->finish(0 === $code ? 'Done' : 'Error');
        if ($displayOutput && !$this->jsonOutput && (0 !== $code || $io->isVerbose())) {
            $text = trim($stdout . ('' !== $stderr ? "\n" . $stderr : ''));
            if ('' !== $text) {
                $io->writeln(0 === $code ? '<fg=gray>' . $this->truncate($text) . '</>' : '<error>' . $text . '</error>');
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
        if (count($lines) > 24) {
            $lines = array_slice($lines, -24);
            array_unshift($lines, '…');
        }

        return implode("\n", array_map(static fn (string $line): string => '  ' . $line, $lines));
    }

    /** @psalm-suppress MissingPureAnnotation @return list<string> */
    protected function phpCommand(string ...$arguments): array
    {
        return array_values([$this->phpBinary, ...$arguments]);
    }
}
