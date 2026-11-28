<?php
/**
 * `db_select` — the structured read.
 *
 * Proposal 167 phase P1, task 1.5. Columns, filters, ordering, limit and a keyset
 * cursor; every value bound; every identifier resolved from the scope-filtered
 * catalogue. There is no SQL string anywhere in the argument surface.
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
 * Reads rows from a scoped relation.
 */
final class WP_MCP_AI_Tool_Db_Select extends WP_MCP_AI_Database_Tool_Base {

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'db_select';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Declared as a literal rather than inherited: bin/generate-tool-capability-flags.php
	 * reads flags statically with token_get_all(), and a tool absent from the
	 * catalogue is denied by the read-only profile guard.
	 */
	public function get_capability_flags() {
		return array( 'pro', 'requires-capability', 'read-only', 'external-api', 'network-dependent', 'paginated' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Select Database Rows', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Read rows from one table or view inside the assistant scope, with optional filters, ordering and keyset paging. Only columns the scope allows are returned; sensitive columns are masked.', 'mcp-ai-wpoos-pro' );
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
					'columns'  => array(
						'type'        => 'array',
						'description' => 'Columns to return. Omit for every column the scope allows.',
						'maxItems'    => 40,
						'items'       => array( 'type' => 'string' ),
					),
					'filters'  => $this->filter_schema(),
					'order'    => $this->order_schema(),
					'limit'    => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Rows to return. The scope cap wins when it is lower.',
					),
					'offset'   => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Row offset. Refused above the scope ceiling; prefer cursor paging.',
					),
					'cursor'   => array(
						'type'        => 'string',
						'description' => 'The next_cursor value from a previous call, for stable keyset paging.',
					),
				)
			),
			'required'             => array( 'relation' ),
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
				$query      = $this->select_query( $arguments );
				$result     = $this->executor( $context )->select( $connection, $scope, $query );

				return $this->envelope(
					$result,
					array(
						'connection' => $connection,
						'relation'   => $query->relation,
					)
				);
			}
		);
	}
}
