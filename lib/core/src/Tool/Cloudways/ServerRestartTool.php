<?php

/**
 * Restart a server — `POST /server/{id}/restart`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_Server_Restart` (Proposal 164 section
 * 164.139). The server is briefly unavailable; the API answers an operation.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Cloudways;

class ServerRestartTool extends ServerLifecycleTool
{
    public function getSlug(): string
    {
        return 'cloudways_server_restart';
    }

    public function getName(): string
    {
        return 'Cloudways: Restart Server';
    }

    public function getDescription(): string
    {
        return 'Restarts a Cloudways server. All applications on it are briefly unavailable.';
    }

    protected function action(): string
    {
        return 'restart';
    }

    protected function message(int $serverId): string
    {
        return sprintf('Server %d restart initiated.', $serverId);
    }
}
