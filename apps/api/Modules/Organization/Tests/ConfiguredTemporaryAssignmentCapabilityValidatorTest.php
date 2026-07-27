<?php

namespace Modules\Organization\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Organization\Features\TemporaryAssignment\Contracts\ValidateTemporaryAssignmentCapabilities;
use Modules\Organization\Infrastructure\Authorization\ConfiguredTemporaryAssignmentCapabilityValidator;
use RuntimeException;
use Tests\TestCase;

final class ConfiguredTemporaryAssignmentCapabilityValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_container_resolves_the_real_validator_binding_and_accepts_active_catalogue_codes(): void
    {
        config(['authorization.temporary_assignment_capabilities' => ['records.read', 'records.approve']]);

        $validator = $this->app->make(ValidateTemporaryAssignmentCapabilities::class);

        $this->assertInstanceOf(ConfiguredTemporaryAssignmentCapabilityValidator::class, $validator);
        $this->assertTrue($validator->allAreActive(['records.read', 'records.approve']));
    }

    public function test_unknown_code_is_rejected(): void
    {
        config(['authorization.temporary_assignment_capabilities' => ['records.read']]);

        $validator = $this->app->make(ValidateTemporaryAssignmentCapabilities::class);

        $this->assertFalse($validator->allAreActive(['records.unknown']));
    }

    public function test_code_removed_from_active_catalogue_is_rejected(): void
    {
        config(['authorization.temporary_assignment_capabilities' => ['records.read']]);
        $validator = $this->app->make(ValidateTemporaryAssignmentCapabilities::class);

        config(['authorization.temporary_assignment_capabilities' => []]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('temporary_assignment_capability_catalogue_unavailable');
        $validator->allAreActive(['records.read']);
    }

    public function test_missing_catalogue_fails_closed_with_explicit_unavailable_error(): void
    {
        config()->offsetUnset('authorization.temporary_assignment_capabilities');
        $validator = $this->app->make(ValidateTemporaryAssignmentCapabilities::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('temporary_assignment_capability_catalogue_unavailable');
        $validator->allAreActive(['records.read']);
    }

    public function test_wildcard_and_malformed_codes_are_not_accepted_as_catalogue_members(): void
    {
        config(['authorization.temporary_assignment_capabilities' => ['records.read']]);
        $validator = $this->app->make(ValidateTemporaryAssignmentCapabilities::class);

        $this->assertFalse($validator->allAreActive(['records.*']));
        $this->assertFalse($validator->allAreActive(['records_read']));
    }
}
