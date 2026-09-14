# PuPHPeteer

[![Puppeteer](https://img.shields.io/badge/Puppeteer-25.11.0-40B5A4?logo=puppeteer)](https://github.com/puppeteer/puppeteer/releases/tag/puppeteer-v25.11.0)
[![Chrome](https://img.shields.io/badge/Chrome-153.0.8010.36-4285F4?logo=googlechrome)](https://googlechromelabs.github.io/chrome-for-testing/)

<img src="https://user-images.githubusercontent.com/817508/100672192-dd258500-3361-11eb-845f-e8b5109752e4.png" style="max-width:100%;" width="190px" align="right">

[English](README.md) | **Русский**

Клиент [Puppeteer](https://github.com/puppeteer/puppeteer) для PHP. Оригинальный Puppeteer выполняется внутри PHP-процесса через **php-quickjs**; Amp обслуживает WebSocket, таймеры и PHP callbacks. Для работы с браузером процесс Node.js не нужен.

Сгенерированные обёртки покрывают часть API Puppeteer; встроенный stealth и пользовательские плагины доступны в пределах, описанных в документации совместимости.

## Содержание

- [Использование](#использование)
- [Требования](#требования)
- [Установка](#установка)
- [Использование с browserless](#использование-с-browserless)
- [Основные отличия от Puppeteer](#основные-отличия-от-puppeteer)
- [Плагины Puppeteer](#плагины-puppeteer)
- [Поддержка IDE и генерация API](#поддержка-ide-и-генерация-api)
- [Обновление с v2](#обновление-с-v2)
- [Разработка](#разработка)
- [Жизненный цикл runtime](#жизненный-цикл-runtime)
- [Контракт расширения](#контракт-расширения)
- [Проверки релиза](#проверки-релиза)
- [Результаты benchmark](#результаты-benchmark)
- [Лицензия](#лицензия)
- [Авторы логотипа](#авторы-логотипа)

## Использование

Открыть страницу и сохранить скриншот:

```php
require 'vendor/autoload.php';

use Nesk\Puphpeteer\Puppeteer\Puppeteer;

$puppeteer = new Puppeteer();
$browser = $puppeteer->launch();
try {
    $page = $browser->newPage();
    $page->goto('https://example.com');
    $page->screenshot(['path' => 'example.png']);
} finally {
    $browser->close();
}
```

## Требования

- PHP **8.4+** и Composer.
- Подключённый [форк расширения php-quickjs](https://github.com/xtrime-ru/php-quickjs). [Инструкция по сборке и установке](https://github.com/xtrime-ru/php-quickjs/blob/async-jobs-fibers/docs/install.md).
- Локальный Chrome или подключение к Browserless.
- Для скачивания локального Chrome: HTTPS streams в PHP (`allow_url_fopen=1`, OpenSSL) и команда `unzip`.

Для работы пакета и установки браузера Node.js и npm не нужны. Готовые JS-bundle входят в пакет.

## Установка

В корне вашего приложения выполните:

```sh
composer require zoon/puphpeteer
```

Если нужен локальный Chrome, установите совместимую версию:

```sh
php vendor/bin/console browser:install
```

Команда скачивает закреплённую версию Chrome в `.chrome` в корне приложения. Повторный запуск использует уже установленный браузер. Добавьте `/.chrome/` в `.gitignore` приложения. Для Browserless или готового Chrome из `Dockerfile-chrome` этот шаг не нужен.

Composer не запускает scripts зависимостей. Для автоматической установки добавьте hooks в `composer.json` приложения:

```json
{
  "scripts": {
    "browser:install": "@php vendor/bin/console browser:install",
    "post-install-cmd": "@browser:install",
    "post-update-cmd": "@browser:install"
  }
}
```

Если hooks уже есть, добавьте команду в их массивы. Для автоматического запуска не используйте `--no-scripts`; явная PHP-команда работает и без hooks. Разрешения npm и Composer `allow-plugins` не требуются.

Для Browserless задайте `PUPPETEER_SKIP_DOWNLOAD=true`. В `Dockerfile-chrome` уже заданы этот флаг и `PUPPETEER_EXECUTABLE_PATH`. Непустой `PUPPETEER_EXECUTABLE_PATH` также отключает скачивание. Эти ENV должны быть доступны и при установке зависимостей.

### Путь к браузеру

Установщик и `launch()` используют один каталог: `.chrome` в корне приложения или `PUPPETEER_CACHE_DIR`. Относительный путь в ENV считается от корня приложения. `launch()` сам ничего не скачивает и не ищет системный Chrome.

| Параметр | Назначение |
| --- | --- |
| `launch(['executablePath' => '/path/to/chrome'])` | Явный путь, высший приоритет |
| `PUPPETEER_EXECUTABLE_PATH` | Путь, если нет явной опции |
| `PUPPETEER_CACHE_DIR` | Каталог установки и поиска закреплённого Chrome |
| `PUPPETEER_SKIP_DOWNLOAD=true` | Пропустить скачивание браузера |
| `PUPPETEER_CHROME_SKIP_DOWNLOAD=true` | Пропустить Chrome; также принимается `PUPPETEER_SKIP_CHROME_DOWNLOAD` |

## Использование с browserless

```sh
docker compose up -d browserless
docker compose run --rm php php examples/03_browserless.php
docker compose down
```

Compose задаёт `BROWSER_WS` с токеном. Вне Compose передайте свой endpoint в `connect(['browserWSEndpoint' => $url])`; для HTTP debugging endpoint используйте `browserURL`. `disconnect()` оставляет браузер работающим, `close()` закрывает его.

| Таймаут | Что ограничивает |
| --- | --- |
| ENV контейнера `TIMEOUT=300000` | Всю сессию: 5 минут в Compose, по умолчанию Browserless — 30 секунд |
| `protocolTimeout` клиента | Отдельный CDP-вызов, миллисекунды |
| `goto(..., ['timeout' => 30000])` | Навигацию, миллисекунды |

Клиентские таймауты не продлевают серверную сессию. В Browserless v1 переменная называлась `CONNECTION_TIMEOUT`. После изменения ENV выполните `docker compose up -d browserless`. [Справочник Browserless](https://docs.browserless.io/enterprise/docker/config).

## Основные отличия от Puppeteer

API браузера находится в `Nesk\Puphpeteer\Puppeteer` (например, `Puppeteer`, `Page`, `Browser`). Общий `JsFunction` остаётся в `Nesk\Puphpeteer`; старые импорты доступны через алиасы.

### Создайте экземпляр Puppeteer

Вместо импорта Puppeteer в JavaScript используется `new Puppeteer()`. `launch()` запускает Chrome из PHP; `connect()` подключается к существующему браузеру. Оригинальная логика Puppeteer выполняется во встроенном QuickJS.

### Promise ожидаются автоматически

Явно вызывать `Revolt\EventLoop::run()` не нужно: публичные методы ожидают внутренние Future, передавая управление event loop на время ожидания. Конкурентность кооперативная: `sleep()` и длительные синхронные PHP-операции блокируют остальные задачи; используйте `Amp\delay()` и асинхронный ввод-вывод.

Публичные методы сразу возвращают результат или выбрасывают исключение. Свойства доступны через обычный синтаксис PHP, например `$page->keyboard->press('Enter')`. Future остаются внутри транспорта. Независимые операции можно выполнять параллельно через Amp:

```php
$results = Amp\Future\await([
    Amp\async(fn() => $firstPage->title()),
    Amp\async(fn() => $secondPage->title()),
]);
```

### Некоторые методы имеют PHP-алиасы

PHP не позволяет использовать имена методов Puppeteer с `$`:

| Puppeteer | PHP |
| --- | --- |
| `$` | `querySelector` |
| `$$` | `querySelectorAll` |
| `$eval` | `querySelectorEval` |
| `$$eval` | `querySelectorAllEval` |

```php
$divs = $page->querySelectorAll('div');
$headings = $page->querySelectorAll('::-p-xpath(//h2)');
```

### JavaScript-функции используют JsFunction

Передайте полный исходник функции или соберите её через совместимые фабрики:

```php
$pageFunction = new JsFunction('(element) => element.textContent');
$pageFunction = JsFunction::createWithParameters(['element'])
    ->body('return element.textContent;');
```

`JsFunction` выполняется в JavaScript: в браузере для `evaluate()`, в QuickJS для событий Puppeteer. PHP callbacks типа `Closure` выполняются в PHP. Методы событий `on()`, `once()` и `off()` сохраняют идентичность одного и того же объекта обработчика; PHP-обработчики могут вызывать методы клиента и приостанавливаться через Amp.

### Перехватывайте исключения напрямую

Модификатор `->tryCatch` не нужен:

```php
try {
    $page->goto('invalid_url');
} catch (\RuntimeException $exception) {
    // Обработайте ошибку; сама по себе ошибка JavaScript не закрывает клиент.
}
```

### Чтение потоков

`createPDFStream()` возвращает `Amp\ByteStream\ReadableStream`. Сам поток остаётся в QuickJS: PHP запрашивает порции до 64 КиБ и уступает event loop между чтениями. Мост не накапливает файл целиком. Источник может иметь собственный буфер; синхронная генерация данных в JS и передача каждой порции всё равно занимают процессорное время.

```php
$stream = $page->createPDFStream();
try {
    while (($chunk = $stream->read()) !== null) {
        // Записать в асинхронный файл или поток HTTP-ответа.
    }
} finally {
    $stream->close();
}
```

Поддерживаются Amp `pipe()` и `buffer()` (последний намеренно собирает данные целиком). Отмена через `read($cancellation)` закрывает только этот поток. Ранний `close()` также освобождает CDP-handle PDF. Node.js writable streams — отдельный интерфейс, этот адаптер их не реализует.

## Плагины Puppeteer

Зарегистрируйте встроенный плагин перед запуском или подключением:

```php
$puppeteer = (new Puppeteer())->use('stealth');
$browser = $puppeteer->launch(['headless' => true]);
```

Bundle включает исходные stealth-модули и адаптер hooks puppeteer-extra.
Пользовательские плагины регистрируются при сборке. Node API внутри QuickJS
недоступны; старая настройка `js_extra` по-прежнему отклоняется.

Регистрация действует на последующие `launch()` и `connect()`; каждое соединение получает новые экземпляры плагинов. `use()` возвращает тот же Puppeteer, повторная регистрация имени заменяет опции. Префикс `puppeteer-extra-plugin-` необязателен. Неизвестные имена, отсутствующие зависимости и несовместимые требования отклоняются до запуска Chrome. Подготовка launch ограничена `timeout` (по умолчанию 30 секунд), connect — `protocolTimeout` или `read_timeout` (180 секунд); ноль отключает этот таймаут.

Можно выбрать upstream evasions или настроить отдельный модуль:

```php
$puppeteer->use('stealth', ['enabledEvasions' => [
    'navigator.webdriver', 'navigator.languages', 'navigator.hardwareConcurrency',
]]);
$puppeteer->use('stealth/evasions/navigator.hardwareConcurrency', [
    'hardwareConcurrency' => 8,
]);
```

### Собственные плагины

Установите npm-пакет в проект сборки и создайте реестр фабрик:

```js
// app-plugins.js
import plugin from 'puppeteer-extra-plugin-example';
export default {example: options => plugin(options)};
```

```sh
docker compose run --rm php npm run build -- --plugins=./app-plugins.js
```

Сохраните собранный `resources/puppeteer.js` вместе с приложением. Для отдельного файла используйте `new Puppeteer(['bundle' => '/absolute/path/puppeteer.js'])`, затем `$puppeteer->use('example', $options)`. Явный `bundle` имеет приоритет; иначе без плагинов выбирается меньший `resources/puppeteer-core.js`. Проверка воспроизводимости требует того же `--plugins`.

Имена собственного реестра не могут заменять встроенные; зависимости тоже должны входить в реестр. Разрешение зависимостей происходит при сборке: runtime `require()` и Node-модули файловой системы, процессов и сети недоступны. Старый `js_extra` не поддерживается.

### Ограничения плагинов

- Поддерживается CDP Chrome: контексты, существующие targets, frames и popups. Созданная клиентом страница ожидает `onPageCreated`. `evaluateOnNewDocument()` действует на будущие навигации и дочерние frames; существующие документы автоматически не изменяются.
- Первый документ popup может выполниться раньше завершения `targetcreated`. Если инъекция должна предшествовать скриптам, получите страницу popup и затем выполните навигацию. Уже выполненные скрипты не исправляются задним числом.
- Для stealth `user-agent-override` используется upstream CDP `acceptLanguage` в headful и headless режимах. Файл Preferences профиля не меняется; сохранение locale и порядок HTTP-заголовков могут отличаться от Node-версии.
- Ошибки хуков запрошенных операций доходят до PHP; фоновые ошибки target/close возвращаются при следующей операции клиента. Закрытие уже закрытого target при инициализации считается обычной очисткой. `close()` и `disconnect()` доступны после ошибок. Плагины должны возвращать или ожидать свою асинхронную работу.
- Тесты проверяют конкретные свойства и lifecycle, но не гарантируют невозможность обнаружить автоматизацию сайтом.

Исходники upstream: [хуки плагинов](https://github.com/berstend/puppeteer-extra/tree/master/packages/puppeteer-extra-plugin), [stealth](https://github.com/berstend/puppeteer-extra/tree/master/packages/puppeteer-extra-plugin-stealth).

## Поддержка IDE и генерация API

Из деклараций закреплённого Puppeteer генерируются реальные PHP-классы, методы, getter-свойства, сигнатуры и PHPDoc. PhpStorm использует эти классы для автодополнения; Psalm проверяет типы. Возвращаемый JS Promise преобразуется в PHP-тип результата.

Покрытие частичное: пропущенные объявления перечислены в [отчёте покрытия](upstream/coverage.json), ограничения типов — в [контракте генератора](upstream/README.md). Покрытие деклараций не подтверждает работоспособность каждого метода.

```sh
docker compose run --rm php composer update-php
docker compose run --rm php composer verify-php
```

Первая команда обновляет обёртки и алиасы совместимости; вторая проверяет их без изменения файлов. Ручные изменения сгенерированных файлов перезаписываются. Удалённые из upstream классы, методы и соответствующие алиасы удаляются из результата генерации.

## Обновление с v2

Установите PHP 8.4+, совместимое расширение QuickJS и Chrome по инструкции выше. Node.js нужен только для разработки.

Composer подключает алиасы по принципу **best effort**, поэтому прежние импорты можно оставить. В новом коде используйте:

| Импорт v2 | Текущий импорт |
| --- | --- |
| `Nesk\Puphpeteer\Puppeteer` | `Nesk\Puphpeteer\Puppeteer\Puppeteer` |
| `Nesk\Puphpeteer\Resources\Page` (и другие обёртки) | `Nesk\Puphpeteer\Puppeteer\Page` |
| `Nesk\Rialto\Data\JsFunction` | `Nesk\Puphpeteer\JsFunction` |

Алиасы сохраняют `instanceof` для доступных обёрток, но не восстанавливают удалённые upstream-методы и не заменяют классы, уже загруженные приложением. Имена `querySelector*()` и автоматическое ожидание результатов сохраняются.

**Ошибки:** уберите `tryCatch` и перехватывайте `RuntimeException` вместо исключений Rialto. Обычная ошибка операции Puppeteer не закрывает клиент.

```php
// v2
try {
    $page->tryCatch->goto('invalid_url');
} catch (\Nesk\Rialto\Exceptions\Node\Exception $error) {
    echo $error->getMessage();
}

// v3
try {
    $page->goto('invalid_url');
} catch (\RuntimeException $error) {
    echo $error->getMessage();
}
```

**Плагины:** замените JavaScript-инициализацию в `js_extra` регистрацией до `launch()` или `connect()`.

```php
// v2
$puppeteer = new Puppeteer(['js_extra' => "
    const puppeteer = require('puppeteer-extra');
    puppeteer.use(require('puppeteer-extra-plugin-stealth')());
    instruction.setDefaultResource(puppeteer);
"]);

// v3
$puppeteer = (new Puppeteer())->use('stealth');
```

**Таймауты и HTTPS:** старые настройки преобразуются автоматически; предпочтительны имена из upstream. `protocolTimeout` задаётся в миллисекундах, а `read_timeout` — в секундах. У навигации отдельный таймаут.

```php
// v2
$puppeteer = new Puppeteer(['read_timeout' => 65]);
$browser = $puppeteer->launch(['ignoreHTTPSErrors' => true]);

// v3
$puppeteer = new Puppeteer();
$browser = $puppeteer->launch([
    'protocolTimeout' => 65000,
    'acceptInsecureCerts' => true,
]);
// В обеих версиях:
$browser->newPage()->goto($url, ['timeout' => 60000]);
```

**JavaScript-функции:** прежние фабрики продолжают работать. Прямой конструктор `(parameters, body, scope)` замените фабрикой или полным исходником функции:

```php
// v2
$function = new JsFunction(['element'], 'return element.textContent;', []);

// v3: фабрика (работает и в v2)
$function = JsFunction::createWithParameters(['element'])
    ->body('return element.textContent;');
// Или исходник функции в v3:
$function = new JsFunction('(element) => element.textContent');
```

Другие изменения поведения:

- Настройки Node, старый logger и `js_extra` вызывают исключение. Локальный запуск использует установленный Chrome, `executablePath` или `PUPPETEER_EXECUTABLE_PATH`; системный Chrome автоматически не выбирается.
- Scope и значения параметров функции по умолчанию принимают скаляры, массивы и `JsFunction`; remote handles передавайте отдельными аргументами `evaluate()`. PHP callbacks должны быть объектами `Closure`. Для конкурентности используйте `Amp\async()`; вручную вызывать `await()` для публичных результатов не нужно.
- `undefined` превращается в `null`, бинарные результаты — в PHP-строки. Файловые операции скриншотов, PDF, загрузки скриптов и стилей выполняются на PHP-хосте. Скриншот в base64 возвращается без записи в `path`.
- Firefox, pipe transport, Node.js writable streams, запись видео и `followSymlinks: false` не поддерживаются.

## Разработка

### Docker для разработки

Команды ниже выполняются из checkout репозитория и предназначены для разработки пакета. Для генерации, сборки и JS-тестов нужны Node.js **22+** и npm; они включены в образы.

Нужны Docker и Compose **2.17+**. Образ собирает закреплённый SHA форка и подключает расширение через PHP ini; PHP/Rust на хосте не нужны.

```sh
docker compose build php chrome
docker compose run --rm php composer install
docker compose run --rm php npm ci
docker compose run --rm chrome php examples/01_page_open.php
```

`Dockerfile` содержит PHP, QuickJS, Composer и Node.js без браузера. `Dockerfile-chrome` устанавливает Chrome из `upstream/lock.json` тем же PHP-установщиком в `/opt/chrome` и задаёт `PUPPETEER_EXECUTABLE_PATH=/usr/local/bin/chrome`. В образах нет кода приложения и его зависимостей; автоматически ничего не запускается. В обоих образах есть Node.js и npm для разработки. Compose монтирует весь checkout в `/app`, включая `vendor` и `node_modules`; зависимости устанавливаются прямо в каталог на хосте. `npm ci` устанавливает только инструменты разработки. При переключении между macOS и Linux повторите `npm ci` в целевом окружении: нативные npm-бинарники зависят не только от архитектуры, но и от ОС. После изменения Dockerfile, расширения или закреплённого Chrome пересоберите образ.

Архитектура соответствует хосту; доступность Chrome зависит от закреплённой версии. Chrome-сервис использует `SYS_ADMIN` для sandbox. Графического дисплея в образе нет.

#### Примеры

Запуск: `docker compose run --rm chrome php examples/<имя файла>`.

| Файл | Что показывает |
| --- | --- |
| [01_page_open.php](examples/01_page_open.php) | Опции launch, viewport, таймаут и evaluate |
| [02_page_screenshot.php](examples/02_page_screenshot.php) | Stealth, User-Agent, язык, масштаб viewport и screenshot |
| [04_form_intercept.php](examples/04_form_intercept.php) | Ожидание POST до клика, вывод данных и отмена запроса |

Используются локальные HTML без HTTP-сервера; скриншот сохраняется на хосте. Для видимого браузера на хосте с PHP/QuickJS и графическим дисплеем: `php examples/01_page_open.php --headful` (`headless => false`).

#### Обновление через Docker

Обновить PHP-зависимости и установить JS-зависимости из lock-файла:

```sh
docker compose run --rm php composer update
docker compose run --rm php npm ci
```

Обновить Puppeteer и поддерживаемый Chrome:

```sh
docker compose run --rm php npm install --save-dev --save-exact puppeteer-core@latest
docker compose run --rm php php bin/console generate
docker compose run --rm php npm run build
docker compose build chrome
docker compose run --rm chrome php bin/console test all --no-interaction
```

После обновления `upstream/lock.json` командой `generate` пересоберите `chrome`: путь из ENV выбирает браузер внутри образа. Генерация API и сборка bundle — отдельные команды. Изменения `package.json`, `package-lock.json`, `upstream/` и `resources/` будут видны в Git на хосте. Composer lock сохраняется на хосте, но в этом пакете не коммитится.


После установки Docker-окружения запускайте функциональные и статические проверки:

```sh
docker compose run --rm chrome composer test
```

Стиль PHP — Symfony (`PHP CS Fixer`) с пробелами вокруг `.` и импортом классов, включая встроенные. Проверка: `composer cs:check`, исправление всех PHP-файлов: `composer cs:fix` (также через `docker compose run --rm php ...`). Генератор применяет те же правила. Проверка включена в `composer test` и CI.

`npm run build` собирает минифицированные CDP bundle: `resources/puppeteer-core.js` без плагинов и `resources/puppeteer.js` с плагинами. `--debug` создаёт читаемый JS. Коммитьте ресурсы вместе с исходниками и lock-файлами. `package-lock.json` фиксирует JS-зависимости; `composer.lock` остаётся локальным, PHP-версии разрешает приложение.

Без расширения доступны только unit-тесты и Psalm: `PUPPETEER_SKIP_DOWNLOAD=true composer install --ignore-platform-req=ext-php_quickjs`, затем `php vendor/bin/phpunit` и `composer psalm`.

Отдельный набор: `php bin/console test unit` (или `integration` / `browser`); отдельные тесты: `php vendor/bin/phpunit --filter=...`. `composer test` включает PHP/JS-тесты, Psalm, проверку генерации API и bundle; `composer test-release` добавляет нагрузочные циклы; `composer benchmark` измеряет производительность отдельно.

Список CLI-команд: `docker compose run --rm php php bin/console`. `doctor` проверяет окружение; `--no-interaction` отключает вопросы, `--json` включает машинный вывод.

## Жизненный цикл runtime

Диагностика QuickJS записывается асинхронно: очередь ограничена 64 сообщениями / 1 МиБ, payload сообщения — 64 КиБ. При переполнении сообщения отбрасываются. Явное закрытие ждёт дописывания логов не больше секунды.

- Закрывайте страницы/контексты через `close()`, JS handles — через `dispose()`, желательно в `finally`. `release()` удаляет только ссылку моста. Не передавайте объекты между клиентами.
- `on()`, `once()` и `off()` сохраняют идентичность обработчика. Последняя снятая регистрация освобождает callback; остальные callbacks, например `exposeFunction()`, удерживаются до закрытия соединения.
- Ошибки event callbacks пишутся в stderr; callbacks с возвращаемым результатом отклоняют JS Promise. Логи записываются через Amp; `close()`/`disconnect()` ожидают дописывания до одной секунды.
- Обычные JS-ошибки и таймауты операций не закрывают соединение. Сбой транспорта очищает вызовы, таймеры и реестры; после него создайте новое соединение.
- `undefined` становится `null`, binary — PHP-строкой, пустые JS object и array — `[]`. Точные целые должны укладываться в ±9 007 199 254 740 991. BigInt/non-finite результаты — tagged-массивы; циклические и слишком глубокие данные отклоняются.
- Поддерживается CDP Chrome. Firefox/BiDi, публичный `AbortSignal` и произвольные Node API недоступны.

## Контракт расширения

Используйте SHA форка, закреплённый в `Dockerfile`: одного наличия `dispatch()` недостаточно. [Интеграционные тесты](tests/Integration/) проверяют типы, лимиты, восстановление, освобождение ресурсов и Fibers на фактически установленном расширении.

Мост копирует значения без MessagePack и shared memory. Клиент выполняет до 100 готовых JS jobs за batch, I/O обслуживает Amp. Лимиты: 2 секунды нативного исполнения, 256 МиБ JS heap, 512 КиБ stack; 4 096 сообщений / 32 МиБ очереди; 16 МиБ на преобразование с учётом структурных расходов; глубина 64. Они не ограничивают PHP callbacks и память Chrome.

При сбое dispatch частичные сообщения удаляются, но JS-изменения не откатываются: клиент закрывает транспорт без повторного вызова. PHP callbacks запускаются после выхода из native, чтобы они могли приостанавливать Fiber.

## Проверки релиза

[GitHub Actions](.github/workflows/tests.yaml) на push, PR и ручной запуск проверяет PHPUnit/Psalm для PHP 8.4/8.5, JS, bundle, нативную интеграцию, браузерные сценарии и примеры. Composer и образы кешируются; тесты проекта выполняются и при cache hit.

Нагрузку и benchmark запускаем только для релизов:

```sh
docker compose run --rm chrome composer test-release -- --cycles=50 --timeout=600
docker compose run --rm chrome composer benchmark -- --trials=5 --iterations=1000
```

Каждый цикл нагрузки проверяет две страницы, listeners, освобождение handles и восстановление после ошибок; каждый десятый создаёт PNG/PDF. Реестры ресурсов должны возвращаться к исходному уровню. `--timeout` ограничивает нагрузку в секундах; для длительного прогона увеличьте оба аргумента. Эти проверки не доказывают отсутствие всех нативных утечек.

Release workflow запускается **после публикации**, включая prerelease, и не блокирует её. До публикации проверьте аудит зависимостей и целевые платформы: hosted runtime CI покрывает Linux amd64.

### Методика benchmark

Прогон: 1 000 evaluate, 100 возвратов 64 КиБ, 20 конкурентных ожиданий по 25 мс и 20 навигаций. `--iterations` меняет число evaluate. CPU/RSS не включают Chrome; опрос раз в 25 мс может пропускать пики. Сравнивайте на одинаковом оборудовании, PHP, Chrome, расширении и bundle. Поддерживаются macOS/Linux с `ps` и `/usr/bin/time` (GNU time на Linux).

## Результаты benchmark

Последние оптимизированные прогоны QuickJS выполнены на macOS arm64, PHP 8.5.7
и управляемом Chrome 144.0.7559.96. Значения ниже — средние; запуск benchmark
описан в [методике benchmark](#методика-benchmark).

- **Rialto** — PHP управляет отдельным процессом Node.js через сокет; Puppeteer выполняется в Node.js.
- **Native PHP** — реализация на PHP напрямую работает с CDP, без Node.js и QuickJS; покрытие API частичное.
- **QuickJS** — bundle Puppeteer выполняется во встроенном QuickJS внутри PHP; Amp обслуживает WebSocket CDP.

| Измерение | Rialto | Native PHP | QuickJS, оптимизированный |
| --- | ---: | ---: | ---: |
| 1 000 вызовов `evaluate` | 413,02 мс | **331,68 мс** | 354,34 мс |
| 100 возвратов строки 64 КиБ | 114,60 мс | 103,78 мс | **76,92 мс** |
| 20 ожиданий по 25 мс | 545,30 мс | **29,64 мс** | 29,86 мс |
| CPU клиентского процесса | 830 мс | **360 мс** | 428 мс |
| Пиковая RSS клиента | 123,55 МиБ | **38,14 МиБ** | 56,53 МиБ |

## Лицензия

Лицензия MIT (MIT). См. [файл лицензии](LICENSE).

## Авторы логотипа

Логотип PuPHPeteer состоит из:

- [Puppet](https://thenounproject.com/search/?q=puppet&i=52120) автора Luis Prado из [the Noun Project](https://thenounproject.com/).
- [Elephant](https://thenounproject.com/search/?q=elephant&i=954119) автора Lluisa Iborra из [the Noun Project](https://thenounproject.com/).

Спасибо [Laravel News](https://laravel-news.com/) за выбор иконок и цветов логотипа.
