<?php

namespace Modules\PlatformSettings\Domain;

use InvalidArgumentException;

final readonly class AlertPolicy
{
    private const SEVERITIES = ['info', 'warning', 'critical'];

    private const CHANNELS = ['in_app', 'email'];

    public function __construct(
        public string $code,
        public string $severity,
        public string $channel,
        public string $recipientCapability,
        public int $escalationMinutes,
    ) {
        if (trim($code) === '') {
            throw new InvalidArgumentException('alert_code_required');
        }
        if (! in_array($severity, self::SEVERITIES, true)) {
            throw new InvalidArgumentException('alert_severity_invalid');
        }
        if (! in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException('alert_channel_invalid');
        }
        if ($escalationMinutes <= 0) {
            throw new InvalidArgumentException('alert_escalation_minutes_must_be_positive');
        }
        if (preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/', $recipientCapability) !== 1
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $recipientCapability) === 1) {
            throw new InvalidArgumentException('alert_recipient_capability_invalid');
        }
    }
}
