<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class GenerateCommand extends ProcessCommand
{
    #[\Override]
    protected function configure(): void
    {
        $this->setDescription('Сгенерировать PHP API из деклараций Puppeteer')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Только проверить сгенерированные файлы')
            ->addOption('offline', null, InputOption::VALUE_NONE, 'Не обращаться к сети')
            ->addOption('model-only', null, InputOption::VALUE_NONE, 'Обновить только модель API');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $arguments = ['tools/upstream/update.cjs'];
        foreach (['check', 'offline', 'model-only'] as $option) {
            if ($input->getOption($option)) { $arguments[] = '--' . $option; }
        }
        $result = $this->runProcess($io, ['node', ...$arguments], message: 'Генерируется PHP API…');
        if ($this->jsonOutput) { $this->writeJson($output, ['command' => 'generate', 'status' => $result['code'] === 0 ? 'ok' : 'error', 'code' => $result['code'], 'elapsed' => $result['elapsed']]); }
        elseif ($result['code'] === 0) { $io->success('Генерация завершена.'); }
        return $result['code'];
    }
}
