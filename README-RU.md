# PuPHPeteer

<img src="https://user-images.githubusercontent.com/817508/100672192-dd258500-3361-11eb-845f-e8b5109752e4.png" style="max-width:100%;" width="190px" align="right">

[English](README.md) | **Русский**

Клиент [Puppeteer](https://github.com/puppeteer/puppeteer) для PHP. Оригинальный Puppeteer выполняется внутри PHP-процесса через **php-quickjs**; Amp обслуживает WebSocket, таймеры и PHP callbacks. Для работы с браузером процесс Node.js не нужен.

Эта версия **находится в разработке и ещё не выпущена**. Сгенерированные обёртки покрывают часть API Puppeteer; встроенный stealth и пользовательские плагины доступны в пределах, описанных в документации совместимости.

## Содержание

- [Использование](#использование)
- [Требования и установка](#требования-и-установка)
- [Сборка и установка php-quickjs](#сборка-и-установка-php-quickjs)
- [Использование с browserless](#использование-с-browserless)
- [Основные отличия от Puppeteer](#основные-отличия-от-puppeteer)
- [Плагины Puppeteer](#плагины-puppeteer)
- [Поддержка IDE и генерация API](#поддержка-ide-и-генерация-api)
- [Обновление с v2](#обновление-с-v2)
- [Интерактивный CLI](#интерактивный-cli)
- [Разработка](#разработка)
- [План реализации](#план-реализации)
- [Лицензия](#лицензия)
- [Авторы логотипа](#авторы-логотипа)

## Использование

Открыть страницу и сохранить скриншот:

```php
require 'vendor/autoload.php';

use Nesk\Puphpeteer\Puppeteer;

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

Выполнить JavaScript в той же `$page` перед закрытием браузера:

```php
use Nesk\Puphpeteer\JsFunction;

$dimensions = $page->evaluate(JsFunction::createWithBody('
    return {
        width: document.documentElement.clientWidth,
        height: document.documentElement.clientHeight,
        deviceScaleFactor: window.devicePixelRatio
    };
'));

printf('Dimensions: %s', print_r($dimensions, true));
```

См. также готовые к запуску [примеры](examples/), включая [перехват отправки формы с параллельным ожиданием request](examples/04_form_intercept.php). В [инструкции запуска](examples/README.md) используются статичные страницы; browserless вынесен в отдельный пример с Docker.

## Требования и установка

- PHP **8.4+**, Composer и совместимый [**форк php_quickjs**](https://github.com/xtrime-ru/php-quickjs) с `Js\Callback::dispatch()` и `__quickjsEmit`. Сборка upstream без этих API моста не подходит.
- Chrome для локального запуска или удалённый Chrome с браузерным WebSocket endpoint.
- Node.js **22+** и npm для установки браузера и инструментов разработки. PHP-клиент и тестовые runner не запускают Node.js.

Перед установкой PHP-зависимостей соберите и подключите расширение [по инструкции ниже](#сборка-и-установка-php-quickjs).

Версия на QuickJS ещё не опубликована. Для работы используйте копию этой ветки:

```sh
composer install
npm ci
```

Перед запуском клиента подключите совместимое расширение в конфигурации PHP. Composer проверяет наличие расширения; клиент — необходимые методы моста. JS bundle хранится в `resources/` в Git, поэтому для обычного использования пересборка не нужна. Если эта копия проекта подключена к другому приложению как Composer-зависимость, запускайте npm-установку в каталоге пакета; PHP найдёт браузер и там.

`npm ci` скачивает соответствующий Chrome for Testing в `node_modules/.puphpeteer/` через postinstall. Для повторной установки выполните `npm run browser:install`.

По умолчанию `launch()` находит этот браузер в каталоге пакета или родительском каталоге приложения; системные браузеры не ищет. Путь можно переопределить стандартной переменной Puppeteer:

```sh
export PUPPETEER_EXECUTABLE_PATH=/absolute/path/to/chrome
```

Явный `launch(['executablePath' => '/absolute/path/to/chrome'])` имеет приоритет. Старая переменная `CHROME_BIN` также принимается, если `PUPPETEER_EXECUTABLE_PATH` не задана. Установка и скачивание Chrome — отдельный шаг подготовки; `launch()` не скачивает браузер автоматически.

## Сборка и установка php-quickjs

Используйте наш [форк php-quickjs](https://github.com/xtrime-ru/php-quickjs) с правками прямого моста (`dispatch` и `__quickjsEmit`). Эти правки должны присутствовать в вашей копии исходников; готовые upstream-релизы не предоставляют необходимый API.

Проверяемые правки сейчас находятся в локальной ветке `async-jobs-fibers` и ещё не отправлены в удалённый репозиторий. Пока собирайте из этой локальной копии; клонирования основной удалённой ветки недостаточно. После публикации ветки её можно получить командой:

```sh
git clone --branch async-jobs-fibers https://github.com/xtrime-ru/php-quickjs.git
```

Для сборки на Linux или macOS нужны **Rust 1.96+** с Cargo, C-компилятор и clang/libclang, **заголовки PHP 8.4+ NTS** и `php-config`. `PHP` и `PHP_CONFIG` должны указывать на одну установку PHP. Бинарный файл должен соответствовать ОС, архитектуре, минорной версии PHP и режиму thread safety целевого окружения. QuickJS включён в исходники; `phpize` не нужен.

```sh
cd /path/to/php-quickjs
export PHP="$(command -v php)"
export PHP_CONFIG="$(command -v php-config)"
cargo build --release --locked

case "$(uname -s)" in
    Darwin) export QUICKJS_EXTENSION="$PWD/target/release/libphp_quickjs.dylib" ;;
    Linux) export QUICKJS_EXTENSION="$PWD/target/release/libphp_quickjs.so" ;;
esac
```

Для производительности используйте release-сборку. Если libclang не найдена, задайте `LIBCLANG_PATH` — каталог с её динамической библиотекой. Проверьте работу самого моста, а не только загрузку расширения:

```sh
"$PHP" -n -d "extension=$QUICKJS_EXTENSION" <<'PHP'
<?php
if (!extension_loaded('php_quickjs') || !method_exists(Js\Callback::class, 'dispatch')) {
    throw new RuntimeException('The PuPHPeteer-compatible php-quickjs fork is required.');
}
$js = new QuickJS();
$callback = $js->eval('(value) => { __quickjsEmit("check", value); }');
if ($callback->dispatch([42])['messages'] !== [['check', 42]]) {
    throw new RuntimeException('QuickJS bridge check failed.');
}
echo "QuickJS bridge OK\n";
PHP
```

Для постоянной установки сохраните бинарный файл по стабильному абсолютному пути либо скопируйте в каталог из `php-config --extension-dir`. Найдите активную конфигурацию CLI и выведите строку для добавления:

```sh
php --ini
printf 'extension=%s\n' "$QUICKJS_EXTENSION"
```

Добавьте эту строку `extension=/absolute/path/...` один раз в `php.ini` или загружаемый `.ini`-файл. Если используется PHP-FPM, настройте его SAPI отдельно и перезапустите workers. Проверьте настроенный CLI командой `php --ri php_quickjs`; проверку моста выше после этого можно запускать без `-n -d ...`. Переменная `QUICKJS_EXTENSION` нужна нашим тестовым runner; обычные PHP-приложения подключают расширение через конфигурацию PHP.

Вернитесь в каталог PuPHPeteer и выполните `composer install`, `npm ci`, затем `composer test-browser`, сохранив экспортированную `QUICKJS_EXTENSION`. См. [документацию по сборке форка](https://github.com/xtrime-ru/php-quickjs/blob/main/docs/install.md) и [подробности тестирования PuPHPeteer](docs/quickjs.md).

## Использование с browserless

Подключитесь к browserless по браузерному WebSocket URL:

```php
$puppeteer = new Nesk\Puphpeteer\Puppeteer();
$browser = $puppeteer->connect([
    'browserWSEndpoint' => getenv('BROWSER_WS'),
]);
try {
    $page = $browser->newPage();
    $page->goto('https://example.com');
    $page->screenshot(['path' => 'example.png']);
} finally {
    $browser->disconnect();
}
```

Используйте URL и параметры авторизации вашей установки browserless. Для HTTP endpoint отладчика Chrome подходит `connect(['browserURL' => 'http://localhost:9222'])`. `disconnect()` отсоединяет клиент, а `close()` закрывает браузер. См. [пример browserless](examples/03_browserless.php).

## Основные отличия от Puppeteer

### Создайте экземпляр Puppeteer

Вместо импорта Puppeteer в JavaScript используется `new Puppeteer()`. `launch()` запускает Chrome из PHP; `connect()` подключается к существующему браузеру. Оригинальная логика Puppeteer выполняется во встроенном QuickJS.

### Promise ожидаются автоматически

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

## Плагины Puppeteer

Зарегистрируйте встроенный плагин перед запуском или подключением:

```php
$puppeteer = (new Puppeteer())->use('stealth');
$browser = $puppeteer->launch(['headless' => true]);
```

Bundle включает исходные stealth-модули и адаптер hooks puppeteer-extra.
Пользовательские плагины регистрируются при сборке. Node API внутри QuickJS
недоступны; старая настройка `js_extra` по-прежнему отклоняется.
См. [настройку плагинов, собственные bundle и ограничения совместимости](docs/plugins.md),
включая первый документ popup и адаптацию языка браузера.

## Поддержка IDE и генерация API

Из деклараций закреплённого Puppeteer генерируются реальные PHP-классы, методы, getter-свойства, сигнатуры и PHPDoc. PhpStorm использует эти классы для автодополнения; Psalm проверяет типы. Возвращаемый JS Promise преобразуется в PHP-тип результата.

Покрытие частичное: пропущенные объявления перечислены в [отчёте покрытия](upstream/coverage.json), ограничения типов — в [контракте генератора](upstream/README.md). Покрытие деклараций не подтверждает работоспособность каждого метода.

```sh
composer update-php
composer verify-php
```

Первая команда обновляет обёртки и алиасы совместимости; вторая проверяет их без изменения файлов. Ручные изменения сгенерированных файлов перезаписываются. Удалённые из upstream классы, методы и соответствующие алиасы удаляются из результата генерации.

## Обновление с v2

Совместимость предоставляется по принципу **best effort**. Основные классы теперь находятся в `Nesk\Puphpeteer`. Composer автоматически подключает сгенерированные алиасы `Nesk\Puphpeteer\Resources\*` и `Nesk\Rialto\Data\JsFunction`. Старые импорты и `instanceof` работают для доступных обёрток. Если старое имя уже предоставлено приложением, оно сохраняется; совместимость такого стороннего класса не гарантируется.

- PHP 8.4+ и совместимое расширение QuickJS заменяют runtime Rialto/Node. Старые реализации остаются в истории Git и ветках `zoon`, `native`, `native-wip`.
- `new JsFunction(source)` принимает полную функцию. Старый конструктор `(parameters, body, scope)` не поддерживается. Сохранены `create()`, `createWithBody()`, `createWithParameters()`, `createWithScope()`, `createWithAsync()` и неизменяемые цепочки `body()/parameters()/scope()/async()`.
- Scope функции и значения параметров по умолчанию принимают скаляры, массивы и `JsFunction`. Remote handles передавайте отдельными аргументами `evaluate()`; PHP callbacks должны быть объектами `Closure`.
- Обёртки соответствуют закреплённому API Puppeteer. Алиасы не восстанавливают удалённые или неподдерживаемые upstream-методы.
- Замените `->tryCatch` и импорты исключений Rialto обычным PHP `try/catch`; ошибки JavaScript сейчас преобразуются в `\RuntimeException`.
- Неподдерживаемые настройки, включая `js_extra`, Node-настройки и старый logger, вызывают исключение. `read_timeout` переводится из секунд в `protocolTimeout` в миллисекундах; `ignoreHTTPSErrors` — в `acceptInsecureCerts`. Firefox и pipe-транспорт не реализованы.
- Локальный запуск использует Chrome из `node_modules`, явный `executablePath` или `PUPPETEER_EXECUTABLE_PATH`. Системный Chrome автоматически не выбирается.
- `undefined` преобразуется в `null`; бинарные результаты — в PHP-строки. `screenshot()` и `pdf()` записывают результат по `path` средствами PHP. Явно ожидать публичные вызовы не нужно; для параллельности используйте `Amp\async()`.

## Интерактивный CLI

Команды разработки доступны через `bin/console` и используют Symfony
Console: анимированный индикатор, прогресс-бары, таблицы и интерактивный выбор:

```sh
bin/console                 # список команд
bin/console doctor          # PHP, QuickJS, Node.js, npm и bundle
bin/console build           # production-сборка bundle
bin/console generate --check
bin/console test            # интерактивный выбор набора тестов
bin/console benchmark --trials=5 --iterations=1000
```

Для CI используйте `--no-interaction`. Для агентов добавляйте `--json`: он
отключает украшения и печатает одну JSON-строку. Для integration, browser и benchmark
автоматически используется `QUICKJS_EXTENSION`; параметр
`--extension=/path/to/module` имеет приоритет. Старые Composer-команды также
остаются доступными.

## Разработка

Для разработки без подключённого расширения:

```sh
composer install --ignore-platform-req=ext-php_quickjs
npm ci
npm run build
composer test-unit
composer psalm
npm run test-generator
composer verify-php
```

Пропуск требования платформы только разрешает установку зависимостей; клиенту всё равно нужно расширение. Для браузерных проверок установите Chrome, как описано выше, и укажите совместимый бинарный файл расширения:

```sh
QUICKJS_EXTENSION=/absolute/path/to/libphp_quickjs.so composer test-browser
QUICKJS_EXTENSION=/absolute/path/to/libphp_quickjs.so composer test-release
QUICKJS_EXTENSION=/absolute/path/to/libphp_quickjs.so composer benchmark
```

На macOS расширение может иметь суффикс `.dylib`. `PHP_BIN` переопределяет исполняемый файл PHP для runner. Smoke запускает браузерные сценарии и все три примера. Бенчмарк поддерживает macOS и Linux и измеряет PHP-нагрузку отдельно от Chrome и процесса-обёртки. Подробнее — [устройство QuickJS и проверки](docs/quickjs.md).

`npm run build` создаёт минифицированные CDP-only bundle: `resources/puppeteer-core.js` для пути без плагинов и `resources/puppeteer.js` с поддержкой плагинов, а также метаданные версий и параметры запуска. Включайте эти ресурсы в коммит вместе с изменениями исходников и lock-файла. `npm run build:check` проверяет воспроизводимость, `npm run build -- --debug` создаёт читаемые bundle для отладки. Диапазоны PHP-зависимостей разрешает приложение-потребитель; `composer.lock` остаётся локальным. JS-инструменты закреплены в `package-lock.json`.

## План реализации

1. **Подготовка:** удалить старые backend; настроить Composer, PHPUnit, Psalm, воспроизводимый bundle и CI. Выполнено.
2. **Генератор:** реальные методы, свойства, PHP-типы, PHPDoc и алиасы совместимости. Реализовано; покрытие API остаётся частичным.
3. **Расширение:** прямой мост, `dispatch`, контракт типов, лимиты очередей, освобождение callback/handle и границы Fibers реализованы и покрыты [контрактными тестами](docs/extension-contract.md). Проверка релиза на остальных платформах остаётся в пункте 6.
4. **Runtime:** реализованы завершение транспорта, внутренняя отмена, восстановление после таймаутов и очистка объектов/браузера; добавлены [lifecycle-тесты и описание границ](docs/runtime.md).
5. **Плагины:** реализованы встроенные stealth-модули, реестр пользовательских плагинов и lifecycle hooks с изолированными и браузерными тестами; [границы совместимости](docs/plugins.md) описаны.
6. **Выпуск:** реализованы PHP runner проверок, повторяемая браузерная нагрузка, контроль удержания ресурсов, переносимые бенчмарки и CI-матрица расширения/браузера. См. [проверки и оставшиеся блокеры релиза](docs/release.md).

CI проверяет unit-тесты, Psalm, генерацию API, плагины и JS-сборку. Матрице расширения/браузера нужен опубликованный SHA форка в `PHP_QUICKJS_REF`. Перед релизом остаются публикация этой версии, успешные прогоны на целевых платформах и обновление уязвимого загрузчика Chrome. Исторические отчёты в [docs/benchmarks](docs/benchmarks/) описывают предыдущие снимки проекта.

## Лицензия

Лицензия MIT (MIT). См. [файл лицензии](LICENSE).

## Авторы логотипа

Логотип PuPHPeteer состоит из:

- [Puppet](https://thenounproject.com/search/?q=puppet&i=52120) автора Luis Prado из [the Noun Project](https://thenounproject.com/).
- [Elephant](https://thenounproject.com/search/?q=elephant&i=954119) автора Lluisa Iborra из [the Noun Project](https://thenounproject.com/).

Спасибо [Laravel News](https://laravel-news.com/) за выбор иконок и цветов логотипа.
