<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class BuildCommand extends ProcessCommand
{
    #[\Override]
    protected function configure(): void
    {
        $this->setDescription('Собрать JavaScript bundle для QuickJS')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Только проверить воспроизводимость сборки')
            ->addOption('debug', null, InputOption::VALUE_NONE, 'Собрать читаемый debug bundle')
            ->addOption('plugins', null, InputOption::VALUE_REQUIRED, 'Файл с custom plugins');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->jsonOutput) { $io->title('Сборка PuPHPeteer'); }
        if (!$this->jsonOutput) { $io->progressStart(3); $io->progressAdvance(); }
        /** @var list<string> $arguments */
        $arguments = ['tools/build.cjs'];
        if ($input->getOption('check')) { $arguments[] = '--check'; }
        if ($input->getOption('debug')) { $arguments[] = '--debug'; }
        if (($plugins = $input->getOption('plugins')) !== null) { $arguments[] = '--plugins=' . $plugins; }
        $result = $this->runProcess($io, ['node', ...$arguments], message: 'Собирается bundle…', indicator: false);
        if (!$this->jsonOutput) { $io->progressAdvance(); $io->progressAdvance(); $io->progressFinish(); }
        if ($result['code'] !== 0) {
            if ($this->jsonOutput) { $this->writeJson($output, ['command' => 'build', 'status' => 'error', 'code' => $result['code'], 'elapsed' => $result['elapsed']]); }
            return $result['code'];
        }
        $bundle = $this->root . '/resources/puppeteer.js';
        $size = is_file($bundle) ? filesize($bundle) : false;
        if ($this->jsonOutput) {
            $this->writeJson($output, ['command' => 'build', 'status' => 'ok', 'mode' => $input->getOption('debug') ? 'debug' : 'production', 'bundle_bytes' => $size === false ? null : $size, 'elapsed' => $result['elapsed']]);
            return 0;
        }
        $io->table(['Режим', 'Bundle', 'Время'], [[
            $input->getOption('debug') ? 'debug' : 'production',
            $size === false ? '—' : self::formatBytes($size),
            sprintf('%.2f с', $result['elapsed']),
        ]]);
        $io->success($input->getOption('check') ? 'Bundle актуален.' : 'Bundle собран.');
        return 0;
    }

    /** @psalm-pure */
    private static function formatBytes(int $bytes): string
    {
        return $bytes >= 1_048_576 ? sprintf('%.2f MiB', $bytes / 1_048_576) : sprintf('%.1f KiB', $bytes / 1024);
    }
}
