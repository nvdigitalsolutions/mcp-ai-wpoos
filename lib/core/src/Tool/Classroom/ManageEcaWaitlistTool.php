<?php
/**
 * Manage ECA Waitlist tool — list, promote, reorder and remove.
 *
 * Mirrors `WP_MCP_AI_Tool_Manage_ECA_Waitlist` (addons/pro/includes/tools/
 * eca-management): same slug, the same five actions and the same result
 * shapes.
 *
 * The waitlist is **derived, not stored**. There is no position column: an
 * entry is on the waitlist when `_eca_student_enrollments[ <student_id> ]
 * ['enrollment_type'] === 'waitlist'`, and its position comes from sorting the
 * waitlist by `enrollment_date`. So `reorder` is implemented the only way this
 * schema allows — by rewriting every waitlisted entry's `enrollment_date` to
 * `base + order` seconds.
 *
 * Behaviour carried over deliberately:
 * - `reorder` **rewrites the enrollment date of every waitlisted student**, not
 *   just the moved one. That destroys the original sign-up time, which is a
 *   real cost of storing order in the date field. Carried over because a
 *   partial rewrite would leave two entries on the same second and the order
 *   would silently depend on map iteration order.
 * - `promote` does **not** check capacity. Promoting onto a full activity
 *   pushes `_eca_current_enrollment` past `_eca_max_students` and sets the
 *   status to `full`, matching WordPress.
 * - `remove` does **not** touch `_eca_current_enrollment` (a waitlisted student
 *   was never counted) and does **not** reset a `full` status.
 * - Position is clamped to the waitlist length rather than rejected.
 *
 * Divergences, all deliberate:
 * - **Student sides are written before the activity side.** WordPress writes
 *   the activity map first in `promote`; this family's established order (see
 *   `WithdrawStudentEcaTool`) is mirrors first so the authoritative record is
 *   never ahead of the records derived from it. A failed activity write rolls
 *   the student sides back best-effort.
 * - **`reorder` pre-flights every waitlisted student.** WordPress checks only
 *   `edit_posts`; `update()` here is per-record, so a permission split halfway
 *   through would leave the waitlist partially re-dated. The check runs before
 *   the first write.
 * - **`checkdate()`-style date validation is not needed** — `reorder` computes
 *   its own dates, it never accepts them.
 *
 * Not carried over: the `wp_mcp_ai_eca_waitlist_promoted` action. Core has no
 * hook seam; a runtime that needs the event can observe the store instead.
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
use Nvoos\Core\Tool\AbstractTool;

class ManageEcaWaitlistTool extends AbstractTool {

	private const ACTIONS = array( 'list', 'promote', 'promote_next', 'reorder', 'remove' );

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'manage_eca_waitlist';
	}

	public function getName(): string {
		return 'Manage ECA Waitlist';
	}

	public function getDescription(): string {
		return 'Lists, promotes, reorders and removes students on an ECA waitlist. Positions derive from enrollment date, so reordering rewrites those dates.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'eca_id'     => array(
					'type'        => 'integer',
					'description' => 'ECA record ID (required)',
					'minimum'     => 1,
				),
				'action'     => array(
					'type'        => 'string',
					'description' => 'Waitlist action to perform (required)',
					'enum'        => self::ACTIONS,
				),
				'student_id' => array(
					'type'        => 'integer',
					'description' => 'Student record ID (required for promote, reorder and remove)',
					'minimum'     => 1,
				),
				'position'   => array(
					'type'        => 'integer',
					'description' => 'New 1-based waitlist position (used with the reorder action)',
					'minimum'     => 1,
				),
			),
			'required'             => array( 'eca_id', 'action' ),
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
		$action = strtolower( trim( $this->stringParam( $arguments, 'action' ) ) );

		if ( $eca_id <= 0 || '' === $action ) {
			return $this->errors->validationFailed(
				'Both eca_id and action are required.',
				array(
					'eca_id' => array( 'This field is required.' ),
					'action' => array( 'This field is required.' ),
				)
			);
		}

		$eca = $this->content->find( $eca_id );

		if ( ! $eca instanceof ContentItem || 'mcp_ai_eca' !== $eca->type ) {
			return $this->errors->create( 'invalid_eca', 'Invalid ECA ID.' );
		}

		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return $this->errors->create( 'invalid_action', 'Invalid waitlist action.' );
		}

		$enrollments = Enrollments::map( $eca->meta['_eca_student_enrollments'] ?? array() );
		$waitlist    = $this->waitlist( $enrollments );

		switch ( $action ) {
			case 'list':
				return $this->actionList( $eca, $waitlist );

			case 'promote':
				$student_id = $this->intParam( $arguments, 'student_id' );

				if ( $student_id <= 0 ) {
					return $this->errors->validationFailed(
						'student_id is required for the promote action.',
						array( 'student_id' => array( 'This field is required.' ) )
					);
				}

				return $this->actionPromote( $eca, $student_id, $enrollments, $waitlist, $user_id );

			case 'promote_next':
				return $this->actionPromoteNext( $eca, $enrollments, $waitlist, $user_id );

			case 'reorder':
				$student_id = $this->intParam( $arguments, 'student_id' );
				$position   = $this->intParam( $arguments, 'position' );

				if ( $student_id <= 0 || $position <= 0 ) {
					return $this->errors->validationFailed(
						'student_id and position are required for the reorder action.',
						array(
							'student_id' => array( 'This field is required.' ),
							'position'   => array( 'This field is required.' ),
						)
					);
				}

				return $this->actionReorder( $eca, $student_id, $position, $enrollments, $waitlist, $user_id );

			case 'remove':
				$student_id = $this->intParam( $arguments, 'student_id' );

				if ( $student_id <= 0 ) {
					return $this->errors->validationFailed(
						'student_id is required for the remove action.',
						array( 'student_id' => array( 'This field is required.' ) )
					);
				}

				return $this->actionRemove( $eca, $student_id, $enrollments, $waitlist, $user_id );
		}

		// Unreachable: the enum check above rejects anything else.
		return $this->errors->create( 'invalid_action', 'Invalid waitlist action.' );
	}

	/**
	 * Waitlisted records, ordered by enrollment date.
	 *
	 * `uasort` is stable on PHP 8, so entries sharing a timestamp keep map
	 * insertion order — the same tie-break WordPress gets.
	 *
	 * @param array<int, array<string, mixed>> $enrollments Activity-side map.
	 * @return array<int, array<string, mixed>>
	 */
	private function waitlist( array $enrollments ): array {
		$waitlist = array();

		foreach ( $enrollments as $student_id => $record ) {
			if ( isset( $record['enrollment_type'] ) && 'waitlist' === $record['enrollment_type'] ) {
				$waitlist[ $student_id ] = $record;
			}
		}

		uasort(
			$waitlist,
			static function ( $a, $b ) {
				$date_a = isset( $a['enrollment_date'] ) ? (string) $a['enrollment_date'] : '';
				$date_b = isset( $b['enrollment_date'] ) ? (string) $b['enrollment_date'] : '';

				return strcmp( $date_a, $date_b );
			}
		);

		return $waitlist;
	}

	/**
	 * @param array<int, array<string, mixed>> $waitlist Ordered waitlist.
	 */
	private function actionList( ContentItem $eca, array $waitlist ): array {
		$list     = array();
		$position = 1;

		foreach ( $waitlist as $student_id => $record ) {
			$list[] = array(
				'position'        => $position,
				'student_id'      => (int) $student_id,
				'student_name'    => $this->studentName( (int) $student_id ),
				'enrollment_date' => isset( $record['enrollment_date'] ) ? (string) $record['enrollment_date'] : '',
				'notes'           => isset( $record['notes'] ) ? (string) $record['notes'] : '',
			);
			++$position;
		}

		return $this->success(
			empty( $list )
				? sprintf( 'The waitlist for %s is empty.', $eca->title )
				: sprintf( '%1$d students are on the waitlist for %2$s.', count( $list ), $eca->title ),
			array(
				'eca_id'   => $eca->id,
				'eca_name' => $eca->title,
				'total'    => count( $list ),
				'waitlist' => $list,
			)
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $enrollments Activity-side map.
	 * @param array<int, array<string, mixed>> $waitlist    Ordered waitlist.
	 */
	private function actionPromote( ContentItem $eca, int $student_id, array $enrollments, array $waitlist, int $user_id ): mixed {
		if ( ! array_key_exists( $student_id, $waitlist ) ) {
			return $this->errors->create( 'not_on_waitlist', 'Student is not on the waitlist for this ECA.' );
		}

		return $this->promote( $eca, $student_id, $enrollments, $user_id );
	}

	/**
	 * @param array<int, array<string, mixed>> $enrollments Activity-side map.
	 * @param array<int, array<string, mixed>> $waitlist    Ordered waitlist.
	 */
	private function actionPromoteNext( ContentItem $eca, array $enrollments, array $waitlist, int $user_id ): mixed {
		if ( array() === $waitlist ) {
			return $this->errors->create( 'empty_waitlist', 'The waitlist is empty.' );
		}

		$student_id = (int) array_key_first( $waitlist );

		return $this->promote( $eca, $student_id, $enrollments, $user_id );
	}

	/**
	 * @param array<int, array<string, mixed>> $enrollments Activity-side map.
	 * @param array<int, array<string, mixed>> $waitlist    Ordered waitlist.
	 */
	private function actionReorder( ContentItem $eca, int $student_id, int $position, array $enrollments, array $waitlist, int $user_id ): mixed {
		if ( ! array_key_exists( $student_id, $waitlist ) ) {
			return $this->errors->create( 'not_on_waitlist', 'Student is not on the waitlist for this ECA.' );
		}

		$position = min( $position, count( $waitlist ) );

		// Pre-flight every record this action touches. A half-reordered
		// waitlist would have duplicated dates, and the order would then
		// depend on map iteration rather than on intent.
		$ordered = array_map( 'intval', array_keys( $waitlist ) );

		foreach ( array_merge( array( $eca->id ), $ordered ) as $target_id ) {
			if ( ! $this->content->userCanAccess( $target_id, $user_id, 'edit' ) ) {
				return $this->errors->forbidden( 'You do not have permission to edit this content.' );
			}
		}

		$reordered = array_values( array_filter( $ordered, static fn ( int $id ): bool => $id !== $student_id ) );
		array_splice( $reordered, $position - 1, 0, array( $student_id ) );

		$base  = time();
		$dates = array();
		$order = 0;

		foreach ( $reordered as $id ) {
			$dates[ $id ] = date( 'Y-m-d H:i:s', $base + $order );
			++$order;
		}

		$written = array();

		foreach ( $reordered as $id ) {
			$student = $this->content->find( $id );

			if ( ! $student instanceof ContentItem ) {
				continue;
			}

			$original = Enrollments::map( $student->meta['_student_eca_enrollments'] ?? array() );

			if ( ! array_key_exists( $eca->id, $original ) ) {
				continue;
			}

			$map                               = $original;
			$map[ $eca->id ]['enrollment_date'] = $dates[ $id ];

			try {
				$this->content->update(
					$id,
					new UpdateContentCommand(
						userId: $user_id,
						meta: array( '_student_eca_enrollments' => $map ),
					)
				);
			} catch ( \Throwable $e ) {
				$this->rollbackStudents( $written, $user_id );

				return $this->errors->create( 'waitlist_failed', $e->getMessage() );
			}

			$written[ $id ] = $original;
		}

		foreach ( $reordered as $id ) {
			$enrollments[ $id ]['enrollment_date'] = $dates[ $id ];
		}

		try {
			$this->content->update(
				$eca->id,
				new UpdateContentCommand(
					userId: $user_id,
					meta: array( '_eca_student_enrollments' => $enrollments ),
				)
			);
		} catch ( \Throwable $e ) {
			$this->rollbackStudents( $written, $user_id );

			return $this->errors->create( 'waitlist_failed', $e->getMessage() );
		}

		$name = $this->studentName( $student_id );

		return $this->success(
			sprintf( '%1$s moved to waitlist position %2$d.', $name, $position ),
			array(
				'eca_id'       => $eca->id,
				'eca_name'     => $eca->title,
				'student_id'   => $student_id,
				'student_name' => $name,
				'new_position' => $position,
			)
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $enrollments Activity-side map.
	 * @param array<int, array<string, mixed>> $waitlist    Ordered waitlist.
	 */
	private function actionRemove( ContentItem $eca, int $student_id, array $enrollments, array $waitlist, int $user_id ): mixed {
		if ( ! array_key_exists( $student_id, $waitlist ) ) {
			return $this->errors->create( 'not_on_waitlist', 'Student is not on the waitlist for this ECA.' );
		}

		foreach ( array( $eca->id, $student_id ) as $target_id ) {
			if ( ! $this->content->userCanAccess( $target_id, $user_id, 'edit' ) ) {
				return $this->errors->forbidden( 'You do not have permission to edit this content.' );
			}
		}

		$student     = $this->content->find( $student_id );
		$student_map = null;

		if ( $student instanceof ContentItem ) {
			$map = Enrollments::map( $student->meta['_student_eca_enrollments'] ?? array() );

			if ( array_key_exists( $eca->id, $map ) ) {
				$student_map = $map;
				unset( $map[ $eca->id ] );

				try {
					$this->content->update(
						$student_id,
						new UpdateContentCommand(
							userId: $user_id,
							meta: array( '_student_eca_enrollments' => $map ),
						)
					);
				} catch ( \Throwable $e ) {
					return $this->errors->create( 'waitlist_failed', $e->getMessage() );
				}
			}
		}

		unset( $enrollments[ $student_id ] );

		try {
			$this->content->update(
				$eca->id,
				new UpdateContentCommand(
					userId: $user_id,
					meta: array( '_eca_student_enrollments' => $enrollments ),
				)
			);
		} catch ( \Throwable $e ) {
			if ( null !== $student_map ) {
				$this->rollbackStudents( array( $student_id => $student_map ), $user_id );
			}

			return $this->errors->create( 'waitlist_failed', $e->getMessage() );
		}

		$name = $this->studentName( $student_id );

		return $this->success(
			sprintf( '%1$s has been removed from the waitlist for %2$s.', $name, $eca->title ),
			array(
				'eca_id'       => $eca->id,
				'eca_name'     => $eca->title,
				'student_id'   => $student_id,
				'student_name' => $name,
			)
		);
	}

	/**
	 * Move one waitlisted entry to confirmed and count it.
	 *
	 * @param array<int, array<string, mixed>> $enrollments Activity-side map.
	 */
	private function promote( ContentItem $eca, int $student_id, array $enrollments, int $user_id ): mixed {
		foreach ( array( $eca->id, $student_id ) as $target_id ) {
			if ( ! $this->content->userCanAccess( $target_id, $user_id, 'edit' ) ) {
				return $this->errors->forbidden( 'You do not have permission to edit this content.' );
			}
		}

		$student     = $this->content->find( $student_id );
		$previous    = null;

		if ( $student instanceof ContentItem ) {
			$map = Enrollments::map( $student->meta['_student_eca_enrollments'] ?? array() );

			if ( array_key_exists( $eca->id, $map ) ) {
				$previous                           = $map;
				$map[ $eca->id ]['enrollment_type'] = 'confirmed';

				try {
					$this->content->update(
						$student_id,
						new UpdateContentCommand(
							userId: $user_id,
							meta: array( '_student_eca_enrollments' => $map ),
						)
					);
				} catch ( \Throwable $e ) {
					return $this->errors->create( 'waitlist_failed', $e->getMessage() );
				}
			}
		}

		$enrollments[ $student_id ]['enrollment_type'] = 'confirmed';

		$current = $this->intMeta( $eca, '_eca_current_enrollment' );
		$max     = $this->intMeta( $eca, '_eca_max_students' );
		$next    = $current + 1;

		$meta = array(
			'_eca_student_enrollments' => $enrollments,
			'_eca_current_enrollment'  => $next,
		);

		if ( $max > 0 && $next >= $max ) {
			$meta['_eca_status'] = 'full';
		}

		try {
			$this->content->update(
				$eca->id,
				new UpdateContentCommand( userId: $user_id, meta: $meta )
			);
		} catch ( \Throwable $e ) {
			if ( null !== $previous ) {
				$this->rollbackStudents( array( $student_id => $previous ), $user_id );
			}

			return $this->errors->create( 'waitlist_failed', $e->getMessage() );
		}

		$name = $student instanceof ContentItem ? $student->title : $this->studentName( $student_id );

		return $this->success(
			sprintf( '%1$s has been promoted from waitlist to confirmed for %2$s.', $name, $eca->title ),
			array(
				'eca_id'       => $eca->id,
				'eca_name'     => $eca->title,
				'student_id'   => $student_id,
				'student_name' => $name,
			)
		);
	}

	/**
	 * Restore student sides an action had already written.
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

	private function studentName( int $student_id ): string {
		$student = $this->content->find( $student_id );

		return $student instanceof ContentItem ? $student->title : 'Unknown';
	}

	private function intMeta( ContentItem $item, string $key ): int {
		$value = $item->meta[ $key ] ?? 0;

		return is_numeric( $value ) ? (int) $value : 0;
	}
}
