<?php

namespace Modules\Documents\Application;

use InvalidArgumentException;
use Modules\Documents\Domain\UuidV7;

final readonly class IdempotencyContext
{
    public string $keyHash;

    public function __construct(
        public string $principalId,
        public string $operation,
        string $key,
        public string $requestHash,
    ) {
        UuidV7::assert($this->principalId, 'Idempotency principal id');
        if (trim($this->operation) === '' || mb_strlen($this->operation) > 96) {
            throw new InvalidArgumentException('Idempotency operation is invalid.');
        }
        if ($key === '' || mb_strlen($key) > 255) {
            throw new InvalidArgumentException('Idempotency key is invalid.');
        }
        if (preg_match('/\A[a-f0-9]{64}\z/', $this->requestHash) !== 1) {
            throw new InvalidArgumentException('Idempotency request hash must be SHA-256.');
        }
        $this->keyHash = hash('sha256', $key);
    }
}
