Differences from upstream
=========================

pbFenom is a fork of [fenom/fenom](https://github.com/fenom-template/fenom) **3.1.0**.
Template syntax is unchanged; what differs is engine behaviour, robustness and speed.
Every row below was verified by running code — each has a regression test.

## Security

The first four rows matter when template authors are not fully trusted. In MODX that is
the normal case: editing chunks is not an administrator-only right.

| | upstream 3.1.0 | pbFenom |
|---|---|---|
| `{$.php.f()}` under `DENY_PHP_CALLS` | runs — the option is never checked | blocked |
| `{$.php.f()}` under `DENY_NATIVE_FUNCS` | runs, although `{f()}` is blocked | obeys the same whitelist |
| `disable_accessor` | declared, never checked anywhere | enforced |
| two call filters | combined with AND — nothing passes | any matching filter allows |
| auto-escaping | `ENT_COMPAT` — `'` is left raw | `ENT_QUOTES\|ENT_SUBSTITUTE\|ENT_HTML5` |
| malformed UTF-8 in a value | the value silently becomes empty | replacement character |
| `escape:'js'` | `</script>` passes straight through | hex-escaped, cannot break out |
| `escape:'unknown'` | returns the string unescaped | throws |
| `{strip}` | does not strip; flips escaping and never restores it | strips; leaves escaping alone |
| template-root check | bare prefix — accepts `<root>_backup` | separator-aware; NUL rejected |
| `Provider::clean()` | deletes through symlinks, outside the directory | removes the link only |
| compile directory | defaults to `/tmp`; a planted file is executed | required; world-writable refused; files `0640` |
| cache filename | `crc32` — collides after ~65k names | `sha256` prefix |
| `{include}` / `{insert}` cycle | uncatchable fatal: stack or memory | exception naming the template, instantly |
| runaway `{macro}` | PHP's stack guard, naming no macro | `MAX_MACRO_RECURSIVE` |
| `eval()` | used in three modes | none anywhere |

## Correctness

| | upstream 3.1.0 | pbFenom |
|---|---|---|
| `AUTO_RELOAD` after a template edit | throws until the cache is cleared by hand | recompiles |
| two instances, one compile dir | serve each other's artifacts | key includes the registry |
| `{for}` tag | fatal: methods deleted in 3.0.0, registration left behind | restored |
| `{use}` + `{paste}` | writes invalid PHP to the cache | works |
| `{foreach ... last=}` over a Generator | fatal on `count()` | works |
| `{$.block}` | Undefined constant — names emitted unquoted | works |
| `{$.tag}` | fatal: the method never existed | removed |
| `{"😀"\|length}` | 3 | 1 |
| `strip` + non-UTF-8 template | the whole output is silently empty | text preserved |
| `addFunctionSmart()` with a closure | TypeError — strings only | any callable |
| `addFunction()` | callback gets `($params, $tpl, $var)` | the callable's signature is the template API |
| `getModifier('x')` with one argument | TypeError on its own default | works |

## Performance

Measured on PHP 8.3, identical templates, warm cache. Generated code is byte-for-byte
identical to upstream's — the speedups do not come from changing the output.

| Scenario | upstream | pbFenom | |
|---|---:|---:|---:|
| compiling a 256 KB template | 183 ms | 103 ms | 1.8× |
| compiling a 512 KB template | 4,771 ms | 170 ms | 28× |
| compiling a 1 MB template | 35,844 ms | 340 ms | 106× |
| `{include}` ×1000 in a loop | 385 µs | 235 µs | 1.6× |
| a page under `AUTO_RELOAD` | 25.3 µs | 7.6 µs | 3.3× |
| `{foreach}` over a 1000-row table | 454 µs | 422 µs | −7% |
| artifact size | 45% is comments | behind an option | −45% |

Compilation was quadratic in block-tag count: every `{/foreach}`, `{/block}` and
`{/macro}` copied the whole accumulated template body. Hence 35 seconds on a megabyte —
and linear time once fixed.

## Code quality

| | upstream 3.1.0 | pbFenom |
|---|---|---|
| tests | 937 | 1053 |
| `declare(strict_types=1)` | in no file | in all 17 |
| static analysis | none | PHPStan level 3, clean |
| CI | one job, no matrix, no coverage | PHP 8.2 / 8.3 / 8.4 + coverage |

## Compatibility

**Unchanged:** the whole template syntax, option names and meanings, modifiers, tags,
accessors, the BSD-3-Clause license.

**Needs a change when migrating:**

* the `pbFenom` namespace instead of `Fenom`;
* `factory()` requires a compile directory — the `/tmp` default is gone;
* `ENT_QUOTES` changes output: `'` becomes `&apos;`;
* `addFunction()` reads the callable's signature by default;
* the cache format changed — clear the compile directory once.

**Inherited from upstream 3.0.0**, not introduced here: `ProviderInterface` is typed (so
custom providers need matching signatures), PHP 8.2+ is required, `registerAutoload()`
was removed.

## Why the namespace was renamed

In MODX every component loads its own `vendor/` into one PHP process, and classes are
global. Without the rename another component's copy of Fenom could win the autoload race
and silently replace the patched engine with an unpatched one. Composer cannot prevent
this: it knows nothing about a neighbouring component's `vendor/`.
