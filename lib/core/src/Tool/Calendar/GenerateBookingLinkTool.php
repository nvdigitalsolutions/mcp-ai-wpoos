<?php

/**
 * Generate a public booking link.
 *
 * Port of `WP_MCP_AI_Tool_Generate_Booking_Link` (Proposal 164 section 164.87).
 *
 * ## Corrections
 *
 * 1. **The token is stored as a SHA-256 hash, not in plaintext.** WordPress
 *    wrote `_token` verbatim, so every copy of the database contained working
 *    booking links. The token is returned once, in this response; only
 *    `_token_hash` is stored. See `BookingLinkDefinition`.
 * 2. **The expiry is absolute.** WordPress stored `_expiry_days` and nothing
 *    computed a deadline, so every consumer had to re-derive it from the
 *    creation time — and none did. `_expires_at` is written in UTC and is the
 *    authority; `_expiry_days` is echoed for round-trip only.
 * 3. **The bounds are enforced.** Duration reuses the appointment bounds
 *    (15–480), expiry is capped at ten years, and a negative `max_bookings` is
 *    refused. WordPress ran `absint` over all three, which silently turned a
 *    negative limit into "unlimited" — the opposite of what the caller asked.
 * 4. **The capability is the one WordPress enforced.** The WordPress class
 *    declared `edit_posts` and then required `manage_options` inside
 *    `execute()`; minting a public access grant is an administrative act, so
 *    the port declares the enforced capability.
 *
 * ## Adaptations
 *
 * The runtime has no site URL, so the response always carries `booking_path`.
 * `booking_url` is filled only when a `RuntimeIdentityInterface` is bound and
 * reports a usable `site_url`; it is `null` otherwise rather than a fabricated
 * absolute URL. `token` and `booking_url` are sensitive: either one grants
 * booking access, so neither should be logged or echoed into a transcript.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\RuntimeIdentityInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class GenerateBookingLinkTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * An unbound identity service means the host does not expose a site URL;
     * the registrar's optional-collaborator rule expresses that as a null
     * default, and the payload then reports `booking_path` only.
     */
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
        private readonly ?RuntimeIdentityInterface $identity = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'generate_booking_link';
    }

    public function getName(): string
    {
        return 'Generate Booking Link';
    }

    public function getDescription(): string
    {
        return 'Creates a public booking link for one appointment type, with an optional default duration, expiry window and booking cap. Returns the token once; the link resolves at /book/{token}.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'appointment_type' => array(
                    'type'        => 'string',
                    'description' => 'Type of appointment the link books. Defaults to "general".',
                    'maxLength'   => BookingLinkDefinition::MAX_APPOINTMENT_TYPE,
                ),
                'duration_minutes' => array(
                    'type'        => 'integer',
                    'description' => 'Default appointment length in minutes.',
                    'default'     => AppointmentDefinition::DEFAULT_DURATION,
                    'minimum'     => AppointmentDefinition::MIN_DURATION,
                    'maximum'     => AppointmentDefinition::MAX_DURATION,
                ),
                'expiry_days'      => array(
                    'type'        => 'integer',
                    'description' => 'Link expiry in days. 0 means no expiry.',
                    'default'     => 0,
                    'minimum'     => 0,
                    'maximum'     => BookingLinkDefinition::MAX_EXPIRY_DAYS,
                ),
                'max_bookings'     => array(
                    'type'        => 'integer',
                    'description' => 'Maximum bookings the link may take. 0 means unlimited.',
                    'default'     => 0,
                    'minimum'     => 0,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'manage_options';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return CapabilityFlagVocabulary::LOCAL_WRITE;
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $userId = (int) ( $context['user_id'] ?? 0 );

        $type = $this->appointmentType($arguments);

        if (null === $type) {
            return $this->errors->validationFailed(
                sprintf(
                    'appointment_type must be a single line of at most %d bytes.',
                    BookingLinkDefinition::MAX_APPOINTMENT_TYPE
                ),
                array('appointment_type' => array('Too long.'))
            );
        }

        $duration = $this->intInRange(
            $arguments,
            'duration_minutes',
            AppointmentDefinition::DEFAULT_DURATION,
            AppointmentDefinition::MIN_DURATION,
            AppointmentDefinition::MAX_DURATION
        );

        if (null === $duration) {
            return $this->errors->validationFailed(
                sprintf(
                    'duration_minutes must be between %d and %d.',
                    AppointmentDefinition::MIN_DURATION,
                    AppointmentDefinition::MAX_DURATION
                ),
                array('duration_minutes' => array('Out of range.'))
            );
        }

        $expiryDays = $this->intInRange(
            $arguments,
            'expiry_days',
            0,
            0,
            BookingLinkDefinition::MAX_EXPIRY_DAYS
        );

        if (null === $expiryDays) {
            return $this->errors->validationFailed(
                sprintf('expiry_days must be between 0 and %d.', BookingLinkDefinition::MAX_EXPIRY_DAYS),
                array('expiry_days' => array('Out of range.'))
            );
        }

        $maxBookings = $this->intInRange($arguments, 'max_bookings', 0, 0, PHP_INT_MAX);

        if (null === $maxBookings) {
            return $this->errors->validationFailed(
                'max_bookings must be 0 (unlimited) or a positive integer.',
                array('max_bookings' => array('Out of range.'))
            );
        }

        try {
            $token = BookingLinkDefinition::token();
            $now   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $expiry = BookingLinkDefinition::expiresAt($expiryDays, $now);

            $item = $this->content->create(
                new CreateContentCommand(
                    title: BookingLinkDefinition::title($type),
                    type: BookingLinkDefinition::TYPE,
                    authorId: $userId,
                    status: 'publish',
                    content: '',
                    meta: array(
                        BookingLinkDefinition::META_TOKEN_HASH       => BookingLinkDefinition::hash($token),
                        BookingLinkDefinition::META_APPOINTMENT_TYPE => $type,
                        BookingLinkDefinition::META_DURATION         => $duration,
                        BookingLinkDefinition::META_EXPIRY_DAYS      => $expiryDays,
                        BookingLinkDefinition::META_EXPIRES_AT       => $expiry,
                        BookingLinkDefinition::META_MAX_BOOKINGS     => $maxBookings,
                        BookingLinkDefinition::META_BOOKINGS_COUNT   => 0,
                        BookingLinkDefinition::META_CREATED_BY       => $userId,
                        BookingLinkDefinition::META_CREATED_AT       => $now->format(AppointmentDefinition::TIME_FORMAT),
                    ),
                )
            );
        } catch (AccessDeniedException $error) {
            return $this->errors->forbidden($error->getMessage());
        } catch (\Throwable $error) {
            return $this->errors->create('booking_link_write_failed', $error->getMessage());
        }

        $path = BookingLinkDefinition::path($token);

        return $this->success(
            'Booking link generated successfully.',
            array(
                'link_id'      => $item->id,
                'link'         => BookingLinkDefinition::row($item),
                'booking_path' => $path,
                'booking_url'  => $this->absoluteUrl($path),
                'token'        => $token,
            )
        );
    }

    /**
     * The appointment-type label, or null when it is unusable.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function appointmentType(array $arguments): ?string
    {
        if (! array_key_exists('appointment_type', $arguments)) {
            return BookingLinkDefinition::DEFAULT_APPOINTMENT_TYPE;
        }

        $raw = $arguments['appointment_type'];

        if (null === $raw || '' === $raw) {
            return BookingLinkDefinition::DEFAULT_APPOINTMENT_TYPE;
        }

        if (! is_scalar($raw)) {
            return null;
        }

        $type = Text::singleLine((string) $raw);

        if ('' === $type) {
            return BookingLinkDefinition::DEFAULT_APPOINTMENT_TYPE;
        }

        return strlen($type) > BookingLinkDefinition::MAX_APPOINTMENT_TYPE ? null : $type;
    }

    /**
     * A bounded integer argument, or null when it is present and unusable.
     *
     * An absent, null or empty value takes the default; a non-numeric or
     * out-of-range value is a caller error rather than a silent fallback,
     * because all three fields are durable.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function intInRange(array $arguments, string $key, int $default, int $min, int $max): ?int
    {
        if (! array_key_exists($key, $arguments) || null === $arguments[ $key ] || '' === $arguments[ $key ]) {
            return $default;
        }

        if (! is_numeric($arguments[ $key ])) {
            return null;
        }

        $value = (int) $arguments[ $key ];

        return ( $value < $min || $value > $max ) ? null : $value;
    }

    /**
     * The absolute URL, when the host reports a site URL.
     *
     * A relative or non-http(s) value is refused rather than concatenated:
     * a `booking_url` of `/wp` plus a path is not a link.
     */
    private function absoluteUrl(string $path): ?string
    {
        if (null === $this->identity) {
            return null;
        }

        $identity = $this->identity->identity();
        $site     = is_array($identity) && isset($identity['site_url']) && is_string($identity['site_url'])
            ? trim($identity['site_url'])
            : '';

        if ('' === $site || 1 !== preg_match('#^https?://#i', $site)) {
            return null;
        }

        return rtrim($site, '/') . $path;
    }
}
