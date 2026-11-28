<?php
/**
 * `db_test_connection` — probe and record health.
 *
 * Proposal 167 amendment A1 and phase P1 task 1.12. This is an operator tool, not
 * an assistant capability: it requires `manage_options`, because it opens a
 * connection with a stored credential and discloses role privileges.
 *
 * The probe is what makes the executor willing to serve a connection at all, so
 * this tool is the supported way to fix `db_connection_unavailable` after editing
 * a record. For a native connection the result is persisted; for a Remote Sites
 * record it is returned but not written, because that store owns its own shape.
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

use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;

/**
 * Probes a connection.
 */
final class WP_MCP_AI_Tool_Db_Test_Connection extends WP_MCP_AI_Database_Tool_Base {

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'db_test_connection';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Test Database Connection', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Open a configured database connection, report its health, and report the role it authenticates as, whether that role can write, and whether it bypasses row-level security. Administrator only.', 'mcp-ai-wpoos-pro' );
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
					'description' => 'Connection id. The id from db_list_connections, or a Remote Sites id prefixed "rs:".',
				),
			),
			'required'             => array( 'connection' ),
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
		return array( 'pro', 'requires-capability', 'state-changing', 'external-api', 'network-dependent' );
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
				$id = isset( $arguments['connection'] ) ? strtolower( sanitize_text_field( wp_unslash( (string) $arguments['connection'] ) ) ) : '';

				if ( '' === $id ) {
					throw \Nvoos\Database\Domain\Service\Database\DatabaseRefusal::of(
						\Nvoos\Database\Domain\Service\Database\RefusalCode::UNKNOWN_CONNECTION,
						'A connection id is required.'
					);
				}

				$registry = new WP_MCP_AI_Db_Connection_Registry();
				$resolver = new WP_MCP_AI_Connection_Resolver();

				$resolver->register( new WP_MCP_AI_Native_Connection_Provider( $registry ) );

				if ( class_exists( 'WP_MCP_AI_Remote_Site_Connection_Provider' ) ) {
					$remote = new WP_MCP_AI_Remote_Site_Connection_Provider();

					if ( $remote->is_available() ) {
						$resolver->register( $remote );
					}
				}

				$descriptor = $resolver->resolve( $id, $this->principal( $context ) );
				$probe      = $this->probe( $descriptor, $registry );

				return array(
					'success'    => true,
					'connection' => $descriptor->id,
					'kind'       => $descriptor->kind,
					'probe'      => $probe->toArray(),
					'persisted'  => ! str_contains( $descriptor->id, ':' ),
					'guidance'   => $this->guidance( $probe->canWrite, $probe->bypassesRls, $probe->reachable ),
				);
			}
		);
	}

	/**
	 * Probe a descriptor, persisting when it is a native record.
	 *
	 * @param ConnectionDescriptor             $descriptor Descriptor.
	 * @param WP_MCP_AI_Db_Connection_Registry $registry  Native registry.
	 */
	private function probe( ConnectionDescriptor $descriptor, WP_MCP_AI_Db_Connection_Registry $registry ): \Nvoos\Database\Domain\ValueObject\Database\ProbeResult {
		$factory = new WP_MCP_AI_Db_Connection_Factory(
			new WP_MCP_AI_Db_Encrypted_Credential_Store(),
			$this->sqlite_roots()
		);

		$probe = ( new WP_MCP_AI_Db_Probe( $factory ) )->run( $descriptor );

		if ( ! str_contains( $descriptor->id, ':' ) ) {
			$registry->record_probe( $descriptor->id, $probe );
			( new WP_MCP_AI_Db_Catalogue( $factory, new WP_MCP_AI_Db_Transient_Cache() ) )->invalidate( $descriptor );
		}

		return $probe;
	}

	/**
	 * Operator-facing guidance from the probe result.
	 *
	 * @param bool $can_write   Whether the role can write.
	 * @param bool $bypasses    Whether the role bypasses RLS.
	 * @param bool $reachable   Whether the probe connected.
	 * @return list<string>
	 */
	private function guidance( bool $can_write, bool $bypasses, bool $reachable ): array {
		$notes = array();

		if ( ! $reachable ) {
			$notes[] = __( 'The connection could not be opened. Check the host, port, database name, credential and network access.', 'mcp-ai-wpoos-pro' );
		}

		if ( $can_write ) {
			$notes[] = __( 'This role can write. Read-only scopes will refuse this connection until a SELECT-only role is used.', 'mcp-ai-wpoos-pro' );
		}

		if ( $bypasses ) {
			$notes[] = __( 'This role bypasses row-level security. Tenant-filtered scopes will refuse this connection.', 'mcp-ai-wpoos-pro' );
		}

		if ( array() === $notes ) {
			$notes[] = __( 'The connection is reachable and safe for read-only scopes.', 'mcp-ai-wpoos-pro' );
		}

		return $notes;
	}
}
