<?php

/**
 * Send a WhatsApp template message.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Send_WhatsApp_Template` (Proposal 164 section
 * 164.103). One `POST /{phone_number_id}/messages` of type `template`.
 *
 * A template is the only way to message a recipient outside the 24-hour
 * service window, and it must already be approved in the business account;
 * Meta refuses an unknown or unapproved name.
 *
 * ## Corrections
 *
 * 1. **`language_code` falls back to `en_US` only when it is absent.** WordPress
 *    took any string and passed it through, so an empty value became an empty
 *    language rather than the documented default.
 * 2. **The reply is projected** to the message id, the recipient, the template
 *    name and the language actually requested.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class SendWhatsAppTemplateTool extends WhatsAppTool
{
    public function getSlug(): string
    {
        return 'send_whatsapp_template';
    }

    public function getName(): string
    {
        return 'Send WhatsApp Template';
    }

    public function getDescription(): string
    {
        return 'Send a pre-approved WhatsApp template message, which is the only way to reach a recipient outside the 24-hour service window. Returns the WhatsApp message id and the normalised recipient.';
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
                'template_name'   => array(
                    'type'        => 'string',
                    'description' => 'Name of the approved template.',
                    'minLength'   => 1,
                ),
                'language_code'   => array(
                    'type'        => 'string',
                    'description' => 'Template language code such as en_US. Defaults to en_US.',
                    'default'     => 'en_US',
                ),
                'components'      => array(
                    'type'        => 'array',
                    'description' => 'Optional template components: header, body and button parameters.',
                    'items'       => array('type' => 'object'),
                ),
            ),
            'required'             => array('access_token', 'phone_number_id', 'to', 'template_name'),
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

        $name = $this->textArg($arguments, 'template_name');

        if ('' === $name) {
            return $this->errors->validationFailed(
                'A template name is required.',
                array('template_name' => array('A template name is required.'))
            );
        }

        $language = $this->textArg($arguments, 'language_code');

        if ('' === $language) {
            $language = 'en_US';
        }

        $template = array(
            'name'     => $name,
            'language' => array('code' => $language),
        );

        $components = $this->arrayParam($arguments, 'components');

        if (array() !== $components) {
            $template['components'] = $components;
        }

        try {
            $response = $this->apiPost(
                $token,
                '/' . $phoneId . '/messages',
                array(
                    'messaging_product' => 'whatsapp',
                    'recipient_type'    => $recipient['type'],
                    'to'                => $recipient['to'],
                    'type'              => 'template',
                    'template'          => $template,
                )
            );
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $sent = $this->sent($response);

        if ('' === $sent['message_id']) {
            return $this->errors->create(
                'whatsapp_invalid_response',
                'WhatsApp accepted the template but returned no message id.'
            );
        }

        return $this->success(
            'Template ' . $name . ' sent to ' . $recipient['to'] . '.',
            array(
                'message_id'    => $sent['message_id'],
                'wa_id'         => $sent['wa_id'],
                'to'            => $recipient['to'],
                'type'          => 'template',
                'template_name' => $name,
                'language_code' => $language,
            )
        );
    }
}
