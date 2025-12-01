<?php
/**
 * Provides a structured summary of JetEngine REST endpoints and CRUD coverage.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Utility for surfacing JetEngine endpoint metadata.
 */
class WP_MCP_AI_JetEngine_Endpoint_Report {
	/**
	 * Retrieve the endpoint report.
	 *
	 * @return array{
	 *     routes: array<string, array<string, array<int, array{route: string, method: string, description: string}>>>,
	 *     coverage: array<string, array<string, bool>>,
	 *     missing: array<string, array<int, string>>
	 * }
	 */
	public static function get_report() {
		$routes   = self::get_routes();
		$coverage = self::build_coverage_matrix( $routes );
		$missing  = self::locate_missing_operations( $coverage );

		return array(
			'routes'   => $routes,
			'coverage' => $coverage,
			'missing'  => $missing,
		);
	}

	/**
	 * Return the known JetEngine REST endpoints grouped by resource and CRUD operation.
	 *
	 * @return array<string, array<string, array<int, array{route: string, method: string, description: string}>>>
	 */
	protected static function get_routes() {
		$routes = array(
			'post_types' => array(
				'create' => array(
					array(
						'method'      => 'POST',
						'route'       => '/jet-engine/v2/add-post-type',
						'description' => __( 'Create a new custom post type definition.', 'wp-mcp-ai' ),
					),
					array(
						'method'      => 'POST',
						'route'       => '/jet-engine/v2/copy-post-type',
						'description' => __( 'Duplicate an existing post type into a new definition.', 'wp-mcp-ai' ),
					),
				),
				'read'   => array(
					array(
						'method'      => 'GET',
						'route'       => '/jet-engine/v2/get-post-type',
						'description' => __( 'Read a single stored post type configuration.', 'wp-mcp-ai' ),
					),
					array(
						'method'      => 'GET',
						'route'       => '/jet-engine/v2/get-post-types',
						'description' => __( 'List all stored custom post type configurations.', 'wp-mcp-ai' ),
					),
					array(
						'method'      => 'GET',
						'route'       => '/jet-engine/v2/get-built-in-post-type',
						'description' => __( 'Read overrides applied to a built-in post type.', 'wp-mcp-ai' ),
					),
				),
				'update' => array(
					array(
						'method'      => 'POST',
						'route'       => '/jet-engine/v2/edit-post-type',
						'description' => __( 'Update an existing custom post type definition.', 'wp-mcp-ai' ),
					),
					array(
						'method'      => 'POST',
						'route'       => '/jet-engine/v2/edit-built-in-post-type',
						'description' => __( 'Update overrides stored for a built-in post type.', 'wp-mcp-ai' ),
					),
				),
				'delete' => array(
					array(
						'method'      => 'DELETE',
						'route'       => '/jet-engine/v2/delete-post-type',
						'description' => __( 'Delete a stored custom post type definition.', 'wp-mcp-ai' ),
					),
					array(
						'method'      => 'DELETE',
						'route'       => '/jet-engine/v2/reset-built-in-post-type',
						'description' => __( 'Remove stored overrides from a built-in post type.', 'wp-mcp-ai' ),
					),
				),
			),
			'taxonomies' => array(
				'create' => array(
					array(
						'method'      => 'POST',
						'route'       => '/jet-engine/v2/add-taxonomy',
						'description' => __( 'Create a new taxonomy definition.', 'wp-mcp-ai' ),
					),
					array(
						'method'      => 'POST',
						'route'       => '/jet-engine/v2/copy-taxonomy',
						'description' => __( 'Clone an existing taxonomy definition into a new record.', 'wp-mcp-ai' ),
					),
				),
				'read'   => array(
					array(
						'method'      => 'GET',
						'route'       => '/jet-engine/v2/get-taxonomy',
						'description' => __( 'Read a single stored taxonomy configuration.', 'wp-mcp-ai' ),
					),
					array(
						'method'      => 'GET',
						'route'       => '/jet-engine/v2/get-taxonomies',
						'description' => __( 'List all stored taxonomy definitions.', 'wp-mcp-ai' ),
					),
					array(
						'method'      => 'GET',
						'route'       => '/jet-engine/v2/get-built-in-tax',
						'description' => __( 'Read overrides configured for a built-in taxonomy.', 'wp-mcp-ai' ),
					),
				),
				'update' => array(
					array(
						'method'      => 'POST',
						'route'       => '/jet-engine/v2/edit-taxonomy',
						'description' => __( 'Update an existing custom taxonomy definition.', 'wp-mcp-ai' ),
					),
					array(
						'method'      => 'POST',
						'route'       => '/jet-engine/v2/edit-built-in-tax',
						'description' => __( 'Update overrides for a built-in taxonomy.', 'wp-mcp-ai' ),
					),
				),
				'delete' => array(
					array(
						'method'      => 'DELETE',
						'route'       => '/jet-engine/v2/delete-taxonomy',
						'description' => __( 'Delete a stored custom taxonomy definition.', 'wp-mcp-ai' ),
					),
					array(
						'method'      => 'DELETE',
						'route'       => '/jet-engine/v2/reset-built-in-tax',
						'description' => __( 'Remove stored overrides from a built-in taxonomy.', 'wp-mcp-ai' ),
					),
				),
			),
			'relations'  => array(
				'create' => array(
					array(
						'method'      => 'POST',
						'route'       => '/jet-engine/v2/add-relation',
						'description' => __( 'Create a new relation definition.', 'wp-mcp-ai' ),
					),
				),
				'read'   => array(
					array(
						'method'      => 'GET',
						'route'       => '/jet-engine/v2/get-relation',
						'description' => __( 'Read a single relation configuration.', 'wp-mcp-ai' ),
					),
					array(
						'method'      => 'GET',
						'route'       => '/jet-engine/v2/get-relations',
						'description' => __( 'List all stored relations.', 'wp-mcp-ai' ),
					),
				),
				'update' => array(
					array(
						'method'      => 'POST',
						'route'       => '/jet-engine/v2/edit-relation',
						'description' => __( 'Update an existing relation definition.', 'wp-mcp-ai' ),
					),
				),
				'delete' => array(
					array(
						'method'      => 'DELETE',
						'route'       => '/jet-engine/v2/delete-relation',
						'description' => __( 'Delete a stored relation definition.', 'wp-mcp-ai' ),
					),
				),
			),
			'items'      => array(
				'create' => array(
					array(
						'method'      => 'POST',
						'route'       => '/jet-engine/v2/add-item',
						'description' => __( 'Create a new JetEngine item (listing/form entry).', 'wp-mcp-ai' ),
					),
				),
				'read'   => array(
					array(
						'method'      => 'GET',
						'route'       => '/jet-engine/v2/get-item',
						'description' => __( 'Read a stored JetEngine item.', 'wp-mcp-ai' ),
					),
					array(
						'method'      => 'GET',
						'route'       => '/jet-engine/v2/get-items',
						'description' => __( 'List stored JetEngine items.', 'wp-mcp-ai' ),
					),
					array(
						'method'      => 'GET',
						'route'       => '/jet-engine/v2/search-posts',
						'description' => __( 'Search posts using JetEngine utilities.', 'wp-mcp-ai' ),
					),
				),
				'update' => array(
					array(
						'method'      => 'POST',
						'route'       => '/jet-engine/v2/edit-item',
						'description' => __( 'Update an existing JetEngine item.', 'wp-mcp-ai' ),
					),
				),
				'delete' => array(
					array(
						'method'      => 'DELETE',
						'route'       => '/jet-engine/v2/delete-item',
						'description' => __( 'Delete a stored JetEngine item.', 'wp-mcp-ai' ),
					),
				),
			),
		);

		/**
		 * Filter the JetEngine endpoint reference data before it is returned.
		 *
		 * @param array<string, array<string, array<int, array{route: string, method: string, description: string}>>> $routes Endpoint data.
		 */
		return apply_filters( 'wp_mcp_ai_jetengine_endpoint_routes', $routes );
	}

	/**
	 * Build a CRUD coverage matrix for the provided endpoints.
	 *
	 * @param array<string, array<string, array<int, array{route: string, method: string, description: string}>>> $routes Endpoint data.
	 * @return array<string, array<string, bool>>
	 */
	protected static function build_coverage_matrix( $routes ) {
		$coverage = array();

		foreach ( $routes as $resource => $operations ) {
			$coverage[ $resource ] = array(
				'create' => ! empty( $operations['create'] ),
				'read'   => ! empty( $operations['read'] ),
				'update' => ! empty( $operations['update'] ),
				'delete' => ! empty( $operations['delete'] ),
			);
		}

		/**
		 * Filter the CRUD coverage matrix derived from the JetEngine endpoints.
		 *
		 * @param array<string, array<string, bool>> $coverage CRUD matrix.
		 * @param array<string, array<string, array<int, array{route: string, method: string, description: string}>>> $routes Endpoint data used to derive the matrix.
		 */
		return apply_filters( 'wp_mcp_ai_jetengine_endpoint_coverage', $coverage, $routes );
	}

	/**
	 * Locate missing CRUD operations for each JetEngine resource.
	 *
	 * @param array<string, array<string, bool>> $coverage CRUD matrix.
	 * @return array<string, array<int, string>>
	 */
	protected static function locate_missing_operations( $coverage ) {
		$missing = array();

		foreach ( $coverage as $resource => $operations ) {
			$missing_ops = array();

			foreach ( $operations as $operation => $supported ) {
				if ( ! $supported ) {
					$missing_ops[] = $operation;
				}
			}

			if ( ! empty( $missing_ops ) ) {
				$missing[ $resource ] = $missing_ops;
			}
		}

		/**
		 * Filter the list of missing CRUD operations derived from the JetEngine coverage matrix.
		 *
		 * @param array<string, array<int, string>> $missing Missing CRUD operations grouped by resource.
		 * @param array<string, array<string, bool>> $coverage CRUD matrix.
		 */
		return apply_filters( 'wp_mcp_ai_jetengine_missing_operations', $missing, $coverage );
	}
}

/**
 * Public helper for retrieving the JetEngine endpoint report.
 *
 * @return array{
 *     routes: array<string, array<string, array<int, array{route: string, method: string, description: string}>>>,
 *     coverage: array<string, array<string, bool>>,
 *     missing: array<string, array<int, string>>
 * }
 */
function wp_mcp_ai_get_jetengine_endpoint_report() {
	return WP_MCP_AI_JetEngine_Endpoint_Report::get_report();
}
