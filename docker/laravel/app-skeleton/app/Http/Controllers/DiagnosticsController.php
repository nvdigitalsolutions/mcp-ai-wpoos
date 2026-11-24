<?php

namespace App\Http\Controllers;

use App\Support\Diagnostics\RoutingExportStore;
use App\Support\SettingsSchema;
use Illuminate\Http\JsonResponse;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Laravel\Adapter\JobStatusStore;
use Nvoos\Laravel\Adapter\MediaWorkerCapabilityProbe;

class DiagnosticsController
{
    public function __invoke(
        ProviderRouter $providers,
        ToolRegistry $tools,
        SettingsStoreInterface $store,
        JobStatusStore $jobs,
        ProEntitlementInterface $entitlement,
        RoutingExportStore $routing,
        MediaWorkerCapabilityProbe $mediaWorker,
    ): JsonResponse {
        $settings = $store->all();
        $registered = $providers->getRegisteredSlugs();

        $providerRows = array_map(
            static function (string $slug) use ($settings): array {
                // The provider client's storage key, not the settings surface's
                // key: diagnostics reports whether the runtime can authenticate,
                // so it must ask the same question the client does.
                $key = SettingsSchema::providerCredentialKey($slug);

                $has = ! empty($settings[$key]);

                if ($slug === 'ollama') {
                    $has = $has || ! empty($settings['ollama_endpoint_url']);
                }

                return [
                    'slug' => $slug,
                    'label' => ucfirst($slug),
                    'has_credentials' => $has,
                    'key_source' => $has ? 'settings' : 'none',
                ];
            },
            $registered,
        );

        return response()->json([
            'success' => true,
            'data' => [
                'generated_at' => time(),
                'health' => [
                    'status' => 'ok',
                    'timestamp' => time(),
                    'version' => (string) config('app.version', '1.2.0'),
                    'checks' => [
                        'tools' => $tools->count(),
                        'providers' => count($registered),
                    ],
                ],
                'system' => [
                    'wp_version' => 'n/a',
                    'php_version' => PHP_VERSION,
                    'plugin_version' => (string) config('app.version', '1.2.0'),
                    'memory_limit' => (string) ini_get('memory_limit'),
                    'is_multisite' => false,
                ],
                'settings_dashboard' => [
                    'constants' => [],
                    'classes' => [],
                    'dashboard_global_set' => true,
                    'admin_settings_global' => true,
                    'settings_init_function' => true,
                    'sections_total' => count(SettingsSchema::fields()),
                ],
                'mcp_server' => [
                    'auth0_configured' => false,
                    'federation_enabled' => false,
                    'federation_directory_enabled' => false,
                    'mesh_enabled' => ! empty(config('oos.mesh_api_key')),
                    'tool_count' => $tools->count(),
                    'mcp_routes_present' => true,
                ],
                'providers' => $providerRows,
                'rest_context' => [
                    'backend' => 'laravel',
                    'contract' => 'mcp-ai/v1',
                ],
                'pro_dashboard' => [
                    'plan' => $entitlement->plan(),
                    'source' => $entitlement->source(),
                ],
                'queue' => [
                    'available' => $jobs->isAvailable(),
                    'counts' => $jobs->counts(),
                ],
                // §78 — the gauge a scraper reads. The page shows the same
                // facts as a sentence; these are the fields behind it.
                'routing_export' => $this->routingExport($routing),

                // Proposal 164 §164.4.2 — what the processing tier can do, not
                // just whether it is up. A managed Node host can answer
                // `/api/health` and still have no `ffmpeg`; without this block
                // the only way to learn that was a failed transcode.
                'media_worker' => $mediaWorker->toArray(),
            ],
        ]);
    }

    /**
     * Machine-readable routing-export state.
     *
     * `backing_off` is the gauge: true only while the exporter is inside its
     * retry window. `retry_after` is null when it is false, so a consumer
     * never has to compare a timestamp against its own clock to decide.
     *
     * @return array{configured: bool, backing_off: bool, retry_after: string|null, consecutive_failures: int}
     */
    private function routingExport(RoutingExportStore $store): array
    {
        if ('' === trim((string) config('oos.routing.export.endpoint', ''))) {
            return [
                'configured' => false,
                'backing_off' => false,
                'retry_after' => null,
                'consecutive_failures' => 0,
            ];
        }

        $state = $store->backoffState();

        return [
            'configured' => true,
            'backing_off' => $state['backing_off'],
            'retry_after' => $state['retry_after']?->toIso8601String(),
            'consecutive_failures' => $state['consecutive_failures'],
        ];
    }
}
