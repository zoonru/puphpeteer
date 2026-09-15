<?php

declare(strict_types=1);
use Zoon\Puphpeteer\Tooling\CodeStyle;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$files = json_decode(file_get_contents('php://stdin') ?: '', true, flags: JSON_THROW_ON_ERROR);
$sources = [];
foreach ($files as $index => $file) {
    if (str_ends_with($file['path'], '.php')) {
        $sources[$index] = $file['content'];
    }
}
foreach (CodeStyle::formatMany($sources) as $index => $content) {
    $files[$index]['content'] = $content;
}
echo json_encode($files, JSON_THROW_ON_ERROR);
