<?php

namespace Modules\WorkDefinitions\Infrastructure;

use Modules\WorkDefinitions\Contracts\ResolvePublishedRequestFixture;
use Modules\WorkDefinitions\Features\PublishRequestFixture\Handler\PublishRequestFixtureHandler;

final class ResolvePublishedRequestFixtureFromPersistence implements ResolvePublishedRequestFixture
{
    public function __construct(
        private readonly PublishRequestFixtureHandler $publisher,
    ) {}

    /**
     * @return array{version_id: string, code: 'request', fields: array{0: 'title', 1: 'description'}}
     */
    public function resolve(): array
    {
        $fixture = $this->publisher->publish();

        return [
            'version_id' => $fixture['id'],
            'code' => 'request',
            'fields' => array_keys($fixture['input_schema']['properties']),
        ];
    }
}
