<?php

namespace Modules\Authorization\Infrastructure\Simulation;

use Modules\Authorization\Contracts\AuthorizationResourceReference;
use Modules\Authorization\Contracts\AuthorizationSimulationFactsProvider;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Contracts\ResolveAuthorizationSimulationFacts;
use Throwable;

/**
 * Iterates AuthorizationSimulationFactsProvider implementations registered
 * under the 'authorization.simulation_facts' container tag and returns the
 * first non-null RecordFacts whose resourceType/recordId match the
 * reference. Tagged providers are supplied by each module's own
 * ServiceProvider, e.g.:
 *
 *     $this->app->tag(MyFactsProvider::class, 'authorization.simulation_facts');
 *     // or equivalently via the array form on bind:
 *     $this->app->bind(MyFactsProvider::class, fn () => new MyFactsProvider, 'authorization.simulation_facts');
 *
 * The AuthorizationServiceProvider wires this resolver into the
 * ResolveAuthorizationSimulationFacts contract so that DecideAccessController
 * receives every tagged provider at resolve-time; providers that throw are
 * swallowed so one misbehaving module cannot break the resolution chain.
 */
final class RegisteredAuthorizationSimulationFactsResolver implements ResolveAuthorizationSimulationFacts
{
    /** @param iterable<AuthorizationSimulationFactsProvider> $providers */
    public function __construct(private readonly iterable $providers = []) {}

    public function resolve(AuthorizationResourceReference $reference): ?RecordFacts
    {
        foreach ($this->providers as $provider) {
            try {
                if (! $provider->supports($reference)) {
                    continue;
                }
                $facts = $provider->resolve($reference);

                return $facts !== null
                    && hash_equals($reference->type, $facts->resourceType)
                    && is_string($facts->recordId)
                    && hash_equals($reference->id, $facts->recordId)
                    ? $facts
                    : null;
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
