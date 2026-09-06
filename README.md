pbFenom — hardened Fenom for PageBlocks
=======================================

A fork of [fenom/fenom](https://github.com/fenom-template/fenom) 3.1.0, isolated under
its own namespace so it cannot be displaced by another component's copy.

* **Template syntax:** unchanged — Smarty-like, fully compatible with upstream Fenom.
* **PHP:** 8.2+
* **Differences from upstream:** [English](./docs/en/upstream-diff.md) · [Русский](./docs/ru/upstream-diff.md)

## Why a fork

Upstream is maintained sporadically (one burst of activity in April 2026, nothing since),
has no working security contact, and no external code contribution has ever been merged.
The fixes below change behaviour, so they were unlikely to land upstream quickly.

## Why a renamed namespace

MODX extras each load their own vendor tree into the same PHP process, and PHP has one
global class namespace. If another component ships `fenom/fenom`, whichever autoloader
resolves `Fenom\Template` first wins — silently. Composer's `replace` cannot help,
because that component is not in this project's dependency graph.

Renaming to `pbFenom` makes the two coexist. Verified: with the original Fenom loaded
first in the same process, `Fenom` keeps its own behaviour and `pbFenom` keeps ours.

No `class_alias` is provided on purpose — it would reintroduce the collision.

## Usage

```php
require __DIR__ . '/vendor/autoload.php';

$fenom = pbFenom::factory($templateDir, $compileDir, [
    'auto_escape' => true,
    'auto_reload' => true,
]);

echo $fenom->fetch('page.tpl', ['user' => $user]);
```

Migrating existing code: replace `Fenom` with `pbFenom` and `Fenom\` with `pbFenom\`.
Templates need no changes at all.

## Differences from upstream 3.1.0

### Security

| | upstream | pbFenom |
|---|---|---|
| `{$.php.f()}` under `DENY_PHP_CALLS` | runs | blocked |
| `{$.php.f()}` under `DENY_NATIVE_FUNCS` | runs | follows the whitelist, like a plain call |
| `disable_accessor` | no-op | enforced |
| two call filters registered | blocks everything (AND) | any match allows (OR) |
| `{strip}` | doesn't strip; permanently flips escaping | strips; leaves escaping alone |
| auto-escape flags | `ENT_COMPAT` — `'` stays raw | `ENT_QUOTES\|ENT_SUBSTITUTE\|ENT_HTML5` |
| malformed UTF-8 in a value | becomes `""` | substituted |
| `escape:'js'` | `</script>` passes through | hex-escaped |
| `escape:'unknown'` | returns input unescaped | throws |
| template root check | bare prefix — accepts `<root>_backup` | separator-aware, rejects NUL |
| `Provider::clean()` | deletes through symlinks | removes the link only |
| compile dir default | `/tmp` | required; world-writable rejected; files `0640` |
| cache filename | `crc32` — collides | `sha256` prefix |
| `{extends}` cycle | OOM, or an endless CPU spin | aborts at `pbFenom::$max_template_depth` |
| `{include}` / `{insert}` cycle | uncatchable fatal (stack or memory) | catchable exception, named template |
| runaway `{macro}` | PHP's bare "max call stack" | enforces `MAX_MACRO_RECURSIVE` |
| `AUTO_RELOAD` after a template edit | throws until the cache is cleared by hand | recompiles |
| two instances, one compile dir | serve each other's artifacts | keyed by `getSignature()` |
| `eval()` | used for `force_compile`, `disable_cache`, `compileCode()` | **none anywhere** |

Note the compile directory holds PHP that is `include()`d unconditionally: it must not be
shared or reachable over HTTP.

### Correctness

`{foreach ... last=}` works on Generators · `{$.block}` emits quoted names ·
`Modifier::length()` counts astral characters · `*/` in a tag no longer breaks the
generated PHP · `AUTO_STRIP` no longer blanks non-UTF-8 templates ·
`getModifier()`/`getTag()` accept their own default argument · removed the dead
`$.tag` accessor.

### Performance

Compilation was O(n²) in block-tag count: `Tag::cutContent()` copied the whole
accumulated body on every `{/foreach}`, `{/block}` and `{/macro}`.

| template | upstream | pbFenom |
|---:|---:|---:|
| 256 KB | 183 ms | 103 ms |
| 512 KB | 4 771 ms | 170 ms |
| 1 MB | 35 844 ms | 340 ms |

Compilation is now linear, and the generated code is byte-for-byte identical to
upstream's.

Rendering: `{include}` in a loop is 1.64x faster (a static name is resolved once per
render); a page under `AUTO_RELOAD` is 3.3x faster (25.3 -> 7.6 us); `{foreach}` binds
its loop variable to a local reference, which is ~7% on a 1000-row table. Per-tag debug
comments moved behind the `debug_comments` option — they were ~45% of a generated file.

## Tests

```
composer install && vendor/bin/phpunit
```

1053 tests — 937 from upstream plus 116 regression tests.

## License

BSD-3-Clause, unchanged. Copyright (c) 2013 Ivan Shalganov; fork modifications
copyright (c) 2026 PageBlocks contributors. See [license.md](license.md).
