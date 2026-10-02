<?php
/**
 * WP-CLI remote site commands for NV oOS Pro.
 *
 * Read-only view over the Pro Remote Site Manager and the mesh peer roster.
 * Writes (create/update/delete) are owned by `wp mcp-ai connection`.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CLI
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-pro-cli-base-command.php';

/**
 * Inspect NV oOS Pro remote sites and mesh peers from the command line.
 *
 * Complements `wp mcp-ai connection` (which owns generic connection CRUD)
 * by surfacing the Remote Site Manager's stored health/status data and the
 * mesh peer roster. All subcommands are read-only.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_CLI_Remote_Site_Command extends WP_MCP_AI_Pro_CLI_Base_Command {

	/**
	 * List all remote sites with their stored health status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 *   - ids
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Limit the output to specific fields.
	 *
	 * [--field=<field>]
	 * : Print the value of a single field.
	 *
	 * ## EXAMPLES
	 *
	 *     # List all remote sites.
	 *     $ wp mcp-ai remote-site list
	 *
	 *     # Export as JSON.
	 *     $ wp mcp-ai remote-site list --format=json
	 *
	 *     # Show only name and health columns.
	 *     $ wp mcp-ai remote-site list --fields=name,health
	 *
	 * @subcommand list
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function list( $args, $assoc_args ) {
		$this->assert_pro_loaded();

		$format = $this->get_format( $assoc_args, 'table' );

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			WP_CLI::error( __( 'Remote Site Manager class is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		if ( empty( $connections ) ) {
			WP_CLI::log( __( 'No remote sites configured.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', array_keys( $connections ) ) );
			return;
		}

		$items = array();
		foreach ( $connections as $id => $conn ) {
			$health = WP_MCP_AI_Pro_Remote_Site_Manager::get_health_metrics( $id );

			$items[] = array(
				'id'              => $id,
				'name'            => $conn['name'] ?? '',
				'url'             => $conn['url'] ?? '',
				'connection_type' => $conn['connection_type'] ?? '',
				'auth_type'       => $conn['auth_type'] ?? '',
				'health'          => $health['status'],
			);
		}

		$fields = $this->get_fields(
			$assoc_args,
			array( 'id', 'name', 'url', 'connection_type', 'auth_type', 'health' )
		);

		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}

	/**
	 * Get details for a single remote site (credentials redacted).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The remote site connection ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Show details for a remote site.
	 *     $ wp mcp-ai remote-site get conn_abc123
	 *
	 *     # Dump as JSON.
	 *     $ wp mcp-ai remote-site get conn_abc123 --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function get( $args, $assoc_args ) {
		$this->assert_pro_loaded();

		$id     = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';
		$format = $this->get_format( $assoc_args, 'table' );

		if ( '' === $id ) {
			WP_CLI::error( __( 'Please provide a remote site connection ID.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			WP_CLI::error( __( 'Remote Site Manager class is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$conn = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );

		if ( null === $conn ) {
			/* translators: %s: remote site connection ID */
			WP_CLI::error( sprintf( __( 'Remote site "%s" not found.', 'mcp-ai-wpoos-pro' ), $id ) );
		}

		$data = array();
		foreach ( $conn as $key => $value ) {
			// Skip internal bookkeeping flags (e.g. _api_key_encrypted).
			if ( 0 === strpos( (string) $key, '_' ) ) {
				continue;
			}

			// Redact credentials using the manager's own credential vocabulary.
			$data[ $key ] = WP_MCP_AI_Pro_Remote_Site_Manager::is_credential_field( (string) $key )
				? '[REDACTED]'
				: $value;
		}

		// Append the stored health/status summary.
		$health         = WP_MCP_AI_Pro_Remote_Site_Manager::get_health_metrics( $id );
		$data['health'] = $health['status'];

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( 'yaml' === $format ) {
			foreach ( $data as $key => $value ) {
				WP_CLI::line( "{$key}: " . ( is_scalar( $value ) ? $value : wp_json_encode( $value ) ) );
			}
			return;
		}

		$items = array();
		foreach ( $data as $key => $value ) {
			$items[] = array(
				'field' => $key,
				'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $items, array( 'field', 'value' ) );
	}

	/**
	 * Test a remote site connection (read-only connectivity check).
	 *
	 * Runs the Remote Site Manager's connectivity probe and reports the
	 * stored health/status metrics alongside the live result.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The remote site connection ID.
	 *
	 * ## EXAMPLES
	 *
	 *     # Test a remote site connection.
	 *     $ wp mcp-ai remote-site test conn_abc123
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function test( $args, $assoc_args ) {
		$this->assert_pro_loaded();

		$id = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';

		if ( '' === $id ) {
			WP_CLI::error( __( 'Please provide a remote site connection ID.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			WP_CLI::error( __( 'Remote Site Manager class is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$conn = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );

		if ( null === $conn ) {
			/* translators: %s: remote site connection ID */
			WP_CLI::error( sprintf( __( 'Remote site "%s" not found.', 'mcp-ai-wpoos-pro' ), $id ) );
		}

		// Report the stored health/status field first so it survives a failed probe.
		$health = WP_MCP_AI_Pro_Remote_Site_Manager::get_health_metrics( $id );
		/* translators: %s: stored health status */
		WP_CLI::log( sprintf( __( 'Stored health: %s', 'mcp-ai-wpoos-pro' ), $health['status'] ) );

		/* translators: 1: remote site name, 2: remote site connection ID */
		WP_CLI::log(
			sprintf(
				__( 'Testing remote site "%1$s" (%2$s)…', 'mcp-ai-wpoos-pro' ),
				$conn['name'] ?? $id,
				$id
			)
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $id );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$success = is_array( $result ) && ! empty( $result['success'] );
		$message = is_array( $result ) && ! empty( $result['message'] ) ? $result['message'] : '';

		if ( $success ) {
			if ( '' !== $message ) {
				WP_CLI::success( $message );
			} else {
				/* translators: %s: remote site connection ID */
				WP_CLI::success( sprintf( __( 'Remote site "%s" is reachable.', 'mcp-ai-wpoos-pro' ), $id ) );
			}
		} elseif ( '' !== $message ) {
			WP_CLI::error( $message );
		} else {
			/* translators: %s: remote site connection ID */
			WP_CLI::error( sprintf( __( 'Remote site "%s" test failed.', 'mcp-ai-wpoos-pro' ), $id ) );
		}
	}

	/**
	 * List mesh peers.
	 *
	 * Reads the `mesh_peer_sites` roster that the bidirectional sync class
	 * keeps in sync with mesh_peer remote site connections, and cross-references
	 * each peer with its remote site connection ID.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 *   - ids
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Limit the output to specific fields.
	 *
	 * [--field=<field>]
	 * : Print the value of a single field.
	 *
	 * ## EXAMPLES
	 *
	 *     # List all mesh peers.
	 *     $ wp mcp-ai remote-site peer-list
	 *
	 *     # Export as JSON.
	 *     $ wp mcp-ai remote-site peer-list --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function peer_list( $args, $assoc_args ) {
		$this->assert_pro_loaded();

		$format = $this->get_format( $assoc_args, 'table' );

		$peers = $this->get_mesh_peers();

		if ( empty( $peers ) ) {
			WP_CLI::log( __( 'No mesh peers configured.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		// Cross-reference mesh_peer remote site connections by URL.
		$mesh_connections = array();
		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			foreach ( WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections() as $conn_id => $conn ) {
				if ( isset( $conn['connection_type'] ) && 'mesh_peer' === $conn['connection_type'] && ! empty( $conn['url'] ) ) {
					$mesh_connections[ $conn['url'] ] = $conn_id;
				}
			}
		}

		$items = array();
		foreach ( $peers as $peer ) {
			$url = isset( $peer['url'] ) ? $peer['url'] : '';

			$items[] = array(
				'id'            => 'mesh_' . md5( (string) $url ),
				'name'          => isset( $peer['name'] ) ? $peer['name'] : '',
				'url'           => $url,
				'api_key'       => empty( $peer['api_key'] ) ? '' : '[REDACTED]',
				'connection_id' => isset( $mesh_connections[ $url ] ) ? $mesh_connections[ $url ] : '',
			);
		}

		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', wp_list_pluck( $items, 'id' ) ) );
			return;
		}

		$fields = $this->get_fields(
			$assoc_args,
			array( 'id', 'name', 'url', 'api_key', 'connection_id' )
		);

		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}

	/**
	 * Read the mesh peer roster from plugin settings.
	 *
	 * The bidirectional sync class owns sync (writes) but exposes no read
	 * method; the canonical peer list is the `mesh_peer_sites` settings entry
	 * it keeps in sync, so read it from there.
	 *
	 * @return array Mesh peers (each with name/url/api_key).
	 */
	private function get_mesh_peers() {
		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			return array();
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$peers    = isset( $settings['mesh_peer_sites'] ) && is_array( $settings['mesh_peer_sites'] )
			? $settings['mesh_peer_sites']
			: array();

		$peers = array_filter( $peers, 'is_array' );

		return array_values( $peers );
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai remote-site', 'WP_MCP_AI_Pro_CLI_Remote_Site_Command' );
}
