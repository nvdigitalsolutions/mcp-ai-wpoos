<?php

/**
 * One application's monitoring summary — `GET /app/{server}/{app}/monitor/summary`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_App_Monitor_Summary` (Proposal 164 section
 * 164.135). Requests and bandwidth, both banded strings in the API's own units.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Cloudways;

class AppMonitorSummaryTool extends AppAnalyticsTool
{
    public function getSlug(): string
    {
        return 'cloudways_app_monitor_summary';
    }

    public function getName(): string
    {
        return 'Cloudways: App Monitoring Summary';
    }

    public function getDescription(): string
    {
        return 'Returns an application\'s request count and bandwidth summary. Read-only.';
    }

    protected function segment(): string
    {
        return 'monitor/summary';
    }

    protected function block(): string
    {
        return 'summary';
    }

    /**
     * @param array<string, mixed> $block The block the API returned.
     *
     * @return array<string, string>
     */
    protected function project(array $block): array
    {
        return array(
            'requests'  => $this->text($block['requests'] ?? null),
            'bandwidth' => $this->text($block['bandwidth'] ?? null),
        );
    }

    protected function message(int $appId): string
    {
        return sprintf('Monitoring summary retrieved for app %d.', $appId);
    }
}
