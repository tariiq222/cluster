<?php

namespace Modules\PlatformSettings\Tests;

use App\Integrations\PlatformSettings\PlatformSettingsApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\PlatformSettings\Features\Calendars\Handler\BusinessCalendarHandler;
use Modules\PlatformSettings\Features\Calendars\Http\BusinessCalendarController;
use Modules\PlatformSettings\Features\Settings\Handler\PlatformSettingsHandler;
use Modules\PlatformSettings\Features\Settings\Http\CreateSettingsVersionController;
use Modules\PlatformSettings\Features\Settings\Http\GetCurrentPlatformSettingsController;
use Modules\PlatformSettings\Features\Settings\Http\PublishSettingsVersionController;
use Modules\PlatformSettings\Features\Settings\Http\UpdateSettingsValueController;
use Modules\PlatformSettings\Features\Settings\Http\ValidateSettingsVersionController;
use Modules\PlatformSettings\Infrastructure\Persistence\DatabaseBusinessCalendars;
use Tests\TestCase;

class_alias(\Modules\Authorization\Contracts\AccessDecision::class, __NAMESPACE__.'\\AccessDecision');
class_alias(\Modules\Authorization\Contracts\DecideAccess::class, __NAMESPACE__.'\\DecideAccess');
class_alias(\Modules\Authorization\Contracts\RecordFacts::class, __NAMESPACE__.'\\RecordFacts');
class_alias(\Modules\Authorization\Features\OperationsOffice\BootstrapOperationsOffice::class, __NAMESPACE__.'\\BootstrapOperationsOffice');
class_alias(\Modules\Authorization\Infrastructure\RbacAbacDecideAccess::class, __NAMESPACE__.'\\RbacAbacDecideAccess');
class_alias(\Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal::class, __NAMESPACE__.'\\ResolveDevelopmentFixturePrincipal');
class_alias(\Modules\Organization\Contracts\ResolveOrganizationScopeAncestry::class, __NAMESPACE__.'\\ResolveOrganizationScopeAncestry');

final class PlatformSettingsHttpAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '0197f0e0-0000-7000-8000-000000000801';

    public function test_current_settings_requires_an_authenticated_session(): void
    {
        $response = $this->current('missing');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/authentication-required', $response->getData(true)['type']);
    }

    public function test_current_settings_hides_the_platform_resource_without_read_capability(): void
    {
        $response = $this->current('deny');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/access-denied', $response->getData(true)['type']);
    }

    public function test_second_draft_returns_conflict(): void
    {
        $this->assertSame(201, $this->createVersion()->getStatusCode());
        $response = $this->createVersion();

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/conflict', $response->getData(true)['type']);
    }

    public function test_unknown_version_is_not_exposed(): void
    {
        $response = $this->updateValue('0197f0e0-0000-7000-8000-000000000899', 'localization.default_locale', 'ar', '"1"');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/resource-not-found', $response->getData(true)['type']);
    }

    public function test_stale_etag_is_rejected_without_overwriting_the_settings_version(): void
    {
        $created = $this->createVersion()->getData(true);
        $versionId = $created['id'];
        $this->assertSame(200, $this->updateValue($versionId, 'localization.default_locale', 'en', '"1"')->getStatusCode());

        $response = $this->updateValue($versionId, 'localization.default_locale', 'ar', '"1"');

        $this->assertSame(412, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/precondition-failed', $response->getData(true)['type']);
    }

    public function test_invalid_typed_setting_value_returns_validation_problem(): void
    {
        $versionId = $this->createVersion()->getData(true)['id'];
        $response = $this->updateValue($versionId, 'operations.active_log_months', 'not-an-integer', '"1"');

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/validation-failed', $response->getData(true)['type']);
    }

    public function test_settings_validate_publish_and_calendar_publish_require_idempotency_key(): void
    {
        $versionId = $this->createVersion()->getData(true)['id'];
        $handler = $this->app->make(PlatformSettingsHandler::class);
        foreach ([
            new ValidateSettingsVersionController($this->api(), $handler),
            new PublishSettingsVersionController($this->api(), $handler),
        ] as $controller) {
            $request = $this->request('POST', '/platform-settings/versions/'.$versionId.'/lifecycle');
            $request->headers->set('If-Match', '"1"');
            $response = $controller($request, $versionId);
            $this->assertSame(400, $response->getStatusCode());
            $this->assertSame('https://cluster.example/problems/invalid-idempotency-key', $response->getData(true)['type']);
        }

        $calendarId = '0197f0e0-0000-7000-8000-000000000874';
        DB::table('business_calendars')->insert([
            'id' => $calendarId,
            'scope_type' => 'platform',
            'scope_id' => null,
            'parent_calendar_id' => null,
            'status' => 'draft',
            'timezone' => 'Asia/Riyadh',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $request = $this->request('POST', '/platform-settings/calendars/'.$calendarId.'/publish');
        $request->headers->set('If-Match', '"1"');
        $response = (new BusinessCalendarController(
            $this->api(),
            new BusinessCalendarHandler(new DatabaseBusinessCalendars(static fn (): ?array => null)),
        ))->publish($request, $calendarId);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/invalid-idempotency-key', $response->getData(true)['type']);
    }

    public function test_lifecycle_commands_replay_and_reject_conflicting_key_reuse(): void
    {
        $versionId = $this->createVersion()->getData(true)['id'];
        $handler = $this->app->make(PlatformSettingsHandler::class);
        $validate = new ValidateSettingsVersionController($this->api(), $handler);
        $validateRequest = $this->request('POST', '/platform-settings/versions/'.$versionId.'/validate');
        $validateRequest->headers->set('If-Match', '"1"');
        $validateRequest->headers->set('Idempotency-Key', 'settings-validate-replay');
        $firstValidate = $validate($validateRequest, $versionId);
        $replayValidate = $validate($validateRequest, $versionId);
        $this->assertSame($firstValidate->getData(true), $replayValidate->getData(true));
        $this->assertSame(200, $firstValidate->getStatusCode());
        $this->assertSame(200, $replayValidate->getStatusCode());
        $conflictValidate = clone $validateRequest;
        $conflictValidate->headers->set('If-Match', '"2"');
        $this->assertSame(409, $validate($conflictValidate, $versionId)->getStatusCode());

        $publish = new PublishSettingsVersionController($this->api(), $handler);
        $publishRequest = $this->request('POST', '/platform-settings/versions/'.$versionId.'/publish');
        $publishRequest->headers->set('If-Match', '"2"');
        $publishRequest->headers->set('Idempotency-Key', 'settings-publish-replay');
        $firstPublish = $publish($publishRequest, $versionId);
        $replayPublish = $publish($publishRequest, $versionId);
        $this->assertSame($firstPublish->getData(true), $replayPublish->getData(true));
        $conflictPublish = clone $publishRequest;
        $this->assertSame(200, $firstPublish->getStatusCode());
        $this->assertSame(200, $replayPublish->getStatusCode());
        $conflictPublish->headers->set('If-Match', '"3"');
        $this->assertSame(409, $publish($conflictPublish, $versionId)->getStatusCode());

        $calendarId = '0197f0e0-0000-7000-8000-000000000875';
        DB::table('business_calendars')->insert([
            'id' => $calendarId,
            'scope_type' => 'platform',
            'scope_id' => null,
            'parent_calendar_id' => null,
            'status' => 'draft',
            'timezone' => 'Asia/Riyadh',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $calendar = new BusinessCalendarController(
            $this->api(),
            new BusinessCalendarHandler(new DatabaseBusinessCalendars(static fn (): ?array => null)),
        );
        $calendarRequest = $this->request('POST', '/platform-settings/calendars/'.$calendarId.'/publish');
        $calendarRequest->headers->set('If-Match', '"1"');
        $calendarRequest->headers->set('Idempotency-Key', 'calendar-publish-replay');
        $firstCalendar = $calendar->publish($calendarRequest, $calendarId);
        $replayCalendar = $calendar->publish($calendarRequest, $calendarId);
        $this->assertSame(200, $firstCalendar->getStatusCode());
        $this->assertSame(200, $replayCalendar->getStatusCode());
        $this->assertSame($firstCalendar->getData(true), $replayCalendar->getData(true));
        $conflictCalendar = clone $calendarRequest;
        $conflictCalendar->headers->set('If-Match', '"2"');
        $this->assertSame(409, $calendar->publish($conflictCalendar, $calendarId)->getStatusCode());
    }

    public function test_business_calendar_live_routes_are_authoritative_and_planned_alias_is_absent(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());
        $expected = [
            'GET' => ['api/v1/platform-settings/calendars'],
            'POST' => ['api/v1/platform-settings/calendars', 'api/v1/platform-settings/calendars/{calendarId}/publish'],
            'PUT' => [
                'api/v1/platform-settings/calendars/{calendarId}/weekdays/{weekday}',
                'api/v1/platform-settings/calendars/{calendarId}/exceptions/{date}',
            ],
        ];

        foreach ($expected as $method => $uris) {
            foreach ($uris as $uri) {
                $this->assertTrue(
                    $routes->contains(fn ($route): bool => in_array($method, $route->methods(), true) && $route->uri() === $uri),
                    "Missing {$method} {$uri}",
                );
            }
        }

        $this->assertFalse($routes->contains(fn ($route): bool => str_contains($route->uri(), 'business-calendars')));
        $this->getJson('/api/v1/business-calendars')->assertNotFound();
    }

    public function test_calendar_weekday_route_accepts_iso_sunday_and_rejects_zero(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());
        $route = $routes->first(fn ($candidate): bool => $candidate->uri() === 'api/v1/platform-settings/calendars/{calendarId}/weekdays/{weekday}');

        $this->assertNotNull($route);
        $this->assertSame('[1-7]', $route->wheres['weekday'] ?? null);

        $calendarId = '0197f0e0-0000-7000-8000-000000000890';
        $this->assertTrue($route->matches(Request::create("/api/v1/platform-settings/calendars/{$calendarId}/weekdays/7", 'PUT')));
        $this->assertFalse($route->matches(Request::create("/api/v1/platform-settings/calendars/{$calendarId}/weekdays/0", 'PUT')));
    }

    public function test_calendar_domain_accepts_every_contract_exception_type(): void
    {
        $accepted = ['official_holiday', 'local_closure', 'local_hours', 'official_holiday_work_override', 'ramadan'];

        foreach ($accepted as $type) {
            $exception = \Modules\PlatformSettings\Domain\CalendarException::forRange(
                $type,
                new \DateTimeImmutable('2026-07-26'),
                null,
                true,
                '08:00',
                '16:00',
            );

            $this->assertSame($type, $exception->type);
        }
    }

    public function test_calendar_weekday_rejects_a_stale_etag_without_overwriting_the_calendar(): void
    {
        $calendarId = '0197f0e0-0000-7000-8000-000000000891';
        DB::table('business_calendars')->insert(['id' => $calendarId, 'scope_type' => 'platform', 'scope_id' => 'platform', 'parent_calendar_id' => null, 'status' => 'draft', 'timezone' => 'Asia/Riyadh', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $controller = new BusinessCalendarController($this->api(), new BusinessCalendarHandler(new DatabaseBusinessCalendars(static fn (): ?array => null)));
        $first = $this->request('PUT', "/platform-settings/calendars/{$calendarId}/weekdays/1");
        $first->headers->set('If-Match', '"1"');
        $first->merge(['is_working_day' => true, 'starts_at' => '08:00', 'ends_at' => '16:00']);
        $this->assertSame(200, $controller->setWeekday($first, $calendarId, 1)->getStatusCode());

        $stale = $this->request('PUT', "/platform-settings/calendars/{$calendarId}/weekdays/1");
        $stale->headers->set('If-Match', '"1"');
        $stale->merge(['is_working_day' => true, 'starts_at' => '09:00', 'ends_at' => '17:00']);

        $this->assertSame(412, $controller->setWeekday($stale, $calendarId, 1)->getStatusCode());
        $this->assertSame('08:00', DB::table('business_calendar_weekdays')->where('business_calendar_id', $calendarId)->value('starts_at'));
    }

    public function test_calendar_listing_exposes_weekday_hours_for_seeded_schedules(): void
    {
        $calendarId = '0197f0e0-0000-7000-8000-000000000901';
        DB::table('business_calendars')->insert([
            'id' => $calendarId, 'scope_type' => 'platform', 'scope_id' => 'platform',
            'parent_calendar_id' => null, 'status' => 'draft', 'timezone' => 'Asia/Riyadh',
            'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('business_calendar_weekdays')->insert([
            'id' => '0197f0e0-0000-7000-8000-000000000902',
            'business_calendar_id' => $calendarId, 'weekday' => 1,
            'is_working_day' => true, 'starts_at' => '08:00', 'ends_at' => '16:00',
            'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('business_calendar_weekdays')->insert([
            'id' => '0197f0e0-0000-7000-8000-000000000903',
            'business_calendar_id' => $calendarId, 'weekday' => 6,
            'is_working_day' => false, 'starts_at' => null, 'ends_at' => null,
            'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $controller = new BusinessCalendarController($this->api(), new BusinessCalendarHandler(new DatabaseBusinessCalendars(static fn (): ?array => null)));
        $response = $controller->index($this->request('GET', '/platform-settings/calendars'));

        $this->assertSame(200, $response->getStatusCode());
        $items = $response->getData(true)['items'];
        $this->assertCount(1, $items);
        $weekdays = $items[0]['values']['weekdays'];
        $this->assertCount(2, $weekdays);

        $byWeekday = [];
        foreach ($weekdays as $entry) {
            $byWeekday[$entry['weekday']] = $entry;
        }
        $this->assertSame(['weekday' => 1, 'is_working_day' => true, 'starts_at' => '08:00', 'ends_at' => '16:00'], $byWeekday[1]);
        $this->assertSame(['weekday' => 6, 'is_working_day' => false, 'starts_at' => null, 'ends_at' => null], $byWeekday[6]);
    }

    public function test_calendar_weekday_set_then_list_preserves_existing_hours_in_projection(): void
    {
        $calendarId = '0197f0e0-0000-7000-8000-000000000904';
        DB::table('business_calendars')->insert([
            'id' => $calendarId, 'scope_type' => 'platform', 'scope_id' => 'platform',
            'parent_calendar_id' => null, 'status' => 'draft', 'timezone' => 'Asia/Riyadh',
            'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $controller = new BusinessCalendarController($this->api(), new BusinessCalendarHandler(new DatabaseBusinessCalendars(static fn (): ?array => null)));
        $put = $this->request('PUT', "/platform-settings/calendars/{$calendarId}/weekdays/1");
        $put->headers->set('If-Match', '"1"');
        $put->merge(['is_working_day' => true, 'starts_at' => '08:00', 'ends_at' => '16:00']);
        $this->assertSame(200, $controller->setWeekday($put, $calendarId, 1)->getStatusCode());

        $response = $controller->index($this->request('GET', '/platform-settings/calendars'));
        $weekdays = $response->getData(true)['items'][0]['values']['weekdays'];

        $this->assertCount(1, $weekdays);
        $this->assertSame(['weekday' => 1, 'is_working_day' => true, 'starts_at' => '08:00', 'ends_at' => '16:00'], $weekdays[0]);
    }

    public function test_calendar_mutation_is_denied_when_the_target_facility_scope_is_not_authorized(): void
    {
        $calendarId = '0197f0e0-0000-7000-8000-000000000892';
        $blockedFacility = '0197f0e0-0000-7000-8000-000000000893';
        DB::table('business_calendars')->insert(['id' => $calendarId, 'scope_type' => 'facility', 'scope_id' => $blockedFacility, 'parent_calendar_id' => null, 'status' => 'draft', 'timezone' => 'Asia/Riyadh', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $api = new PlatformSettingsApi(new PlatformSettingsHttpPrincipalResolver, new PlatformSettingsScopeDecider($blockedFacility), new PlatformSettingsScopeAncestry($blockedFacility));
        $controller = new BusinessCalendarController($api, new BusinessCalendarHandler(new DatabaseBusinessCalendars(static fn (): ?array => null)));
        $request = $this->request('PUT', "/platform-settings/calendars/{$calendarId}/weekdays/1");
        $request->headers->set('If-Match', '"1"');
        $request->merge(['is_working_day' => true, 'starts_at' => '08:00', 'ends_at' => '16:00']);

        $this->assertSame(403, $controller->setWeekday($request, $calendarId, 1)->getStatusCode());
        $this->assertSame(0, DB::table('business_calendar_weekdays')->where('business_calendar_id', $calendarId)->count());
    }

    public function test_denied_official_holiday_override_does_not_advance_the_calendar_etag(): void
    {
        $calendarId = '0197f0e0-0000-7000-8000-000000000894';
        DB::table('business_calendars')->insert(['id' => $calendarId, 'scope_type' => 'platform', 'scope_id' => 'platform', 'parent_calendar_id' => null, 'status' => 'draft', 'timezone' => 'Asia/Riyadh', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $api = new PlatformSettingsApi(new PlatformSettingsHttpPrincipalResolver, new PlatformSettingsScopeDecider(null, true));
        $controller = new BusinessCalendarController($api, new BusinessCalendarHandler(new DatabaseBusinessCalendars(static fn (): ?array => null)));
        $request = $this->request('PUT', "/platform-settings/calendars/{$calendarId}/exceptions/2026-07-23");
        $request->headers->set('If-Match', '"1"');
        $request->merge(['type' => 'official_holiday_work_override', 'is_working_day' => true, 'starts_at' => '08:00', 'ends_at' => '16:00', 'reason' => 'Approved coverage']);

        $this->assertSame(403, $controller->setException($request, $calendarId, '2026-07-23')->getStatusCode());
        $this->assertSame(1, DB::table('business_calendars')->where('id', $calendarId)->value('lock_version'));
        $this->assertSame(0, DB::table('business_calendar_exceptions')->where('business_calendar_id', $calendarId)->count());
    }

    public function test_target_facility_scope_is_used_for_official_holiday_override_authorization(): void
    {
        $calendarId = '0197f0e0-0000-7000-8000-000000000899';
        $facility = '0197f0e0-0000-7000-8000-000000000900';
        DB::table('business_calendars')->insert(['id' => $calendarId, 'scope_type' => 'facility', 'scope_id' => $facility, 'parent_calendar_id' => null, 'status' => 'draft', 'timezone' => 'Asia/Riyadh', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $api = new PlatformSettingsApi(new PlatformSettingsHttpPrincipalResolver, new PlatformSettingsScopeDecider(null, true), new PlatformSettingsScopeAncestry($facility));
        $controller = new BusinessCalendarController($api, new BusinessCalendarHandler(new DatabaseBusinessCalendars(static fn (): ?array => null)));
        $request = $this->request('PUT', "/platform-settings/calendars/{$calendarId}/exceptions/2026-07-23");
        $request->headers->set('If-Match', '"1"');
        $request->merge(['type' => 'official_holiday_work_override', 'is_working_day' => true, 'starts_at' => '08:00', 'ends_at' => '16:00', 'reason' => 'Approved coverage']);

        $this->assertSame(403, $controller->setException($request, $calendarId, '2026-07-23')->getStatusCode());
        $this->assertSame(1, DB::table('business_calendars')->where('id', $calendarId)->value('lock_version'));
    }

    public function test_real_cluster_scoped_platform_owner_is_allowed_for_platform_settings(): void
    {
        $owner = '0197f0e0-0000-7000-8000-000000000896';
        $cluster = '0197f0e0-0000-7000-8000-000000000897';
        $facility = '0197f0e0-0000-7000-8000-000000000898';
        $this->app->make(BootstrapOperationsOffice::class)->bootstrap($owner, $cluster);
        $api = new PlatformSettingsApi(new PlatformSettingsOwnerPrincipalResolver($owner, $facility), $this->app->make(RbacAbacDecideAccess::class), new PlatformSettingsClusterAncestry($facility, $cluster));
        $response = (new GetCurrentPlatformSettingsController($api, $this->app->make(PlatformSettingsHandler::class)))($this->request('GET', '/platform-settings/current'));

        $this->assertSame(200, $response->getStatusCode());
    }

    private function current(string $authorization): JsonResponse
    {
        return (new GetCurrentPlatformSettingsController($this->api($authorization), $this->app->make(PlatformSettingsHandler::class)))(
            $this->request('GET', '/platform-settings/current', $authorization),
        );
    }

    private function createVersion(): JsonResponse
    {
        $request = $this->request('POST', '/platform-settings/versions');
        $request->headers->set('Idempotency-Key', 'create-settings-version');

        return (new CreateSettingsVersionController($this->api(), $this->app->make(PlatformSettingsHandler::class)))(
            $request,
        );
    }

    private function updateValue(string $versionId, string $key, mixed $value, string $etag): JsonResponse
    {
        $request = $this->request('PUT', "/platform-settings/versions/{$versionId}/settings/{$key}");
        $request->headers->set('If-Match', $etag);
        $request->merge(['value' => $value]);

        return (new UpdateSettingsValueController($this->api(), $this->app->make(PlatformSettingsHandler::class)))($request, $versionId, $key);
    }

    private function api(string $authorization = 'allow'): PlatformSettingsApi
    {
        return new PlatformSettingsApi(new PlatformSettingsHttpPrincipalResolver, new PlatformSettingsHttpDecider($authorization === 'deny'));
    }

    private function request(string $method, string $uri, string $authorization = 'allow'): Request
    {
        $request = Request::create($uri, $method);
        $request->headers->set('X-Correlation-ID', self::CORRELATION_ID);
        $request->headers->set('Authorization', $authorization);

        return $request;
    }
}

final class PlatformSettingsHttpPrincipalResolver implements ResolveDevelopmentFixturePrincipal
{
    public function issue(array $principal): array
    {
        return ['access_token' => 'test', 'expires_at' => now()->addHour()->toIso8601String()];
    }

    public function resolve(Request $request): ?array
    {
        return $request->header('Authorization') === 'missing' ? null : ['user_id' => '0197f0e0-0000-7000-8000-000000000811', 'facility_id' => '0197f0e0-0000-7000-8000-000000000812'];
    }
}

final class PlatformSettingsHttpDecider implements DecideAccess
{
    public function __construct(private readonly bool $deny) {}

    /**
     * Test doubles persist nothing, so the read-side evaluation IS decide().
     */
    public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return $this->decide($actor, $capability, $facts);
    }

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return new AccessDecision(
            $this->deny ? 'deny' : 'allow',
            $capability,
            ($facts === null ? 'platform_settings' : $facts->resourceType),
            [],
            'test',
            'test',
            'internal',
            allowedActions: $this->deny ? [] : ['create', 'update', 'validate', 'publish'],
        );
    }
}

final class PlatformSettingsScopeDecider implements DecideAccess
{
    public function __construct(private readonly ?string $blockedFacility, private readonly bool $denyOverride = false) {}

    /**
     * Test doubles persist nothing, so the read-side evaluation IS decide().
     */
    public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return $this->decide($actor, $capability, $facts);
    }

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        $denied = ($this->blockedFacility !== null && $facts?->ownerFacilityId === $this->blockedFacility)
            || ($this->denyOverride && $capability === 'platform_settings.calendar.override_official_holiday');

        return new AccessDecision($denied ? 'deny' : 'allow', $capability, $facts === null ? 'business_calendar' : $facts->resourceType, [], 'test', 'test', 'internal');
    }
}

final class PlatformSettingsScopeAncestry implements ResolveOrganizationScopeAncestry
{
    public function __construct(private readonly string $facilityId) {}

    public function ancestry(string $scopeType, string $scopeId): ?array
    {
        return $scopeType === 'facility' && $scopeId === $this->facilityId ? ['cluster_id' => '0197f0e0-0000-7000-8000-000000000895', 'facility_id' => $scopeId, 'unit_id' => null] : null;
    }

    public function facilityClusterIds(array $facilityIds): array
    {
        return array_fill_keys($facilityIds, '0197f0e0-0000-7000-8000-000000000895');
    }
}

final class PlatformSettingsOwnerPrincipalResolver implements ResolveDevelopmentFixturePrincipal
{
    public function __construct(private readonly string $owner, private readonly string $facility) {}

    public function issue(array $principal): array
    {
        return ['access_token' => 'test', 'expires_at' => now()->addHour()->toIso8601String()];
    }

    public function resolve(Request $request): array
    {
        return ['user_id' => $this->owner, 'facility_id' => $this->facility];
    }
}

final class PlatformSettingsClusterAncestry implements ResolveOrganizationScopeAncestry
{
    public function __construct(private readonly string $facility, private readonly string $cluster) {}

    public function ancestry(string $scopeType, string $scopeId): ?array
    {
        return $scopeType === 'facility' && $scopeId === $this->facility ? ['cluster_id' => $this->cluster, 'facility_id' => $this->facility, 'unit_id' => null] : null;
    }

    public function facilityClusterIds(array $facilityIds): array
    {
        return array_fill_keys($facilityIds, $this->cluster);
    }
}
