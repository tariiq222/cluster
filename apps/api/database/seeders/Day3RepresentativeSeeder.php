<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class Day3RepresentativeSeeder extends Seeder
{
    public const DOCUMENT_ID = '019f7000-0000-7000-8000-000000000903';

    public const VERSION_ID = '019f7000-0000-7000-8000-000000000904';

    public function run(): void
    {
        $now = now();
        $storageId = '019f7000-0000-7000-8000-000000000905';
        $documentRowId = '019f7000-0000-7000-8000-000000000906';
        $versionRowId = '019f7000-0000-7000-8000-000000000907';
        DB::table('document_storage_objects')->insertOrIgnore([
            'id' => $storageId, 'disk' => 'documents-available', 'object_key' => 'fixtures/day3-evidence.pdf',
            'storage_class' => 'available', 'immutable' => true, 'immutable_since' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('documents')->insertOrIgnore([
            'id' => $documentRowId, 'public_id' => self::DOCUMENT_ID,
            'owner_organization_unit_id' => '018f6f7d-0c00-7000-8000-000000000011',
            'created_by_user_id' => '018f6f7d-0c00-7000-8000-000000000021', 'name' => 'دليل رحلة اليوم الثالث',
            'classification' => 'internal', 'status' => 'active', 'current_version_id' => $versionRowId,
            'legal_hold' => false, 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('document_versions')->insertOrIgnore([
            'id' => $versionRowId, 'public_id' => self::VERSION_ID, 'document_id' => $documentRowId,
            'storage_object_id' => $storageId, 'version_number' => 1, 'original_filename' => 'day3-evidence.pdf',
            'declared_mime_type' => 'application/pdf', 'detected_mime_type' => 'application/pdf', 'size_bytes' => 29,
            'sha256' => hash('sha256', 'day3-representative-evidence'), 'scan_status' => 'clean',
            'availability_status' => 'available', 'scan_engine_version' => 'fixture-after-real-clamav-gate',
            'scan_result' => json_encode(['result' => 'clean'], JSON_THROW_ON_ERROR), 'scanned_at' => $now,
            'available_at' => $now, 'created_by_user_id' => '018f6f7d-0c00-7000-8000-000000000021',
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
