# Security Policy

## Supported versions

| Version | Supported |
| ------- | --------- |
| 1.x     | ✅        |

pbFenom is a fork of [fenom/fenom](https://github.com/fenom-template/fenom); fixes
made here are not automatically present upstream, and vice versa.

## Reporting a vulnerability

Please report privately rather than in a public issue: open a
[security advisory](https://github.com/Boshnik/pbFenom/security/advisories/new),
or write to pageblocks@boshnik.com.

Expect an acknowledgement within a few days. If the report also affects upstream Fenom,
we will coordinate before publishing details.

## Notes for integrators

Two properties of the engine are worth knowing when deploying it:

* **The compile directory contains executable PHP.** Compiled templates are `include`d
  unconditionally, so that directory must not be shared with other users and must not be
  reachable over HTTP. pbFenom refuses a world-writable compile directory and writes
  cache files with mode `0640`.
* **Auto-escaping is HTML-text escaping, not context-aware.** It does not make a URL
  safe: `javascript:` in an `href` passes through untouched. Validate schemes yourself,
  and see [the escape modifier](docs/en/mods/escape.md) for the available strategies.

If templates are authored by users who are not fully trusted, combine the `disable_*`
options — see [Sandboxing](docs/en/configuration.md#sandboxing-untrusted-templates).
