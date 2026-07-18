<?php

namespace Modules\Documents\Application;

use RuntimeException;

/** Signals an unavailable storage dependency; callers must leave content quarantined and retry. */
final class RetryableStorageException extends RuntimeException {}
