<?php

namespace Modules\Documents\Contracts;

use Modules\Documents\Application\CleanSpreadsheetDocument;
use Modules\Documents\Application\CleanSpreadsheetParseResult;

/**
 * Neutral contract for consumers such as Organization. Documents supplies only
 * a reference to a clean available CSV/XLSX version; object keys stay private.
 */
interface CleanSpreadsheetParser
{
    public function parse(CleanSpreadsheetDocument $document): CleanSpreadsheetParseResult;
}
