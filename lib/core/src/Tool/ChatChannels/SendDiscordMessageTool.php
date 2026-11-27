<?php

/**
 * Post a message to Discord.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Send_Discord_Message` (Proposal 164 section
 * 164.101). One `POST /channels/{id}/messages`; the reply is the created
 * message, projected, because its `id` is what a reaction or a reply needs.
 *
 * ## Corrections
 *
 * 1. **The channel id is validated, not merely sanitised.** WordPress passed
 *    `sanitize_text_field()`, which leaves `/` and `..` intact, straight into
 *    the path. A snowflake is required here.
 * 2. **Success is any 2xx.** WordPress required exactly 200; a conforming 201
 *    from a proxy would have been reported as a failure.
 * 3. **The reply is projected.** WordPress forwarded the whole message
 *    object, including the author's avatar, flags and mentions.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class SendDiscordMessageTool extends DiscordTool
{
    public function getSlug(): string
    {
        return 'send_discord_message';
    }

    public function getName(): string
    {
        return 'Send Discord Message';
    }

    public function getDescription(): string
    {
        return 'Post a text message to a Discord channel. Returns the message id, channel, content and timestamp; the id is what a reaction or a threaded reply needs.';
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
                    'description' => 'Numeric Discord channel id to post in.',
                    'pattern'     => '^[0-9]{1,20}$',
                ),
                'content'    => array(
                    'type'        => 'string',
                    'description' => 'Message text.',
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('token', 'channel_id', 'content'),
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

        $content = $this->textArg($arguments, 'content');

        if ('' === $content) {
            return $this->errors->validationFailed(
                'Message content is required.',
                array('content' => array('Message content must be provided.'))
            );
        }

        try {
            $response = $this->apiPost($token, '/channels/' . $channelId . '/messages', array('content' => $content));
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $message = $this->messageRow($response);

        if ('' === $message['id']) {
            return $this->errors->create(
                'discord_invalid_response',
                'Discord accepted the message but returned no message object.'
            );
        }

        return $this->success(
            'Message posted to channel ' . $channelId . '.',
            array(
                'id'         => $message['id'],
                'channel_id' => '' !== $message['channel_id'] ? $message['channel_id'] : $channelId,
                'content'    => $message['content'],
                'timestamp'  => $message['timestamp'],
            )
        );
    }
}
