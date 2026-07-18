<?php

namespace Modules\Workflow\Features\PublishWorkflowVersion\Handler;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Workflow\Domain\WorkflowVersion;
use Shared\Contracts\TransactionalOutbox;

final class PublishWorkflowVersionHandler
{
    public function __construct(private readonly TransactionalOutbox $outbox) {}

    /** @param array<string, mixed> $graph @return array<string, mixed> */
    public function publish(string $code, string $sourceRecordType, string $actorUserId, array $graph): array
    {
        return DB::transaction(function () use ($code, $sourceRecordType, $actorUserId, $graph): array {
            $definition = DB::table('workflow_definitions')->where('code', $code)->first();
            $definitionId = $definition === null ? Str::uuid7()->toString() : (string) $definition->id;
            if ($definition === null) {
                DB::table('workflow_definitions')->insert([
                    'id' => $definitionId,
                    'code' => $code,
                    'source_record_type' => $sourceRecordType,
                    'created_by_user_id' => $actorUserId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $versionNumber = ((int) DB::table('workflow_versions')->where('workflow_definition_id', $definitionId)->max('version_number')) + 1;
            $version = WorkflowVersion::published(Str::uuid7()->toString(), $definitionId, $versionNumber, $graph);
            $publishedAt = now();
            DB::table('workflow_versions')->insert([
                'id' => $version->id,
                'workflow_definition_id' => $version->definitionId,
                'version_number' => $version->versionNumber,
                'definition_state' => $version->state,
                'graph_document' => json_encode($version->graph, JSON_THROW_ON_ERROR),
                'graph_hash' => $version->graphHash,
                'dsl_version' => '1',
                'published_at' => $publishedAt,
                'created_at' => $publishedAt,
                'updated_at' => $publishedAt,
            ]);
            $this->outbox->append(
                Str::uuid7()->toString(),
                $version->id,
                'workflow.version.published.v1',
                ['workflow_version_id' => $version->id, 'workflow_definition_id' => $definitionId, 'version_number' => $versionNumber],
            );

            return [
                'id' => $version->id,
                'workflow_definition_id' => $definitionId,
                'version_number' => $versionNumber,
                'definition_state' => 'published',
                'graph_document' => $graph,
                'graph_hash' => $version->graphHash,
            ];
        });
    }
}
