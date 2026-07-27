<?php

namespace Modules\Organization\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Organization\Contracts\GetDefaultClusterId;

final class DatabaseGetDefaultClusterId implements GetDefaultClusterId
{
    public function resolve(): ?string
    {
        if (! Schema::hasTable('clusters')) {
            return null;
        }

        $clusterId = DB::table('clusters')->where('singleton_key', 1)->value('id');

        return is_string($clusterId) ? $clusterId : null;
    }
}
