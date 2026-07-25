<?php

namespace Modules\WorkDefinitions\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\WorkDefinitions\Contracts\ResolvePublishedRequestFixture;
use Modules\WorkDefinitions\Contracts\ResolvePublishedWorkDefinition;
use Modules\WorkDefinitions\Infrastructure\ResolvePublishedRequestFixtureFromPersistence;
use Modules\WorkDefinitions\Infrastructure\ResolvePublishedWorkDefinitionFromPersistence;

final class WorkDefinitionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ResolvePublishedRequestFixture::class, ResolvePublishedRequestFixtureFromPersistence::class);
        $this->app->bind(ResolvePublishedWorkDefinition::class, ResolvePublishedWorkDefinitionFromPersistence::class);
    }
}
