<?php

declare(strict_types=1);

namespace Modules\Authorization\Tests;

use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Shared\Http\AuthenticatedCursorCodec;
use Tests\TestCase;

/**
 * Locks the wire format and validation contract of
 * {@see AuthenticatedCursorCodec}.
 *
 * The codec is the single source of truth for every pagination cursor
 * consumers expose over the Shared HTTP boundary, so every failure
 * mode documented in the contract is pinned here: round trip, list
 * order preservation, object-key-order-insensitive binding, tamper,
 * resource mismatch, principal/scope/filter/limit mismatch, malformed
 * JSON / payload / version, and the stable, safe exception message.
 */
#[CoversClass(AuthenticatedCursorCodec::class)]
final class SharedAuthenticatedCursorCodecTest extends TestCase
{
    private AuthenticatedCursorCodec $codec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->codec = new AuthenticatedCursorCodec;
    }

    public function test_round_trip_returns_the_exact_sort_tuple_that_was_encoded(): void
    {
        $resource = 'person_directory';
        $binding = [
            'principal' => '018f6f7d-0c00-7000-8000-000000000021',
            'scope' => 'org_unit:018f6f7d-0c00-7000-8000-000000000011',
            'filter' => ['status' => 'active', 'tenant' => 'acme'],
            'limit' => 50,
        ];
        $sortTuple = [
            ['column' => 'created_at', 'direction' => 'desc', 'value' => '2026-07-27T10:15:00Z'],
            ['column' => 'id', 'direction' => 'asc', 'value' => '018f6f7d-0c00-7000-8000-000000000777'],
        ];

        $cursor = $this->codec->encode($resource, $sortTuple, $binding);

        $this->assertNotSame('', $cursor);
        $this->assertSame($sortTuple, $this->codec->decode($cursor, $resource, $binding));
    }

    public function test_decoded_sort_tuple_preserves_nested_list_order_exactly(): void
    {
        $resource = 'task_feed';
        $binding = ['principal' => 'u-1', 'scope' => 'facility:f-1', 'filter' => ['type' => 'a'], 'limit' => 20];
        $sortTuple = [
            ['c' => 'a', 'd' => 'asc', 'v' => 1],
            ['c' => 'b', 'd' => 'desc', 'v' => 2],
            ['c' => 'c', 'd' => 'asc', 'v' => 3],
        ];

        $cursor = $this->codec->encode($resource, $sortTuple, $binding);

        $decoded = $this->codec->decode($cursor, $resource, $binding);

        $this->assertSame($sortTuple, $decoded);
        $this->assertSame(['a', 'asc', 1], [$decoded[0]['c'], $decoded[0]['d'], $decoded[0]['v']]);
        $this->assertSame(['b', 'desc', 2], [$decoded[1]['c'], $decoded[1]['d'], $decoded[1]['v']]);
        $this->assertSame(['c', 'asc', 3], [$decoded[2]['c'], $decoded[2]['d'], $decoded[2]['v']]);
    }

    public function test_binding_is_insensitive_to_object_key_order_at_the_top_level(): void
    {
        $resource = 'audit_log';
        $sortTuple = [['column' => 'id', 'direction' => 'asc', 'value' => 'row-1']];

        $bindingA = [
            'principal' => 'u-1',
            'scope' => 'org:o-1',
            'filter' => ['status' => 'active', 'tenant' => 'acme'],
            'limit' => 25,
        ];
        $bindingB = [
            'limit' => 25,
            'filter' => ['tenant' => 'acme', 'status' => 'active'],
            'scope' => 'org:o-1',
            'principal' => 'u-1',
        ];

        $cursorA = $this->codec->encode($resource, $sortTuple, $bindingA);

        // Laravel encryption uses a random IV, so ciphertext equality is
        // neither expected nor relevant. Successful decode with reordered
        // maps proves the recursively canonical binding digest is stable.
        $this->assertSame($sortTuple, $this->codec->decode($cursorA, $resource, $bindingB));
    }

    public function test_tampered_ciphertext_is_rejected_with_the_safe_message(): void
    {
        $resource = 'identity_account';
        $binding = ['principal' => 'u-1', 'scope' => 's-1', 'filter' => [], 'limit' => 10];
        $sortTuple = [['column' => 'id', 'direction' => 'asc', 'value' => 'acc-1']];

        $cursor = $this->codec->encode($resource, $sortTuple, $binding);

        // Flip the last character of the base64-encoded envelope —
        // Crypt will fail to decrypt.
        $flipped = substr($cursor, 0, -1).($cursor[-1] === 'A' ? 'B' : 'A');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AuthenticatedCursorCodec::INVALID_CURSOR_MESSAGE);

        try {
            $this->codec->decode($flipped, $resource, $binding);
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString($resource, $e->getMessage());
            $this->assertStringNotContainsString('acc-1', $e->getMessage());
            $this->assertStringNotContainsString('u-1', $e->getMessage());
            throw $e;
        }
    }

    public function test_decode_rejects_scope_mismatch(): void
    {
        $sortTuple = [['column' => 'id', 'direction' => 'asc', 'value' => 'row-1']];
        $bindingA = ['principal' => 'u-1', 'scope' => 'org:o-1', 'filter' => [], 'limit' => 10];
        $bindingB = ['principal' => 'u-1', 'scope' => 'org:o-2', 'filter' => [], 'limit' => 10];

        $cursor = $this->codec->encode('people', $sortTuple, $bindingA);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AuthenticatedCursorCodec::INVALID_CURSOR_MESSAGE);

        $this->codec->decode($cursor, 'people', $bindingB);
    }

    public function test_decode_rejects_filter_mismatch(): void
    {
        $sortTuple = [['column' => 'id', 'direction' => 'asc', 'value' => 'row-1']];
        $bindingA = ['principal' => 'u-1', 'scope' => 's-1', 'filter' => ['status' => 'active'], 'limit' => 10];
        $bindingB = ['principal' => 'u-1', 'scope' => 's-1', 'filter' => ['status' => 'inactive'], 'limit' => 10];

        $cursor = $this->codec->encode('people', $sortTuple, $bindingA);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AuthenticatedCursorCodec::INVALID_CURSOR_MESSAGE);

        $this->codec->decode($cursor, 'people', $bindingB);
    }

    public function test_decode_rejects_limit_mismatch(): void
    {
        $sortTuple = [['column' => 'id', 'direction' => 'asc', 'value' => 'row-1']];
        $bindingA = ['principal' => 'u-1', 'scope' => 's-1', 'filter' => [], 'limit' => 10];
        $bindingB = ['principal' => 'u-1', 'scope' => 's-1', 'filter' => [], 'limit' => 25];

        $cursor = $this->codec->encode('people', $sortTuple, $bindingA);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AuthenticatedCursorCodec::INVALID_CURSOR_MESSAGE);

        $this->codec->decode($cursor, 'people', $bindingB);
    }

    public function test_decode_rejects_malformed_json_payload(): void
    {
        $resource = 'people';
        $binding = ['principal' => 'u-1', 'scope' => 's-1', 'filter' => [], 'limit' => 10];

        // Encrypt a string that is not valid JSON.
        $cursor = Crypt::encryptString('not-valid-json');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AuthenticatedCursorCodec::INVALID_CURSOR_MESSAGE);

        $this->codec->decode($cursor, $resource, $binding);
    }

    public function test_decode_rejects_payload_that_is_not_an_object(): void
    {
        $resource = 'people';
        $binding = ['principal' => 'u-1', 'scope' => 's-1', 'filter' => [], 'limit' => 10];

        $cursor = Crypt::encryptString(json_encode(['a', 'b', 'c'], JSON_THROW_ON_ERROR));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AuthenticatedCursorCodec::INVALID_CURSOR_MESSAGE);

        $this->codec->decode($cursor, $resource, $binding);
    }

    public function test_decode_rejects_payload_with_unexpected_top_level_keys(): void
    {
        $resource = 'people';
        $binding = ['principal' => 'u-1', 'scope' => 's-1', 'filter' => [], 'limit' => 10];

        $cursor = Crypt::encryptString(json_encode([
            'v' => 1,
            'r' => $resource,
            'b' => str_repeat('a', 64),
            's' => [],
            'extra' => 'leak',
        ], JSON_THROW_ON_ERROR));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AuthenticatedCursorCodec::INVALID_CURSOR_MESSAGE);

        $this->codec->decode($cursor, $resource, $binding);
    }

    public function test_decode_rejects_payload_missing_top_level_keys(): void
    {
        $resource = 'people';
        $binding = ['principal' => 'u-1', 'scope' => 's-1', 'filter' => [], 'limit' => 10];

        $cursor = Crypt::encryptString(json_encode([
            'v' => 1,
            'r' => $resource,
            'b' => str_repeat('a', 64),
        ], JSON_THROW_ON_ERROR));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AuthenticatedCursorCodec::INVALID_CURSOR_MESSAGE);

        $this->codec->decode($cursor, $resource, $binding);
    }

    public function test_decode_rejects_payload_with_version_other_than_one(): void
    {
        $resource = 'people';
        $binding = ['principal' => 'u-1', 'scope' => 's-1', 'filter' => [], 'limit' => 10];
        $sortTuple = [['column' => 'id', 'direction' => 'asc', 'value' => 'row-1']];

        $cursor = Crypt::encryptString(json_encode([
            'v' => 2,
            'r' => $resource,
            'b' => hash('sha256', json_encode($binding, JSON_THROW_ON_ERROR)),
            's' => $sortTuple,
        ], JSON_THROW_ON_ERROR));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AuthenticatedCursorCodec::INVALID_CURSOR_MESSAGE);

        $this->codec->decode($cursor, $resource, $binding);
    }

    public function test_decode_rejects_payload_with_string_version(): void
    {
        $resource = 'people';
        $binding = ['principal' => 'u-1', 'scope' => 's-1', 'filter' => [], 'limit' => 10];

        $cursor = Crypt::encryptString(json_encode([
            'v' => '1',
            'r' => $resource,
            'b' => str_repeat('0', 64),
            's' => [],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AuthenticatedCursorCodec::INVALID_CURSOR_MESSAGE);

        $this->codec->decode($cursor, $resource, $binding);
    }

    public function test_decode_rejects_binding_with_non_array_value(): void
    {
        $resource = 'people';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AuthenticatedCursorCodec::INVALID_CURSOR_MESSAGE);

        $this->codec->decode('any-cursor', $resource, 'not-an-array');
    }

    public function test_decode_rejects_empty_cursor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AuthenticatedCursorCodec::INVALID_CURSOR_MESSAGE);

        $this->codec->decode('', 'people', ['principal' => 'u-1']);
    }

    public function test_encode_rejects_blank_resource_key(): void
    {
        $binding = ['principal' => 'u-1', 'scope' => 's-1', 'filter' => [], 'limit' => 10];
        $sortTuple = [['column' => 'id', 'direction' => 'asc', 'value' => 'row-1']];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AuthenticatedCursorCodec::INVALID_CURSOR_MESSAGE);

        $this->codec->encode('', $sortTuple, $binding);
    }

    public function test_encode_rejects_non_array_sort_tuple(): void
    {
        $binding = ['principal' => 'u-1', 'scope' => 's-1', 'filter' => [], 'limit' => 10];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AuthenticatedCursorCodec::INVALID_CURSOR_MESSAGE);

        $this->codec->encode('people', 'not-an-array', $binding);
    }

    public function test_encode_rejects_non_array_binding(): void
    {
        $sortTuple = [['column' => 'id', 'direction' => 'asc', 'value' => 'row-1']];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AuthenticatedCursorCodec::INVALID_CURSOR_MESSAGE);

        $this->codec->encode('people', $sortTuple, 'not-an-array');
    }

    public function test_invalid_cursor_message_is_the_stable_literal_string(): void
    {
        $this->assertSame('The pagination cursor is invalid.', AuthenticatedCursorCodec::INVALID_CURSOR_MESSAGE);
    }

    public function test_class_is_final_and_namespace_is_shared_http(): void
    {
        $reflection = new \ReflectionClass(AuthenticatedCursorCodec::class);

        $this->assertTrue($reflection->isFinal(), 'codec must be final');
        $this->assertSame('Shared\\Http', $reflection->getNamespaceName());
        $this->assertSame(AuthenticatedCursorCodec::class, $reflection->getName());
    }

    public function test_public_surface_is_exactly_encode_and_decode(): void
    {
        $reflection = new \ReflectionClass(AuthenticatedCursorCodec::class);
        $publicMethods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            array_values(array_filter(
                $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
                static fn (\ReflectionMethod $method): bool => ! $method->isConstructor() && ! $method->isDestructor(),
            )),
        );

        sort($publicMethods);

        $this->assertSame(['decode', 'encode'], $publicMethods);
    }

    public function test_class_has_no_module_specific_names(): void
    {
        $reflection = new \ReflectionClass(AuthenticatedCursorCodec::class);
        $haystack = $reflection->getFileName().'/'.$reflection->getName();

        foreach (['Modules', 'Audit', 'Identity', 'Organization', 'Work', 'Notification', 'Document', 'Reporting'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $haystack,
                "codec file/name must not reference '{$needle}'",
            );
        }
    }
}
