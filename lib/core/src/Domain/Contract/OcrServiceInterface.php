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

interface OcrServiceInterface {
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function extractText(string $filePath, array $options = []): array;
    /**
     * @return array<int, string>
     */
    public function getAvailableProviders(): array;
    public function isAvailable(): bool;
}
