<?php
/**
 * Timeout Detection — domain contract.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface TimeoutDetectionInterface
{
    public function start(string $operationId, int $timeoutSeconds): void;

    public function check(string $operationId): array;

    public function cancel(string $operationId): void;

    public function extendTime(string $operationId, int $additionalSeconds): void;
}
