<?php

namespace Modules\WorkDefinitions\Features\PublishRequestFixture\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\WorkDefinitions\Features\PublishRequestFixture\Handler\PublishRequestFixtureHandler;
use Tests\TestCase;

class PublishRequestFixtureTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_publishes_the_immutable_request_definition_with_only_title_and_description(): void
    {
        $fixture = (new PublishRequestFixtureHandler)->publish();

        $this->assertSame('request', $fixture['code']);
        $this->assertSame('published', $fixture['status']);
        $this->assertSame(1, $fixture['version']);
        $this->assertSame(['title', 'description'], array_keys($fixture['input_schema']['properties']));
        $this->assertSame(['title', 'description'], $fixture['input_schema']['required']);
        $this->assertSame($fixture['id'], DB::table('work_definition_development_work_type_versions')->value('id'));
    }

    public function test_republishing_returns_the_same_live_version_without_creating_a_conflict(): void
    {
        $handler = new PublishRequestFixtureHandler;

        $first = $handler->publish();
        $second = $handler->publish();

        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('work_definition_development_work_type_versions')->count());
    }
}
