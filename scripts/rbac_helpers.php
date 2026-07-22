<?php

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function collect_rows(array $rows, string $tag): array
{
    return array_values(array_filter($rows, fn($row) => ($row['endpoint_tag'] ?? null) === $tag));
}
