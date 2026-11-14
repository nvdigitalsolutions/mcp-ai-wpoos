<?php
/**
 * Enroll Student in ECA tool — the first write in the classroom family.
 *
 * Mirrors `WP_MCP_AI_Tool_Enroll_Student_ECA` (addons/pro/includes/tools/
 * eca-management): same slug, parameters, capacity and eligibility rules, and
 * the same two-sided write.
 *
 * Enrolment lives on **both** records as an id-keyed map of records
 * (`_student_eca_enrollments[ eca_id ]` and `_eca_student_enrollments[ student_id ]`),
 * exactly as the WordPress tool writes it. A confirmed enrolment also
 * increments `_eca_current_enrollment` and flips the activity to `full` at
 * capacity.
 *
 * Behaviour carried over deliberately, because a "smarter" port would answer
 * differently for the same call:
 * - A full activity does **not** fail; a confirmed enrolment silently becomes
 *   `waitlist` unless `skip_capacity_check` is set.
 * - An unknown `enrollment_type` falls back to `confirmed` rather than erroring.
 * - `payment_status` is stored as `n/a` for a free activity, whatever was sent.
 *
 * Not carried over: the iSAMS push (`sync_enrollment_to_isams`), which is the
 * WordPress-only integration this runtime deliberately does not have.
 *
 * **Not atomic.** Two records are written through `ContentStoreInterface`,
 * which has no transaction seam (a Laravel-only `DB::transaction()` would put
 * the framework back in core). The likely failure — the caller can edit one
 * record but not the other — is caught up front by `userCanAccess()` on both,
 * and a failed activity write rolls the student side back best-effort.
 *
 * @package Nvoos\Core
 * @since   1.4.0
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
use Nvoos\Core\Tool\AbstractTool;

class EnrollStudentEcaTool extends AbstractTool {

	private const TYPES = array( 'confirmed', 'waitlist', 'preference', 'trial' );

	private const PAYMENT_STATUSES = array( 'pending', 'paid', 'partial', 'waived' );

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'enroll_student_eca';
	}

	public function getName(): string {
		return 'Enroll Student in ECA';
	}

	public function getDescription(): string {
		return 'Enrolls a student in an Extra-Curricular Activity. Checks capacity limits, year group eligibility, and handles payment requirements. Updates enrollment counts automatically.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'student_id'          => array(
					'type'        => 'integer',
					'description' => 'Student record ID (required)',
					'minimum'     => 1,
				),
				'eca_id'              => array(
					'type'        => 'integer',
					'description' => 'ECA record ID (required)',
					'minimum'     => 1,
				),
				'enrollment_type'     => array(
					'type'        => 'string',
					'description' => 'Type of enrollment',
					'enum'        => self::TYPES,
					'default'     => 'confirmed',
				),
				'payment_status'      => array(
					'type'        => 'string',
					'description' => 'Payment status (for paid ECAs)',
					'enum'        => self::PAYMENT_STATUSES,
					'default'     => 'pending',
				),
				'notes'               => array(
					'type'        => 'string',
					'description' => 'Enrollment notes or comments',
					'maxLength'   => 1000,
				),
				'skip_capacity_check' => array(
					'type'        => 'boolean',
					'description' => 'Skip capacity check (admin override)',
					'default'     => false,
				),
			),
			'required'             => array( 'student_id', 'eca_id' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$this->authorize( $context, 'edit_posts' );

		$user_id = (int) ( $context['user_id'] ?? 0 );

		$student_id = $this->intParam( $arguments, 'student_id' );
		$eca_id     = $this->intParam( $arguments, 'eca_id' );

		if ( $student_id <= 0 || $eca_id <= 0 ) {
			return $this->errors->validationFailed(
				'Both student_id and eca_id are required.',
				array(
					'student_id' => array( 'This field is required.' ),
					'eca_id'     => array( 'This field is required.' ),
				)
			);
		}

		$student = $this->content->find( $student_id );

		if ( ! $student instanceof ContentItem || 'mcp_ai_student' !== $student->type ) {
			return $this->errors->create( 'invalid_student', 'Invalid student ID.' );
		}

		$eca = $this->content->find( $eca_id );

		if ( ! $eca instanceof ContentItem || 'mcp_ai_eca' !== $eca->type ) {
			return $this->errors->create( 'invalid_eca', 'Invalid ECA ID.' );
		}

		// Read before writing: the maps are the duplicate check.
		$student_enrollments = Enrollments::map( $student->meta['_student_eca_enrollments'] ?? array() );
		$eca_enrollments     = Enrollments::map( $eca->meta['_eca_student_enrollments'] ?? array() );

		// WordPress checks the student side only. The port checks both: this
		// runtime can hold the activity side without the reverse link (the
		// seeder does exactly that, and a partially-written enrolment would
		// too), and enrolling again would add a second row and increment
		// `_eca_current_enrollment` twice for one student.
		if ( array_key_exists( $eca_id, $student_enrollments ) || array_key_exists( $student_id, $eca_enrollments ) ) {
			return $this->errors->create( 'already_enrolled', 'Student is already enrolled in this ECA.' );
		}

		$enrollment_type = strtolower( $this->stringParam( $arguments, 'enrollment_type', 'confirmed' ) );

		if ( ! in_array( $enrollment_type, self::TYPES, true ) ) {
			$enrollment_type = 'confirmed';
		}

		$payment_status = strtolower( $this->stringParam( $arguments, 'payment_status', 'pending' ) );

		if ( ! in_array( $payment_status, self::PAYMENT_STATUSES, true ) ) {
			$payment_status = 'pending';
		}

		$notes = $this->stringParam( $arguments, 'notes' );
		$skip  = $this->boolParam( $arguments, 'skip_capacity_check' );

		$max_students       = $this->intMeta( $eca, '_eca_max_students' );
		$current_enrollment = $this->intMeta( $eca, '_eca_current_enrollment' );

		// WordPress switches a confirmed enrolment to the waitlist when the
		// activity is full; it does not fail the call.
		if ( 'confirmed' === $enrollment_type && ! $skip && $max_students > 0 && $current_enrollment >= $max_students ) {
			$enrollment_type = 'waitlist';
		}

		if ( ! $this->yearGroupEligible( $student, $eca ) ) {
			return $this->errors->create(
				'ineligible_year_group',
				sprintf(
					'Student year group (%1$s) is not eligible for this ECA: %2$s',
					$this->textMeta( $student, '_student_year_group' ),
					$eca->title
				)
			);
		}

		$is_paid = $this->isYes( $eca, '_eca_is_paid' );
		$cost    = $is_paid ? $this->floatMeta( $eca, '_eca_cost' ) : 0.0;
		$paid    = $is_paid ? $payment_status : 'n/a';

		// Pre-flight both writes. ContentStoreInterface has no transaction
		// seam, so the permission split is caught before anything is written.
		foreach ( array( $student_id, $eca_id ) as $target_id ) {
			if ( ! $this->content->userCanAccess( $target_id, $user_id, 'edit' ) ) {
				return $this->errors->forbidden( 'You do not have permission to edit this content.' );
			}
		}

		$enrollment_date = date( 'Y-m-d H:i:s' );

		$record = array(
			'student_id'      => $student_id,
			'eca_id'          => $eca_id,
			'enrollment_type' => $enrollment_type,
			'enrollment_date' => $enrollment_date,
			'payment_status'  => $paid,
			'amount_due'      => $cost,
			'notes'           => $notes,
			'enrolled_by'     => $user_id,
		);

		$student_enrollments[ $eca_id ] = $record;

		try {
			$this->content->update(
				$student_id,
				new UpdateContentCommand(
					userId: $user_id,
					meta: array( '_student_eca_enrollments' => $student_enrollments ),
				)
			);
		} catch ( NotFoundException $e ) {
			return $this->errors->notFound( $e->getMessage() );
		} catch ( AccessDeniedException $e ) {
			return $this->errors->forbidden( $e->getMessage() );
		} catch ( \Throwable $e ) {
			return $this->errors->create( 'enrollment_failed', $e->getMessage() );
		}

		// Append, as WordPress does — the waitlist reads this map in order.
		$eca_enrollments[ $student_id ] = $record;

		$eca_meta = array( '_eca_student_enrollments' => $eca_enrollments );

		if ( 'confirmed' === $enrollment_type ) {
			$new_count = $current_enrollment + 1;

			$eca_meta['_eca_current_enrollment'] = $new_count;

			if ( $max_students > 0 && $new_count >= $max_students ) {
				$eca_meta['_eca_status'] = 'full';
			}
		}

		try {
			$this->content->update(
				$eca_id,
				new UpdateContentCommand( userId: $user_id, meta: $eca_meta )
			);
		} catch ( \Throwable $e ) {
			// Best effort: leave no half enrolment behind. If this fails too,
			// the returned error still tells the caller the truth.
			$this->rollbackStudent( $student_id, $user_id, $eca_id, $student_enrollments );

			return $e instanceof AccessDeniedException
				? $this->errors->forbidden( $e->getMessage() )
				: $this->errors->create( 'enrollment_failed', $e->getMessage() );
		}

		return $this->success(
			sprintf( '%1$s has been enrolled in %2$s.', $student->title, $eca->title ),
			array(
				'student_id'      => $student_id,
				'student_name'    => $student->title,
				'eca_id'          => $eca_id,
				'eca_name'        => $eca->title,
				'enrollment_type' => $enrollment_type,
				'payment_status'  => $paid,
				'amount_due'      => $cost,
				'enrollment_date' => $enrollment_date,
			)
		);
	}

	/**
	 * Remove the entry the first write added, best effort.
	 *
	 * @param array<int, array<string, mixed>> $written Map as written.
	 */
	private function rollbackStudent( int $student_id, int $user_id, int $eca_id, array $written ): void {
		unset( $written[ $eca_id ] );

		try {
			$this->content->update(
				$student_id,
				new UpdateContentCommand(
					userId: $user_id,
					meta: array( '_student_eca_enrollments' => $written ),
				)
			);
		} catch ( \Throwable $e ) {
			// Nothing further can be done here; the caller already has an error.
			unset( $e );
		}
	}

	/**
	 * Year-group eligibility, compared as strings.
	 *
	 * WordPress compares strictly, which breaks as soon as one side holds an
	 * integer — a JSON round trip can turn `'10'` into `10`. String comparison
	 * accepts everything the WordPress check accepts and nothing it rejects.
	 */
	private function yearGroupEligible( ContentItem $student, ContentItem $eca ): bool {
		$groups = $eca->meta['_eca_year_groups'] ?? array();

		if ( ! is_array( $groups ) || array() === $groups ) {
			return true;
		}

		$student_group = $this->textMeta( $student, '_student_year_group' );

		if ( '' === $student_group ) {
			return true;
		}

		$normalised = array_map(
			static fn ( $value ): string => is_scalar( $value ) ? (string) $value : '',
			$groups
		);

		return in_array( $student_group, $normalised, true );
	}

	private function textMeta( ContentItem $item, string $key ): string {
		$value = $item->meta[ $key ] ?? '';

		return is_scalar( $value ) ? (string) $value : '';
	}

	private function intMeta( ContentItem $item, string $key ): int {
		$value = $item->meta[ $key ] ?? 0;

		return is_numeric( $value ) ? (int) $value : 0;
	}

	private function floatMeta( ContentItem $item, string $key ): float {
		$value = $item->meta[ $key ] ?? 0;

		return is_numeric( $value ) ? (float) $value : 0.0;
	}

	/**
	 * WordPress checkbox encoding, tolerating a real boolean or `'1'`.
	 */
	private function isYes( ContentItem $item, string $key ): bool {
		$value = $item->meta[ $key ] ?? '';

		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( (string) $value ), array( 'yes', '1', 'true' ), true );
	}
}
