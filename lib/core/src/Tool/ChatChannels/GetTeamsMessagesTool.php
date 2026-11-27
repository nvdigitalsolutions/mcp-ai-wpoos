<?php

/**
 * Read a Microsoft Teams channel's messages.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Get_Teams_Messages` (Proposal 164 section
 * 164.104). One `GET /teams/{team_id}/channels/{channel_id}/messages`.
 *
 * ## Corrections
 *
 * 1. **The channel id is a path segment.** WordPress interpolated
 *    `sanitize_text_field()` output, which leaves `/` intact; the id is
 *    validated to the characters a Teams channel id actually uses and then
 *    percent-encoded.
 * 2. **The next page is reachable** through `next_link`, where WordPress
 *    dropped `@odata.nextLink`.
 * 3. **Rows are projected**: id, reply target, type, subject, body text and
 *    type, sender display name, timestamps and web URL.
 * 4. **`top` is clamped** to 1-50; WordPress clamped it too, but read it with
 *    `absint()`, so a non-numeric value became 1 instead of the default.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class GetTeamsMessagesTool extends GraphTool
{
    private const DEFAULT_TOP = 50;
    private const MAX_TOP = 50;

    public function getSlug(): string
    {
        return 'get_teams_messages';
    }

    public function getName(): string
    {
        return 'Get Teams Messages';
    }

    public function getDescription(): string
    {
        return 'Read messages from a Microsoft Teams channel: id, reply target, type, subject, body text and content type, sender, timestamps and web URL. Pass a returned next_link to read the next page.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'token'      => array(
                    'type'        => 'string',
                    'description' => 'Microsoft Graph access token.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'team_id'    => array(
                    'type'        => 'string',
                    'description' => 'Team id as a GUID.',
                    'pattern'     => '^[0-9a-fA-F-]{36}$',
                ),
                'channel_id' => array(
                    'type'        => 'string',
                    'description' => 'Channel id, such as 19:abc123@thread.tacv2.',
                    'minLength'   => 1,
                ),
                'top'        => array(
                    'type'        => 'integer',
                    'description' => 'How many messages to return, 1-50. Defaults to 50.',
                    'minimum'     => 1,
                    'maximum'     => 50,
                ),
                'next_link'  => array(
                    'type'        => 'string',
                    'description' => 'next_link from a previous page. Must be an https Graph URL.',
                ),
            ),
            'required'             => array('token', 'team_id', 'channel_id'),
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

        $channelId = $this->channelId($arguments);

        if (! is_string($channelId)) {
            return $channelId;
        }

        $top = $this->limit($arguments['top'] ?? null, self::DEFAULT_TOP, self::MAX_TOP);

        $page = $this->page(
            $token,
            '/teams/' . $teamId . '/channels/' . $channelId . '/messages',
            array('$top' => $top),
            $arguments
        );

        if ($this->errors->isError($page)) {
            return $page;
        }

        $rows = array();

        foreach (is_array($page['value'] ?? null) ? $page['value'] : array() as $message) {
            $row = $this->chatMessageRow($message);

            if (array() !== $row) {
                $rows[] = $row;
            }
        }

        return $this->success(
            sprintf('Found %d message%s.', count($rows), 1 === count($rows) ? '' : 's'),
            array(
                'messages'  => $rows,
                'count'     => count($rows),
                'top'       => $top,
                'next_link' => $this->nextLink($page),
            )
        );
    }
}
