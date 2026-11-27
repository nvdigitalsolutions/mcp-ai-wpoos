<?php

/**
 * Shared ground for the four Apple Messages for Business tools.
 *
 * Proposal 164 section 164.108. Apple Messages for Business has no API a
 * business calls directly: sending requires an approved Messaging Service
 * Provider (Infobip, Zendesk, Sunshine Conversations, LivePerson, CM.com, and
 * others), and Apple publishes the MSP REST API that those providers implement.
 * The endpoint, the token and the exact envelope therefore belong to the
 * provider, which is why every tool in this family takes `msp_api_url`.
 *
 * The envelope these tools build — `businessId`, `type`, `body.text`, `locale`,
 * `conversationId`, `recipientId`, `intent` — follows Apple's conceptual model
 * and the WordPress plugin's own convention. It is **not** Apple's MSP wire
 * format: Apple's native shape nests the payload under
 * `interactiveData.data.<camelCaseType>` and refers to images by separately
 * uploaded identifier. A gateway that expects the native shape must translate;
 * the tool's contract is "POST this JSON to this URL", which it honours.
 *
 * ## Corrections shared by the four tools
 *
 * 1. **The endpoint must be a public https URL.** WordPress checked
 *    `FILTER_VALIDATE_URL` and an `https://` prefix, which accepts
 *    `https://127.0.0.1/` and `https://user:pass@host/`. The bearer token travels
 *    with the request, so that is both an SSRF surface and a credential leak.
 * 2. **An over-long message is refused, not truncated.** WordPress capped the
 *    body at 2,000 characters with `mb_substr()` and sent the shortened text, so
 *    a caller's message arrived cut mid-sentence with no indication.
 * 3. **The locale is validated.** WordPress passed any string; a malformed tag
 *    is refused locally rather than by the gateway.
 * 4. **The endpoint is never echoed.** A gateway URL may carry a key in its
 *    query string.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

abstract class AppleTool extends GatewayTool
{
    /**
     * Apple Messages for Business documents a 2,000-character soft limit.
     */
    protected const MAX_MESSAGE_LENGTH = 2000;

    /**
     * A group conversation supports this many participants.
     */
    protected const MAX_PARTICIPANTS = 32;

    /**
     * A list picker supports this many sections.
     */
    protected const MAX_PICKER_SECTIONS = 10;

    /**
     * A list picker section supports this many items.
     */
    protected const MAX_PICKER_ITEMS = 10;

    /**
     * The locale used when the caller names none.
     */
    protected const DEFAULT_LOCALE = 'en-US';

    /**
     * The interactive message types Apple documents.
     *
     * @var list<string>
     */
    protected const INTERACTIVE_TYPES = array('list_picker', 'time_picker', 'rich_link', 'authenticate');

    /**
     * The business id, or the canonical refusal.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return string|mixed
     */
    protected function businessId(array $arguments): mixed
    {
        $id = $this->opaque($arguments, 'business_id');

        if ('' === $id || 1 !== preg_match('/^[A-Za-z0-9._:@-]{1,128}$/', $id)) {
            return $this->errors->validationFailed(
                'A business_id issued during Apple registration is required.',
                array('business_id' => array('Required, and limited to letters, digits and ._:@-.'))
            );
        }

        return $id;
    }

    /**
     * The message body, or the canonical refusal.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     * @param string               $key       Argument name.
     *
     * @return string|mixed
     */
    protected function appleMessage(array $arguments, string $key = 'message'): mixed
    {
        $text = is_scalar($arguments[$key] ?? null) ? trim((string) $arguments[$key]) : '';

        if ('' === $text) {
            return $this->errors->validationFailed(
                'A message body is required.',
                array($key => array('Required.'))
            );
        }

        if (self::MAX_MESSAGE_LENGTH < mb_strlen($text)) {
            return $this->errors->validationFailed(
                'Apple Messages are limited to ' . self::MAX_MESSAGE_LENGTH . ' characters.',
                array($key => array('Too long.'))
            );
        }

        return $text;
    }

    /**
     * A BCP 47 locale, or the canonical refusal.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return string|mixed
     */
    protected function locale(array $arguments): mixed
    {
        $locale = $this->opaque($arguments, 'locale');

        if ('' === $locale) {
            return self::DEFAULT_LOCALE;
        }

        if (1 !== preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{1,8})*$/', $locale)) {
            return $this->errors->validationFailed(
                'locale must be a BCP 47 language tag such as en-US.',
                array('locale' => array('Not a language tag.'))
            );
        }

        return $locale;
    }

    /**
     * The conversation addressing pairs, at least one of which is required.
     *
     * A gateway cannot route a message with neither a conversation id nor a
     * recipient id; the WordPress schema said so in both descriptions and then
     * accepted a request with neither.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array<string, string>|mixed
     */
    protected function conversation(array $arguments): mixed
    {
        $conversation = $this->opaque($arguments, 'conversation_id');
        $recipient    = $this->opaque($arguments, 'recipient_id');

        if ('' === $conversation && '' === $recipient) {
            return $this->errors->validationFailed(
                'A conversation_id or a recipient_id is required to address the message.',
                array(
                    'conversation_id' => array('Supply this to continue a conversation.'),
                    'recipient_id'    => array('Supply this to start one.'),
                )
            );
        }

        $addressing = array();

        if ('' !== $conversation) {
            $addressing['conversationId'] = $conversation;
        }

        if ('' !== $recipient) {
            $addressing['recipientId'] = $recipient;
        }

        return $addressing;
    }

    /**
     * An opaque identifier from the caller, trimmed and printable, or ''.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     * @param string               $key       Argument name.
     */
    protected function opaque(array $arguments, string $key): string
    {
        $value = is_scalar($arguments[$key] ?? null) ? trim((string) $arguments[$key]) : '';

        if ('' === $value || 256 < strlen($value)) {
            return '';
        }

        return 1 === preg_match('/^[^\x00-\x1F\x7F]+$/', $value) ? $value : '';
    }

    /**
     * The intent string, which Apple passes through when a customer tapped a
     * "Message Us" entry point.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function intent(array $arguments): string
    {
        return $this->opaque($arguments, 'intent');
    }
}
