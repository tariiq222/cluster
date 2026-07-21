<?php

namespace Modules\Organization\Domain;

use InvalidArgumentException;

final readonly class JobTitle
{
    private function __construct(
        public string $id,
        public string $code,
        public string $titleAr,
    ) {}

    public static function create(string $id, string $code, string $titleAr): self
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $id) !== 1) {
            throw new InvalidArgumentException('JobTitle id must be a lowercase UUIDv7.');
        }
        if (preg_match('/\A[A-Z0-9_-]{2,96}\z/', $code) !== 1) {
            throw new InvalidArgumentException('JobTitle code must match [A-Z0-9_-]{2,96}.');
        }
        if ($titleAr === '' || mb_strlen($titleAr) > 255) {
            throw new InvalidArgumentException('JobTitle title_ar is invalid.');
        }

        return new self($id, $code, $titleAr);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title_ar' => $this->titleAr,
            'status' => 'active',
        ];
    }
}
