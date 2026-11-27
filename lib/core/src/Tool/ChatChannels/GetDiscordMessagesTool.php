<?php

/**
 * Read a Discord channel's message history.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Get_Discord_Messages` (Proposal 164 section
 * 164.101). One `GET /channels/{id}/messages`, newest first, at most 100 per
 * call — Discord's limit, not ours.
 *
 * ## Corrections
 *
 * 1. **The channel id is validated** before it becomes a path segment.
 * 2. **The limit is clamped and always sent.** WordPress clamped it correctly
 *    but read it with `absint()`, so a fractional or string limit silently
 *    became something else; `limit()` accepts numeric input and refuses the
 *    rest to the default.
 * 3. **Rows are projected** to the message identity, author, content and
 *    timestamps. WordPress returned Discord's full message objects.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class GetDiscordMessagesTool extends DiscordTool
{
    public function getSlug(): string
    {
        return 'get_discord_messages';
    }

    public function getName(): string
    {
        return 'Get Discord Messages';
    }

    public function getDescription(): string
    {
        return 'Read recent messages from a Discord channel, newest first: id, channel, author id and username, content, sent time and edited time. Returns at most 100 messages.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'token'      => array(
                    'type'        => 'string',
                    'description' => 'Discord bot token.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'channel_id' => array(
                    'type'        => 'string',
                    'description' => 'Numeric Discord channel id, as returned by get_discord_channels.',
                    'pattern'     => '^[0-9]{1,20}$',
                ),
                'limit'      => array(
                    'type'        => 'integer',
                    'description' => 'How many messages to return, 1-100. Defaults to 50.',
                    'minimum'     => 1,
                    'maximum'     => 100,
                ),
            ),
            'required'             => array('token', 'channel_id'),
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

        $channelId = $this->snowflake($arguments, 'channel_id', 'channel id');

        if (! is_string($channelId)) {
            return $channelId;
        }

        $limit = $this->limit($arguments['limit'] ?? null, self::DEFAULT_LIMIT, self::MAX_LIMIT);

        try {
            $response = $this->apiGet(
                $token,
                '/channels/' . $channelId . '/messages',
                array('limit' => $limit)
            );
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $rows = array();

        foreach ($response as $message) {
            $row = $this->messageRow($message);

            if (array() !== $row) {
                $rows[] = $row;
            }
        }

        return $this->success(
            sprintf('Found %d message%s in channel %s.', count($rows), 1 === count($rows) ? '' : 's', $channelId),
            array(
                'messages' => $rows,
                'count'    => count($rows),
                'limit'    => $limit,
            )
        );
    }
}
