<?php
/**
 * `db_propose_write` — record an intended change, change nothing.
 *
 * Proposal 167 phase P6, task 6.2. The tool is deliberately incapable of
 * executing: it compiles the structured request, asks the database how many rows
 * would match, stores a proposal, and returns its id. An approver then sees the
 * statement template and the database's count before anyone can apply it.
 *
 * Registered only when `enable_database_writes` is on. A scope must also hold
 * `propose_write`, and `max_affected_rows` must be non-zero — the scope's own
 * default refuses every write.
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
 * Proposes an UPDATE or DELETE.
 */
final class WP_MCP_AI_Tool_Db_Propose_Write extends WP_MCP_AI_Database_Tool_Base {

	/**
	 * {@inheritDoc}
	 */
	public static function is_available() {
		return self::writes_enabled() && parent::is_available();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'db_propose_write';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Propose Database Change', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Propose an UPDATE or DELETE on a scoped table. Nothing is changed: the tool returns a proposal id, the statement template and the number of rows the database says would be affected. A human must approve it before db_apply_write can run.', 'mcp-ai-wpoos-pro' );
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
					'operation' => array(
						'type' => 'string',
						'enum' => array( 'update', 'delete' ),
					),
					'relation'  => $this->relation_schema(),
					'set'       => array(
						'type'        => 'object',
						'description' => 'Column to new value. Required for update; omitted for delete.',
					),
					'filters'   => array_merge(
						$this->filter_schema(),
						array( 'minItems' => 1 )
					),
					'rationale' => array(
						'type'      => 'string',
						'maxLength' => 500,
					),
				)
			),
			'required'             => array( 'operation', 'relation', 'filters', 'rationale' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'requires-capability', 'state-changing', 'external-api', 'network-dependent', 'financial-impact' );
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
				$principal  = $this->principal( $context );
				$request    = array(
					'operation' => sanitize_key( (string) ( $arguments['operation'] ?? '' ) ),
					'relation'  => $this->relation( $arguments ),
					'set'       => array(),
					'filters'   => $this->filters( $arguments['filters'] ?? array() ),
				);

				foreach ( (array) ( $arguments['set'] ?? array() ) as $column => $value ) {
					$request['set'][ strtolower( sanitize_key( (string) $column ) ) ] = $this->filter_value( $value );
				}

				$writer = $this->writer( $context );
				$result = $writer->propose(
					$principal,
					$connection,
					$this->scope( $arguments ),
					$request,
					sanitize_text_field( wp_unslash( (string) ( $arguments['rationale'] ?? '' ) ) )
				);

				return array_merge( array( 'success' => true ), $result );
			}
		);
	}

	/**
	 * Build a writer bound to the request's registries.
	 *
	 * @param array<string, mixed> $context Execution context.
	 */
	private function writer( array $context ): WP_MCP_AI_Db_Writer {
		$scopes      = new WP_MCP_AI_Db_Scope_Registry();
		$connections = new WP_MCP_AI_Db_Connection_Registry( null, $scopes );
		$factory     = new WP_MCP_AI_Db_Connection_Factory(
			new WP_MCP_AI_Db_Encrypted_Credential_Store(),
			$this->sqlite_roots()
		);
		$catalogue   = new WP_MCP_AI_Db_Catalogue( $factory, new WP_MCP_AI_Db_Transient_Cache() );

		unset( $context );

		return new WP_MCP_AI_Db_Writer(
			$connections,
			$scopes,
			$catalogue,
			$factory,
			new WP_MCP_AI_Db_Audit_Log(),
			new WP_MCP_AI_Db_Proposal_Store(),
			$this->tenant_resolver()
		);
	}
}
