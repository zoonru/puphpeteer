# PuPHPeteer

<img src="https://user-images.githubusercontent.com/817508/100672192-dd258500-3361-11eb-845f-e8b5109752e4.png" style="max-width:100%;" width="190px" align="right">

[![PHP Version](https://img.shields.io/packagist/php-v/zoon/puphpeteer.svg?style=flat-square)](http://php.net/)
[![Composer Version](https://img.shields.io/packagist/v/zoon/puphpeteer.svg?style=flat-square&label=Composer)](https://packagist.org/packages/zoon/puphpeteer)

A [Puppeteer](https://github.com/GoogleChrome/puppeteer/) bridge for PHP, supporting the entire API. Based on [Rialto](https://github.com/zoonru/rialto/), a package to manage Node resources from PHP.

Here are some examples [borrowed from Puppeteer's documentation](https://github.com/GoogleChrome/puppeteer/blob/master/README.md#usage) and adapted to PHP's syntax:

**Example** - navigating to https://example.com and saving a screenshot as *example.png*:

```php
use Nesk\Puphpeteer\Puppeteer;

$puppeteer = new Puppeteer;
$browser = $puppeteer->launch();

$page = $browser->newPage();
$page->goto('https://example.com');
$page->screenshot(['path' => 'example.png']);

$browser->close();
```

**Example** - evaluate a script in the context of the page:

```php
use Nesk\Puphpeteer\Puppeteer;
use Nesk\Rialto\Data\JsFunction;

$puppeteer = new Puppeteer;

$browser = $puppeteer->launch();
$page = $browser->newPage();
$page->goto('https://example.com');

// Get the "viewport" of the page, as reported by the page.
$dimensions = $page->evaluate(JsFunction::createWithBody("
    return {
        width: document.documentElement.clientWidth,
        height: document.documentElement.clientHeight,
        deviceScaleFactor: window.devicePixelRatio
    };
"));

printf('Dimensions: %s', print_r($dimensions, true));

$browser->close();
```

## Requirements and installation

Основное направление проекта — оригинальный Puppeteer внутри PHP-процесса через
расширение **php-quickjs**. Amp обслуживает WebSocket, таймеры и PHP-callbacks.
Node.js нужен для сборки JavaScript и тестовых runner, но не для работы клиента.

Это **разрабатываемая версия**, а не готовый production-релиз. Типизированные
обёртки полного API и поддержка `puppeteer-extra`/stealth ещё не реализованы.

## План реализации

1. **Подготовка проекта:** удалить старые backend и зависимости; настроить
   Composer, PHPUnit, Psalm, воспроизводимую JS-сборку и CI по образцу `native`.
2. **Генератор:** перенести framework из `native`; генерировать реальные
   PHP-классы и методы, PHP-сигнатуры и PHPDoc для IDE, включая `Future<T>`
   и формы массивов параметров. Psalm проверяет типы; публичный API не строится
   исключительно на `__call`.
3. **Расширение php-quickjs:** закрепить прямой мост и `dispatch`, контракт
   типов, лимиты очередей, освобождение ресурсов и безопасность Fibers.
4. **PHP runtime:** довести Future, отмену, таймауты, события, транспорт и
   lifecycle объектов.
5. **Плагины:** проверить `puppeteer-extra` и stealth в QuickJS; адаптировать
   необходимые Node API, lifecycle hooks и сборку bundle. Проверять отдельные
   evasions, новые страницы, frames и popup; загрузка плагина недостаточна.
6. **Проверки и выпуск:** совместимость API, аварийные и длительные тесты,
   утечки, повторные бенчмарки, проверка на реальной нагрузке и откат.

Координатор согласует контракты генератора, моста и runtime. После подготовки
проекта задачи 2–5 выполняются параллельно в отдельных рабочих копиях.
Результаты проверки плагинов учитываются **до фиксации архитектуры**.

## Статус подготовки

Шаг 1 выполнен: старый runtime удалён, зависимости и npm lock-файл приведены
к QuickJS, настроены PHPUnit, Psalm и CI для PHP 8.4/8.5.
Локально проверены unit-тесты, native bridge integration и браузерный smoke.
CI выполняет unit/Psalm/JS build; сборка расширения и браузерная интеграция
в CI появятся после фиксации воспроизводимой сборки форка на шаге 3.

У закреплённого Puppeteer 24.36.1 остаются npm audit-предупреждения
в цепочке `extract-zip` → `@puppeteer/browsers` → `puppeteer-core`
([GHSA-jmr9-qjv8-65gv](https://github.com/advisories/GHSA-jmr9-qjv8-65gv),
[GHSA-7pqw-9j4j-h8q3](https://github.com/advisories/GHSA-7pqw-9j4j-h8q3)).
Загрузчик браузеров исключён из QuickJS bundle; тесты используют явно заданный
Chrome. Предлагаемый npm переход на Puppeteer 25 требует отдельной проверки
совместимости и не включён в подготовку проекта.

## Архитектура текущего прототипа

- `src/` — временный динамический PHP-фасад `Nesk\Puphpeteer\Client`;
  типизированный публичный API появится на шаге 2.
- `js/` — оригинальный Puppeteer и host adapters на JS.
- `tools/build.cjs` — сборка bundle в `resources/`.
- `tests/Browser/` — браузерный smoke-тест; `benchmarks/` — бенчмарк.
- `docs/benchmarks/` — сохранённые исторические результаты.
- Прямой мост передаёт значения без MessagePack и дополнительного JSON;
  JSON самого CDP сохранён. Бинарные результаты передаются как PHP-строки.
- `Js\Callback::dispatch()` объединяет dispatch, ограниченную обработку
  jobs и возврат сообщений. Продолжение очереди отдаётся event loop.
- Код расширения находится в отдельном репозитории php-quickjs. Нужен форк
  с `dispatch` и `__quickjsEmit`; исходного релиза 0.0.2 недостаточно.

## Разработка

Нужны PHP 8.4+, Composer и Node.js для сборки. Для выполнения браузерных
сценариев нужны Chrome и совместимая сборка расширения `php_quickjs`.

```sh
# Только подготовка и проверки без загруженного расширения:
composer install --ignore-platform-req=ext-php_quickjs
npm ci
npm run build
composer test-unit
composer psalm
```

Пропуск требования расширения разрешает установить зависимости для разработки,
но не позволяет запускать сам клиент без расширения.

```sh
CHROME_BIN=/absolute/path/to/chrome QUICKJS_EXTENSION=/absolute/path/to/libphp_quickjs.dylib npm run test-smoke
CHROME_BIN=/absolute/path/to/chrome QUICKJS_EXTENSION=/absolute/path/to/libphp_quickjs.dylib npm run benchmark
```

Для Linux укажите соответствующий `.so`. `CHROME_BIN` задаётся явно;
при необходимости задайте `PHP_BIN`. Расширение должно быть собрано для выбранной версии и платформы PHP.
Команды браузерных проверок уточнены в [описании QuickJS](docs/quickjs.md).

## Старые реализации

Rialto/Node и native PHP не входят в новый runtime. Их код и тесты сохраняются
в истории Git и ветках `zoon`, `native`, `native-wip`. Framework генерации и
нужные тестовые сценарии переносим из `native` на следующих шагах.
Старые benchmark-отчёты в `docs/benchmarks/` являются историческими результатами;
они не подтверждают состояние текущей версии.

## Зависимости и сборка bundle

PHP-зависимости задаются диапазонами в `composer.json`; `composer.lock` остаётся
локальным. CI устанавливает последние допустимые зависимости через
`composer update --prefer-stable` и запускает unit-тесты и Psalm 7 beta
на PHP 8.4 и 8.5.

Для JS-инструментов сохраняется `package-lock.json`. `npm ci` устанавливает
зафиксированные зависимости, затем `npm run build` запускает `tools/build.cjs`.
Esbuild объединяет `js/guest.js`, host adapters и browser-версию Puppeteer
в `resources/puppeteer.js` (IIFE); рядом записывается `manifest.json` с версиями.
`Client` загружает этот JS в QuickJS. Это обычный JS bundle, не байткод расширения.

Bundle и manifest сохраняются в Git вместе с исходниками и npm lock-файлом.
CI выполняет `npm run build:check`: пересобирает результат в памяти и проверяет
совпадение с сохранёнными файлами. При изменении JS или npm-зависимостей выполните
`npm run build` и включите обновлённые resources в коммит.
Приложению-потребителю не нужны Node.js и npm. Требуется совместимое расширение PHP.
