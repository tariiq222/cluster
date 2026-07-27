<?php

namespace Modules\Organization\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Organization\Infrastructure\Persistence\DatabaseGetDefaultClusterId;
use Tests\TestCase;

class DatabaseGetDefaultClusterIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_authoritative_singleton_cluster(): void
    {
        $clusterId = '018f6f7d-0c00-7000-8000-000000000601';
        DB::table('clusters')->insert([
            [
                'id' => '018f6f7d-0c00-7000-8000-000000000602',
                'singleton_key' => 2,
                'code' => 'AAA-NON-AUTHORITATIVE',
                'name_ar' => 'صف غير مرجعي',
                'name_en' => 'Non-authoritative row',
                'status' => 'active',
                'lock_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $clusterId,
                'singleton_key' => 1,
                'code' => 'THC3',
                'name_ar' => 'التجمع الصحي الثالث',
                'name_en' => 'Third Health Cluster',
                'status' => 'active',
                'lock_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertSame($clusterId, (new DatabaseGetDefaultClusterId)->resolve());
    }

    public function test_it_returns_null_when_the_clusters_table_is_empty(): void
    {
        $this->assertNull((new DatabaseGetDefaultClusterId)->resolve());
    }

    public function test_it_returns_null_when_the_clusters_table_does_not_exist(): void
    {
        Schema::drop('clusters');

        $this->assertNull((new DatabaseGetDefaultClusterId)->resolve());
    }
}
