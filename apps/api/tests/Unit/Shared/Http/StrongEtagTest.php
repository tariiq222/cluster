<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shared\Http\StrongEtag;
use Shared\Http\StrongEtagResult;

#[CoversClass(StrongEtag::class)]
#[CoversClass(StrongEtagResult::class)]
final class StrongEtagTest extends TestCase
{
    private StrongEtag $parser;

    protected function setUp(): void
    {
        $this->parser = new StrongEtag;
    }

    public function test_empty_header_is_rejected_with_empty_failure(): void
    {
        $result = $this->parser->parse('');

        $this->assertFalse($result->valid);
        $this->assertSame('empty', $result->failure);
        $this->assertNull($result->version);
        $this->assertNull($result->etag);
    }

    public function test_null_header_is_rejected_with_empty_failure(): void
    {
        $result = $this->parser->parse(null);

        $this->assertFalse($result->valid);
        $this->assertSame('empty', $result->failure);
        $this->assertNull($result->version);
        $this->assertNull($result->etag);
    }

    public function test_whitespace_only_header_is_rejected_with_empty_failure(): void
    {
        $result = $this->parser->parse("   \t");

        $this->assertFalse($result->valid);
        $this->assertSame('empty', $result->failure);
        $this->assertNull($result->version);
        $this->assertNull($result->etag);
    }

    public function test_weak_validator_is_rejected_with_weak_failure(): void
    {
        $result = $this->parser->parse('W/"1"');

        $this->assertFalse($result->valid);
        $this->assertSame('weak', $result->failure);
        $this->assertNull($result->version);
        $this->assertNull($result->etag);
    }

    public function test_weak_validator_with_uuid_is_rejected_with_weak_failure(): void
    {
        $result = $this->parser->parse('W/"0197f0e0-0000-7000-8000-000000000101"');

        $this->assertFalse($result->valid);
        $this->assertSame('weak', $result->failure);
        $this->assertNull($result->version);
        $this->assertNull($result->etag);
    }

    public function test_unquoted_string_is_rejected_as_malformed(): void
    {
        $result = $this->parser->parse('abc');

        $this->assertFalse($result->valid);
        $this->assertSame('malformed', $result->failure);
        $this->assertNull($result->version);
        $this->assertNull($result->etag);
    }

    public function test_quoted_integer_version_is_accepted(): void
    {
        $result = $this->parser->parse('"1"');

        $this->assertTrue($result->valid);
        $this->assertNull($result->failure);
        $this->assertSame(1, $result->version);
        $this->assertNull($result->etag);
    }

    public function test_larger_integer_version_is_accepted(): void
    {
        $result = $this->parser->parse('"42"');

        $this->assertTrue($result->valid);
        $this->assertNull($result->failure);
        $this->assertSame(42, $result->version);
        $this->assertNull($result->etag);
    }

    public function test_uuidv7_is_accepted_with_etag_populated(): void
    {
        $uuid = '0197f0e0-0000-7000-8000-000000000101';

        $result = $this->parser->parse('"'.$uuid.'"');

        $this->assertTrue($result->valid);
        $this->assertNull($result->failure);
        $this->assertNull($result->version);
        $this->assertSame($uuid, $result->etag);
    }

    public function test_zero_version_is_rejected_as_malformed(): void
    {
        $result = $this->parser->parse('"0"');

        $this->assertFalse($result->valid);
        $this->assertSame('malformed', $result->failure);
        $this->assertNull($result->version);
        $this->assertNull($result->etag);
    }

    public function test_all_zero_version_is_rejected_as_malformed(): void
    {
        // The contract forbids non-positive *values*; "00", "000", …
        // all canonicalise to zero and must be rejected.
        $result = $this->parser->parse('"000"');

        $this->assertFalse($result->valid);
        $this->assertSame('malformed', $result->failure);
        $this->assertNull($result->version);
        $this->assertNull($result->etag);
    }

    public function test_leading_zero_version_is_accepted_with_stripped_value(): void
    {
        // The contract specifies the *numeric* value must be >= 1, not
        // the lexical form. "01" therefore parses to version 1.
        $result = $this->parser->parse('"01"');

        $this->assertTrue($result->valid);
        $this->assertNull($result->failure);
        $this->assertSame(1, $result->version);
        $this->assertNull($result->etag);
    }

    public function test_leading_zero_large_version_is_accepted_with_stripped_value(): void
    {
        $result = $this->parser->parse('"00042"');

        $this->assertTrue($result->valid);
        $this->assertNull($result->failure);
        $this->assertSame(42, $result->version);
        $this->assertNull($result->etag);
    }

    public function test_version_at_php_int_max_is_accepted(): void
    {
        $result = $this->parser->parse('"'.PHP_INT_MAX.'"');

        $this->assertTrue($result->valid);
        $this->assertNull($result->failure);
        $this->assertSame(PHP_INT_MAX, $result->version);
        $this->assertNull($result->etag);
    }

    public function test_version_above_php_int_max_is_rejected_as_malformed(): void
    {
        // One digit beyond PHP_INT_MAX must not silently saturate to
        // PHP_INT_MAX — that was the overflow bug the bound check exists
        // to prevent.
        $overflow = (string) PHP_INT_MAX.'0';

        $result = $this->parser->parse('"'.$overflow.'"');

        $this->assertFalse($result->valid);
        $this->assertSame('malformed', $result->failure);
        $this->assertNull($result->version);
        $this->assertNull($result->etag);
    }

    public function test_version_one_above_php_int_max_with_same_length_is_rejected_as_malformed(): void
    {
        // Same length as PHP_INT_MAX but lexically greater — the lex
        // comparison must catch this even when the length check passes.
        // PHP_INT_MAX on every supported PHP build ends in `7`
        // (largest signed 64-bit integer), so incrementing that final
        // digit yields a same-length strictly-greater decimal string.
        $max = (string) PHP_INT_MAX;
        $overflow = substr($max, 0, -1).((int) substr($max, -1) + 1);

        $result = $this->parser->parse('"'.$overflow.'"');

        $this->assertFalse($result->valid);
        $this->assertSame('malformed', $result->failure);
        $this->assertNull($result->version);
        $this->assertNull($result->etag);
    }

    public function test_version_with_too_many_digits_is_rejected_as_malformed(): void
    {
        // Far more digits than PHP_INT_MAX can hold — the regex accepts
        // any length, so the post-match length / lex check must reject
        // before any cast is attempted.
        $result = $this->parser->parse('"'.str_repeat('9', 100).'"');

        $this->assertFalse($result->valid);
        $this->assertSame('malformed', $result->failure);
        $this->assertNull($result->version);
        $this->assertNull($result->etag);
    }

    public function test_uuidv4_with_wrong_version_nibble_is_rejected_as_malformed(): void
    {
        // Version nibble is `4`, not `7` — must not slip through.
        $uuid = '0197f0e0-0000-4000-8000-000000000101';

        $result = $this->parser->parse('"'.$uuid.'"');

        $this->assertFalse($result->valid);
        $this->assertSame('malformed', $result->failure);
        $this->assertNull($result->version);
        $this->assertNull($result->etag);
    }

    public function test_uuidv7_with_bad_variant_is_rejected_as_malformed(): void
    {
        // Variant nibble is `c`, which is outside the RFC 4122 range
        // (`8`/`9`/`a`/`b`).
        $uuid = '0197f0e0-0000-7000-c000-000000000101';

        $result = $this->parser->parse('"'.$uuid.'"');

        $this->assertFalse($result->valid);
        $this->assertSame('malformed', $result->failure);
        $this->assertNull($result->version);
        $this->assertNull($result->etag);
    }

    public function test_uuidv7_uppercase_hex_is_accepted_with_canonical_etag(): void
    {
        // RFC 9562 §3 declares the textual UUID hex digits as
        // case-insensitive; an uppercase opaque tag must parse and the
        // captured etag must stay in the caller's original case so the
        // value remains byte-identical to what arrived on the wire.
        $uuid = '0197F0E0-0000-7000-8000-000000000101';

        $result = $this->parser->parse('"'.$uuid.'"');

        $this->assertTrue($result->valid);
        $this->assertNull($result->failure);
        $this->assertNull($result->version);
        $this->assertSame($uuid, $result->etag);
    }

    public function test_uuidv7_mixed_case_hex_is_accepted_with_original_case_etag(): void
    {
        // Mixed-case opaque tags must also be accepted (still RFC 9562
        // conformant); the returned etag must preserve the input case.
        $uuid = '0197F0e0-0000-7000-8000-000000000101';

        $result = $this->parser->parse('"'.$uuid.'"');

        $this->assertTrue($result->valid);
        $this->assertNull($result->failure);
        $this->assertNull($result->version);
        $this->assertSame($uuid, $result->etag);
    }

    public function test_uuidv7_uppercase_invalid_version_nibble_is_still_rejected(): void
    {
        // The case-insensitive flag must not mask the version-nibble
        // check: `4` (UUIDv4) is still malformed even in uppercase.
        $uuid = '0197F0E0-0000-4000-8000-000000000101';

        $result = $this->parser->parse('"'.$uuid.'"');

        $this->assertFalse($result->valid);
        $this->assertSame('malformed', $result->failure);
        $this->assertNull($result->version);
        $this->assertNull($result->etag);
    }
}
