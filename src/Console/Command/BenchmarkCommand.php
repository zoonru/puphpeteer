<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class BenchmarkCommand extends ProcessCommand
{
    #[\Override]
    protected function configure(): void
    {
        $this->setDescription('Запустить benchmark QuickJS')
            ->addOption('extension', null, InputOption::VALUE_REQUIRED, 'Путь к php-quickjs')
            ->addOption('trials', null, InputOption::VALUE_REQUIRED, 'Количество прогонов', '5')
            ->addOption('iterations', null, InputOption::VALUE_REQUIRED, 'Количество evaluate за прогон', '1000');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $extension = $input->getOption('extension') ?: getenv('QUICKJS_EXTENSION') ?: null;
        if (!is_string($extension) || $extension === '' || !is_file($extension)) {
            if ($this->jsonOutput) { $this->writeJson($output, ['command' => 'benchmark', 'status' => 'error', 'error' => 'QUICKJS_EXTENSION is required']); }
            else { $io->error('Укажите существующий QUICKJS_EXTENSION или --extension.'); }
            return 2;
        }
        $trials = (int) $input->getOption('trials');
        $iterations = (int) $input->getOption('iterations');
        if ($trials < 1 || $iterations < 1) {
            if ($this->jsonOutput) { $this->writeJson($output, ['command' => 'benchmark', 'status' => 'error', 'error' => 'trials and iterations must be positive']); }
            else { $io->error('trials и iterations должны быть положительными.'); }
            return 2;
        }
        $bar = null;
        if (!$this->jsonOutput) {
            $io->title('Benchmark QuickJS');
            $bar = $io->createProgressBar($trials);
            $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%%  %message%');
            $bar->setMessage('подготовка');
            $bar->start();
        }
        /** @var list<string> $command */
        $command = $this->phpCommand('-d', 'extension=' . $extension, 'benchmarks/run.php');
        $result = $this->runProcess(
            $io,
            $command,
            ['BENCH_TRIALS' => (string) $trials, 'BENCH_ITERATIONS' => (string) $iterations, 'QUICKJS_EXTENSION' => $extension],
            'Выполняются прогоны…',
            false,
            function (string $line) use (&$bar): void {
                if (!isset($bar)) { return; }
                if (preg_match('/Running quickjs (\d+)\/(\d+)/i', $line, $match)) {
                    $bar->setProgress((int) $match[1]);
                    $bar->setMessage('trial ' . $match[1] . '/' . $match[2]);
                }
            },
        );
        if (isset($bar)) { $bar->setMessage($result['code'] === 0 ? 'готово' : 'ошибка'); $bar->finish(); }
        if ($result['code'] !== 0) {
            if ($this->jsonOutput) { $this->writeJson($output, ['command' => 'benchmark', 'status' => 'error', 'code' => $result['code'], 'elapsed' => $result['elapsed']]); }
            return $result['code'];
        }
        $rows = [];
        $details = [];
        /** @var array<string,float> $totals */
        $totals = [];
        foreach (preg_split('/\R/', trim($result['stdout'])) ?: [] as $line) {
            if (!str_starts_with($line, '{')) { continue; }
            $data = json_decode($line, true);
            if (!is_array($data) || !isset($data['phases'], $data['resources'])) { continue; }
            $details[] = $data;
            $phase = static fn(string $name, string $metric = 'wall_ms'): float => (float) ($data['phases'][$name][$metric] ?? 0);
            $values = [
                'setup' => (float) ($data['setup_ms'] ?? 0),
                'evaluate' => $phase('evaluate'),
                'p50' => $phase('evaluate', 'p50_ms'),
                'p95' => $phase('evaluate', 'p95_ms'),
                'payload' => $phase('payload_64k'),
                'concurrent' => $phase('concurrent_20x25ms'),
                'navigation' => $phase('navigate_title'),
                'cpu' => (float) ($data['resources']['total_process_tree_cpu_ms'] ?? 0),
                'rss' => (float) ($data['resources']['sampled_tree_peak_rss_bytes'] ?? 0) / 1048576.0,
            ];
            foreach ($values as $name => $value) { $totals[$name] = ($totals[$name] ?? 0.0) + $value; }
            $rows[] = [
                $data['backend'] ?? 'quickjs',
                (string) ($data['trial'] ?? count($rows) + 1),
                sprintf('%.2f ms', (float) ($data['setup_ms'] ?? 0)),
                sprintf('%.2f ms', $phase('evaluate')),
                sprintf('%.3f ms', $phase('evaluate', 'p50_ms')),
                sprintf('%.3f ms', $phase('evaluate', 'p95_ms')),
                sprintf('%.2f ms', $phase('payload_64k')),
                sprintf('%.2f ms', $phase('concurrent_20x25ms')),
                sprintf('%.2f ms', $phase('navigate_title')),
                sprintf('%.2f ms', (float) ($data['resources']['total_process_tree_cpu_ms'] ?? 0)),
                sprintf('%.1f MiB', (float) ($data['resources']['sampled_tree_peak_rss_bytes'] ?? 0) / 1048576.0),
            ];
        }
        if ($rows !== []) {
            $count = (float) count($details);
            $rows[] = new TableSeparator();
            $rows[] = [
                'mean',
                '—',
                sprintf('%.2f ms', $totals['setup'] / $count),
                sprintf('%.2f ms', $totals['evaluate'] / $count),
                sprintf('%.3f ms', $totals['p50'] / $count),
                sprintf('%.3f ms', $totals['p95'] / $count),
                sprintf('%.2f ms', $totals['payload'] / $count),
                sprintf('%.2f ms', $totals['concurrent'] / $count),
                sprintf('%.2f ms', $totals['navigation'] / $count),
                sprintf('%.2f ms', $totals['cpu'] / $count),
                sprintf('%.1f MiB', $totals['rss'] / $count),
            ];
        }
        if ($this->jsonOutput) { $this->writeJson($output, ['command' => 'benchmark', 'status' => 'ok', 'runs' => $details, 'elapsed' => $result['elapsed']]); return 0; }
        if ($rows !== []) {
            $io->table(['Backend', 'Trial', 'Setup', 'Evaluate', 'Eval p50', 'Eval p95', '64 KiB', '20×25 ms', 'Navigation', 'CPU total', 'Peak RSS'], $rows);
        }
        $io->success(sprintf('Benchmark завершён за %.2f с.', $result['elapsed']));
        return 0;
    }
}
