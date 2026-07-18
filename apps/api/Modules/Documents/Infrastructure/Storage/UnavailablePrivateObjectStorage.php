<?php

namespace Modules\Documents\Infrastructure\Storage;

use Modules\Documents\Application\QuarantineObjectReference;
use Modules\Documents\Application\QuarantineUploadRequest;
use Modules\Documents\Application\SignedUploadIntent;
use Modules\Documents\Application\StoredObjectProperties;
use Modules\Documents\Application\VerifiedQuarantineObject;
use Modules\Documents\Contracts\PrivateObjectStorage;
use RuntimeException;

/**
 * Explicit production fail-closed adapter. The installed Flysystem package has no
 * S3 driver/client, so it cannot safely enforce conditional PUT, signed headers,
 * temporary URLs, metadata inspection, or generation-bound promotion.
 */
final class UnavailablePrivateObjectStorage implements PrivateObjectStorage
{
    public function issueQuarantineUpload(QuarantineUploadRequest $request): SignedUploadIntent
    {
        $this->unavailable();
    }

    public function inspectQuarantineObject(QuarantineObjectReference $reference): StoredObjectProperties
    {
        $this->unavailable();
    }

    public function promoteVerifiedObject(VerifiedQuarantineObject $object): StoredObjectProperties
    {
        $this->unavailable();
    }

    private function unavailable(): never
    {
        throw new RuntimeException('documents_private_storage_unavailable');
    }
}
