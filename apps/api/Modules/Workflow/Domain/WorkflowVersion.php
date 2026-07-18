<?php

namespace Modules\Workflow\Domain;

use InvalidArgumentException;

final readonly class WorkflowVersion
{
    /** @param array<string, mixed> $graph */
    public function __construct(
        public string $id,
        public string $definitionId,
        public int $versionNumber,
        public string $state,
        public array $graph,
        public string $graphHash,
    ) {}

    /** @param array<string, mixed> $graph */
    public static function published(string $id, string $definitionId, int $versionNumber, array $graph): self
    {
        if ($graph === []) {
            throw new InvalidArgumentException('A workflow graph cannot be empty.');
        }

        $hash = hash('sha256', json_encode($graph, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return new self($id, $definitionId, $versionNumber, 'published', $graph, $hash);
    }
}
