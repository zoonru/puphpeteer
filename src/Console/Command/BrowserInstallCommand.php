<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Console\Command;

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
        $result = $this->runProcess($io, [$this->phpBinary, 'tools/install-browser.php'], message: 'Downloading Chrome…');
        if ($this->jsonOutput) {
            $this->writeJson($output, ['command' => 'browser:install', 'status' => 0 === $result['code'] ? 'ok' : 'error', 'code' => $result['code'], 'elapsed' => $result['elapsed']]);
        } elseif (0 === $result['code']) {
            $io->success(trim($result['stdout']));
        }

        return $result['code'];
    }
}
