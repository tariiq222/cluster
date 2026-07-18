<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Modules\Identity\Features\Sessions\Contracts\TrustedRequestBindingContext;

final class IdentityRequestBinding
{
    public static function context(Request $request): TrustedRequestBindingContext
    {
        $ip = $request->ip();
        $cidr = self::cidr($ip);

        return new TrustedRequestBindingContext(
            ipCidr: $cidr ?? '',
            userAgentHash: hash('sha256', (string) ($request->userAgent() ?? '')),
            trusted: $cidr !== null,
        );
    }

    /** @return array{ip_cidr: string, user_agent_hash: string, capability_version: string} */
    public static function metadata(Request $request): array
    {
        $context = self::context($request);

        return [
            'ip_cidr' => $context->ipCidr,
            'user_agent_hash' => $context->userAgentHash,
            'capability_version' => 'identity-http-v1',
        ];
    }

    private static function cidr(?string $ip): ?string
    {
        if (! is_string($ip)) {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ip);

            return sprintf('%d.%d.%d.0/24', (int) $parts[0], (int) $parts[1], (int) $parts[2]);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        $network = inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8));

        return $network === false ? null : strtolower($network).'/64';
    }
}
