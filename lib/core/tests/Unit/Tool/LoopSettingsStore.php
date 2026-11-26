<?php

/**
 * In-memory settings for the orchestration loop tests.
 *
 * The breaker and `configure_circuit_breaker` read and write the same row, so a
 * mock would have to remember what it was handed anyway. This fake does that in
 * nine lines and lets a test assert the stored map directly.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

final class LoopSettingsStore implements SettingsStoreInterface
{
    /**
     * @param array<string, mixed> $values Initial rows.
     */
    public function __construct(public array $values = array())
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[ $key ] ?? $default;
    }

    public function all(): array
    {
        return $this->values;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[ $key ] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->values[ $key ]);
    }

    public function getDefaultProvider(): string
    {
        return 'openai';
    }

    public function getDefaultModel(): string
    {
        return 'gpt-4o-mini';
    }

    public function getApiKey(string $provider): ?string
    {
        return null;
    }

    public function getApiBaseUrl(string $provider): ?string
    {
        return null;
    }

    public function getRequestTimeout(): int
    {
        return 30;
    }

    public function isEnabled(string $feature): bool
    {
        return false;
    }
}
