<?php

/**
 * Send a text message through an Apple Messages for Business provider.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Send_Apple_Message` (Proposal 164 section
 * 164.108). One POST of the family envelope to the caller's MSP endpoint.
 *
 * See `AppleTool` for the platform model and the four corrections the Apple
 * tools share. The correction specific to this tool is that a message must be
 * addressed: WordPress required neither `conversation_id` nor `recipient_id`,
 * although both of its own property descriptions say one is needed, so a request
 * that no gateway can route was sent and failed remotely.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

use Nvoos\Core\Support\Json;

class SendAppleMessageTool extends AppleTool
{
    public function getSlug(): string
    {
        return 'send_apple_message';
    }

    public function getName(): string
    {
        return 'Send Apple Message';
    }

    public function getDescription(): string
    {
        return 'Sends a text message to an Apple Messages for Business conversation through your approved Messaging Service Provider. Apple has no API a business calls directly, so the endpoint and token are your provider\'s, and the JSON envelope must be one your provider accepts.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'msp_api_url'     => array(
                    'type'        => 'string',
                    'description' => 'Your provider\'s public https endpoint for sending Apple Messages. Never echoed back.',
                ),
                'api_key'         => array(
                    'type'        => 'string',
                    'description' => 'Bearer token issued by your provider.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'business_id'     => array(
                    'type'        => 'string',
                    'description' => 'Apple Messages for Business id issued during registration.',
                    'minLength'   => 1,
                    'maxLength'   => 128,
                ),
                'conversation_id' => array(
                    'type'        => 'string',
                    'description' => 'Conversation to continue. Supply this or recipient_id.',
                ),
                'recipient_id'    => array(
                    'type'        => 'string',
                    'description' => 'Opaque customer identifier Apple supplied when the conversation started. Supply this or conversation_id.',
                ),
                'message'         => array(
                    'type'        => 'string',
                    'description' => 'Plain-text body, up to 2,000 characters.',
                    'minLength'   => 1,
                    'maxLength'   => self::MAX_MESSAGE_LENGTH,
                ),
                'intent'          => array(
                    'type'        => 'string',
                    'description' => 'Intent string from a Message Us entry point, used to route the conversation.',
                ),
                'locale'          => array(
                    'type'        => 'string',
                    'description' => 'BCP 47 locale of the message. Defaults to en-US.',
                    'default'     => self::DEFAULT_LOCALE,
                ),
            ),
            'required'             => array('msp_api_url', 'api_key', 'business_id', 'message'),
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

        $url = $this->gatewayUrl($arguments, 'msp_api_url', 'provider endpoint');

        if (! is_string($url)) {
            return $url;
        }

        $token = $this->gatewayKey($arguments);

        if (! is_string($token)) {
            return $token;
        }

        $business = $this->businessId($arguments);

        if (! is_string($business)) {
            return $business;
        }

        $message = $this->appleMessage($arguments);

        if (! is_string($message)) {
            return $message;
        }

        $addressing = $this->conversation($arguments);

        if ($this->errors->isError($addressing) || ! is_array($addressing)) {
            return $addressing;
        }

        $locale = $this->locale($arguments);

        if (! is_string($locale)) {
            return $locale;
        }

        $payload = array_merge(
            array(
                'businessId' => $business,
                'body'       => array('text' => $message),
                'type'       => 'text',
                'locale'     => $locale,
            ),
            $addressing
        );

        $intent = $this->intent($arguments);

        if ('' !== $intent) {
            $payload['intent'] = $intent;
        }

        $body = Json::encodeOr($payload, '');

        if ('' === $body) {
            return $this->errors->validationFailed(
                'The message could not be encoded as JSON.',
                array('message' => array('Not encodable.'))
            );
        }

        try {
            $response = $this->gatewayPost($url, $token, $body);
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        return $this->success(
            'Apple Message sent.',
            array(
                'business_id'     => $business,
                'type'            => 'text',
                'conversation_id' => $addressing['conversationId'] ?? '',
                'recipient_id'    => $addressing['recipientId'] ?? '',
                'text_length'     => mb_strlen($message),
                'message_id'      => $this->stringValue($response['messageId'] ?? $response['id'] ?? null),
                'response'        => $response,
            )
        );
    }
}
