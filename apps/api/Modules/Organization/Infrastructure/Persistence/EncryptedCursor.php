<?php

namespace Modules\Organization\Infrastructure\Persistence;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * Encrypts a JSON-serialised cursor payload. Cursor shape validation lives
 * with the owning handler because each site uses a different field set with
 * different equality rules — pushing that into one helper would force flag
 * parameters and obscure the wire format from the caller's reader.
 */
final class EncryptedCursor
{
    /** @var int phpstan/pod whole-file constant. */
    private const DEFAULT_DEPTH = 8;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function encrypt(array $payload): string
    {
        return Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Decrypts a cursor envelope and returns the decoded payload, or null
     * when the cipher fails to decrypt or the JSON is malformed. The caller
     * is responsible for the typed shape check and for raising its own
     * resource-specific InvalidArgumentException.
     *
     * @return array<string, mixed>|null
     */
    public function tryDecrypt(string $cipher, int $depth = self::DEFAULT_DEPTH): ?array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode(Crypt::decryptString($cipher), true, $depth, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
