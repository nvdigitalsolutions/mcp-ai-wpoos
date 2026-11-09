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

interface LanguageDetectionInterface {
    /**
     * @return array<string, mixed>
     */
    public function detect(string $text): array;
    public function getLanguageName(string $isoCode): string;
    /**
     * @return array<int, string>
     */
    public function getSupportedLanguages(): array;
}
