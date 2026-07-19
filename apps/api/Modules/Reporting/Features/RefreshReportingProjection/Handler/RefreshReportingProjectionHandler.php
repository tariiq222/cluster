<?php

namespace Modules\Reporting\Features\RefreshReportingProjection\Handler;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RefreshReportingProjectionHandler
{
    public const PROJECTION_VERSION = 'w1.9-v1';

    /**
     * @param  array<string, mixed>  $event
     * @return array{id: string, refreshed: bool}
     */
    public function handle(array $event): array
    {
        foreach (['report_id', 'source_module', 'source_type', 'source_id', 'source_version'] as $key) {
            if (! isset($event[$key]) || ! is_string($event[$key]) || trim($event[$key]) === '') {
                throw new InvalidArgumentException("Missing {$key}.");
            }
        }

        $sourceType = trim($event['source_type']);
        $sourceId = trim($event['source_id']);
        $id = $this->deterministicUuid(implode('|', [trim($event['report_id']), $sourceType, $sourceId, self::PROJECTION_VERSION]));
        $safeData = is_array($event['safe_data'] ?? null) ? $this->safeData($event['safe_data']) : [];
        $now = now();

        DB::table('report_read_models')->upsert([[
            'id' => $id,
            'report_id' => trim($event['report_id']),
            'source_module' => trim($event['source_module']),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_version' => trim($event['source_version']),
            'scope_id' => $this->safeString($event['scope_id'] ?? $event['facility_id'] ?? null, 64),
            'classification' => $this->safeString($event['classification'] ?? 'internal', 24) ?? 'internal',
            'projection_version' => self::PROJECTION_VERSION,
            'title' => $this->safeString($event['title'] ?? null, 240),
            'safe_data' => json_encode($safeData, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['report_id', 'source_type', 'source_id'], [
            'source_module', 'source_version', 'scope_id', 'classification', 'projection_version', 'title', 'safe_data', 'updated_at',
        ]);

        return ['id' => $id, 'refreshed' => true];
    }

    /** @param array<string, mixed> $data @return array<string, scalar|null> */
    private function safeData(array $data): array
    {
        $safe = [];
        foreach ($data as $key => $value) {
            if (preg_match('/(?:raw|payload|secret|password|token|sensitive|hidden)/i', $key) === 1) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        ksort($safe);

        return $safe;
    }

    private function safeString(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function deterministicUuid(string $value): string
    {
        $hex = sha1($value);
        $hex[12] = '5';
        $hex[16] = in_array($hex[16], ['8', '9', 'a', 'b'], true) ? $hex[16] : '8';

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
