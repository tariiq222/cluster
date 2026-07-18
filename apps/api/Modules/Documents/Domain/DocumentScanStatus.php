<?php

namespace Modules\Documents\Domain;

enum DocumentScanStatus: string
{
    case Pending = 'pending';
    case Scanning = 'scanning';
    case Clean = 'clean';
    case Infected = 'infected';
    case Failed = 'failed';
}
