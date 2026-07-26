<?php

namespace Modules\Workflow\Tests;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Modules\Workflow\Features\PublishWorkflowVersion\Handler\PublishWorkflowVersionHandler;
use PDO;
use RuntimeException;
use Shared\Contracts\TransactionalOutbox;
use Tests\TestCase;
use Throwable;

final class WorkflowVersionMySqlConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private const ACTOR_ID = '018f6f7d-0c00-7000-8000-000000000991';

    private const DEFINITION_ID = '018f6f7d-0c00-7000-8000-000000000992';

    private const CODE = 'mysql-concurrent-workflow';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the explicit MySQL integration lane.');
        }

        DB::table('workflow_definitions')->insert([
            'id' => self::DEFINITION_ID,
            'code' => self::CODE,
            'source_record_type' => 'work_records',
            'created_by_user_id' => self::ACTOR_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_concurrent_publishers_serialize_version_allocation_on_the_definition_row(): void
    {
        $locker = $this->separateMySqlConnection();
        $locker->beginTransaction();
        $statement = $locker->prepare('SELECT id FROM workflow_definitions WHERE id = ? FOR UPDATE');
        $statement->execute([self::DEFINITION_ID]);
        $this->assertSame(self::DEFINITION_ID, $statement->fetchColumn());

        DB::disconnect();
        $workers = [$this->spawnPublisher(), $this->spawnPublisher()];
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
        $this->assertSame(2, DB::table('workflow_versions')->where('workflow_definition_id', self::DEFINITION_ID)->count());
        $this->assertSame(2, DB::table('outbox_events')->where('event_type', 'workflow.version.published.v1')->count());
    }

    /** @return array{pid: int, stream: resource} */
    private function spawnPublisher(): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new RuntimeException('Unable to create the workflow concurrency worker socket.');
        }
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork the workflow concurrency worker.');
        }
        if ($pid === 0) {
            fclose($sockets[0]);
            fgets($sockets[1]);
            DB::purge();

            try {
                $result = (new PublishWorkflowVersionHandler(
                    $this->app->make(TransactionalOutbox::class),
                ))->publish(self::CODE, 'work_records', self::ACTOR_ID, [
                    'nodes' => [['key' => 'review', 'type' => 'work_item']],
                ]);
                $payload = ['version_number' => $result['version_number'], 'error' => null];
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
