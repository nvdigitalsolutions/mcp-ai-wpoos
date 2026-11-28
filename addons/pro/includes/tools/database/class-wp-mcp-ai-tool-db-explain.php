<?php
/**
 * `db_explain` — the query plan, and optionally an analysed run.
 *
 * Proposal 167 phase P1 task 1.4 and P5 task 5.2. `analyze` executes the query,
 * so it is a separate tier (`explain_analyze`) and is refused on an engine that
 * cannot do it. A plain plan is `metadata` + `read`: it must still resolve the
 * same relation and filters, otherwise the plan would describe a query the scope
 * would refuse.
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
 * Explains a scoped select.
 */
final class WP_MCP_AI_Tool_Db_Explain extends WP_MCP_AI_Database_Tool_Base {

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'db_explain';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Explain Database Query', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Return the database plan for a scoped select without returning rows. Set analyze to true to execute and measure, which requires the explain_analyze capability on the scope.', 'mcp-ai-wpoos-pro' );
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
						'type'     => 'array',
						'maxItems' => 40,
						'items'    => array( 'type' => 'string' ),
					),
					'filters'  => $this->filter_schema(),
					'order'    => $this->order_schema(),
					'limit'    => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'analyze'  => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Execute the query and return measured timings. Requires the explain_analyze capability.',
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
				$analyze    = ! empty( $arguments['analyze'] );
				$query      = $this->select_query( $arguments );
				$plan       = $this->executor( $context )->explain( $connection, $scope, $query, $analyze );

				return array_merge(
					array(
						'success'    => true,
						'connection' => $connection,
						'relation'   => $query->relation,
					),
					$plan->toArray()
				);
			}
		);
	}
}
