# Ship `eloquage/chunk` as PHP; compile a `.so` only when you want it

Consumers install this package with Composer and get a working PHP API under `src/`. TypePHP is optional acceleration for maintainers who want a native extension. If Composer required `swoole/typephp`, PHP-only installs would fail.

## What ships

| Path | Role |
| --- | --- |
| `src/` | Public PHP API (source of truth) |
| `native/` | Optional TypePHP sources |
| `project.yml.example` | Ext-mode compile config (copy to gitignored `project.yml`) |

Compile as a PHP **extension** (`mode: ext`). No `main()`. Pure PHP always ships. The `.so` / `.dll` is maintainer/CI only.

For v1, push/PR/tag validation is the pure-PHP gate
(`vendor/bin/pest --coverage --min=90`, no extension). Native compilation is
an optional future feasibility check and is not a release gate.

`php-version: "8.5"` in YAML is the **syntax** TypePHP accepts, not the consumer runtime. CLI flags override YAML: [COMPILER_CLI.md](https://github.com/swoole/typephp/blob/master/docs/en/COMPILER_CLI.md). Limits: [INCOMPATIBLE_PHP_FEATURES.md](https://github.com/swoole/typephp/blob/master/docs/en/INCOMPATIBLE_PHP_FEATURES.md).

## Compile in Docker

The shared builder is Linux-only. Image sources: [`eloquage/typephp-builder`](https://github.com/eloquage/typephp-builder). Artifacts are gitignored (`*.so`, `build/`). Do not commit `project.yml`.

```bash
docker pull ghcr.io/eloquage/typephp-builder:latest
```

From the laravel-x harness:

```bash
docker/typephp/build-package.sh chunk
```

From this package directory:

```bash
docker run --rm -v "$PWD":/src -w /src \
  "${ELOQUAGE_TYPEPHP_IMAGE:-ghcr.io/eloquage/typephp-builder:latest}" \
  sh -c 'test -f project.yml || cp project.yml.example project.yml; tpc.php project.yml'
```

To build the image yourself instead of pulling:

```bash
docker build -t eloquage-typephp-builder -f packages/typephp-builder/Dockerfile packages/typephp-builder
ELOQUAGE_TYPEPHP_IMAGE=eloquage-typephp-builder docker/typephp/build-package.sh chunk
```

`native/` may be empty. Do not treat stub `src/` as a proven TypePHP compile until `tpc` succeeds in that image.

## Optional native feasibility

When a future typed-kernel pass is authorized, maintainers may compile the
extension in Docker and inspect the generated artifact. v1 ships no native
sources or consumer install channel; the pure-PHP API remains authoritative.

## What TypePHP will reject

Top-level executable statements (only declarations, `use`, `declare`, constants). `strict_types=0`. Extra arguments on non-variadic functions. Composer `swoole/typephp` in this package — ext mode does not need `libphp.so`.

Agents: follow the TypePHP skill in laravel-x (`.cursor/skills/typephp/`). Do not treat GitHub `docs/en/QUICKSTART.md` or `COMPILATION_MODES.md` as current if they still show `use native_types` or only two build modes.
