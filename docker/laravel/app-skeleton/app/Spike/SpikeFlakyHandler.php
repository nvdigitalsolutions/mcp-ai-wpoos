<?php

namespace App\Spike;

use Illuminate\Support\Facades\Cache;

/**
 * Handler used by the P4 retry proof.
 *
 * Throws on the first attempt and succeeds on the second, using the
 * cache as an attempt counter.
 */
class SpikeFlakyHandler
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $key = 'spike:attempts:'.(string) ($payload['key'] ?? 'default');
        $attempt = (int) Cache::increment($key);

        if ($attempt < 2) {
            throw new \RuntimeException('Intentional spike failure on attempt '.$attempt);
        }

        Cache::forget($key);

        return [
            'ok' => true,
            'attempts' => $attempt,
        ];
    }
}
