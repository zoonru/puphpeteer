# Puppeteer через php-quickjs и Amp

Рабочий прототип: оригинальный Puppeteer 24.36.1 выполняется внутри PHP-процесса
в QuickJS-NG. Amp обслуживает WebSocket, таймеры и PHP-callbacks. Node.js нужен
для сборки bundle и тестового runner, но не для исполнения PHP-клиента.

Нужна сборка форка `php-quickjs` с прямым мостом и `dispatch()`.
Обычного upstream-релиза недостаточно. Composer проверяет наличие расширения
`php_quickjs`; поддержку методов проверяет клиент при создании.

## Запуск

Все зависимости устанавливаются из **корня репозитория**:

```sh
composer update --prefer-stable
npm ci
npm run build
export QUICKJS_EXTENSION=/absolute/path/to/libphp_quickjs.so
export CHROME_BIN=/absolute/path/to/chrome
npm run test-smoke
BENCH_TRIALS=5 BENCH_ITERATIONS=1000 npm run benchmark
```

На macOS расширение может иметь суффикс `.dylib`. `PHP_BIN` задаёт PHP CLI;
по умолчанию используется `php` из PATH. Runner запускает PHP с `-n` и загружает
только указанную сборку расширения. `QUICKJS_EXTENSION` и `CHROME_BIN` обязательны.

Для PHP unit-тестов и Psalm без расширения:
`composer install --ignore-platform-req=ext-php_quickjs`, затем `composer check`.
Это не проверка браузерной интеграции; её запускают отдельно с расширением.

Бенчмарк рассчитан на macOS (`/usr/bin/time -l`, `ps`) и измеряет только QuickJS.
Новые результаты пишутся в игнорируемый каталог `benchmarks/results/current/`.
Сохранённые файлы в `docs/benchmarks/` — историческое сравнение прототипов;
старые backend больше не входят в проект. Для сравнений нужны отдельные снимки.
Каждый runner запускает собственный Chrome с временным профилем и локальный HTTP fixture.

```php
require 'vendor/autoload.php';

use Nesk\Puphpeteer\Client;
use Nesk\Puphpeteer\JavaScriptFunction as JS;

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

- `js/guest.js`: реестр JS-объектов, вызов оригинальных методов Puppeteer,
  сообщения результатов/ошибок и обратные вызовы. Тела методов не переводятся в PHP.
- `js/host-environment.js`: таймеры, performance и console, необходимые в
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

[Результаты и методика сравнения](benchmarks/report.md).

Готовые `resources/puppeteer.js` и `resources/manifest.json` включаются в Git. Для использования пакета сборка не нужна; разработчик обновляет их через `npm run build`. CI проверяет результат командой `npm run build:check`.
