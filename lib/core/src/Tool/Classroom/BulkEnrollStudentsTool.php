<?php
/**
 * Bulk Enroll Students tool — the batch write in the classroom family.
 *
 * Mirrors `WP_MCP_AI_Tool_Bulk_Enroll_Students` (addons/pro/includes/tools/
 * eca-management): same slug, parameters, capacity rule and per-row result
 * shape.
 *
 * The batch is **partial-success by contract**. One bad row does not abort the
 * call; it comes back with `status: failed` and a reason, and the summary
 * counts tally the rest. That is what the WordPress tool does, and an intake
 * list with one typo'd id should not lose the other twenty-nine enrolments.
 *
 * Behaviour carried over deliberately:
 * - A full activity does not fail the row; a confirmed enrolment silently
 *   becomes `waitlist` unless `skip_capacity_check` is set.
 * - An unknown `enrollment_type` or `payment_status` falls back to the default
 *   rather than erroring.
 * - `payment_status` is stored as `n/a` for a free activity, whatever was sent.
 * - A duplicate id inside the same payload is "Already enrolled." on the
 *   second occurrence, because the batch map is updated as it goes.
 * - `_eca_current_enrollment` is written unconditionally at the end, even when
 *   no confirmed enrolment was added.
 *
 * Divergences, all deliberate:
 * - **No year-group eligibility check.** WordPress checks eligibility in
 *   `enroll_student_eca` but not here. Adding it would reject rows WordPress
 *   accepts, which is exactly what a parity port must not do. Recorded as a
 *   WordPress defect for a follow-up PR.
 * - **Per-row edit permission.** `update()` throws `AccessDeniedException` when
 *   the actor cannot edit a record, so the check is explicit and per row rather
 *   than the whole call failing on one unreadable student.
 * - **A failed student write does not claim the row.** WordPress adds to its
 *   in-memory activity map before writing the student side, so a failed write
 *   still leaves an activity entry. Here the activity map is only updated after
 *   the student write succeeds.
 *
 * **Not atomic.** `ContentStoreInterface` has no transaction seam. The activity
 * side is written last, and a failed activity write rolls every student side
 * back best-effort, so the authoritative list is never ahead of the mirror.
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
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Tool\AbstractTool;

class BulkEnrollStudentsTool extends AbstractTool {

	private const TYPES = array( 'confirmed', 'waitlist', 'preference', 'trial' );

	private const PAYMENT_STATUSES = array( 'pending', 'paid', 'partial', 'waived' );

	/**
	 * Row ceiling for the single batch read. Above it the tool falls back to
	 * per-id reads, so the query cannot truncate and then report real students
	 * as "Invalid student ID".
	 */
	private const BATCH_READ_LIMIT = 200;

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'bulk_enroll_students';
	}

	public function getName(): string {
		return 'Bulk Enroll Students';
	}

	public function getDescription(): string {
		return 'Batch enrollment of multiple students in an ECA. Reports a per-student result, so one invalid row does not abort the rest of the batch.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'eca_id'              => array(
					'type'        => 'integer',
					'description' => 'ECA record ID (required)',
					'minimum'     => 1,
				),
				'students'            => array(
					'type'        => 'array',
					'description' => 'Student enrollment entries (required)',
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'student_id'      => array(
								'type'        => 'integer',
								'description' => 'Student record ID',
								'minimum'     => 1,
							),
							'enrollment_type' => array(
								'type'        => 'string',
								'description' => 'Type of enrollment',
								'enum'        => self::TYPES,
								'default'     => 'confirmed',
							),
							'payment_status'  => array(
								'type'        => 'string',
								'description' => 'Payment status (for paid ECAs)',
								'enum'        => self::PAYMENT_STATUSES,
								'default'     => 'pending',
							),
						),
						'required'             => array( 'student_id' ),
						'additionalProperties' => false,
					),
				),
				'skip_capacity_check' => array(
					'type'        => 'boolean',
					'description' => 'Skip capacity check (admin override)',
					'default'     => false,
				),
			),
			'required'             => array( 'eca_id', 'students' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$this->authorize( $context, 'edit_posts' );

		$user_id = (int) ( $context['user_id'] ?? 0 );

		$eca_id         = $this->intParam( $arguments, 'eca_id' );
		$students_input = $this->arrayParam( $arguments, 'students' );
		$skip           = $this->boolParam( $arguments, 'skip_capacity_check' );

		if ( $eca_id <= 0 ) {
			return $this->errors->validationFailed(
				'eca_id is required.',
				array( 'eca_id' => array( 'This field is required.' ) )
			);
		}

		if ( array() === $students_input ) {
			return $this->errors->validationFailed(
				'students is required and must not be empty.',
				array( 'students' => array( 'This field is required.' ) )
			);
		}

		$eca = $this->content->find( $eca_id );

		if ( ! $eca instanceof ContentItem || 'mcp_ai_eca' !== $eca->type ) {
			return $this->errors->create( 'invalid_eca', 'Invalid ECA ID.' );
		}

		// One activity write serves the whole batch, so an uneditable activity
		// is a call-level error raised before anything is written.
		if ( ! $this->content->userCanAccess( $eca_id, $user_id, 'edit' ) ) {
			return $this->errors->forbidden( 'You do not have permission to edit this content.' );
		}

		$rows = $this->normaliseRows( $students_input );

		$ids = array();

		foreach ( $rows as $row ) {
			if ( $row['student_id'] > 0 ) {
				$ids[ $row['student_id'] ] = true;
			}
		}

		$students = $this->loadStudents( array_keys( $ids ) );

		$eca_enrollments    = Enrollments::map( $eca->meta['_eca_student_enrollments'] ?? array() );
		$max_students       = $this->intMeta( $eca, '_eca_max_students' );
		$current_enrollment = $this->intMeta( $eca, '_eca_current_enrollment' );
		$is_paid            = $this->isYes( $eca, '_eca_is_paid' );
		$cost               = $is_paid ? $this->floatMeta( $eca, '_eca_cost' ) : 0.0;

		$results          = array();
		$written          = array();
		$enrolled_count   = 0;
		$waitlisted_count = 0;
		$failed_count     = 0;

		$enrollment_date = date( 'Y-m-d H:i:s' );

		foreach ( $rows as $row ) {
			$student_id      = $row['student_id'];
			$enrollment_type = $row['enrollment_type'];
			$payment_status  = $row['payment_status'];

			if ( $student_id <= 0 ) {
				$results[] = array(
					'student_id' => 0,
					'status'     => 'failed',
					'reason'     => 'Missing student_id.',
				);
				++$failed_count;
				continue;
			}

			$student = $students[ $student_id ] ?? null;

			if ( ! $student instanceof ContentItem ) {
				$results[] = array(
					'student_id' => $student_id,
					'status'     => 'failed',
					'reason'     => 'Invalid student ID.',
				);
				++$failed_count;
				continue;
			}

			// The activity map is updated as the batch runs, so a duplicate id
			// inside the same payload fails here on its second occurrence.
			if ( array_key_exists( $student_id, $eca_enrollments ) ) {
				$results[] = array(
					'student_id'   => $student_id,
					'student_name' => $student->title,
					'status'       => 'failed',
					'reason'       => 'Already enrolled.',
				);
				++$failed_count;
				continue;
			}

			// `update()` would throw; catching it per row keeps the batch alive.
			if ( ! $this->content->userCanAccess( $student_id, $user_id, 'edit' ) ) {
				$results[] = array(
					'student_id'   => $student_id,
					'student_name' => $student->title,
					'status'       => 'failed',
					'reason'       => 'You do not have permission to edit this student.',
				);
				++$failed_count;
				continue;
			}

			if ( 'confirmed' === $enrollment_type && ! $skip && $max_students > 0 && $current_enrollment >= $max_students ) {
				$enrollment_type = 'waitlist';
			}

			$record = array(
				'student_id'      => $student_id,
				'eca_id'          => $eca_id,
				'enrollment_type' => $enrollment_type,
				'enrollment_date' => $enrollment_date,
				'payment_status'  => $is_paid ? $payment_status : 'n/a',
				'amount_due'      => $cost,
				'notes'           => '',
				'enrolled_by'     => $user_id,
			);

			$original = Enrollments::map( $student->meta['_student_eca_enrollments'] ?? array() );
			$student_enrollments            = $original;
			$student_enrollments[ $eca_id ] = $record;

			try {
				$this->content->update(
					$student_id,
					new UpdateContentCommand(
						userId: $user_id,
						meta: array( '_student_eca_enrollments' => $student_enrollments ),
					)
				);
			} catch ( \Throwable $e ) {
				$results[] = array(
					'student_id'   => $student_id,
					'student_name' => $student->title,
					'status'       => 'failed',
					'reason'       => $e->getMessage(),
				);
				++$failed_count;
				continue;
			}

			$written[ $student_id ] = $original;

			$eca_enrollments[ $student_id ] = $record;

			if ( 'confirmed' === $enrollment_type ) {
				++$current_enrollment;
				++$enrolled_count;
				$results[] = array(
					'student_id'   => $student_id,
					'student_name' => $student->title,
					'status'       => 'enrolled',
				);
			} else {
				++$waitlisted_count;
				$results[] = array(
					'student_id'   => $student_id,
					'student_name' => $student->title,
					'status'       => 'waitlisted',
				);
			}
		}

		$eca_meta = array(
			'_eca_student_enrollments' => $eca_enrollments,
			'_eca_current_enrollment'  => $current_enrollment,
		);

		if ( $max_students > 0 && $current_enrollment >= $max_students ) {
			$eca_meta['_eca_status'] = 'full';
		}

		try {
			$this->content->update(
				$eca_id,
				new UpdateContentCommand( userId: $user_id, meta: $eca_meta )
			);
		} catch ( \Throwable $e ) {
			$this->rollbackStudents( $written, $user_id );

			return $e instanceof AccessDeniedException
				? $this->errors->forbidden( $e->getMessage() )
				: $this->errors->create( 'bulk_enrollment_failed', $e->getMessage() );
		}

		return $this->success(
			sprintf(
				'Processed %1$d students for %2$s: %3$d enrolled, %4$d waitlisted, %5$d failed.',
				count( $results ),
				$eca->title,
				$enrolled_count,
				$waitlisted_count,
				$failed_count
			),
			array(
				'eca_id'           => $eca_id,
				'eca_name'         => $eca->title,
				'total_processed'  => count( $results ),
				'enrolled_count'   => $enrolled_count,
				'waitlisted_count' => $waitlisted_count,
				'failed_count'     => $failed_count,
				'results'          => $results,
			)
		);
	}

	/**
	 * Coerce the caller's payload into a fixed row shape.
	 *
	 * Validation is per row on purpose: WordPress reports a malformed entry as
	 * a failed row rather than rejecting the request.
	 *
	 * @param array<mixed, mixed> $input Raw `students` argument.
	 * @return array<int, array{student_id: int, enrollment_type: string, payment_status: string}>
	 */
	private function normaliseRows( array $input ): array {
		$rows = array();

		foreach ( $input as $entry ) {
			$entry = is_array( $entry ) ? $entry : array();

			$rows[] = array(
				'student_id'      => isset( $entry['student_id'] ) && is_numeric( $entry['student_id'] ) ? (int) $entry['student_id'] : 0,
				'enrollment_type' => $this->enumOr( $entry['enrollment_type'] ?? 'confirmed', self::TYPES, 'confirmed' ),
				'payment_status'  => $this->enumOr( $entry['payment_status'] ?? 'pending', self::PAYMENT_STATUSES, 'pending' ),
			);
		}

		return $rows;
	}

	/**
	 * Load every referenced student in one read where the contract allows it.
	 *
	 * `statuses: array()` is "no status filter", matching `find()`, so a draft
	 * student resolves here exactly as it does in the single-enrol tool.
	 *
	 * @param array<int, int> $ids Non-zero student ids.
	 * @return array<int, ContentItem> Keyed by id.
	 */
	private function loadStudents( array $ids ): array {
		$map = array();

		if ( array() === $ids ) {
			return $map;
		}

		if ( count( $ids ) <= self::BATCH_READ_LIMIT ) {
			$collection = $this->content->query(
				new ContentQuery(
					types: array( 'mcp_ai_student' ),
					statuses: array(),
					include: $ids,
					perPage: count( $ids ),
				)
			);

			foreach ( $collection->items as $item ) {
				$map[ $item->id ] = $item;
			}

			return $map;
		}

		foreach ( $ids as $id ) {
			$item = $this->content->find( $id );

			if ( $item instanceof ContentItem && 'mcp_ai_student' === $item->type ) {
				$map[ $id ] = $item;
			}
		}

		return $map;
	}

	/**
	 * Restore the student sides the batch had already written.
	 *
	 * Best effort: if a restore fails, the activity side is still untouched, so
	 * no enrolment is reported that the store does not hold.
	 *
	 * @param array<int, array<int, array<string, mixed>>> $written Original maps, keyed by student id.
	 */
	private function rollbackStudents( array $written, int $user_id ): void {
		foreach ( $written as $student_id => $original ) {
			try {
				$this->content->update(
					$student_id,
					new UpdateContentCommand(
						userId: $user_id,
						meta: array( '_student_eca_enrollments' => $original ),
					)
				);
			} catch ( \Throwable $e ) {
				// Nothing further can be done; the caller already has an error.
				unset( $e );
			}
		}
	}

	/**
	 * Match a value against a fixed set, case-insensitively, with a fallback.
	 *
	 * `sanitize_key()` in WordPress lowercases and strips punctuation; the
	 * realistic inputs are already shaped like the enum members.
	 *
	 * @param mixed               $value   Raw argument value.
	 * @param array<int, string>  $allowed Permitted values.
	 * @param string              $default Fallback when nothing matches.
	 */
	private function enumOr( mixed $value, array $allowed, string $default ): string {
		$normalised = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';

		return in_array( $normalised, $allowed, true ) ? $normalised : $default;
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
