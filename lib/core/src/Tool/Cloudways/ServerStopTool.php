<?php

/**
 * Stop a server — `POST /server/{id}/stop`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_Server_Stop` (Proposal 164 section 164.139).
 * Every application on the server becomes unavailable, which is why the
 * description says so rather than calling it a pause.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Cloudways;

class ServerStopTool extends ServerLifecycleTool
{
    public function getSlug(): string
    {
        return 'cloudways_server_stop';
    }

    public function getName(): string
    {
        return 'Cloudways: Stop Server';
    }

    public function getDescription(): string
    {
        return 'Stops a running Cloudways server. Every application on it becomes unavailable until it is started again.';
    }

    protected function action(): string
    {
        return 'stop';
    }

    protected function message(int $serverId): string
    {
        return sprintf('Server %d stop initiated.', $serverId);
    }
}
