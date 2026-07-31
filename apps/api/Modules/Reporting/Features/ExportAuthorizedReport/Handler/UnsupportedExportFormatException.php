<?php

declare(strict_types=1);

namespace Modules\Reporting\Features\ExportAuthorizedReport\Handler;

use InvalidArgumentException;

/**
 * Raised when an export is requested in a format the module can honestly
 * produce. The HTTP layer surfaces this as a 422 `unsupported-export-format`
 * problem; only csv and json are supported.
 */
final class UnsupportedExportFormatException extends InvalidArgumentException
{
    public function __construct(string $format)
    {
        parent::__construct(sprintf('Unsupported export format: %s', $format));
    }
}
