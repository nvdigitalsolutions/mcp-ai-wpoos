<?php

/**
 * List a Discord guild's channels.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Get_Discord_Channels` (Proposal 164 section
 * 164.101). One `GET /guilds/{id}/channels`, which returns the whole guild
 * tree in a single unpaginated array.
 *
 * ## Corrections
 *
 * 1. **The guild id is validated**, not merely sanitised, before it becomes a
 *    path segment.
 * 2. **Rows are projected** to id, name, type, position and parent, which is
 *    what a caller needs to pick a channel and understand the tree. WordPress
 *    returned Discord's objects verbatim, permission overwrites included.
 * 3. **A non-array body is an empty list.** Discord always returns an array
 *    here, but a malformed gateway answer should read as "no channels" rather
 *    than reach the caller as a scalar inside a list.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class GetDiscordChannelsTool extends DiscordTool
{
    public function getSlug(): string
    {
        return 'get_discord_channels';
    }

    public function getName(): string
    {
        return 'Get Discord Channels';
    }

    public function getDescription(): string
    {
        return 'List the channels in a Discord guild: id, name, type (0 text, 2 voice, 4 category, 5 announcement), position and parent category. Use the id to read or post in a channel.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'token'    => array(
                    'type'        => 'string',
                    'description' => 'Discord bot token.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'guild_id' => array(
                    'type'        => 'string',
                    'description' => 'Numeric Discord guild (server) id.',
                    'pattern'     => '^[0-9]{1,20}$',
                ),
            ),
            'required'             => array('token', 'guild_id'),
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

        $guildId = $this->snowflake($arguments, 'guild_id', 'guild id');

        if (! is_string($guildId)) {
            return $guildId;
        }

        try {
            $response = $this->apiGet($token, '/guilds/' . $guildId . '/channels');
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $rows = array();

        foreach ($response as $channel) {
            $row = $this->channelRow($channel);

            if (array() !== $row) {
                $rows[] = $row;
            }
        }

        return $this->success(
            sprintf('Found %d channel%s in guild %s.', count($rows), 1 === count($rows) ? '' : 's', $guildId),
            array(
                'channels' => $rows,
                'count'    => count($rows),
            )
        );
    }
}
