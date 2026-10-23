# Document chunking for RAG

<p align="center"><img src="art/banner.png" alt="chunk package banner" width="100%"></p>

Split text into overlapping character windows or pack complete paragraphs,
Markdown sections, and fenced code blocks. The package is pure PHP and needs no
framework or tokenizer.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/eloquage/chunk.svg?style=flat-square)](https://packagist.org/packages/eloquage/chunk)
[![Tests](https://github.com/eloquage/chunk/actions/workflows/run-tests.yml/badge.svg)](https://github.com/eloquage/chunk/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/eloquage/chunk.svg?style=flat-square)](https://packagist.org/packages/eloquage/chunk)

## Install

```bash
composer require eloquage/chunk
```

## Character windows

```php
use Eloquage\Chunk\Chunk;

$chunker = new Chunk();
$records = $chunker->split('A short source document.', [
    'size' => 10,
    'overlap' => 2,
]);
```

Each record contains the exact source `text`, a zero-based `index`, and
`start` / `end` offsets. Offsets count UTF-8 characters; `start` is inclusive
and `end` is exclusive.

## Pack Markdown sections

Use `pack` to prefer natural boundaries. Markdown headings stay with their
first body paragraph, and fenced code blocks stay intact:

````php
$records = $chunker->split("# Guide\n\nKeep this section together.\n\n```php\nreturn true;\n```", [
    'strategy' => 'pack',
    'format' => 'markdown',
    'size' => 80,
]);
````

Plain packing prefers paragraphs and then lines. An oversized line may be
split; an oversized fenced block is kept intact and can exceed the requested
size.

## Use an existing token counter

Token budgets require a counter supplied by your application. Chunk does not
install or select a tokenizer:

```php
$chunker = new Chunk(
    static fn (string $text): int => count(preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY)),
);

$records = $chunker->split($text, ['unit' => 'tokens', 'size' => 128]);
```

## Options and limits

- `strategy`: `window` (default) or `pack`.
- `format`: `plain` (default) or `markdown`.
- `unit`: `characters` (default) or `tokens`.
- `size` must be positive; `overlap` must be non-negative and smaller than
  `size`. Unknown options are rejected.
- Empty input returns an empty list. Windows advance by `size - overlap`;
  packed structural overlap is used only when the complete piece fits.

## Test

```bash
composer test
vendor/bin/pest --coverage --min=90
```

MIT licensed. See [LICENSE.md](LICENSE.md).
