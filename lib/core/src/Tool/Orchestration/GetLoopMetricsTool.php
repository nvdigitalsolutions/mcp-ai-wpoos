<?php

/**
 * Performance analytics for one autonomous loop session.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Get_Loop_Metrics` (Proposal 164 section 164.78).
 * Reads the session's tool-call history through
 * {@see ExecutionHistoryStoreInterface}; when the host records none, it answers
 * the WordPress "no execution history found" payload with zeroed metrics rather
 * than an error.
 *
 * ## Pinned
 *
 * - **Average duration divides by every call, not just timed ones.** A record
 *   with no `duration_ms` counts as zero, so a half-measured session reports a
 *   lower average than its measured calls. The WordPress arithmetic is kept.
 * - **A missing `success` key counts as success**, which is the WordPress
 *   default and the only safe reading for an append-only log whose older rows
 *   predate the field.
 * - **The tool-frequency and error-distribution maps are capped at 10 entries**
 *   while the totals above them are not truncated.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Orchestration;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ExecutionHistoryStoreInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Tool\AbstractTool;

class GetLoopMetricsTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * A host that records no tool-call history is not broken; the metrics
     * answer is zeroed, as WordPress does when its history CCT is absent.
     */
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ?ExecutionHistoryStoreInterface $history = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'get_loop_metrics';
    }

    public function getName(): string
    {
        return 'Get Loop Metrics';
    }

    public function getDescription(): string
    {
        return 'Get performance analytics for autonomous orchestration loops. Returns success rates, average iteration duration, tool-call frequency, error distribution, and a health recommendation.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'session_id' => array(
                    'type'        => 'string',
                    'description' => 'Session ID to get metrics for.',
                ),
                'period'     => array(
                    'type'        => 'string',
                    'enum'        => array('session', '24h', '7d', '30d'),
                    'description' => 'Time period for aggregated metrics (recorded in the payload; the history read is the session).',
                    'default'     => 'session',
                ),
            ),
            'required'             => array('session_id'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'read';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('read-only', 'idempotent');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $sessionId = $this->stringParam($arguments, 'session_id');

        if ('' === $sessionId) {
            return $this->errors->create('missing_session_id', 'Missing required argument: session_id.');
        }

        $period  = $this->stringParam($arguments, 'period', 'session');
        $history = null !== $this->history ? $this->history->forSession($sessionId) : array();

        $metrics = array(
            'session_id'         => $sessionId,
            'period'             => $period,
            'total_calls'        => count($history),
            'successful_calls'   => 0,
            'failed_calls'       => 0,
            'success_rate'       => 0,
            'avg_duration_ms'    => 0,
            'total_tokens'       => 0,
            'most_used_tool'     => '',
            'error_tools'        => array(),
            'tool_frequency'     => array(),
            'error_distribution' => array(),
        );

        if (array() === $history) {
            $metrics['message'] = 'No execution history found for this session.';

            return $this->success('No execution history found for this session.', array('metrics' => $metrics));
        }

        $totalDuration = 0;
        $toolCounts    = array();
        $errorCounts   = array();

        foreach ($history as $record) {
            $tool    = isset($record['tool_name']) && is_scalar($record['tool_name'])
                ? (string) $record['tool_name']
                : 'unknown';
            $success = isset($record['success']) ? (bool) $record['success'] : true;

            if ($success) {
                ++$metrics['successful_calls'];
            } else {
                ++$metrics['failed_calls'];

                if (! isset($errorCounts[ $tool ])) {
                    $errorCounts[ $tool ] = array('count' => 0, 'messages' => array());
                }

                ++$errorCounts[ $tool ]['count'];

                if (count($errorCounts[ $tool ]['messages']) < 3) {
                    $message                            = isset($record['error_message']) && is_scalar($record['error_message'])
                        ? (string) $record['error_message']
                        : 'Unknown error';
                    $errorCounts[ $tool ]['messages'][] = $message;
                }
            }

            $toolCounts[ $tool ] = ( $toolCounts[ $tool ] ?? 0 ) + 1;

            if (isset($record['duration_ms']) && (int) $record['duration_ms'] > 0) {
                $totalDuration += (int) $record['duration_ms'];
            }

            if (isset($record['tokens_used']) && (int) $record['tokens_used'] > 0) {
                $metrics['total_tokens'] += (int) $record['tokens_used'];
            }
        }

        $total = (int) $metrics['total_calls'];

        $metrics['success_rate']    = round(( $metrics['successful_calls'] / $total ) * 100, 1);
        $metrics['avg_duration_ms'] = round($totalDuration / $total);

        arsort($toolCounts);
        $metrics['most_used_tool'] = (string) array_key_first($toolCounts);
        $metrics['tool_frequency'] = array_slice($toolCounts, 0, 10, true);

        if (array() !== $errorCounts) {
            uasort(
                $errorCounts,
                static fn (array $a, array $b): int => $b['count'] <=> $a['count']
            );
            $metrics['error_distribution'] = $errorCounts;
            $metrics['error_tools']        = array_keys($errorCounts);
        }

        $metrics['health_recommendation'] = $this->recommendation($metrics);

        return $this->success(
            sprintf('Computed metrics for %d tool call(s).', $total),
            array('metrics' => $metrics)
        );
    }

    /**
     * The WordPress recommendation bands.
     *
     * @param array<string, mixed> $metrics Computed metrics.
     */
    private function recommendation(array $metrics): string
    {
        if (0 === (int) $metrics['total_calls']) {
            return 'No data available yet. Start the loop to collect metrics.';
        }

        if ($metrics['success_rate'] >= 90) {
            return 'Excellent! Loop is performing well with high success rate. Consider increasing max_iterations for longer autonomous runs.';
        }

        if ($metrics['success_rate'] >= 70) {
            return 'Good. Some errors detected — review error distribution for patterns and consider adjusting tool configurations.';
        }

        if ($metrics['failed_calls'] > $metrics['successful_calls']) {
            return sprintf(
                'Critical: More failures than successes. Failing tools: %s. Review circuit breaker status and consider pausing the session for investigation.',
                implode(', ', (array) $metrics['error_tools'])
            );
        }

        return 'Moderate performance. Monitor closely and review tool configurations for optimisation.';
    }
}
