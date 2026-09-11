<?php
declare(strict_types=1);
$autoload = is_file(__DIR__ . '/vendor/autoload.php') ? __DIR__ . '/vendor/autoload.php' : dirname(__DIR__, 2) . '/vendor/autoload.php';
require $autoload;
spl_autoload_register(static function (string $class): void {
    $prefix = 'PuphpeteerQuickJs\\';
    if (str_starts_with($class, $prefix)) { require __DIR__ . '/src/' . substr($class, strlen($prefix)) . '.php'; }
});
