<?php

namespace Modules\Search\Features\IndexSourceEvent\Handler;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class IndexSourceEventHandler
{
    public const PROJECTION_VERSION = 'w1.9-v1';

    /**
     * Indexes only the explicitly safe projection fields. Raw payloads and
     * sensitive fields are intentionally ignored, making this read model
     * rebuildable without copying source data.
     *
     * @param  array<string, mixed>  $event
     * @return array{id: string, indexed: bool}
     */
    public function handle(array $event): array
    {
        foreach (['source_module', 'source_type', 'source_id', 'source_version'] as $key) {
            if (! isset($event[$key]) || ! is_string($event[$key]) || trim($event[$key]) === '') {
                throw new InvalidArgumentException("Missing {$key}.");
            }
        }

        $sourceType = trim($event['source_type']);
        $sourceId = trim($event['source_id']);
        $id = $this->deterministicUuid(implode('|', [$sourceType, $sourceId, self::PROJECTION_VERSION]));
        $now = now();
        $indexable = is_array($event['indexable'] ?? null) ? $event['indexable'] : [];
        $title = $this->safeString($indexable['title'] ?? null, 240);
        $excerpt = $this->safeString($indexable['excerpt'] ?? null, 500);
        $text = $this->safeString($indexable['text'] ?? trim(implode(' ', array_filter([$title, $excerpt]))), 4000);

        $row = [
            'id' => $id,
            'source_module' => trim($event['source_module']),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_version' => trim($event['source_version']),
            'status' => $this->safeString($event['status'] ?? null, 32),
            'projection_version' => self::PROJECTION_VERSION,
            'scope_id' => $this->safeString($event['scope_id'] ?? $event['facility_id'] ?? null, 64),
            'classification' => $this->safeString($event['classification'] ?? 'internal', 24) ?? 'internal',
            'visibility' => $this->safeString($event['visibility'] ?? 'eligible', 16) ?? 'eligible',
            'title' => $title,
            'excerpt' => $excerpt,
            'search_text' => $text,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('search_index_entries')->upsert(
            [$row],
            ['source_type', 'source_id', 'projection_version'],
            ['source_module', 'source_version', 'status', 'scope_id', 'classification', 'visibility', 'title', 'excerpt', 'search_text', 'updated_at'],
        );

        return ['id' => $id, 'indexed' => true];
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
