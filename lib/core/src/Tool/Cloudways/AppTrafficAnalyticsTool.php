<?php

/**
 * One application's traffic analytics — `GET /app/{server}/{app}/analytics/traffic`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_App_Traffic_Analytics` (Proposal 164
 * section 164.135). A visit count and a bandwidth figure.
 *
 * The Cloudways MCP surface splits this endpoint four ways (top IPs, top bots,
 * top URLs, status codes) behind a `resource` argument. The WordPress toolkit's
 * tool has no such argument and reads only these two fields, so the port does
 * the same; adding the four breakdowns would be a new capability, not parity.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Cloudways;

class AppTrafficAnalyticsTool extends AppAnalyticsTool
{
    public function getSlug(): string
    {
        return 'cloudways_app_traffic_analytics';
    }

    public function getName(): string
    {
        return 'Cloudways: App Traffic Analytics';
    }

    public function getDescription(): string
    {
        return 'Returns an application\'s visit count and bandwidth. Read-only.';
    }

    protected function segment(): string
    {
        return 'analytics/traffic';
    }

    protected function block(): string
    {
        return 'traffic';
    }

    /**
     * @param array<string, mixed> $block The block the API returned.
     *
     * @return array<string, int|string>
     */
    protected function project(array $block): array
    {
        return array(
            'visits'    => $this->number($block['visits'] ?? 0),
            'bandwidth' => $this->text($block['bandwidth'] ?? null),
        );
    }

    protected function message(int $appId): string
    {
        return sprintf('Traffic analytics retrieved for app %d.', $appId);
    }
}
