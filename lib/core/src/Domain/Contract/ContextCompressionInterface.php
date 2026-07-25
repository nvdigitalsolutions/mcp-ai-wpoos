<?php
/**
 * Context Compression — domain contract.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface ContextCompressionInterface
{
    public function compress(string $content, array $options = []): array;

    public function chunk(string $content, int $chunkSize = 500, float $overlapRatio = 0.15): array;

    public function estimateTokens(string $text): int;
}
