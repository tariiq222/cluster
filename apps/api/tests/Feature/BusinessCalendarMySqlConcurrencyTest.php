<?php

namespace Tests\Feature;

use App\Integrations\PlatformSettings\PlatformSettingsApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\PlatformSettings\Features\Calendars\Handler\BusinessCalendarHandler;
use Modules\PlatformSettings\Features\Calendars\Http\BusinessCalendarController;
use Modules\PlatformSettings\Infrastructure\Persistence\DatabaseBusinessCalendars;
use PDO;
use RuntimeException;
use Tests\TestCase;

final class BusinessCalendarMySqlConcurrencyTest extends TestCase
{
    public const ACTOR_ID = '018f6f7d-0c00-7000-8000-000000000941';

    private const CALENDAR_ID = '018f6f7d-0c00-7000-8000-000000000942';

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000943';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the explicit MySQL integration lane.');
        }

        DB::table('business_calendars')->insert([
            'id' => self::CALENDAR_ID,
            'scope_type' => 'platform',
            'scope_id' => 'platform',
            'parent_calendar_id' => null,
            'status' => 'draft',
            'timezone' => 'Asia/Riyadh',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_two_weekday_writers_with_the_same_etag_produce_one_winner_and_one_412(): void
    {
        $locker = $this->separateMySqlConnection();
        $locker->beginTransaction();
        $statement = $locker->prepare('SELECT id FROM business_calendars WHERE id = ? FOR UPDATE');
        $statement->execute([self::CALENDAR_ID]);
        $this->assertSame(self::CALENDAR_ID, $statement->fetchColumn());

        DB::disconnect();
        $workers = [
            $this->spawnWeekdayWorker(1, '08:00', '16:00'),
            $this->spawnWeekdayWorker(2, '09:00', '17:00'),
        ];
        foreach ($workers as $worker) {
            fwrite($worker['stream'], "go\n");
        }
        usleep(200_000);
        $locker->rollBack();

        $statuses = [];
        foreach ($workers as $worker) {
            stream_set_timeout($worker['stream'], 15);
            $payload = stream_get_contents($worker['stream']);
            fclose($worker['stream']);
            pcntl_waitpid($worker['pid'], $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
            $decoded = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
            $statuses[] = $decoded['status'];
        }

        sort($statuses);
        $this->assertSame([200, 412], $statuses);
        DB::reconnect();
        $this->assertSame(2, (int) DB::table('business_calendars')->where('id', self::CALENDAR_ID)->value('lock_version'));
        $this->assertSame(1, DB::table('business_calendar_weekdays')->where('business_calendar_id', self::CALENDAR_ID)->count());
    }

    /** @return array{pid: int, stream: resource} */
    private function spawnWeekdayWorker(int $weekday, string $startsAt, string $endsAt): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new RuntimeException('Unable to create the calendar concurrency worker socket.');
        }
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork the calendar concurrency worker.');
        }
        if ($pid === 0) {
            fclose($sockets[0]);
            fgets($sockets[1]);
            DB::purge();

            $request = Request::create(
                '/platform-settings/calendars/'.self::CALENDAR_ID.'/weekdays/'.$weekday,
                'PUT',
                ['is_working_day' => true, 'starts_at' => $startsAt, 'ends_at' => $endsAt],
            );
            $request->headers->set('X-Correlation-ID', self::CORRELATION_ID);
            $request->headers->set('Authorization', 'allow');
            $request->headers->set('If-Match', '"1"');
            $api = new PlatformSettingsApi(new CalendarConcurrencyPrincipalResolver, new CalendarConcurrencyAccessDecider);
            $controller = new BusinessCalendarController(
                $api,
                new BusinessCalendarHandler(new DatabaseBusinessCalendars(static fn (): ?array => null)),
            );
            $response = $controller->setWeekday($request, self::CALENDAR_ID, $weekday);

            fwrite($sockets[1], json_encode(['status' => $response->getStatusCode()], JSON_THROW_ON_ERROR));
            fclose($sockets[1]);
            exit(0);
        }

        fclose($sockets[1]);

        return ['pid' => $pid, 'stream' => $sockets[0]];
    }

    private function separateMySqlConnection(): PDO
    {
        /** @var array{host: string, port: int|string, database: string, username: string, password: string} $configuration */
        $configuration = config('database.connections.mysql');

        return new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $configuration['host'],
                $configuration['port'],
                $configuration['database'],
            ),
            $configuration['username'],
            $configuration['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }
}

final class CalendarConcurrencyPrincipalResolver implements ResolveDevelopmentFixturePrincipal
{
    public function issue(array $principal): array
    {
        return ['access_token' => 'test', 'expires_at' => now()->addHour()->toIso8601String()];
    }

    public function resolve(Request $request): array
    {
        return ['user_id' => BusinessCalendarMySqlConcurrencyTest::ACTOR_ID, 'facility_id' => '018f6f7d-0c00-7000-8000-000000000944'];
    }
}

final class CalendarConcurrencyAccessDecider implements DecideAccess
{
    /**
     * Test doubles persist nothing, so the read-side evaluation IS decide().
     */
    public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return $this->decide($actor, $capability, $facts);
    }

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return new AccessDecision('allow', $capability, 'business_calendar', ['update'], 'test', 'test', 'internal');
    }
}
