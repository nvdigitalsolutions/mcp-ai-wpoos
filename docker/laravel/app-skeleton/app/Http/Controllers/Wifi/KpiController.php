<?php

namespace App\Http\Controllers\Wifi;

use App\Wifi\VenueRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NvoosWifi\Domain\Result;
use NvoosWifi\Domain\Window;
use NvoosWifi\Services\Class_Kpi_Service;

/**
 * GET /api/v1/wifi/venues/{venue}/kpis?from=&to=  (Proposal 150, T-0.10)
 *
 * The Laravel twin of `addons/nvoos-wifi/includes/REST/Class_Kpi_Controller.php`.
 *
 * Both call the same portable `Class_Kpi_Service::for_venue()`. The only thing
 * this class is allowed to do that WordPress does differently is turn a domain
 * `Result` into a host-specific wire format — which is exactly the boundary the
 * layering reserves for it.
 *
 * If you find yourself adding shaping, filtering or renaming here, stop: the
 * WordPress controller does not do it, and the parity test
 * (`tests/Feature/WifiKpisTest.php`) exists to catch the resulting drift.
 */
final class KpiController
{
    /**
     * Domain error code → HTTP status.
     *
     * Mirrors `Class_Result_Mapper::STATUS` on the WordPress side. Kept as a
     * per-host table on purpose: HTTP status is a transport concern and does not
     * belong in the domain. Unknown codes fall through to 500 — never to 200, so
     * a failure cannot be mistaken for an empty success.
     *
     * @var array<string, int>
     */
    private const STATUS = [
        'invalid_venue' => 400,
        'invalid_window' => 400,
        'invalid_timezone' => 400,
        'invalid_residency' => 400,
        'invalid_device_identity' => 400,
        'invalid_kpi_value' => 400,
        'invalid_kpi_time' => 400,
        'invalid_method' => 422,
        'invalid_sample' => 500,
        'mac_not_allowed' => 400,
        'method_required' => 422,
        'unknown_kpi_key' => 500,
        'venue_not_found' => 404,
        'adapter_shape' => 502,
        'adapter_auth' => 502,
        'adapter_rate_limited' => 503,
        'adapter_unreachable' => 504,
    ];

    public function __invoke(
        Request $request,
        string $venue,
        Class_Kpi_Service $service,
        VenueRepository $venues,
    ): JsonResponse {
        $found = $venues->find($venue);

        if ($found->is_error()) {
            return $this->fail($found);
        }

        $window = Window::make($request->integer('from'), $request->integer('to'));

        if ($window->is_error()) {
            return $this->fail($window);
        }

        $result = $service->for_venue($found->value(), $window->value());

        if ($result->is_error()) {
            return $this->fail($result);
        }

        return response()->json($result->value());
    }

    private function fail(Result $result): JsonResponse
    {
        $code = '' === $result->code() ? 'unknown_error' : $result->code();

        return response()->json(
            [
                'ok' => false,
                'error' => [
                    'code' => $code,
                    'message' => $result->message(),
                ],
            ],
            self::STATUS[$code] ?? 500,
        );
    }
}
