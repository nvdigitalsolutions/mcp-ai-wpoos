<?php

/**
 * Send a message through a Google Chat incoming webhook.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Send_Google_Chat_Message` (Proposal 164 section
 * 164.105). One POST of `{text}` to the caller's webhook URL.
 *
 * The webhook URL carries its own key, so there is no bearer token. That makes
 * the endpoint the credential, and it is why the guard matters: WordPress
 * validated the URL with `esc_url_raw()` and `FILTER_VALIDATE_URL` only, which
 * accepts `http://` and an internal address. A model that supplied
 * `http://169.254.169.254/…` would have made the server POST to it.
 *
 * ## Corrections
 *
 * 1. **The URL must be a public https endpoint.** `publicUrl()` rejects a
 *    non-https scheme, userinfo, and literal private, loopback, link-local or
 *    reserved addresses, plus local hostnames.
 * 2. **The URL is never echoed.** The reply names the outcome, not the endpoint,
 *    because the endpoint is the secret.
 * 3. **A failed send reports the status.** WordPress's error dropped the body
 *    and any provider message.
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

class SendGoogleChatMessageTool extends GoogleChatTool
{
    public function getSlug(): string
    {
        return 'send_google_chat_message';
    }

    public function getName(): string
    {
        return 'Send Google Chat Message';
    }

    public function getDescription(): string
    {
        return 'Post a text message to a Google Chat space through an incoming webhook URL. The URL carries the auth key, so treat it as a secret; it is never echoed back.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'webhook_url' => array(
                    'type'        => 'string',
                    'description' => 'Google Chat incoming webhook URL: an absolute public https URL.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'text'        => array(
                    'type'        => 'string',
                    'description' => 'Message text. Google Chat renders basic markdown.',
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('webhook_url', 'text'),
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

        $url = $this->publicUrl($arguments, 'webhook_url', 'webhook URL');

        if (! is_string($url)) {
            return $url;
        }

        $text = $this->textArg($arguments, 'text');

        if ('' === $text) {
            return $this->errors->validationFailed(
                'A message text is required.',
                array('text' => array('Message text must be provided.'))
            );
        }

        try {
            $response = $this->request(
                'POST',
                $url,
                array('Content-Type' => 'application/json; charset=UTF-8'),
                Json::encode(array('text' => $text))
            );
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $decoded = $this->decode($response);

        if (200 > $response->statusCode || 300 <= $response->statusCode) {
            $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : array();

            $message = $this->text($error['message'] ?? '');

            return $this->failure(
                new ChatChannelApiException(
                    '' !== $message
                        ? 'Google Chat API error: ' . $message
                        : sprintf('Google Chat returned HTTP %d.', $response->statusCode),
                    'google_chat_api_error',
                    $response->statusCode,
                    $this->text($error['status'] ?? ''),
                    $decoded
                )
            );
        }

        return $this->success(
            'Message posted to the Google Chat space.',
            array(
                'sent'         => true,
                'message_name' => $this->text($decoded['name'] ?? ''),
                'text'         => '' !== $this->text($decoded['text'] ?? '')
                    ? $this->text($decoded['text'])
                    : $text,
            )
        );
    }
}
