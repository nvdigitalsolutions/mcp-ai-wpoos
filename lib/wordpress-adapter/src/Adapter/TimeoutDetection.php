<?php
declare(strict_types=1);
namespace Nvoos\WordPress\Adapter;
use Nvoos\Core\Domain\Contract\TimeoutDetectionInterface;

final class TimeoutDetection implements TimeoutDetectionInterface
{
    private array $operations = [];

    public function start(string $operationId, int $timeoutSeconds): void {
        $this->operations[$operationId] = ['start' => \microtime(true), 'timeout' => $timeoutSeconds];
    }

    public function check(string $operationId): array {
        if (!isset($this->operations[$operationId])) {
            return ['timed_out' => false, 'elapsed_ms' => 0, 'remaining_ms' => 0];
        }
        $op = $this->operations[$operationId];
        $elapsed = (\microtime(true) - $op['start']) * 1000;
        $timeout = $op['timeout'] * 1000;
        $remaining = \max(0, $timeout - $elapsed);
        return ['timed_out' => $elapsed >= $timeout, 'elapsed_ms' => \round($elapsed, 1), 'remaining_ms' => \round($remaining, 1)];
    }

    public function cancel(string $operationId): void { unset($this->operations[$operationId]); }

    public function extendTime(string $operationId, int $additionalSeconds): void {
        if (isset($this->operations[$operationId])) {
            $this->operations[$operationId]['timeout'] += $additionalSeconds;
        }
    }
}
