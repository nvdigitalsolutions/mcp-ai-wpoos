<?php

/**
 * Capacity planning for autonomous orchestration via Little's Law.
 *
 * Port of `WP_MCP_AI_Tool_Calculate_Orchestration_Capacity` (Proposal 164
 * section 164.78). Three modes:
 *
 * - `calculate_capacity` — L = λ × W from a supplied arrival rate and service
 *   time, with utilisation, the 80% safe ceiling and a 50% buffer limit.
 * - `predict_wait_time` — queue position and wait for a new session against a
 *   concurrency cap, assuming serial execution.
 * - `analyze_current` — measures λ and W from the session store and compares
 *   the load it predicts with the load actually running.
 *
 * ## Re-based on the session store
 *
 * WordPress counts live sessions by scanning `_transient_mcp_ai_session_*` rows
 * in the options table with `$wpdb`, then reads each transient back. That is a
 * database query masquerading as a cache read, and it only sees sessions that
 * happen to live in the options table. This port asks
 * {@see AutonomousSessionStoreInterface}: active sessions are
 * `query(statuses: [active])`, and the historical sample is the most recent
 * completed sessions. The numbers mean the same thing; the coupling does not.
 *
 * WordPress's window was implicitly 24 hours because transients expired then.
 * Here the window is explicit: a completed session counts only when its
 * `completed_at` is within the last 24 hours. The sample is capped at the store's
 * 200-session page, which is the newest 200 by activity — enough for a 24-hour
 * window unless a host completes more than 200 sessions a day, in which case the
 * rate is under-read rather than wrong in kind.
 *
 * ## Pinned, and three corrections
 *
 * - **`arrival_rate` and `service_time` must be positive** in
 *   `calculate_capacity`; the defaults (0.5 sessions/hour, 1 hour) apply only to
 *   `analyze_current`, as in WordPress.
 * - **A zero concurrency cap cannot fatal.** WordPress divides by
 *   `max_concurrent` directly; `empty()` makes 0 fall back to 10 there, but a
 *   negative value would reach the division. Here the cap is a positive integer
 *   by construction.
 * - **Negative queue inputs are their magnitudes.** WordPress's `empty()` test
 *   passes `-3` through as `-3`, producing a negative wait. A queue cannot be
 *   negative, so the port takes `abs()`.
 * - **Zero-length predictions do not divide.** The "can handle Nx more load"
 *   line is omitted when L is 0, which positive λ and W make unreachable but
 *   float underflow could reach.
 * - **Unparseable timestamps contribute zero duration**, not a negative one.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Orchestration;

use Nvoos\Core\Domain\Contract\AutonomousSessionStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Tool\AbstractTool;

class CalculateOrchestrationCapacityTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /** The default concurrency cap, as in WordPress. */
    private const DEFAULT_MAX_CONCURRENT = 10;

    /** The default service time in hours, as in WordPress. */
    private const DEFAULT_SERVICE_TIME = 1.0;

    /** The default arrival rate in sessions per hour, as in WordPress. */
    private const DEFAULT_ARRIVAL_RATE = 0.5;

    /** The page size used for the historical sample. */
    private const HISTORY_SAMPLE = 200;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly AutonomousSessionStoreInterface $sessions,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'calculate_orchestration_capacity';
    }

    public function getName(): string
    {
        return 'Calculate Orchestration Capacity';
    }

    public function getDescription(): string
    {
        return 'Calculate optimal capacity for autonomous orchestration using Little\'s Law (L = λ × W). Helps determine how many concurrent sessions can run without overload.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'mode'             => array(
                    'type'        => 'string',
                    'enum'        => array('calculate_capacity', 'predict_wait_time', 'analyze_current'),
                    'description' => 'Calculation mode (default: analyze_current).',
                ),
                'arrival_rate'     => array(
                    'type'        => 'number',
                    'description' => 'Session arrival rate (sessions per hour) — λ.',
                ),
                'service_time'     => array(
                    'type'        => 'number',
                    'description' => 'Average service time (hours per session) — W.',
                ),
                'max_concurrent'   => array(
                    'type'        => 'integer',
                    'description' => 'Maximum concurrent sessions limit (default: 10).',
                ),
                'current_sessions' => array(
                    'type'        => 'integer',
                    'description' => 'Current number of active sessions.',
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('read-only', 'idempotent', 'local-only');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $mode = $this->stringParam($arguments, 'mode');

        if ('' === $mode) {
            $mode = 'analyze_current';
        }

        return match ($mode) {
            'calculate_capacity' => $this->calculateCapacity($arguments),
            'predict_wait_time'  => $this->predictWaitTime($arguments),
            'analyze_current'    => $this->analyzeCurrent($arguments),
            default              => $this->errors->create(
                'invalid_mode',
                'Invalid mode. Use: calculate_capacity, predict_wait_time, or analyze_current.'
            ),
        };
    }

    /**
     * @param array<string, mixed> $arguments Arguments.
     */
    private function calculateCapacity(array $arguments): mixed
    {
        if (! isset($arguments['arrival_rate'], $arguments['service_time'])) {
            return $this->errors->create(
                'missing_arguments',
                'Missing required arguments: arrival_rate and service_time.'
            );
        }

        $lambda  = is_numeric($arguments['arrival_rate']) ? (float) $arguments['arrival_rate'] : 0.0;
        $service = is_numeric($arguments['service_time']) ? (float) $arguments['service_time'] : 0.0;

        if ($lambda <= 0 || $service <= 0) {
            return $this->errors->create(
                'invalid_arguments',
                'arrival_rate and service_time must be positive numbers.'
            );
        }

        $l = $lambda * $service;

        $maxConcurrent = $this->positiveInt($arguments['max_concurrent'] ?? self::DEFAULT_MAX_CONCURRENT, self::DEFAULT_MAX_CONCURRENT);
        $utilization   = ( $l / $maxConcurrent ) * 100;

        return $this->success(
            sprintf('Little\'s Law capacity: L = %.2f at %.1f%% utilization.', $l, $utilization),
            array(
                'littles_law'     => array(
                    'L'       => round($l, 2),
                    'λ'       => $lambda,
                    'W'       => $service,
                    'formula' => 'L = λ × W',
                ),
                'capacity'        => array(
                    'expected_queue_length' => round($l, 2),
                    'current_limit'         => $maxConcurrent,
                    'utilization_percent'   => round($utilization, 1),
                    'safe_capacity'         => floor($maxConcurrent * 0.8),
                    'recommended_limit'     => ceil($l * 1.5),
                ),
                'recommendations' => $this->capacityRecommendations($l, $maxConcurrent, $utilization),
            )
        );
    }

    /**
     * @param array<string, mixed> $arguments Arguments.
     */
    private function predictWaitTime(array $arguments): array
    {
        $current       = isset($arguments['current_sessions']) ? abs((int) $arguments['current_sessions']) : 0;
        $maxConcurrent = $this->positiveInt($arguments['max_concurrent'] ?? self::DEFAULT_MAX_CONCURRENT, self::DEFAULT_MAX_CONCURRENT);
        $serviceTime   = isset($arguments['service_time']) && is_numeric($arguments['service_time'])
            ? abs((float) $arguments['service_time'])
            : self::DEFAULT_SERVICE_TIME;

        if ($serviceTime <= 0.0) {
            $serviceTime = self::DEFAULT_SERVICE_TIME;
        }

        if ($current < $maxConcurrent) {
            return $this->success(
                'Capacity available - session can start immediately',
                array(
                    'wait_time_hours'   => 0,
                    'wait_time_minutes' => 0,
                    'queue_position'    => 0,
                    'message'           => 'Capacity available - session can start immediately',
                )
            );
        }

        $queuePosition = $current - $maxConcurrent + 1;
        $waitHours     = $queuePosition * $serviceTime;
        $message       = sprintf(
            'System at capacity. Estimated wait: %.0f minutes (position %d in queue)',
            $waitHours * 60,
            $queuePosition
        );

        return $this->success(
            $message,
            array(
                'wait_time_hours'   => round($waitHours, 2),
                'wait_time_minutes' => round($waitHours * 60, 0),
                'queue_position'    => $queuePosition,
                'current_load'      => array(
                    'active_sessions' => $current,
                    'max_concurrent'  => $maxConcurrent,
                    'utilization'     => round(( $current / $maxConcurrent ) * 100, 1),
                ),
                'message'           => $message,
            )
        );
    }

    /**
     * @param array<string, mixed> $arguments Arguments.
     */
    private function analyzeCurrent(array $arguments): array
    {
        $maxConcurrent = $this->positiveInt($arguments['max_concurrent'] ?? self::DEFAULT_MAX_CONCURRENT, self::DEFAULT_MAX_CONCURRENT);

        $active = $this->sessions->query(
            array(
                'statuses' => array(AutonomousSessionStoreInterface::STATUS_ACTIVE),
                'limit'    => 1,
            )
        )['total'];

        $metrics = $this->historicalMetrics();

        $lambda     = $metrics['arrival_rate'];
        $service    = $metrics['avg_service_time'];
        $predictedL = $lambda * $service;

        $currentUtilization   = ( $active / $maxConcurrent ) * 100;
        $predictedUtilization = ( $predictedL / $maxConcurrent ) * 100;
        $headroomPercent      = ( ( $maxConcurrent - $active ) / $maxConcurrent ) * 100;

        return $this->success(
            sprintf(
                'Current load: %d of %d slots in use (%.1f%%).',
                $active,
                $maxConcurrent,
                $currentUtilization
            ),
            array(
                'current_state'      => array(
                    'active_sessions' => $active,
                    'max_concurrent'  => $maxConcurrent,
                    'utilization'     => round($currentUtilization, 1),
                ),
                'historical_metrics' => array(
                    'arrival_rate'      => round($lambda, 2),
                    'avg_service_time'  => round($service, 2),
                    'completed_today'   => $metrics['completed_today'],
                    'avg_duration_mins' => $metrics['avg_duration_mins'],
                ),
                'littles_law'        => array(
                    'predicted_queue_length' => round($predictedL, 2),
                    'predicted_utilization'  => round($predictedUtilization, 1),
                    'formula'                => sprintf('L = %.2f × %.2f = %.2f', $lambda, $service, $predictedL),
                ),
                'capacity_analysis'  => array(
                    'status'            => $this->loadStatus($currentUtilization),
                    'headroom_sessions' => max(0, $maxConcurrent - $active),
                    'headroom_percent'  => round($headroomPercent, 1),
                ),
                'recommendations'    => $this->loadRecommendations(
                    $currentUtilization,
                    $predictedUtilization,
                    $active,
                    $maxConcurrent
                ),
            )
        );
    }

    /**
     * Arrival rate and service time from sessions completed in the last day.
     *
     * @return array{arrival_rate: float, avg_service_time: float, completed_today: int, avg_duration_mins: float}
     */
    private function historicalMetrics(): array
    {
        $result = $this->sessions->query(
            array(
                'statuses' => array(AutonomousSessionStoreInterface::STATUS_COMPLETED),
                'limit'    => self::HISTORY_SAMPLE,
            )
        );

        $since         = time() - 86400;
        $completed     = 0;
        $totalDuration = 0;

        foreach ($result['sessions'] as $session) {
            $completedAt = strtotime((string) $session['completed_at']);

            if (false === $completedAt || $completedAt < $since) {
                continue;
            }

            ++$completed;

            $startedAt = strtotime((string) $session['started_at']);

            if (false !== $startedAt) {
                $totalDuration += max(0, $completedAt - $startedAt);
            }
        }

        return array(
            'arrival_rate'      => $completed > 0 ? $completed / 24 : self::DEFAULT_ARRIVAL_RATE,
            'avg_service_time'  => $completed > 0
                ? ( $totalDuration / $completed ) / 3600
                : self::DEFAULT_SERVICE_TIME,
            'completed_today'   => $completed,
            'avg_duration_mins' => round(( $totalDuration / max(1, $completed) ) / 60, 0),
        );
    }

    /**
     * @return list<string>
     */
    private function capacityRecommendations(float $predictedL, int $maxConcurrent, float $utilization): array
    {
        $recommendations = array();

        if ($utilization > 90) {
            $recommendations[] = '🚨 CRITICAL: System severely overloaded. Increase max_concurrent or reduce arrival rate.';
            $recommendations[] = sprintf(
                'Recommended limit: %d sessions (current: %d)',
                (int) ceil($predictedL * 1.5),
                $maxConcurrent
            );
        } elseif ($utilization > 80) {
            $recommendations[] = '⚠️ WARNING: System nearing capacity. Consider increasing limits.';
            $recommendations[] = sprintf('Safe capacity: %d sessions (80%% rule)', (int) floor($maxConcurrent * 0.8));
        } elseif ($utilization > 60) {
            $recommendations[] = '✅ OK: System operating normally with moderate load.';
        } else {
            $recommendations[] = '✅ EXCELLENT: System has plenty of headroom.';

            if ($predictedL > 0) {
                $recommendations[] = sprintf('Can handle %.1fx more load', $maxConcurrent / $predictedL);
            }
        }

        return $recommendations;
    }

    private function loadStatus(float $utilization): string
    {
        if ($utilization >= 90) {
            return 'CRITICAL';
        }

        if ($utilization >= 80) {
            return 'WARNING';
        }

        if ($utilization >= 60) {
            return 'MODERATE';
        }

        if ($utilization >= 30) {
            return 'LIGHT';
        }

        return 'IDLE';
    }

    /**
     * @return list<string>
     */
    private function loadRecommendations(
        float $currentUtilization,
        float $predictedUtilization,
        int $activeSessions,
        int $maxConcurrent,
    ): array {
        $recommendations = array();

        if ($currentUtilization > 90) {
            $recommendations[] = '🚨 System at capacity. Consider pausing new sessions until load decreases.';
        } elseif ($currentUtilization > 80) {
            $recommendations[] = '⚠️ High load. Monitor closely and prepare to scale if needed.';
        } else {
            $recommendations[] = sprintf(
                '✅ Capacity available: %d of %d slots in use (%.1f%%)',
                $activeSessions,
                $maxConcurrent,
                $currentUtilization
            );
        }

        $variance = abs($predictedUtilization - $currentUtilization);

        if ($variance > 20) {
            $recommendations[] = sprintf(
                '📊 Load variance: Predicted %.1f%%, actual %.1f%% (%.1f%% difference)',
                $predictedUtilization,
                $currentUtilization,
                $variance
            );

            $recommendations[] = $predictedUtilization > $currentUtilization
                ? 'Trend: Load increasing - prepare for higher utilization.'
                : 'Trend: Load decreasing - excess capacity available.';
        }

        return $recommendations;
    }

    /**
     * A positive integer, or the documented fallback for zero.
     */
    private function positiveInt(mixed $value, int $fallback): int
    {
        $int = abs((int) $value);

        return $int > 0 ? $int : $fallback;
    }
}
