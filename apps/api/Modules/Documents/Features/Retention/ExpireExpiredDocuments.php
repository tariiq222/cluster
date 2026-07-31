<?php

declare(strict_types=1);

namespace Modules\Documents\Features\Retention;

use Illuminate\Support\Facades\DB;
use Modules\Documents\Domain\DocumentStatus;
use Modules\Documents\Domain\UuidV7;
use Shared\Contracts\TransactionalOutbox;

/**
 * Bounded retention expiry. Marks documents whose retention period has
 * elapsed as archived; a legal hold always prevents expiry. One bounded
 * batch per invocation (see the `documents:expire-retention --once`
 * console wiring).
 */
final class ExpireExpiredDocuments
{
    public const MAX_BATCH_SIZE = 100;

    public function __construct(
        private readonly TransactionalOutbox $outbox,
    ) {}

    public function expireOnce(int $limit = 100): int
    {
        $limit = max(1, min($limit, self::MAX_BATCH_SIZE));
        $now = now();
        $boundary = $now->format('Y-m-d H:i:s.u');

        $expired = DB::table('documents')
            ->whereNotNull('retention_until')
            ->where('retention_until', '<=', $boundary)
            ->where('legal_hold', false)
            ->where('status', '!=', DocumentStatus::Archived->value)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->all();

        $expiredCount = 0;
        foreach ($expired as $document) {
            $updated = DB::table('documents')
                ->where('id', $document->id)
                ->where('lock_version', (int) $document->lock_version)
                ->update([
                    'status' => DocumentStatus::Archived->value,
                    'lock_version' => (int) $document->lock_version + 1,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                continue;
            }
            $this->outbox->append(UuidV7::generate(), (string) $document->id, 'com.cluster.documents.lifecycletransitioned.v1', [
                'document_id' => $document->public_id,
                'action' => 'expire',
                'lock_version' => (int) $document->lock_version + 1,
                'correlation_id' => null,
                'actor_user_id' => null,
            ]);
            $expiredCount++;
        }

        return $expiredCount;
    }
}
