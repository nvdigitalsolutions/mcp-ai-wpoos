<?php
/**
 * Get ECA tool — one `mcp_ai_eca` row plus its enrolment view.
 *
 * Mirrors `WP_MCP_AI_Tool_Get_ECA` (addons/pro/includes/tools/eca-management):
 * same slug, same `eca_id` / `include_enrollments` parameters, same field order
 * in the returned `eca` object, same derived capacity values.
 *
 * The envelope is the canonical one, so the row sits under `data.eca` rather
 * than at the top level — the same nesting every other ported tool uses.
 *
 * Documented divergences (090-d §5):
 * - `url` is always empty: permalinks are a WordPress concept.
 * - `enrollment_breakdown` counts `_eca_student_enrollments` when the
 *   per-enrolment records are absent. The WordPress tool reads only
 *   `_eca_enrollments`, which nothing in the family writes, so its breakdown is
 *   always zero on real data. See `EcaRow::breakdown()`.
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

class GetEcaTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'get_eca';
	}

	public function getName(): string {
		return 'Get ECA';
	}

	public function getDescription(): string {
		return 'Returns full details of a single Extra-Curricular Activity, including schedule, capacity, enrollment breakdown, and optionally the enrolled student list.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'eca_id'              => array(
					'type'        => 'integer',
					'description' => 'ECA ID to retrieve (required)',
					'minimum'     => 1,
				),
				'include_enrollments' => array(
					'type'        => 'boolean',
					'description' => 'Include the list of enrolled students with their enrollment details',
					'default'     => false,
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
		$this->authorize( $context, 'read' );

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

		$data = EcaRow::detail( $eca );

		if ( $this->boolParam( $arguments, 'include_enrollments' ) ) {
			$data['enrollments'] = $this->enrollments( $eca );
		}

		return $this->success(
			sprintf( 'Retrieved ECA "%s".', $eca->title ),
			array( 'eca' => $data )
		);
	}

	/**
	 * Resolve the enrolled student list to the WordPress wire shape.
	 *
	 * `enrollment_date` and `payment_status` are empty strings: the local
	 * mirror stores the enrolment as a bare id list, so there is nothing to
	 * report for either. A student that no longer resolves is kept (as
	 * `Unknown`) rather than dropped, so the count never disagrees with the
	 * activity's `current_enrollment`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function enrollments( ContentItem $eca ): array {
		$records = $eca->meta['_eca_enrollments'] ?? array();

		// Per-enrolment records win when a site has them.
		if ( is_array( $records ) && array() !== $records ) {
			$rows = array();

			foreach ( $records as $record ) {
				if ( ! is_array( $record ) ) {
					continue;
				}

				$student_id = isset( $record['student_id'] ) ? (int) $record['student_id'] : 0;

				if ( $student_id <= 0 ) {
					continue;
				}

				$rows[] = $this->enrollmentRow(
					$student_id,
					isset( $record['enrollment_type'] ) && is_string( $record['enrollment_type'] )
						? $record['enrollment_type']
						: 'confirmed',
					isset( $record['enrollment_date'] ) && is_scalar( $record['enrollment_date'] )
						? (string) $record['enrollment_date']
						: '',
					isset( $record['payment_status'] ) && is_scalar( $record['payment_status'] )
						? (string) $record['payment_status']
						: ''
				);
			}

			return $rows;
		}

		$rows = array();

		foreach ( EcaRow::enrollmentIds( $eca ) as $student_id ) {
			$rows[] = $this->enrollmentRow( $student_id, 'confirmed', '', '' );
		}

		return $rows;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function enrollmentRow(
		int $student_id,
		string $enrollment_type,
		string $enrollment_date,
		string $payment_status
	): array {
		$student = $this->content->find( $student_id );

		return array(
			'student_id'      => $student_id,
			'student_name'    => $student instanceof ContentItem ? $student->title : 'Unknown',
			'enrollment_type' => $enrollment_type,
			'enrollment_date' => $enrollment_date,
			'payment_status'  => $payment_status,
		);
	}
}
