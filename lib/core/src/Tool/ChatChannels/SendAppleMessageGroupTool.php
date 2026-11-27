<?php

/**
 * Send a message to an Apple Messages for Business group conversation.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Send_Apple_Message_Group` (Proposal 164 section
 * 164.108). One POST of the `group` envelope to the caller's MSP endpoint.
 *
 * See `AppleTool` for the platform model and the shared corrections. Three are
 * specific to this tool:
 *
 * 1. **The participant cap is enforced on the list that is sent.** WordPress
 *    checked the raw array length and then sanitised, so a list of 40 entries of
 *    which 30 were empty passed the check and sent 10, while a list of 32 valid
 *    entries plus one blank was refused. The cap applies after sanitisation.
 * 2. **A malformed participant is refused, not dropped.** WordPress `continue`d
 *    past a non-string entry, so a typo silently addressed the group to fewer
 *    people than the caller asked for.
 * 3. **A group with neither an id nor participants is refused.** WordPress
 *    accepted `group_id` and `participants` as equally optional, so it could
 *    post a "group" message that names no group.
 *
 * `multipleSelection` is read as a real boolean. WordPress cast it with
 * `(bool)`, which turns the string `"false"` into true.
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

class SendAppleMessageGroupTool extends AppleTool
{
    public function getSlug(): string
    {
        return 'send_apple_message_group';
    }

    public function getName(): string
    {
        return 'Send Apple Group Message';
    }

    public function getDescription(): string
    {
        return 'Sends a text message to an Apple Messages for Business group conversation, creating the group when no group id is given. A group holds at most 32 participants. The endpoint and token are your provider\'s.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'msp_api_url'  => array(
                    'type'        => 'string',
                    'description' => 'Your provider\'s public https endpoint for group messages.',
                ),
                'api_key'      => array(
                    'type'        => 'string',
                    'description' => 'Bearer token issued by your provider.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'business_id'  => array(
                    'type'        => 'string',
                    'description' => 'Apple Messages for Business id issued during registration.',
                    'minLength'   => 1,
                    'maxLength'   => 128,
                ),
                'group_id'     => array(
                    'type'        => 'string',
                    'description' => 'Existing group to send to. Omit to create one, in which case group_name and participants are needed.',
                ),
                'group_name'   => array(
                    'type'        => 'string',
                    'description' => 'Display name of the group.',
                ),
                'participants' => array(
                    'type'        => 'array',
                    'description' => 'Opaque participant identifiers, at most 32 in total.',
                    'items'       => array('type' => 'string'),
                    'maxItems'    => self::MAX_PARTICIPANTS,
                ),
                'message'      => array(
                    'type'        => 'string',
                    'description' => 'Plain-text body, up to 2,000 characters.',
                    'minLength'   => 1,
                    'maxLength'   => self::MAX_MESSAGE_LENGTH,
                ),
                'sender_name'  => array(
                    'type'        => 'string',
                    'description' => 'Display name of the agent or system sending, shown in the group.',
                ),
                'locale'       => array(
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

        $locale = $this->locale($arguments);

        if (! is_string($locale)) {
            return $locale;
        }

        $groupId = $this->opaque($arguments, 'group_id');
        $group   = $this->participants($arguments);

        if ($this->errors->isError($group) || ! is_array($group)) {
            return $group;
        }

        if ('' === $groupId && array() === $group) {
            return $this->errors->validationFailed(
                'A group_id, or a non-empty participants list, is required to address a group.',
                array(
                    'group_id'     => array('Supply this to continue an existing group.'),
                    'participants' => array('Supply these to create a group.'),
                )
            );
        }

        $payload = array(
            'businessId' => $business,
            'type'       => 'group',
            'body'       => array('text' => $message),
            'locale'     => $locale,
        );

        if ('' !== $groupId) {
            $payload['groupId'] = $groupId;
        }

        $name = $this->opaque($arguments, 'group_name');

        if ('' !== $name) {
            $payload['groupName'] = $name;
        }

        if (array() !== $group) {
            $payload['participants'] = $group;
        }

        $sender = $this->opaque($arguments, 'sender_name');

        if ('' !== $sender) {
            $payload['senderName'] = $sender;
        }

        $body = Json::encodeOr($payload, '');

        if ('' === $body) {
            return $this->errors->validationFailed(
                'The group message could not be encoded as JSON.',
                array('message' => array('Not encodable.'))
            );
        }

        try {
            $response = $this->gatewayPost($url, $token, $body);
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        return $this->success(
            'Apple group message sent.',
            array(
                'business_id'   => $business,
                'type'          => 'group',
                'group_id'      => $groupId,
                'group_name'    => $name,
                'participants'  => count($group),
                'text_length'   => mb_strlen($message),
                'message_id'    => $this->stringValue($response['messageId'] ?? $response['id'] ?? null),
                'response'      => $response,
            )
        );
    }

    /**
     * The participant list, sanitised, or the canonical refusal.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return list<string>|mixed
     */
    private function participants(array $arguments): mixed
    {
        $raw = $arguments['participants'] ?? null;

        if (null === $raw || array() === $raw) {
            return array();
        }

        if (! is_array($raw)) {
            return $this->errors->validationFailed(
                'participants must be a list of identifiers.',
                array('participants' => array('Not a list.'))
            );
        }

        $clean = array();

        foreach ($raw as $index => $participant) {
            $value = $this->opaqueEntry($participant);

            if ('' === $value) {
                return $this->errors->validationFailed(
                    'Every participant must be a non-empty identifier.',
                    array('participants' => array('Entry ' . $index . ' is not an identifier.'))
                );
            }

            $clean[] = $value;
        }

        if (self::MAX_PARTICIPANTS < count($clean)) {
            return $this->errors->validationFailed(
                'A group holds at most ' . self::MAX_PARTICIPANTS . ' participants.',
                array('participants' => array('Too many.'))
            );
        }

        return $clean;
    }

    /**
     * One participant value, or ''.
     */
    private function opaqueEntry(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $text = trim((string) $value);

        if ('' === $text || 256 < strlen($text)) {
            return '';
        }

        return 1 === preg_match('/^[^\x00-\x1F\x7F]+$/', $text) ? $text : '';
    }
}
