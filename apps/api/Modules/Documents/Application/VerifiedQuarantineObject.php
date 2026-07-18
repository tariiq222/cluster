<?php

namespace Modules\Documents\Application;

final readonly class VerifiedQuarantineObject
{
    public function __construct(
        public QuarantineObjectReference $reference,
        public StoredObjectProperties $properties,
    ) {}
}
