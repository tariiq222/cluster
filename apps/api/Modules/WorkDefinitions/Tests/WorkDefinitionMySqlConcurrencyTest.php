<?php

namespace Modules\WorkDefinitions\Tests;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Organization\Contracts\GetDefaultClusterId;
use Modules\WorkDefinitions\Features\Definition\Handler\WorkDefinitionMutator;
use PDO;
use RuntimeException;
use Shared\Contracts\TransactionalOutbox;
use Tests\TestCase;
use Throwable;

final class WorkDefinitionMySqlConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    public const ACTOR_ID = '018f6f7d-0c00-7000-8000-000000000951';

    private const DEFINITION_ID = '018f6f7d-0c00-7000-8000-000000000952';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the explicit MySQL integration lane.');
        }

        DB::table('work_definitions')->insert([
            'id' => self::DEFINITION_ID,
            'code' => 'mysql-concurrent-definition',
            'name' => 'Concurrent version allocation',
            'description' => null,
            'default_classification' => 'internal',
            'created_by_user_id' => self::ACTOR_ID,
            'status' => 'active',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_concurrent_version_allocations_serialize_on_the_parent_and_remain_unique(): void
    {
        $locker = $this->separateMySqlConnection();
        $locker->beginTransaction();
        $statement = $locker->prepare('SELECT id FROM work_definitions WHERE id = ? FOR UPDATE');
        $statement->execute([self::DEFINITION_ID]);
        $this->assertSame(self::DEFINITION_ID, $statement->fetchColumn());

        DB::disconnect();
        $workers = [
            $this->spawnVersionWorker('work-definition-version-a'),
            $this->spawnVersionWorker('work-definition-version-b'),
        ];
        foreach ($workers as $worker) {
            fwrite($worker['stream'], "go\n");
        }
        usleep(200_000);
        $locker->rollBack();

        $versions = [];
        $errors = [];
        foreach ($workers as $worker) {
            stream_set_timeout($worker['stream'], 15);
            $payload = stream_get_contents($worker['stream']);
            fclose($worker['stream']);
            pcntl_waitpid($worker['pid'], $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
            $decoded = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
            if ($decoded['error'] !== null) {
                $errors[] = $decoded['error'];
            } else {
                $versions[] = $decoded['version_number'];
            }
        }

        sort($versions);
        $this->assertSame([], $errors);
        $this->assertSame([1, 2], $versions);
        DB::reconnect();
        $this->assertSame(2, DB::table('work_definition_versions')->where('work_definition_id', self::DEFINITION_ID)->count());
        $this->assertSame(2, DB::table('work_definition_idempotency_keys')->count());
        $this->assertSame(2, DB::table('outbox_events')->where('event_type', 'work_definition.version.created.v1')->count());
    }

    /** @return array{pid: int, stream: resource} */
    private function spawnVersionWorker(string $operation): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new RuntimeException('Unable to create the work-definition concurrency worker socket.');
        }
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork the work-definition concurrency worker.');
        }
        if ($pid === 0) {
            fclose($sockets[0]);
            fgets($sockets[1]);
            DB::purge();

            try {
                $mutator = new WorkDefinitionMutator(
                    $this->app->make(TransactionalOutbox::class),
                    new WorkDefinitionConcurrencyAccessDecider,
                    new WorkDefinitionConcurrencyCluster,
                );
                $result = $mutator->createVersion(
                    self::DEFINITION_ID,
                    ['type' => 'object', 'properties' => []],
                    'work_definition.default',
                    null,
                    ['user_id' => self::ACTOR_ID],
                    hash('sha256', $operation),
                    hash('sha256', $operation.':payload'),
                    $operation,
                );
                $payload = ['version_number' => $result['resource']['version_number'], 'error' => null];
            } catch (Throwable $exception) {
                $payload = ['version_number' => null, 'error' => $exception->getMessage()];
            }

            fwrite($sockets[1], json_encode($payload, JSON_THROW_ON_ERROR));
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

final class WorkDefinitionConcurrencyAccessDecider implements DecideAccess
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
        return new AccessDecision('allow', $capability, 'work_definition', [], 'test', 'test', 'internal');
    }
}

final class WorkDefinitionConcurrencyCluster implements GetDefaultClusterId
{
    public function resolve(): string
    {
        return '018f6f7d-0c00-7000-8000-000000000953';
    }
}
