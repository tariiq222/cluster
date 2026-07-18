<?php

namespace Modules\Documents\Domain;

enum DocumentVersionAvailabilityStatus: string
{
    case Uploading = 'uploading';
    case Quarantined = 'quarantined';
    case PromotionPending = 'promotion_pending';
    case Available = 'available';
    case Rejected = 'rejected';
    case Missing = 'missing';
}
