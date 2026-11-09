<?php
/**
 * Timeout Detection — domain contract.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface TimeoutDetectionInterface
{
    public function start(string $operationId, int $timeoutSeconds): void;

    /**
     * @return array<string, mixed>
     */
    public function check(string $operationId): array;

    public function cancel(string $operationId): void;

    public function extendTime(string $operationId, int $additionalSeconds): void;
}
