<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Export;

/**
 * Encodes the tabular reporting result as UTF-8 CSV with spreadsheet
 * formula-injection protection. Complex cells (data, field_access,
 * allowed_actions) are serialized as compact JSON strings; scalar cells
 * are emitted verbatim after escaping.
 */
final class CsvExportEncoder
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'source_type',
        'source_id',
        'title',
        'scope_id',
        'classification',
        'decision_id',
        'allowed_actions',
        'field_access',
        'data',
    ];

    /** @var list<string> */
    private const FORMULA_LEAD = ['=', '+', '-', '@'];

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public static function encode(array $items): string
    {
        $lines = [implode(',', self::COLUMNS)."\r\n"];
        foreach ($items as $item) {
            $cells = [];
            foreach (self::COLUMNS as $column) {
                $cells[] = self::escape(self::cellValue($item[$column] ?? null));
            }
            $lines[] = implode(',', $cells)."\r\n";
        }

        return implode('', $lines);
    }

    private static function cellValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function escape(string $value): string
    {
        if ($value !== '' && (in_array($value[0], self::FORMULA_LEAD, true) || $value[0] === "\t" || $value[0] === "\r")) {
            $value = "'".$value;
        }

        $needsQuoting = str_contains($value, ',')
            || str_contains($value, '"')
            || str_contains($value, "\n")
            || str_contains($value, "\r")
            || str_contains($value, "\t");
        if ($needsQuoting) {
            $value = '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
