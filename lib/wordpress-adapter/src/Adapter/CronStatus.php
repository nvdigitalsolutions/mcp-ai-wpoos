<?php
/**
 * WordPress Adapter — Cron Status.
 *
 * @package  Nvoos\WordPress
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\CronStatusInterface;

final class CronStatus implements CronStatusInterface
{
    public function getSummary(int $userId = 0, int $limit = 10, $assistantId = null): array
    {
        if (!\class_exists('WP_MCP_AI_Cron_Status_Service')) {
            return ['active' => 0, 'pending' => 0, 'completed' => 0, 'failed' => 0, 'jobs' => []];
        }

        $service = new \WP_MCP_AI_Cron_Status_Service();

        /** @var array $jobs */
        $jobs = $service->get_status_summary($userId, $limit, $assistantId);

        if (!\is_array($jobs)) {
            return ['active' => 0, 'pending' => 0, 'completed' => 0, 'failed' => 0, 'jobs' => []];
        }

        $counts = ['active' => 0, 'pending' => 0, 'completed' => 0, 'failed' => 0];

        foreach ($jobs as $job) {
            $status = $job['status'] ?? '';
            if ($status === 'active' || $status === 'running') { $counts['active']++; }
            elseif ($status === 'pending' || $status === 'scheduled') { $counts['pending']++; }
            elseif ($status === 'completed' || $status === 'success') { $counts['completed']++; }
            elseif ($status === 'failed' || $status === 'error') { $counts['failed']++; }
        }

        return ['active' => $counts['active'], 'pending' => $counts['pending'],
            'completed' => $counts['completed'], 'failed' => $counts['failed'], 'jobs' => $jobs];
    }

    public function getJob(string $jobId): array
    {
        if (!\class_exists('WP_MCP_AI_Cron_Status_Service')) {
            return ['found' => false];
        }

        $service = new \WP_MCP_AI_Cron_Status_Service();

        if (\method_exists($service, 'get_job_details')) {
            /** @var array|false $job */
            $job = $service->get_job_details($jobId);
            if ($job === false || $job === null) { return ['found' => false]; }
            return ['found' => true, 'job' => \is_array($job) ? $job : []];
        }

        return ['found' => false];
    }

    public function getCounts($assistantId = null): array
    {
        $summary = $this->getSummary(0, 1000, $assistantId);
        return [
            'active'    => $summary['active'],
            'pending'   => $summary['pending'],
            'completed' => $summary['completed'],
            'failed'    => $summary['failed'],
            'total'     => $summary['active'] + $summary['pending'] + $summary['completed'] + $summary['failed'],
        ];
    }
}
