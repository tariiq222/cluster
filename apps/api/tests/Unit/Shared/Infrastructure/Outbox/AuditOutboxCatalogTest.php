<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Outbox;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shared\Infrastructure\Outbox\OutboxEventType;

/**
 * Narrowly scoped assertions that complement the architecture test in
 * {@see Tests\Architecture\ModuleBoundariesTest::test_every_event_type_in_outbox_has_a_matching_json_schema()}.
 *
 * The architecture test only confirms a schema file exists, is valid JSON,
 * and exposes a top-level `data` object. It does NOT verify that the
 * schema declares the exact payload keys emitted by the producer event
 * classes, or that UUIDv7 / UTC-Z / safe-enum / range constraints are
 * actually applied. This test owns that contract for the three frozen
 * M01 Audit event types so a producer drift or a permissive schema
 * edit cannot silently desynchronise producer and consumer.
 */
#[CoversClass(OutboxEventType::class)]
final class AuditOutboxCatalogTest extends TestCase
{
    private const UUID_V7 = '^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$';

    private const UTC_MS_Z = '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\\.[0-9]{3}Z$';

    private const STREAM_KEY = '^[a-z][a-z0-9_-]*:[a-z][a-z0-9_-]*:(?:[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}|global)$';

    /**
     * @return iterable<string, array{OutboxEventType, list<string>}>
     */
    public static function auditCatalogProvider(): iterable
    {
        yield 'auditeventrecorded' => [
            OutboxEventType::AuditEventRecorded,
            [
                'event_id',
                'source_module',
                'action',
                'actor_type',
                'actor_id',
                'original_actor_id',
                'subject_type',
                'subject_id',
                'correlation_id',
                'outcome',
                'classification',
                'retention_class',
                'stream_key',
                'stream_sequence',
                'occurred_at',
                'recorded_at',
            ],
        ];

        yield 'auditexportcompleted' => [
            OutboxEventType::AuditExportCompleted,
            [
                'event_id',
                'export_id',
                'principal_id',
                'facility_id',
                'format',
                'event_count',
                'correlation_id',
                'completed_at',
            ],
        ];

        yield 'auditintegrityviolationdetected' => [
            OutboxEventType::AuditIntegrityViolationDetected,
            [
                'event_id',
                'verification_id',
                'stream_key',
                'correlation_id',
                'first_mismatch_stream_sequence',
                'verified_event_count',
                'integrity_status',
                'detected_at',
            ],
        ];
    }

    /**
     * The schema file resolved by `schemaPath()` MUST exist at the
     * declared slug, parse as valid JSON, expose the documented
     * `$schema` and `$id` drafts, and be a `type:object` envelope with
     * a nested `data` object. This is the file-existence half of the
     * catalog contract.
     */
    public function test_audit_case_schema_path_resolves_to_existing_json_file(): void
    {
        foreach (self::auditCatalogProvider() as $label => [$case, $_required]) {
            $path = $case->schemaPath();
            $absolute = dirname(__DIR__, 7).'/'.$path;

            self::assertFileExists($absolute, "{$label}: schema file {$path} is missing.");
            $raw = file_get_contents($absolute);
            self::assertIsString($raw, "{$label}: could not read {$path}.");

            $schema = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            self::assertIsArray($schema, "{$label}: {$path} is not valid JSON.");
            self::assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema'] ?? null, "{$label}: missing or wrong \$schema.");
            self::assertStringEndsWith(basename($path), (string) ($schema['$id'] ?? ''), "{$label}: \$id must mirror the file path.");
            self::assertSame('object', $schema['type'] ?? null, "{$label}: top level must be type:object.");
            self::assertSame(['data'], $schema['required'] ?? null, "{$label}: top level must require exactly one key, `data`.");

            $data = $schema['properties']['data'] ?? null;
            self::assertIsArray($data, "{$label}: missing top-level `data` schema.");
            self::assertSame('object', $data['type'] ?? null, "{$label}: data must be type:object.");
            self::assertFalse($data['additionalProperties'] ?? true, "{$label}: data.additionalProperties must be false to forbid forbidden keys.");
        }
    }

    /**
     * The schema MUST `require` exactly the keys emitted by the producer
     * payload() — neither fewer (which would let consumers drift on
     * missing fields) nor more (which would silently widen the contract
     * and admit fields the §7 audit-module plan forbids).
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('auditCatalogProvider')]
    public function test_audit_case_schema_requires_exact_payload_keys(OutboxEventType $case, array $expectedRequired): void
    {
        $schema = $this->loadDataSchema($case);

        sort($expectedRequired);
        $actualRequired = $schema['required'];
        sort($actualRequired);

        self::assertSame(
            $expectedRequired,
            $actualRequired,
            sprintf(
                'OutboxEventType::%s schema required keys must mirror %s::payload() (additionalProperties is already false).',
                $case->name,
                $case->value,
            ),
        );
    }

    /**
     * UUIDv7 / UTC-Z / safe-enum / range constraints MUST be applied
     * to every field that the producer enforces in the constructor —
     * otherwise the schema lies about what consumers may rely on.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('auditCatalogProvider')]
    public function test_audit_case_schema_enforces_strict_constraints(OutboxEventType $case, array $required): void
    {
        $data = $this->loadDataSchema($case);

        foreach ($required as $key) {
            self::assertArrayHasKey($key, $data['properties'] ?? [], "{$case->name}: required key {$key} must be declared in properties.");
        }

        // Every payload carries a UUIDv7 event_id and correlation_id.
        foreach (['event_id', 'correlation_id'] as $uuidField) {
            self::assertSame(self::UUID_V7, $this->propertyPattern($data, $uuidField), "{$case->name}: {$uuidField} must enforce the UUIDv7 pattern.");
        }

        // Every payload carries a UTC `*_at` timestamp in Y-m-d\TH:i:s.v\Z form.
        $timestampField = match ($case) {
            OutboxEventType::AuditEventRecorded => 'occurred_at',
            OutboxEventType::AuditExportCompleted => 'completed_at',
            OutboxEventType::AuditIntegrityViolationDetected => 'detected_at',
            default => throw new \LogicException("{$case->name} is not an Audit event type."),
        };
        self::assertSame(self::UTC_MS_Z, $this->propertyPattern($data, $timestampField), "{$case->name}: {$timestampField} must enforce the UTC millisecond-Z pattern.");

        // Per-event safe enums / ranges.
        if ($case === OutboxEventType::AuditEventRecorded) {
            self::assertSame(['user', 'service', 'system'], $data['properties']['actor_type']['enum']);
            self::assertSame(['succeeded', 'denied', 'failed'], $data['properties']['outcome']['enum']);
            self::assertSame(['public', 'internal', 'confidential', 'top_secret'], $data['properties']['classification']['enum']);
            self::assertSame(['standard', 'security', 'regulated'], $data['properties']['retention_class']['enum']);
            self::assertSame(1, $data['properties']['stream_sequence']['minimum']);
            self::assertContains('null', $data['properties']['actor_id']['type'], 'actor_id must allow null.');
            self::assertContains('null', $data['properties']['original_actor_id']['type'], 'original_actor_id must allow null.');
            self::assertContains('null', $data['properties']['subject_id']['type'], 'subject_id must allow null.');
        }

        if ($case === OutboxEventType::AuditExportCompleted) {
            self::assertSame(['csv', 'ndjson'], $data['properties']['format']['enum']);
            self::assertSame(0, $data['properties']['event_count']['minimum']);
            self::assertContains('null', $data['properties']['facility_id']['type'], 'facility_id must allow null.');
            self::assertSame(self::UUID_V7, $this->propertyPattern($data, 'export_id'));
            self::assertSame(self::UUID_V7, $this->propertyPattern($data, 'principal_id'));
        }

        if ($case === OutboxEventType::AuditIntegrityViolationDetected) {
            self::assertSame(['violated'], $data['properties']['integrity_status']['enum']);
            self::assertSame(1, $data['properties']['first_mismatch_stream_sequence']['minimum']);
            self::assertSame(0, $data['properties']['verified_event_count']['minimum']);
            self::assertSame(self::UUID_V7, $this->propertyPattern($data, 'verification_id'));
            self::assertSame(self::STREAM_KEY, $this->propertyPattern($data, 'stream_key'));
        }
    }

    /**
     * The schema MUST NOT admit any key the §7 audit-module plan
     * forbids: no `context`, no export bytes, no download outcome, no
     * hash material, no key version. `additionalProperties: false` plus
     * an exact `required` array is what enforces that, and this test
     * fails fast if the schema admits extra keys or widens the contract.
     */
    public function test_audit_event_schemas_forbid_section7_sensitive_fields(): void
    {
        $forbidden = ['context', 'export_bytes', 'download_outcome', 'event_hash', 'previous_hash', 'integrity_key_version'];

        foreach (self::auditCatalogProvider() as $label => [$case, $_required]) {
            $data = $this->loadDataSchema($case);
            $properties = array_keys($data['properties']);

            foreach ($forbidden as $field) {
                self::assertNotContains(
                    $field,
                    $properties,
                    "{$label} ({$case->value}): schema must not declare `{$field}` (forbidden by the §7 Audit contract).",
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadDataSchema(OutboxEventType $case): array
    {
        $absolute = dirname(__DIR__, 7).'/'.$case->schemaPath();
        self::assertFileExists($absolute, "{$case->name}: schema file {$case->schemaPath()} is missing.");

        $schema = json_decode((string) file_get_contents($absolute), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($schema, "{$case->name}: {$case->schemaPath()} is not valid JSON.");

        $data = $schema['properties']['data'] ?? null;
        self::assertIsArray($data, "{$case->name}: missing `data` schema object.");

        return $data;
    }

    private function propertyPattern(array $data, string $key): string
    {
        self::assertArrayHasKey($key, $data['properties'] ?? [], "data.properties must declare `{$key}`.");
        self::assertArrayHasKey('pattern', $data['properties'][$key], "data.properties.{$key} must declare a JSON-Schema `pattern`.");

        return $data['properties'][$key]['pattern'];
    }
}
