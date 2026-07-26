<?php

declare(strict_types=1);

namespace Shared\Http;

/**
 * Outcome of {@see StrongEtag::parse()}.
 *
 * The contract distinguishes three failure modes so callers (middleware,
 * controllers, tests) can map a rejection onto the correct response code
 * (400 vs 412) without re-parsing the input:
 *
 *  - `empty`     — the header was missing, `null`, or whitespace-only.
 *  - `weak`      — the header carried the `W/` opaque-tag prefix that RFC 9110
 *                  marks as a weak validator; this codebase requires strong
 *                  ETags only.
 *  - `malformed` — the value was not a recognised strong tag (bad quoting,
 *                  non-positive version, unsupported opaqueness value, …).
 */
final class StrongEtagResult
{
    public function __construct(
        public readonly bool $valid,
        public readonly ?int $version = null,
        public readonly ?string $etag = null,
        public readonly ?string $failure = null,
    ) {}

    /**
     * Convenience constructor for a successful integer-version parse.
     */
    public static function forVersion(int $version): self
    {
        return new self(valid: true, version: $version, etag: null, failure: null);
    }

    /**
     * Convenience constructor for a successful UUID/opaque parse.
     */
    public static function forEtag(string $etag): self
    {
        return new self(valid: true, version: null, etag: $etag, failure: null);
    }

    /**
     * Convenience constructor for a documented rejection.
     */
    public static function rejected(string $failure): self
    {
        return new self(valid: false, version: null, etag: null, failure: $failure);
    }
}
