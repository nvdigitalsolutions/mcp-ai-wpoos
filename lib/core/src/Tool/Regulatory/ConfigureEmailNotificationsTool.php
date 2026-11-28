<?php

/**
 * Configure a regulatory notification rule.
 *
 * Port of `WP_MCP_AI_Tool_Configure_Email_Notifications` (Proposal 164 section
 * 164.161). One rule per notification type, stored as a
 * `mcp_ai_notification_rule` content item.
 *
 * ## `enabled` is stored and honoured by nothing
 *
 * The option this tool wrote was read in exactly two places, both in this
 * toolkit: `send_expiry_alerts` and `send_status_change_notification`, and both
 * read only `recipients`. No code reads `enabled`, and no code reads
 * `conditions`. A rule configured as disabled still supplies its recipients to
 * the sender, and nothing dispatches on a status change or an expiry date.
 *
 * The port keeps that. `enabled` is stored and returned so a host can enforce it
 * later, and the description says plainly that nothing currently does. Inventing
 * a dispatcher — an event hook that mails on a status change — is a subsystem,
 * not a port, and it would change behaviour nobody asked to change. Recorded as
 * a declared gap in §164.161.
 *
 * ## Three store-level corrections
 *
 * **The type was not validated.** `notification_type` was sanitised and then
 * used as a settings key, so `notification_type: "nonsense"` created a new
 * bucket nothing would ever read. The port requires one of the four types.
 *
 * **`enabled` was cast.** `(bool) $arguments['enabled']` turns the string
 * `'false'` into `true`, so a caller sending a string disabled the rule in
 * reverse. The port reads it through `boolParam()`.
 *
 * **Recipients were stored unvalidated.** `sanitize_email()` returns an empty
 * string for a junk address, and the empty string was stored and later handed to
 * `wp_mail`. The port validates each address, stores only the ones that pass,
 * and reports the ones it dropped.
 *
 * ## The configuration audit log is not ported
 *
 * WordPress appended to `wp_mcp_ai_notification_log`, capped at 100 entries, and
 * **nothing ever read it**. The store is not created here: a write-only log is a
 * store with no reader, and the rule item already carries `updated_at` and
 * `updated_by`, which is the audit information a reader would want.
 *
 * ## Gating
 *
 * The declaration said `edit_posts` and the handler checked `manage_options`. The
 * handler is the stricter rule and the one that was enforced, so the port gates
 * on `manage_options` — the same choice `check_authority_status` made in the
 * other direction.
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
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;

class ConfigureEmailNotificationsTool extends RegulatoryTool
{
    /** Human labels for the four types. */
    private const LABELS = array(
        'expiry_alert'            => 'Expiry alert',
        'status_change'           => 'Status change',
        'submission_confirmation' => 'Submission confirmation',
        'approval_notice'         => 'Approval notice',
    );

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'configure_email_notifications';
    }

    public function getName(): string
    {
        return 'Configure Email Notifications';
    }

    public function getDescription(): string
    {
        return 'Stores the rule for one notification type: whether it is enabled, its recipients and its conditions. Nothing dispatches a rule and nothing reads enabled or conditions; the only field another tool uses is the recipient list, as a fallback when a sender is called without one.';
    }

    public function getRequiredCapability(): string
    {
        return 'manage_options';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'notification_type' => array(
                    'type'        => 'string',
                    'description' => 'Which notification type to configure.',
                    'enum'        => self::NOTIFICATION_TYPES,
                ),
                'enabled'           => array(
                    'type'        => 'boolean',
                    'description' => 'Record the rule as enabled or disabled. Nothing currently reads this field.',
                ),
                'recipients'        => array(
                    'type'        => 'array',
                    'description' => 'Addresses the rule stores. Omit to clear the stored list.',
                    'items'       => array('type' => 'string', 'format' => 'email'),
                ),
                'conditions'        => array(
                    'type'        => 'object',
                    'description' => 'Trigger conditions, stored and not evaluated. Omit to clear them.',
                    'properties'  => array(
                        'days_before_expiry' => array('type' => 'integer', 'minimum' => 1),
                        'countries'          => array('type' => 'array', 'items' => array('type' => 'string')),
                        'status_from'        => array('type' => 'string'),
                        'status_to'          => array('type' => 'string'),
                    ),
                ),
            ),
            'required'             => array('notification_type', 'enabled'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $userId = (int) ( $context['user_id'] ?? 0 );

        $this->authorize($context, 'manage_options');

        $type   = trim($this->stringParam($arguments, 'notification_type'));
        $fields = array();

        if ('' === $type) {
            $fields['notification_type'] = array('The notification type.');
        } elseif (! in_array($type, self::NOTIFICATION_TYPES, true)) {
            $fields['notification_type'] = array(
                'One of: ' . implode(', ', self::NOTIFICATION_TYPES) . '.',
            );
        }

        // Required, as it was there: omitting it is not the same request as
        // disabling, and a default would silently disable a live rule.
        if (! array_key_exists('enabled', $arguments)) {
            $fields['enabled'] = array('Enabled or disabled.');
        }

        $conditions = array();

        if (array_key_exists('conditions', $arguments)) {
            $checked = $this->conditions($arguments['conditions']);

            $fields = array_merge($fields, $checked['fields']);
            $conditions = $checked['conditions'];
        }

        $requested = $this->addressList($arguments['recipients'] ?? null);
        $checked   = $this->checkRecipients($requested);

        // A caller who supplied addresses and had every one rejected has not
        // asked to clear the list, and clearing it silently is how a typo
        // becomes a rule that mails nobody.
        if (array() !== $requested && array() === $checked['valid']) {
            $fields['recipients'] = array('At least one valid email address.');
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The notification rule cannot be saved.', $fields);
        }

        $enabled = $this->boolParam($arguments, 'enabled', false);
        $now     = $this->nowStamp();

        $meta = array(
            'notification_type' => $type,
            'enabled'           => $enabled,
            'recipients'        => $checked['valid'],
            'conditions'        => $conditions,
            'updated_at'        => $now,
            'updated_by'        => $userId,
        );

        $existing = $this->notificationRule($type);
        $created  = null === $existing;

        try {
            if (null === $existing) {
                $meta['created_at'] = $now;
                $meta['created_by'] = $userId;

                $item = $this->content->create(
                    new CreateContentCommand(
                        title: self::LABELS[ $type ],
                        type: self::NOTIFICATION_RULE_TYPE,
                        authorId: $userId,
                        status: 'publish',
                        meta: $meta,
                    )
                );
            } else {
                $item = $this->content->update(
                    $existing->id,
                    new UpdateContentCommand(userId: $userId, meta: $meta)
                );
            }
        } catch (\Throwable $e) {
            return $this->errors->create('configure_email_notifications_failed', $e->getMessage());
        }

        return $this->success(
            sprintf(
                'Email notification "%s" has been %s.',
                $type,
                $enabled ? 'enabled' : 'disabled'
            ),
            array(
                'notification_type'  => $type,
                'content_id'         => $item->id,
                'created'            => $created,
                'enabled'            => $enabled,
                'recipients'         => $checked['valid'],
                'recipients_count'   => count($checked['valid']),
                'invalid_recipients' => $checked['invalid'],
                'has_conditions'     => array() !== $conditions,
                'conditions'         => $conditions,
                'configured_at'      => $now,
                'dispatch_configured' => false,
            )
        );
    }

    /**
     * Validate the stored condition object.
     *
     * Unknown keys are kept, because dropping a key on a round trip is a silent
     * data loss and the WordPress tool stored whatever it was given. The two keys
     * the schema declares are checked.
     *
     * @return array{conditions: array<string, mixed>, fields: array<string, array<int, string>>}
     */
    private function conditions(mixed $raw): array
    {
        if (! is_array($raw)) {
            return array(
                'conditions' => array(),
                'fields'     => array('conditions' => array('An object.')),
            );
        }

        $fields = array();

        if (array_key_exists('days_before_expiry', $raw) && $this->intValue($raw['days_before_expiry']) < 1) {
            $fields['conditions.days_before_expiry'] = array('A whole number of days, at least 1.');
        }

        if (array_key_exists('countries', $raw) && ! is_array($raw['countries'])) {
            $fields['conditions.countries'] = array('A list of country codes.');
        }

        return array('conditions' => $raw, 'fields' => $fields);
    }
}
