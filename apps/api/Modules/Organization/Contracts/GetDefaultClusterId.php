<?php

namespace Modules\Organization\Contracts;

interface GetDefaultClusterId
{
    public function resolve(): ?string;
}
