<?php

namespace Modules\WorkDefinitions\Contracts;

interface ResolvePublishedRequestFixture
{
    /**
     * @return array{version_id: string, code: 'request', fields: array{0: 'title', 1: 'description'}}
     */
    public function resolve(): array;
}
