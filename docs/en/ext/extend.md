Extends pbFenom
=============

*TODO*

# Add tags

В шаблонизаторе принято различать два типа тегов: _компиляторы_ и _функции_.
Compilers invokes during compilation template to PHP source and have to
Компиляторы вызываются во время преобразования кода шаблона в PHP код и возвращяю PHP код который будет вставлен вместо тега.
А функции вызываются непременно в момент выполнения шаблона и возвращают непосредственно данные которые будут отображены.
Среди тегов как и в HTML есть строчные и блоковые теги.

## Inline function

```php
$fenom->addFunction(string $name, callable $callback [, callable $parser]);
```

By default the tag's arguments are mapped onto the **callback's own signature** by
reflection — named arguments by name, bare ones by position:

```php
$fenom->addFunction('greet', function (string $name, string $greeting = 'Hello'): string {
    return "$greeting, $name!";
});
```
```smarty
{greet name="World"}                  {* Hello, World! *}
{greet name="World" greeting="Hi"}    {* Hi, World! *}
{greet "World"}                       {* positional *}
```

So the parameter names are part of your template API: renaming one breaks templates.
A missing required argument is reported at compile time.

`addFunctionSmart()` is a synonym of the above, kept because it was the only way to get
this behaviour before 1.1.0.

### The raw form

To receive the arguments as an array instead, ask for it explicitly:

```php
$fenom->addFunction('some_function', function (array $params, pbFenom\Render $tpl) {
    /* ... */
}, pbFenom::RAW_FUNC_PARSER);
```

This was the default before 1.1.0, which is why writing a function used to feel harder
than writing a modifier.

### One callable, both ways

Register it twice, under the same name:

```php
$excerpt = fn(string $text, int $words = 10): string => /* ... */;
$fenom->addModifier('excerpt', $excerpt);
$fenom->addFunction('excerpt', $excerpt);
```
```smarty
{$post.body|excerpt:20}
{excerpt text=$post.body words=20}
```

Two calls rather than one helper, deliberately: the second claims a **tag** name, and
tag names collide. `escape` and `strip` are each both a built-in modifier and a built-in
block tag, so a single call that quietly did both could overwrite `{strip}...{/strip}`.

### A parser of your own

For full control over how the tag is parsed:

```php
$fenom->addFunction('some_function', $callback,
    function (pbFenom\Tokenizer $tokenizer, pbFenom\Tag $tag) {
        /* return the PHP code for this tag */
    });
```

## Block function

Добавление блоковой функции аналогичен добавлению строковой за исключением того что есть возможность указать парсер для закрывающего тега.

```php
$fenom->addBlockFunction(string $function_name, callable $callback[, callable $parser_open[, callable $parser_close]]);
```

Сам коллбек принимает первым аргументом контент между открывающим и закрывающим тегом, а вторым аргументом - ассоциативный массив из аргуметов тега:

```php
$fenom->addBlockFunction('some_block_function', function ($content, array $params) {
    /* ... */
});
```

## Inline compiler

Добавление строчного компилятора осуществляеться очень просто:

```php
$fenom->addCompiler(string $compiler, callable $parser);
```

Парсер должен принимать `pbFenom\Tokenizer $tokenizer`, `pbFenom\Template $template` и возвращать PHP код.
Компилятор так же можно импортировать из класса автоматически

```php
$fenom->addCompilerSmart(string $compiler, $storage);
```

`$storage` может быть как классом так и объектом. В данном случае шаблонизатор будет искать метод `tag{$compiler}`, который будет взят в качестве парсера тега.

## Block compiler

Добавление блочного компилятора осуществяется двумя способами.

Первый:

```php
$fenom->addBlockCompiler(string $compiler, array $parsers, array $tags);
```
где `$parser` ассоциативный массив `["open" => parser, "close" => parser]`, сождержащий парсер на открывающий и на закрывающий тег, а `$tags` содержит список внутренних тегов в формате `["tag_name"] => parser`, которые могут быть использованы только с этим компилятором.

Второй способ добавления парсера через импортирование из класса или объекта методов:

```php
$fenom->addBlockCompilerSmart(string $compiler, $storage, array $tags, array $floats);
```

# Add modifiers

```php
$fenom->addModifier(string $modifier, callable $callback);
```

* `$modifier` - название модификатора, которое будет использоваться в шаблоне
* `$callback` - коллбек, который будет вызван для изменения данных

For example:

```smarty
{$variable|my_modifier:$param1:$param2}
```

```php
$fenom->addModifier('my_modifier', function ($variable, $param1, $param2) {
    // ...
});
```

# Extends test operator

```php
$fenom->addTest($name, $code);
```

# Add template provider

Бывает так что шаблны не хранятся на файловой сиситеме, а хранятся в некотором хранилище, например, в базе данных MySQL.
В этом случае шаблонизатору нужно описать как забирать шаблоны из хранилища, как проверять дату изменения шаблона и где хранить кеш шаблонов (опционально).
Эту задачу берут на себя Providers, это объекты реальзующие интерфейс `pbFenom\ProviderInterface`.

# Extends accessor

# Extends cache

Изначально pbFenom не расчитывался на то что кеш скомпиленых шаблонов может располагаться не на файловой системе.
Однако, в теории, есть возможность реализовать свое кеширование для скомпиленых шаблонов без переопределения шаблонизатора.
Речь идет о своем протоколе, отличным от `file://`, который [можно определить](http://php.net/manual/en/class.streamwrapper.php) в PHP.

Ваш протол должени иметь класс реализации протокола как указан в документации [Stream Wrapper](http://www.php.net/manual/en/class.streamwrapper.php).
Класс протокола может иметь не все указанные в документации методы. Вот список методов, необходимых шаблонизатору:

* [CacheStreamWrapper::stream_open](http://www.php.net/manual/en/streamwrapper.stream-open.php)
* [CacheStreamWrapper::stream_write](http://www.php.net/manual/en/streamwrapper.stream-write.php)
* [CacheStreamWrapper::stream_close](http://www.php.net/manual/en/streamwrapper.stream-close.php)
* [CacheStreamWrapper::rename](http://www.php.net/manual/en/streamwrapper.rename.php)

For `include`:

* [CacheStreamWrapper::stream_stat](http://www.php.net/manual/en/streamwrapper.stream-stat.php)
* [CacheStreamWrapper::stream_read](http://www.php.net/manual/en/streamwrapper.stream-read.php)
* [CacheStreamWrapper::stream_eof](http://www.php.net/manual/en/streamwrapper.stream-eof.php)

**Note**
(On 2014-05-13) Zend OpCacher doesn't support custom protocols except `file://` and `phar://`.

For example,

```php
$this->setCacheDir("redis://hash/compiled/");
```

* `$cache = fopen("redis://hash/compiled/XnsbfeDnrd.php", "w");`
* `fwrite($cache, "... <template content> ...");`
* `fclose($cache);`
* `rename("redis://hash/compiled/XnsbfeDnrd.php", "redis://hash/compiled/main.php");`
