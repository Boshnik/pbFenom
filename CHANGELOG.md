Changelog
=========
## pbFenom 1.1.0

Changed

- **`eval()` is gone entirely**, and a tokenizer-based test keeps it that way.
  `FORCE_COMPILE` always wrote the artifact to disk and then ignored it, rendering
  through `eval()`; it now includes the file it just wrote, which also lets opcache keep
  the opcodes. The two modes that have no file by design — `DISABLE_CACHE` and
  `compileCode()` — now `include` from a private stream wrapper instead. That needs no
  `allow_url_include`, measured the same as `eval()`, and makes runtime errors name the
  template rather than reporting `eval()'d code on line N`.
- Modifiers accept `null` again. Upstream typed these signatures in 3.0.0, which turned
  an ordinary null template variable into a fatal on `{$x|escape}` — while plain `{$x}`
  kept working, since `htmlspecialchars(null)` merely warns. `escape`, `unescape`,
  `truncate`, `strip`, `replace`, `ereplace`, `match`, `ematch` and `date` treat null as
  `""`, the way they did before 3.0.0.
- `addFunction()` keeps its long-standing contract: the callback receives
  `($params, $tpl, $var)`. An earlier revision of this release changed the default to
  the smart parser, which broke every function registered the usual way — including
  user-supplied callbacks that frameworks built on the engine pass straight through.
  Use `addFunctionSmart()`, now fixed to accept any callable, when you want the
  callable's signature to be the template API.

Fixed

- `addFunctionSmart()` only ever worked with **string** callables: the parser called
  `strpos()` on the callback, so a closure, an array callable or an invokable object
  was a `TypeError`. Every callable form now works.
- A missing required argument to a smart function is reported at compile time
  ("Function excerpt requires the 'text' argument") instead of surfacing as an
  `ArgumentCountError` while rendering.
- `addFunction()`, `addFunctionSmart()`, `addBlockFunction()` and `addCompilerSmart()`
  did not invalidate the compile-cache signature, so two instances differing only in a
  registered *function* could still serve each other's artifacts. (Regression from the
  signature work earlier in this release.)

Note

- To make one callable usable both ways, register it twice — `addModifier()` and
  `addFunctionSmart()` with the same name. This stays two explicit calls on purpose: the
  second one claims a **tag** name, and tag names collide (`escape` and `strip` are
  both a built-in modifier and a built-in block tag), so hiding it behind one call
  would let `{strip}...{/strip}` be overwritten without a word.

Documentation

- All 102 pages now describe pbFenom rather than upstream Fenom: 308 renamed
  references, install instructions pointing at this fork, and the links pinned to
  `bzick/fenom@1.2.2` (a two-major-old tag whose paths had moved) removed.
- Deleted `tags/autotrim.md`. `{autotrim}` and the `:trim` / `:ltrim` / `:rtrim` tag
  options had been documented for a decade and never implemented.
- `configuration.md` rewritten: the option table was missing `disable_accessor`,
  `disable_php_calls` and `debug_comments`; the providers section was untranslated
  Russian inside the English docs; three callback headings had no content. Added
  sections on sandboxing, the recursion limit, the compile-directory rules and
  `getSignature()`. Every one of its 14 code examples is executed and passes.
- `mods/escape.md` rewritten for the strategies this fork actually implements, with
  the sharp edges spelled out: `js` returns a quoted literal, escaping is not
  context-aware and does not make a URL safe, and `|escape` under `auto_escape`
  escapes twice. All ten of its claims are verified by an executable check.
- Added the missing `mods/date.md` (both languages) — the index had linked to it all
  along. Fixed five broken internal links.
- New `DocsTest` guards all of this: no broken internal links, no docs for
  unimplemented features, no links to stale upstream revisions, and every modifier
  the index advertises is actually registered.

Removed

- The `auto_trim` option / `pbFenom::AUTO_TRIM` constant, reserved and inert since
  2013. Passing it now throws `Undefined option 'auto_trim'` rather than being
  silently accepted and ignored. `Tag::LTRIM` / `Tag::RTRIM` are gone too.
- Fixed the option-error message, which printed the *value* instead of the key
  ("Undefined parameter 1").

Types

- `declare(strict_types=1)` in all 17 source files. It exposed four latent bugs,
  each of which had been silently papered over by weak-mode coercion:
  `strstr()` returns `false` rather than `null` when a template name carries no
  schema, so `getProvider()` was handed a bool on *every* template load;
  `{include}` seeded its by-ref out-parameter with `false` against a `?string`;
  and both date modifiers let a numeric *string* past `is_numeric()` straight
  into `date()`, which wants an int.
- Compiled artifacts deliberately do **not** declare strict types — template data
  is arbitrary, and coercing an int handed to a string-typed modifier is the
  documented behaviour.

Fixed

- The compiled artifact wrote `provider` and `options` while `Render::__construct()`
  read `scm` and ignored `options`, so `getScm()` always returned `""` and
  `getOptions()` always `0` for a cache-loaded template — and `$.tpl.scm` /
  `$.tpl.options` lied accordingly.
- Added `pbFenom::CACHE_FORMAT` to the cache signature so artifacts from an older
  codegen are not reused.

Security / robustness

- Recursion is bounded everywhere. A cyclic or self-referencing `{include}` used to
  recurse until PHP exhausted the call stack, and a cyclic `{insert}` until it
  exhausted memory — both *fatal*, therefore uncatchable, with no context and a
  blank 500. All of them now raise a normal exception in milliseconds, naming the
  template. `pbFenom::MAX_MACRO_RECURSIVE` — declared and unused since 2013 — is
  enforced, so a runaway macro reports its own name instead of PHP's bare
  "Maximum call stack size reached". The limit is `pbFenom::$max_template_depth`
  (32) for templates. Cost on the hot path: 1.8% on a loop of 1000 `{include}`s.
- Render errors are no longer re-wrapped once per `{include}` level; the message
  used to read "unhandled exception in `a`: unhandled exception in `b`: ...".
- The macro name is emitted with `var_export()` instead of being spliced into a
  double-quoted PHP literal.

Performance

- `{foreach}` binds its key and value to local PHP references and the compiler
  resolves the loop variable to that local while parsing the body, so `{$row.n}`
  costs one hash lookup instead of two. Because the local is a *reference*,
  `$var["row"]` stays in sync and `{include}`, macros and `$.tpl` are unaffected.
  Measured same-session A/B on a 1000-row table: 454 -> 422 us (7%). Aliases are
  suspended while compiling a macro body, which becomes its own PHP scope.

Fixed

- `AUTO_RELOAD`: the first request after a template edit died with
  `CompileException: failed to store cache` and kept dying until the compile
  directory was cleared by hand. `_load()` now recompiles a stale artifact
  instead of falling through to the error. Present in upstream 3.1.0.

Performance

- `{include}` with a static name is resolved once per render instead of on every
  iteration of an enclosing loop: 1000 includes 385 -> 235 us (1.64x).
- `Render::isValid()` no longer takes the slow path for the common
  single-dependency case, and `Provider` memoises resolved paths, so `realpath()`
  leaves the hot path. A page with `{extends}` + 20 `{include}` under
  `AUTO_RELOAD`: 25.3 -> 7.6 us (3.3x). Without `AUTO_RELOAD`: 7.2 -> 4.5 us.
- Per-tag debug comments are now opt-in (`debug_comments`); they were roughly 45%
  of a generated file, costing disk and opcache memory for no runtime benefit.
- The charset argument is omitted from `htmlspecialchars()` when it already matches
  `default_charset`. Measured separately, this is ~2% - and the stronger escape
  flags adopted in 1.0.0 turned out to be *faster* than `ENT_COMPAT` (202 vs 279 ns),
  so hardening the escaping cost nothing.

Changed

- The compile-cache key now includes `pbFenom::getSignature()` - a digest of the
  registered modifiers, tags, accessors, tests and the charset. Two instances
  sharing a compile directory but configured differently used to serve each
  other's compiled templates. Closure callables are excluded on purpose: they
  compile to a runtime `call_user_func()` and are not baked into the artifact.

## pbFenom 1.0.0 (fork of fenom/fenom 3.1.0)

Security

- `{$.php}`/`{$.call}` now honour `DENY_PHP_CALLS` and the `DENY_NATIVE_FUNCS` whitelist;
  they previously reached `call_user_func_array()` with no checks at all.
- `DENY_ACCESSOR` is enforced (it was declared, mapped in `setOptions()`, never checked).
- Call filters combine with OR; two filters used to block every callback. `fnmatch()`
  uses `FNM_NOESCAPE` instead of `addslashes()`.
- `Tag::setOption()` writes the option it was given. `{strip}` did not strip and instead
  flipped auto-escaping permanently.
- Escaping uses `ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5`; `ENT_COMPAT` left `'` raw and
  dropped malformed UTF-8 values entirely.
- `escape:'js'` hex-escapes, so `</script>` can no longer close the element.
  `escape`/`unescape` throw on an unknown strategy; `'url'` is RFC 3986, `'query'` is form encoding.
- Provider containment is separator-aware and rejects NUL; `verify()` no longer
  concatenates paths directly; `Provider::clean()` does not delete through symlinks.
- No `/tmp` compile-dir default; world-writable compile dirs are refused; cache files are 0640.
- Cache names use a sha256 prefix (crc32 collided) and the length guard actually
  measures length (`$tpl > 200` compared a string to an int).
- `{extends}` cycles abort at `pbFenom::$max_template_depth`; the dynamic form used to
  spin forever at full CPU.

Fixed

- Restored `{for}`: its compiler methods were deleted by the 3.0 PHP-8 migration while the
  registration remained, so the documented tag fatalled from 3.0.0 on.
- `{use}`+`{paste}` emitted a syntax error and cached it.
- `{$.fetch("x",)}` left a variable undefined and produced invalid PHP.
- `{foreach ... last=}` fatalled on Generators.
- `{$.block}` emitted unquoted block names.
- `Modifier::length()` counted an emoji as 3.
- `AUTO_STRIP` blanked non-UTF-8 templates.
- `getModifier()`/`getTag()` TypeErrored on their own default argument.
- Removed `$.tag`, registered to a method that never existed.
- `parseStatic()` was declared `callable` but returns a string.
- `Template::$extended` had no default; `Provider::getSource()` could leak `false` into a float.

Performance

- Compilation is linear again: `Tag::cutContent()` copied the whole accumulated body on
  every block-tag close. 1 MB of `{foreach}` went from 35.8 s to 0.34 s. Generated code
  is byte-for-byte identical.

Changed

- Namespace renamed to `pbFenom` so the fork cannot be displaced by another component's
  copy of Fenom in the same process. Template syntax is unchanged.
- `factory()` requires an explicit compile directory.

Tooling

- CI runs PHP 8.2/8.3/8.4 with coverage, plus PHPStan level 3 (clean).
- Removed `.travis.yml`; `composer.lock` is no longer tracked.

## 3.0.0 (2023-02-23)

- Fenom supported php8+
- Remove `eval` from template compiler
- `strftime` -> `date` with fallback support.
- update tokenizer
- bugfixes and optimizations 

## 2.11.0 (2016-06-09)

- Added method to get the name of the cache template `$fenom->getCacheName($template_name)`(#231)
- Fix bug with before-code in template inheritance (#229)
- Added `??` operator.
- Improve compile mechanism
- ++Docs
- ++Test

## 2.10.0 (2016-05-08)

- Add tag `{do ...}`
- ++Docs
- ++Tests

## 2.9.0 (2016-05-08)

- Add `$.block`
- Refactory range
- Refactory blocks
- Docs

...

## 2.6.0 (2015-02-22)

- Add range operator (`1..3`)
- Tag `for` now is deprecated, use tag `foreach` with range
- Internal improves

### 2.5.4 (2015-02-19)

- Fix bug #152
- Add composer.lock to git

### 2.5.3 (2015-02-19)

- Fix bug #147

### 2.5.2 (2015-02-10)

- Fix bug: unexpected array conversion when object given to {foreach} with force verify option (pull #148)

### 2.5.1 (2015-02-10)

- Fix bugs #144, #135


## 2.5.0 (2015-02-01)

- Internal improvement: functions accept array of template variables 
- Improve `in` operator
- Fix bug #142

### 2.4.6 (2015-01-30)

- Fix bug #138

### 2.4.5 (2015-01-30)

Move project to organization `fenom-template`

### 2.4.4 (2015-01-22)

- Fix: parse error then modifier's argument converts to false

### 2.4.3 (2015-01-08)

- Fix #132

### 2.4.2 (2015-01-07)

- Internal improvements and code cleaning

### 2.4.2 (2015-01-07)

- Fix bug #128

## 2.4.0 (2015-01-02)

- Fix bugs #120, #104, #119
- Add `~~` operator. Concatenation with space. 
- Improve #126. Disable clearcachestats() by default in Fenom\Provider. clearcachestats() may be enabled.
- Improve accessors (unnamed system variable). Now possible add, redefine yours accessors.
- ++Docs
- ++Tests

### 2.3.1 (2014-11-06)

- Fix #122

### 2.3.1 (2014-08-27)

- Fix #105
- ++Tests

## 2.3.0 (2014-08-08)

- Add tags {set} and {add}
- Fix bug #97
- ++Docs
- --Bugs
- ++Tests

### 2.2.1 (2014-07-29)

- ++Docs
- --Bugs

## 2.2.0 (2014-07-11)
- Add new modifiers: match, ematch, replace, ereplace, split, esplit, join
- ++Docs
- ++Tests

### 2.1.2 (2014-07-03)

- Add test for bug #86 
- Fix bug #90 
- --Bugs
- ++Tests

### 2.1.1 (2014-06-30)

- Fix bug #86: mismatch semicolon separator when value for foreach got from method  (by nekufa)

## 2.1.0 (2014-06-29)

- Check variable before using in {foreach} (#83)
- Add tag {unset} (#80)
- Refactory array parser
- --Bugs
- ++Tests
- ++Docs

### 2.0.1 (2014-06-09)

- Fix string concatenation. If `~` in the end of expression Fenom generates broken template.
- Fix `~=` operator. Operator was not working.
- ++Tests
- ++Docs

## 2.0.0

- Add tag the {filter}
- Redesign `extends` algorithm:
    - Blocks don't support dynamic names
    - Blocks can't be nested
- Add tag options support
- Improve Fenom API
- Move benchmark to another project
- Internal improvements
- Add `Fenom::STRIP` option
- Add tags {escape} and {strip}
- Method addProvider accept compile path which will saved the template's PHP cache. If compile path is not specified, will be taken global compile path.

### 1.4.9 (2013-04-09)

- Fix #75
- Docs++

### 1.4.8 (2013-12-01)

- Fix #52
- Tests++

### 1.4.7 (2013-09-21)

- Bug fixes
- Tests++

### 1.4.6 (2013-09-19)

- Bug fixes
- Tests++

### 1.4.5 (2013-09-15)

- Bug fixes
- Tests++

### 1.4.4 (2013-09-13)

- Bug fixes
- Tests++

### 1.4.3 (2013-09-10)

- Bug fixes

### 1.4.2 (2013-09-06)

- Added check the cache directory to record

### 1.4.1 (2013-09-05)

- Fix equating for {case} in {switch}
- Fix ternary operator when option `force_verify` is enabled
- Docs++

## 1.4.0 (2013-09-02)

- Redesign tag {switch}
- Add tag {insert}
- Add variable verification before using (option `Fenom::FORCE_VERIFY`)
- Improve internal parsers
- Fix #45: intersection of names of tmp vars
- Fix #44: invalid `_depend` format in template
- Docs++
- Tests++

### 1.3.1 (2013-08-29)

- Fix: accessor don't work in modifier
- Removed too many EOLs in template code
- Tests++

## 1.3.0 (2013-08-23)

- Feature #41: Add system variable `$`.
- Fix bug when recursive macros doesn't work in `Fenom\Template`
- Recognize variable parser
- Recognize macros parser
- Fix `auto_reload` option
- Tests++
- Docs++

### 1.2.2 (2013-08-07)

- Fix bug in setOptions method

### 1.2.1 (2013-08-06)

- Fix #39: compile error with boolean operators

## 1.2.0 (2013-08-05)

- Feature #28: macros may be called recursively
- Feature #29: add {unset} tag
- Add hook for loading modifiers and tags
- Feature #3: Add string operator '~'
- Improve parsers: parserExp, parserVar, parserVariable, parserMacro
- Fix ternary bug
- Bugs--
- Tests++
- Docs++

### 1.1.1 (2013-07-24)

- Bug fixes

## 1.1.0 (2013-07-22)

- Bug #19: Bug with "if" expressions starting with "("
- Bug #16: Allow modifiers for function calls
- Bug #25: Invalid option flag for `auto_reload`
- Bug: Invalid options for cached templates
- Bug: Removed memory leak after render
- Fix nested bracket pull #10
- Fix bugs with provider
- Improve providers' performance
- Improve #1: Add `is` and `in` operator
- Remove Fenom::addTemplate(). Use providers for adding custom templates.
- Big refractory: parsers, providers, storage
- Improve tokenizer
- Internal optimization
- Add options for benchmark
- Add stress test (thanks to @klkvsk)
- Bugs--
- Comments++
- Docs++
- Test++

### 1.0.8 (2013-07-07)

- Perform auto_escape options
- Fix bugs
- Update documentation

### 1.0.7 (2013-07-07)

- Perform auto_escape options
- Fix bugs

### 1.0.6 (2013-07-04)

- Fix modifiers insertions

### 1.0.5 (2013-07-04)

- Add `Fenom::AUTO_ESCAPE` support (feature #2)
- Update documentation

### 1.0.4 (2013-06-27)

- Add nested level for {extends} and {use}
- Small bug fix
- Update documentation

### 1.0.3 (2013-06-20)

- Allow any callable for modifier (instead string)
- Bug fix
- Update documentation

### 1.0.2 (2013-06-18)

- Optimize extends
- Bug fix
- Update documentation

### 1.0.1 (2013-05-30)

- Bug fix
- comments don't work

## 1.0.0 (2013-05-30)

- First release
