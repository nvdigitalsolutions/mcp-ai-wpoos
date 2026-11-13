<?php
/**
 * List Projects tool — reads the mcp_ai_project family through the content port.
 *
 * Identical slug, parameters and result fields to the WordPress tool; the
 * storage shape is whatever ContentStoreInterface is bound to (CPT + postmeta
 * on WordPress, nvoos_posts rows on Laravel).
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Tool\ProjectManagement\ProjectRow;

class ListProjectsTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'list_projects';
	}

	public function getName(): string {
		return 'List Projects';
	}

	public function getDescription(): string {
		return 'Lists projects with optional filtering by status, date range, or assigned user. Useful for project management and calendar views.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'status'      => array(
					'type'        => 'string',
					'description' => 'Filter by project status (optional)',
					'enum'        => array( 'planning', 'active', 'on-hold', 'completed', 'cancelled' ),
				),
				'assigned_to' => array(
					'type'        => 'integer',
					'description' => 'Filter by assigned user ID (optional)',
				),
				'start_after' => array(
					'type'        => 'string',
					'description' => 'Filter projects starting after this date (YYYY-MM-DD) (optional)',
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_before'  => array(
					'type'        => 'string',
					'description' => 'Filter projects ending before this date (YYYY-MM-DD) (optional)',
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'limit'       => array(
					'type'        => 'integer',
					'description' => 'Maximum number of projects to return (default: 20, max: 100)',
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
			),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$this->authorize( $context, 'edit_posts' );

		$meta = array();

		$status = $this->stringParam( $arguments, 'status' );
		if ( '' !== $status ) {
			$meta[] = array(
				'key'     => '_project_status',
				'value'   => $status,
				'compare' => '=',
			);
		}

		$assigned = $this->intParam( $arguments, 'assigned_to' );
		if ( $assigned > 0 ) {
			// `_project_assigned_to` is an array of string user IDs. The
			// quoted pattern matches a JSON element and a PHP-serialized
			// string element, so the filter behaves the same on both runtimes.
			$meta[] = array(
				'key'     => '_project_assigned_to',
				'value'   => '%"' . $assigned . '"%',
				'compare' => 'LIKE',
			);
		}

		$start_after = $this->stringParam( $arguments, 'start_after' );
		if ( '' !== $start_after ) {
			$meta[] = array(
				'key'     => '_project_start_date',
				'value'   => $start_after,
				'compare' => '>=',
			);
		}

		$end_before = $this->stringParam( $arguments, 'end_before' );
		if ( '' !== $end_before ) {
			$meta[] = array(
				'key'     => '_project_end_date',
				'value'   => $end_before,
				'compare' => '<=',
			);
		}

		$limit = min( 100, max( 1, $this->intParam( $arguments, 'limit', 20 ) ) );

		$collection = $this->content->query(
			new ContentQuery(
				types: array( 'mcp_ai_project' ),
				statuses: array( 'publish' ),
				metaQuery: $meta,
				orderBy: 'date',
				order: 'DESC',
				perPage: $limit,
			)
		);

		$projects = array();

		foreach ( $collection->items as $item ) {
			$projects[] = ProjectRow::from( $item );
		}

		return $this->success(
			sprintf( 'Found %d projects.', count( $projects ) ),
			array(
				'count'    => count( $projects ),
				'total'    => $collection->total,
				'projects' => $projects,
			)
		);
	}

}
