<?php

/**
 * Create a Discord channel.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Create_Discord_Channel` (Proposal 164 section
 * 164.101). One `POST /guilds/{id}/channels`; Discord answers 201 with the new
 * channel object.
 *
 * ## Corrections
 *
 * 1. **An invalid type is refused, not coerced.** WordPress ran `absint()` and
 *    silently replaced anything outside 0/2/4/5 with 0, so a caller that asked
 *    for a forum channel got a text channel and no signal. The schema enum and
 *    a local check both refuse it.
 * 2. **The guild id is validated** before it becomes a path segment.
 * 3. **Success is any 2xx.** WordPress required exactly 201, which is what
 *    Discord sends; the broader check keeps a conforming proxy answer working.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class CreateDiscordChannelTool extends DiscordTool
{
    public function getSlug(): string
    {
        return 'create_discord_channel';
    }

    public function getName(): string
    {
        return 'Create Discord Channel';
    }

    public function getDescription(): string
    {
        return 'Create a channel in a Discord guild: text, voice, category or announcement. Returns the new channel id, name, type, position and parent category. The bot needs Manage Channels.';
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
                'name'     => array(
                    'type'        => 'string',
                    'description' => 'Channel name, 2-100 characters.',
                    'minLength'   => 2,
                    'maxLength'   => 100,
                ),
                'type'     => array(
                    'type'        => 'integer',
                    'description' => '0 text, 2 voice, 4 category, 5 announcement. Defaults to 0.',
                    'enum'        => array(0, 2, 4, 5),
                    'default'     => 0,
                ),
            ),
            'required'             => array('token', 'guild_id', 'name'),
            'additionalProperties' => false,
        );
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array(
            'external-api',
            'network-dependent',
            'requires-credentials',
            'state-changing',
            'write',
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

        $name = $this->textArg($arguments, 'name');

        if ('' === $name) {
            return $this->errors->validationFailed(
                'A channel name is required.',
                array('name' => array('A channel name is required.'))
            );
        }

        $type = array_key_exists('type', $arguments) && is_numeric($arguments['type'])
            ? (int) $arguments['type']
            : 0;

        if (! in_array($type, self::CHANNEL_TYPES, true)) {
            return $this->errors->validationFailed(
                'The channel type must be 0 (text), 2 (voice), 4 (category) or 5 (announcement).',
                array('type' => array('Unsupported channel type.'))
            );
        }

        try {
            $response = $this->apiPost(
                $token,
                '/guilds/' . $guildId . '/channels',
                array(
                    'name' => $name,
                    'type' => $type,
                )
            );
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $channel = $this->channelRow($response);

        if ('' === $channel['id']) {
            return $this->errors->create(
                'discord_invalid_response',
                'Discord created the channel but returned no channel object.'
            );
        }

        return $this->success(
            'Created Discord channel ' . ('' !== $channel['name'] ? $channel['name'] : $name) . '.',
            array(
                'id'        => $channel['id'],
                'name'      => '' !== $channel['name'] ? $channel['name'] : $name,
                'type'      => $channel['type'],
                'position'  => $channel['position'],
                'parent_id' => $channel['parent_id'],
            )
        );
    }
}
