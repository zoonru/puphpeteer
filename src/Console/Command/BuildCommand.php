<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Console\Command;

use Override;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function Amp\File\getSize;
use function Amp\File\isFile;

final class BuildCommand extends ProcessCommand
{
    #[Override]
    protected function configure(): void
    {
        $this->setDescription('Build the JavaScript bundle for QuickJS')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Check build reproducibility without writing files')
            ->addOption('debug', null, InputOption::VALUE_NONE, 'Build a readable debug bundle')
            ->addOption('plugins', null, InputOption::VALUE_REQUIRED, 'Custom plugin registry file');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->jsonOutput) {
            $io->title('PuPHPeteer build');
        }
        if (!$this->jsonOutput) {
            $io->progressStart(3);
            $io->progressAdvance();
        }
        /** @var list<string> $arguments */
        $arguments = ['tools/build.cjs'];
        if ($input->getOption('check')) {
            $arguments[] = '--check';
        }
        if ($input->getOption('debug')) {
            $arguments[] = '--debug';
        }
        if (($plugins = $input->getOption('plugins')) !== null) {
            $arguments[] = '--plugins=' . $plugins;
        }
        $result = $this->runProcess($io, ['node', ...$arguments], message: 'Building the bundle…', indicator: false);
        if (!$this->jsonOutput) {
            $io->progressAdvance();
            $io->progressAdvance();
            $io->progressFinish();
        }
        if (0 !== $result['code']) {
            if ($this->jsonOutput) {
                $this->writeJson($output, ['command' => 'build', 'status' => 'error', 'code' => $result['code'], 'elapsed' => $result['elapsed']]);
            }

            return $result['code'];
        }
        $bundle = $this->root . '/resources/puppeteer.js';
        $size = isFile($bundle) ? getSize($bundle) : false;
        if ($this->jsonOutput) {
            $this->writeJson($output, ['command' => 'build', 'status' => 'ok', 'mode' => $input->getOption('debug') ? 'debug' : 'production', 'bundle_bytes' => false === $size ? null : $size, 'elapsed' => $result['elapsed']]);

            return 0;
        }
        $io->table(['Mode', 'Bundle', 'Time'], [[
            $input->getOption('debug') ? 'debug' : 'production',
            false === $size ? '—' : self::formatBytes($size),
            sprintf('%.2f s', $result['elapsed']),
        ]]);
        $io->success($input->getOption('check') ? 'Bundle is up to date.' : 'Bundle built.');

        return 0;
    }

    /** @psalm-pure */
    private static function formatBytes(int $bytes): string
    {
        return $bytes >= 1_048_576 ? sprintf('%.2f MiB', $bytes / 1_048_576) : sprintf('%.1f KiB', $bytes / 1024);
    }
}
