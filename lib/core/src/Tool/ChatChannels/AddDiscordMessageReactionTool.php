<?php

/**
 * Add an emoji reaction to a Discord message.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Add_Discord_Message_Reaction` (Proposal 164
 * section 164.101). One `PUT
 * /channels/{id}/messages/{id}/reactions/{emoji}/@me`; Discord answers 204 No
 * Content, which is the whole reply.
 *
 * ## Corrections
 *
 * 1. **Both ids are validated** before they become path segments. WordPress
 *    passed `sanitize_text_field()` output, which leaves `/` and `..` intact,
 *    into the URL.
 * 2. **Success is any 2xx.** WordPress required exactly 204.
 * 3. **The tool report does not repeat `success`.** WordPress returned
 *    `success => true` inside its array; the envelope already says that, so the
 *    data carries what was done instead.
 *
 * The emoji is URL-encoded once, in the path only. A custom emoji is
 * `name:id`; a Unicode emoji is passed as itself.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class AddDiscordMessageReactionTool extends DiscordTool
{
    public function getSlug(): string
    {
        return 'add_discord_message_reaction';
    }

    public function getName(): string
    {
        return 'Add Discord Message Reaction';
    }

    public function getDescription(): string
    {
        return 'Add an emoji reaction to a Discord message as the bot. Accepts a Unicode emoji or a custom guild emoji in name:id form.';
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
                    'description' => 'Numeric id of the channel containing the message.',
                    'pattern'     => '^[0-9]{1,20}$',
                ),
                'message_id' => array(
                    'type'        => 'string',
                    'description' => 'Numeric id of the message to react to.',
                    'pattern'     => '^[0-9]{1,20}$',
                ),
                'emoji'      => array(
                    'type'        => 'string',
                    'description' => 'Unicode emoji, or a custom guild emoji as name:id.',
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('token', 'channel_id', 'message_id', 'emoji'),
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

        $channelId = $this->snowflake($arguments, 'channel_id', 'channel id');

        if (! is_string($channelId)) {
            return $channelId;
        }

        $messageId = $this->snowflake($arguments, 'message_id', 'message id');

        if (! is_string($messageId)) {
            return $messageId;
        }

        $emoji = $this->textArg($arguments, 'emoji');

        if ('' === $emoji) {
            return $this->errors->validationFailed(
                'An emoji is required.',
                array('emoji' => array('An emoji is required.'))
            );
        }

        $path = '/channels/' . $channelId
            . '/messages/' . $messageId
            . '/reactions/' . rawurlencode($emoji)
            . '/@me';

        try {
            $this->apiPut($token, $path);
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        return $this->success(
            'Reaction added to message ' . $messageId . '.',
            array(
                'channel_id' => $channelId,
                'message_id' => $messageId,
                'emoji'      => $emoji,
                'added'      => true,
            )
        );
    }
}
