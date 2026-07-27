<?php

namespace Modules\Authorization\Tests;

use Modules\Authorization\Contracts\AuthorizationResourceReference;
use Modules\Authorization\Contracts\AuthorizationSimulationFactsProvider;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Contracts\ResolveAuthorizationSimulationFacts;
use Modules\Authorization\Infrastructure\Simulation\RegisteredAuthorizationSimulationFactsResolver;
use Modules\Authorization\Providers\AuthorizationServiceProvider;
use Tests\TestCase;

/**
 * Verifies the container wiring introduced after audit finding #1:
 * AuthorizationServiceProvider::register() must bind
 * ResolveAuthorizationSimulationFacts to RegisteredAuthorizationSimulationFactsResolver
 * populated from the 'authorization.simulation_facts' container tag, so a
 * module that tags its provider is picked up and an untagged provider is not.
 */
final class SimulationFactsContainerWiringTest extends TestCase
{
    /**
     * The Laravel container resolves tagged bindings lazily, so we have to
     * forget any cached ResolveAuthorizationSimulationFacts instance before
     * tagging our fakes; this mirrors the rebind pattern already used by
     * AuthorizationHttpAdapterTest.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->forgetInstance(ResolveAuthorizationSimulationFacts::class);
    }

    public function test_tagged_provider_is_resolved_by_the_bound_resolver(): void
    {
        $referenceId = '018f6f7d-0c00-7000-8000-00000000af01';
        $reference = new AuthorizationResourceReference('work_record', $referenceId);

        $this->app->bind(TaggedFactsProvider::class, fn () => new TaggedFactsProvider);
        $this->app->tag(TaggedFactsProvider::class, 'authorization.simulation_facts');

        $this->app->forgetInstance(ResolveAuthorizationSimulationFacts::class);

        /** @var ResolveAuthorizationSimulationFacts $resolver */
        $resolver = $this->app->make(ResolveAuthorizationSimulationFacts::class);

        $this->assertInstanceOf(RegisteredAuthorizationSimulationFactsResolver::class, $resolver);

        $facts = $resolver->resolve($reference);

        $this->assertNotNull($facts, 'Tagged provider must be reached by the wired resolver.');
        $this->assertSame('work_record', $facts->resourceType);
        $this->assertSame($referenceId, $facts->recordId);
        $this->assertSame('tagged-classification', $facts->classification);
        $this->assertSame('work_record', TaggedFactsProvider::$lastReferenceType, 'Tagged provider resolve() must have been called with the reference.');
    }

    public function test_untagged_provider_is_not_resolved_by_the_bound_resolver(): void
    {
        $referenceId = '018f6f7d-0c00-7000-8000-00000000af02';
        $reference = new AuthorizationResourceReference('work_record', $referenceId);

        $this->app->bind(UntaggedFactsProvider::class, fn () => new UntaggedFactsProvider);

        $this->app->forgetInstance(ResolveAuthorizationSimulationFacts::class);

        /** @var ResolveAuthorizationSimulationFacts $resolver */
        $resolver = $this->app->make(ResolveAuthorizationSimulationFacts::class);

        $this->assertInstanceOf(RegisteredAuthorizationSimulationFactsResolver::class, $resolver);
        $this->assertNull($resolver->resolve($reference), 'Untagged provider must NOT be reached by the wired resolver.');
        $this->assertNull(UntaggedFactsProvider::$lastReferenceType, 'Untagged provider must not have been called at all.');
    }

    public function test_service_provider_is_the_source_of_truth_for_the_binding(): void
    {
        $this->app->forgetInstance(ResolveAuthorizationSimulationFacts::class);
        (new AuthorizationServiceProvider($this->app))->register();
        $this->app->forgetInstance(ResolveAuthorizationSimulationFacts::class);

        $reference = new AuthorizationResourceReference('work_record', '018f6f7d-0c00-7000-8000-00000000af03');

        /** @var ResolveAuthorizationSimulationFacts $resolver */
        $resolver = $this->app->make(ResolveAuthorizationSimulationFacts::class);

        $this->assertInstanceOf(RegisteredAuthorizationSimulationFactsResolver::class, $resolver);
        $this->assertNull(
            $resolver->resolve($reference),
            'Provider-less container must resolve to null (fail-closed), matching production behaviour before any module registers a tagged provider.',
        );
    }
}

final class TaggedFactsProvider implements AuthorizationSimulationFactsProvider
{
    public static ?string $lastReferenceType = null;

    public function supports(AuthorizationResourceReference $reference): bool
    {
        return $reference->type === 'work_record';
    }

    public function resolve(AuthorizationResourceReference $reference): RecordFacts
    {
        self::$lastReferenceType = $reference->type;

        return new RecordFacts(
            ownerFacilityId: null,
            resourceType: $reference->type,
            classification: 'tagged-classification',
            recordId: $reference->id,
        );
    }
}

final class UntaggedFactsProvider implements AuthorizationSimulationFactsProvider
{
    public static ?string $lastReferenceType = null;

    public function supports(AuthorizationResourceReference $reference): bool
    {
        return $reference->type === 'work_record';
    }

    public function resolve(AuthorizationResourceReference $reference): RecordFacts
    {
        self::$lastReferenceType = $reference->type;

        return new RecordFacts(
            ownerFacilityId: null,
            resourceType: $reference->type,
            classification: 'untagged-classification',
            recordId: $reference->id,
        );
    }
}
