<?php

namespace Modules\PlatformSettings\Features\Alerts\Http;

use App\Integrations\PlatformSettings\PlatformSettingsApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AlertPoliciesController
{
    public function __construct(private readonly PlatformSettingsApi $api) {}

    public function index(Request $request): JsonResponse
    {
        $context = $this->api->authorize($request, 'platform_operations.alerts.manage', $this->api->facts('platform_alert_policy'));
        if ($context instanceof JsonResponse) {
            return $context;
        }
        $items = DB::table('platform_alert_policies')->orderBy('code')->get()->map(fn (object $row) => $this->present($row, $context['decision']->allowedActions))->all();

        return $this->api->response(['items' => $items], 200, $context['correlation_id']);
    }

    public function update(Request $request, string $policyId): JsonResponse
    {
        $policy = DB::table('platform_alert_policies')->where('id', $policyId)->first();
        if ($policy === null) {
            return $this->api->problem(404, 'resource-not-found', 'Not Found', 'Alert policy was not found.', $this->api->correlationId($request));
        }
        $context = $this->api->authorize($request, 'platform_operations.alerts.manage', $this->api->facts('platform_alert_policy', $policyId));
        if ($context instanceof JsonResponse) {
            return $context;
        }
        $etag = $this->api->ifMatch($request);
        if ($etag === null) {
            return $this->api->problem(412, 'precondition-required', 'Precondition Failed', 'If-Match is required.', $context['correlation_id']);
        }
        $updated = DB::table('platform_alert_policies')->where('id', $policyId)->where('lock_version', $etag)->update([
            'status' => (string) $request->input('status', $policy->status), 'severity' => (string) $request->input('severity', $policy->severity), 'channel' => (string) $request->input('channel', $policy->channel), 'lock_version' => $etag + 1, 'updated_at' => now(),
        ]);
        if ($updated !== 1) {
            return $this->api->problem(412, 'precondition-failed', 'Precondition Failed', 'If-Match does not match the current alert policy.', $context['correlation_id']);
        }
        $next = DB::table('platform_alert_policies')->where('id', $policyId)->first();

        return $this->api->response($this->present($next, $context['decision']->allowedActions), 200, $context['correlation_id'], (int) $next->lock_version);
    }

    private function present(object $policy, array $allowedActions): array
    {
        return ['id' => $policy->id, 'code' => $policy->code, 'status' => $policy->status, 'severity' => $policy->severity, 'channel' => $policy->channel, 'lock_version' => (int) $policy->lock_version, 'allowed_actions' => $allowedActions];
    }
}
