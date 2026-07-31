<?php

declare(strict_types=1);

namespace Modules\Organization\Infrastructure\Import;

/**
 * Parses a UTF-8 CSV import source into the associative row payloads the
 * import templates validate. The first non-empty line names the columns;
 * every following line becomes one row. Empty optional cells are omitted so
 * optional fields (`end_at`, `name_en`, …) stay absent instead of failing
 * type checks, and the boolean `is_primary` column is coerced from its
 * CSV spelling (`true`/`1`/`yes`/`false`/`0`/`no`).
 */
final class CsvImportRowsParser
{
    /** @return list<array<string, mixed>> */
    public static function parse(string $content): array
    {
        $header = null;
        $rows = [];
        foreach (self::logicalLines($content) as $line) {
            if (trim($line) === '') {
                continue;
            }
            /** @var list<string|null> $cells */
            $cells = str_getcsv($line);
            if ($header === null) {
                $header = array_map(
                    static fn (?string $cell): string => trim((string) $cell),
                    $cells,
                );
                if (count(array_filter($header, static fn (string $cell): bool => $cell !== '')) === 0) {
                    $header = null;
                }

                continue;
            }
            $row = self::row($header, $cells);
            if ($row !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  list<string>  $header
     * @param  list<string|null>  $cells
     * @return array<string, mixed>
     */
    private static function row(array $header, array $cells): array
    {
        $row = [];
        foreach ($header as $index => $key) {
            if ($key === '') {
                continue;
            }
            $value = trim((string) ($cells[$index] ?? ''));
            if ($value === '') {
                continue;
            }
            $row[$key] = $key === 'is_primary' ? self::booleanValue($value) : $value;
        }

        return $row;
    }

    private static function booleanValue(string $value): bool|string
    {
        return match (strtolower($value)) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => $value,
        };
    }

    /**
     * Splits CSV content into logical records, rejoining lines inside a
     * quoted field so embedded newlines survive the parse.
     *
     * @return list<string>
     */
    private static function logicalLines(string $content): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        $physical = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $lines = [];
        $buffer = '';
        foreach ($physical as $line) {
            $buffer = $buffer === '' ? $line : $buffer."\n".$line;
            if (self::hasUnclosedQuote($buffer)) {
                continue;
            }
            $lines[] = $buffer;
            $buffer = '';
        }
        if ($buffer !== '') {
            $lines[] = $buffer;
        }

        return $lines;
    }

    private static function hasUnclosedQuote(string $line): bool
    {
        $inQuotes = false;
        $length = strlen($line);
        for ($index = 0; $index < $length; $index++) {
            if ($line[$index] !== '"') {
                continue;
            }
            if ($inQuotes && $index + 1 < $length && $line[$index + 1] === '"') {
                $index++;

                continue;
            }
            $inQuotes = ! $inQuotes;
        }

        return $inQuotes;
    }
}
