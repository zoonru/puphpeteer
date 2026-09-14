<?php

declare(strict_types=1);
use Zoon\Puphpeteer\Tooling\CodeStyle;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$files = json_decode(file_get_contents('php://stdin') ?: '', true, flags: JSON_THROW_ON_ERROR);
foreach ($files as &$file) {
    if (str_ends_with($file['path'], '.php')) {
        $file['content'] = CodeStyle::format($file['content']);
    }
}
unset($file);
echo json_encode($files, JSON_THROW_ON_ERROR);
