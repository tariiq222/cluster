<?php

namespace Modules\Documents\Infrastructure\Security;

use RuntimeException;

/** Raised when the ClamAV transport cannot be reached, framed, or read. */
final class ClamAvTransportException extends RuntimeException {}
