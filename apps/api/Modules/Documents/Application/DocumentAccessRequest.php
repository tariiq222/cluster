<?php

namespace Modules\Documents\Application;

use InvalidArgumentException;

final readonly class DocumentAccessRequest
{
    public function __construct(
        public string $principalId,
        public string $facilityId,
        public string $correlationId,
        public ?string $sourceIp = null,
        public ?string $deviceFingerprintHash = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['principal id' => $this->principalId, 'facility id' => $this->facilityId, 'correlation id' => $this->correlationId] as $label => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Document access {$label} is required.");
            }
        }
        if ($this->sourceIp !== null && filter_var($this->sourceIp, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('Document access source IP is invalid.');
        }
        if ($this->deviceFingerprintHash !== null
            && preg_match('/\A[a-f0-9]{64}\z/', $this->deviceFingerprintHash) !== 1) {
            throw new InvalidArgumentException('Document access device fingerprint must be SHA-256.');
        }
    }
}
