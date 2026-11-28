<?php

/**
 * One application's MySQL analytics — `GET /app/{server}/{app}/analytics/mysql`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_App_MySQL_Analytics` (Proposal 164 section
 * 164.135). Two counts, both integers.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Cloudways;

class AppMysqlAnalyticsTool extends AppAnalyticsTool
{
    public function getSlug(): string
    {
        return 'cloudways_app_mysql_analytics';
    }

    public function getName(): string
    {
        return 'Cloudways: App MySQL Analytics';
    }

    public function getDescription(): string
    {
        return 'Returns an application\'s slow-query count and total query count. Read-only.';
    }

    protected function segment(): string
    {
        return 'analytics/mysql';
    }

    protected function block(): string
    {
        return 'mysql_analytics';
    }

    /**
     * @param array<string, mixed> $block The block the API returned.
     *
     * @return array<string, int>
     */
    protected function project(array $block): array
    {
        return array(
            'slow_queries' => $this->number($block['slow_queries'] ?? 0),
            'query_count'  => $this->number($block['query_count'] ?? 0),
        );
    }

    protected function message(int $appId): string
    {
        return sprintf('MySQL analytics retrieved for app %d.', $appId);
    }
}
