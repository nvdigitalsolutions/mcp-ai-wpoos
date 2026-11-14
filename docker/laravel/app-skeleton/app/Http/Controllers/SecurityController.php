<?php

namespace App\Http\Controllers;

use App\Support\SettingsSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvoos\Core\Application\Security\PostureEvaluator;
use Nvoos\Core\Application\Security\PostureScorer;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Security\SecuritySignalCatalogue;

class SecurityController
{
    /**
     * Security posture for the console.
     *
     * Every signal comes from the shared catalogue in lib/core, so this
     * runtime reports the same 27 cards WordPress does — the 9 WordPress-only
     * controls arrive as `applicable: false` and leave the score's
     * denominator (the AWS Security Hub / CIS-CAT rule). Evaluations only
     * supply pass/detail; identity, weight and the remediation target stay
     * in the catalogue.
     */
    public function posture(Request $request, SettingsStoreInterface $store): JsonResponse
    {
        $settings = $store->all();
        $https = $request->isSecure() || str_starts_with((string) config('app.url'), 'https://');
        $providers = ! empty($settings['openai_api_key'])
            || ! empty($settings['google_api_key'])
            || ! empty($settings['anthropic_api_key'])
            || ! empty($settings['ollama_endpoint']);

        $security = (array) config('oos.security', []);
        $hstsMaxAge = (int) ($security['hsts_max_age'] ?? 0);
        $postMax = (string) ini_get('post_max_size');
        $corsOrigins = (array) config('cors.allowed_origins', ['*']);

        /** @var array<string, array{passed: bool, detail: string}> $evaluations */
        $evaluations = [
            'https_active' => [
                'passed' => $https,
                'detail' => $https ? 'HTTPS is active.' : 'Serve the console over HTTPS.',
            ],
            'hsts_enabled' => [
                // Observatory's bar is six months; the console proxy ships a
                // 1-day ramp, so the honest answer is a finding until raised.
                'passed' => $hstsMaxAge >= 15768000,
                'detail' => $hstsMaxAge >= 15768000
                    ? 'HSTS max-age is at least six months.'
                    : sprintf('HSTS max-age is %d seconds (six months is 15768000). The console proxy ships a 1-day ramp.', $hstsMaxAge),
            ],
            'rate_limiting_on' => [
                'passed' => (bool) $store->get('enable_rate_limiting', true),
                'detail' => 'Per-route throttles are active.',
            ],
            'rate_limit_by_user' => [
                'passed' => true,
                'detail' => 'Named limiters key by user id when authenticated, falling back to the client IP.',
            ],
            'security_headers_on' => [
                'passed' => (bool) ($security['headers'] ?? true),
                'detail' => 'The console proxy sets nosniff, HSTS, frame and referrer headers.',
            ],
            'csp_frame_ancestors' => [
                'passed' => '' !== (string) ($security['csp_frame_ancestors'] ?? ''),
                'detail' => '' !== (string) ($security['csp_frame_ancestors'] ?? '')
                    ? 'CSP frame-ancestors is configured.'
                    : 'Set oos.security.csp_frame_ancestors (the console proxy does not enforce a CSP yet).',
            ],
            'require_https_api' => [
                'passed' => $https && (bool) config('session.secure'),
                'detail' => $https && (bool) config('session.secure')
                    ? 'API traffic is TLS-only and session cookies are secure.'
                    : 'Serve behind TLS with SESSION_SECURE_COOKIE=true.',
            ],
            'cors_restricted' => [
                'passed' => ! in_array('*', $corsOrigins, true),
                'detail' => ! in_array('*', $corsOrigins, true)
                    ? 'CORS is limited to the configured origins.'
                    : 'CORS allows every origin; set NVOOS_CORS_ORIGINS.',
            ],
            'error_verbosity_safe' => [
                'passed' => ! config('app.debug'),
                'detail' => config('app.debug')
                    ? 'APP_DEBUG is on; production responses leak stack traces.'
                    : 'APP_DEBUG is off.',
            ],
            'auth_brute_force_on' => [
                'passed' => true,
                'detail' => 'Session login is throttled (throttle:6,1) per client.',
            ],
            'body_size_limited' => [
                'passed' => (int) $postMax > 0,
                'detail' => (int) $postMax > 0
                    ? 'PHP post_max_size is '.$postMax.'.'
                    : 'PHP post_max_size is unlimited.',
            ],
            'minimum_capability' => [
                'passed' => true,
                'detail' => 'Every console route sits behind a can: gate (edit_posts, manage_options or upload_files).',
            ],
            'audit_log_on' => [
                'passed' => Schema::hasTable('nvoos_approvals') && Schema::hasTable('nvoos_slash_command_audit'),
                'detail' => 'Approval decisions and slash-command executions are durable and queryable.',
            ],
            'audit_log_retention' => [
                'passed' => (int) ($security['audit_retention_days'] ?? 30) >= 30,
                'detail' => sprintf('Audit retention is %d days.', (int) ($security['audit_retention_days'] ?? 30)),
            ],
            'csrf' => [
                'passed' => true,
                'detail' => 'Laravel CSRF middleware validates every state-changing console request.',
            ],
            'secure_cookies' => [
                'passed' => (bool) config('session.secure') || $https,
                'detail' => (bool) config('session.secure') || $https
                    ? 'Session cookies are secure.'
                    : 'Set SESSION_SECURE_COOKIE=true behind TLS.',
            ],
            'provider_keys' => [
                'passed' => $providers,
                'detail' => $providers
                    ? 'At least one provider is configured.'
                    : 'No provider credentials are configured.',
            ],
            'memory_controls' => [
                'passed' => (bool) $store->get('memory_enabled', true),
                'detail' => 'Agent memory can be disabled per site.',
            ],
            'prompt_injection_detector' => [
                'passed' => (bool) ($security['prompt_injection_detector'] ?? false),
                'detail' => (bool) ($security['prompt_injection_detector'] ?? false)
                    ? sprintf(
                        'The orchestrator screens the latest user turn (%s mode, %s sensitivity) before the provider call.',
                        (string) ($security['prompt_injection_mode'] ?? 'log'),
                        (string) ($security['prompt_injection_sensitivity'] ?? 'medium'),
                    )
                    : 'Set NVOOS_PROMPT_INJECTION_DETECTOR=true to screen messages for jailbreak and injection patterns.',
            ],
            'pii_filter' => [
                'passed' => (bool) ($security['pii_filter'] ?? false),
                'detail' => (bool) ($security['pii_filter'] ?? false)
                    ? 'User messages are redacted before they reach the session log.'
                    : 'Set NVOOS_PII_FILTER=true to redact emails, phone numbers, card numbers and API keys before persistence.',
            ],
        ];

        $signals = PostureEvaluator::build(SecuritySignalCatalogue::RUNTIME_LARAVEL, $evaluations);

        return response()->json(( new PostureScorer() )->score($signals));
    }

    public function previewHeaders(Request $request): JsonResponse
    {
        $secure = $request->isSecure();
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ];

        if ($secure) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return response()->json([
            'security_headers_enabled' => true,
            'hsts_enabled' => $secure,
            'headers' => $headers,
        ]);
    }

    public function snapshots(): JsonResponse
    {
        $rows = DB::table('nvoos_security_snapshots')->orderByDesc('created_at')->limit(50)->get();

        return response()->json([
            'snapshots' => array_map([$this, 'snapshotRecord'], $rows->all()),
        ]);
    }

    public function saveSnapshot(Request $request, SettingsStoreInterface $store): JsonResponse
    {
        $data = $request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $payload = array_intersect_key($store->all(), array_flip(SettingsSchema::keys()));

        // Never persist plaintext credentials: secrets are replaced with the
        // masked placeholder and skipped again on restore.
        foreach (SettingsSchema::sensitiveKeys() as $key) {
            if (isset($payload[$key]) && $payload[$key] !== '') {
                $payload[$key] = SettingsSchema::MASKED_PLACEHOLDER;
            }
        }

        $id = (string) Str::uuid();

        DB::table('nvoos_security_snapshots')->insert([
            'id' => $id,
            'label' => $data['label'] ?? 'Snapshot '.now()->toDateTimeString(),
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json($this->snapshotRecord(DB::table('nvoos_security_snapshots')->find($id)), 201);
    }

    public function restoreSnapshot(Request $request, SettingsStoreInterface $store): JsonResponse
    {
        $data = $request->validate([
            'snapshot_id' => ['required', 'string', 'max:64'],
        ]);

        $row = DB::table('nvoos_security_snapshots')->find($data['snapshot_id']);

        if ($row === null) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'not_found', 'message' => 'Snapshot not found.'],
            ], 404);
        }

        $payload = json_decode((string) $row->payload, true);
        $allowed = array_flip(SettingsSchema::keys());
        $restored = 0;

        if (is_array($payload)) {
            foreach ($payload as $key => $value) {
                if (! isset($allowed[$key])) {
                    continue;
                }

                if ($value === SettingsSchema::MASKED_PLACEHOLDER) {
                    continue;
                }

                $store->set((string) $key, $value);
                $restored++;
            }
        }

        return response()->json(['success' => true, 'restored' => $restored]);
    }

    public function selfTest(): JsonResponse
    {
        $checks = [];

        try {
            app(SettingsStoreInterface::class);
            $checks[] = ['id' => 'bindings', 'label' => 'Core bindings', 'passed' => true, 'message' => 'Core contracts resolve.'];
        } catch (\Throwable $e) {
            $checks[] = ['id' => 'bindings', 'label' => 'Core bindings', 'passed' => false, 'message' => $e->getMessage()];
        }

        $checks[] = [
            'id' => 'database',
            'label' => 'Database',
            'passed' => Schema::hasTable('users'),
            'message' => 'The users table is reachable.',
        ];

        $cacheKey = 'nvoos-security-self-test';
        $cacheOk = false;

        try {
            Cache::put($cacheKey, 'ok', 10);
            $cacheOk = Cache::get($cacheKey) === 'ok';
            Cache::forget($cacheKey);
        } catch (\Throwable) {
            $cacheOk = false;
        }

        $checks[] = [
            'id' => 'cache',
            'label' => 'Cache',
            'passed' => $cacheOk,
            'message' => $cacheOk ? 'Cache read/write works.' : 'Cache read/write failed.',
        ];

        $storageOk = false;

        try {
            $disk = Storage::disk((string) config('oos.storage_disk', 'local'));
            $path = 'nvoos-security-self-test.txt';
            $disk->put($path, 'ok');
            $storageOk = $disk->exists($path);
            $disk->delete($path);
        } catch (\Throwable) {
            $storageOk = false;
        }

        $checks[] = [
            'id' => 'storage',
            'label' => 'Storage',
            'passed' => $storageOk,
            'message' => $storageOk ? 'Storage read/write works.' : 'Storage read/write failed.',
        ];

        return response()->json([
            'success' => true,
            'checks' => $checks,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotRecord(object $row): array
    {
        return [
            'snapshot_id' => (string) $row->id,
            'id' => (string) $row->id,
            'label' => (string) ($row->label ?? ''),
            'created_at' => (string) ($row->created_at ?? ''),
        ];
    }
}
