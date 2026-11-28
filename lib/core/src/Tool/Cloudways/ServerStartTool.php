<?php

/**
 * Start a server — `POST /server/{id}/start`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_Server_Start` (Proposal 164 section
 * 164.139). The API answers an operation.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Cloudways;

class ServerStartTool extends ServerLifecycleTool
{
    public function getSlug(): string
    {
        return 'cloudways_server_start';
    }

    public function getName(): string
    {
        return 'Cloudways: Start Server';
    }

    public function getDescription(): string
    {
        return 'Starts a stopped Cloudways server and makes its applications available again.';
    }

    protected function action(): string
    {
        return 'start';
    }

    protected function message(int $serverId): string
    {
        return sprintf('Server %d start initiated.', $serverId);
    }
}
