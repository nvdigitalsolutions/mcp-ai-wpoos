<?php
/**
 * `db_apply_write` — apply a proposal a human approved.
 *
 * Proposal 167 phase P6, task 6.3. This tool is the only path that modifies a
 * scoped database, and it can only do so for a proposal that:
 *
 *  - exists, is unexpired, and belongs to the calling principal;
 *  - carries a human `approved_by`;
 *  - was compiled against the scope version still in force;
 *  - still matches its row count, re-checked inside the write transaction.
 *
 * A drift on any of those is a refusal, not a retry.
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
 * Applies an approved proposal.
 */
final class WP_MCP_AI_Tool_Db_Apply_Write extends WP_MCP_AI_Database_Tool_Base {

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
		return 'db_apply_write';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Apply Approved Database Change', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Apply a database change that a human has approved. The row count is re-checked inside the transaction and the call refuses if it changed. Requires the apply_write capability on the scope.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'proposal_id' => array(
					'type'        => 'string',
					'description' => 'The id returned by db_propose_write.',
				),
			),
			'required'             => array( 'proposal_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'requires-capability', 'write', 'state-changing', 'irreversible', 'external-api', 'network-dependent', 'financial-impact' );
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
				$proposal_id = isset( $arguments['proposal_id'] ) ? sanitize_text_field( wp_unslash( (string) $arguments['proposal_id'] ) ) : '';

				$scopes      = new WP_MCP_AI_Db_Scope_Registry();
				$connections = new WP_MCP_AI_Db_Connection_Registry( null, $scopes );
				$factory     = new WP_MCP_AI_Db_Connection_Factory(
					new WP_MCP_AI_Db_Encrypted_Credential_Store(),
					$this->sqlite_roots()
				);

				$writer = new WP_MCP_AI_Db_Writer(
					$connections,
					$scopes,
					new WP_MCP_AI_Db_Catalogue( $factory, new WP_MCP_AI_Db_Transient_Cache() ),
					$factory,
					new WP_MCP_AI_Db_Audit_Log(),
					new WP_MCP_AI_Db_Proposal_Store(),
					$this->tenant_resolver()
				);

				$result = $writer->apply( $this->principal( $context ), $proposal_id );

				return array_merge( array( 'success' => true ), $result );
			}
		);
	}
}
