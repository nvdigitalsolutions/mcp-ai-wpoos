<?php
/**
 * `db_aggregate` — grouped metrics.
 *
 * Proposal 167 phase P1, task 1.6. Metrics are a closed enum, never SQL: the
 * grammar emits `COUNT(*)`, `SUM(col)` and the PostgreSQL percentile form, and
 * refuses a metric the engine cannot compute. That is why `db_aggregate` cannot
 * be used as a general-purpose query builder.
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
 * Computes grouped aggregates.
 */
final class WP_MCP_AI_Tool_Db_Aggregate extends WP_MCP_AI_Database_Tool_Base {

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'db_aggregate';
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
		return __( 'Aggregate Database Rows', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Compute counts, sums, averages, minima, maxima, distinct counts or percentiles for one relation, optionally grouped by columns. Metrics are a closed list; raw SQL is not accepted.', 'mcp-ai-wpoos-pro' );
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
					'group_by' => array(
						'type'        => 'array',
						'description' => 'Columns to group by, in order.',
						'maxItems'    => 8,
						'items'       => array( 'type' => 'string' ),
					),
					'metrics'  => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => 12,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'metric' => array(
									'type' => 'string',
									'enum' => array( 'count', 'count_distinct', 'sum', 'avg', 'min', 'max', 'median', 'p90' ),
								),
								'column' => array(
									'type'        => 'string',
									'description' => 'Required for every metric except count.',
								),
								'alias'  => array(
									'type'        => 'string',
									'description' => 'Output column name; defaults to the metric name.',
								),
							),
							'required'             => array( 'metric' ),
							'additionalProperties' => false,
						),
					),
					'filters'  => $this->filter_schema(),
					'order'    => $this->order_schema(),
					'limit'    => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				)
			),
			'required'             => array( 'relation', 'metrics' ),
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
				$query      = $this->aggregate_query( $arguments );
				$result     = $this->executor( $context )->aggregate( $connection, $scope, $query );

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
