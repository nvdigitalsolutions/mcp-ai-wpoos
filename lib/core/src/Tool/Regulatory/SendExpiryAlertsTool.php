<?php

/**
 * Send expiry alert email for registrations inside a window.
 *
 * Port of `WP_MCP_AI_Tool_Send_Expiry_Alerts` (Proposal 164 section 164.155).
 * The only writing tool in the family: it reads registrations expiring inside a
 * window, composes one message listing them, and sends it to each recipient
 * through the host's `EmailServiceInterface`.
 *
 * ## Where the recipients used to come from, and where they cannot
 *
 * WordPress resolved recipients in three steps: the `recipients` argument, then
 * the `expiry_alert` entry of the `wp_mcp_ai_notification_settings` option that
 * `configure_email_notifications` writes, then `admin_email`. This runtime has
 * no options store and no administrator address, so the second and third steps
 * do not exist. The port therefore takes the argument, falls back to recipients
 * the host supplied at construction, and **refuses the call** when both are
 * empty rather than sending to a guess. The refusal names the argument.
 *
 * ## An empty address was attempted, not refused
 *
 * WordPress ran every recipient through `sanitize_email( $address )`, which
 * turns an invalid address into an empty string, and then called `wp_mail( '' )`
 * for it. The send failed silently and the response still listed the address.
 * The port validates each address, drops the invalid ones, reports them in
 * `invalid_recipients`, and refuses the call when none survives.
 *
 * ## No transport means no send, and that is reported
 *
 * A host without an email service still gets the report: the registrations are
 * listed, `emails_sent` is zero, and `transport_available` is false. Losing the
 * answer because the mail could not go out would be worse than a missed email,
 * which is the rule the calendar tools already follow.
 *
 * ## Two gaps, recorded rather than papered over
 *
 * The WordPress option log (`wp_mcp_ai_expiry_alert_log`, capped at fifty
 * entries) is not ported, because this runtime has no options store; the
 * response reports what was sent and `sent_at`. There is also **no idempotency
 * guard**: calling the tool twice sends twice. The calendar tools log sends per
 * record because a confirmation is a promise to a client; this tool mails the
 * operator's own team, who control the cadence, so the port follows WordPress
 * and leaves the cadence to the caller. `test_mode` is the way to preview.
 *
 * ## The window excludes what has already expired
 *
 * WordPress queried `expiry_date BETWEEN today AND today + threshold`, so an
 * already-expired registration is never alerted here — `list_expiring_
 * registrations` is where overdue ones belong. The excluded count is reported,
 * because a caller that assumes "no alert" means "nothing wrong" should see it.
 *
 * Gated on `edit_posts`, matching both the declaration and the handler in
 * WordPress: this tool sends mail, so the family's relaxation to `read` does
 * not apply.
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

class SendExpiryAlertsTool extends RegulatoryTool
{
    /** The default alert window, in days. */
    private const DEFAULT_DAYS_THRESHOLD = 90;

    /** The bounds the schema and the handler agree on. */
    private const MIN_DAYS_THRESHOLD = 1;

    private const MAX_DAYS_THRESHOLD = 365;

    /**
     * @param array<int, string> $defaultRecipients Addresses to use when the
     *                                              caller supplies none.
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
        return 'send_expiry_alerts';
    }

    public function getName(): string
    {
        return 'Send Expiry Alerts';
    }

    public function getDescription(): string
    {
        return 'Emails one alert listing the registrations expiring within a 1-365 day window to each recipient. Invalid addresses are reported and dropped, and the call is refused when no valid recipient remains. test_mode produces the report without sending. Already-expired registrations are excluded and counted.';
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
                'days_threshold' => array(
                    'type'        => 'integer',
                    'description' => 'Alert for registrations expiring within this many days, 1-365. Default 90.',
                    'minimum'     => self::MIN_DAYS_THRESHOLD,
                    'maximum'     => self::MAX_DAYS_THRESHOLD,
                    'default'     => self::DEFAULT_DAYS_THRESHOLD,
                ),
                'recipients'     => array(
                    'type'        => 'array',
                    'description' => 'Email addresses to notify.',
                    'items'       => array(
                        'type'   => 'string',
                        'format' => 'email',
                    ),
                ),
                'countries'      => array(
                    'type'        => 'array',
                    'description' => 'Only registrations in these countries.',
                    'items'       => array(
                        'type' => 'string',
                    ),
                ),
                'test_mode'      => array(
                    'type'        => 'boolean',
                    'description' => 'Build the report without sending email. Default false.',
                    'default'     => false,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $threshold = $this->intParam($arguments, 'days_threshold', self::DEFAULT_DAYS_THRESHOLD);

        if ($threshold < self::MIN_DAYS_THRESHOLD || $threshold > self::MAX_DAYS_THRESHOLD) {
            return $this->errors->validationFailed(
                'The expiry alert cannot be sent.',
                array(
                    'days_threshold' => array(
                        sprintf(
                            'A whole number of days from %d to %d.',
                            self::MIN_DAYS_THRESHOLD,
                            self::MAX_DAYS_THRESHOLD
                        ),
                    ),
                )
            );
        }

        $testMode = $this->boolParam($arguments, 'test_mode', false);

        $requested = $this->addressList($arguments['recipients'] ?? null);

        if (array() === $requested) {
            $requested = $this->addressList($this->defaultRecipients);
        }

        $checked   = $this->checkRecipients($requested);
        $countries = $this->countryFilter($arguments['countries'] ?? null);

        if (! $testMode && array() === $checked['valid']) {
            return $this->errors->validationFailed(
                'The expiry alert cannot be sent.',
                array(
                    'recipients' => array(
                        'At least one valid email address. This runtime has no configured default '
                        . 'recipients, so every address must be supplied.',
                    ),
                )
            );
        }

        $metaQuery = array('relation' => 'AND');

        if (array() !== $countries) {
            $metaQuery[] = array('key' => 'country', 'value' => $countries, 'compare' => 'IN');
        }

        $now           = time();
        $registrations = array();
        $unreadable    = array();
        $expired       = 0;

        foreach ($this->collectContent(array(self::REGISTRATION_TYPE), $metaQuery) as $registration) {
            $expiryDate = $this->text($registration->meta['expiry_date'] ?? null);

            if ('' === $expiryDate) {
                continue;
            }

            $days = $this->daysUntil($expiryDate, $now);

            if (null === $days) {
                $unreadable[] = array(
                    'registration_id' => $registration->id,
                    'expiry_date'     => $expiryDate,
                );

                continue;
            }

            if ($days < 0) {
                ++$expired;

                continue;
            }

            if ($days > $threshold) {
                continue;
            }

            $registrations[] = array(
                'id'             => $registration->id,
                'title'          => $registration->title,
                'country'        => $this->text($registration->meta['country'] ?? null),
                'cos_number'     => $this->text($registration->meta['cos_number'] ?? null),
                'expiry_date'    => $expiryDate,
                'days_to_expiry' => $days,
            );
        }

        $registrations = $this->soonestFirst($registrations);

        $transport          = null !== $this->email && $this->email->isAvailable() ? $this->email : null;
        $transportAvailable = null !== $transport;
        $sent               = 0;
        $failures           = array();

        if (! $testMode && null !== $transport && array() !== $registrations) {
            $subject = sprintf(
                'Registration Expiry Alert: %d registrations expiring soon',
                count($registrations)
            );

            $body = "The following registrations are expiring soon:\n\n";

            foreach ($registrations as $registration) {
                $body .= sprintf(
                    "%s (%s) - Expires: %s (%d days)\n",
                    $registration['title'],
                    $registration['country'],
                    $registration['expiry_date'],
                    $registration['days_to_expiry']
                );
            }

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

        return $this->success(
            $this->message($testMode, $transportAvailable, count($registrations), $sent, count($checked['valid'])),
            array(
                'test_mode'              => $testMode,
                'expiring_registrations' => count($registrations),
                'days_threshold'         => $threshold,
                'recipients'             => $checked['valid'],
                'invalid_recipients'     => $checked['invalid'],
                'emails_sent'            => $sent,
                'failed_recipients'      => $failures,
                'transport_available'    => $transportAvailable,
                'expired_excluded'       => $expired,
                'unreadable_expiry_dates' => $unreadable,
                'registrations'          => $registrations,
                'sent_at'                => $this->nowStamp(),
            )
        );
    }

    /**
     * What the caller should understand from the numbers.
     */
    private function message(
        bool $testMode,
        bool $transportAvailable,
        int $registrations,
        int $sent,
        int $recipients
    ): string {
        if (0 === $registrations) {
            return 'No registrations expire inside the window; no alert was due.';
        }

        if ($testMode) {
            return sprintf(
                'Test mode: %d registration(s) would be included in an alert to %d recipient(s).',
                $registrations,
                $recipients
            );
        }

        if (! $transportAvailable) {
            return sprintf(
                '%d registration(s) expire inside the window, but this host has no email service; nothing was sent.',
                $registrations
            );
        }

        return sprintf(
            'Alert listing %d registration(s) sent to %d of %d recipient(s).',
            $registrations,
            $sent,
            $recipients
        );
    }

    /**
     * The requested country filter, trimmed and de-duplicated.
     *
     * @return list<string>
     */
    private function countryFilter(mixed $raw): array
    {
        if (! is_array($raw)) {
            return array();
        }

        $countries = array();

        foreach ($raw as $country) {
            $value = $this->text($country);

            if ('' !== $value && ! in_array($value, $countries, true)) {
                $countries[] = $value;
            }
        }

        return $countries;
    }

    /**
     * Order rows by days to expiry, soonest first, then by id.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function soonestFirst(array $rows): array
    {
        usort(
            $rows,
            static function (array $a, array $b): int {
                $left  = $a['days_to_expiry'] ?? 0;
                $right = $b['days_to_expiry'] ?? 0;

                $left  = is_int($left) ? $left : 0;
                $right = is_int($right) ? $right : 0;

                $idLeft  = $a['id'] ?? 0;
                $idRight = $b['id'] ?? 0;

                return array($left, is_int($idLeft) ? $idLeft : 0)
                    <=> array($right, is_int($idRight) ? $idRight : 0);
            }
        );

        return $rows;
    }
}
