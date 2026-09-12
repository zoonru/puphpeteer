# Puppeteer через php-quickjs и Amp

Рабочий прототип: оригинальный Puppeteer 24.36.1 выполняется внутри PHP-процесса
в QuickJS-NG. Amp обслуживает WebSocket, таймеры и PHP-callbacks. Node.js нужен
для сборки bundle и генерации API. Клиент, smoke и benchmark работают через PHP.

Нужна release-сборка [нашего форка php-quickjs](https://github.com/xtrime-ru/php-quickjs)
с прямым мостом `__quickjsEmit` и `Js\Callback::dispatch()`.
[Сборка, установка и проверка расширения](../README-RU.md#сборка-и-установка-php-quickjs)
([English](../README.md#build-and-install-php-quickjs)).
Обычного upstream-релиза недостаточно. Composer проверяет наличие расширения
`php_quickjs`; поддержку методов проверяет клиент при создании.
[Контракт расширения](extension-contract.md) фиксирует типы, лимиты, обработку ошибок,
владение ресурсами и границы Fibers. Наличие `dispatch` само по себе
не подтверждает совместимость старой экспериментальной сборки: запускайте
контрактные тесты на той release-библиотеке, которую будет использовать приложение.

## Запуск

Для установки браузера и сборки используйте Node.js **22+**.
Все зависимости устанавливаются из **корня репозитория**:

```sh
composer update --prefer-stable
npm ci
npm run build
export QUICKJS_EXTENSION=/absolute/path/to/libphp_quickjs.so
composer test-browser
BENCH_TRIALS=5 BENCH_ITERATIONS=1000 composer benchmark
```

На macOS расширение может иметь суффикс `.dylib`. `PHP_BIN` задаёт PHP CLI;
по умолчанию используется PHP текущего runner. Runner запускает тестовый PHP с `-n` и загружает
только указанную сборку расширения. `QUICKJS_EXTENSION` обязательна. `npm ci` устанавливает совместимый Chrome в
`node_modules/.puphpeteer/`; `npm run browser:install` повторяет установку.
Для другого браузера задайте `PUPPETEER_EXECUTABLE_PATH` (либо прежнюю `CHROME_BIN`).
Системные браузеры автоматически не выбираются.

Для PHP unit-тестов и Psalm без расширения:
`composer install --ignore-platform-req=ext-php_quickjs`, затем `composer check`.
Это не проверка браузерной интеграции; её запускают отдельно с расширением.

Бенчмарк поддерживает macOS и Linux (`/usr/bin/time`, `ps`) и измеряет только QuickJS.
Старые backend больше не входят в проект; для сравнений нужны отдельные снимки.
Каждый браузерный сценарий запускает собственный Chrome через публичный `launch()`; тестовые страницы берутся из `examples/pages`.

```php
require 'vendor/autoload.php';
use Nesk\Puphpeteer\Puppeteer;
use Nesk\Puphpeteer\JsFunction as JS;

$browser = (new Puppeteer())->connect(['browserWSEndpoint' => $browserWebSocketEndpoint]);
$context = $browser->createBrowserContext();
try {
    $page = $context->newPage();
    $page->goto('https://example.com');
    echo $page->title();
    $page->exposeFunction('phpDouble', function ($n) { Amp\delay(0.01); return $n * 2; });
    echo $page->evaluate(new JS('() => window.phpDouble(21)'));
} finally {
    $context->close();
    $browser->disconnect();
}
```

## Устройство

- `js/guest.js`: реестр JS-объектов, вызов оригинальных методов Puppeteer,
  сообщения результатов/ошибок и обратные вызовы. Тела методов не переводятся в PHP.
- `js/host-environment.js`: таймеры, performance, console, ReadableStream и
  UTF-8 TextEncoder/TextDecoder, необходимые в проверенных сценариях. Это не
  полная реализация Web API или Node.js.
- `src/Client.php`: один reader, последовательный writer, таблица Future,
  ограниченная обработка JS jobs. Продолжение очереди переносится на следующий
  оборот Revolt через `defer`, чтобы не вытеснять I/O бесконечными microtasks.
- `RemoteObject`: общий мост для сгенерированных методов и свойств; автоматически
  ожидает внутренний Future. `JsFunction` явно обозначает JS-функцию: в браузере для `evaluate`,
  в QuickJS для событий Puppeteer.
- Host callbacks внутри расширения только кладут сообщения в очередь. Асинхронный
  PHP-код запускается после возврата из QuickJS; Fiber не приостанавливается
  внутри нативного JS-вызова.

## Проверено и границы

Smoke проверяет две страницы, навигацию/title, evaluate с аргументами, конкурентные
ожидания 250/30 мс, асинхронный PHP-callback и его отказ, JSHandle/dispose,
восстановление после JS-ошибки, click в активной вкладке, console events on/once/off с повторным входом в клиент, PNG screenshot в файл,
PHP launch, browserURL и три examples.

Это разрабатываемая версия, а не полноценная замена публичного клиента.
Типизированные обёртки и запуск Chrome из PHP реализованы; покрытие API частичное.
Пока нет полного набора Web API, Firefox/BiDi,
публичного API отмены операций и полного отображения типов. [Stealth и совместимые
плагины](plugins.md) включаются в bundle; произвольные Node API недоступны.
Chrome устанавливается на этапе
подготовки через npm, а не при PHP-вызове `launch()`.
`undefined` преобразуется в `null`. Специальные значения (BigInt, non-finite numbers) возвращаются
явными tagged-массивами; входные специальные значения и произвольные циклические
PHP/JS структуры не образуют полноценный API. Обычные данные с ключом `$quickjs`
экранируются мостом и возвращаются без интерпретации служебных тегов.

JS-объекты освобождаются через `RemoteObject::release()`, отложенный финализатор
PHP-обёртки или закрытие клиента; `release()` не вызывает Puppeteer `dispose()`/`close()`.
Обработчики событий освобождаются после удаления последней регистрации;
остальные PHP callbacks удерживаются до `Client::close()`. Не передавайте RemoteObject
между разными клиентами. [Release runner](release.md) проверяет повторяемую нагрузку
и удержание ресурсов; это не доказательство отсутствия всех нативных утечек.

[Lifecycle транспорта](runtime.md) описывает таймауты, внутреннюю отмену,
очистку ресурсов и поведение при ошибках. Публичные `AbortSignal` пока не поддерживаются.

Готовые `resources/puppeteer.js` и `resources/puppeteer-core.js` включаются в Git. `Puppeteer` выбирает core bundle без плагинов и полный bundle после `use()`. Оба bundle собираются в CDP-only production-режиме; WebDriver BiDi не поддерживается. `--debug` оставляет читаемый JavaScript. Для использования пакета сборка не нужна; разработчик обновляет ресурсы через `npm run build`. CI проверяет результат командой `npm run build:check`.
