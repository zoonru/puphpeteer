<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Console\Command;

use Override;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class DoctorCommand extends ProcessCommand
{
    #[Override]
    protected function configure(): void
    {
        $this->setDescription('Check the environment and bundle availability');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $extensionReady = extension_loaded('php_quickjs');
        $checks = [
            ['PHP', PHP_VERSION, version_compare(PHP_VERSION, '8.4.0', '>=')],
            ['php-quickjs', $extensionReady ? 'loaded in PHP' : 'not loaded', $extensionReady],
            ['bundle', $this->root . '/resources/puppeteer.js', is_file($this->root . '/resources/puppeteer.js')],
        ];
        $failed = count(array_filter($checks, static fn (array $check): bool => !$check[2]));
        if ($this->jsonOutput) {
            $this->writeJson($output, ['command' => 'doctor', 'status' => 0 === $failed ? 'ok' : 'error', 'checks' => array_map(static fn (array $check): array => ['name' => $check[0], 'value' => $check[1], 'ok' => $check[2]], $checks)]);

            return 0 === $failed ? 0 : 1;
        }
        $io->title('Environment check');
        $io->table(['Component', 'Value', 'Status'], array_map(static fn (array $check): array => [$check[0], $check[1] ?? 'not found', $check[2] ? '<fg=green>OK</>' : '<fg=red>FAIL</>'], $checks));
        if ($failed) {
            $io->warning($failed . ' checks failed.');

            return 1;
        }
        $io->success('Environment is ready.');

        return 0;
    }
}
