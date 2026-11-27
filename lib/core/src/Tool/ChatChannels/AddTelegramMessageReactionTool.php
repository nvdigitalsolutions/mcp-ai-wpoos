<?php

/**
 * Set the bot's reaction on a Telegram message.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Add_Telegram_Message_Reaction` (Proposal 164
 * section 164.102). One `setMessageReaction`; a bot holds a single reaction per
 * message, so this replaces rather than accumulates.
 *
 * ## Corrections
 *
 * 1. **The message id is required to be positive.** WordPress ran `absint()`
 *    and refused zero, which is right; the port refuses a non-numeric value
 *    too instead of turning it into zero.
 * 2. **The reply is projected** to the target and the emoji, where WordPress
 *    returned Telegram's `{ok, result}` envelope.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class AddTelegramMessageReactionTool extends TelegramTool
{
    public function getSlug(): string
    {
        return 'add_telegram_message_reaction';
    }

    public function getName(): string
    {
        return 'Add Telegram Message Reaction';
    }

    public function getDescription(): string
    {
        return 'Set the bot reaction on a Telegram message to one emoji. A bot holds one reaction per message, so a second call replaces the first. Requires Telegram Bot API 7.0 or later.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'token'      => array(
                    'type'        => 'string',
                    'description' => 'Telegram bot token.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'chat_id'    => array(
                    'type'        => array('string', 'integer'),
                    'description' => 'Numeric chat id or a channel username such as @channelname.',
                ),
                'message_id' => array(
                    'type'        => 'integer',
                    'description' => 'Id of the message to react to.',
                    'minimum'     => 1,
                ),
                'emoji'      => array(
                    'type'        => 'string',
                    'description' => 'One Telegram-supported reaction emoji, e.g. a thumbs up.',
                    'minLength'   => 1,
                ),
                'is_big'     => array(
                    'type'        => 'boolean',
                    'description' => 'Play the big reaction animation. Defaults to false.',
                    'default'     => false,
                ),
            ),
            'required'             => array('token', 'chat_id', 'message_id', 'emoji'),
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

        $chatId = $this->chatId($arguments['chat_id'] ?? null);

        if ('' === $chatId) {
            return $this->errors->validationFailed(
                'A chat id is required.',
                array('chat_id' => array('A chat id is required.'))
            );
        }

        $messageId = array_key_exists('message_id', $arguments) && is_numeric($arguments['message_id'])
            ? (int) $arguments['message_id']
            : 0;

        if ($messageId < 1) {
            return $this->errors->validationFailed(
                'A valid message id is required.',
                array('message_id' => array('A message id must be a positive integer.'))
            );
        }

        $emoji = $this->textArg($arguments, 'emoji');

        if ('' === $emoji) {
            return $this->errors->validationFailed(
                'An emoji is required.',
                array('emoji' => array('An emoji is required.'))
            );
        }

        $isBig = $this->boolParam($arguments, 'is_big');

        try {
            $this->apiPost(
                $token,
                'setMessageReaction',
                array(
                    'chat_id'    => $chatId,
                    'message_id' => $messageId,
                    'reaction'   => array(
                        array(
                            'type'  => 'emoji',
                            'emoji' => $emoji,
                        ),
                    ),
                    'is_big'     => $isBig,
                )
            );
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        return $this->success(
            'Reaction set on message ' . $messageId . '.',
            array(
                'chat_id'    => $chatId,
                'message_id' => $messageId,
                'emoji'      => $emoji,
                'is_big'     => $isBig,
            )
        );
    }
}
