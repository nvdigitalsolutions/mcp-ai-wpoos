<?php

/**
 * File header.
 *
 * @package WP_MCP_AI
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Nvoos\Core\Domain\Contract;

interface MusicGenerationInterface {
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function generate(string $prompt, array $options = []): array;
    /**
     * @return array<string, mixed>
     */
    public function checkStatus(string $jobId): array;
    public function isAvailable(): bool;
    /**
     * @return array<int, string>
     */
    public function getSupportedModels(): array;
}
