<?php

declare(strict_types=1);

namespace Shared\Http;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use JsonException;

/**
 * Authenticated, self-binding pagination cursor for Shared HTTP.
 *
 * Every cursor encrypts a fixed-shape version 1 payload with
 * {@see Crypt::encryptString()} and binds it to an explicit resource key
 * plus a SHA-256 digest over the caller-supplied binding data. The codec
 * is intentionally module-agnostic: the resource key is opaque to this
 * class, and the binding is treated as an arbitrary associative array
 * whose object/map keys are canonicalized recursively before hashing so
 * key order does not change the digest.
 *
 * The payload wire format is stable:
 *
 *   {
 *       "v": 1,                       // payload version (int)
 *       "r": "<resource key>",        // opaque resource identifier
 *       "b": "<sha256 hex digest>",   // canonicalized binding digest
 *       "s": [...],                   // exact sort tuple (list order preserved)
 *   }
 *
 * Any deviation from this shape — missing keys, extra keys, wrong
 * version, wrong resource key, wrong binding digest, malformed JSON, or
 * a decryption failure — collapses to a single
 * {@see InvalidArgumentException} whose message is the literal
 * `"The pagination cursor is invalid."` string. The exception never
 * echoes decrypted payload contents, binding data, or the cursor string.
 *
 * The class is stateless and safe to inject as a singleton; it carries
 * no module-specific dependencies and no per-call configuration.
 */
final class AuthenticatedCursorCodec
{
    /**
     * Stable exception message returned for every failure mode. The
     * exact text is part of the contract: callers map it to a 400
     * response without inspecting the cause, and the message must
     * never contain the cursor, the decrypted payload, or any binding
     * data.
     */
    public const INVALID_CURSOR_MESSAGE = 'The pagination cursor is invalid.';

    /**
     * Supported payload version. Bumping this constant is the only
     * supported way to invalidate previously issued cursors; the decode
     * path rejects any other value.
     */
    private const PAYLOAD_VERSION = 1;

    public function encode(string $resourceKey, mixed $sortTuple, mixed $binding): string
    {
        if (trim($resourceKey) === '' || ! is_array($sortTuple) || ! is_array($binding)) {
            throw new InvalidArgumentException(self::INVALID_CURSOR_MESSAGE);
        }

        // Pre-validate that the caller's data is JSON-encodable before
        // touching Crypt. A failure here is a programmer error (the
        // caller passed a resource or value that cannot survive JSON),
        // so we surface the same opaque message rather than leak the
        // offending value.
        $this->assertJsonEncodable($sortTuple, 'sort tuple');
        $this->assertJsonEncodable($binding, 'binding');

        $payload = [
            'v' => self::PAYLOAD_VERSION,
            'r' => $resourceKey,
            'b' => hash('sha256', $this->canonicalize($binding)),
            's' => $sortTuple,
        ];

        try {
            $serialized = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException(self::INVALID_CURSOR_MESSAGE);
        }

        return Crypt::encryptString($serialized);
    }

    /**
     * @return array<int, mixed> The exact sort tuple that was supplied to
     *                           {@see encode()}. List order is preserved
     *                           and every element is returned verbatim —
     *                           the caller receives the same array shape
     *                           it bound, no canonicalization, no
     *                           reordering.
     */
    public function decode(string $cursor, string $expectedResourceKey, mixed $expectedBinding): array
    {
        if ($cursor === '' || trim($expectedResourceKey) === '' || ! is_array($expectedBinding)) {
            throw new InvalidArgumentException(self::INVALID_CURSOR_MESSAGE);
        }
        $this->assertJsonEncodable($expectedBinding, 'binding');

        try {
            $serialized = Crypt::decryptString($cursor);
        } catch (DecryptException) {
            throw new InvalidArgumentException(self::INVALID_CURSOR_MESSAGE);
        }

        try {
            $decoded = json_decode($serialized, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException(self::INVALID_CURSOR_MESSAGE);
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException(self::INVALID_CURSOR_MESSAGE);
        }

        // Reject any payload whose top-level keys are not exactly the
        // four documented keys — no extras and no missing keys. JSON
        // object key order is not semantically significant.
        $payloadKeys = array_keys($decoded);
        sort($payloadKeys, SORT_STRING);
        if ($payloadKeys !== ['b', 'r', 's', 'v']) {
            throw new InvalidArgumentException(self::INVALID_CURSOR_MESSAGE);
        }

        $version = $decoded['v'];
        $resource = $decoded['r'];
        $digest = $decoded['b'];
        $sort = $decoded['s'];

        if (! is_int($version) || $version !== self::PAYLOAD_VERSION) {
            throw new InvalidArgumentException(self::INVALID_CURSOR_MESSAGE);
        }

        if (! is_string($resource) || $resource === '' || $resource !== $expectedResourceKey) {
            throw new InvalidArgumentException(self::INVALID_CURSOR_MESSAGE);
        }

        if (! is_string($digest) || $digest === '') {
            throw new InvalidArgumentException(self::INVALID_CURSOR_MESSAGE);
        }

        if (! is_array($sort)) {
            throw new InvalidArgumentException(self::INVALID_CURSOR_MESSAGE);
        }

        $expectedDigest = hash('sha256', $this->canonicalize($expectedBinding));

        // hash_equals prevents timing-based digest probing and returns
        // boolean safely; no payload data is ever exposed to the
        // caller on failure.
        if (! hash_equals($expectedDigest, $digest)) {
            throw new InvalidArgumentException(self::INVALID_CURSOR_MESSAGE);
        }

        return $sort;
    }

    /**
     * Recursively canonicalize a binding payload so that the digest is
     * insensitive to object/map key order, while preserving list order
     * for the top-level binding and every nested array. The output is a
     * deterministic JSON string with no extra whitespace — ready to be
     * hashed.
     */
    private function canonicalize(array $value): string
    {
        return (string) json_encode($this->canonicalizeValue($value), JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<mixed>  $value
     * @return array<int|string, mixed>
     */
    private function canonicalizeValue(array $value): array
    {
        // A PHP "list" (zero-indexed, sequential integer keys) keeps
        // its insertion order — the contract for the sort tuple and
        // any other list-shaped data is exact order preservation.
        if (array_is_list($value)) {
            $out = [];
            foreach ($value as $item) {
                $out[] = is_array($item) ? $this->canonicalizeValue($item) : $item;
            }

            return $out;
        }

        // Associative arrays are sorted by key before recursion so the
        // digest is identical regardless of how the caller ordered the
        // keys at the top level or inside any nested map.
        $keys = array_keys($value);
        sort($keys, SORT_STRING);

        $out = [];
        foreach ($keys as $key) {
            $out[$key] = is_array($value[$key]) ? $this->canonicalizeValue($value[$key]) : $value[$key];
        }

        return $out;
    }

    /**
     * Surface a JSON encoding failure as the same opaque cursor
     * exception. We never want to leak the offending value or the
     * encodable-must-be-of-type details; the caller only needs to know
     * that the supplied data cannot be turned into a cursor.
     */
    private function assertJsonEncodable(mixed $value, string $label): void
    {
        try {
            json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException(self::INVALID_CURSOR_MESSAGE);
        }
    }
}
