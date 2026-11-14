<?php
/**
 * Update ECA tool — field-level patch with an audit trail.
 *
 * Mirrors `WP_MCP_AI_Tool_Update_ECA` (addons/pro/includes/tools/eca-management):
 * same slug, parameters, capacity guard, change log shape and status
 * auto-adjustment.
 *
 * Only the arguments the caller supplies are touched. That is the whole point
 * of this tool existing next to `create_eca`, whose update branch always
 * rewrites the required meta.
 *
 * Behaviour carried over deliberately:
 * - Setting `max_students` below `_eca_current_enrollment` is rejected with the
 *   current count in the message.
 * - A `max_students` change auto-adjusts `_eca_status`: `full` falls back to
 *   `active` when the new maximum clears the current count, and `active` flips
 *   to `full` when the new maximum is reached or exceeded.
 * - Every change is appended to `_eca_change_history` as
 *   `{timestamp, user_id, changes[]}` with `field` / `old_value` / `new_value`,
 *   capped at the last 100 entries.
 * - Old/new comparison is **loose**, as WordPress does, so `'45'` and `45` are
 *   not reported as a change.
 *
 * Divergences, all deliberate:
 * - **Enums are validated, not stored blind.** WordPress declares an enum in
 *   the schema and then stores whatever text arrives, so `status: 'bogus'`
 *   lands in the meta and breaks the `full` checks downstream. A patch tool
 *   that silently substitutes a default would be worse still, so an
 *   out-of-enum value is a validation error.
 * - **Numeric fields are stored as numbers.** WordPress runs every field
 *   through `sanitize_text_field()`, so `update_eca` stores `_eca_cost` as the
 *   string `'45'` while `create_eca` stores a float. Readers use
 *   `floatval()` / `is_numeric()`, so normalising here is invisible to them.
 * - **`description` is sanitised text, not post HTML** — same divergence as
 *   `create_eca` (`wp_kses_post` has no core equivalent).
 * - **One write.** WordPress issues one `update_post_meta` per field plus one
 *   for the audit row; the store contract takes a single update.
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
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class UpdateEcaTool extends AbstractTool {

	private const TYPES = array( 'club', 'society', 'sport_squad', 'sport_academy', 'activity' );

	private const DAYS = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );

	private const STATUSES = array( 'active', 'inactive', 'full', 'cancelled' );

	private const BOOKING_TYPES = array( 'first_come_first_served', 'preference_based', 'preselected', 'signup' );

	private const COST_PERIODS = array( 'term', 'month', 'session', 'year' );

	private const CURRENCIES = array( 'GBP', 'USD', 'EUR', 'AED', 'INR', 'AUD', 'CAD', 'SGD', 'ZAR' );

	private const TERMS = array( 'Term 1', 'Term 2', 'Term 3', 'Yearly' );

	/**
	 * Argument → [ meta key, coercion ]. Coercion is `text`, `list`, `int` or `float`.
	 */
	private const FIELDS = array(
		'eca_code'     => array( '_eca_code', 'text' ),
		'eca_type'     => array( '_eca_type', 'text' ),
		'day'          => array( '_eca_day', 'text' ),
		'start_time'   => array( '_eca_start_time', 'text' ),
		'end_time'     => array( '_eca_end_time', 'text' ),
		'venue'        => array( '_eca_venue', 'text' ),
		'year_groups'  => array( '_eca_year_groups', 'list' ),
		'max_students' => array( '_eca_max_students', 'int' ),
		'teachers'     => array( '_eca_teachers', 'list' ),
		'cost'         => array( '_eca_cost', 'float' ),
		'cost_period'  => array( '_eca_cost_period', 'text' ),
		'currency'     => array( '_eca_currency', 'text' ),
		'term'         => array( '_eca_term', 'text' ),
		'booking_type' => array( '_eca_booking_type', 'text' ),
		'status'       => array( '_eca_status', 'text' ),
	);

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'update_eca';
	}

	public function getName(): string {
		return 'Update ECA';
	}

	public function getDescription(): string {
		return 'Updates individual fields on an existing ECA and records every change in the activity change history. Only the supplied fields are touched.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'eca_id'            => array(
					'type'        => 'integer',
					'description' => 'ECA record ID to update (required)',
					'minimum'     => 1,
				),
				'name'              => array(
					'type'        => 'string',
					'description' => 'New ECA name',
					'maxLength'   => 200,
				),
				'description'       => array(
					'type'        => 'string',
					'description' => 'New description',
					'maxLength'   => 10000,
				),
				'eca_code'          => array(
					'type'        => 'string',
					'description' => 'New ECA code',
					'maxLength'   => 50,
				),
				'eca_type'          => array(
					'type'        => 'string',
					'description' => 'New activity type',
					'enum'        => self::TYPES,
				),
				'day'               => array(
					'type'        => 'string',
					'description' => 'New day of the week',
					'enum'        => self::DAYS,
				),
				'start_time'        => array(
					'type'        => 'string',
					'description' => 'New start time (HH:MM AM/PM)',
					'pattern'     => '^\d{1,2}:\d{2}\s?(AM|PM|am|pm)$',
				),
				'end_time'          => array(
					'type'        => 'string',
					'description' => 'New end time (HH:MM AM/PM)',
					'pattern'     => '^\d{1,2}:\d{2}\s?(AM|PM|am|pm)$',
				),
				'venue'             => array(
					'type'        => 'string',
					'description' => 'New venue or location',
					'maxLength'   => 200,
				),
				'year_groups'       => array(
					'type'        => 'array',
					'description' => 'New eligible year groups',
					'items'       => array( 'type' => 'string' ),
				),
				'max_students'      => array(
					'type'        => 'integer',
					'description' => 'New maximum students',
					'minimum'     => 1,
					'maximum'     => 200,
				),
				'teachers'          => array(
					'type'        => 'array',
					'description' => 'New teachers in charge',
					'items'       => array( 'type' => 'string' ),
				),
				'is_paid'           => array(
					'type'        => 'boolean',
					'description' => 'Whether this is a paid activity',
				),
				'cost'              => array(
					'type'        => 'number',
					'description' => 'New cost per student',
					'minimum'     => 0,
				),
				'cost_period'       => array(
					'type'        => 'string',
					'description' => 'New billing period',
					'enum'        => self::COST_PERIODS,
				),
				'currency'          => array(
					'type'        => 'string',
					'description' => 'New currency code',
					'enum'        => self::CURRENCIES,
				),
				'term'              => array(
					'type'        => 'string',
					'description' => 'New term',
					'enum'        => self::TERMS,
				),
				'requires_audition' => array(
					'type'        => 'boolean',
					'description' => 'Whether the activity requires an audition or tryout',
				),
				'booking_type'      => array(
					'type'        => 'string',
					'description' => 'New enrolment booking method',
					'enum'        => self::BOOKING_TYPES,
				),
				'status'            => array(
					'type'        => 'string',
					'description' => 'New activity status',
					'enum'        => self::STATUSES,
				),
			),
			'required'             => array( 'eca_id' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$this->authorize( $context, 'edit_posts' );

		$user_id = (int) ( $context['user_id'] ?? 0 );
		$eca_id  = $this->intParam( $arguments, 'eca_id' );

		if ( $eca_id <= 0 ) {
			return $this->errors->create( 'missing_id', 'ECA ID is required.' );
		}

		$eca = $this->content->find( $eca_id );

		if ( ! $eca instanceof ContentItem || 'mcp_ai_eca' !== $eca->type ) {
			return $this->errors->create( 'invalid_eca', 'Invalid ECA ID.' );
		}

		if ( ! $this->content->userCanAccess( $eca_id, $user_id, 'edit' ) ) {
			return $this->errors->forbidden( 'You do not have permission to update this ECA.' );
		}

		$current_enrollment = $this->intMeta( $eca, '_eca_current_enrollment' );

		if ( isset( $arguments['max_students'] ) ) {
			$new_max = $this->intParam( $arguments, 'max_students' );

			if ( $new_max < $current_enrollment ) {
				return $this->errors->create(
					'capacity_conflict',
					sprintf( 'Cannot set max students below current enrollment count (%d).', $current_enrollment )
				);
			}
		}

		$enum_error = $this->validateEnums( $arguments );

		if ( null !== $enum_error ) {
			return $enum_error;
		}

		$changes = array();
		$title   = null;
		$content = null;

		if ( isset( $arguments['name'] ) ) {
			$title = Text::singleLine( $this->stringParam( $arguments, 'name' ) );
			$this->record( $changes, 'name', $eca->title, $title );
		}

		if ( isset( $arguments['description'] ) ) {
			$content = Text::multiline( $this->rawString( $arguments, 'description' ) );
			$this->record( $changes, 'description', $eca->content, $content );
		}

		$meta = array();

		foreach ( self::FIELDS as $argument => $field ) {
			if ( ! isset( $arguments[ $argument ] ) ) {
				continue;
			}

			list( $meta_key, $coercion ) = $field;

			$value = $this->coerce( $arguments[ $argument ], $coercion );

			$meta[ $meta_key ] = $value;

			$this->record( $changes, $argument, $eca->meta[ $meta_key ] ?? '', $value );
		}

		foreach ( array( 'is_paid' => '_eca_is_paid', 'requires_audition' => '_eca_requires_audition' ) as $argument => $meta_key ) {
			if ( ! isset( $arguments[ $argument ] ) ) {
				continue;
			}

			$value = $this->boolParam( $arguments, $argument ) ? 'yes' : 'no';

			$meta[ $meta_key ] = $value;

			$this->record( $changes, $argument, $eca->meta[ $meta_key ] ?? '', $value );
		}

		// The status the activity will hold once this patch lands. WordPress
		// re-reads it after its field loop; computing it here is the same thing
		// without the round trip.
		$effective_status = isset( $meta['_eca_status'] )
			? (string) $meta['_eca_status']
			: $this->textMeta( $eca, '_eca_status' );

		if ( isset( $arguments['max_students'] ) ) {
			$new_max = $this->intParam( $arguments, 'max_students' );

			if ( 'full' === $effective_status && $new_max > $current_enrollment ) {
				$meta['_eca_status'] = 'active';
				$this->record( $changes, 'status', 'full', 'active' );
			} elseif ( 'active' === $effective_status && $new_max > 0 && $current_enrollment >= $new_max ) {
				$meta['_eca_status'] = 'full';
				$this->record( $changes, 'status', 'active', 'full' );
			}
		}

		if ( array() !== $changes ) {
			$meta['_eca_change_history'] = $this->appendHistory( $eca, $changes, $user_id );
		}

		try {
			$this->content->update(
				$eca_id,
				new UpdateContentCommand(
					userId: $user_id,
					title: $title,
					content: $content,
					meta: $meta,
				)
			);
		} catch ( NotFoundException $e ) {
			return $this->errors->notFound( $e->getMessage() );
		} catch ( AccessDeniedException $e ) {
			return $this->errors->forbidden( $e->getMessage() );
		} catch ( \Throwable $e ) {
			return $this->errors->create( 'eca_write_failed', $e->getMessage() );
		}

		return $this->success(
			'ECA updated successfully.',
			array(
				'eca_id'         => $eca_id,
				'fields_changed' => count( $changes ),
				'changes'        => $changes,
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
	 * Reject an out-of-enum value instead of storing it or substituting one.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 */
	private function validateEnums( array $arguments ): mixed {
		$enums = array(
			'eca_type'     => self::TYPES,
			'day'          => self::DAYS,
			'status'       => self::STATUSES,
			'booking_type' => self::BOOKING_TYPES,
			'cost_period'  => self::COST_PERIODS,
			'currency'     => self::CURRENCIES,
			'term'         => self::TERMS,
		);

		foreach ( $enums as $argument => $allowed ) {
			if ( ! isset( $arguments[ $argument ] ) ) {
				continue;
			}

			$value = Text::singleLine( (string) $arguments[ $argument ] );

			// Currency and term codes are case-sensitive labels; the rest are keys.
			$match = in_array( $argument, array( 'currency', 'term' ), true )
				? in_array( $value, $allowed, true )
				: in_array( Text::sanitizeKey( $value ), $allowed, true );

			if ( ! $match ) {
				return $this->errors->validationFailed(
					sprintf( 'Invalid value for %s.', $argument ),
					array( $argument => array( sprintf( 'Must be one of: %s.', implode( ', ', $allowed ) ) ) )
				);
			}
		}

		return null;
	}

	/**
	 * Coerce an incoming value to the type the meta field expects.
	 *
	 * @param mixed  $value    Raw argument.
	 * @param string $coercion One of `text`, `list`, `int`, `float`.
	 * @return mixed
	 */
	private function coerce( mixed $value, string $coercion ): mixed {
		switch ( $coercion ) {
			case 'list':
				$list = array();

				foreach ( is_array( $value ) ? $value : array() as $entry ) {
					if ( is_scalar( $entry ) ) {
						$list[] = Text::singleLine( (string) $entry );
					}
				}

				return $list;

			case 'int':
				return is_numeric( $value ) ? (int) $value : 0;

			case 'float':
				return is_numeric( $value ) ? (float) $value : 0.0;
		}

		return Text::singleLine( is_scalar( $value ) ? (string) $value : '' );
	}

	/**
	 * Append a change row to the audit trail, capped at 100 entries.
	 *
	 * @param array<int, array<string, string>> $changes Changes recorded this call.
	 * @return array<int, array<string, mixed>>
	 */
	private function appendHistory( ContentItem $eca, array $changes, int $user_id ): array {
		$history = $eca->meta['_eca_change_history'] ?? array();

		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$history[] = array(
			'timestamp' => date( 'Y-m-d H:i:s' ),
			'user_id'   => $user_id,
			'changes'   => $changes,
		);

		if ( count( $history ) > 100 ) {
			$history = array_slice( $history, -100 );
		}

		return array_values( $history );
	}

	/**
	 * Record a change unless the values are loosely equal, as WordPress does.
	 *
	 * @param array<int, array<string, string>> $changes Change log, by reference.
	 * @param string                            $field   Argument name reported to the caller.
	 * @param mixed                             $old     Current value.
	 * @param mixed                             $new     Incoming value.
	 */
	private function record( array &$changes, string $field, mixed $old, mixed $new ): void {
		// phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- parity with the WordPress tool.
		if ( $old == $new ) {
			return;
		}

		$changes[] = array(
			'field'     => $field,
			'old_value' => is_array( $old ) ? (string) json_encode( $old ) : (string) $old,
			'new_value' => is_array( $new ) ? (string) json_encode( $new ) : (string) $new,
		);
	}

	private function textMeta( ContentItem $item, string $key ): string {
		$value = $item->meta[ $key ] ?? '';

		return is_scalar( $value ) ? (string) $value : '';
	}

	private function intMeta( ContentItem $item, string $key ): int {
		$value = $item->meta[ $key ] ?? 0;

		return is_numeric( $value ) ? (int) $value : 0;
	}
}
