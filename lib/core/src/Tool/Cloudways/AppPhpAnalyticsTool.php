<?php

/**
 * One application's PHP analytics — `GET /app/{server}/{app}/analytics/php`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_App_PHP_Analytics` (Proposal 164 section
 * 164.135). A response time and a slow-page count.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Cloudways;

class AppPhpAnalyticsTool extends AppAnalyticsTool
{
    public function getSlug(): string
    {
        return 'cloudways_app_php_analytics';
    }

    public function getName(): string
    {
        return 'Cloudways: App PHP Analytics';
    }

    public function getDescription(): string
    {
        return 'Returns an application\'s PHP response time and slow-page count. Read-only.';
    }

    protected function segment(): string
    {
        return 'analytics/php';
    }

    protected function block(): string
    {
        return 'php_analytics';
    }

    /**
     * @param array<string, mixed> $block The block the API returned.
     *
     * @return array<string, int|string>
     */
    protected function project(array $block): array
    {
        return array(
            'response_time' => $this->text($block['response_time'] ?? null),
            'slow_pages'    => $this->number($block['slow_pages'] ?? 0),
        );
    }

    protected function message(int $appId): string
    {
        return sprintf('PHP analytics retrieved for app %d.', $appId);
    }
}
