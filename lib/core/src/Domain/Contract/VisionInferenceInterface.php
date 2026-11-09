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

interface VisionInferenceInterface {
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function infer(string $imagePath, string $prompt = '', array $options = []): array;
    /**
     * @return array<int, string>
     */
    public function getAvailableModels(): array;
    public function isAvailable(): bool;
}
