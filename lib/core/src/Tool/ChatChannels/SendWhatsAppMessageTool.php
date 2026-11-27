<?php

/**
 * Send a WhatsApp text message.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Send_WhatsApp_Message` (Proposal 164 section
 * 164.103). One `POST /{phone_number_id}/messages` of type `text`.
 *
 * ## Corrections
 *
 * 1. **`preview_url` is parsed, not cast.** WordPress used `(bool)`, which turns
 *    the string `"false"` into true, so a caller that asked for no link preview
 *    got one.
 * 2. **The reply is projected.** WordPress returned the Graph document; the
 *    reply here is the message id, the normalised recipient and the type, which
 *    is what a delivery check or a reply needs.
 *
 * ## Adaptations
 *
 * WordPress passed the body through `sanitize_textarea_field()`, which strips
 * HTML and keeps newlines. The core has no equivalent and WhatsApp renders the
 * text as plain text anyway, so the body is sent as given.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class SendWhatsAppMessageTool extends WhatsAppTool
{
    public function getSlug(): string
    {
        return 'send_whatsapp_message';
    }

    public function getName(): string
    {
        return 'Send WhatsApp Message';
    }

    public function getDescription(): string
    {
        return 'Send a free-form text message through the WhatsApp Cloud API to a phone number or group. Returns the WhatsApp message id and the normalised recipient.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'access_token'    => array(
                    'type'        => 'string',
                    'description' => 'WhatsApp Cloud API access token.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'phone_number_id' => array(
                    'type'        => 'string',
                    'description' => 'Numeric id of the sending phone number.',
                    'pattern'     => '^[0-9]+$',
                ),
                'to'              => array(
                    'type'        => 'string',
                    'description' => 'E.164 phone number such as +1234567890, or a group id such as 120363...@g.us.',
                    'minLength'   => 1,
                ),
                'recipient_type'  => array(
                    'type'        => 'string',
                    'description' => 'individual for a phone number, group for a group id. Defaults to individual.',
                    'enum'        => array('individual', 'group'),
                    'default'     => 'individual',
                ),
                'text'            => array(
                    'type'        => 'string',
                    'description' => 'Message text.',
                    'minLength'   => 1,
                ),
                'preview_url'     => array(
                    'type'        => 'boolean',
                    'description' => 'Render link previews in the message. Defaults to true.',
                    'default'     => true,
                ),
            ),
            'required'             => array('access_token', 'phone_number_id', 'to', 'text'),
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

        $phoneId = $this->phoneNumberId($arguments);

        if (! is_string($phoneId)) {
            return $phoneId;
        }

        $recipient = $this->recipient($arguments);

        if (! is_array($recipient)) {
            return $recipient;
        }

        $text = $this->textArg($arguments, 'text');

        if ('' === $text) {
            return $this->errors->validationFailed(
                'Message text is required.',
                array('text' => array('Message text must be provided.'))
            );
        }

        $preview = array_key_exists('preview_url', $arguments)
            ? $this->boolParam($arguments, 'preview_url')
            : true;

        try {
            $response = $this->apiPost(
                $token,
                '/' . $phoneId . '/messages',
                array(
                    'messaging_product' => 'whatsapp',
                    'recipient_type'    => $recipient['type'],
                    'to'                => $recipient['to'],
                    'type'              => 'text',
                    'text'              => array(
                        'body'        => $text,
                        'preview_url' => $preview,
                    ),
                )
            );
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $sent = $this->sent($response);

        if ('' === $sent['message_id']) {
            return $this->errors->create(
                'whatsapp_invalid_response',
                'WhatsApp accepted the message but returned no message id.'
            );
        }

        return $this->success(
            'Message sent to ' . $recipient['to'] . '.',
            array(
                'message_id' => $sent['message_id'],
                'wa_id'      => $sent['wa_id'],
                'to'         => $recipient['to'],
                'type'       => 'text',
            )
        );
    }
}
