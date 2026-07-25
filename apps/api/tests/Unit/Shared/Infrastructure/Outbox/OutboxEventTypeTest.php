<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Outbox;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shared\Infrastructure\Outbox\OutboxEventType;

#[CoversClass(OutboxEventType::class)]
final class OutboxEventTypeTest extends TestCase
{
    public function test_every_case_value_is_unique(): void
    {
        $values = array_map(static fn (OutboxEventType $case): string => $case->value, OutboxEventType::cases());
        $this->assertSame(
            count($values),
            count(array_unique($values)),
            'OutboxEventType cases must have unique string values.',
        );
    }

    public function test_every_case_value_follows_the_cloudevents_reverse_dns_pattern(): void
    {
        $pattern = '/^com\.cluster\.[a-z][a-z0-9_-]*\.[a-z][a-z0-9_-]*\.v\d+$/';
        foreach (OutboxEventType::cases() as $case) {
            $this->assertMatchesRegularExpression(
                $pattern,
                $case->value,
                "OutboxEventType::{$case->name} value '{$case->value}' must look like com.cluster.<module>.<name>.v<integer>.",
            );
        }
    }

    public function test_every_case_resolves_a_schema_path_under_docs_contracts_schemas(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        foreach (OutboxEventType::cases() as $case) {
            $absolute = $repoRoot.'/'.$case->schemaPath();
            $this->assertStringStartsWith(
                'docs/contracts/schemas/',
                $case->schemaPath(),
                "OutboxEventType::{$case->name} schemaPath() must live under docs/contracts/schemas/.",
            );
            $this->assertStringEndsWith(
                '.schema.json',
                $case->schemaPath(),
                "OutboxEventType::{$case->name} schemaPath() must end with .schema.json.",
            );
        }
    }

    public function test_schema_path_filename_matches_the_event_type_value(): void
    {
        foreach (OutboxEventType::cases() as $case) {
            $expected = str_replace('.', '-', $case->value).'.schema.json';
            $this->assertStringEndsWith(
                $expected,
                $case->schemaPath(),
                "OutboxEventType::{$case->name} schemaPath() must encode the event-type value with dots as dashes.",
            );
        }
    }

    public function test_every_case_includes_a_module_namespace(): void
    {
        $exempt = [
            // No exempt cases: every event type in the catalogue must belong
            // to a single module so module ownership stays unambiguous.
        ];
        foreach (OutboxEventType::cases() as $case) {
            $this->assertNotContains(
                $case->name,
                $exempt,
                "OutboxEventType::{$case->name} must declare a module namespace (e.g. Organization*).",
            );
        }
    }
}
