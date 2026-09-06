Модификатор date
================

Форматирует дату через PHP-функцию `date()`.

```smarty
{$value|date:$format}
```

`$value` — UNIX-таймстамп (числом или числовой строкой), объект `DateTime` либо любая
строка, понятная `strtotime()`. Неразобранная строка заменяется текущим временем.
`$format` по умолчанию `"Y m d"`, синтаксис — как у [`date()`](https://www.php.net/manual/ru/function.date.php).

```smarty
{1343323616|date:"Y"}          {* 2012 *}
{"2012-07-26"|date:"d.m.Y"}    {* 26.07.2012 *}
{$post.created|date:"Y-m-d H:i"}
```

Смотрите также [date_format](./date_format.md) — он принимает шаблоны в стиле `strftime()`.
