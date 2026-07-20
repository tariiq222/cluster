<?php

namespace Modules\Authorization\Contracts;

final readonly class AuthorizationResourceReference
{
    public function __construct(
        public string $type,
        public string $id,
    ) {}
}
