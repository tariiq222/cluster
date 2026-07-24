<?php

namespace App\Integrations\PlatformOperations;

use InvalidArgumentException;
use Modules\PlatformSettings\Contracts\TechnicalLogSource;
use Modules\PlatformSettings\Domain\TechnicalLogEntry;
use Modules\PlatformSettings\Domain\TechnicalLogFilter;
use Modules\PlatformSettings\Domain\TechnicalLogPage;

final readonly class CompositeTechnicalLogSource implements TechnicalLogSource
{
    public function isAvailable(): bool
    {
        return true;
    }

    /** @param list<TechnicalLogSource> $sources */
    public function __construct(private array $sources, private string $cursorSigningKey)
    {
        if ($cursorSigningKey === '') {
            throw new InvalidArgumentException('Technical log cursor signing key is required.');
        }
    }

    public function search(TechnicalLogFilter $filter): TechnicalLogPage
    {
        $entries = [];
        foreach ($this->sources as $source) {
            $sourceFilter = $filter->withoutCursor();
            $seenCursors = [];
            do {
                $page = $source->search($sourceFilter);
                foreach ($page->entries as $entry) {
                    if ($this->matches($entry, $filter)) {
                        $entries[] = $entry;
                    }
                }
                if ($page->nextCursor !== null && isset($seenCursors[$page->nextCursor])) {
                    throw new InvalidArgumentException('Technical log source cursor did not advance.');
                }
                if ($page->nextCursor !== null) {
                    $seenCursors[$page->nextCursor] = true;
                }
                $sourceFilter = $sourceFilter->withCursor($page->nextCursor);
            } while ($page->nextCursor !== null);
        }

        usort($entries, static function (TechnicalLogEntry $left, TechnicalLogEntry $right): int {
            return $left->occurredAt <=> $right->occurredAt ?: $left->id <=> $right->id;
        });

        $offset = $this->decodeOffset($filter);
        $pageEntries = array_slice($entries, $offset, $filter->perPage);
        $nextOffset = $offset + count($pageEntries);

        return new TechnicalLogPage(
            $pageEntries,
            $nextOffset < count($entries) ? $this->encodeCursor($nextOffset, $filter) : null,
        );
    }

    private function matches(TechnicalLogEntry $entry, TechnicalLogFilter $filter): bool
    {
        return ($filter->category === null || $entry->category === $filter->category)
            && ($filter->source === null || $entry->source === $filter->source)
            && ($filter->correlationId === null || $entry->correlationId === $filter->correlationId);
    }

    private function encodeCursor(int $offset, TechnicalLogFilter $filter): string
    {
        $payload = json_encode(['offset' => $offset, 'filter' => $filter->fingerprint()], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $payload, $this->cursorSigningKey);

        return rtrim(strtr(base64_encode($payload.'.'.$signature), '+/', '-_'), '=');
    }

    private function decodeOffset(TechnicalLogFilter $filter): int
    {
        if ($filter->cursor === null) {
            return 0;
        }
        $encoded = strtr($filter->cursor, '-_', '+/');
        $decoded = base64_decode($encoded.str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
        if (! is_string($decoded) || substr_count($decoded, '.') !== 1) {
            throw new InvalidArgumentException('Technical log cursor is invalid.');
        }
        [$payload, $signature] = explode('.', $decoded, 2);
        $expected = hash_hmac('sha256', $payload, $this->cursorSigningKey);
        if (! hash_equals($expected, $signature)) {
            throw new InvalidArgumentException('Technical log cursor signature is invalid.');
        }
        $data = json_decode($payload, true);
        if (! is_array($data) || ! is_int($data['offset'] ?? null) || $data['offset'] < 0 || ($data['filter'] ?? null) !== $filter->fingerprint()) {
            throw new InvalidArgumentException('Technical log cursor is invalid.');
        }

        return $data['offset'];
    }
}
