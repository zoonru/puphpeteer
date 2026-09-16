<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Console\Command;

use Nesk\Puphpeteer\Internal\BrowserInstallation;
use Override;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class BrowserInstallCommand extends ProcessCommand
{
    #[Override]
    protected function configure(): void
    {
        $this->setDescription('Install the compatible Chrome for Testing version');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $started = microtime(true);
        $path = BrowserInstallation::skipDownload() ? null : (new BrowserInstallation($this->root))->install();
        $elapsed = microtime(true) - $started;
        if ($this->jsonOutput) {
            $this->writeJson($output, ['command' => 'browser:install', 'status' => 'ok', 'path' => $path, 'skipped' => null === $path, 'elapsed' => $elapsed]);
        } else {
            $io->success($path ?? 'Chrome download skipped.');
        }

        return self::SUCCESS;
    }
}
