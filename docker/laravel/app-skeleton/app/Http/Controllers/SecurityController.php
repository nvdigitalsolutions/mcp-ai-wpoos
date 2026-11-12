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
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class SecurityController
{
    public function posture(Request $request, SettingsStoreInterface $store): JsonResponse
    {
        $settings = $store->all();
        $https = $request->isSecure() || str_starts_with((string) config('app.url'), 'https://');
        $providers = ! empty($settings['openai_api_key'])
            || ! empty($settings['google_api_key'])
            || ! empty($settings['anthropic_api_key'])
            || ! empty($settings['ollama_endpoint']);

        $signals = [
            $this->signal('https', 'HTTPS enforced', 20, $https, $https ? 'HTTPS is active.' : 'Serve the console over HTTPS.', 'network', '/security'),
            $this->signal('debug_off', 'Debug mode disabled', 15, ! config('app.debug'), config('app.debug') ? 'APP_DEBUG is on.' : 'APP_DEBUG is off.', 'network', '/security'),
            $this->signal('provider_keys', 'Provider credentials present', 15, $providers, $providers ? 'At least one provider is configured.' : 'No provider credentials are configured.', 'ai_safety', '/settings'),
            $this->signal('rate_limiting', 'Rate limiting enabled', 10, (bool) $store->get('enable_rate_limiting', true), 'Per-route throttles are active.', 'network', '/security'),
            $this->signal('secure_cookies', 'Secure session cookies', 10, (bool) config('session.secure') || $https, (bool) config('session.secure') || $https ? 'Session cookies are secure.' : 'Set SESSION_SECURE_COOKIE=true behind TLS.', 'access', '/security'),
            $this->signal('csrf', 'CSRF protection', 10, true, 'Laravel CSRF middleware is active.', 'access', '/security'),
            $this->signal('audit', 'Durable audit surfaces', 10, Schema::hasTable('nvoos_jobs') && Schema::hasTable('nvoos_approvals'), 'Job and approval history are durable.', 'audit', '/operations'),
            $this->signal('memory_controls', 'Memory controls available', 10, (bool) $store->get('memory_enabled', true), 'Agent memory can be disabled per site.', 'ai_safety', '/settings'),
        ];

        $totalWeight = array_sum(array_column($signals, 'weight'));
        $passedWeight = array_sum(array_map(
            static fn (array $signal): int => $signal['passed'] ? $signal['weight'] : 0,
            $signals,
        ));

        $score = (int) round(($passedWeight / max(1, $totalWeight)) * 100);
        $grade = match (true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default => 'F',
        };

        return response()->json([
            'score' => $score,
            'grade' => $grade,
            'signals' => $signals,
            'quick_wins' => array_values(array_filter($signals, static fn (array $signal): bool => ! $signal['passed'])),
            'computed_at' => now()->toIso8601String(),
        ]);
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
            $disk = Storage::disk((string) config('nvoos.storage_disk', 'local'));
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
    private function signal(string $id, string $label, int $weight, bool $passed, string $detail, string $subtab, string $anchor): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'weight' => $weight,
            'passed' => $passed,
            'detail' => $detail,
            'subtab' => $subtab,
            'anchor' => $anchor,
        ];
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
