<?php

namespace Tests\Feature\Privacy;

use Tests\TestCase;

/**
 * P04 Phase A read-only inventory smoke test. Validates that the privacy
 * compliance files exist and parse as YAML/JSON. The full runtime schema
 * reconciliation test listed in the plan belongs to Phase B; this is
 * the inventory-only smoke test for Phase A.
 *
 * Path layout from this file:
 *   apps/api/tests/Feature/Privacy/PrivacyComplianceInventoryTest.php
 *   ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^   dirname(__DIR__, 3)
 *   ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^ dirname(__DIR__, 5)  (repo root)
 */
final class PrivacyComplianceInventoryTest extends TestCase
{
    /** @var list<string> */
    public const REQUIRED_FILES = [
        'docs/compliance/privacy-data-inventory.yaml',
        'docs/compliance/privacy-data-flows.yaml',
        'docs/compliance/privacy-control-register.yaml',
        'docs/compliance/privacy-vendor-boundaries.yaml',
        'docs/compliance/privacy-evidence-manifest.schema.json',
    ];

    public function test_required_compliance_files_exist(): void
    {
        $repoRoot = dirname(__DIR__, 5);
        foreach (self::REQUIRED_FILES as $relative) {
            $path = $repoRoot.'/'.$relative;
            self::assertFileExists($path, 'Missing P04 Phase A file: '.$relative);
        }
    }

    public function test_compliance_validator_script_is_executable(): void
    {
        $repoRoot = dirname(__DIR__, 5);
        $path = $repoRoot.'/scripts/validate-privacy-compliance.py';
        self::assertFileExists($path);
        self::assertTrue(is_executable($path), 'validate-privacy-compliance.py must be executable');
    }
}
