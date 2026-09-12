<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Console;

use Nesk\Puphpeteer\Console\Command\BenchmarkCommand;
use Nesk\Puphpeteer\Console\Command\BrowserInstallCommand;
use Nesk\Puphpeteer\Console\Command\BuildCommand;
use Nesk\Puphpeteer\Console\Command\DoctorCommand;
use Nesk\Puphpeteer\Console\Command\GenerateCommand;
use Nesk\Puphpeteer\Console\Command\TestCommand;
use Symfony\Component\Console\Application;

final class ConsoleApplication extends Application
{
    public function __construct()
    {
        parent::__construct('PuPHPeteer', '3.0-dev');
        $this->addCommands([
            new BuildCommand('build'),
            new GenerateCommand('generate'),
            new BrowserInstallCommand('browser:install'),
            new TestCommand('test'),
            new TestCommand('test:unit', 'unit'),
            new TestCommand('test:integration', 'integration'),
            new TestCommand('test:browser', 'browser'),
            new TestCommand('test:release', 'release'),
            new BenchmarkCommand('benchmark'),
            new DoctorCommand('doctor'),
        ]);
    }
}
