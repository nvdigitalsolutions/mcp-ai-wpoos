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

interface FinancialDataInterface {
    /**
     * @return array<string, mixed>
     */
    public function getQuote(string $symbol): array;
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getHistory(string $symbol, string $period = '1mo'): array;
    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query): array;
    public function isAvailable(): bool;
}
