<?php
/**
 * `db_describe` — the scope-filtered catalogue.
 *
 * Proposal 167 phase P1, task 1.2 and decision D7. System schemas never appear,
 * and a relation or column outside the scope is absent rather than redacted: the
 * catalogue is the grammar's identifier source, so "absent" and "cannot be
 * queried" are the same fact.
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
 * Describes tables and columns.
 */
final class WP_MCP_AI_Tool_Db_Describe extends WP_MCP_AI_Database_Tool_Base {

	/**
	 * Maximum relations returned in one call.
	 */
	private const MAX_TABLES = 60;

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'db_describe';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Describe Database Tables', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Describe the tables and columns this assistant may read. Optionally name one relation (schema.table) to describe it in detail. Only relations inside the configured scope are returned.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection' => array(
					'type'        => 'string',
					'description' => 'Connection id from db_list_connections. Optional when only one connection is available.',
				),
				'relation'   => array(
					'type'        => 'string',
					'description' => 'A single schema-qualified relation, e.g. "reporting.orders".',
				),
			),
			'required'             => array(),
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
				$relation   = $this->relation( $arguments );
				$catalogue  = $this->executor( $context )->describe(
					$connection,
					'' !== $relation ? $relation : null
				);

				$tables    = array();
				$truncated = false;

				foreach ( $catalogue->tables as $table ) {
					if ( count( $tables ) >= self::MAX_TABLES ) {
						$truncated = true;
						break;
					}

					$columns = array();

					foreach ( $table->columns as $column ) {
						$columns[] = array(
							'name'     => $column->name,
							'type'     => $column->type,
							'nullable' => $column->nullable,
						);
					}

					$tables[] = array(
						'relation'    => $table->key(),
						'is_view'     => $table->isView,
						'primary_key' => $table->primaryKey,
						'columns'     => $columns,
					);
				}

				return array(
					'success'    => true,
					'connection' => $connection,
					'tables'     => $tables,
					'count'      => count( $tables ),
					'truncated'  => $truncated,
				);
			}
		);
	}
}
