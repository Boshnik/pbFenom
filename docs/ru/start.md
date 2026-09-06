Быстрый старт
============

## Установка pbFenom

### Composer

pbFenom — форк [fenom/fenom](https://github.com/fenom-template/fenom) в собственном
пространстве имён, поэтому может сосуществовать с оригиналом в одном процессе.
Пропишите в `composer.json`:
```json
{
    "require": {
        "pageblocks/fenom": "^1.0"
    }
}
```
и обновите зависимости: `composer update`.

### Ручная установка

Клонируйте pbFenom в любую директорию Вашего проекта: `git clone https://github.com/Boshnik/pbFenom.git`.

pbFenom использует [PSR-4](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-4-autoloader.md) стандарт автозагрузки.
Подключите autoloader Composer или любой другой PSR-4 совместимый загрузчик:

```php
require_once '/path/to/vendor/autoload.php';
```

## Настройка pbFenom

Есть два варианта инициировать объект шаблонизатора: через `new` оператор и фабрику.
Пример создания pbFenom через фабрику:
```php
$fenom = pbFenom::factory('/path/to/templates', '/path/to/compiled/template', $options);
```
Пример создания pbFenom через оператор `new`:
```php
$fenom = new pbFenom(new pbFenom\Provider('/path/to/templates'));
$fenom->setCompileDir('/path/to/template/cache');
$fenom->setOptions($options);
```

* `/path/to/templates` — директория в которой хранятся шаблоны.
* `/path/to/template/cache` — директория в которую pbFenom будет сохранять PHP-кеш шаблонов
* `$options` - битовая маска или массив [параметров](./configuration.md).

### Использование

Что бы отобразить шаблон на экран используйте метод `display`:

```php
// $fenom->display(string $template, array $variables) : void

$fenom->display("template/name.tpl", $vars);
```

Метод найдет шаблон `template/name.tpl` отрисует его в `stdout`, подставляя переменные из массива `$vars`.

Метод `fetch` возвращает вывод шаблона вместо его отображения на экран.
```php
// $fenom->fetch(string $template, array $variables) : string

$result = $fenom->fetch("template/name.tpl", $vars);
```

Для вывода большого количества данных можно использовать поток

```php
// $fenom->pipe(string $template, array $variables, callable $callback, int $chunk_size) : void

$fenom->pipe(
    "template/sitemap.tpl",
    $vars,
    $callback = [new SplFileObject("compress.zlib:///tmp/sitemap.xml.gz", "w"), "fwrite"], // поток с архивацией в файл /tmp/sitemap.xml.gz
    1e6 // размер куска данных в байтах
);
```

Поток позволяет обрабатывать большой результат по кускам, размер куска указывается в байтах аргументом `$chunk_size`.
Каждый кусок передается в `$callback` для обработки или вывода.
