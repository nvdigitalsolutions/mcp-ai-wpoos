<?php
/**
 * List ECAs tool — reads the `mcp_ai_eca` family through the content port.
 *
 * Mirrors `WP_MCP_AI_Tool_List_ECAs` (addons/pro/includes/tools/eca-management):
 * same slug, same filters, same pagination envelope and same row keys. The
 * storage shape is whatever `ContentStoreInterface` is bound to — the CPT plus
 * postmeta on WordPress, `nvoos_posts` rows on Laravel.
 *
 * Scope is the **local mirror** (090-d cluster 3): the Google Classroom and
 * ISAMS-backed tools in this family talk to remote APIs and stay
 * WordPress-only; this tool only ever reads rows the local store already has.
 *
 * Documented divergences (090-d §5):
 * - `sort_by` accepts `name` and `created_date` only. WordPress can also sort
 *   by `day` and `type` through `meta_value`, which `ContentQuery` does not
 *   expose (its order columns are `date`, `modified`, `title`, `id`). Sorting
 *   by an unsupported field fails loudly instead of silently returning the
 *   default order.
 * - `url` is always empty: permalinks are a WordPress concept.
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

class ListEcasTool extends AbstractTool {

	private const SORTABLE = array( 'name', 'created_date' );

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'list_ecas';
	}

	public function getName(): string {
		return 'List ECAs';
	}

	public function getDescription(): string {
		return 'Lists Extra-Curricular Activities with comprehensive filtering by type, day, year group, teacher, venue, status, and availability. Returns enrollment counts with capacity utilization.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'eca_type'         => array(
					'type'        => 'string',
					'description' => 'Filter by ECA type',
					'enum'        => array( 'club', 'society', 'sport_squad', 'sport_academy', 'activity' ),
				),
				'day'              => array(
					'type'        => 'string',
					'description' => 'Filter by day of the week',
					'enum'        => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ),
				),
				'year_group'       => array(
					'type'        => 'string',
					'description' => 'Filter by year group eligibility',
				),
				'teacher'          => array(
					'type'        => 'string',
					'description' => 'Filter by teacher name or user ID',
				),
				'venue'            => array(
					'type'        => 'string',
					'description' => 'Filter by venue/location',
				),
				'status'           => array(
					'type'        => 'string',
					'description' => 'Filter by ECA status',
					'enum'        => array( 'active', 'inactive', 'full', 'cancelled' ),
				),
				'is_paid'          => array(
					'type'        => 'boolean',
					'description' => 'Filter by paid/free activities',
				),
				'has_availability' => array(
					'type'        => 'boolean',
					'description' => 'Filter to show only ECAs with available spots',
				),
				'search'           => array(
					'type'        => 'string',
					'description' => 'Search by ECA name or description',
					'maxLength'   => 200,
				),
				'sort_by'          => array(
					'type'        => 'string',
					'description' => 'Sort results by field. This runtime supports name and created_date; day and type are WordPress-only.',
					'enum'        => self::SORTABLE,
					'default'     => 'name',
				),
				'sort_order'       => array(
					'type'        => 'string',
					'description' => 'Sort direction',
					'enum'        => array( 'ASC', 'DESC' ),
					'default'     => 'ASC',
				),
				'page'             => array(
					'type'        => 'integer',
					'description' => 'Page number for pagination',
					'default'     => 1,
					'minimum'     => 1,
				),
				'per_page'         => array(
					'type'        => 'integer',
					'description' => 'Number of ECAs per page',
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$this->authorize( $context, 'read' );

		$page     = max( 1, $this->intParam( $arguments, 'page', 1 ) );
		$per_page = min( 100, max( 1, $this->intParam( $arguments, 'per_page', 20 ) ) );

		$sort_by = $this->stringParam( $arguments, 'sort_by', 'name' );

		if ( ! in_array( $sort_by, self::SORTABLE, true ) ) {
			return $this->errors->validationFailed(
				sprintf(
					"Unsupported 'sort_by' value '%s'. This runtime can sort ECAs by name or created_date; day and type sorting is WordPress-only.",
					$sort_by
				),
				array(
					'sort_by' => array( 'Use name or created_date.' ),
				)
			);
		}

		$order = 'DESC' === strtoupper( $this->stringParam( $arguments, 'sort_order', 'ASC' ) ) ? 'DESC' : 'ASC';

		$meta = array();

		$this->addEquals( $meta, '_eca_type', $this->stringParam( $arguments, 'eca_type' ) );
		$this->addEquals( $meta, '_eca_day', $this->stringParam( $arguments, 'day' ) );
		$this->addEquals( $meta, '_eca_status', $this->stringParam( $arguments, 'status' ) );

		// Year groups and teachers are list-valued; a LIKE match against the
		// stored encoding is what the WordPress tool does too.
		$this->addLike( $meta, '_eca_year_groups', $this->stringParam( $arguments, 'year_group' ) );
		$this->addLike( $meta, '_eca_teachers', $this->stringParam( $arguments, 'teacher' ) );
		$this->addLike( $meta, '_eca_venue', $this->stringParam( $arguments, 'venue' ) );

		// `isset()` in the WordPress tool means an explicit false filters for
		// free activities, while an absent key does not filter at all.
		if ( array_key_exists( 'is_paid', $arguments ) && null !== $arguments['is_paid'] ) {
			$meta[] = array(
				'key'     => '_eca_is_paid',
				'value'   => $this->boolParam( $arguments, 'is_paid' ) ? 'yes' : 'no',
				'compare' => '=',
			);
		}

		if ( $this->boolParam( $arguments, 'has_availability' ) ) {
			$meta[] = array(
				'key'     => '_eca_status',
				'value'   => 'full',
				'compare' => '!=',
			);
		}

		$search = $this->stringParam( $arguments, 'search' );

		$collection = $this->content->query(
			new ContentQuery(
				types: array( 'mcp_ai_eca' ),
				statuses: array( 'publish' ),
				search: '' !== $search ? $search : null,
				metaQuery: $meta,
				orderBy: 'name' === $sort_by ? 'title' : 'date',
				order: $order,
				page: $page,
				perPage: $per_page,
			)
		);

		$ecas = array();

		foreach ( $collection->items as $item ) {
			$ecas[] = EcaRow::from( $item );
		}

		return $this->success(
			sprintf( 'Found %d ECAs.', count( $ecas ) ),
			array(
				'ecas'        => $ecas,
				'total'       => $collection->total,
				'page'        => $collection->page,
				'per_page'    => $collection->perPage,
				'total_pages' => $collection->totalPages,
				'has_more'    => $collection->hasMorePages(),
			)
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $meta Meta clause list, by reference.
	 */
	private function addEquals( array &$meta, string $key, string $value ): void {
		if ( '' === $value ) {
			return;
		}

		$meta[] = array(
			'key'     => $key,
			'value'   => $value,
			'compare' => '=',
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $meta Meta clause list, by reference.
	 */
	private function addLike( array &$meta, string $key, string $value ): void {
		if ( '' === $value ) {
			return;
		}

		$meta[] = array(
			'key'     => $key,
			'value'   => $value,
			'compare' => 'LIKE',
		);
	}
}
