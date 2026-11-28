<?php
/**
 * `db_audit_tail` — the audit trail.
 *
 * Proposal 167 phase P3 task 3.4. Administrator-only, because the trail is
 * cross-assistant: it shows which principal asked which connection for what, and
 * a refusal pattern is itself sensitive (it reveals which relations exist).
 *
 * Records never carry bound values, so this tool is safe to show in full; the
 * capability gate is about estate visibility, not about the row contents.
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
 * Reads the database audit log.
 */
final class WP_MCP_AI_Tool_Db_Audit_Tail extends WP_MCP_AI_Database_Tool_Base {

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'db_audit_tail';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Tail Database Audit Log', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Return the most recent database audit records, newest first, with optional filters. Records contain the statement template and a fingerprint, never bound values. Administrator only.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'limit'        => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 200,
					'default' => 50,
				),
				'connection'   => array(
					'type' => 'string',
				),
				'scope'        => array(
					'type' => 'string',
				),
				'action'       => array(
					'type' => 'string',
					'enum' => array( 'describe', 'select', 'aggregate', 'search', 'raw_sql', 'explain', 'explain_analyze', 'export' ),
				),
				'refusal_code' => array(
					'type'        => 'string',
					'description' => 'A refusal code, e.g. db_scope_table_not_allowed.',
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'requires-capability', 'read-only', 'local-only' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $arguments Sanitised tool arguments.
	 * @param array<string, mixed> $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		unset( $context );

		return $this->attempt(
			function () use ( $arguments ) {
				$filters = array();

				foreach ( array( 'connection', 'scope', 'action', 'refusal_code' ) as $key ) {
					if ( isset( $arguments[ $key ] ) && '' !== trim( (string) $arguments[ $key ] ) ) {
						$filters[ $key ] = sanitize_text_field( wp_unslash( (string) $arguments[ $key ] ) );
					}
				}

				$limit   = isset( $arguments['limit'] ) ? min( 200, max( 1, absint( $arguments['limit'] ) ) ) : 50;
				$log     = new WP_MCP_AI_Db_Audit_Log();
				$records = $log->tail( $limit, $filters );

				return array(
					'success'  => true,
					'records'  => $records,
					'count'    => count( $records ),
					'summary'  => $log->summary( 3600 ),
					'retained' => WP_MCP_AI_Db_Audit_Log::MAX_RECORDS,
				);
			}
		);
	}
}
