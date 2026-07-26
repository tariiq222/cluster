<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('outbox_events')) {
            throw new RuntimeException('Shared outbox_events must exist before legacy outbox cutover.');
        }

        DB::transaction(function (): void {
            $hasDocuments = Schema::hasTable('document_outbox_events');
            $hasPlatformSettings = Schema::hasTable('platform_settings_outbox');
            if ($hasDocuments) {
                $this->copyDocuments();
                $this->verifyLegacyRows('document_outbox_events');
            }
            if ($hasPlatformSettings) {
                $this->copyPlatformSettings();
                $this->verifyLegacyRows('platform_settings_outbox');
            }

            if ($hasDocuments) {
                Schema::drop('document_outbox_events');
            }
            if ($hasPlatformSettings) {
                Schema::drop('platform_settings_outbox');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('outbox_events')) {
            throw new RuntimeException('Shared outbox_events is required to restore legacy module outboxes.');
        }

        $this->assertCanonicalRowsRestorable();

        DB::transaction(function (): void {
            if (! Schema::hasTable('document_outbox_events')) {
                $this->createDocumentOutbox();
                $this->restoreDocuments();
            }

            if (! Schema::hasTable('platform_settings_outbox')) {
                $this->createPlatformSettingsOutbox();
                $this->restorePlatformSettings();
            }
        });
    }

    private function copyDocuments(): void
    {
        foreach (DB::table('document_outbox_events')->orderBy('occurred_at')->orderBy('id')->get() as $row) {
            $payload = $this->decodeObject((string) $row->payload, 'document_outbox_events', (string) $row->id);
            $occurredAt = Carbon::parse((string) $row->occurred_at)->utc();
            $this->insertCanonical(
                (string) $row->id,
                (string) $row->aggregate_id,
                (string) $row->event_type,
                [
                    'specversion' => '1.0',
                    'id' => (string) $row->id,
                    'source' => '/'.(string) $row->event_type,
                    'type' => (string) $row->event_type,
                    'subject' => '/'.(string) $row->aggregate_id,
                    'time' => $occurredAt->format('Y-m-d\TH:i:s.v\Z'),
                    'datacontenttype' => 'application/json',
                    'data' => $payload,
                ],
                $occurredAt,
                $row->published_at,
                $row->created_at,
                $row->updated_at,
            );
        }
    }

    private function copyPlatformSettings(): void
    {
        foreach (DB::table('platform_settings_outbox')->orderBy('occurred_at')->orderBy('id')->get() as $row) {
            $payload = $this->decodeObject((string) $row->payload, 'platform_settings_outbox', (string) $row->id);
            $occurredAt = Carbon::parse((string) $row->occurred_at)->utc();
            $this->insertCanonical(
                (string) $row->id,
                (string) $row->aggregate_id,
                (string) $row->event_type,
                [
                    'specversion' => '1.0',
                    'id' => (string) $row->id,
                    'source' => '/platform-settings',
                    'type' => (string) $row->event_type,
                    'subject' => '/'.(string) $row->aggregate_type.'/'.(string) $row->aggregate_id,
                    'time' => $occurredAt->format('Y-m-d\TH:i:s.v\Z'),
                    'datacontenttype' => 'application/json',
                    'aggregatetype' => (string) $row->aggregate_type,
                    'data' => $payload,
                ],
                $occurredAt,
                $row->published_at,
                $row->created_at,
                $row->updated_at,
            );
        }
    }

    /** @param array<string, mixed> $cloudEvent */
    private function insertCanonical(
        string $eventId,
        string $aggregateId,
        string $eventType,
        array $cloudEvent,
        Carbon $occurredAt,
        mixed $publishedAt,
        mixed $createdAt,
        mixed $updatedAt,
    ): void {
        $existing = DB::table('outbox_events')->where('event_id', $eventId)->first();
        if ($existing !== null) {
            $existingCloudEvent = $this->decodeObject((string) $existing->cloud_event, 'outbox_events', $eventId);
            if ((string) $existing->aggregate_id !== $aggregateId
                || (string) $existing->event_type !== $eventType
                || $this->canonicalize($this->eventData($existingCloudEvent, 'outbox_events', $eventId)) !== $this->canonicalize($this->eventData($cloudEvent, 'new canonical event', $eventId))) {
                throw new RuntimeException("Legacy outbox event {$eventId} conflicts with the shared outbox row.");
            }

            return;
        }

        DB::table('outbox_events')->insert([
            'event_id' => $eventId,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'cloud_event' => json_encode($cloudEvent, JSON_THROW_ON_ERROR),
            'occurred_at' => $occurredAt,
            'published_at' => $publishedAt,
            'delivery_attempts' => 0,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]);
    }

    private function verifyLegacyRows(string $table): void
    {
        foreach (DB::table($table)->get(['id', 'aggregate_id', 'event_type', 'payload', 'occurred_at', 'published_at']) as $row) {
            $canonical = DB::table('outbox_events')->where('event_id', $row->id)->first();
            if ($canonical === null
                || (string) $canonical->aggregate_id !== (string) $row->aggregate_id
                || (string) $canonical->event_type !== (string) $row->event_type
                || $this->timestamp($canonical->occurred_at) !== $this->timestamp($row->occurred_at)
                || $this->nullableTimestamp($canonical->published_at) !== $this->nullableTimestamp($row->published_at)) {
                throw new RuntimeException("Legacy outbox event {$row->id} was not copied to the shared outbox.");
            }
            $cloudEvent = $this->decodeObject((string) $canonical->cloud_event, 'outbox_events', (string) $row->id);
            $payload = $this->decodeObject((string) $row->payload, $table, (string) $row->id);
            if ($this->canonicalize($this->eventData($cloudEvent, 'outbox_events', (string) $row->id)) !== $this->canonicalize($payload)) {
                throw new RuntimeException("Legacy outbox event {$row->id} payload changed during cutover.");
            }
        }
    }

    private function assertCanonicalRowsRestorable(): void
    {
        $rows = DB::table('outbox_events')
            ->where(function ($query): void {
                $query->where('event_type', 'like', 'com.cluster.documents.%')
                    ->orWhere('event_type', 'like', 'com.cluster.platform-settings.%')
                    ->orWhere('event_type', 'like', 'com.cluster.platform-operations.%')
                    ->orWhere('event_type', 'like', 'com.cluster.platform.%');
            })
            ->get();

        foreach ($rows as $row) {
            $eventId = (string) $row->event_id;
            $cloudEvent = $this->decodeObject((string) $row->cloud_event, 'outbox_events', $eventId);
            $this->eventData($cloudEvent, 'outbox_events', $eventId);
        }
    }

    private function restoreDocuments(): void
    {
        foreach (DB::table('outbox_events')->where('event_type', 'like', 'com.cluster.documents.%')->get() as $row) {
            $cloudEvent = $this->decodeObject((string) $row->cloud_event, 'outbox_events', (string) $row->event_id);
            DB::table('document_outbox_events')->insertOrIgnore([
                'id' => $row->event_id,
                'aggregate_id' => $row->aggregate_id,
                'event_type' => $row->event_type,
                'payload' => json_encode($this->eventData($cloudEvent, 'outbox_events', (string) $row->event_id), JSON_THROW_ON_ERROR),
                'occurred_at' => $row->occurred_at,
                'published_at' => $row->published_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }
    }

    private function restorePlatformSettings(): void
    {
        $rows = DB::table('outbox_events')
            ->where(function ($query): void {
                $query->where('event_type', 'like', 'com.cluster.platform-settings.%')
                    ->orWhere('event_type', 'like', 'com.cluster.platform-operations.%')
                    ->orWhere('event_type', 'like', 'com.cluster.platform.%');
            })
            ->get();
        foreach ($rows as $row) {
            $cloudEvent = $this->decodeObject((string) $row->cloud_event, 'outbox_events', (string) $row->event_id);
            DB::table('platform_settings_outbox')->insertOrIgnore([
                'id' => $row->event_id,
                'event_type' => $row->event_type,
                'aggregate_type' => is_string($cloudEvent['aggregatetype'] ?? null) ? $cloudEvent['aggregatetype'] : 'platform_setting',
                'aggregate_id' => $row->aggregate_id,
                'payload' => json_encode($this->eventData($cloudEvent, 'outbox_events', (string) $row->event_id), JSON_THROW_ON_ERROR),
                'occurred_at' => $row->occurred_at,
                'published_at' => $row->published_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }
    }

    private function createDocumentOutbox(): void
    {
        Schema::create('document_outbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('aggregate_id')->index();
            $table->string('event_type', 128)->index();
            $table->json('payload');
            $table->dateTime('occurred_at', 3);
            $table->dateTime('published_at', 3)->nullable();
            $table->timestamps();
            $table->index(['published_at', 'occurred_at']);
        });
    }

    private function createPlatformSettingsOutbox(): void
    {
        Schema::create('platform_settings_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type', 128);
            $table->string('aggregate_type', 96);
            $table->uuid('aggregate_id');
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['published_at', 'occurred_at']);
        });
    }

    /** @return array<string, mixed> */
    private function decodeObject(string $json, string $table, string $eventId): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException("{$table} event {$eventId} payload must be a JSON object.");
        }

        return $decoded;
    }

    /** @param array<string, mixed> $cloudEvent @return array<string, mixed> */
    private function eventData(array $cloudEvent, string $table, string $eventId): array
    {
        $data = $cloudEvent['data'] ?? null;
        if (! is_array($data) || array_is_list($data)) {
            throw new RuntimeException("{$table} event {$eventId} data must be a JSON object.");
        }

        return $data;
    }

    private function timestamp(mixed $value): string
    {
        return Carbon::parse((string) $value)->utc()->format('Y-m-d H:i:s');
    }

    private function nullableTimestamp(mixed $value): ?string
    {
        return $value === null ? null : $this->timestamp($value);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
};
