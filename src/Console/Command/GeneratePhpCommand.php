<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Console\Command;

use Nesk\Puphpeteer\Console\Command\Generate\CodeStyle;
use Nesk\Puphpeteer\Console\Command\Generate\Request;
use Nesk\Puphpeteer\Console\Command\Generate\Synchronizer;
use Override;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Internal PHP stage of the TypeScript generator. */
final class GeneratePhpCommand extends Command
{
    #[Override]
    protected function configure(): void
    {
        $this->setDescription('Run the internal PHP generation stage')
            ->setHidden(true)
            ->addArgument('operation', InputArgument::REQUIRED, 'Operation: synchronize or format');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = stream_get_contents(STDIN);
        if (false === $json) {
            throw new RuntimeException('Cannot read generator input');
        }
        $operation = $input->getArgument('operation');
        if ('synchronize' === $operation) {
            $request = Request::decode($json);
            $result = (new Synchronizer())->synchronize($request['root'], $request['classes']);
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return [] === $result['diagnostics'] ? self::SUCCESS : self::FAILURE;
        }
        if ('format' === $operation) {
            $files = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($files) || !array_is_list($files)) {
                throw new RuntimeException('Formatting request must be a list of files');
            }
            $sources = [];
            foreach ($files as $index => $file) {
                if (!is_array($file) || !is_string($file['path'] ?? null) || !is_string($file['content'] ?? null)) {
                    throw new RuntimeException('Formatting request contains an invalid file');
                }
                if (str_ends_with($file['path'], '.php')) {
                    $sources[$index] = $file['content'];
                }
            }
            foreach (CodeStyle::formatMany($sources) as $index => $content) {
                $files[$index]['content'] = $content;
            }
            $output->write(json_encode($files, JSON_THROW_ON_ERROR), false, OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        throw new RuntimeException('Unknown PHP generation operation: ' . $operation);
    }
}
