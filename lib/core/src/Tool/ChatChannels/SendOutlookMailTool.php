<?php

/**
 * Send an Outlook mail message.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Send_Outlook_Mail` (Proposal 164 section
 * 164.104). One `POST /me/sendMail`, which Graph answers 202 with no body — the
 * mail is queued, not necessarily delivered.
 *
 * ## Corrections
 *
 * 1. **An invalid CC is refused.** WordPress checked `is_email()` and silently
 *    dropped a CC that failed, so the sender believed the copy had gone.
 * 2. **`content_type` is validated.** WordPress mapped anything that was not
 *    `html` to Text, so a typo quietly sent plain text.
 * 3. **The reply says what was queued.** WordPress returned a bare success;
 *    the reply names the recipient, the CC when used, the subject and the
 *    content type, which is what a caller needs to log the send.
 *
 * ## Adaptations
 *
 * WordPress's `sanitize_text_field()` on the subject and its message sanitiser
 * on the body are dropped: a subject is a single line by protocol, and the body
 * may legitimately be HTML when `content_type` is html. The `is_email()` check
 * becomes `FILTER_VALIDATE_EMAIL`, which is stricter about the same shapes.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class SendOutlookMailTool extends GraphTool
{
    public function getSlug(): string
    {
        return 'send_outlook_mail';
    }

    public function getName(): string
    {
        return 'Send Outlook Mail';
    }

    public function getDescription(): string
    {
        return 'Send an email through the signed-in Outlook mailbox: recipient, subject and body, with an optional CC and a text or HTML body. Graph queues the message and returns no body.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'token'        => array(
                    'type'        => 'string',
                    'description' => 'Microsoft Graph access token.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'to_email'     => array(
                    'type'        => 'string',
                    'description' => 'Recipient email address.',
                ),
                'subject'      => array(
                    'type'        => 'string',
                    'description' => 'Subject line.',
                    'minLength'   => 1,
                ),
                'body'         => array(
                    'type'        => 'string',
                    'description' => 'Message body.',
                    'minLength'   => 1,
                ),
                'cc_email'     => array(
                    'type'        => 'string',
                    'description' => 'Optional CC address.',
                ),
                'content_type' => array(
                    'type'        => 'string',
                    'description' => 'Body content type. Defaults to text.',
                    'enum'        => array('text', 'html'),
                    'default'     => 'text',
                ),
            ),
            'required'             => array('token', 'to_email', 'subject', 'body'),
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

        $to = $this->textArg($arguments, 'to_email');

        if (! $this->isEmail($to)) {
            return $this->errors->validationFailed(
                'A valid recipient email address is required.',
                array('to_email' => array('A valid email address is required.'))
            );
        }

        $cc = $this->textArg($arguments, 'cc_email');

        if ('' !== $cc && ! $this->isEmail($cc)) {
            return $this->errors->validationFailed(
                'The CC address is not a valid email address.',
                array('cc_email' => array('A valid email address is required.'))
            );
        }

        $subject = $this->textArg($arguments, 'subject');

        if ('' === $subject) {
            return $this->errors->validationFailed(
                'A subject is required.',
                array('subject' => array('A subject is required.'))
            );
        }

        $body = $this->textArg($arguments, 'body');

        if ('' === $body) {
            return $this->errors->validationFailed(
                'A message body is required.',
                array('body' => array('A message body is required.'))
            );
        }

        $contentType = $this->textArg($arguments, 'content_type');

        if ('' === $contentType) {
            $contentType = 'text';
        }

        if (! in_array($contentType, array('text', 'html'), true)) {
            return $this->errors->validationFailed(
                'The content type must be text or html.',
                array('content_type' => array('Unsupported content type.'))
            );
        }

        $message = array(
            'subject'      => $subject,
            'body'         => array(
                'contentType' => 'html' === $contentType ? 'HTML' : 'Text',
                'content'     => $body,
            ),
            'toRecipients' => array($this->recipient($to)),
        );

        if ('' !== $cc) {
            $message['ccRecipients'] = array($this->recipient($cc));
        }

        try {
            $this->apiPost($token, '/me/sendMail', array('message' => $message));
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        return $this->success(
            'Mail queued to ' . $to . '.',
            array(
                'sent'         => true,
                'to'           => $to,
                'cc'           => '' !== $cc ? $cc : null,
                'subject'      => $subject,
                'content_type' => $contentType,
            )
        );
    }

    /**
     * A Graph recipient object.
     *
     * @return array<string, array<string, string>>
     */
    private function recipient(string $address): array
    {
        return array('emailAddress' => array('address' => $address));
    }

    /**
     * Whether a value is an email address.
     */
    private function isEmail(string $value): bool
    {
        return '' !== $value && false !== filter_var($value, FILTER_VALIDATE_EMAIL);
    }
}
