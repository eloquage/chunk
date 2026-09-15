# eloquage/chunk

Document chunking for RAG: overlapping windows, markdown/code-aware splits, and token-budget packing.

- Composer: `eloquage/chunk`
- Entrypoint: `Eloquage\Chunk\Chunk`
- This package is framework-agnostic. The Laravel app at the monorepo root is a local test bench only.

## Layout

- `src/` — public PHP API (source of truth)
- `native/` — optional TypePHP AOT sources (empty today)
- `tests/` — Pest 5
- `TYPEPHP.md` — extension build contract
- `project.yml.example` — TypePHP project config (copy to gitignored `project.yml`)

## Commands

```bash
composer test
composer format
vendor/bin/pest --coverage --min=90
```

## Conventions

- No Illuminate / Laravel service providers.
- Always ship a pure-PHP fallback. Never `require` `swoole/typephp`.
- Consumers: PHP 8.3+. Package CI: PHP 8.4. TypePHP compile: PHP 8.5 syntax.

## API invariants and fixtures

- `Chunk::split(string, array): array` is the only chunking entrypoint; keep `name()` unchanged.
- Records are exact source slices with `index`, `start` (inclusive), and `end` (exclusive). Offsets are UTF-8 character offsets even for token budgets.
- `strategy=window` is a raw sliding window. `strategy=pack` prefers paragraphs and lines, with Markdown headings and fenced blocks enabled by `format=markdown`.
- `characters` is built in. `tokens` requires the constructor-injected `callable(string): int`; never add an `eloquage/tokens` runtime dependency.
- `size` is positive and `0 <= overlap < size`. Empty input returns `[]`; oversized fenced blocks remain intact and may exceed the budget.
- Markdown tests must cover headings inside fences, unclosed fences, multibyte text, heading/body packing, and structural overlap limits. Keep fixtures source-backed and call only `Chunk`.

Package checks:

```bash
composer test
vendor/bin/pest --coverage --min=90
```

## TypePHP

Extension mode only (`mode: ext`). The v1 native feasibility pass is
explicitly skipped: keep `native/` empty, ship the pure-PHP implementation,
and do not make a generated `build/*.so` a release gate. Build in Docker, not on the host, when a future typed-kernel pass is authorized:

```bash
# harness (default: ghcr.io/eloquage/typephp-builder)
docker/typephp/build-package.sh chunk

# this repo
docker run --rm -v "$PWD":/src -w /src \
  "${ELOQUAGE_TYPEPHP_IMAGE:-ghcr.io/eloquage/typephp-builder:latest}" \
  sh -c 'test -f project.yml || cp project.yml.example project.yml; tpc.php project.yml'
```

See `TYPEPHP.md`. Linux containers only for the shared builder. Do not add
`swoole/typephp` to Composer or introduce Illuminate.

## Harness demo

Public behavior must be exercisable from the laravel-x welcome page (`/` → `resources/views/welcome.blade.php`) with a Feature test.

## Humans vs agents

- README — install/usage for humans
- This file — agent context
- TYPEPHP.md — AOT / Docker / release
