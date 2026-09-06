Modifier escape
===============

Escapes a string for safe insertion into the output. The strategy depends on the
context you are inserting into; the default is HTML.

```smarty
{$text|escape}
{$text|escape:'html'}
{$text|escape:$type:$charset}
```

`e` is an alias of `escape`.

## Strategies

| Strategy | For | Implementation |
| -------- | --- | -------------- |
| `html` (default) | HTML text and **quoted** attributes | `htmlspecialchars()` with `ENT_QUOTES\|ENT_SUBSTITUTE\|ENT_HTML5` |
| `attr` | the same; a clearer name at a quoted attribute | as above |
| `js` | a JavaScript literal inside `<script>` | `json_encode()` with the `JSON_HEX_*` flags |
| `url` | a URI path segment | `rawurlencode()` — a space becomes `%20` |
| `query` | a form-encoded query value | `urlencode()` — a space becomes `+` |

An **unknown strategy throws** `InvalidArgumentException`. It does not fall back to
returning the string unescaped, which would silently produce no escaping at all.

`$charset` applies to `html` / `attr` only and defaults to `pbFenom::$charset`.

## Notes

`'` is escaped, so a single-quoted attribute is safe:

```smarty
<a href='{$url|escape}'>          {* &apos; cannot break out *}
```

Invalid UTF-8 is replaced rather than dropped — without `ENT_SUBSTITUTE`,
`htmlspecialchars()` returns an empty string and the value silently vanishes.

`js` returns a **complete literal, quotes included**, so do not add your own:

```smarty
<script>var a = {$text|escape:'js'};</script>    {* correct *}
<script>var a = "{$text|escape:'js'}";</script>  {* wrong: doubled quotes *}
```

The `JSON_HEX_*` flags are what keep `</script>` from closing the surrounding element.

Escaping is **not** context-aware: the modifier does not know where in the document its
result lands, and `auto_escape` applies the `html` strategy everywhere. In particular it
does **not** make a URL safe — `javascript:` in an `href` passes through untouched, so
validate the scheme yourself.

Under `auto_escape` an explicit `{$x|escape}` escapes twice. Use `{raw $x}` for a value
you have already escaped yourself.
