<?php
/**
 * Mark ECA Attendance tool — records one session against an activity.
 *
 * Mirrors `WP_MCP_AI_Tool_Mark_ECA_Attendance` (addons/pro/includes/tools/
 * eca-management): same slug, parameters, per-student statuses and counters.
 *
 * Storage is `_eca_attendance_log`, a **date-keyed map** of session records:
 *
 *     [ '2026-11-14' => [ date, recorded_by, recorded_at, session_notes,
 *                         attendees[], present_count, absent_count,
 *                         late_count, excused_count ] ]
 *
 * `attendees` is a plain list inside the record — deliberately different from
 * the enrolment maps, which are id-keyed, so it is not run through
 * {@see Enrollments}.
 *
 * Re-recording an existing date replaces that session, as WordPress does.
 *
 * Divergence: `session_date` is validated with `checkdate()` as well as the
 * `YYYY-MM-DD` shape. WordPress only matches the shape, so it accepts
 * `2026-02-31` and stores a session under a key no calendar ever reaches.
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

class MarkEcaAttendanceTool extends AbstractTool {

	private const STATUSES = array( 'present', 'absent', 'late', 'excused' );

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'mark_eca_attendance';
	}

	public function getName(): string {
		return 'Mark ECA Attendance';
	}

	public function getDescription(): string {
		return 'Records attendance for students in an ECA session. Tracks present, absent, late, and excused statuses with optional notes per student and per session.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'eca_id'        => array(
					'type'        => 'integer',
					'description' => 'ECA record ID (required)',
					'minimum'     => 1,
				),
				'session_date'  => array(
					'type'        => 'string',
					'description' => 'Date of the session in YYYY-MM-DD format (required)',
					'minLength'   => 10,
					'maxLength'   => 10,
				),
				'attendees'     => array(
					'type'        => 'array',
					'description' => 'Attendance record for each student (required)',
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'student_id' => array(
								'type'        => 'integer',
								'description' => 'Student record ID',
								'minimum'     => 1,
							),
							'status'     => array(
								'type'        => 'string',
								'description' => 'Attendance status',
								'enum'        => self::STATUSES,
							),
							'notes'      => array(
								'type'        => 'string',
								'description' => 'Optional notes for this student',
								'maxLength'   => 500,
							),
						),
						'required'             => array( 'student_id', 'status' ),
						'additionalProperties' => false,
					),
				),
				'session_notes' => array(
					'type'        => 'string',
					'description' => 'Optional notes for the overall session',
					'maxLength'   => 1000,
				),
			),
			'required'             => array( 'eca_id', 'session_date', 'attendees' ),
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

		if ( $eca_id <= 0 ) {
			return $this->errors->validationFailed(
				'ECA ID is required.',
				array(
					'eca_id' => array( 'This field is required.' ),
				)
			);
		}

		$eca = $this->content->find( $eca_id );

		if ( ! $eca instanceof ContentItem || 'mcp_ai_eca' !== $eca->type ) {
			return $this->errors->create( 'invalid_eca', 'Invalid ECA ID.' );
		}

		$session_date = $this->stringParam( $arguments, 'session_date' );

		if ( ! $this->isIsoDate( $session_date ) ) {
			return $this->errors->create(
				'invalid_date',
				'A valid session date in YYYY-MM-DD format is required.'
			);
		}

		$attendees = $this->arrayParam( $arguments, 'attendees' );

		if ( array() === $attendees ) {
			return $this->errors->create( 'missing_attendees', 'At least one attendee record is required.' );
		}

		$counts = array(
			'present' => 0,
			'absent'  => 0,
			'late'    => 0,
			'excused' => 0,
		);

		$records = array();

		foreach ( $attendees as $attendee ) {
			if ( ! is_array( $attendee ) ) {
				continue;
			}

			$student_id = isset( $attendee['student_id'] ) && is_numeric( $attendee['student_id'] )
				? (int) $attendee['student_id']
				: 0;

			if ( $student_id <= 0 ) {
				return $this->errors->create( 'invalid_attendee', 'Each attendee must have a valid student_id.' );
			}

			$student = $this->content->find( $student_id );

			if ( ! $student instanceof ContentItem || 'mcp_ai_student' !== $student->type ) {
				return $this->errors->create(
					'invalid_student',
					sprintf( 'Invalid student ID: %d', $student_id )
				);
			}

			$status = isset( $attendee['status'] ) && is_string( $attendee['status'] )
				? strtolower( trim( strip_tags( $attendee['status'] ) ) )
				: '';

			if ( ! in_array( $status, self::STATUSES, true ) ) {
				return $this->errors->create(
					'invalid_status',
					sprintf( 'Invalid attendance status for student ID: %d', $student_id )
				);
			}

			$notes = isset( $attendee['notes'] ) && is_scalar( $attendee['notes'] )
				? trim( strip_tags( (string) $attendee['notes'] ) )
				: '';

			$records[] = array(
				'student_id'   => $student_id,
				'student_name' => $student->title,
				'status'       => $status,
				'notes'        => $notes,
			);

			++$counts[ $status ];
		}

		if ( array() === $records ) {
			return $this->errors->create( 'invalid_attendee', 'Each attendee must have a valid student_id.' );
		}

		$log = $eca->meta['_eca_attendance_log'] ?? array();

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		// Re-recording a date replaces that session, as WordPress does.
		$log[ $session_date ] = array(
			'date'          => $session_date,
			'recorded_by'   => $user_id,
			'recorded_at'   => date( 'Y-m-d H:i:s' ),
			'session_notes' => $this->stringParam( $arguments, 'session_notes' ),
			'attendees'     => $records,
			'present_count' => $counts['present'],
			'absent_count'  => $counts['absent'],
			'late_count'    => $counts['late'],
			'excused_count' => $counts['excused'],
		);

		try {
			$this->content->update(
				$eca_id,
				new UpdateContentCommand(
					userId: $user_id,
					meta: array( '_eca_attendance_log' => $log ),
				)
			);
		} catch ( NotFoundException $e ) {
			return $this->errors->notFound( $e->getMessage() );
		} catch ( AccessDeniedException $e ) {
			return $this->errors->forbidden( $e->getMessage() );
		} catch ( \Throwable $e ) {
			return $this->errors->create( 'attendance_failed', $e->getMessage() );
		}

		return $this->success(
			sprintf( 'Attendance recorded for %1$s on %2$s.', $eca->title, $session_date ),
			array(
				'eca_id'           => $eca_id,
				'eca_name'         => $eca->title,
				'session_date'     => $session_date,
				'attendance_count' => count( $records ),
				'present_count'    => $counts['present'],
				'absent_count'     => $counts['absent'],
				'late_count'       => $counts['late'],
				'excused_count'    => $counts['excused'],
			)
		);
	}
}
