<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class DoctorCommand extends ProcessCommand
{
    #[\Override]
    protected function configure(): void { $this->setDescription('Проверить окружение и готовность bundle'); }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $extension = getenv('QUICKJS_EXTENSION') ?: null;
        $extensionReady = extension_loaded('php_quickjs') || (is_string($extension) && is_file($extension));
        $checks = [
            ['PHP', PHP_VERSION, version_compare(PHP_VERSION, '8.4.0', '>=')],
            ['php-quickjs', $extensionReady ? ($extension ?: 'загружен в PHP') : ($extension ?: 'не задан'), $extensionReady],
            ['Node.js', $this->binaryPath('node'), $this->binaryPath('node') !== null],
            ['npm', $this->binaryPath('npm'), $this->binaryPath('npm') !== null],
            ['bundle', $this->root . '/resources/puppeteer.js', is_file($this->root . '/resources/puppeteer.js')],
        ];
        $failed = count(array_filter($checks, static fn(array $check): bool => !$check[2]));
        if ($this->jsonOutput) {
            $this->writeJson($output, ['command' => 'doctor', 'status' => $failed === 0 ? 'ok' : 'error', 'checks' => array_map(static fn(array $check): array => ['name' => $check[0], 'value' => $check[1], 'ok' => $check[2]], $checks)]);
            return $failed === 0 ? 0 : 1;
        }
        $io->title('Проверка окружения');
        $io->table(['Компонент', 'Значение', 'Статус'], array_map(static fn(array $check): array => [$check[0], $check[1] ?? 'не найден', $check[2] ? '<fg=green>OK</>' : '<fg=red>FAIL</>'], $checks));
        if ($failed) { $io->warning($failed . ' проверок не пройдено.'); return 1; }
        $io->success('Окружение готово.');
        return 0;
    }

    private function binaryPath(string $binary): ?string
    {
        $path = getenv('PATH');
        foreach (explode(PATH_SEPARATOR, is_string($path) ? $path : '') as $directory) {
            $candidate = $directory . DIRECTORY_SEPARATOR . $binary;
            if (is_file($candidate) && is_executable($candidate)) { return $candidate; }
        }
        return null;
    }
}
