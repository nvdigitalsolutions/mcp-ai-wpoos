<?php

/**
 * Send a one-to-one Twitter/X Direct Message.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Send_Twitter_DM` (Proposal 164 section 164.107).
 *
 * ## Corrections
 *
 * 1. **Both ids are validated before they reach the path.** WordPress ran
 *    `sanitize_text_field()` over `recipient_id` and then interpolated it into
 *    `/2/dm_conversations/with/{id}/messages`; a value containing `/` or a dot
 *    sequence would address a different route. `recipient_id` is the documented
 *    `^[0-9]{1,19}$` and is encoded once.
 * 2. **The documented 10,000-character ceiling is enforced.** WordPress said
 *    "max 10,000 characters" in the schema and then sent whatever it was given;
 *    the API refuses it after the round trip. The refusal is local now.
 * 3. **The signature covers the URL it is sent to.** See `TwitterTool`; the
 *    four WordPress signed endpoints happened to have no query string, so its
 *    base-string construction was correct only by accident.
 *
 * ## Adaptations
 *
 * The optional API version/plan arguments some WordPress callers passed are
 * gone: the endpoint is v2 and the caller's access tier is not the tool's
 * business. The credential properties are `writeOnly`, as everywhere in this
 * family.
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

class SendTwitterDmTool extends TwitterTool
{
    public function getSlug(): string
    {
        return 'send_twitter_dm';
    }

    public function getName(): string
    {
        return 'Send Twitter/X DM';
    }

    public function getDescription(): string
    {
        return 'Sends a one-to-one Direct Message on Twitter/X, opening the conversation with the recipient if there is none. Requires an OAuth 1.0a user context with dm.write.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'recipient_id'        => array(
                    'type'        => 'string',
                    'description' => 'Numeric user id of the recipient. A username is not accepted.',
                    'pattern'     => '^[0-9]{1,19}$',
                ),
                'text'                => array(
                    'type'        => 'string',
                    'description' => 'Message text, up to 10,000 characters.',
                    'minLength'   => 1,
                    'maxLength'   => self::MAX_DM_LENGTH,
                ),
                'api_key'             => array(
                    'type'        => 'string',
                    'description' => 'OAuth 1.0a consumer key.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'api_secret'          => array(
                    'type'        => 'string',
                    'description' => 'OAuth 1.0a consumer secret.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'access_token'        => array(
                    'type'        => 'string',
                    'description' => 'OAuth 1.0a access token for the sending account.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'access_token_secret' => array(
                    'type'        => 'string',
                    'description' => 'OAuth 1.0a access token secret.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('recipient_id', 'text', 'api_key', 'api_secret', 'access_token', 'access_token_secret'),
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

        $recipient = $this->userId($arguments['recipient_id'] ?? null);

        if ('' === $recipient) {
            return $this->errors->validationFailed(
                'recipient_id must be a numeric Twitter/X user id.',
                array('recipient_id' => array('Not a numeric user id.'))
            );
        }

        $text = $this->dmText($arguments['text'] ?? null);

        if (! is_string($text)) {
            return $text;
        }

        $credentials = $this->oauth1($arguments);

        if ($this->errors->isError($credentials) || ! is_array($credentials)) {
            return $credentials;
        }

        $body = Json::encodeOr(array('text' => $text), '');

        if ('' === $body) {
            return $this->errors->validationFailed(
                'The DM text is not valid UTF-8.',
                array('text' => array('Not encodable.'))
            );
        }

        $url = self::API . '/2/dm_conversations/with/' . rawurlencode($recipient) . '/messages';

        try {
            $payload = $this->signed('POST', $url, $credentials, $body);
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : array();

        return $this->success(
            'Twitter/X DM sent.',
            array(
                'event_id'        => $this->scalarText($data['dm_event_id'] ?? null),
                'conversation_id' => $this->scalarText($data['dm_conversation_id'] ?? null),
                'recipient_id'    => $recipient,
                'text'            => $text,
            )
        );
    }
}
