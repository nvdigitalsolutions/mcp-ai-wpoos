<?php

/**
 * List a Microsoft Teams team's channels.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Get_Teams_Channels` (Proposal 164 section
 * 164.104). One `GET /teams/{team_id}/channels`.
 *
 * ## Corrections
 *
 * 1. **The team id is validated as a GUID** and the channel path is composed
 *    from encoded segments. WordPress interpolated `sanitize_text_field()`
 *    output into the path, which leaves `/` and `..` intact.
 * 2. **The next page is reachable.** Graph returns `@odata.nextLink`; WordPress
 *    dropped it, so only the first page of a large team was ever visible. The
 *    reply carries `next_link` when Graph sends one.
 * 3. **Rows are projected** to id, display name, description, membership type
 *    and web URL, where WordPress returned Graph's objects verbatim.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class GetTeamsChannelsTool extends GraphTool
{
    public function getSlug(): string
    {
        return 'get_teams_channels';
    }

    public function getName(): string
    {
        return 'Get Teams Channels';
    }

    public function getDescription(): string
    {
        return 'List the channels in a Microsoft Teams team: id, display name, description, membership type and web URL. Pass a returned next_link to read the next page.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'token'     => array(
                    'type'        => 'string',
                    'description' => 'Microsoft Graph access token.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'team_id'   => array(
                    'type'        => 'string',
                    'description' => 'Team id as a GUID.',
                    'pattern'     => '^[0-9a-fA-F-]{36}$',
                ),
                'next_link' => array(
                    'type'        => 'string',
                    'description' => 'next_link from a previous page. Must be an https Graph URL.',
                ),
            ),
            'required'             => array('token', 'team_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $token = $this->token($arguments);

        if (! is_string($token)) {
            return $token;
        }

        $teamId = $this->teamId($arguments);

        if (! is_string($teamId)) {
            return $teamId;
        }

        $page = $this->page($token, '/teams/' . $teamId . '/channels', array(), $arguments);

        if ($this->errors->isError($page)) {
            return $page;
        }

        $rows = array();

        foreach (is_array($page['value'] ?? null) ? $page['value'] : array() as $channel) {
            $row = $this->channelRow($channel);

            if (array() !== $row) {
                $rows[] = $row;
            }
        }

        return $this->success(
            sprintf('Found %d channel%s.', count($rows), 1 === count($rows) ? '' : 's'),
            array(
                'channels'  => $rows,
                'count'     => count($rows),
                'next_link' => $this->nextLink($page),
            )
        );
    }
}
