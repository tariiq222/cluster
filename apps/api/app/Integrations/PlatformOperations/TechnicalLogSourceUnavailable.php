<?php

namespace App\Integrations\PlatformOperations;

use RuntimeException;

/**
 * Thrown by `UnavailableTechnicalLogSource` to signal that the
 * technical-logs surface is deferred. The technical-logs controller maps
 * this exception to a 503 problem document; it never leaks through the
 * API as a 500.
 */
final class TechnicalLogSourceUnavailable extends RuntimeException {}
