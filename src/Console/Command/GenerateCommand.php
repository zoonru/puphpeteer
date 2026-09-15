<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Console\Command;

use Override;
use RuntimeException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class GenerateCommand extends ProcessCommand
{
    #[Override]
    protected function configure(): void
    {
        $this->setDescription('Generate the PHP API from Puppeteer declarations')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Check generated files without writing changes')
            ->addOption('offline', null, InputOption::VALUE_NONE, 'Do not access the network')
            ->addOption('model-only', null, InputOption::VALUE_NONE, 'Update only the API model');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $arguments = ['tools/upstream/update.cjs'];
        if ($output->isVerbose()) {
            $arguments[] = '--verbose';
        }
        foreach (['check', 'offline', 'model-only'] as $option) {
            if ($input->getOption($option)) {
                $arguments[] = '--' . $option;
            }
        }
        $progress = null;
        if (!$this->jsonOutput) {
            $arguments[] = '--progress';
            $progress = new ProgressBar($output, 6);
            $progress->setFormat(' %current%/%max% [%bar%] %message%');
            $progress->setMessage('Checking dependencies');
            $progress->start();
        }
        $result = $this->runProcess($io, ['node', ...$arguments], indicator: false, onLine: static function (string $line) use ($progress): void {
            if (null === $progress || !str_starts_with($line, '@progress ')) {
                return;
            }
            $step = json_decode(substr($line, 10), true, flags: JSON_THROW_ON_ERROR);
            $progress?->setMessage($step['message']);
            $progress?->setProgress($step['step'] - 1);
            $progress?->display();
        }, displayOutput: false);
        if (null !== $progress) {
            if (0 === $result['code']) {
                $progress->setMessage('Done');
                $progress->finish();
            }
            $io->newLine(2);
        }
        $result['stdout'] = preg_replace('/^@progress .*\R/m', '', $result['stdout']) ?? $result['stdout'];
        $report = json_decode($result['stdout'], true);
        $errors = $report['errors'] ?? (0 === $result['code'] ? 0 : 1);
        $warnings = $report['warnings'] ?? 0;
        $summary = sprintf('Errors: %d; warnings: %d.', $errors, $warnings);
        if (0 === $result['code'] && (!is_array($report) || !is_array($report['diagnostics'] ?? null))) {
            throw new RuntimeException('Invalid generator diagnostics response');
        }
        if ($this->jsonOutput) {
            $this->writeJson($output, ['command' => 'generate', 'status' => 0 === $result['code'] ? 'ok' : 'error', 'code' => $result['code'], 'elapsed' => $result['elapsed'], 'report' => $report, 'error' => 0 === $result['code'] ? null : trim($result['stderr'] ?: $result['stdout'])]);
        } elseif (0 === $result['code']) {
            foreach ($report['diagnostics'] as $diagnostic) {
                $io->writeln(json_encode($diagnostic, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            }
            $io->success('Generation completed. ' . $summary);
        } else {
            $io->writeln($result['stderr'] ?: $result['stdout'], OutputInterface::OUTPUT_RAW);
            $io->error('Generation failed. ' . $summary);
        }

        return $result['code'];
    }
}
