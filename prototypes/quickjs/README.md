# Puppeteer через php-quickjs и Amp

Рабочий прототип: оригинальный Puppeteer 24.36.1 выполняется внутри PHP-процесса
в QuickJS-NG. Amp обслуживает WebSocket, таймеры и PHP-callbacks. Node.js нужен
для сборки bundle и тестового runner, но не для исполнения PHP-клиента.

Расширение: форк `/Users/xtrime/PhpstormProjects/php-quickjs`, ветка
`codex/async-jobs-fibers`. Нужны методы `runJobs()` и `hasPendingJobs()`;
исходного релиза v0.0.2 недостаточно. В этой машине проверенная локальная сборка
установлена в `/opt/homebrew/lib/php/pecl/20250925/quickjs.dylib`.

## Запуск

Из корня puphpeteer, с установленными Composer-зависимостями проекта:

```sh
npm --prefix prototypes/quickjs ci
node prototypes/quickjs/build.cjs
node prototypes/quickjs/run-smoke.cjs
BENCH_TRIALS=5 BENCH_ITERATIONS=1000 node prototypes/quickjs/benchmark.cjs
```

Параметры runner: `QUICKJS_EXTENSION` (путь к бинарнику), `CHROME_BIN`,
`PHP_BIN`.
Бенчмарк рассчитан на macOS (`/usr/bin/time -l`, `ps`); smoke не измеряет ресурсы.
Каждый runner запускает собственный Chrome с временным профилем и локальный
HTTP fixture. Завершение клиента не закрывает посторонние браузеры.

В отдельной установке можно выполнить `composer install` внутри этого каталога.
Для сравнения с Rialto/native нужны также корневые зависимости и файлы ветки
`native-wip`. Команда `composer --working-dir=prototypes/quickjs run build`
пересобирает JS bundle после установки npm-зависимостей; это ещё не генератор
полного типизированного PHP API.

```php
require 'prototypes/quickjs/bootstrap.php';

use PuphpeteerQuickJs\Client;
use PuphpeteerQuickJs\JavaScriptFunction as JS;

$client = new Client();
try {
    $browser = $client->connect($browserWebSocketEndpoint)->await();
    $context = $browser->createBrowserContext()->await();
    try {
        $page = $context->newPage()->await();
        $page->goto('https://example.com')->await();
        echo $page->title()->await();

        $page->exposeFunction('phpDouble', fn($n) => Amp\async(function () use ($n) {
            Amp\delay(0.01);
            return $n * 2;
        }))->await();
        echo $page->evaluate(new JS('() => window.phpDouble(21)'))->await();
    } finally {
        $context->close()->await();
    }
} finally {
    $client->close();
}
```

## Устройство

- `src/guest.js`: реестр JS-объектов, вызов оригинальных методов Puppeteer,
  сообщения результатов/ошибок и обратные вызовы. Тела методов не переводятся в PHP.
- `src/host-environment.js`: таймеры, performance и console, необходимые в
  проверенных сценариях. Это не полная реализация Web API или Node.js.
- `src/Client.php`: один reader, последовательный writer, таблица Future,
  ограниченная обработка JS jobs. Продолжение очереди переносится на следующий
  оборот Revolt через `defer`, чтобы не вытеснять I/O бесконечными microtasks.
- `RemoteObject`: динамическая PHP-обёртка; даже синхронный JS-метод возвращает
  PHP Future. `JavaScriptFunction` явно обозначает функцию, выполняемую в браузере.
- Host callbacks внутри расширения только кладут сообщения в очередь. Асинхронный
  PHP-код запускается после возврата из QuickJS; Fiber не приостанавливается
  внутри нативного JS-вызова.

## Проверено и границы

Smoke проверяет две страницы, навигацию/title, evaluate с аргументами, конкурентные
ожидания 250/30 мс, асинхронный PHP-callback и его отказ, JSHandle/dispose,
восстановление после JS-ошибки, click в активной вкладке, console event и PNG screenshot.

Это прототип, а не полноценная замена публичного клиента. Пока нет генерации
типизированных обёрток, полного набора Web API, Firefox/BiDi, Node.js-плагинов,
запуска/скачивания Chrome из PHP, API отмены операций и полного отображения типов.
Специальные значения (`undefined`, BigInt, non-finite numbers) возвращаются
явными tagged-массивами; входные специальные значения и произвольные циклические
PHP/JS структуры не образуют полноценный API. Ключ `$quickjs` зарезервирован протоколом.

JS-объекты удерживаются реестром до `RemoteObject::release()` или закрытия
экземпляра клиента; `release()` не вызывает Puppeteer `dispose()`/`close()`.
PHP callbacks удерживаются до `Client::close()`. Не передавайте RemoteObject
между разными клиентами. Долговременная нагрузка и исчерпание памяти не проверены.

[Результаты и методика сравнения](results/report.md).
