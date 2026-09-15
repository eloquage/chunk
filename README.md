# Document chunking for RAG: overlapping windows, markdown/code-aware splits, and token-budget packing for embedding pipelines.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/eloquage/chunk.svg?style=flat-square)](https://packagist.org/packages/eloquage/chunk)
[![Tests](https://github.com/eloquage/chunk/actions/workflows/run-tests.yml/badge.svg)](https://github.com/eloquage/chunk/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/eloquage/chunk.svg?style=flat-square)](https://packagist.org/packages/eloquage/chunk)

## Installation

Install via Composer (pure PHP; always works without a native extension):

```bash
composer require eloquage/chunk
```

### Optional native acceleration

The v1 release is pure PHP. `packages/chunk/native/` stays empty and no
TypePHP Composer dependency is required. The public contract is complete
without an extension.

The future feasibility/build path is Docker-only and uses extension mode:

```bash
docker pull ghcr.io/eloquage/typephp-builder:latest
docker/typephp/build-package.sh chunk
```

Generated `build/*.so` files are optional maintainer artifacts, not a release
gate. See [TYPEPHP.md](TYPEPHP.md) for the extension contract.

## Usage

```php
use Eloquage\Chunk\Chunk;

$chunk = new Chunk();

echo $chunk->name(); // chunk

$records = $chunk->split('A short source document.', [
    'size' => 10,
    'overlap' => 2,
]);

// Each record has: text, index, start, end.
```

Every record has the exact source `text`, a zero-based `index`, and inclusive
`start` / exclusive `end` offsets measured in UTF-8 characters. Character
windows advance by `size - overlap`, so ordinary windows have the requested
overlap. `overlap` is a bounded target for packed Markdown structure: complete
paragraphs or other structural pieces are repeated only when they fit the next
budget.

For natural boundaries, use `pack`. Plain packing prefers paragraphs and then
lines; Markdown packing also keeps ATX headings with their first body
paragraph and fenced code blocks intact:

````php
$records = $chunk->split("# Guide\n\nKeep this section together.\n\n```php\nreturn true;\n```", [
    'strategy' => 'pack',
    'format' => 'markdown',
    'size' => 80,
]);
````

Select `unit => 'tokens'` only when the constructor receives the counter your
application already uses. The callable accepts a string and returns a
non-negative integer; the package does not create or require a tokenizer:

```php
$chunk = new Chunk(static fn (string $value): int => count(preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY)));
$records = $chunk->split($text, ['unit' => 'tokens', 'size' => 128]);
```

`strategy` is `window` or `pack`; `format` is `plain` or `markdown`; and
`unit` is `characters` or `tokens`. `size` must be positive, `overlap` must
be non-negative and smaller than `size`, and unknown options are rejected.
Empty input returns an empty list. An oversized fenced block is returned
intact as the one documented budget exception, including an unclosed fence
through the end of the input.

## Testing

```bash
composer test
vendor/bin/pest --coverage --min=90
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Pull requests and issues are welcome on [GitHub](https://github.com/eloquage/chunk).

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Miguel Enes](https://github.com/eloquage)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Development

See [AGENTS.md](AGENTS.md) for agent context, tests, and TypePHP Docker builds.
