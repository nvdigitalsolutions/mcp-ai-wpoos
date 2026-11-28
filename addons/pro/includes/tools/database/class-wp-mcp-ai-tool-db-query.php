<?php
/**
 * `db_query` — operator-authored SQL, behind its own tier.
 *
 * Proposal 167 decision D3 and phase P5. Default off by tier: a scope must hold
 * `raw_sql` before this tool can do anything. Even then the statement is:
 *
 *  - admitted by {@see \Nvoos\Database\Domain\Service\Database\AdmissionClassifier}
 *    (`SELECT`/`WITH … SELECT` only, no `SET ROLE`, no file functions);
 *  - executed on a prepared statement inside `BEGIN READ ONLY`;
 *  - run by a role that cannot write, on a connection whose probe proved it.
 *
 * Three independent reasons a write cannot happen, only one of which is lexical.
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

use Nvoos\Database\Domain\ValueObject\Database\RawQuery;

/**
 * Executes an admitted raw SELECT.
 */
final class WP_MCP_AI_Tool_Db_Query extends WP_MCP_AI_Database_Tool_Base {

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'db_query';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Run Database Query', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Run one read-only SQL SELECT (or WITH … SELECT) against a scoped connection. Only available when the scope grants raw SQL. One statement per call; placeholders are required for every value.', 'mcp-ai-wpoos-pro' );
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
					'sql'    => array(
						'type'        => 'string',
						'maxLength'   => 8000,
						'description' => 'A single SELECT or WITH … SELECT statement using :name placeholders.',
					),
					'params' => array(
						'type'        => 'object',
						'description' => 'Placeholder values keyed by :name. Every placeholder in the statement must be supplied.',
					),
				)
			),
			'required'             => array( 'sql' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'requires-capability', 'read-only', 'external-api', 'network-dependent', 'non-deterministic' );
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

				$sql = isset( $arguments['sql'] ) ? trim( (string) wp_unslash( $arguments['sql'] ) ) : '';

				$params = array();

				foreach ( (array) ( $arguments['params'] ?? array() ) as $key => $value ) {
					$name = sanitize_key( (string) $key );

					if ( '' === $name ) {
						continue;
					}

					$params[ ':' . $name ] = $this->filter_value( $value );
				}

				$result = $this->executor( $context )->query( $connection, $scope, new RawQuery( $sql, $params ) );

				return $this->envelope( $result, array( 'connection' => $connection ) );
			}
		);
	}
}
