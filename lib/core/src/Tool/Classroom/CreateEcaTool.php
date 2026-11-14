<?php
/**
 * Create ECA tool — create, or update in place when `eca_id` is supplied.
 *
 * Mirrors `WP_MCP_AI_Tool_Create_ECA` (addons/pro/includes/tools/eca-management):
 * same slug, parameters, defaults, enum fallbacks and result shape.
 *
 * The dual create/update behaviour is WordPress's, not a simplification: the
 * schema requires `name` either way, and the tool sets `updated` in the result
 * so the caller can tell which branch ran.
 *
 * Behaviour carried over deliberately:
 * - Optional meta is only written when supplied and non-empty. Passing
 *   `venue: ''` therefore does **not** clear an existing venue — `update_eca`
 *   is the tool for that.
 * - `_eca_type`, `_eca_is_paid`, `_eca_requires_audition`, `_eca_booking_type`
 *   and `_eca_status` are always written, so their defaults are applied on
 *   create and re-applied on update when the argument is omitted.
 * - `_eca_cost` / `_eca_cost_period` are only written for a paid activity with
 *   a cost above zero.
 * - An unknown enum value falls back to the default rather than erroring; an
 *   unknown `day` is an error (WordPress validates it strictly).
 * - The create branch seeds `_eca_current_enrollment` at 0.
 *
 * Divergences, all deliberate:
 * - **No content-media embedding.** WordPress merges `content_images` /
 *   `content_charts` into the description through a trait that builds
 *   attachment `<img>` and chart markup. Core has no media seam, and a tool
 *   that advertises parameters it ignores is worse than one that does not
 *   advertise them, so the schema omits them.
 * - **`description` is stored as sanitised text, not post HTML.** WordPress
 *   runs `wp_kses_post`; core has no kses equivalent. `Text::multiline()` is
 *   the `sanitize_textarea_field()` shape, which is stricter — formatting
 *   markup is dropped rather than filtered. A content-sanitiser contract would
 *   restore parity.
 * - **`url` and `edit_url` are empty strings.** Core has no permalink or admin
 *   URL, the same divergence `list_ecas` already documents.
 * - **No iSAMS sync.** Consistent with the rest of the classroom family.
 *
 * @package Nvoos\Core
 * @since   1.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Classroom;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class CreateEcaTool extends AbstractTool {

	private const TYPES = array( 'club', 'society', 'sport_squad', 'sport_academy', 'activity' );

	private const DAYS = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );

	private const STATUSES = array( 'active', 'inactive', 'full', 'cancelled' );

	private const BOOKING_TYPES = array( 'first_come_first_served', 'preference_based', 'preselected', 'signup' );

	private const COST_PERIODS = array( 'term', 'month', 'session', 'year' );

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'create_eca';
	}

	public function getName(): string {
		return 'Create ECA';
	}

	public function getDescription(): string {
		return 'Creates a new Extra-Curricular Activity, or updates an existing one when eca_id is supplied. Includes schedule, venue, capacity, teacher assignments and cost.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'eca_id'            => array(
					'type'        => 'integer',
					'description' => 'Optional ECA ID. When supplied the existing activity is updated instead of a new one created.',
					'minimum'     => 1,
				),
				'name'              => array(
					'type'        => 'string',
					'description' => 'ECA name (required)',
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'eca_code'          => array(
					'type'        => 'string',
					'description' => 'ECA code or identifier (optional, e.g. "ECA-001")',
					'maxLength'   => 50,
				),
				'description'       => array(
					'type'        => 'string',
					'description' => 'ECA description and details (optional)',
					'maxLength'   => 10000,
				),
				'eca_type'          => array(
					'type'        => 'string',
					'description' => 'Type of activity',
					'enum'        => self::TYPES,
					'default'     => 'club',
				),
				'day'               => array(
					'type'        => 'string',
					'description' => 'Day of the week the activity takes place',
					'enum'        => self::DAYS,
				),
				'start_time'        => array(
					'type'        => 'string',
					'description' => 'Start time in HH:MM AM/PM format (e.g. "2:45 PM")',
					'pattern'     => '^\d{1,2}:\d{2}\s?(AM|PM|am|pm)$',
				),
				'end_time'          => array(
					'type'        => 'string',
					'description' => 'End time in HH:MM AM/PM format (e.g. "3:30 PM")',
					'pattern'     => '^\d{1,2}:\d{2}\s?(AM|PM|am|pm)$',
				),
				'venue'             => array(
					'type'        => 'string',
					'description' => 'Venue or location',
					'maxLength'   => 200,
				),
				'year_groups'       => array(
					'type'        => 'array',
					'description' => 'Year groups eligible (e.g. ["Year 7", "Year 8"])',
					'items'       => array( 'type' => 'string' ),
				),
				'max_students'      => array(
					'type'        => 'integer',
					'description' => 'Maximum number of students that can enrol',
					'minimum'     => 1,
					'maximum'     => 200,
				),
				'teachers'          => array(
					'type'        => 'array',
					'description' => 'Teachers in charge',
					'items'       => array( 'type' => 'string' ),
				),
				'is_paid'           => array(
					'type'        => 'boolean',
					'description' => 'Whether this is a paid activity',
					'default'     => false,
				),
				'cost'              => array(
					'type'        => 'number',
					'description' => 'Cost per student (paid activities only)',
					'minimum'     => 0,
				),
				'cost_period'       => array(
					'type'        => 'string',
					'description' => 'Billing period for the cost',
					'enum'        => self::COST_PERIODS,
					'default'     => 'term',
				),
				'requires_audition' => array(
					'type'        => 'boolean',
					'description' => 'Whether the activity requires an audition or tryout',
					'default'     => false,
				),
				'booking_type'      => array(
					'type'        => 'string',
					'description' => 'How enrolment is handled',
					'enum'        => self::BOOKING_TYPES,
					'default'     => 'first_come_first_served',
				),
				'status'            => array(
					'type'        => 'string',
					'description' => 'Activity status',
					'enum'        => self::STATUSES,
					'default'     => 'active',
				),
				'isams_sync_id'     => array(
					'type'        => 'string',
					'description' => 'iSAMS/SOCS system ID, stored for reference (no sync on this runtime)',
					'maxLength'   => 100,
				),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$this->authorize( $context, 'edit_posts' );

		$user_id = (int) ( $context['user_id'] ?? 0 );

		$eca_id = $this->intParam( $arguments, 'eca_id' );
		$name   = Text::singleLine( $this->stringParam( $arguments, 'name' ) );

		if ( '' === $name ) {
			return $this->errors->validationFailed(
				'ECA name is required.',
				array( 'name' => array( 'This field is required.' ) )
			);
		}

		$existing = null;

		if ( $eca_id > 0 ) {
			$existing = $this->content->find( $eca_id );

			if ( ! $existing instanceof ContentItem || 'mcp_ai_eca' !== $existing->type ) {
				return $this->errors->create( 'eca_not_found', 'ECA not found.' );
			}

			if ( ! $this->content->userCanAccess( $eca_id, $user_id, 'edit' ) ) {
				return $this->errors->forbidden( 'You do not have permission to update this ECA.' );
			}
		}

		$day = Text::singleLine( $this->stringParam( $arguments, 'day' ) );

		// WordPress validates the day strictly instead of falling back.
		if ( '' !== $day && ! in_array( $day, self::DAYS, true ) ) {
			return $this->errors->create( 'invalid_day', 'Invalid day of week.' );
		}

		$eca_code    = Text::singleLine( $this->stringParam( $arguments, 'eca_code' ) );
		$description = Text::multiline( $this->rawString( $arguments, 'description' ) );
		$type        = $this->enumOr( $this->stringParam( $arguments, 'eca_type', 'club' ), self::TYPES, 'club' );
		$start_time  = Text::singleLine( $this->stringParam( $arguments, 'start_time' ) );
		$end_time    = Text::singleLine( $this->stringParam( $arguments, 'end_time' ) );
		$venue       = Text::singleLine( $this->stringParam( $arguments, 'venue' ) );
		$max         = $this->intParam( $arguments, 'max_students' );
		$is_paid     = $this->boolParam( $arguments, 'is_paid' );
		$cost        = $this->floatParam( $arguments, 'cost' );
		$cost_period = $this->enumOr( $this->stringParam( $arguments, 'cost_period', 'term' ), self::COST_PERIODS, 'term' );
		$audition    = $this->boolParam( $arguments, 'requires_audition' );
		$booking     = $this->enumOr(
			$this->stringParam( $arguments, 'booking_type', 'first_come_first_served' ),
			self::BOOKING_TYPES,
			'first_come_first_served'
		);
		$status      = $this->enumOr( $this->stringParam( $arguments, 'status', 'active' ), self::STATUSES, 'active' );
		$isams_id    = Text::singleLine( $this->stringParam( $arguments, 'isams_sync_id' ) );

		$year_groups = $this->stringList( $arguments, 'year_groups' );
		$teachers    = $this->stringList( $arguments, 'teachers' );

		$meta = array(
			'_eca_type'              => $type,
			'_eca_is_paid'           => $is_paid ? 'yes' : 'no',
			'_eca_requires_audition' => $audition ? 'yes' : 'no',
			'_eca_booking_type'      => $booking,
			'_eca_status'            => $status,
		);

		// Optional meta is only written when supplied and non-empty, exactly as
		// WordPress does — an empty string is "not provided", not "clear it".
		foreach ( array(
			'_eca_code'       => $eca_code,
			'_eca_day'        => $day,
			'_eca_start_time' => $start_time,
			'_eca_end_time'   => $end_time,
			'_eca_venue'      => $venue,
			'_eca_isams_sync_id' => $isams_id,
		) as $key => $value ) {
			if ( '' !== $value ) {
				$meta[ $key ] = $value;
			}
		}

		if ( $max > 0 ) {
			$meta['_eca_max_students'] = $max;
		}

		if ( array() !== $year_groups ) {
			$meta['_eca_year_groups'] = $year_groups;
		}

		if ( array() !== $teachers ) {
			$meta['_eca_teachers'] = $teachers;
		}

		if ( $is_paid && $cost > 0 ) {
			$meta['_eca_cost']        = $cost;
			$meta['_eca_cost_period'] = $cost_period;
		}

		try {
			if ( $existing instanceof ContentItem ) {
				$this->content->update(
					$eca_id,
					new UpdateContentCommand(
						userId: $user_id,
						title: $name,
						content: $description,
						meta: $meta,
					)
				);
			} else {
				$meta['_eca_current_enrollment'] = 0;

				$created = $this->content->create(
					new CreateContentCommand(
						title: $name,
						type: 'mcp_ai_eca',
						authorId: $user_id,
						status: 'publish',
						content: $description,
						meta: $meta,
					)
				);

				$eca_id = $created->id;
			}
		} catch ( NotFoundException $e ) {
			return $this->errors->notFound( $e->getMessage() );
		} catch ( AccessDeniedException $e ) {
			return $this->errors->forbidden( $e->getMessage() );
		} catch ( \Throwable $e ) {
			return $this->errors->create( 'eca_write_failed', $e->getMessage() );
		}

		$saved = $this->content->find( $eca_id );

		if ( ! $saved instanceof ContentItem ) {
			return $this->errors->notFound( 'ECA not found.' );
		}

		$updated = $existing instanceof ContentItem;

		return $this->success(
			$updated
				? sprintf( 'ECA updated: %s', $name )
				: 'ECA created successfully.',
			array(
				'eca_id'             => $eca_id,
				'name'               => $saved->title,
				'eca_code'           => $eca_code,
				'type'               => $type,
				'day'                => $day,
				'start_time'         => $start_time,
				'end_time'           => $end_time,
				'venue'              => $venue,
				'max_students'       => $max,
				'current_enrollment' => $this->intMeta( $saved, '_eca_current_enrollment' ),
				'year_groups'        => $year_groups,
				'teachers'           => $teachers,
				'is_paid'            => $is_paid,
				'cost'               => $is_paid ? $cost : 0.0,
				'status'             => $status,
				'url'                => '',
				'edit_url'           => '',
				'updated'            => $updated,
			)
		);
	}

	/**
	 * Read a string argument without the default `strip_tags()` pass.
	 *
	 * `Text::multiline()` must see `<script>` and `<style>` **blocks** to remove
	 * them with their contents; running `strip_tags()` first leaves the contents
	 * behind as plain text and there is nothing left to match.
	 */
	private function rawString( array $arguments, string $key ): string {
		$value = $arguments[ $key ] ?? '';

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Map a value onto a fixed set, case-insensitively, with a fallback.
	 *
	 * @param string             $value   Raw argument value.
	 * @param array<int, string> $allowed Permitted values.
	 * @param string             $default Fallback when nothing matches.
	 */
	private function enumOr( string $value, array $allowed, string $default ): string {
		$normalised = Text::sanitizeKey( $value );

		return in_array( $normalised, $allowed, true ) ? $normalised : $default;
	}

	/**
	 * Sanitised list of strings, dropping anything that is not scalar.
	 *
	 * @return array<int, string>
	 */
	private function stringList( array $arguments, string $key ): array {
		$raw = $arguments[ $key ] ?? array();

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$values = array();

		foreach ( $raw as $value ) {
			if ( is_scalar( $value ) ) {
				$values[] = Text::singleLine( (string) $value );
			}
		}

		return $values;
	}

	private function intMeta( ContentItem $item, string $key ): int {
		$value = $item->meta[ $key ] ?? 0;

		return is_numeric( $value ) ? (int) $value : 0;
	}
}
