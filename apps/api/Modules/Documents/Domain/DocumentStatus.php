<?php

namespace Modules\Documents\Domain;

enum DocumentStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
    case Held = 'held';
    case Rejected = 'rejected';
}
