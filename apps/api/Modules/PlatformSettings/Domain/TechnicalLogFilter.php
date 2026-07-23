<?php

namespace Modules\PlatformSettings\Domain;

use InvalidArgumentException;

final readonly class TechnicalLogFilter
{
    public function __construct(
        public ?string $category = null,
        public ?string $source = null,
        public ?string $correlationId = null,
        public ?string $cursor = null,
        public int $perPage = 50,
    ) {
        if ($category !== null && ! in_array($category, ['audit', 'security', 'system', 'operations'], true)) {
            throw new InvalidArgumentException('Technical log category is invalid.');
        }
        if ($perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('Technical log page size must be between 1 and 100.');
        }
    }

    public function withoutCursor(int $perPage = 100): self
    {
        return new self($this->category, $this->source, $this->correlationId, null, $perPage);
    }

    public function withCursor(?string $cursor): self
    {
        return new self($this->category, $this->source, $this->correlationId, $cursor, $this->perPage);
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'category' => $this->category,
            'source' => $this->source,
            'correlation_id' => $this->correlationId,
        ], JSON_THROW_ON_ERROR));
    }
}
