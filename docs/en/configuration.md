Setup
=====

## Configure

### Template cache

```php
$fenom->setCompileDir($dir);
```

Sets the directory where compiled templates are stored. There is **no default** — it
must be passed to `pbFenom::factory()` or set explicitly, and it must be writable.

Compiled templates are PHP files that are `include`d unconditionally, so the compile
directory is executable code. It must not be shared with other users and must not be
reachable over HTTP. A world-writable directory is refused:

```php
$fenom->setCompileDir('/tmp');
// LogicException: Cache directory /tmp is world-writable; compiled templates are
// executed as PHP.
```

If that is genuinely intended, opt out explicitly:

```php
$fenom->allowSharedCompileDir()->setCompileDir('/tmp');
```

`setCompileId()` prefixes compiled filenames, which is occasionally useful for keeping
several applications apart inside one directory:

```php
$fenom->setCompileId('admin_');
```

You rarely need it — the cache key already includes a fingerprint of the registered
modifiers, tags, accessors, tests and the charset (see `getSignature()` below).

### Template settings

```php
// via the factory
$fenom = pbFenom::factory($tpl_dir, $compile_dir, $options);
// or later
$fenom->setOptions($options);
```

Options are either an associative array (`'option_name' => true`) or a bitmask.
An unknown option name throws — it is never silently ignored.

All options are **off** by default.

| Option name            | Constant                     | Description | Affect |
| ---------------------- | ---------------------------- | ----------- | ------ |
| *auto_escape*          | `pbFenom::AUTO_ESCAPE`       | HTML-escape every variable output | decreases performance |
| *auto_reload*          | `pbFenom::AUTO_RELOAD`       | recompile when the source changes | decreases performance |
| *force_compile*        | `pbFenom::FORCE_COMPILE`     | recompile on every render | greatly decreases performance |
| *disable_cache*        | `pbFenom::DISABLE_CACHE`     | never write a compiled file | greatly decreases performance |
| *force_include*        | `pbFenom::FORCE_INCLUDE`     | inline `{include}` bodies instead of calling out | increases performance, increases cache size |
| *force_verify*         | `pbFenom::FORCE_VERIFY`      | check that every variable used exists | decreases performance |
| *strip*                | `pbFenom::AUTO_STRIP`        | collapse whitespace in the template text | decreases cache size |
| *debug_comments*       | `pbFenom::DEBUG_COMMENTS`    | emit a `/* name:line: {tag} */` comment before each compiled tag | ~45% larger cache files |
| *disable_methods*      | `pbFenom::DENY_METHODS`      | forbid calling methods on objects | |
| *disable_native_funcs* | `pbFenom::DENY_NATIVE_FUNCS` | forbid native functions except the allowed list | |
| *disable_php_calls*    | `pbFenom::DENY_PHP_CALLS`    | forbid `{$.php}` and `{$.call}` | |
| *disable_accessor*     | `pbFenom::DENY_ACCESSOR`     | forbid every `{$.…}` accessor | |
| *disable_statics*      | `pbFenom::DENY_STATICS`      | deprecated alias of *disable_php_calls* (same value) | |

```php
$fenom->setOptions(array(
    "auto_escape"   => true,
    "force_include" => true,
));
// equivalent
$fenom->setOptions(pbFenom::AUTO_ESCAPE | pbFenom::FORCE_INCLUDE);
```

### Sandboxing untrusted templates

If template authors are not fully trusted, combine the `disable_*` options. They are
enforced consistently: a call blocked as `{phpversion()}` is also blocked as
`{$.php.phpversion()}`.

```php
$fenom->setOptions(
    pbFenom::DENY_NATIVE_FUNCS | pbFenom::DENY_METHODS |
    pbFenom::DENY_PHP_CALLS    | pbFenom::DENY_ACCESSOR
);
```

`DENY_NATIVE_FUNCS` is a whitelist rather than a blanket ban — extend it with
`addAllowedFunctions()`. To let a narrow set of callables through `{$.php}` / `{$.call}`
without opening everything, register call filters; **any** matching filter allows the
call:

```php
$fenom->addCallFilter('App\Helper\*');
$fenom->addCallFilter('App\Format\*::*');
```

### About eval()

pbFenom does not call `eval()` anywhere — a test asserts this using the tokenizer, so it
cannot creep back in.

Templates normally compile to PHP files that are `include`d. Where there is no file by
design — `disable_cache`, and `compileCode()` for a template built from a string at
runtime — the generated code is served through a private stream wrapper and `include`d
from memory. This needs no `allow_url_include`, costs the same as `eval()` did, and
gives errors that name the template instead of reporting `eval()'d code on line N`.

Note that neither of those two modes can benefit from opcache: there is no file to cache.
`disable_cache` is for tests, not production.

### Recursion limit

Cyclic `{include}`, `{insert}` and `{extends}`, and runaway `{macro}` recursion, abort
with an ordinary exception instead of exhausting the PHP stack or memory.

```php
pbFenom::$max_template_depth = 32;      // templates
pbFenom::MAX_MACRO_RECURSIVE;           // macros, 32
```

### Charset

```php
pbFenom::$charset = 'UTF-8';
```

Used by auto-escaping and by the `escape` / `unescape` modifiers. It is baked into the
compiled template at compile time and is part of the cache key, so changing it
invalidates the affected caches.

## Extends

### Template providers

Templates need not live on a filesystem — they may come from a database or any other
store. A provider tells pbFenom how to read a template, how to check whether it has
changed and, optionally, where to cache it. A provider implements
`pbFenom\ProviderInterface`; the bundled `pbFenom\Provider` reads from a directory.

```php
$fenom = new pbFenom(new pbFenom\Provider($tpl_dir));
$fenom->setCompileDir($compile_dir);

// extra providers are addressed by schema: {include "db:page.tpl"}
$fenom->addProvider('db', new MyDbProvider($pdo));

// ...optionally with their own compile directory
$fenom->addProvider('db', new MyDbProvider($pdo), $other_compile_dir);
```

### Cache signature

```php
$fenom->getSignature(); // e.g. "91447a6622bb"
```

A digest of everything that can change generated code: the registered modifiers, tags,
accessors and tests, the charset, and the cache format version. It is folded into every
compiled filename, so two differently configured instances can safely share one compile
directory. Closure callables are excluded on purpose — they compile to a runtime
`call_user_func()` and never reach the artifact, so including them would only
destabilise the key between processes.

### Callbacks and filters

#### Before compile callback

Runs on the raw template source before it is parsed.

```php
$fenom->addPreFilter(function (pbFenom\Template $tpl, string $src): string {
    return str_replace('<!--#', '{*', $src);
});
```

#### Tag filter callback

Runs on the text of every tag, before it is tokenised.

```php
$fenom->addTagFilter(function (string $tag, pbFenom\Template $tpl): string {
    return $tag;
});
```

#### Filter callback

Runs on each chunk of plain (non-tag) text.

```php
$fenom->addFilter(function (pbFenom\Template $tpl, string $text): string {
    return $text;
});
```

#### After compile callback

Runs on the finished PHP body of the template.

```php
$fenom->addPostFilter(function (pbFenom\Template $tpl, string $body): string {
    return $body;
});
```

### Custom tests

`{$x is <name>}` is table-driven; a test is a `sprintf` format applied to the operand.

```php
$fenom->addTest('email', 'filter_var(%s, FILTER_VALIDATE_EMAIL) !== false');
// {if $addr is email} ... {/if}
```
