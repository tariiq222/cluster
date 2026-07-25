<?php

namespace Modules\Organization\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Modules\Organization\Contracts\GetDefaultClusterId;

final class DatabaseGetDefaultClusterId implements GetDefaultClusterId
{
    public function resolve(): ?string
    {
        $clusterId = DB::table('clusters')->orderBy('code')->value('id');

        return is_string($clusterId) ? $clusterId : null;
    }
}
