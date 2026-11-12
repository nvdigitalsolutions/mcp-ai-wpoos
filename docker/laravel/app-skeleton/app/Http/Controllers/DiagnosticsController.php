<?php

namespace App\Http\Controllers;

use App\Support\SettingsSchema;
use Illuminate\Http\JsonResponse;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Laravel\Adapter\JobStatusStore;

class DiagnosticsController
{
    public function __invoke(
        ProviderRouter $providers,
        ToolRegistry $tools,
        SettingsStoreInterface $store,
        JobStatusStore $jobs,
        ProEntitlementInterface $entitlement,
    ): JsonResponse {
        $settings = $store->all();
        $registered = $providers->getRegisteredSlugs();

        $providerRows = array_map(
            static function (string $slug) use ($settings): array {
                $key = match ($slug) {
                    'openai' => 'openai_api_key',
                    'gemini' => 'google_api_key',
                    'anthropic' => 'anthropic_api_key',
                    default => $slug.'_api_key',
                };

                $has = ! empty($settings[$key]);

                if ($slug === 'ollama') {
                    $has = $has || ! empty($settings['ollama_endpoint']);
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
            ],
        ]);
    }
}
