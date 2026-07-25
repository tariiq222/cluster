<?php

namespace Tests\Feature\Http\Controllers\Organization;

use DomainException;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Modules\Organization\Contracts\AccessDecision;
use Modules\Organization\Contracts\DecideAccess;
use Modules\Organization\Contracts\RecordFacts;
use Modules\Organization\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Features\TemporaryAssignment\Console\ExpireTemporaryAssignmentsCommand;
use Modules\Organization\Features\TemporaryAssignment\Console\RunTemporaryAssignmentExpiration;
use Modules\Organization\Features\TemporaryAssignment\Exceptions\TemporaryAssignmentIdempotencyConflict;
use Modules\Organization\Features\TemporaryAssignment\Http\CreateTemporaryAssignmentController;
use Modules\Organization\Features\TemporaryAssignment\Http\GetTemporaryAssignmentController;
use Modules\Organization\Features\TemporaryAssignment\Http\ListTemporaryAssignmentsController;
use Modules\Organization\Features\TemporaryAssignment\Http\RevokeTemporaryAssignmentController;
use Modules\Organization\Features\TemporaryAssignment\Http\TemporaryAssignmentHttpGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

class OrganizationTemporaryAssignmentHttpAdapterTest extends TestCase
{
    private const ACTOR_ID = '018f6f7d-0c00-7000-8000-000000000805';

    private const ASSIGNMENT_ID = '018f6f7d-0c00-7000-8000-000000000803';

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000807';

    private const FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000806';

    private const PERSON_ID = '018f6f7d-0c00-7000-8000-000000000804';

    private const UNIT_ID = '018f6f7d-0c00-7000-8000-000000000801';

    private const OTHER_UNIT_ID = '018f6f7d-0c00-7000-8000-000000000802';

    public function test_create_binds_the_exact_unit_and_actor_and_emits_separate_cache_and_mutation_tokens(): void
    {
        $gateway = new FakeTemporaryAssignmentHttpGateway($this->assignment());
        $access = new FakeTemporaryAssignmentAccess;
        $controller = new CreateTemporaryAssignmentController($this->principal(), $access, $gateway);

        $response = TestResponse::fromBaseResponse($controller(
            $this->request('POST', '/api/v1/organization/temporary-assignments', $this->createBody(), [
                'Idempotency-Key' => 'temporary-create',
            ]),
        ));

        $response->assertCreated()
            ->assertHeader('ETag', 'W/"temporary-assignment-'.self::ASSIGNMENT_ID.'-v1-pending"')
            ->assertHeader('X-Resource-Version', '"1"')
            ->assertJsonPath('data.organization_unit_id', self::UNIT_ID)
            ->assertJsonPath('data.person_id', self::PERSON_ID)
            ->assertJsonMissingPath('data.representation_etag')
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.approved_by_user_id', self::ACTOR_ID)
            ->assertJsonMissingPath('data.state')
            ->assertJsonMissingPath('data.display_name_ar')
            ->assertJsonMissingPath('data.employee_number');

        $this->assertSame(self::UNIT_ID, $gateway->lastCreateInput['organization_unit_id']);
        $this->assertSame(self::ACTOR_ID, $gateway->lastActorId);
        $this->assertSame('organization.temporary-assignment.manage', $access->capabilities[0]);
        $this->assertSame('organization_temporary_assignment', $access->facts[0]?->resourceType);

        TestResponse::fromBaseResponse($controller(
            $this->request('POST', '/api/v1/organization/temporary-assignments', [
                ...$this->createBody(),
                'approved_by_user_id' => self::OTHER_UNIT_ID,
            ], ['Idempotency-Key' => 'client-actor']),
        ))->assertBadRequest();

        $gateway->conflictAction = 'create';
        TestResponse::fromBaseResponse($controller(
            $this->request('POST', '/api/v1/organization/temporary-assignments', $this->createBody(), [
                'Idempotency-Key' => 'conflicting-create',
            ]),
        ))->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/idempotency-conflict');
    }

    public function test_list_and_get_are_exact_unit_scoped_authorized_and_cacheable_without_pii(): void
    {
        $gateway = new FakeTemporaryAssignmentHttpGateway($this->assignment());
        $access = new FakeTemporaryAssignmentAccess;
        $principal = $this->principal();

        $listed = TestResponse::fromBaseResponse((new ListTemporaryAssignmentsController($principal, $access, $gateway))(
            $this->request('GET', '/api/v1/organization/temporary-assignments?organization_unit_id='.self::UNIT_ID.'&limit=20'),
        ));
        $listed->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.organization_unit_id', self::UNIT_ID)
            ->assertJsonMissingPath('items.0.representation_etag')
            ->assertJsonMissingPath('items.0.display_name_ar');
        $this->assertSame(self::UNIT_ID, $gateway->lastListUnitId);
        $this->assertSame(20, $gateway->lastListLimit);

        $get = new GetTemporaryAssignmentController($principal, $access, $gateway);
        $found = TestResponse::fromBaseResponse($get(
            $this->request('GET', '/api/v1/organization/temporary-assignments/'.self::ASSIGNMENT_ID),
            self::ASSIGNMENT_ID,
        ));
        $found->assertOk()
            ->assertHeader('ETag', 'W/"temporary-assignment-'.self::ASSIGNMENT_ID.'-v1-pending"')
            ->assertHeader('X-Resource-Version', '"1"');

        TestResponse::fromBaseResponse($get(
            $this->request('GET', '/api/v1/organization/temporary-assignments/'.self::ASSIGNMENT_ID, [], [
                'If-None-Match' => 'W/"temporary-assignment-'.self::ASSIGNMENT_ID.'-v1-pending"',
            ]),
            self::ASSIGNMENT_ID,
        ))->assertStatus(304)
            ->assertHeader('ETag', 'W/"temporary-assignment-'.self::ASSIGNMENT_ID.'-v1-pending"');

        TestResponse::fromBaseResponse($get(
            $this->request('GET', '/api/v1/organization/temporary-assignments/'.self::OTHER_UNIT_ID),
            self::OTHER_UNIT_ID,
        ))->assertNotFound();

        $access->allow = false;
        TestResponse::fromBaseResponse((new ListTemporaryAssignmentsController($principal, $access, $gateway))(
            $this->request('GET', '/api/v1/organization/temporary-assignments?organization_unit_id='.self::UNIT_ID),
        ))->assertForbidden();
        TestResponse::fromBaseResponse($get(
            $this->request('GET', '/api/v1/organization/temporary-assignments/'.self::ASSIGNMENT_ID),
            self::ASSIGNMENT_ID,
        ))->assertNotFound();

        $this->assertContains('organization.temporary-assignment.read', $access->capabilities);
    }

    public function test_revoke_requires_the_strong_resource_version_and_maps_stale_and_typed_conflicts(): void
    {
        $gateway = new FakeTemporaryAssignmentHttpGateway($this->assignment());
        $access = new FakeTemporaryAssignmentAccess;
        $controller = new RevokeTemporaryAssignmentController($this->principal(), $access, $gateway);
        $uri = '/api/v1/organization/temporary-assignments/'.self::ASSIGNMENT_ID.'/revoke';

        TestResponse::fromBaseResponse($controller(
            $this->request('POST', $uri, ['reason' => 'انتهاء الحاجة'], [
                'Idempotency-Key' => 'weak-version',
                'If-Match' => 'W/"temporary-assignment-'.self::ASSIGNMENT_ID.'-v1-pending"',
            ]),
            self::ASSIGNMENT_ID,
        ))->assertBadRequest();

        TestResponse::fromBaseResponse($controller(
            $this->request('POST', $uri, ['reason' => 'انتهاء الحاجة'], $this->mutationHeaders('"2"', 'stale-version')),
            self::ASSIGNMENT_ID,
        ))->assertStatus(412)
            ->assertJsonPath('type', 'https://cluster.example/problems/precondition-failed');

        $gateway->revokeAssignment = $this->assignment([
            'state' => 'revoked',
            'lock_version' => 2,
            'representation_etag' => 'W/"temporary-assignment-'.self::ASSIGNMENT_ID.'-v2-revoked"',
            'revoked_at' => '2026-07-18T10:30:00.000Z',
            'revocation_reason' => 'انتهاء الحاجة',
        ]);
        $revoked = TestResponse::fromBaseResponse($controller(
            $this->request('POST', $uri, ['reason' => 'انتهاء الحاجة'], $this->mutationHeaders('"1"', 'revoke')),
            self::ASSIGNMENT_ID,
        ));
        $revoked->assertOk()
            ->assertHeader('ETag', 'W/"temporary-assignment-'.self::ASSIGNMENT_ID.'-v2-revoked"')
            ->assertHeader('X-Resource-Version', '"2"')
            ->assertJsonPath('data.status', 'revoked')
            ->assertJsonPath('data.revoke_reason', 'انتهاء الحاجة');
        $this->assertSame(self::ACTOR_ID, $gateway->lastActorId);

        $gateway->conflictAction = 'revoke';
        TestResponse::fromBaseResponse($controller(
            $this->request('POST', $uri, ['reason' => 'سبب آخر'], $this->mutationHeaders('"2"', 'revoke-conflict')),
            self::ASSIGNMENT_ID,
        ))->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/idempotency-conflict');

        $access->allow = false;
        TestResponse::fromBaseResponse($controller(
            $this->request('POST', $uri, ['reason' => 'محاولة مرفوضة'], $this->mutationHeaders('"2"', 'denied')),
            self::ASSIGNMENT_ID,
        ))->assertNotFound();
    }

    public function test_anonymous_requests_fail_closed_before_the_gateway_is_called(): void
    {
        $gateway = new FakeTemporaryAssignmentHttpGateway($this->assignment());
        $controller = new CreateTemporaryAssignmentController(
            new FakeTemporaryAssignmentPrincipal(null),
            new FakeTemporaryAssignmentAccess,
            $gateway,
        );

        TestResponse::fromBaseResponse($controller(
            $this->request('POST', '/api/v1/organization/temporary-assignments', $this->createBody(), [
                'Idempotency-Key' => 'anonymous',
            ]),
        ))->assertUnauthorized();

        $this->assertSame([], $gateway->lastCreateInput);
    }

    public function test_expiration_command_invokes_exactly_one_bounded_batch_with_server_identity(): void
    {
        $expiration = new FakeTemporaryAssignmentExpiration;
        $command = new ExpireTemporaryAssignmentsCommand($expiration);

        $result = $command(37);

        $this->assertSame(['expired_count' => 37, 'expired_ids' => [], 'has_more' => true], $result);
        $this->assertSame([37], $expiration->limits);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $expiration->subjectId);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $expiration->correlationId);

        foreach ([0, 501] as $invalidLimit) {
            try {
                $command($invalidLimit);
                $this->fail('The expiration command must reject an unbounded limit.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('temporary_assignment_expiration_limit_invalid', $exception->getMessage());
            }
        }
        $this->assertSame([37], $expiration->limits);

        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $this->assertSame(Command::INVALID, $tester->execute(['--limit' => '7']));
        $this->assertSame(Command::SUCCESS, $tester->execute(['--once' => true, '--limit' => '7']));
        $this->assertSame([37, 7], $expiration->limits);
    }

    /** @param array<string, mixed> $overrides */
    /** @return array<string, mixed> */
    private function assignment(array $overrides = []): array
    {
        return [
            'id' => self::ASSIGNMENT_ID,
            'person_id' => self::PERSON_ID,
            'organization_unit_id' => self::UNIT_ID,
            'capability_codes' => ['records.read'],
            'start_at' => '2026-07-18T11:00:00.000Z',
            'end_at' => '2026-07-19T11:00:00.000Z',
            'state' => 'pending',
            'state_evaluated_at' => '2026-07-18T10:00:00.000Z',
            'representation_etag' => 'W/"temporary-assignment-'.self::ASSIGNMENT_ID.'-v1-pending"',
            'reason' => 'تغطية المناوبة',
            'revoked_at' => null,
            'revocation_reason' => null,
            'lock_version' => 1,
            'approved_by_user_id' => self::ACTOR_ID,
            'employee_number' => 'EMP-PRIVATE',
            ...$overrides,
        ];
    }

    /** @return array<string, mixed> */
    private function createBody(): array
    {
        return [
            'person_id' => self::PERSON_ID,
            'organization_unit_id' => self::UNIT_ID,
            'capability_codes' => ['records.read'],
            'start_at' => '2026-07-18T11:00:00.000Z',
            'end_at' => '2026-07-19T11:00:00.000Z',
            'reason' => 'تغطية المناوبة',
        ];
    }

    /** @return array<string, string> */
    private function mutationHeaders(string $version, string $key): array
    {
        return ['Idempotency-Key' => $key, 'If-Match' => $version];
    }

    private function principal(): FakeTemporaryAssignmentPrincipal
    {
        return new FakeTemporaryAssignmentPrincipal([
            'user_id' => self::ACTOR_ID,
            'facility_id' => self::FACILITY_ID,
        ]);
    }

    /** @param array<string, mixed> $body */
    /** @param array<string, string> $headers */
    private function request(string $method, string $uri, array $body = [], array $headers = []): Request
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CORRELATION_ID' => self::CORRELATION_ID,
        ];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create(
            $uri,
            $method,
            [],
            [],
            [],
            $server,
            $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}

final class FakeTemporaryAssignmentPrincipal implements ResolveDevelopmentFixturePrincipal
{
    /** @param array{user_id: string, facility_id: string}|null $principal */
    public function __construct(private readonly ?array $principal) {}

    public function issue(array $principal): array
    {
        return ['access_token' => 'unused', 'expires_at' => '2026-07-18T12:00:00.000Z'];
    }

    public function resolve(Request $request): ?array
    {
        return $this->principal;
    }
}

final class FakeTemporaryAssignmentAccess implements DecideAccess
{
    public bool $allow = true;

    /** @var list<string> */
    public array $capabilities = [];

    /** @var list<RecordFacts|null> */
    public array $facts = [];

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        $this->capabilities[] = $capability;
        $this->facts[] = $facts;
        if ($facts === null) {
            throw new InvalidArgumentException('Temporary-assignment authorization requires record facts.');
        }

        return new AccessDecision(
            decision: $this->allow ? 'allow' : 'deny',
            action: $capability,
            resourceType: $facts->resourceType,
            reasonCodes: $this->allow ? [] : ['denied'],
            policyVersion: 'temporary-assignment-test-v1',
            factsVersion: $facts->factsVersion,
            classification: $facts->classification,
        );
    }
}

final class FakeTemporaryAssignmentHttpGateway implements TemporaryAssignmentHttpGateway
{
    /** @var array<string, mixed> */
    public array $assignment;

    public ?string $conflictAction = null;

    /** @var array<string, mixed>|null */
    public ?array $revokeAssignment = null;

    public ?string $lastActorId = null;

    /** @var array<string, mixed> */
    public array $lastCreateInput = [];

    public int $lastListLimit = 0;

    public ?string $lastListUnitId = null;

    /** @param array<string, mixed> $assignment */
    public function __construct(array $assignment)
    {
        $this->assignment = $assignment;
    }

    public function create(string $temporaryAssignmentId, array $input, string $actorId, string $correlationId, array $idempotency): array
    {
        if ($this->conflictAction === 'create') {
            throw new TemporaryAssignmentIdempotencyConflict;
        }
        $this->lastCreateInput = $input;
        $this->lastActorId = $actorId;

        return ['created' => true, 'temporary_assignment' => $this->assignment];
    }

    public function find(string $temporaryAssignmentId): ?array
    {
        if ($temporaryAssignmentId !== $this->assignment['id']) {
            return null;
        }

        return $this->assignment;
    }

    public function listInUnit(string $organizationUnitId, ?string $cursor, int $limit): array
    {
        $this->lastListUnitId = $organizationUnitId;
        $this->lastListLimit = $limit;

        return [
            'items' => $organizationUnitId === $this->assignment['organization_unit_id'] ? [$this->assignment] : [],
            'next_cursor' => null,
        ];
    }

    public function revoke(
        string $temporaryAssignmentId,
        int $expectedVersion,
        string $reason,
        string $actorId,
        string $correlationId,
        array $idempotency,
    ): array {
        if ($this->conflictAction === 'revoke') {
            throw new TemporaryAssignmentIdempotencyConflict;
        }
        if ($expectedVersion !== (int) $this->assignment['lock_version']) {
            throw new DomainException('precondition_failed');
        }
        $this->lastActorId = $actorId;

        return ['changed' => true, 'temporary_assignment' => $this->revokeAssignment ?? $this->assignment];
    }
}

final class FakeTemporaryAssignmentExpiration implements RunTemporaryAssignmentExpiration
{
    public string $correlationId = '';

    /** @var list<int> */
    public array $limits = [];

    public string $subjectId = '';

    public function run(int $limit, string $subjectId, string $correlationId): array
    {
        $this->limits[] = $limit;
        $this->subjectId = $subjectId;
        $this->correlationId = $correlationId;

        return ['expired_count' => $limit, 'expired_ids' => [], 'has_more' => true];
    }
}
