<?php

namespace Modules\Authorization\Infrastructure\Simulation;

use Modules\Authorization\Contracts\AuthorizationResourceReference;
use Modules\Authorization\Contracts\AuthorizationSimulationFactsProvider;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Contracts\ResolveAuthorizationSimulationFacts;
use Throwable;

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
