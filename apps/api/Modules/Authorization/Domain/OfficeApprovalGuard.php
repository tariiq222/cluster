<?php

namespace Modules\Authorization\Domain;

use Modules\Authorization\Contracts\CountOperationsOfficeMembers;

/**
 * Static helper that decides whether the author of a workflow version may also
 * approve it inside the operations office.
 *
 * The decision is data-driven by the active office membership size — never by
 * an `is_super` short-circuit. The four possible outcomes are:
 *
 *  - `multi-member-allowed`: a different active office member is the approver.
 *  - `single-member-bootstrap-allowed`: a lone bootstrap member authored and
 *    is also approving. The exception is flagged at the call site so the
 *    decision row and notification are explicitly marked.
 *  - `self-approval-forbidden`: two or more active members exist, and the
 *    approver is the same person who authored the version.
 *  - `office-empty`: no active office member exists; the version cannot be
 *    approved at all and the caller must surface a bootstrap error.
 */
final class OfficeApprovalGuard
{
    public const CODE_MULTI_MEMBER_ALLOWED = 'multi-member-allowed';

    public const CODE_SINGLE_MEMBER_BOOTSTRAP_ALLOWED = 'single-member-bootstrap-allowed';

    public const CODE_SELF_APPROVAL_FORBIDDEN = 'self-approval-forbidden';

    public const CODE_OFFICE_EMPTY = 'office-empty';

    private function __construct() {}

    /**
     * @return array{allow: bool, code: string}
     */
    public static function canApproveAfterAuthoring(
        string $authorUserId,
        string $approverUserId,
        CountOperationsOfficeMembers $counter,
    ): array {
        UuidV7::assert($authorUserId, 'OfficeApprovalGuard author user id');
        UuidV7::assert($approverUserId, 'OfficeApprovalGuard approver user id');

        if ($authorUserId !== $approverUserId) {
            return ['allow' => true, 'code' => self::CODE_MULTI_MEMBER_ALLOWED];
        }

        $activeMembers = $counter->activeMembers();

        if ($activeMembers === 0) {
            return ['allow' => false, 'code' => self::CODE_OFFICE_EMPTY];
        }

        if ($activeMembers === 1) {
            return ['allow' => true, 'code' => self::CODE_SINGLE_MEMBER_BOOTSTRAP_ALLOWED];
        }

        return ['allow' => false, 'code' => self::CODE_SELF_APPROVAL_FORBIDDEN];
    }
}
