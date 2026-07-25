<?php
/**
 * Token Usage — domain contract.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface TokenUsageInterface
{
    public function trackUsage(int $userId, string $modelId, int $promptTokens, int $completionTokens, array $metadata = []): void;

    public function getUserUsage(int $userId, string $startDate, string $endDate): array;

    public function getModelUsage(string $modelId, string $startDate, string $endDate): array;
}
