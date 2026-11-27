<?php

/**
 * Send a Messenger message.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Send_Messenger_Message` (Proposal 164 section
 * 164.105). One `POST /me/messages` to a page-scoped user id.
 *
 * ## Corrections
 *
 * 1. **The recipient is validated as a PSID.** Facebook page-scoped user ids are
 *    numeric; WordPress passed `sanitize_text_field()` output through, so a
 *    value with a slash reached the JSON body unchanged. Nothing in the body is
 *    a path segment, so this is shape validation rather than traversal
 *    defence — but a malformed PSID is a guaranteed provider error.
 * 2. **The reply is projected** to the recipient and the message id, where
 *    WordPress returned the Graph document.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class SendMessengerMessageTool extends MessengerTool
{
    public function getSlug(): string
    {
        return 'send_messenger_message';
    }

    public function getName(): string
    {
        return 'Send Messenger Message';
    }

    public function getDescription(): string
    {
        return 'Send a text message to a Facebook Messenger user by page-scoped id. Returns the recipient and the message id.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'access_token' => array(
                    'type'        => 'string',
                    'description' => 'Facebook Page access token.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'recipient_id' => array(
                    'type'        => 'string',
                    'description' => 'Page-scoped user id (PSID) of the recipient.',
                    'pattern'     => '^[0-9]+$',
                ),
                'message'      => array(
                    'type'        => 'string',
                    'description' => 'Message text.',
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('access_token', 'recipient_id', 'message'),
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

        $token = $this->accessToken($arguments);

        if (! is_string($token)) {
            return $token;
        }

        $recipient = $this->textArg($arguments, 'recipient_id');

        if (1 !== preg_match('/^[0-9]{1,30}$/', $recipient)) {
            return $this->errors->validationFailed(
                'A numeric page-scoped recipient id is required.',
                array('recipient_id' => array('A numeric PSID is required.'))
            );
        }

        $message = $this->textArg($arguments, 'message');

        if ('' === $message) {
            return $this->errors->validationFailed(
                'Message text is required.',
                array('message' => array('Message text must be provided.'))
            );
        }

        try {
            $response = $this->apiPost(
                $token,
                '/me/messages',
                array(
                    'recipient' => array('id' => $recipient),
                    'message'   => array('text' => $message),
                )
            );
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $messageId = $this->text($response['message_id'] ?? '');

        return $this->success(
            'Message sent to ' . $recipient . '.',
            array(
                'recipient_id' => $recipient,
                'message_id'   => '' !== $messageId ? $messageId : null,
            )
        );
    }
}
