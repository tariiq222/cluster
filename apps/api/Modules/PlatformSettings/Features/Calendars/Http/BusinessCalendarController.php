<?php

namespace Modules\PlatformSettings\Features\Calendars\Http;

use App\Integrations\PlatformSettings\PlatformSettingsApi;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\PlatformSettings\Domain\CalendarException;
use Modules\PlatformSettings\Domain\CalendarScope;
use Modules\PlatformSettings\Domain\WorkingWeek;
use Modules\PlatformSettings\Features\Calendars\Handler\BusinessCalendarHandler;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Throwable;

final class BusinessCalendarController
{
    public function __construct(private readonly PlatformSettingsApi $api, private readonly BusinessCalendarHandler $calendars) {}

    public function index(Request $request): JsonResponse
    {
        $context = $this->api->authorize($request, 'platform_settings.calendar.read', $this->api->facts('business_calendar'));
        if ($context instanceof JsonResponse) {
            return $context;
        }
        $items = DB::table('business_calendars')->orderBy('created_at')->get()->map(fn (object $row) => $this->present($row, $context['decision']->allowedActions))->all();

        return $this->api->response(['items' => $items], 200, $context['correlation_id']);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $scope = CalendarScope::from((string) $request->input('scope_type'), (string) $request->input('scope_id'));
            $context = $this->api->authorize($request, 'platform_settings.calendar.manage', $this->api->facts('business_calendar', null, null, null, $scope->type, $scope->id));
            if ($context instanceof JsonResponse) {
                return $context;
            }
            if ($this->api->idempotencyKey($request) === null) {
                return $this->api->problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $context['correlation_id']);
            }
            $id = Str::uuid7()->toString();
            DB::table('business_calendars')->insert(['id' => $id, 'scope_type' => $scope->type, 'scope_id' => $scope->id, 'parent_calendar_id' => null, 'status' => 'draft', 'timezone' => 'Asia/Riyadh', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);

            return $this->api->response($this->present(DB::table('business_calendars')->where('id', $id)->first(), $context['decision']->allowedActions), 201, $context['correlation_id'], 1);
        } catch (Throwable $exception) {
            return $this->api->exception($exception, $this->api->correlationId($request) ?? '');
        }
    }

    public function setWeekday(Request $request, string $calendarId, int $weekday): JsonResponse
    {
        return $this->mutate($request, $calendarId, 'platform_settings.calendar.manage', function (array $context) use ($request, $calendarId, $weekday): JsonResponse {
            if ($this->api->ifMatch($request) === null) {
                return $this->api->problem(412, 'precondition-required', 'Precondition Failed', 'If-Match is required.', $context['correlation_id']);
            }
            $this->advanceLockVersion($calendarId, (int) $this->api->ifMatch($request));
            $this->calendars->setWeekday($calendarId, WorkingWeek::forDay($weekday, (bool) $request->input('is_working_day'), $request->input('starts_at'), $request->input('ends_at')));

            return $this->calendarResponse($calendarId, $context);
        });
    }

    public function setException(Request $request, string $calendarId, string $date): JsonResponse
    {
        return $this->mutate($request, $calendarId, 'platform_settings.calendar.manage', function (array $context) use ($request, $calendarId, $date): JsonResponse {
            if ($this->api->ifMatch($request) === null) {
                return $this->api->problem(412, 'precondition-required', 'Precondition Failed', 'If-Match is required.', $context['correlation_id']);
            }
            $exception = CalendarException::forRange((string) $request->input('type'), new DateTimeImmutable($date), $request->filled('ends_on') ? new DateTimeImmutable((string) $request->input('ends_on')) : null, (bool) $request->input('is_working_day'), $request->input('starts_at'), $request->input('ends_at'), $request->input('reason'));
            $overrideDecision = [];
            if ($exception->requiresOfficialHolidayOverrideCapability()) {
                $calendar = DB::table('business_calendars')->where('id', $calendarId)->first();
                if ($calendar === null) {
                    throw new NotFoundHttpException('Business calendar was not found.');
                }
                $override = $this->api->authorize($request, 'platform_settings.calendar.override_official_holiday', $this->api->facts('business_calendar', $calendarId, null, null, (string) $calendar->scope_type, (string) $calendar->scope_id));
                if ($override instanceof JsonResponse) {
                    return $override;
                }
                $overrideDecision = ['capability' => 'platform_settings.calendar.override_official_holiday', 'allowed' => $override['decision']->isAllowed()];
            }
            $this->advanceLockVersion($calendarId, (int) $this->api->ifMatch($request));
            $this->calendars->setException($calendarId, $exception, $overrideDecision);

            return $this->calendarResponse($calendarId, $context);
        });
    }

    public function publish(Request $request, string $calendarId): JsonResponse
    {
        return $this->mutate($request, $calendarId, 'platform_settings.calendar.manage', function (array $context) use ($request, $calendarId): JsonResponse {
            $etag = $this->api->ifMatch($request);
            if ($etag === null) {
                return $this->api->problem(412, 'precondition-required', 'Precondition Failed', 'If-Match is required.', $context['correlation_id']);
            }
            $updated = DB::table('business_calendars')->where('id', $calendarId)->where('lock_version', $etag)->where('status', 'draft')->update(['status' => 'published', 'lock_version' => $etag + 1, 'updated_at' => now()]);
            if ($updated !== 1) {
                return $this->api->problem(412, 'precondition-failed', 'Precondition Failed', 'If-Match does not match the current calendar.', $context['correlation_id']);
            }

            return $this->calendarResponse($calendarId, $context);
        });
    }

    private function mutate(Request $request, string $calendarId, string $capability, \Closure $callback): JsonResponse
    {
        $calendar = DB::table('business_calendars')->where('id', $calendarId)->first();
        if ($calendar === null) {
            return $this->api->problem(404, 'resource-not-found', 'Not Found', 'Business calendar was not found.', $this->api->correlationId($request));
        }
        $context = $this->api->authorize($request, $capability, $this->api->facts('business_calendar', $calendarId, null, null, (string) $calendar->scope_type, (string) $calendar->scope_id));
        if ($context instanceof JsonResponse) {
            return $context;
        }
        try {
            return DB::transaction(fn (): JsonResponse => $callback($context));
        } catch (Throwable $exception) {
            return $this->api->exception($exception, $context['correlation_id']);
        }
    }

    private function calendarResponse(string $id, array $context): JsonResponse
    {
        $calendar = DB::table('business_calendars')->where('id', $id)->first();
        if ($calendar === null) {
            throw new NotFoundHttpException('Business calendar was not found.');
        }

        return $this->api->response($this->present($calendar, $context['decision']->allowedActions), 200, $context['correlation_id'], (int) $calendar->lock_version);
    }

    private function advanceLockVersion(string $calendarId, int $expectedLockVersion): void
    {
        $updated = DB::table('business_calendars')->where('id', $calendarId)->where('lock_version', $expectedLockVersion)->update(['lock_version' => $expectedLockVersion + 1, 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new PreconditionFailedHttpException('If-Match does not match the current calendar.');
        }
    }

    private function present(object $calendar, array $allowedActions): array
    {
        return ['id' => $calendar->id, 'scope_type' => $calendar->scope_type, 'scope_id' => $calendar->scope_id, 'status' => $calendar->status, 'timezone' => $calendar->timezone, 'lock_version' => (int) $calendar->lock_version, 'allowed_actions' => $allowedActions];
    }
}
