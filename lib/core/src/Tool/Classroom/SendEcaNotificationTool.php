<?php

/**
 * Email an activity's students, and optionally their parents — Proposal 164 §164.59.
 *
 * Ported from `WP_MCP_AI_Tool_Send_ECA_Notification`: the same six notification
 * types, the same three recipient groups, `student_ids` for the targeted group,
 * `include_parent` and `custom_message`. Four decisions are recorded here:
 *
 * ### A batch reports per recipient, it does not stop at the first failure
 *
 * WordPress collects `sent` and `failed` counts while its loop runs and returns
 * them together. This port keeps that shape and makes it usable: `sent` and
 * `failed` are lists naming each student, each address and each reason. One
 * mistyped parent address must not hide the eleven students who were written to.
 *
 * ### "All enrolled" means confirmed, with an untyped row counted as confirmed
 *
 * WordPress requires `enrollment_type === 'confirmed'` and therefore skips a
 * roster row that has no type at all — a row written by an importer or a mirror.
 * Here an absent or empty type is read as confirmed, because the alternative is
 * a notification pass that silently omits people who are on the roster.
 *
 * ### The parent address is a second message, not a second header
 *
 * `include_parent` sends the parent their own copy addressed to them, and a
 * parent with no address is reported as `no_parent_email` rather than dropped.
 * WordPress does the same; the difference is that it counts the skip and this
 * port names it.
 *
 * ### The log row says how many failed
 *
 * WordPress appends `type`, `recipients_count`, `sent_at` and `sent_by` to
 * `_eca_notification_log`. This port adds `failed_count`, because a log that
 * records only the successes cannot answer "did the cancellation notice go
 * out?" for the parent whose address bounced.
 *
 * The body reads `_eca_start_time` and `_eca_end_time`. WordPress reads
 * `_eca_time`, a key its own create tool never writes, so its Time row is
 * always empty; this port reads what is actually stored.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Classroom;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\EmailServiceInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Tool\AbstractTool;

class SendEcaNotificationTool extends AbstractTool
{
    private const TYPES = array(
        'enrollment_confirmed',
        'waitlist_update',
        'schedule_change',
        'cancellation',
        'reminder',
        'payment_due',
    );

    private const GROUPS = array( 'all_enrolled', 'waitlisted', 'specific_students' );

    /**
     * The sentence each type contributes to the body.
     *
     * @var array<string, string>
     */
    private const MESSAGES = array(
        'enrollment_confirmed' => 'Your enrollment has been confirmed.',
        'waitlist_update'      => 'There has been an update to the waitlist status.',
        'schedule_change'      => 'The schedule for this activity has changed. Please review the updated details below.',
        'cancellation'         => 'This activity session has been cancelled. Please check for further updates.',
        'reminder'             => 'This is a reminder about your upcoming activity session.',
        'payment_due'          => 'A payment is due for this activity. Please arrange payment at your earliest convenience.',
    );

    /**
     * The subject line each type uses. The activity name is appended.
     *
     * @var array<string, string>
     */
    private const SUBJECTS = array(
        'enrollment_confirmed' => 'ECA Enrollment Confirmed: %s',
        'waitlist_update'      => 'ECA Waitlist Update: %s',
        'schedule_change'      => 'ECA Schedule Change: %s',
        'cancellation'         => 'ECA Cancellation Notice: %s',
        'reminder'             => 'ECA Reminder: %s',
        'payment_due'          => 'ECA Payment Due: %s',
    );

    private const SCAN_LIMIT = 200;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
        private readonly EmailServiceInterface $email,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'send_eca_notification';
    }

    public function getName(): string
    {
        return 'Send ECA Notification';
    }

    public function getDescription(): string
    {
        return 'Emails the students of an Extra-Curricular Activity - all confirmed enrolments, the waitlist, or named students - and optionally their parents, one message per address. Every recipient is reported individually: sent with the address used, or failed with the reason, so one bad address does not hide the rest. The attempt, including the failure count, is appended to the activity notification log.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'eca_id'            => array(
                    'type'        => 'integer',
                    'description' => 'ECA record ID (required).',
                    'minimum'     => 1,
                ),
                'notification_type' => array(
                    'type'        => 'string',
                    'enum'        => self::TYPES,
                    'description' => 'What the message is about; it selects the subject and the leading sentence.',
                ),
                'recipients'        => array(
                    'type'        => 'string',
                    'enum'        => self::GROUPS,
                    'description' => 'all_enrolled sends to confirmed enrolments, waitlisted to the waitlist, specific_students to the ids in student_ids.',
                ),
                'student_ids'       => array(
                    'type'        => 'array',
                    'description' => 'Student ids when recipients is specific_students.',
                    'items'       => array( 'type' => 'integer', 'minimum' => 1 ),
                    'maxItems'    => self::SCAN_LIMIT,
                ),
                'custom_message'    => array(
                    'type'        => 'string',
                    'description' => 'Extra text appended below the activity details.',
                    'maxLength'   => 2000,
                ),
                'include_parent'    => array(
                    'type'        => 'boolean',
                    'description' => 'Also send a copy to each student parent address.',
                    'default'     => false,
                ),
            ),
            'required'   => array( 'eca_id', 'notification_type', 'recipients' ),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $user_id  = (int) ( $context['user_id'] ?? 0 );
        $eca_id   = $this->intParam($arguments, 'eca_id');
        $type     = strtolower(trim($this->stringParam($arguments, 'notification_type')));
        $group    = strtolower(trim($this->stringParam($arguments, 'recipients')));
        $parent   = $this->boolParam($arguments, 'include_parent', false);
        $custom   = trim($this->stringParam($arguments, 'custom_message'));

        if ($eca_id <= 0) {
            return $this->errors->validationFailed(
                'An ECA id is required.',
                array( 'eca_id' => array( 'The activity whose students are being notified.' ) ),
            );
        }

        if (! in_array($type, self::TYPES, true)) {
            return $this->errors->validationFailed(
                'Unknown notification_type "' . $type . '".',
                array( 'notification_type' => array( 'Use one of ' . implode(', ', self::TYPES) . '.' ) ),
            );
        }

        if (! in_array($group, self::GROUPS, true)) {
            return $this->errors->validationFailed(
                'Unknown recipients group "' . $group . '".',
                array( 'recipients' => array( 'Use one of ' . implode(', ', self::GROUPS) . '.' ) ),
            );
        }

        $eca = $this->content->find($eca_id);

        if (! $eca instanceof ContentItem || 'mcp_ai_eca' !== $eca->type) {
            return $this->errors->notFound('There is no ECA with id ' . $eca_id . '.');
        }

        $resolved = $this->resolve($eca, $group, $arguments);

        if (isset($resolved['error'])) {
            return $resolved['error'];
        }

        if (array() === $resolved['ids']) {
            return $this->errors->create(
                'no_recipients',
                'No students match the "' . $group . '" group for this activity.',
                array( 'eca_id' => $eca_id, 'recipients' => $group ),
            );
        }

        if (! $this->email->isAvailable()) {
            return $this->errors->create('email_unavailable', 'No email transport is configured on this host.');
        }

        $subject = sprintf(self::SUBJECTS[ $type ], $eca->title);
        $body    = $this->body($eca, $type, $custom);

        $sent   = array();
        $failed = array();

        foreach ($resolved['ids'] as $student_id) {
            $student = $this->content->find($student_id);

            if (! $student instanceof ContentItem || 'mcp_ai_student' !== $student->type) {
                $failed[] = array(
                    'student_id' => $student_id,
                    'email'      => '',
                    'role'       => '',
                    'error'      => 'no such student record',
                );

                continue;
            }

            $student_address = Contacts::student($student);
            $targets         = array( $student_address );

            if ($parent) {
                $targets[] = Contacts::parent($student);
            }

            foreach ($targets as $target) {
                if ('' === $target['email']) {
                    $failed[] = array(
                        'student_id' => $student_id,
                        'email'      => '',
                        'role'       => $target['role'],
                        'error'      => Contacts::ROLE_PARENT === $target['role'] ? 'no_parent_email' : 'no_student_email',
                    );

                    continue;
                }

                if (! $this->email->validateRecipient($target['email'])) {
                    $failed[] = array(
                        'student_id' => $student_id,
                        'email'      => $target['email'],
                        'role'       => $target['role'],
                        'error'      => 'invalid address',
                    );

                    continue;
                }

                $result = $this->email->send(
                    array(
                        'to'      => $target['email'],
                        'subject' => $subject,
                        'body'    => $body,
                        'is_html' => true,
                    )
                );

                if (empty($result['success'])) {
                    $failed[] = array(
                        'student_id' => $student_id,
                        'email'      => $target['email'],
                        'role'       => $target['role'],
                        'error'      => (string) ( $result['error'] ?? 'send failed' ),
                    );

                    continue;
                }

                $sent[] = array(
                    'student_id' => $student_id,
                    'email'      => $target['email'],
                    'role'       => $target['role'],
                );
            }
        }

        $this->log($eca, $user_id, $type, count($sent), count($failed));

        return $this->success(
            sprintf('Sent %d message(s); %d failed.', count($sent), count($failed)),
            array(
                'eca_id'            => $eca_id,
                'notification_type' => $type,
                'recipients'        => $group,
                'sent_count'        => count($sent),
                'failed_count'      => count($failed),
                'sent'              => $sent,
                'failed'            => $failed,
            )
        );
    }

    /**
     * Student ids for the chosen group, or a refusal.
     *
     * @return array{ids: list<int>}|array{error: mixed}
     */
    private function resolve(ContentItem $eca, string $group, array $arguments): array
    {
        if ('specific_students' === $group) {
            $given = $arguments['student_ids'] ?? array();

            if (! is_array($given) || array() === $given) {
                return array(
                    'error' => $this->errors->validationFailed(
                        'student_ids is required when recipients is specific_students.',
                        array( 'student_ids' => array( 'Give the student ids to notify.' ) ),
                    ),
                );
            }

            $ids = array();

            foreach ($given as $id) {
                $id = (int) $id;

                if ($id > 0) {
                    $ids[ $id ] = $id;
                }
            }

            return array( 'ids' => array_values($ids) );
        }

        $wanted = 'waitlisted' === $group ? 'waitlist' : 'confirmed';
        $ids    = array();

        foreach (EcaRow::enrollments($eca) as $student_id => $record) {
            $type = strtolower(trim((string) ( $record['enrollment_type'] ?? '' )));

            if ('' === $type) {
                $type = 'confirmed';
            }

            if ($type !== $wanted) {
                continue;
            }

            $ids[] = (int) $student_id;
        }

        return array( 'ids' => $ids );
    }

    /**
     * A small HTML body: one sentence, the activity details, the optional note.
     */
    private function body(ContentItem $eca, string $type, string $custom): string
    {
        $rows = array(
            'Day'   => trim((string) ( $eca->meta['_eca_day'] ?? '' )),
            'Time'  => $this->window($eca),
            'Venue' => trim((string) ( $eca->meta['_eca_venue'] ?? '' )),
        );

        $html  = '<html><body>';
        $html .= '<h2>' . htmlspecialchars($eca->title, ENT_QUOTES, 'UTF-8') . '</h2>';
        $html .= '<p>' . htmlspecialchars(self::MESSAGES[ $type ], ENT_QUOTES, 'UTF-8') . '</p>';
        $html .= '<table style="border-collapse:collapse;">';

        foreach ($rows as $label => $value) {
            if ('' === $value) {
                continue;
            }

            $html .= '<tr><td style="padding:4px 8px;font-weight:bold;">' . $label . '</td><td style="padding:4px 8px;">'
                . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }

        $html .= '</table>';

        if ('' !== $custom) {
            $html .= '<hr /><p>' . nl2br(htmlspecialchars($custom, ENT_QUOTES, 'UTF-8')) . '</p>';
        }

        return $html . '</body></html>';
    }

    /**
     * The activity's single-slot time window, when both ends are set.
     */
    private function window(ContentItem $eca): string
    {
        $start = trim((string) ( $eca->meta['_eca_start_time'] ?? '' ));
        $end   = trim((string) ( $eca->meta['_eca_end_time'] ?? '' ));

        if ('' === $start || '' === $end) {
            return '';
        }

        return $start . '-' . $end;
    }

    /**
     * Append the attempt to `_eca_notification_log`.
     *
     * A failed log write is not allowed to turn a delivered batch into an error
     * result: the messages are already out, and saying otherwise would invite a
     * caller to retry and send everything twice.
     */
    private function log(ContentItem $eca, int $user_id, string $type, int $sent, int $failed): void
    {
        $log = $eca->meta['_eca_notification_log'] ?? array();

        if (! is_array($log)) {
            $log = array();
        }

        $log[] = array(
            'type'             => $type,
            'recipients_count' => $sent,
            'failed_count'     => $failed,
            'sent_at'          => gmdate('Y-m-d H:i:s'),
            'sent_by'          => $user_id,
        );

        try {
            $this->content->update(
                $eca->id,
                new UpdateContentCommand(userId: $user_id, meta: array( '_eca_notification_log' => $log ))
            );
        } catch (\Throwable $e) {
            return;
        }
    }
}
