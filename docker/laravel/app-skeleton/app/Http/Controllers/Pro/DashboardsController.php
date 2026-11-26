<?php

namespace App\Http\Controllers\Pro;

use App\Support\DashboardCatalogue;
use App\Support\Dashboards\DashboardGatherer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pro dashboards (`/api/v1/pro/dashboards/schema` and `/{id}/data`).
 *
 * Shapes are deliberately **unwrapped** rather than the `{success, data}`
 * envelope the other Laravel controllers use: `DashboardsClient.catalogue()`
 * reads `dashboards` / `disabled` off the top level, exactly as WordPress's
 * `rest_ensure_response()` returns them, and this client is shared by both
 * runtimes.
 *
 * WordPress dispatches each dashboard to that dashboard's wp-admin page class
 * (`gather()`), which does not exist here. So this controller owns one
 * gatherer per served dashboard, and a dashboard is only listed as available
 * when its store exists on this runtime. See {@see DashboardCatalogue}.
 */
class DashboardsController
{
    /**
     * Dashboard catalogue: served dashboards plus metadata for the rest.
     */
    public function schema(): JsonResponse
    {
        $dashboards = [];
        $disabled = [];

        foreach (DashboardCatalogue::all() as $id => $config) {
            if (DashboardCatalogue::isAvailable($id)) {
                $dashboards[$id] = $config + ['id' => $id];

                continue;
            }

            $disabled[$id] = [
                'label' => $config['label'],
                'description' => $config['description'],
                'icon' => $config['icon'],
                'toolkit_flag' => DashboardCatalogue::flag($id),
                // Every flag that would enable it. A merged dashboard has
                // several, and naming one of three is incomplete advice.
                'toolkit_flags' => DashboardCatalogue::flags($id),
            ];
        }

        return response()->json([
            'dashboards' => $dashboards,
            'disabled' => $disabled,
            // 158 D1 — the retired ids and what replaced them, so a client can
            // migrate its own saved state. Additive; a client that ignores it
            // keeps working. Must match the WordPress envelope.
            'aliases' => DashboardCatalogue::aliases(),
        ]);
    }

    /**
     * Filtered metric payload for one dashboard.
     *
     * A retired id resolves first, and the response reports the **resolved**
     * id — a client that asked for `ezuite` is told it received `connectors`,
     * which is what it needs to update its own state. Resolving is not a grant:
     * the replacement's own availability check still applies.
     */
    public function data(Request $request, string $id): JsonResponse
    {
        $id = DashboardCatalogue::resolveAlias($id);

        if (! array_key_exists($id, DashboardCatalogue::all())) {
            return response()->json(['message' => 'Unknown dashboard.'], 400);
        }

        if (! DashboardCatalogue::isAvailable($id)) {
            return response()->json(
                ['message' => 'This dashboard requires a toolkit that is not enabled on this site.'],
                404
            );
        }

        $payload = app(DashboardGatherer::class)->gather($id, $this->filters($request, $id));

        if (null === $payload) {
            // A dashboard marked available with no gatherer is a wiring bug,
            // and saying so beats an empty page that looks like zero data.
            return response()->json(['message' => 'This dashboard has no gatherer on this runtime.'], 501);
        }

        return response()->json([
            'dashboard' => $id,
            'data' => $payload,
        ]);
    }

    /**
     * Whitelist the caller's filters against the dashboard's declared keys.
     *
     * The console sends `filters` as a JSON string; decode defensively so a
     * direct handler call behaves like the REST pipeline.
     *
     * @return array<string, string>
     */
    private function filters(Request $request, string $id): array
    {
        $raw = $request->query('filters');

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $declared = DashboardCatalogue::all()[$id]['filters'] ?? [];
        $filters = [];

        foreach ($declared as $filter) {
            $key = (string) $filter['key'];

            if (isset($raw[$key]) && is_scalar($raw[$key])) {
                $filters[$key] = trim((string) $raw[$key]);
            }
        }

        return $filters;
    }
}
