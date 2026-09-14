<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class BrowserInstallCommand extends ProcessCommand
{
    #[\Override]
    protected function configure(): void
    {
        $this->setDescription('Установить совместимый Chrome for Testing');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->runProcess($io, [$this->phpBinary, 'tools/install-browser.php'], message: 'Загружается Chrome…');
        if ($this->jsonOutput) { $this->writeJson($output, ['command' => 'browser:install', 'status' => $result['code'] === 0 ? 'ok' : 'error', 'code' => $result['code'], 'elapsed' => $result['elapsed']]); }
        elseif ($result['code'] === 0) { $io->success(trim($result['stdout'])); }
        return $result['code'];
    }
}
