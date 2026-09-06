Modifier date
=============

Formats a date with PHP's `date()`.

```smarty
{$value|date:$format}
```

`$value` may be a UNIX timestamp (int or numeric string), a `DateTime`, or any string
`strtotime()` understands. An unparsable string falls back to the current time.
`$format` defaults to `"Y m d"` and uses [`date()` syntax](https://www.php.net/manual/function.date.php).

```smarty
{1343323616|date:"Y"}          {* 2012 *}
{"2012-07-26"|date:"d.m.Y"}    {* 26.07.2012 *}
{$post.created|date:"Y-m-d H:i"}
```

See also [date_format](./date_format.md), which accepts `strftime()`-style patterns.
