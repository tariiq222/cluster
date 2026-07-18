<?php

namespace Modules\Documents\Application;

use InvalidArgumentException;

final readonly class CleanSpreadsheetParseResult
{
    /** @param list<string> $headers @param list<array<string, scalar|null>> $rows */
    public function __construct(
        public string $sourceFilename,
        public string $format,
        public array $headers,
        public array $rows,
    ) {
        if (! in_array($this->format, ['csv', 'xlsx'], true)) {
            throw new InvalidArgumentException('Spreadsheet format is unsupported.');
        }
    }

    /** @return array{source_filename: string, format: string, headers: list<string>, rows: list<array<string, scalar|null>>} */
    public function toArray(): array
    {
        return [
            'source_filename' => $this->sourceFilename,
            'format' => $this->format,
            'headers' => $this->headers,
            'rows' => $this->rows,
        ];
    }
}
