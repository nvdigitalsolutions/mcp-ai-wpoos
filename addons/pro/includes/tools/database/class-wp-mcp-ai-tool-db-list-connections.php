<?php
/**
 * `db_list_connections` — what this assistant can reach.
 *
 * Proposal 167 phase P1, task 1.1. The list is scope-derived and redacted: it
 * answers "which connections may I use?" without disclosing a host, a database
 * name, a credential handle or a replica. That is deliberate — a model that can
 * enumerate endpoints can infer an estate, which is information the scope did not
 * grant.
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
 * Lists the connections a principal may use.
 */
final class WP_MCP_AI_Tool_Db_List_Connections extends WP_MCP_AI_Database_Tool_Base {

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'db_list_connections';
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
		return __( 'List Database Connections', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'List the database connections this assistant may use, with each connection id, engine and health state. Use the returned id in other db_* tools.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
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
		unset( $arguments );

		return $this->attempt(
			function () use ( $context ) {
				$executor    = $this->executor( $context );
				$redactor    = new WP_MCP_AI_Db_Redactor();
				$connections = array();

				foreach ( $executor->connections( $this->principal( $context ) ) as $connection ) {
					$connections[] = $redactor->connection_summary( $connection );
				}

				return array(
					'success'     => true,
					'connections' => $connections,
					'count'       => count( $connections ),
				);
			}
		);
	}
}
