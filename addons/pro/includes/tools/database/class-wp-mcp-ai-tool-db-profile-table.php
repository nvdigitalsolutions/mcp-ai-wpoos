<?php
/**
 * `db_profile_table` — a bounded column profile.
 *
 * Proposal 167 phase P1, task 1.3. "Bounded" is the whole design: at most eight
 * columns, three aggregate queries per column, and no value listing for a column
 * the scope marks sensitive. Profiling a sensitive column would disclose its
 * distribution (and its top values) despite the masking that applies to row
 * reads, so it is skipped and reported as skipped rather than silently profiled.
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

use Nvoos\Database\Domain\Service\Database\GrantTier;
use Nvoos\Database\Domain\Service\Database\Metric;
use Nvoos\Database\Domain\Service\Database\RefusalCode;
use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\ValueObject\Database\AggregateQuery;
use Nvoos\Database\Domain\ValueObject\Database\FilterClause;
use Nvoos\Database\Domain\ValueObject\Database\MetricClause;
use Nvoos\Database\Domain\ValueObject\Database\OrderClause;

/**
 * Profiles columns of one relation.
 */
final class WP_MCP_AI_Tool_Db_Profile_Table extends WP_MCP_AI_Database_Tool_Base {

	/**
	 * Maximum columns profiled per call.
	 */
	private const MAX_COLUMNS = 8;

	/**
	 * Top values returned per column.
	 */
	private const TOP_VALUES = 5;

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'db_profile_table';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Profile Database Table', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Summarise a table: row count, null counts, distinct counts, minima, maxima and the five most frequent values for each profiled column. Sensitive columns are skipped, not profiled.', 'mcp-ai-wpoos-pro' );
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
						'description' => 'Columns to profile. Defaults to the first eight readable columns.',
						'maxItems'    => self::MAX_COLUMNS,
						'items'       => array( 'type' => 'string' ),
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
				$scope_id   = $this->scope( $arguments );
				$relation   = $this->relation( $arguments );
				$principal  = $this->principal( $context );
				$executor   = $this->executor( $context );

				$scope = ( new WP_MCP_AI_Db_Scope_Registry() )->resolve_for(
					$principal,
					$connection,
					'' !== $scope_id ? $scope_id : null,
					GrantTier::READ
				);

				$described = $executor->describe( $connection, $relation );
				$table     = reset( $described->tables );

				if ( ! $table instanceof \Nvoos\Database\Domain\ValueObject\Database\TableDescriptor ) {
					throw DatabaseRefusal::of( RefusalCode::RELATION_NOT_FOUND, 'Relation is not readable: ' . $relation );
				}

				$requested = $this->string_list( $arguments['columns'] ?? array() );
				$readable  = array_keys( $table->columns );
				$chosen    = array() !== $requested ? array_values( array_intersect( $requested, $readable ) ) : array_slice( $readable, 0, self::MAX_COLUMNS );
				$chosen    = array_slice( $chosen, 0, self::MAX_COLUMNS );

				if ( array() === $chosen ) {
					throw DatabaseRefusal::of( RefusalCode::COLUMN_NOT_ALLOWED, 'No readable column remains to profile.' );
				}

				$total = $executor->aggregate(
					$connection,
					$scope->id,
					new AggregateQuery(
						relation: $table->key(),
						metrics: array( new MetricClause( Metric::COUNT, 'n' ) ),
						limit: 1
					)
				);

				$row_count = (int) ( $total->rows[0]['n'] ?? 0 );
				$columns   = array();
				$skipped   = array();

				foreach ( $chosen as $column ) {
					if ( $scope->isSensitive( $table, $column ) ) {
						$skipped[] = $column;
						continue;
					}

					$columns[] = $this->profile_column( $executor, $connection, $scope->id, $table->key(), $column, $row_count );
				}

				return array(
					'success'    => true,
					'connection' => $connection,
					'relation'   => $table->key(),
					'row_count'  => $row_count,
					'columns'    => $columns,
					'skipped'    => $skipped,
					'scope'      => $this->scope_summary( $scope ),
				);
			}
		);
	}

	/**
	 * Profile one column.
	 *
	 * @param WP_MCP_AI_Db_Executor $executor   Executor.
	 * @param string                $connection Connection id.
	 * @param string                $scope      Scope id.
	 * @param string                $relation   Relation.
	 * @param string                $column     Column.
	 * @param int                   $row_count  Total rows.
	 * @return array<string, mixed>
	 */
	private function profile_column(
		WP_MCP_AI_Db_Executor $executor,
		string $connection,
		string $scope,
		string $relation,
		string $column,
		int $row_count
	): array {
		$nulls = $executor->aggregate(
			$connection,
			$scope,
			new AggregateQuery(
				relation: $relation,
				metrics: array( new MetricClause( Metric::COUNT, 'n' ) ),
				filters: array( new FilterClause( $column, \Nvoos\Database\Domain\Service\Database\FilterOperator::IS_NULL ) ),
				limit: 1
			)
		);

		$stats = $executor->aggregate(
			$connection,
			$scope,
			new AggregateQuery(
				relation: $relation,
				metrics: array(
					new MetricClause( Metric::COUNT_DISTINCT, 'distinct', $column ),
					new MetricClause( Metric::MIN, 'min', $column ),
					new MetricClause( Metric::MAX, 'max', $column ),
				),
				limit: 1
			)
		);

		$top = $executor->aggregate(
			$connection,
			$scope,
			new AggregateQuery(
				relation: $relation,
				groupBy: array( $column ),
				metrics: array( new MetricClause( Metric::COUNT, 'n' ) ),
				order: array( new OrderClause( 'n', 'desc' ) ),
				limit: self::TOP_VALUES
			)
		);

		$null_count = (int) ( $nulls->rows[0]['n'] ?? 0 );
		$stats_row  = $stats->rows[0] ?? array();

		$frequent = array();

		foreach ( $top->rows as $row ) {
			$frequent[] = array(
				'value' => $row[ $column ] ?? null,
				'count' => (int) ( $row['n'] ?? 0 ),
			);
		}

		return array(
			'name'          => $column,
			'nulls'         => $null_count,
			'null_percent'  => $row_count > 0 ? round( $null_count / $row_count * 100, 1 ) : 0.0,
			'distinct'      => (int) ( $stats_row['distinct'] ?? 0 ),
			'min'           => $stats_row['min'] ?? null,
			'max'           => $stats_row['max'] ?? null,
			'most_frequent' => $frequent,
		);
	}
}
