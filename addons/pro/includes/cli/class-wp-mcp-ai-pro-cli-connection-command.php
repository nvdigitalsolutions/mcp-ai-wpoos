<?php
/**
 * WP-CLI remote connection management commands for NV oOS Pro.
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
 * Manage NV oOS Pro remote site connections from the command line.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_CLI_Connection_Command extends WP_MCP_AI_Pro_CLI_Base_Command {

	/**
	 * List all configured remote site connections.
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
	 * ## EXAMPLES
	 *
	 *     # List all connections as a table.
	 *     $ wp mcp-ai connection list
	 *
	 *     # Export connection IDs only.
	 *     $ wp mcp-ai connection list --format=ids
	 *
	 *     # Export as JSON (credentials redacted).
	 *     $ wp mcp-ai connection list --format=json
	 *
	 * @subcommand list
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function list( $args, $assoc_args ) {
		$this->assert_pro_loaded();

		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			WP_CLI::error( __( 'Remote Site Manager class is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		if ( empty( $connections ) ) {
			WP_CLI::log( __( 'No remote connections configured.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', array_keys( $connections ) ) );
			return;
		}

		$items = array();
		foreach ( $connections as $id => $conn ) {
			$items[] = array(
				'id'        => $id,
				'name'      => $conn['name'] ?? '',
				'url'       => $conn['url'] ?? '',
				'type'      => $conn['type'] ?? '',
				'auth_type' => $conn['auth_type'] ?? '',
				'status'    => $conn['status'] ?? '',
			);
		}

		\WP_CLI\Utils\format_items( $format, $items, array( 'id', 'name', 'url', 'type', 'auth_type', 'status' ) );
	}

	/**
	 * Create a new remote site connection.
	 *
	 * ## OPTIONS
	 *
	 * --name=<name>
	 * : Human-readable label for the connection.
	 *
	 * --remote-url=<url>
	 * : Remote site URL.
	 *
	 * --type=<type>
	 * : Connection type.
	 *
	 * [--auth-type=<type>]
	 * : Authentication method.
	 *
	 * [--api-key=<key>]
	 * : API key credential.
	 *
	 * [--username=<user>]
	 * : Username credential.
	 *
	 * [--password=<pass>]
	 * : Password credential.
	 *
	 * [--token=<token>]
	 * : Token credential.
	 *
	 * [--porcelain]
	 * : Output the new connection ID only.
	 *
	 * ## EXAMPLES
	 *
	 *     # Create a basic connection.
	 *     $ wp mcp-ai connection create --name="Staging" --remote-url="https://staging.example.com" --type="wordpress"
	 *
	 *     # Create with API key auth.
	 *     $ wp mcp-ai connection create --name="API Site" --remote-url="https://api.example.com" --type="rest" --auth-type="api_key" --api-key="sk-abc123"
	 *
	 *     # Capture the new connection ID.
	 *     $ CID=$(wp mcp-ai connection create --name="Prod" --remote-url="https://prod.example.com" --type="wordpress" --porcelain)
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function create( $args, $assoc_args ) {
		$this->assert_pro_loaded();

		$name      = sanitize_text_field( \WP_CLI\Utils\get_flag_value( $assoc_args, 'name', '' ) );
		$url       = esc_url_raw( \WP_CLI\Utils\get_flag_value( $assoc_args, 'remote-url', '' ) );
		$type      = sanitize_key( \WP_CLI\Utils\get_flag_value( $assoc_args, 'type', '' ) );
		$auth_type = sanitize_key( \WP_CLI\Utils\get_flag_value( $assoc_args, 'auth-type', '' ) );
		$porcelain = \WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false );

		if ( '' === $name ) {
			WP_CLI::error( __( 'Please provide a --name for the connection.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( '' === $url ) {
			WP_CLI::error( __( 'Please provide a --remote-url for the connection.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( '' === $type ) {
			WP_CLI::error( __( 'Please provide a --type for the connection.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			WP_CLI::error( __( 'Remote Site Manager class is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$connection_data = array(
			'name' => $name,
			'url'  => $url,
			'type' => $type,
		);

		if ( '' !== $auth_type ) {
			$connection_data['auth_type'] = $auth_type;
		}

		$api_key  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'api-key', '' );
		$username = \WP_CLI\Utils\get_flag_value( $assoc_args, 'username', '' );
		$password = \WP_CLI\Utils\get_flag_value( $assoc_args, 'password', '' );
		$token    = \WP_CLI\Utils\get_flag_value( $assoc_args, 'token', '' );

		if ( '' !== $api_key ) {
			$connection_data['api_key'] = $api_key;
		}
		if ( '' !== $username ) {
			$connection_data['username'] = sanitize_text_field( $username );
		}
		if ( '' !== $password ) {
			$connection_data['password'] = $password;
		}
		if ( '' !== $token ) {
			$connection_data['token'] = $token;
		}

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( $porcelain ) {
			WP_CLI::line( $result );
			return;
		}

		/* translators: 1: connection name, 2: connection ID */
		WP_CLI::success( sprintf( __( 'Created connection "%1$s" (ID: %2$s).', 'mcp-ai-wpoos-pro' ), $name, $result ) );
	}

	/**
	 * Get details for a single remote connection (credentials redacted).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The connection ID.
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
	 *     # Show details for a connection.
	 *     $ wp mcp-ai connection get my_site
	 *
	 *     # Dump as JSON.
	 *     $ wp mcp-ai connection get my_site --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function get( $args, $assoc_args ) {
		$this->assert_pro_loaded();

		$id     = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';
		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( ! $id ) {
			WP_CLI::error( __( 'Please provide a connection ID.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			WP_CLI::error( __( 'Remote Site Manager class is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$conn = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );

		if ( null === $conn ) {
			/* translators: %s: connection ID */
			WP_CLI::error( sprintf( __( 'Connection "%s" not found.', 'mcp-ai-wpoos-pro' ), $id ) );
		}

		// Redact sensitive fields.
		$conn = $this->redact_connection( $conn );

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $conn, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( 'yaml' === $format ) {
			foreach ( $conn as $k => $v ) {
				WP_CLI::line( "{$k}: " . ( is_scalar( $v ) ? $v : wp_json_encode( $v ) ) );
			}
			return;
		}

		$items = array();
		foreach ( $conn as $k => $v ) {
			$items[] = array(
				'field' => $k,
				'value' => is_scalar( $v ) ? (string) $v : wp_json_encode( $v ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $items, array( 'field', 'value' ) );
	}

	/**
	 * Test a remote connection.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The connection ID to test.
	 *
	 * ## EXAMPLES
	 *
	 *     # Test a connection.
	 *     $ wp mcp-ai connection test my_site
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function test( $args, $assoc_args ) {
		$this->assert_pro_loaded();

		$id = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';

		if ( ! $id ) {
			WP_CLI::error( __( 'Please provide a connection ID.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			WP_CLI::error( __( 'Remote Site Manager class is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$conn = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );

		if ( null === $conn ) {
			/* translators: %s: connection ID */
			WP_CLI::error( sprintf( __( 'Connection "%s" not found.', 'mcp-ai-wpoos-pro' ), $id ) );
		}

		/* translators: %s: connection name */
		WP_CLI::log( sprintf( __( 'Testing connection "%s"…', 'mcp-ai-wpoos-pro' ), $conn['name'] ?? $id ) );

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $conn );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$status = is_array( $result ) ? ( $result['status'] ?? 'unknown' ) : ( true === $result ? 'success' : 'failed' );

		if ( in_array( $status, array( 'success', 'connected', 'ok' ), true ) || true === $result ) {
			/* translators: %s: connection ID */
			WP_CLI::success( sprintf( __( 'Connection "%s" is reachable.', 'mcp-ai-wpoos-pro' ), $id ) );
		} else {
			$message = is_array( $result ) ? ( $result['message'] ?? $status ) : $status;
			/* translators: 1: connection ID, 2: status message */
			WP_CLI::error( sprintf( __( 'Connection "%1$s" test failed: %2$s', 'mcp-ai-wpoos-pro' ), $id, $message ) );
		}
	}

	/**
	 * Delete a remote connection.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The connection ID to delete.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Delete a connection.
	 *     $ wp mcp-ai connection delete my_site --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function delete( $args, $assoc_args ) {
		$this->assert_pro_loaded();

		$id  = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';
		$yes = \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false );

		if ( ! $id ) {
			WP_CLI::error( __( 'Please provide a connection ID.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			WP_CLI::error( __( 'Remote Site Manager class is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$conn = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );

		if ( null === $conn ) {
			/* translators: %s: connection ID */
			WP_CLI::error( sprintf( __( 'Connection "%s" not found.', 'mcp-ai-wpoos-pro' ), $id ) );
		}

		if ( ! $yes ) {
			/* translators: 1: connection name, 2: connection ID */
			WP_CLI::confirm( sprintf( __( 'Delete connection "%1$s" (%2$s)?', 'mcp-ai-wpoos-pro' ), $conn['name'] ?? $id, $id ) );
		}

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::delete_connection( $id );

		if ( ! $result ) {
			/* translators: %s: connection ID */
			WP_CLI::error( sprintf( __( 'Failed to delete connection "%s".', 'mcp-ai-wpoos-pro' ), $id ) );
		}

		/* translators: %s: connection ID */
		WP_CLI::success( sprintf( __( 'Connection "%s" deleted.', 'mcp-ai-wpoos-pro' ), $id ) );
	}

	/**
	 * Strip credential fields from a connection array before displaying.
	 *
	 * @param array $conn Connection data.
	 * @return array Redacted connection data.
	 */
	protected function redact_connection( $conn ) {
		$sensitive_keys = array( 'password', 'token', 'consumer_key', 'consumer_secret', 'api_key', 'api_secret', 'client_secret', 'refresh_token', 'bot_token', 'secret_token' );
		foreach ( $sensitive_keys as $k ) {
			if ( isset( $conn[ $k ] ) && '' !== $conn[ $k ] ) {
				$conn[ $k ] = '[REDACTED]';
			}
		}
		return $conn;
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai connection', 'WP_MCP_AI_Pro_CLI_Connection_Command' );
}
