<?php
/**
 * List Students tool — reads the `mcp_ai_student` family through the content
 * port.
 *
 * Mirrors `WP_MCP_AI_Tool_List_Students` (addons/pro/includes/tools/
 * eca-management): same slug, filters, row keys and pagination envelope.
 *
 * `enrollment_count` counts `_student_eca_enrollments`, exactly as WordPress
 * does. That is the reverse link the **enrolment** tools maintain, so it reads
 * zero until `enroll_student_eca` is ported — the local mirror records
 * enrolment on the activity (`_eca_student_enrollments`) and has no writer for
 * the student side yet. Reading the activity side instead would be a different
 * number computed from a different table, which is a design decision rather
 * than a port.
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
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Tool\AbstractTool;

class ListStudentsTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'list_students';
	}

	public function getName(): string {
		return 'List Students';
	}

	public function getDescription(): string {
		return 'Lists students with optional filtering by year group or house, and search by name. Returns each student\'s enrolment count.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'year_group' => array(
					'type'        => 'string',
					'description' => 'Filter by year group (optional)',
					'maxLength'   => 50,
				),
				'house'      => array(
					'type'        => 'string',
					'description' => 'Filter by house (optional)',
					'maxLength'   => 100,
				),
				'search'     => array(
					'type'        => 'string',
					'description' => 'Search by student name (optional)',
					'maxLength'   => 200,
				),
				'per_page'   => array(
					'type'        => 'integer',
					'description' => 'Results per page (default: 20, max: 100)',
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'page'       => array(
					'type'        => 'integer',
					'description' => 'Page number (default: 1)',
					'default'     => 1,
					'minimum'     => 1,
				),
			),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$this->authorize( $context, 'read' );

		$per_page = $this->intParam( $arguments, 'per_page', 20 );

		// WordPress resets an out-of-range value to the default rather than
		// clamping it; a port that clamped would answer a different page size
		// for the same request.
		if ( $per_page < 1 || $per_page > 100 ) {
			$per_page = 20;
		}

		$page = max( 1, $this->intParam( $arguments, 'page', 1 ) );

		$meta = array();

		$year_group = $this->stringParam( $arguments, 'year_group' );
		if ( '' !== $year_group ) {
			$meta[] = array(
				'key'     => '_student_year_group',
				'value'   => $year_group,
				'compare' => '=',
			);
		}

		$house = $this->stringParam( $arguments, 'house' );
		if ( '' !== $house ) {
			$meta[] = array(
				'key'     => '_student_house',
				'value'   => $house,
				'compare' => '=',
			);
		}

		$search = $this->stringParam( $arguments, 'search' );

		$collection = $this->content->query(
			new ContentQuery(
				types: array( 'mcp_ai_student' ),
				statuses: array( 'publish' ),
				search: '' !== $search ? $search : null,
				metaQuery: $meta,
				orderBy: 'title',
				order: 'ASC',
				page: $page,
				perPage: $per_page,
			)
		);

		$students = array();

		foreach ( $collection->items as $item ) {
			$students[] = StudentRow::from( $item );
		}

		return $this->success(
			sprintf( 'Found %d students.', count( $students ) ),
			array(
				'students'   => $students,
				'pagination' => array(
					'total'        => $collection->total,
					'total_pages'  => $collection->totalPages,
					'current_page' => $collection->page,
					'per_page'     => $collection->perPage,
				),
			)
		);
	}
}
