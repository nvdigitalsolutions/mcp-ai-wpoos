<?php
/**
 * Get Student tool — one `mcp_ai_student` row plus its enrolled activities.
 *
 * Mirrors `WP_MCP_AI_Tool_Get_Student` (addons/pro/includes/tools/
 * eca-management): same slug, same `student_id` / `include_schedule`
 * parameters, same field order, same weekly-schedule grouping and day order.
 *
 * The row is returned flat inside the canonical `data` payload, because the
 * WordPress tool returns it flat at the top level — unlike `get_eca`, which
 * nests under `eca`.
 *
 * Divergences (090-d §5):
 * - `url` is always empty: permalinks are a WordPress concept.
 * - `include_schedule` defaults to **true**, as it does on WordPress.
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
use Nvoos\Core\Tool\AbstractTool;

class GetStudentTool extends AbstractTool {

	/**
	 * Schedule grouping order, as WordPress renders it.
	 */
	private const DAY_ORDER = array(
		'Monday',
		'Tuesday',
		'Wednesday',
		'Thursday',
		'Friday',
		'Saturday',
		'Sunday',
	);

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'get_student';
	}

	public function getName(): string {
		return 'Get Student';
	}

	public function getDescription(): string {
		return 'Returns one student\'s details with their enrolled activities and, by default, a weekly schedule grouped by day.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'student_id'       => array(
					'type'        => 'integer',
					'description' => 'Student record ID (required)',
					'minimum'     => 1,
				),
				'include_schedule' => array(
					'type'        => 'boolean',
					'description' => 'Include the weekly ECA schedule (default: true)',
					'default'     => true,
				),
			),
			'required'             => array( 'student_id' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$this->authorize( $context, 'read' );

		$student_id = $this->intParam( $arguments, 'student_id' );

		if ( $student_id <= 0 ) {
			return $this->errors->validationFailed(
				'Student ID is required.',
				array(
					'student_id' => array( 'This field is required.' ),
				)
			);
		}

		$student = $this->content->find( $student_id );

		if ( ! $student instanceof ContentItem || 'mcp_ai_student' !== $student->type ) {
			return $this->errors->create( 'invalid_student', 'Invalid student ID.' );
		}

		$include_schedule = $this->boolParam( $arguments, 'include_schedule', true );

		$meta = $student->meta;

		$enrolled = array();
		$schedule = array();

		foreach ( StudentRow::enrollments( $student ) as $eca_id => $record ) {
			$eca = $this->content->find( (int) $eca_id );

			// WordPress skips ids that no longer resolve; a row of another type
			// is just as unusable, so it is skipped too.
			if ( ! $eca instanceof ContentItem || 'mcp_ai_eca' !== $eca->type ) {
				continue;
			}

			$enrolled[] = array(
				'eca_id'          => $eca->id,
				'name'            => $eca->title,
				'type'            => $this->text( $eca, '_eca_type' ),
				'day'             => $this->text( $eca, '_eca_day' ),
				'start_time'      => $this->text( $eca, '_eca_start_time' ),
				'end_time'        => $this->text( $eca, '_eca_end_time' ),
				'venue'           => $this->text( $eca, '_eca_venue' ),
				'teachers'        => $this->list( $eca, '_eca_teachers' ),
				'enrollment_type' => isset( $record['enrollment_type'] ) && is_string( $record['enrollment_type'] )
					? $record['enrollment_type']
					: 'confirmed',
				'enrollment_date' => isset( $record['enrollment_date'] ) && is_scalar( $record['enrollment_date'] )
					? (string) $record['enrollment_date']
					: '',
				// WordPress reports `n/a` here rather than an empty string.
				'payment_status'  => isset( $record['payment_status'] ) && is_scalar( $record['payment_status'] )
					? (string) $record['payment_status']
					: 'n/a',
			);

			$day = $this->text( $eca, '_eca_day' );

			if ( $include_schedule && '' !== $day ) {
				$schedule[ $day ][] = array(
					'eca_id'     => $eca->id,
					'name'       => $eca->title,
					'start_time' => $this->text( $eca, '_eca_start_time' ),
					'end_time'   => $this->text( $eca, '_eca_end_time' ),
					'venue'      => $this->text( $eca, '_eca_venue' ),
				);
			}
		}

		$data = array(
			'student_id'        => $student->id,
			'name'              => $student->title,
			'first_name'        => $this->text( $student, '_student_first_name' ),
			'last_name'         => $this->text( $student, '_student_last_name' ),
			'year_group'        => $this->text( $student, '_student_year_group' ),
			'house'             => $this->text( $student, '_student_house' ),
			'email'             => $this->text( $student, '_student_email' ),
			'total_enrollments' => count( $enrolled ),
			'enrolled_ecas'     => $enrolled,
			'isams_id'          => $this->text( $student, '_student_isams_id' ),
			'isams_synced'      => 'yes' === strtolower( $this->text( $student, '_student_isams_synced' ) ),
			'url'               => '',
		);

		if ( $include_schedule ) {
			$data['weekly_schedule'] = $this->sortSchedule( $schedule );
		}

		return $this->success(
			sprintf( 'Retrieved student "%s".', $student->title ),
			$data
		);
	}

	/**
	 * Reorder the schedule into Monday-first order, dropping days with no entry.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $schedule Day → entries.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function sortSchedule( array $schedule ): array {
		$sorted = array();

		foreach ( self::DAY_ORDER as $day ) {
			if ( isset( $schedule[ $day ] ) ) {
				$sorted[ $day ] = $schedule[ $day ];
			}
		}

		return $sorted;
	}

	/**
	 * Read a scalar meta field as text.
	 */
	private function text( ContentItem $item, string $key ): string {
		$value = $item->meta[ $key ] ?? '';

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Read a list-shaped meta field.
	 *
	 * @return array<int, mixed>
	 */
	private function list( ContentItem $item, string $key ): array {
		$value = $item->meta[ $key ] ?? array();

		return is_array( $value ) ? array_values( $value ) : array();
	}
}
