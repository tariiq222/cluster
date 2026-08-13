<?php

declare(strict_types=1);

namespace Modules\Identity\Contracts;

/**
 * Reads the Identity-owned display-label projection for a batch of accounts.
 * The result is presentation data only; it never grants access.
 */
interface ListUserDisplayLabels
{
    /**
     * @param  list<string>  $userIds
     * @return array<string, string>
     */
    public function labelsFor(array $userIds): array;
}
