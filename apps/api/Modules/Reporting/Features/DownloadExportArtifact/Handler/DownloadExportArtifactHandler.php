<?php

namespace Modules\Reporting\Features\DownloadExportArtifact\Handler;

use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\AccessProjection;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;

final class DownloadExportArtifactHandler
{
    public function __construct(private readonly DecideAccess $access) {}

    /**
     * @param  array{user_id?: string, facility_id?: string}  $actor
     * @return array{id: string, format: string, items: list<array<string, mixed>>, total: int}|null
     */
    public function handle(string $artifactId, array $actor): ?array
    {
        $artifact = DB::table('export_artifacts')->where('id', $artifactId)->first();
        if ($artifact === null || $artifact->status !== 'available' || ($artifact->expires_at !== null && now()->greaterThan($artifact->expires_at))) {
            return null;
        }

        $items = json_decode((string) $artifact->safe_result, true);
        if (! is_array($items)) {
            return null;
        }
        $allowed = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['source_type'] ?? null) || ! is_string($item['source_id'] ?? null)) {
                continue;
            }
            $decision = $this->access->decide(
                $actor,
                'reporting.download',
                new RecordFacts(
                    is_string($item['scope_id'] ?? null) ? $item['scope_id'] : null,
                    $item['source_type'],
                    is_string($item['classification'] ?? null) ? $item['classification'] : 'internal',
                ),
            );
            if (! $decision->isAllowed()) {
                continue;
            }

            unset($item['allowed_actions'], $item['field_access'], $item['decision_id']);
            $allowed[] = AccessProjection::fromDecision($decision)->compose($item);
        }

        return ['id' => $artifact->id, 'format' => $artifact->format, 'items' => $allowed, 'total' => count($allowed)];
    }
}
