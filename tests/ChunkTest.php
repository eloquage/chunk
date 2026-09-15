<?php

use Eloquage\Chunk\Chunk;

it('creates exact overlapping UTF-8 character windows', function () {
    $records = (new Chunk)->split('A🙂BCé', [
        'size' => 3,
        'overlap' => 1,
    ]);

    expect($records)->toBe([
        ['text' => 'A🙂B', 'index' => 0, 'start' => 0, 'end' => 3],
        ['text' => 'BCé', 'index' => 1, 'start' => 2, 'end' => 5],
    ]);
});

it('returns no phantom record for empty input', function () {
    expect((new Chunk)->split('', ['size' => 2]))->toBe([]);
});

it('packs complete paragraphs before splitting ordinary text', function () {
    $text = "first paragraph\n\nsecond paragraph\n\nthird";
    $records = (new Chunk)->split($text, [
        'strategy' => 'pack',
        'size' => 18,
    ]);

    expect(array_column($records, 'text'))->toBe([
        "first paragraph\n\n",
        "second paragraph\n\n",
        'third',
    ]);

    foreach ($records as $record) {
        expect(substr($text, $record['start'], $record['end'] - $record['start']))->toBe($record['text']);
    }
});

it('keeps Markdown headings with their first body paragraph', function () {
    $records = (new Chunk)->split("# Intro\nBody\n\n# Next\nDone", [
        'strategy' => 'pack',
        'format' => 'markdown',
        'size' => 20,
    ]);

    expect(array_column($records, 'text'))->toBe([
        "# Intro\nBody\n\n",
        "# Next\nDone",
    ]);
});

it('treats fenced code as atomic and ignores headings inside it', function () {
    $text = "# Intro\nBody\n\n```php\n# not a heading\nreturn true;\n```\n\n# Next\nDone";
    $records = (new Chunk)->split($text, [
        'strategy' => 'pack',
        'format' => 'markdown',
        'size' => 20,
    ]);

    expect(array_column($records, 'text'))->toContain("```php\n# not a heading\nreturn true;\n```\n")
        ->and(array_column($records, 'text'))->not->toContain('# not a heading');
});

it('preserves an unclosed fence through the end of input', function () {
    $text = "Intro\n\n```\n# still code\ncontent";
    $records = (new Chunk)->split($text, [
        'strategy' => 'pack',
        'format' => 'markdown',
        'size' => 5,
    ]);

    $characterLength = preg_match_all('/./us', $text, $matches);

    expect(end($records)['text'])->toBe("```\n# still code\ncontent")
        ->and(end($records)['start'])->toBe(7)
        ->and(end($records)['end'])->toBe($characterLength);
});

it('splits an oversized ordinary line without losing source text', function () {
    $text = 'abcdefghij';
    $records = (new Chunk)->split($text, [
        'strategy' => 'pack',
        'size' => 4,
    ]);

    expect(array_column($records, 'text'))->toBe(['abcd', 'efgh', 'ij'])
        ->and(implode('', array_column($records, 'text')))->toBe($text);
});

it('uses an injected token counter while retaining character offsets', function () {
    $counter = static fn (string $value): int => preg_match_all('/\S+/u', trim($value), $matches);
    $text = 'one two three four';
    $records = (new Chunk($counter))->split($text, [
        'unit' => 'tokens',
        'size' => 2,
        'overlap' => 1,
    ]);

    expect($records)->not->toBe([])
        ->and($records[0]['start'])->toBe(0)
        ->and($records[0]['text'])->toBe(substr($text, $records[0]['start'], $records[0]['end'] - $records[0]['start']));

    foreach ($records as $record) {
        expect($counter($record['text']))->toBeLessThanOrEqual(2);
    }
});

it('rejects invalid options before producing records', function (array $options, string $message) {
    expect(fn () => (new Chunk)->split('text', $options))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'zero size' => [['size' => 0], 'positive integer'],
    'negative overlap' => [['overlap' => -1], 'non-negative integer'],
    'overlap too large' => [['size' => 2, 'overlap' => 2], 'smaller than size'],
    'unknown unit' => [['unit' => 'words'], 'characters or tokens'],
    'unknown strategy' => [['strategy' => 'semantic'], 'window or pack'],
    'unknown option' => [['unexpected' => true], 'Unknown chunk option'],
]);

it('requires a token counter for token budgets', function () {
    expect(fn () => (new Chunk)->split('text', ['unit' => 'tokens']))
        ->toThrow(InvalidArgumentException::class, 'injected token counter');
});

it('rejects invalid UTF-8 and invalid token-counter results', function () {
    expect(fn () => (new Chunk)->split("invalid\xB1"))
        ->toThrow(InvalidArgumentException::class, 'valid UTF-8');

    $counter = static fn (string $value): string => 'not a count';

    expect(fn () => (new Chunk($counter))->split('text', ['unit' => 'tokens']))
        ->toThrow(InvalidArgumentException::class, 'non-negative integer');
});

it('does not require the tokens package at runtime', function () {
    expect(class_exists('Eloquage\\Tokens\\Tokens'))->toBeFalse();
});
