<?php

declare(strict_types=1);

namespace Shared\Http;

/**
 * Strict parser for the strong `If-Match` ETag values this service emits.
 *
 * The codebase only ever returns strong ETags in one of two shapes:
 *
 *   1. Integer version — `ETag: "1"`, `"42"`, … produced when a resource
 *      carries an explicit optimistic-concurrency counter.
 *   2. UUIDv7 — opaque identifier emitted for resources whose state is
 *      versioned by an RFC 9562 UUIDv7 value.
 *
 * Anything else (weak validators prefixed with `W/`, empty headers,
 * misspelled quotes, non-positive integer versions, etc.) is rejected with
 * a structured {@see StrongEtagResult} so callers can map the failure to
 * a 400 / 412 response without re-parsing the raw input.
 */
final class StrongEtag
{
    /**
     * Quoted integer version literal. The grammar accepts any run of
     * decimal digits inside strong entity-tag quotes; the contract
     * specifies the *numeric* value must be `>= 1`, not the lexical
     * form, so leading zeros are permitted (e.g. `"01"`, `"00042"`).
     * The in-range guarantee is enforced *after* the match — captured
     * digits are stripped of leading zeros and lexically compared
     * against `(string) PHP_INT_MAX` (length first, then `strcmp`) —
     * so the regex itself intentionally imposes no length cap.
     */
    private const VERSION_PATTERN = '/\A"([0-9]+)"\z/';

    /**
     * RFC 9562 UUIDv7: 36 chars, version nibble `7`, RFC 4122 variant bits
     * (`8`/`9`/`a`/`b`) in the high nibble of the 17th hex digit. The UUID
     * is wrapped in a strong entity-tag pair of double-quotes — the same
     * wire form as the integer version literal — and the inner value is
     * captured for `StrongEtagResult::$etag`.
     */
    private const UUIDV7_PATTERN = '/\A"([0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})"\z/i';

    public function parse(?string $header): StrongEtagResult
    {
        if ($header === null) {
            return StrongEtagResult::rejected('empty');
        }

        // Trim only the wrapping whitespace; an internal space would already
        // be a malformed entity-tag, so we leave that decision to the regexes
        // below to keep the failure code honest.
        $trimmed = trim($header);

        if ($trimmed === '') {
            return StrongEtagResult::rejected('empty');
        }

        // Reject weak validators up front — RFC 9110 only allows strong
        // tags for If-Match on state-changing operations in this service.
        if (str_starts_with($trimmed, 'W/')) {
            return StrongEtagResult::rejected('weak');
        }

        // Integer version path: the regex accepts any run of decimal
        // digits, possibly with leading zeros. We strip the leading
        // zeros and lexically compare the remainder against
        // `(string) PHP_INT_MAX` to bound the value before casting. An
        // all-zero stripped result is non-positive (`"0"`, `"00"`, …)
        // and therefore `malformed`.
        if (preg_match(self::VERSION_PATTERN, $trimmed, $matches) === 1) {
            $digits = ltrim($matches[1], '0');
            $maxDigits = (string) PHP_INT_MAX;
            if ($digits === '' || strlen($digits) > strlen($maxDigits)
                || (strlen($digits) === strlen($maxDigits) && strcmp($digits, $maxDigits) > 0)) {
                return StrongEtagResult::rejected('malformed');
            }

            return StrongEtagResult::forVersion((int) $digits);
        }
        // UUIDv7 path: same wire form as the version literal — a strong
        // entity-tag pair of double-quotes wrapping the RFC 9562 UUIDv7.
        // `etag` exposes the captured inner UUID (without the quotes).
        if (preg_match(self::UUIDV7_PATTERN, $trimmed, $matches) === 1) {
            return StrongEtagResult::forEtag($matches[1]);
        }

        return StrongEtagResult::rejected('malformed');
    }
}
