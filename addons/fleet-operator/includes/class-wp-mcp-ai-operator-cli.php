<?php
/**
 * WP-CLI commands for the Fleet Operator addon.
 *
 * Registers `wp mcp-ai operator <create|list|revoke|config>` alongside the
 * base plugin's existing `wp mcp-ai credential` commands.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CLI management for external-operator credentials.
 */
class WP_MCP_AI_Operator_CLI {

	/**
	 * Register the WP-CLI subcommand.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		WP_CLI::add_command( 'mcp-ai operator', __CLASS__ );
	}

	/**
	 * Create an operator credential.
	 *
	 * ## OPTIONS
	 *
	 * <label>
	 * : Human-readable label for the operator (e.g. "Hermes").
	 *
	 * [--user=<id>]
	 * : WordPress user ID the operator acts as. Default: 1.
	 *
	 * [--tools=<csv>]
	 * : Comma-separated tool slugs, globs, or group:<toolkit> entries.
	 *
	 * [--mode=<mode>]
	 * : read or readwrite. Default: readwrite.
	 *
	 * [--expires=<days>]
	 * : Validity in days; 0 = never. Default: 90.
	 *
	 * [--rate-limit=<n>]
	 * : Requests per minute. Default: 60.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function create( $args, $assoc_args ) {
		$label   = isset( $args[0] ) ? $args[0] : '';
		$user_id = isset( $assoc_args['user'] ) ? absint( $assoc_args['user'] ) : 1;
		$mode    = isset( $assoc_args['mode'] ) ? $assoc_args['mode'] : 'readwrite';
		$expires = isset( $assoc_args['expires'] ) ? absint( $assoc_args['expires'] ) : 90;
		$limit   = isset( $assoc_args['rate-limit'] ) ? absint( $assoc_args['rate-limit'] ) : 60;
		$tools   = isset( $assoc_args['tools'] ) ? array_map( 'trim', explode( ',', $assoc_args['tools'] ) ) : array();

		$created = WP_MCP_AI_Operator_Credential_Repository::create( $label, $user_id, $tools, $mode, $expires, $limit );
		if ( is_wp_error( $created ) ) {
			WP_CLI::error( $created->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Operator %s created.', $created['record']['id'] ) );
		WP_CLI::line( 'Token (shown once): ' . $created['token'] );

		$generated = WP_MCP_AI_Operator_Config_Generator::generate_for_site(
			$created['record']['label'],
			untrailingslashit( home_url( '/' ) ),
			$created['token'],
			$created['record']['allowed_tools']
		);
		WP_CLI::line( "--- ~/.hermes/.env ---\n" . $generated['env'] );
		WP_CLI::line( "--- ~/.hermes/config.yaml ---\n" . $generated['yaml'] );
	}

	/**
	 * List operator credentials.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function list( $args, $assoc_args ) {
		unset( $args, $assoc_args );

		$records = WP_MCP_AI_Operator_Credential_Repository::get_all();
		if ( empty( $records ) ) {
			WP_CLI::line( 'No external operators.' );
			return;
		}

		$rows = array();
		foreach ( $records as $record ) {
			$rows[] = array(
				'ID'       => $record['id'],
				'Label'    => $record['label'],
				'Mode'     => $record['mode'],
				'Tools'    => count( $record['allowed_tools'] ),
				'Status'   => $record['status'],
				'Expires'  => empty( $record['expires_at'] ) ? 'never' : gmdate( 'Y-m-d', $record['expires_at'] ),
				'LastUsed' => empty( $record['last_used_at'] ) ? 'never' : gmdate( 'Y-m-d H:i', $record['last_used_at'] ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'ID', 'Label', 'Mode', 'Tools', 'Status', 'Expires', 'LastUsed' ) );
	}

	/**
	 * Revoke an operator credential (kill switch).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Operator identifier (op_xxxx).
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function revoke( $args, $assoc_args ) {
		unset( $assoc_args );

		$id      = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';
		$revoked = WP_MCP_AI_Operator_Credential_Repository::revoke( $id );

		if ( $revoked ) {
			WP_CLI::success( 'Operator revoked.' );
		} else {
			WP_CLI::error( 'Operator not found.' );
		}
	}

	/**
	 * Print the Hermes config fragments for an operator.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Operator identifier (op_xxxx).
	 *
	 * [--token=<token>]
	 * : Full token (op_xxxx.SECRET) required because secrets are stored hashed.
	 *
	 * [--site=<url>]
	 * : Site URL. Default: home_url().
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function config( $args, $assoc_args ) {
		$id    = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';
		$token = isset( $assoc_args['token'] ) ? sanitize_text_field( $assoc_args['token'] ) : '';
		$site  = isset( $assoc_args['site'] ) ? esc_url_raw( $assoc_args['site'] ) : untrailingslashit( home_url( '/' ) );

		$record = WP_MCP_AI_Operator_Credential_Repository::get( $id );
		if ( null === $record ) {
			WP_CLI::error( 'Operator not found.' );
		}
		if ( '' === $token ) {
			WP_CLI::error( 'Pass the full token with --token=<op_xxxx.SECRET>. Secrets are stored hashed and cannot be re-displayed.' );
		}

		$generated = WP_MCP_AI_Operator_Config_Generator::generate_for_site( $record['label'], $site, $token, $record['allowed_tools'] );
		WP_CLI::line( "--- ~/.hermes/.env ---\n" . $generated['env'] );
		WP_CLI::line( "--- ~/.hermes/config.yaml ---\n" . $generated['yaml'] );
	}
}
