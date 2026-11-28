<?php
/**
 * `db_search` — term search over declared columns.
 *
 * Proposal 167 phase P1, task 1.7. The searchable column list comes from the
 * scope (or, absent a declaration, the relation's text columns). The wildcard
 * characters `%` and `_` are escaped with `!`, so a term of `100%` cannot become
 * "every row" — the bug the Laravel `ProductCatalog` already documents.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Searches text columns for a term.
 */
final class WP_MCP_AI_Tool_Db_Search extends WP_MCP_AI_Database_Tool_Base {

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'db_search';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Search Database Rows', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Search for a term across the relation\'s searchable text columns. Wildcards in the term are treated literally. Use db_select for structured filters.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array_merge(
				$this->connection_properties(),
				array(
					'relation' => $this->relation_schema(),
					'term'     => array(
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => 200,
						'description' => 'Text to match. Treated as a literal substring, not a pattern.',
					),
					'columns'  => array(
						'type'        => 'array',
						'description' => 'Columns to search. Defaults to the scope\'s declared searchable columns.',
						'maxItems'    => 8,
						'items'       => array( 'type' => 'string' ),
					),
					'limit'    => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				)
			),
			'required'             => array( 'relation', 'term' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $arguments Sanitised tool arguments.
	 * @param array<string, mixed> $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		return $this->attempt(
			function () use ( $arguments, $context ) {
				$connection = $this->connection( $arguments, $context );
				$scope      = $this->scope( $arguments );
				$query      = $this->search_query( $arguments );
				$result     = $this->executor( $context )->search( $connection, $scope, $query );

				return $this->envelope(
					$result,
					array(
						'connection' => $connection,
						'relation'   => $query->relation,
						'term'       => $query->term,
					)
				);
			}
		);
	}
}
