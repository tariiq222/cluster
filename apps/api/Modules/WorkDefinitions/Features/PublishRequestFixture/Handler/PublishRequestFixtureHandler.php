<?php

namespace Modules\WorkDefinitions\Features\PublishRequestFixture\Handler;

use Illuminate\Support\Facades\DB;
use LogicException;

final class PublishRequestFixtureHandler
{
    private const VERSION_ID = '0197f0e0-0000-7000-8000-000000000001';

    /** @var array{type: string, additionalProperties: bool, required: list<string>, properties: array<string, array{type: string, minLength: int}>} */
    private const INPUT_SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['title', 'description'],
        'properties' => [
            'title' => ['type' => 'string', 'minLength' => 1],
            'description' => ['type' => 'string', 'minLength' => 1],
        ],
    ];

    /**
     * @return array{id: string, code: string, version: int, status: string, input_schema: array{type: string, additionalProperties: bool, required: list<string>, properties: array<string, array{type: string, minLength: int}>}}
     */
    public function publish(): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Development work type fixtures are unavailable outside local and testing environments.');
        }

        return DB::transaction(function (): array {
            $fixture = $this->fixture();
            $existing = DB::table('work_definition_development_work_type_versions')
                ->where('code', $fixture['code'])
                ->first();

            if ($existing === null) {
                DB::table('work_definition_development_work_type_versions')->insert([
                    ...$fixture,
                    'input_schema' => json_encode($fixture['input_schema'], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $fixture;
        });
    }

    /**
     * @return array{id: string, code: string, version: int, status: string, input_schema: array{type: string, additionalProperties: bool, required: list<string>, properties: array<string, array{type: string, minLength: int}>}}
     */
    private function fixture(): array
    {
        return [
            'id' => self::VERSION_ID,
            'code' => 'request',
            'version' => 1,
            'status' => 'published',
            'input_schema' => self::INPUT_SCHEMA,
        ];
    }
}
