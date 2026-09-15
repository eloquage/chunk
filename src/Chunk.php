<?php

namespace Eloquage\Chunk;

use InvalidArgumentException;

/**
 * Primary entrypoint for eloquage/chunk.
 *
 * Pure-PHP implementation lives here. Optional TypePHP/native acceleration
 * can be added under native/ later without changing this public API.
 */
final class Chunk
{
    /**
     * @var callable(string): int|null
     */
    private $tokenCounter;

    public function __construct(?callable $tokenCounter = null)
    {
        $this->tokenCounter = $tokenCounter;
    }

    public function name(): string
    {
        return 'chunk';
    }

    /**
     * Split source text into retrieval-ready, source-backed records.
     *
     * @return list<array{text: string, index: int, start: int, end: int}>
     */
    public function split(string $text, array $options = []): array
    {
        $settings = $this->validateOptions($options);
        $source = $this->sourceMap($text);

        if ($source['length'] === 0) {
            return [];
        }

        if ($settings['strategy'] === 'window') {
            $spans = $this->windowSpans($text, $source, $settings);
        } else {
            $spans = $this->packSpans($text, $source, $settings);
        }

        $records = [];

        foreach ($spans as $span) {
            if ($span['end'] <= $span['start']) {
                continue;
            }

            $records[] = [
                'text' => $this->slice($text, $source, $span['start'], $span['end']),
                'index' => count($records),
                'start' => $span['start'],
                'end' => $span['end'],
            ];
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{strategy: string, format: string, unit: string, size: int, overlap: int}
     */
    private function validateOptions(array $options): array
    {
        $defaults = [
            'strategy' => 'window',
            'format' => 'plain',
            'unit' => 'characters',
            'size' => 1000,
            'overlap' => 0,
        ];
        $unknown = array_diff(array_keys($options), array_keys($defaults));

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown chunk option: '.(string) $unknown[0].'.');
        }

        $settings = array_merge($defaults, $options);

        foreach (['strategy', 'format', 'unit'] as $key) {
            if (! is_string($settings[$key])) {
                throw new InvalidArgumentException("Chunk {$key} must be a supported string value.");
            }
        }

        if (! in_array($settings['strategy'], ['window', 'pack'], true)) {
            throw new InvalidArgumentException('Chunk strategy must be window or pack.');
        }

        if (! in_array($settings['format'], ['plain', 'markdown'], true)) {
            throw new InvalidArgumentException('Chunk format must be plain or markdown.');
        }

        if (! in_array($settings['unit'], ['characters', 'tokens'], true)) {
            throw new InvalidArgumentException('Chunk unit must be characters or tokens.');
        }

        if (! is_int($settings['size']) || $settings['size'] < 1) {
            throw new InvalidArgumentException('Chunk size must be a positive integer.');
        }

        if (! is_int($settings['overlap']) || $settings['overlap'] < 0) {
            throw new InvalidArgumentException('Chunk overlap must be a non-negative integer.');
        }

        if ($settings['overlap'] >= $settings['size']) {
            throw new InvalidArgumentException('Chunk overlap must be smaller than size.');
        }

        if ($settings['unit'] === 'tokens' && $this->tokenCounter === null) {
            throw new InvalidArgumentException('Token measurement requires an injected token counter.');
        }

        return $settings;
    }

    /**
     * @return array{length: int, bytes: list<int>, lines: list<array{start: int, end: int}>}
     */
    private function sourceMap(string $text): array
    {
        if (@preg_match('//u', $text) !== 1) {
            throw new InvalidArgumentException('Chunk input must be valid UTF-8.');
        }

        $matches = [];
        preg_match_all('/./us', $text, $matches, PREG_OFFSET_CAPTURE);
        $bytes = [];

        foreach ($matches[0] as $match) {
            $bytes[] = $match[1];
        }

        $length = count($bytes);
        $bytes[] = strlen($text);

        $lines = [];
        $lineStart = 0;

        for ($index = 0; $index < $length; $index++) {
            if ($this->slice($text, ['bytes' => $bytes], $index, $index + 1) === "\n") {
                $lines[] = ['start' => $lineStart, 'end' => $index + 1];
                $lineStart = $index + 1;
            }
        }

        if ($lineStart < $length || $length === 0) {
            $lines[] = ['start' => $lineStart, 'end' => $length];
        }

        return ['length' => $length, 'bytes' => $bytes, 'lines' => $lines];
    }

    /**
     * @param  array{length: int, bytes: list<int>, lines: list<array{start: int, end: int}>}  $source
     * @param  array{unit: string, size: int, overlap: int}  $settings
     * @return list<array{start: int, end: int}>
     */
    private function windowSpans(string $text, array $source, array $settings): array
    {
        $spans = [];
        $cursor = 0;

        while ($cursor < $source['length']) {
            $end = $this->endWithinBudget($text, $source, $cursor, $source['length'], $settings['size'], $settings['unit']);

            if ($end <= $cursor) {
                $end = $cursor + 1;
            }

            $spans[] = ['start' => $cursor, 'end' => min($end, $source['length'])];

            if ($end >= $source['length']) {
                break;
            }

            $cursor = $this->endWithinBudget(
                $text,
                $source,
                $cursor,
                $source['length'],
                $settings['size'] - $settings['overlap'],
                $settings['unit'],
            );

            if ($cursor <= $spans[count($spans) - 1]['start']) {
                $cursor = $spans[count($spans) - 1]['start'] + 1;
            }
        }

        return $spans;
    }

    /**
     * @param  array{length: int, bytes: list<int>, lines: list<array{start: int, end: int}>}  $source
     * @param  array{format: string, unit: string, size: int, overlap: int}  $settings
     * @return list<array{start: int, end: int}>
     */
    private function packSpans(string $text, array $source, array $settings): array
    {
        $pieces = $this->structurePieces($text, $source, $settings['format'] === 'markdown');
        $units = [];

        foreach ($pieces as $piece) {
            if ($piece['atomic'] || $this->measureSpan($text, $source, $piece['start'], $piece['end'], $settings['unit']) <= $settings['size']) {
                $units[] = $piece;

                continue;
            }

            foreach ($this->splitOversizedPiece($text, $source, $piece, $settings) as $unit) {
                $units[] = $unit;
            }
        }

        $spans = [];
        $current = [];
        $previous = [];

        foreach ($units as $unit) {
            if ($unit['atomic'] && $this->measureSpan($text, $source, $unit['start'], $unit['end'], $settings['unit']) > $settings['size']) {
                if ($current !== []) {
                    $spans[] = $this->spanFromUnits($current);
                    $previous = $current;
                    $current = [];
                }

                $spans[] = ['start' => $unit['start'], 'end' => $unit['end']];
                $previous = [$unit];

                continue;
            }

            if ($current === []) {
                $current = array_merge(
                    $this->selectOverlap($text, $source, $previous, $unit, $settings),
                    [$unit],
                );

                continue;
            }

            $candidate = array_merge($current, [$unit]);

            if ($this->measureSpanFromUnits($text, $source, $candidate, $settings['unit']) <= $settings['size']) {
                $current = $candidate;

                continue;
            }

            $spans[] = $this->spanFromUnits($current);
            $previous = $current;
            $current = array_merge(
                $this->selectOverlap($text, $source, $previous, $unit, $settings),
                [$unit],
            );
        }

        if ($current !== []) {
            $spans[] = $this->spanFromUnits($current);
        }

        return $spans;
    }

    /**
     * @param  array{length: int, bytes: list<int>, lines: list<array{start: int, end: int}>}  $source
     * @return list<array{start: int, end: int, atomic: bool}>
     */
    private function structurePieces(string $text, array $source, bool $markdown): array
    {
        if (! $markdown) {
            return $this->paragraphPieces($text, $source, 0, count($source['lines']));
        }

        $pieces = [];
        $lines = $source['lines'];
        $line = 0;

        while ($line < count($lines)) {
            if ($this->isFenceOpening($this->lineText($text, $source, $lines[$line]))) {
                $close = $this->findFenceEnd($text, $source, $line);
                $pieces[] = [
                    'start' => $lines[$line]['start'],
                    'end' => $lines[$close]['end'],
                    'atomic' => true,
                ];
                $line = $close + 1;

                continue;
            }

            $segmentStart = $line;

            while ($line < count($lines) && ! $this->isFenceOpening($this->lineText($text, $source, $lines[$line]))) {
                $line++;
            }

            foreach ($this->markdownSegmentPieces($text, $source, $segmentStart, $line) as $piece) {
                $pieces[] = $piece;
            }
        }

        return $pieces;
    }

    /**
     * @param  array{length: int, bytes: list<int>, lines: list<array{start: int, end: int}>}  $source
     * @return list<array{start: int, end: int, atomic: bool}>
     */
    private function markdownSegmentPieces(string $text, array $source, int $startLine, int $endLine): array
    {
        $headings = [];

        for ($line = $startLine; $line < $endLine; $line++) {
            if ($this->isHeading($this->lineText($text, $source, $source['lines'][$line]))) {
                $headings[] = $line;
            }
        }

        if ($headings === []) {
            return $this->paragraphPieces($text, $source, $startLine, $endLine);
        }

        $pieces = [];
        $before = $startLine;

        foreach ($headings as $headingIndex => $heading) {
            if ($before < $heading) {
                foreach ($this->paragraphPieces($text, $source, $before, $heading) as $piece) {
                    $pieces[] = $piece;
                }
            }

            $nextHeading = $headings[$headingIndex + 1] ?? $endLine;

            foreach ($this->headingPieces($text, $source, $heading, $nextHeading) as $piece) {
                $pieces[] = $piece;
            }

            $before = $nextHeading;

            if ($before === $endLine) {
                break;
            }
        }

        return $pieces;
    }

    /**
     * @param  array{length: int, bytes: list<int>, lines: list<array{start: int, end: int}>}  $source
     * @return list<array{start: int, end: int, atomic: bool}>
     */
    private function headingPieces(string $text, array $source, int $headingLine, int $endLine): array
    {
        $lines = $source['lines'];
        $body = $headingLine + 1;

        while ($body < $endLine && $this->isBlankLine($this->lineText($text, $source, $lines[$body]))) {
            $body++;
        }

        if ($body === $endLine) {
            return [[
                'start' => $lines[$headingLine]['start'],
                'end' => $lines[$endLine - 1]['end'],
                'atomic' => false,
            ]];
        }

        $firstEnd = $this->paragraphEnd($text, $source, $body, $endLine);
        $pieces = [[
            'start' => $lines[$headingLine]['start'],
            'end' => $lines[$firstEnd - 1]['end'],
            'atomic' => false,
        ]];

        foreach ($this->paragraphPieces($text, $source, $firstEnd, $endLine) as $piece) {
            $pieces[] = $piece;
        }

        return $pieces;
    }

    /**
     * @param  array{length: int, bytes: list<int>, lines: list<array{start: int, end: int}>}  $source
     * @return list<array{start: int, end: int, atomic: bool}>
     */
    private function paragraphPieces(string $text, array $source, int $startLine, int $endLine): array
    {
        $pieces = [];
        $line = $startLine;
        $lines = $source['lines'];

        while ($line < $endLine) {
            $content = $line;

            while ($content < $endLine && $this->isBlankLine($this->lineText($text, $source, $lines[$content]))) {
                $content++;
            }

            if ($content === $endLine) {
                if ($line < $content) {
                    $pieces[] = [
                        'start' => $lines[$line]['start'],
                        'end' => $lines[$content - 1]['end'],
                        'atomic' => false,
                    ];
                }
                break;
            }

            $pieceEnd = $this->paragraphEnd($text, $source, $content, $endLine);
            $pieces[] = [
                'start' => $lines[$line]['start'],
                'end' => $lines[$pieceEnd - 1]['end'],
                'atomic' => false,
            ];
            $line = $pieceEnd;
        }

        return $pieces;
    }

    /**
     * Return the first line after a non-blank paragraph, including its blank separator.
     *
     * @param  array{length: int, bytes: list<int>, lines: list<array{start: int, end: int}>}  $source
     */
    private function paragraphEnd(string $text, array $source, int $startLine, int $endLine): int
    {
        $line = $startLine;

        while ($line < $endLine && ! $this->isBlankLine($this->lineText($text, $source, $source['lines'][$line]))) {
            $line++;
        }

        while ($line < $endLine && $this->isBlankLine($this->lineText($text, $source, $source['lines'][$line]))) {
            $line++;
        }

        return $line;
    }

    /**
     * @param  array{start: int, end: int, atomic: bool}  $piece
     * @param  array{unit: string, size: int, overlap: int}  $settings
     * @return list<array{start: int, end: int, atomic: bool}>
     */
    private function splitOversizedPiece(string $text, array $source, array $piece, array $settings): array
    {
        $lines = [];

        foreach ($source['lines'] as $line) {
            if ($line['start'] >= $piece['start'] && $line['end'] <= $piece['end']) {
                $lines[] = $line;
            }
        }

        $units = [];
        $currentStart = null;
        $currentEnd = null;

        foreach ($lines as $line) {
            $lineMeasure = $this->measureSpan($text, $source, $line['start'], $line['end'], $settings['unit']);

            if ($lineMeasure > $settings['size']) {
                if ($currentStart !== null) {
                    $units[] = ['start' => $currentStart, 'end' => $currentEnd, 'atomic' => false];
                    $currentStart = null;
                    $currentEnd = null;
                }

                $cursor = $line['start'];

                while ($cursor < $line['end']) {
                    $end = $this->endWithinBudget($text, $source, $cursor, $line['end'], $settings['size'], $settings['unit']);

                    if ($end <= $cursor) {
                        $end = $cursor + 1;
                    }

                    $units[] = ['start' => $cursor, 'end' => min($end, $line['end']), 'atomic' => false];
                    $cursor = $end;
                }

                continue;
            }

            if ($currentStart === null) {
                $currentStart = $line['start'];
                $currentEnd = $line['end'];

                continue;
            }

            if ($this->measureSpan($text, $source, $currentStart, $line['end'], $settings['unit']) <= $settings['size']) {
                $currentEnd = $line['end'];

                continue;
            }

            $units[] = ['start' => $currentStart, 'end' => $currentEnd, 'atomic' => false];
            $currentStart = $line['start'];
            $currentEnd = $line['end'];
        }

        if ($currentStart !== null) {
            $units[] = ['start' => $currentStart, 'end' => $currentEnd, 'atomic' => false];
        }

        return $units;
    }

    /**
     * @param  list<array{start: int, end: int, atomic: bool}>  $previous
     * @param  array{start: int, end: int, atomic: bool}  $next
     * @param  array{unit: string, size: int, overlap: int}  $settings
     * @return list<array{start: int, end: int, atomic: bool}>
     */
    private function selectOverlap(string $text, array $source, array $previous, array $next, array $settings): array
    {
        if ($settings['overlap'] === 0 || $previous === []) {
            return [];
        }

        $selected = [];

        for ($index = count($previous) - 1; $index >= 0; $index--) {
            $candidate = array_merge([$previous[$index]], $selected);
            $overlapMeasure = $this->measureSpanFromUnits($text, $source, $candidate, $settings['unit']);

            if ($overlapMeasure > $settings['overlap']) {
                break;
            }

            if ($this->measureSpanFromUnits($text, $source, array_merge($candidate, [$next]), $settings['unit']) > $settings['size']) {
                break;
            }

            $selected = $candidate;

            if ($overlapMeasure === $settings['overlap']) {
                break;
            }
        }

        return $selected;
    }

    /**
     * @param  list<array{start: int, end: int, atomic: bool}>  $spanUnits
     * @return array{start: int, end: int}
     */
    private function spanFromUnits(array $spanUnits): array
    {
        return [
            'start' => $spanUnits[0]['start'],
            'end' => $spanUnits[count($spanUnits) - 1]['end'],
        ];
    }

    /**
     * @param  list<array{start: int, end: int, atomic: bool}>  $units
     */
    private function measureSpanFromUnits(string $text, array $source, array $units, string $unit): int
    {
        return $this->measureSpan($text, $source, $units[0]['start'], $units[count($units) - 1]['end'], $unit);
    }

    /**
     * @param  array{length: int, bytes: list<int>, lines: list<array{start: int, end: int}>}  $source
     */
    private function endWithinBudget(string $text, array $source, int $start, int $limit, int $budget, string $unit): int
    {
        if ($unit === 'characters') {
            return min($start + $budget, $limit);
        }

        $best = $start;

        for ($end = $start + 1; $end <= $limit; $end++) {
            if ($this->measureSpan($text, $source, $start, $end, $unit) > $budget) {
                break;
            }

            $best = $end;
        }

        return $best;
    }

    /**
     * @param  array{length: int, bytes: list<int>, lines: list<array{start: int, end: int}>}  $source
     */
    private function measureSpan(string $text, array $source, int $start, int $end, string $unit): int
    {
        if ($unit === 'characters') {
            return $end - $start;
        }

        $count = ($this->tokenCounter)($this->slice($text, $source, $start, $end));

        if (! is_int($count) || $count < 0) {
            throw new InvalidArgumentException('The token counter must return a non-negative integer.');
        }

        return $count;
    }

    /**
     * @param  array{bytes: list<int>}  $source
     */
    private function slice(string $text, array $source, int $start, int $end): string
    {
        $startByte = $source['bytes'][$start];
        $endByte = $source['bytes'][$end];

        return substr($text, $startByte, $endByte - $startByte);
    }

    /**
     * @param  array{length: int, bytes: list<int>, lines: list<array{start: int, end: int}>}  $source
     * @param  array{start: int, end: int}  $line
     */
    private function lineText(string $text, array $source, array $line): string
    {
        return $this->slice($text, $source, $line['start'], $line['end']);
    }

    private function isBlankLine(string $line): bool
    {
        return trim($line, " \t\r\n") === '';
    }

    private function isHeading(string $line): bool
    {
        return preg_match('/^[ \t]{0,3}#{1,6}(?:[ \t]+|\r?\n|$)/', $line) === 1;
    }

    private function isFenceOpening(string $line): bool
    {
        return preg_match('/^[ \t]{0,3}(`{3,}|~{3,})(?:[^\r\n]*)?(?:\r?\n|$)/', $line) === 1;
    }

    /**
     * @param  array{length: int, bytes: list<int>, lines: list<array{start: int, end: int}>}  $source
     */
    private function findFenceEnd(string $text, array $source, int $openingLine): int
    {
        $opening = $this->lineText($text, $source, $source['lines'][$openingLine]);
        preg_match('/^[ \t]{0,3}(`{3,}|~{3,})/', $opening, $matches);
        $marker = $matches[1];
        $character = $marker[0];
        $length = strlen($marker);

        for ($line = $openingLine + 1; $line < count($source['lines']); $line++) {
            $value = $this->lineText($text, $source, $source['lines'][$line]);
            $withoutNewline = rtrim($value, "\r\n");

            if (preg_match('/^[ \t]{0,3}('.preg_quote($character, '/').'{'.$length.',})[ \t]*$/', $withoutNewline) === 1) {
                return $line;
            }
        }

        return count($source['lines']) - 1;
    }
}
