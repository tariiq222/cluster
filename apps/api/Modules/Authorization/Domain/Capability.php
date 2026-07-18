<?php

namespace Modules\Authorization\Domain;

use InvalidArgumentException;

final readonly class Capability
{
    private function __construct(
        public string $id,
        public string $moduleCode,
        public string $capabilityCode,
        public string $action,
        public string $sensitivity,
    ) {}

    public static function define(
        string $id,
        string $moduleCode,
        string $capabilityCode,
        string $action,
        string $sensitivity = 'normal',
    ): self {
        UuidV7::assert($id, 'Capability id');
        if (! self::isModuleCode($moduleCode)
            || ! self::belongsToModule($capabilityCode, $moduleCode)
            || ! self::isSegment($action, 32)
            || ! in_array($sensitivity, ['normal', 'sensitive', 'critical'], true)) {
            throw new InvalidArgumentException('Capability data is invalid.');
        }

        return new self($id, $moduleCode, $capabilityCode, $action, $sensitivity);
    }

    public static function belongsToModule(string $capabilityCode, string $moduleCode): bool
    {
        return self::isModuleCode($moduleCode)
            && mb_strlen($capabilityCode) <= 96
            && preg_match('/\A[a-z][a-z0-9_-]*(?:\.[a-z][a-z0-9_-]*)+\z/', $capabilityCode) === 1
            && str_starts_with($capabilityCode, $moduleCode.'.');
    }

    /** @return array{id: string, module_code: string, capability_code: string, action: string, sensitivity: string, status: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'module_code' => $this->moduleCode,
            'capability_code' => $this->capabilityCode,
            'action' => $this->action,
            'sensitivity' => $this->sensitivity,
            'status' => 'active',
        ];
    }

    private static function isModuleCode(string $value): bool
    {
        return self::isSegment($value, 64);
    }

    private static function isSegment(string $value, int $maximumLength): bool
    {
        return mb_strlen($value) <= $maximumLength
            && preg_match('/\A[a-z][a-z0-9_-]*\z/', $value) === 1;
    }
}
