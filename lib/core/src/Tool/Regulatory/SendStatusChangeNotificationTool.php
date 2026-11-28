<?php

/**
 * Email the recipients of a registration about a status change.
 *
 * Port of `WP_MCP_AI_Tool_Send_Status_Change_Notification` (Proposal 164 section
 * 164.161). Validates the registration, resolves recipients, sends one plain-text
 * message per address through `EmailServiceInterface`, and records the attempt in
 * the notification log.
 *
 * ## Recipients resolve in three steps, and the answer says which
 *
 * WordPress took the argument list, else `wp_mcp_ai_notification_settings
 * ['status_change']['recipients']`, else `get_option( 'admin_email' )`. This
 * runtime has no admin address, so the last step becomes the host-supplied
 * `$defaultRecipients` the expiry-alert tool already takes. The response reports
 * `recipients_source` as `argument`, `configuration` or `default`, because the
 * WordPress response could not tell a caller which list was used and that is
 * exactly the question asked when a message reaches the wrong person.
 *
 * ## A configured rule supplies recipients whether or not it is enabled
 *
 * That is what WordPress did, and it is the only field of a notification rule
 * anything reads. The port does not add an `enabled` gate, because making a
 * disabled rule silence an explicit request is a behaviour change nobody asked
 * for; the gap is recorded in §164.161.
 *
 * ## Invalid addresses are dropped, and none at all is a refusal
 *
 * `sanitize_email()` returns an empty string for junk, and WordPress handed the
 * result to `wp_mail` without checking. The port validates every address, sends
 * to the ones that pass, and reports the ones it dropped. If a caller supplied
 * addresses and none survive, the call is refused instead of logging a send of
 * zero and returning success.
 *
 * ## Delivery failure does not fail the call
 *
 * A missing transport, or a transport that rejects every recipient, returns
 * `success: true` with `emails_sent: 0`, `transport_available` and
 * `failed_recipients` set, and a message that says nothing went out. Losing the
 * record of the attempt because the mail failed would be worse than the failed
 * mail — the same rule the expiry-alert tool follows.
 *
 * ## Two differences from the WordPress message
 *
 * The body's trailing "View Registration" line is omitted: it was
 * `get_edit_post_link()`, and this runtime has no admin screen to link to.
 * `emails_sent` is written into the log entry, which WordPress did not record;
 * the history reader returns it, and an entry that cannot say whether anything
 * was delivered is a weaker audit than one that can.
 *
 * Gated on `edit_posts`, matching both the declaration and the handler.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Regulatory;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\EmailServiceInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\CreateContentCommand;

class SendStatusChangeNotificationTool extends RegulatoryTool
{
    /**
     * @param array<int, string> $defaultRecipients Addresses used when neither
     *                                              the argument nor a configured
     *                                              rule supplies any.
     */
    public function __construct(
        ErrorFactoryInterface $e,
        ContentStoreInterface $content,
        ?EmailServiceInterface $email = null,
        private readonly array $defaultRecipients = array(),
    ) {
        parent::__construct($e, $content, $email);
    }

    public function getSlug(): string
    {
        return 'send_status_change_notification';
    }

    public function getName(): string
    {
        return 'Send Status Change Notification';
    }

    public function getDescription(): string
    {
        return 'Emails a registration status change to the supplied recipients, else the stored status_change rule recipients, else the host default. Invalid addresses are dropped and reported; none valid is a refusal. The attempt is recorded in the notification log with the delivered count, and a delivery failure is reported rather than raised.';
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'registration_id' => array(
                    'type'        => 'integer',
                    'description' => 'The registration the status change belongs to.',
                    'minimum'     => 1,
                ),
                'old_status'      => array(
                    'type'        => 'string',
                    'description' => 'The status before the change.',
                    'minLength'   => 1,
                ),
                'new_status'      => array(
                    'type'        => 'string',
                    'description' => 'The status after the change.',
                    'minLength'   => 1,
                ),
                'recipients'      => array(
                    'type'        => 'array',
                    'description' => 'Addresses to notify. Omit to use the configured rule or the host default.',
                    'items'       => array('type' => 'string', 'format' => 'email'),
                ),
                'notes'           => array(
                    'type'        => 'string',
                    'description' => 'Extra text included in the message body.',
                    'maxLength'   => 2000,
                ),
            ),
            'required'             => array('registration_id', 'old_status', 'new_status'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $userId = (int) ( $context['user_id'] ?? 0 );

        $this->authorize($context, 'edit_posts');

        $registrationId = $this->intValue($arguments['registration_id'] ?? null);
        $oldStatus      = trim($this->stringParam($arguments, 'old_status'));
        $newStatus      = trim($this->stringParam($arguments, 'new_status'));

        $fields = array();

        if ($registrationId < 1) {
            $fields['registration_id'] = array('The registration id.');
        }

        if ('' === $oldStatus) {
            $fields['old_status'] = array('The previous status.');
        }

        if ('' === $newStatus) {
            $fields['new_status'] = array('The new status.');
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The notification cannot be sent.', $fields);
        }

        $registration = $this->registration($registrationId);

        if (null === $registration) {
            return $this->errors->notFound('Registration not found.');
        }

        $resolved = $this->resolveRecipients($arguments['recipients'] ?? null);

        if (array() === $resolved['requested']) {
            return $this->errors->validationFailed(
                'The notification cannot be sent.',
                array(
                    'recipients' => array(
                        'At least one recipient. No addresses were supplied, the status_change rule '
                        . 'stores none, and this host has no default recipients.',
                    ),
                )
            );
        }

        $checked = $this->checkRecipients($resolved['requested']);

        if (array() === $checked['valid']) {
            return $this->errors->validationFailed(
                'The notification cannot be sent.',
                array('recipients' => array('At least one valid email address.'))
            );
        }

        $notes = trim($this->stringParam($arguments, 'notes'));

        $subject = sprintf('Status Change: %s', $registration->title);
        $body    = $this->body($registration, $oldStatus, $newStatus, $notes);

        $transport = null !== $this->email && $this->email->isAvailable() ? $this->email : null;

        $sent     = 0;
        $failures = array();

        if (null !== $transport) {
            foreach ($checked['valid'] as $recipient) {
                try {
                    $result = $transport->send(
                        array(
                            'to'      => $recipient,
                            'subject' => $subject,
                            'body'    => $body,
                            'is_html' => false,
                        )
                    );
                } catch (\Throwable $error) {
                    $failures[ $recipient ] = $error->getMessage();

                    continue;
                }

                if (empty($result['success'])) {
                    $failure = $result['error'] ?? 'The email could not be sent.';

                    $failures[ $recipient ] = is_scalar($failure)
                        ? (string) $failure
                        : 'The email could not be sent.';

                    continue;
                }

                ++$sent;
            }
        }

        $sentAt = $this->nowStamp();

        $logged = $this->logAttempt(
            $registrationId,
            $userId,
            $oldStatus,
            $newStatus,
            $checked['valid'],
            $sent,
            $sentAt
        );

        return $this->success(
            $this->messageFor(null !== $transport, $checked['valid'], $sent),
            array(
                'registration_id'    => $registrationId,
                'old_status'         => $oldStatus,
                'new_status'         => $newStatus,
                'recipients'         => $checked['valid'],
                'recipients_source'  => $resolved['source'],
                'invalid_recipients' => $checked['invalid'],
                'emails_sent'        => $sent,
                'failed_recipients'  => $failures,
                'transport_available' => null !== $transport,
                'logged'             => $logged,
                'sent_at'            => $sentAt,
            )
        );
    }

    /**
     * The recipient list and where it came from.
     *
     * @return array{requested: list<string>, source: string}
     */
    private function resolveRecipients(mixed $raw): array
    {
        $requested = $this->addressList($raw);

        if (array() !== $requested) {
            return array('requested' => $requested, 'source' => 'argument');
        }

        $rule = $this->notificationRule('status_change');

        if (null !== $rule) {
            $stored = $this->addressList($rule->meta['recipients'] ?? null);

            if (array() !== $stored) {
                return array('requested' => $stored, 'source' => 'configuration');
            }
        }

        return array('requested' => $this->addressList($this->defaultRecipients), 'source' => 'default');
    }

    /**
     * The message body, in the shape WordPress composed.
     */
    private function body(ContentItem $registration, string $oldStatus, string $newStatus, string $notes): string
    {
        $meta = $registration->meta;

        $body = sprintf('The status of registration "%s" has changed:', $registration->title) . "\n\n";
        $body .= sprintf('Previous Status: %s', $oldStatus) . "\n";
        $body .= sprintf('New Status: %s', $newStatus) . "\n\n";
        $body .= "Registration Details:\n";
        $body .= sprintf('Country: %s', $this->text($meta['country'] ?? null)) . "\n";
        $body .= sprintf('Authority: %s', $this->text($meta['authority'] ?? null)) . "\n";

        $cosNumber = $this->text($meta['cos_number'] ?? null);

        if ('' !== $cosNumber) {
            $body .= sprintf('COS Number: %s', $cosNumber) . "\n";
        }

        if ('' !== $notes) {
            $body .= "\nAdditional Notes:\n" . $notes . "\n";
        }

        return $body;
    }

    /**
     * Append the attempt to the notification log.
     *
     * A failed write must not fail the call: the mail has already gone out, and
     * a response that says "error" after delivering would be the wrong answer.
     *
     * @param list<string> $recipients
     */
    private function logAttempt(
        int $registrationId,
        int $userId,
        string $oldStatus,
        string $newStatus,
        array $recipients,
        int $sent,
        string $sentAt
    ): bool {
        try {
            $this->content->create(
                new CreateContentCommand(
                    title: sprintf('status_change %d', $registrationId),
                    type: self::NOTIFICATION_LOG_TYPE,
                    authorId: $userId,
                    status: 'publish',
                    meta: array(
                        'timestamp'       => $sentAt,
                        'user_id'         => $userId,
                        'registration_id' => $registrationId,
                        'type'            => 'status_change',
                        'old_status'      => $oldStatus,
                        'new_status'      => $newStatus,
                        'recipients'      => $recipients,
                        'emails_sent'     => $sent,
                    ),
                )
            );
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * What the caller should understand from the numbers.
     *
     * @param list<string> $recipients
     */
    private function messageFor(bool $transportAvailable, array $recipients, int $sent): string
    {
        if (! $transportAvailable) {
            return sprintf(
                'The status change was recorded for %d recipient(s) but no mail transport is available, so nothing was sent.',
                count($recipients)
            );
        }

        if (0 === $sent) {
            return sprintf('The status change was not delivered to any of %d recipient(s).', count($recipients));
        }

        return sprintf(
            'The status change was sent to %d of %d recipient(s).',
            $sent,
            count($recipients)
        );
    }

    /**
     * A registration by content id, or null.
     */
    private function registration(int $id): ?ContentItem
    {
        try {
            $item = $this->content->find($id);
        } catch (\Throwable $e) {
            return null;
        }

        if (! $item instanceof ContentItem || self::REGISTRATION_TYPE !== $item->type) {
            return null;
        }

        return $item;
    }
}
