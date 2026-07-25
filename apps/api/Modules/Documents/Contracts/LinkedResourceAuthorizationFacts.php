<?php

namespace Modules\Documents\Contracts;

use Modules\Authorization\Contracts\RecordFacts;

/**
 * The producer module resolves its own facts.  Documents receives only the
 * typed contract and IDs; it never queries WorkRecords-owned persistence.
 */
interface LinkedResourceAuthorizationFacts
{
    public function resolve(DocumentSourceReference $reference): ?RecordFacts;
}
