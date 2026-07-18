<?php

namespace Modules\Documents\Contracts;

use Modules\Documents\Application\QuarantineObjectReference;
use Modules\Documents\Application\QuarantineUploadRequest;
use Modules\Documents\Application\SignedUploadIntent;
use Modules\Documents\Application\StoredObjectProperties;
use Modules\Documents\Application\VerifiedQuarantineObject;

/**
 * S3-compatible private-storage boundary. Implementations keep object keys internal;
 * callers receive only an opaque one-shot signed upload intent and never a storage
 * key. Implementations must sign exact Content-Length, checksum, Content-Type, and
 * If-None-Match: * conditions, and must bind inspect/promotion to ETag + generation.
 */
interface PrivateObjectStorage
{
    public function issueQuarantineUpload(QuarantineUploadRequest $request): SignedUploadIntent;

    public function inspectQuarantineObject(QuarantineObjectReference $reference): StoredObjectProperties;

    /** Idempotently promotes exactly the verified source generation after commit. */
    public function promoteVerifiedObject(VerifiedQuarantineObject $object): StoredObjectProperties;
}
