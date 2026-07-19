<?php

namespace Modules\WorkDefinitions\Infrastructure;

use Illuminate\Support\Facades\DB;
use Modules\WorkDefinitions\Contracts\ResolvePublishedWorkDefinition;
use Modules\WorkDefinitions\Features\PublishRequestFixture\Handler\PublishRequestFixtureHandler;

final class ResolvePublishedWorkDefinitionFromPersistence implements ResolvePublishedWorkDefinition
{
    public function __construct(private readonly PublishRequestFixtureHandler $requestFixture) {}

    public function resolve(string $code): ?array
    {
        $definition = DB::table('work_definitions')->where('code', $code)->first();
        if ($definition !== null) {
            $version = DB::table('work_definition_versions')
                ->where('work_definition_id', $definition->id)
                ->where('status', 'published')
                ->orderByDesc('version_number')
                ->first();
            if ($version === null) {
                return null;
            }

            $schema = json_decode((string) $version->schema_document, true, 512, JSON_THROW_ON_ERROR);

            return [
                'version_id' => (string) $version->id,
                'code' => $code,
                'fields' => array_map('strval', array_keys($schema['properties'] ?? [])),
                'classification' => (string) ($definition->default_classification ?? 'internal'),
            ];
        }

        if ($code !== 'request') {
            return null;
        }

        $fixture = $this->requestFixture->publish();

        return [
            'version_id' => $fixture['id'],
            'code' => 'request',
            'fields' => array_map('strval', array_keys($fixture['input_schema']['properties'])),
            'classification' => 'internal',
        ];
    }
}
