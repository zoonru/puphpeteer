<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Console\Command;

use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class TestCommand extends ProcessCommand
{
    private ?string $fixedSuite;

    public function __construct(?string $name = null, ?string $fixedSuite = null)
    {
        $this->fixedSuite = $fixedSuite;
        parent::__construct($name);
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setDescription('Запустить unit, integration, browser или release проверки');
        if ($this->fixedSuite === null) {
            $this->addArgument('suite', InputArgument::OPTIONAL, 'Набор проверок: all, unit, integration, browser, release');
        }
        $this->addOption('extension', null, InputOption::VALUE_REQUIRED, 'Путь к php-quickjs для browser/integration/release');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $suite = $this->fixedSuite ?? $input->getArgument('suite');
        if (!is_string($suite) || $suite === '') {
            $suite = $input->isInteractive()
                ? $io->askQuestion(new ChoiceQuestion('Что запустить?', ['all', 'unit', 'integration', 'browser', 'release'], 'all'))
                : 'all';
        }
        $allowed = ['all', 'unit', 'integration', 'browser', 'release'];
        if (!in_array($suite, $allowed, true)) {
            if ($this->jsonOutput) { $this->writeJson($output, ['command' => 'test', 'status' => 'error', 'error' => 'Unknown suite', 'suite' => $suite]); }
            else { $io->error('Неизвестный набор: ' . $suite); }
            return 2;
        }
        $suites = $suite === 'all' ? ['unit', 'integration', 'browser'] : [$suite];
        $extension = $input->getOption('extension') ?: getenv('QUICKJS_EXTENSION') ?: null;
        if (!$this->jsonOutput) { $io->title('Проверки PuPHPeteer'); $io->progressStart(count($suites)); }
        $results = [];
        foreach ($suites as $current) {
            /** @var list<string> $command */
            $command = match ($current) {
                'unit' => $this->phpCommand('vendor/bin/phpunit'),
                'integration' => $this->phpCommand(...$this->withExtension($extension, 'vendor/bin/phpunit', 'tests/Integration')),
                'browser' => $this->phpCommand(...$this->withExtension($extension, 'tests/Browser/run-smoke.php')),
                'release' => $this->phpCommand(...$this->withExtension($extension, 'tests/Release/run.php')),
            };
            if ($current !== 'unit' && !$extension) {
                if ($this->jsonOutput) { $this->writeJson($output, ['command' => $this->getName() ?? 'test', 'status' => 'error', 'error' => 'QUICKJS_EXTENSION is required', 'suite' => $current]); }
                else { $io->error('Для ' . $current . ' нужен QUICKJS_EXTENSION или --extension.'); }
                return 2;
            }
            $result = $this->runProcess($io, $command, message: $current . '…');
            $results[] = ['suite' => $current, 'status' => $result['code'] === 0 ? 'ok' : 'error', 'code' => $result['code'], 'elapsed' => $result['elapsed']];
            if (!$this->jsonOutput) { $io->progressAdvance(); }
            if ($result['code'] !== 0) { if (!$this->jsonOutput) { $io->progressFinish(); $io->error($current . ' завершён с ошибкой.'); } else { $this->writeJson($output, ['command' => $this->getName() ?? 'test', 'status' => 'error', 'results' => $results]); } return $result['code']; }
        }
        if ($this->jsonOutput) { $this->writeJson($output, ['command' => $this->getName() ?? 'test', 'status' => 'ok', 'results' => $results]); return 0; }
        $io->progressFinish();
        $io->success('Все выбранные проверки пройдены.');
        return 0;
    }

    /** @psalm-pure @return list<string> */
    private function withExtension(?string $extension, string ...$arguments): array
    {
        return $extension !== null ? ['-n', '-d', 'extension=' . $extension, ...$arguments] : array_values($arguments);
    }
}
