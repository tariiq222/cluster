<?php

declare(strict_types=1);

namespace Modules\Organization\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Organization\Infrastructure\Persistence\ValidatePersonReferenceFromPersistence;
use Tests\TestCase;

final class ValidatePersonReferenceFromPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private const PERSON_ID = '018f6f7d-0c00-7000-8000-000000000c01';

    private const KNOWN_HASH = 'a7c4f9e2b1d8c6a3509f8e7d6c5b4a3f2e1d0c9b8a7968574635241302110098';

    private const OTHER_HASH = 'b1d8c6a3509f8e7d6c5b4a3f2e1d0c9b8a7968574635241302110098a7c4f9e2';

    private ValidatePersonReferenceFromPersistence $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = $this->app->make(ValidatePersonReferenceFromPersistence::class);

        // Seed the exact column shape CreatePersonController populates:
        // ciphertext columns + lookup hash are written as part of the people row.
        DB::table('people')->insert([
            'id' => self::PERSON_ID,
            'national_id_ciphertext' => null,
            'national_id_lookup_hash' => self::KNOWN_HASH,
            'employee_number' => 'PERS-001',
            'display_name_ar' => 'موظف التحقق',
            'display_name_en' => 'Validation Person',
            'primary_email_ciphertext' => null,
            'primary_phone_ciphertext' => null,
            'status' => 'active',
            'person_version' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_find_returns_the_persisted_reference_for_a_known_person(): void
    {
        $reference = $this->validator->find(self::PERSON_ID);

        $this->assertIsArray($reference);
        $this->assertSame(self::PERSON_ID, $reference['person_id']);
        $this->assertSame(7, $reference['person_version']);
        $this->assertSame('active', $reference['status']);
        $this->assertSame('موظف التحقق', $reference['display_name_ar']);
        $this->assertSame('Validation Person', $reference['display_name_en']);

        // The reference is the typed public projection; the ciphertext / lookup
        // hash columns must NEVER leak through the contract.
        $this->assertArrayNotHasKey('national_id_ciphertext', $reference);
        $this->assertArrayNotHasKey('national_id_lookup_hash', $reference);
        $this->assertArrayNotHasKey('employee_number', $reference);
    }

    public function test_find_returns_null_for_an_unknown_person_id(): void
    {
        $this->assertNull($this->validator->find('018f6f7d-0c00-7000-8000-0000ffffffff'));
    }

    public function test_validate_marks_the_reference_current_when_person_version_matches(): void
    {
        $result = $this->validator->validate(self::PERSON_ID, 7);

        $this->assertSame('current', $result['state']);
        $this->assertIsArray($result['reference']);
        $this->assertSame(self::PERSON_ID, $result['reference']['person_id']);
        $this->assertSame(7, $result['reference']['person_version']);
        $this->assertSame('active', $result['reference']['status']);
    }

    public function test_validate_marks_the_reference_stale_when_person_version_differs(): void
    {
        $result = $this->validator->validate(self::PERSON_ID, 9);

        $this->assertSame('stale', $result['state']);
        $this->assertIsArray($result['reference']);
        $this->assertSame(7, $result['reference']['person_version']);
    }

    public function test_validate_returns_missing_with_null_reference_for_an_unknown_person_id(): void
    {
        $result = $this->validator->validate('018f6f7d-0c00-7000-8000-0000ffffffff', 1);

        $this->assertSame('missing', $result['state']);
        $this->assertNull($result['reference']);
    }

    public function test_contract_observation_does_not_leak_or_compare_the_national_id_lookup_hash(): void
    {
        // The validator's `find` does not consult national_id_lookup_hash — it
        // returns the public reference for any person row whose id exists.
        // This test pins that behaviour so that if a future change starts
        // comparing the hash column (e.g. adding a hash-aware overload), the
        // contract surface must be widened explicitly and this assertion updated.
        $found = $this->validator->find(self::PERSON_ID);
        $this->assertIsArray($found);
        $this->assertSame(self::PERSON_ID, $found['person_id']);

        // Seeding a different person with a DIFFERENT hash must not change
        // what the validator returns for the ORIGINAL person id — the lookup
        // is keyed on person_id only.
        DB::table('people')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000c02',
            'national_id_ciphertext' => null,
            'national_id_lookup_hash' => self::OTHER_HASH,
            'employee_number' => 'PERS-002',
            'display_name_ar' => 'موظف آخر',
            'display_name_en' => null,
            'primary_email_ciphertext' => null,
            'primary_phone_ciphertext' => null,
            'status' => 'active',
            'person_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sameAsBefore = $this->validator->find(self::PERSON_ID);
        $this->assertSame($found, $sameAsBefore);
    }
}
