<?php
/**
 * Withdraw Student from ECA tool — the counterpart to `enroll_student_eca`.
 *
 * Mirrors `WP_MCP_AI_Tool_Withdraw_Student_ECA` (addons/pro/includes/tools/
 * eca-management): same slug, parameters, result fields, change-history record
 * and waitlist auto-promotion.
 *
 * Behaviour carried over deliberately:
 * - The **activity side is authoritative**: "not enrolled" is decided from
 *   `_eca_student_enrollments`, not the student's reverse link.
 * - The count only moves for a `confirmed` enrolment, and only a `full`
 *   activity is returned to `active` on the way down.
 * - Withdrawing a confirmed student **auto-promotes the longest-waiting**
 *   waitlisted student (`enrollment_date` ascending), which bumps the count
 *   back up.
 * - A missing reverse link is skipped silently rather than erroring.
 *
 * Two differences, both recorded in 090-d:
 * - A promotion that brings the activity to capacity sets `_eca_status` to
 *   `full`. WordPress promotes and increments the count without re-checking
 *   capacity, so a full activity can be left reading `active`.
 * - `effective_date` is validated with `checkdate()` when supplied, so an
 *   impossible date is not written into the change history.
 *
 * Write ordering differs from WordPress on purpose: the student-side removals
 * and promotion are written first and the activity side last, in one write,
 * so the authoritative record is never ahead of the derived ones. A failure
 * before the final write leaves the activity unchanged, and re-running the
 * call converges.
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

class WithdrawStudentEcaTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'withdraw_student_eca';
	}

	public function getName(): string {
		return 'Withdraw Student from ECA';
	}

	public function getDescription(): string {
		return 'Withdraws a student from an Extra-Curricular Activity. Updates enrollment counts, promotes the next waitlisted student, and records the withdrawal in the change history.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'student_id'     => array(
					'type'        => 'integer',
					'description' => 'Student record ID (required)',
					'minimum'     => 1,
				),
				'eca_id'         => array(
					'type'        => 'integer',
					'description' => 'ECA record ID (required)',
					'minimum'     => 1,
				),
				'reason'         => array(
					'type'        => 'string',
					'description' => 'Reason for withdrawal',
					'maxLength'   => 500,
				),
				'effective_date' => array(
					'type'        => 'string',
					'description' => 'Effective date of withdrawal in YYYY-MM-DD format (defaults to today)',
				),
				'process_refund' => array(
					'type'        => 'boolean',
					'description' => 'Whether to flag the enrollment for refund processing',
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

		$eca_enrollments = Enrollments::map( $eca->meta['_eca_student_enrollments'] ?? array() );

		if ( ! array_key_exists( $student_id, $eca_enrollments ) ) {
			return $this->errors->create( 'not_enrolled', 'Student is not enrolled in this ECA.' );
		}

		$record        = $eca_enrollments[ $student_id ];
		$was_confirmed = isset( $record['enrollment_type'] ) && 'confirmed' === $record['enrollment_type'];

		$reason         = $this->stringParam( $arguments, 'reason' );
		$process_refund = $this->boolParam( $arguments, 'process_refund' );
		$effective_date = $this->stringParam( $arguments, 'effective_date', date( 'Y-m-d' ) );

		if ( '' === $effective_date ) {
			$effective_date = date( 'Y-m-d' );
		} elseif ( ! $this->isIsoDate( $effective_date ) ) {
			return $this->errors->create(
				'invalid_date',
				'effective_date must be a real date in YYYY-MM-DD format.'
			);
		}

		// Pre-flight both records: ContentStoreInterface has no transaction
		// seam, so a permission split is caught before anything is written.
		foreach ( array( $student_id, $eca_id ) as $target_id ) {
			if ( ! $this->content->userCanAccess( $target_id, $user_id, 'edit' ) ) {
				return $this->errors->forbidden( 'You do not have permission to edit this content.' );
			}
		}

		// ── Work out the final activity state in memory ──────────────────

		unset( $eca_enrollments[ $student_id ] );

		$max_students       = $this->intMeta( $eca, '_eca_max_students' );
		$current_enrollment = $this->intMeta( $eca, '_eca_current_enrollment' );
		$status             = $this->textMeta( $eca, '_eca_status' );

		$promoted     = null;
		$student_writes = array();

		if ( $was_confirmed ) {
			$current_enrollment = max( 0, $current_enrollment - 1 );

			if ( 'full' === $status && $max_students > 0 && $current_enrollment < $max_students ) {
				$status = 'active';
			}

			$promoted = $this->promoteNext( $eca_enrollments );

			if ( null !== $promoted ) {
				$eca_enrollments[ $promoted['id'] ] = $promoted['record'];

				++$current_enrollment;

				// WordPress forgets this check, which can leave a full
				// activity reading `active` after a promotion fills it.
				if ( $max_students > 0 && $current_enrollment >= $max_students && 'active' === $status ) {
					$status = 'full';
				}

				$student_writes[] = $promoted['id'];
			}
		}

		$history = $this->appendHistory(
			$eca,
			array(
				'action'         => 'withdrawal',
				'student_id'     => $student_id,
				'student_name'   => $student->title,
				'reason'         => $reason,
				'effective_date' => $effective_date,
				'process_refund' => $process_refund,
				'performed_by'   => $user_id,
				'timestamp'      => date( 'Y-m-d H:i:s' ),
			)
		);

		// ── Write student sides first, activity side last ───────────────

		$student_enrollments = Enrollments::map( $student->meta['_student_eca_enrollments'] ?? array() );

		if ( array_key_exists( $eca_id, $student_enrollments ) ) {
			unset( $student_enrollments[ $eca_id ] );

			try {
				$this->content->update(
					$student_id,
					new UpdateContentCommand(
						userId: $user_id,
						meta: array( '_student_eca_enrollments' => $student_enrollments ),
					)
				);
			} catch ( \Throwable $e ) {
				return $this->writeError( $e );
			}
		}

		foreach ( $student_writes as $promoted_id ) {
			$promoted_student = $this->content->find( $promoted_id );

			if ( ! $promoted_student instanceof ContentItem ) {
				continue;
			}

			$map = Enrollments::map( $promoted_student->meta['_student_eca_enrollments'] ?? array() );

			if ( ! array_key_exists( $eca_id, $map ) ) {
				continue;
			}

			$map[ $eca_id ]['enrollment_type'] = 'confirmed';

			try {
				$this->content->update(
					$promoted_id,
					new UpdateContentCommand(
						userId: $user_id,
						meta: array( '_student_eca_enrollments' => $map ),
					)
				);
			} catch ( \Throwable $e ) {
				// The activity side is not written yet, so the withdrawal has
				// not happened. Report it and leave the state recoverable.
				return $this->writeError( $e );
			}
		}

		$eca_meta = array(
			'_eca_student_enrollments' => $eca_enrollments,
			'_eca_change_history'      => $history,
		);

		if ( $was_confirmed ) {
			$eca_meta['_eca_current_enrollment'] = $current_enrollment;

			if ( $status !== $this->textMeta( $eca, '_eca_status' ) ) {
				$eca_meta['_eca_status'] = $status;
			}
		}

		try {
			$this->content->update(
				$eca_id,
				new UpdateContentCommand( userId: $user_id, meta: $eca_meta )
			);
		} catch ( \Throwable $e ) {
			return $this->writeError( $e );
		}

		$data = array(
			'student_id'     => $student_id,
			'student_name'   => $student->title,
			'eca_id'         => $eca_id,
			'eca_name'       => $eca->title,
			'reason'         => $reason,
			'effective_date' => $effective_date,
			'process_refund' => $process_refund,
		);

		if ( null !== $promoted ) {
			$data['waitlist_promoted'] = $promoted['result'];
		}

		return $this->success(
			sprintf( '%1$s has been withdrawn from %2$s.', $student->title, $eca->title ),
			$data
		);
	}

	/**
	 * Pick the longest-waiting waitlisted student from a map.
	 *
	 * WordPress sorts by `enrollment_date` ascending; ties keep insertion
	 * order because `uasort` is not stable, so the port sorts by date and
	 * falls back to the map's own order.
	 *
	 * @param array<int, array<string, mixed>> $enrollments Activity-side map.
	 * @return array{id: int, record: array<string, mixed>, result: array<string, mixed>}|null
	 */
	private function promoteNext( array $enrollments ): ?array {
		$candidates = array();

		foreach ( $enrollments as $student_id => $record ) {
			if ( isset( $record['enrollment_type'] ) && 'waitlist' === $record['enrollment_type'] ) {
				$candidates[ $student_id ] = $record;
			}
		}

		if ( array() === $candidates ) {
			return null;
		}

		$order = array_keys( $candidates );

		usort(
			$order,
			static function ( int $a, int $b ) use ( $candidates ): int {
				$date_a = isset( $candidates[ $a ]['enrollment_date'] ) && is_scalar( $candidates[ $a ]['enrollment_date'] )
					? (string) $candidates[ $a ]['enrollment_date']
					: '';
				$date_b = isset( $candidates[ $b ]['enrollment_date'] ) && is_scalar( $candidates[ $b ]['enrollment_date'] )
					? (string) $candidates[ $b ]['enrollment_date']
					: '';

				return strcmp( $date_a, $date_b );
			}
		);

		$promoted_id = (int) $order[0];
		$record      = $candidates[ $promoted_id ];
		$record['enrollment_type'] = 'confirmed';

		$student = $this->content->find( $promoted_id );
		$name    = $student instanceof ContentItem ? $student->title : 'Unknown';

		return array(
			'id'     => $promoted_id,
			'record' => $record,
			'result' => array(
				'student_id'   => $promoted_id,
				'student_name' => $name,
				'message'      => sprintf( '%s has been auto-promoted from the waitlist.', $name ),
			),
		);
	}

	/**
	 * Append a record to `_eca_change_history`.
	 *
	 * The history is a plain list — not one of the id-keyed maps — so it is
	 * appended rather than merged.
	 *
	 * @param array<string, mixed> $entry Change record.
	 * @return array<int, mixed>
	 */
	private function appendHistory( ContentItem $eca, array $entry ): array {
		$history = $eca->meta['_eca_change_history'] ?? array();

		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$history[] = $entry;

		return array_values( $history );
	}

	private function writeError( \Throwable $e ): mixed {
		return $e instanceof AccessDeniedException
			? $this->errors->forbidden( $e->getMessage() )
			: $this->errors->create( 'withdrawal_failed', $e->getMessage() );
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
