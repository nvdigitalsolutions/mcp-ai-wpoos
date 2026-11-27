<?php

/**
 * Shared ground for the WhatsApp Cloud API tools.
 *
 * Proposal 164 section 164.103. The family differs from the other three
 * providers in three ways the four send tools share:
 *
 * - **Two credentials.** An `access_token` in the bearer header and a
 *   `phone_number_id` in the path. The id is digits only, so it is validated
 *   rather than merely encoded.
 * - **The refusal is nested.** A failed call answers a 4xx with
 *   `{"error": {"message": ..., "code": ..., "error_data": {...}}}`. The
 *   provider code is `error.code`; the message is `error.message`.
 * - **A success names a message.** The body carries `messages[0].id` (the
 *   wamid) and `contacts[0].wa_id` (the normalised recipient), which is what a
 *   delivery or a reply is keyed on.
 *
 * The `recipient_type` and `to` fields are shared by every send, so the
 * recipient normalisation lives here: a phone number keeps its leading `+` and
 * loses every other non-digit, and a group JID keeps the characters a JID uses.
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

abstract class WhatsAppTool extends ChatChannelTool
{
    /**
     * The Graph API version the WordPress tool used.
     *
     * Pinned rather than floating: Meta changes field shapes between versions,
     * and an unversioned call silently picks up whatever the account defaults
     * to.
     */
    private const API_BASE = 'https://graph.facebook.com/v19.0';

    /**
     * The access token, or the canonical refusal when it is absent.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function accessToken(array $arguments): mixed
    {
        $token = $this->credential($arguments, 'access_token');

        if ('' === $token) {
            return $this->errors->validationFailed(
                'A WhatsApp Cloud API access token is required.',
                array('access_token' => array('A valid access token is required.'))
            );
        }

        return $token;
    }

    /**
     * The phone number id, which is a path segment and must be digits.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function phoneNumberId(array $arguments): mixed
    {
        $id = preg_replace('/[^0-9]/', '', $this->textArg($arguments, 'phone_number_id'));

        if (! is_string($id) || '' === $id) {
            return $this->errors->validationFailed(
                'A WhatsApp phone number id is required.',
                array('phone_number_id' => array('A numeric phone number id is required.'))
            );
        }

        return $id;
    }

    /**
     * The recipient and its type.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array{type: string, to: string}|mixed The pair, or the
     *                                              validation error.
     */
    protected function recipient(array $arguments): mixed
    {
        $type = 'group' === $this->textArg($arguments, 'recipient_type') ? 'group' : 'individual';
        $raw  = $this->textArg($arguments, 'to');

        if ('' === $raw) {
            return $this->errors->validationFailed(
                'A recipient is required.',
                array('to' => array('A recipient is required.'))
            );
        }

        $to = 'group' === $type ? $this->groupId($raw) : $this->phoneNumber($raw);

        if ('' === $to) {
            return $this->errors->validationFailed(
                'A valid recipient is required.',
                array('to' => array('A valid phone number or group id is required.'))
            );
        }

        return array('type' => $type, 'to' => $to);
    }

    /**
     * A phone number in E.164 shape: a leading `+` and digits.
     */
    protected function phoneNumber(string $value): string
    {
        $value = trim($value);

        if ('' === $value) {
            return '';
        }

        $digits = preg_replace('/[^0-9]/', '', $value);

        if (! is_string($digits) || '' === $digits) {
            return '';
        }

        return '+' === $value[0] ? '+' . $digits : $digits;
    }

    /**
     * A group JID, which is `{digits}@g.us`, or a phone number.
     */
    protected function groupId(string $value): string
    {
        $value = trim($value);

        if ('' === $value) {
            return '';
        }

        $clean = preg_replace('/[^0-9a-zA-Z@._\-+]/', '', $value);

        return is_string($clean) ? $clean : '';
    }

    /**
     * POST one Graph API endpoint as JSON.
     *
     * @param array<string, mixed> $payload JSON body.
     *
     * @return array<array-key, mixed> The decoded body.
     *
     * @throws ChatChannelApiException On a transport failure or a refusal.
     */
    protected function apiPost(string $token, string $path, array $payload): array
    {
        $response = $this->request(
            'POST',
            self::API_BASE . $path,
            array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            Json::encode($payload)
        );

        $decoded = $this->decode($response);

        if (200 !== $response->statusCode || ! empty($decoded['error'])) {
            $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : array();

            $provider = isset($error['code']) && is_numeric($error['code'])
                ? (string) (int) $error['code']
                : '';

            $detail = isset($error['error_data']['details']) && is_scalar($error['error_data']['details'])
                ? trim((string) $error['error_data']['details'])
                : '';

            $message = $this->text($error['message'] ?? '');

            if ('' !== $detail && '' !== $message) {
                $message .= ' (' . $detail . ')';
            }

            $retry = isset($error['error_data']['retry_after']) && is_numeric($error['error_data']['retry_after'])
                ? (int) ceil((float) $error['error_data']['retry_after'])
                : 0;

            throw new ChatChannelApiException(
                '' !== $message
                    ? 'WhatsApp API error: ' . $message
                    : 'WhatsApp API returned an error.',
                'whatsapp_api_error',
                $response->statusCode,
                $provider,
                $decoded,
                max(0, $retry)
            );
        }

        return $decoded;
    }

    /**
     * The sent message id and normalised recipient from a success body.
     *
     * @param array<array-key, mixed> $response A decoded success body.
     *
     * @return array{message_id: string, wa_id: string}
     */
    protected function sent(array $response): array
    {
        $messages = is_array($response['messages'] ?? null) ? $response['messages'] : array();
        $contacts = is_array($response['contacts'] ?? null) ? $response['contacts'] : array();

        $first  = is_array($messages[0] ?? null) ? $messages[0] : array();
        $person = is_array($contacts[0] ?? null) ? $contacts[0] : array();

        return array(
            'message_id' => $this->text($first['id'] ?? ''),
            'wa_id'      => $this->text($person['wa_id'] ?? ''),
        );
    }

    /**
     * A string field from a Graph API row, or '' when absent.
     */
    protected function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
