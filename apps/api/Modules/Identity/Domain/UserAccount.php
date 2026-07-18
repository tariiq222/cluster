<?php

namespace Modules\Identity\Domain;

use InvalidArgumentException;
use Normalizer;

final readonly class UserAccount
{
    private function __construct(
        public string $id,
        public string $username,
        public string $personId,
        public int $personVersion,
        public string $displayNameAr,
        public ?string $displayNameEn,
    ) {}

    /** @param array{person_id: string, person_version: int, status: string, display_name_ar: string, display_name_en: string|null} $reference */
    public static function create(string $id, string $username, array $reference): self
    {
        if (! self::isUuidV7($id) || ! self::isUuidV7($reference['person_id'])) {
            throw new InvalidArgumentException('Identity identifiers must be lowercase UUIDv7 values.');
        }
        $normalized = self::normalizeUsername($username);
        if ($normalized === '' || mb_strlen($normalized) > 128
            || $reference['person_version'] < 1
            || $reference['display_name_ar'] === ''
            || mb_strlen($reference['display_name_ar']) > 255
            || ($reference['display_name_en'] !== null && mb_strlen($reference['display_name_en']) > 255)) {
            throw new InvalidArgumentException('User account data is invalid.');
        }

        return new self(
            $id,
            $normalized,
            $reference['person_id'],
            $reference['person_version'],
            $reference['display_name_ar'],
            $reference['display_name_en'],
        );
    }

    public static function normalizeUsername(string $username): string
    {
        $normalized = Normalizer::normalize(trim($username), Normalizer::FORM_KC);

        return mb_strtolower(is_string($normalized) ? $normalized : trim($username));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'person_id' => $this->personId,
            'person_version' => $this->personVersion,
            'status' => 'pending',
            'must_change_password' => true,
            'password_version' => 1,
            'locked_until' => null,
            'display_name_ar' => $this->displayNameAr,
            'display_name_en' => $this->displayNameEn,
        ];
    }

    private static function isUuidV7(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1;
    }
}
