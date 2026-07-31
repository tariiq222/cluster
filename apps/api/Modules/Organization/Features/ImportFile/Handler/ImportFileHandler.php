<?php

declare(strict_types=1);

namespace Modules\Organization\Features\ImportFile\Handler;

use DomainException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Organization\Infrastructure\Import\CsvImportRowsParser;

/**
 * Writes a validated CSV import source into the Organization quarantine disk
 * as the `{quarantineObjectId}.json` document `{source_filename, rows}` that
 * {@see \Modules\Organization\Infrastructure\Import\StorageQuarantinedImport}
 * resolves when a submitted import job is validated.
 */
final class ImportFileHandler
{
    private const MAX_ROWS = 1000;

    public function __construct(private readonly ?Filesystem $disk = null) {}

    /**
     * @return array{quarantine_object_id: string, source_filename: string, row_count: int}
     */
    public function store(UploadedFile $file): array
    {
        $content = $file->get();
        if (! is_string($content) || trim($content) === '') {
            throw new DomainException('import_file_empty');
        }
        $rows = CsvImportRowsParser::parse($content);
        if ($rows === []) {
            throw new DomainException('import_file_empty');
        }
        if (count($rows) > self::MAX_ROWS) {
            throw new DomainException('import_rows_too_many');
        }
        $objectId = Str::uuid7()->toString();
        $disk = $this->disk ?? Storage::disk((string) config('organization.import.quarantine_disk', 'organization-quarantine'));
        $disk->put($objectId.'.json', json_encode([
            'source_filename' => $file->getClientOriginalName(),
            'rows' => $rows,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'quarantine_object_id' => $objectId,
            'source_filename' => $file->getClientOriginalName(),
            'row_count' => count($rows),
        ];
    }
}
