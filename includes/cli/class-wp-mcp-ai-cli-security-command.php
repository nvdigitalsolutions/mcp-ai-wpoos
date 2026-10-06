<?php
/**
 * WP-CLI security commands for NV oOS.
 *
 * Operator surface for the security infrastructure: posture scoring, audit
 * log, destructive-ops gate status, and API-key store status. Read-only
 * except `purge-audit`.
 *
 * @package WP_MCP_AI
 * @subpackage CLI
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-cli-base-command.php';

/**
 * Inspect the NV oOS security posture from the command line.
 *
 * ## EXAMPLES
 *
 *     # Print the security posture score and every signal.
 *     $ wp mcp-ai security posture
 *
 *     # Tail the security audit log.
 *     $ wp mcp-ai security audit --per-page=10
 *
 * @since 1.2.0
 */
class WP_MCP_AI_CLI_Security_Command extends WP_MCP_AI_CLI_Base_Command {

	/**
	 * Render the security posture report (score, grade, signals).
	 *
	 * ## OPTIONS
	 *
	 * [--refresh]
	 * : Bypass the cached report and re-evaluate every signal.
	 *
	 * [--format=<format>]
	 * : Output format for the signal table.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated signal columns (default: id,label,status,weight).
	 *
	 * ## EXAMPLES
	 *
	 *     # Cached report.
	 *     $ wp mcp-ai security posture
	 *
	 *     # Re-evaluate every signal.
	 *     $ wp mcp-ai security posture --refresh
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function posture( $args, $assoc_args ) {
		$refresh = \WP_CLI\Utils\get_flag_value( $assoc_args, 'refresh', false );
		$format  = $this->get_format( $assoc_args );
		$fields  = $this->get_fields( $assoc_args, array( 'id', 'label', 'status', 'weight' ) );

		if ( ! class_exists( 'WP_MCP_AI_Security_Posture' ) ) {
			WP_CLI::error( __( 'Security posture service is not available.', 'mcp-ai-wpoos' ) );
		}

		$posture = new WP_MCP_AI_Security_Posture();
		$report  = $posture->get_report( (bool) $refresh );

		WP_CLI::line(
			sprintf(
				/* translators: 1: numeric score, 2: letter grade, 3: ISO timestamp */
				__( 'Posture score: %1$d/100 (%2$s) — computed %3$s', 'mcp-ai-wpoos' ),
				(int) $report['score'],
				$report['grade'],
				$report['computed_at']
			)
		);

		$items = array();
		foreach ( $report['signals'] as $signal ) {
			$items[] = array(
				'id'     => $signal['id'],
				'label'  => $signal['label'],
				'status' => ! empty( $signal['passed'] ) ? __( 'passed', 'mcp-ai-wpoos' ) : __( 'failed', 'mcp-ai-wpoos' ),
				'weight' => (int) $signal['weight'],
				'detail' => $signal['detail'],
			);
		}

		if ( 'table' === $format && ! in_array( 'detail', $fields, true ) && ! empty( $report['quick_wins'] ) ) {
			WP_CLI::line( '' );
			WP_CLI::line( __( 'Quick wins (top unmet signals):', 'mcp-ai-wpoos' ) );
			foreach ( $report['quick_wins'] as $win ) {
				WP_CLI::line( sprintf( '  - %s', $win['label'] ) );
			}
		}

		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}

	/**
	 * List security audit events (newest first).
	 *
	 * ## OPTIONS
	 *
	 * [--page=<number>]
	 * : Page of results. Default: 1.
	 *
	 * [--per-page=<number>]
	 * : Events per page. Default: 20.
	 *
	 * [--event-type=<type>]
	 * : Filter by event type.
	 *
	 * [--user-id=<id>]
	 * : Filter by WordPress user ID.
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
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated columns (default: id,event_type,user_id,event_time).
	 *
	 * ## EXAMPLES
	 *
	 *     # Latest 20 events.
	 *     $ wp mcp-ai security audit
	 *
	 *     # Filter to failed-login events as JSON.
	 *     $ wp mcp-ai security audit --event-type=failed_login --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function audit( $args, $assoc_args ) {
		$format = $this->get_format( $assoc_args );
		$fields = $this->get_fields( $assoc_args, array( 'id', 'event_type', 'user_id', 'ip_address', 'event_time' ) );

		if ( ! class_exists( 'WP_MCP_AI_Security_Audit_Logger' ) ) {
			WP_CLI::error( __( 'Security audit logger is not available.', 'mcp-ai-wpoos' ) );
		}

		// Reuse the canonical REST query (defaults are supplied here because
		// the route schema normally injects them).
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/security/audit-events' );
		$request->set_param( 'per_page', absint( \WP_CLI\Utils\get_flag_value( $assoc_args, 'per-page', 20 ) ) );
		$request->set_param( 'page', max( 1, absint( \WP_CLI\Utils\get_flag_value( $assoc_args, 'page', 1 ) ) ) );

		$event_type = \WP_CLI\Utils\get_flag_value( $assoc_args, 'event-type', '' );
		if ( '' !== $event_type ) {
			$request->set_param( 'event_type', sanitize_key( $event_type ) );
		}

		$user_id = absint( \WP_CLI\Utils\get_flag_value( $assoc_args, 'user-id', 0 ) );
		if ( $user_id > 0 ) {
			$request->set_param( 'user_id', $user_id );
		}

		$response = WP_MCP_AI_Security_Audit_Logger::get_events( $request );

		if ( is_wp_error( $response ) ) {
			WP_CLI::error( $response->get_error_message() );
		}

		$data = $response->get_data();

		if ( empty( $data['events'] ) ) {
			WP_CLI::log( __( 'No audit events found.', 'mcp-ai-wpoos' ) );
			return;
		}

		$items = array();
		foreach ( $data['events'] as $event ) {
			$items[] = array(
				'id'         => (int) $event['id'],
				'event_type' => $event['event_type'],
				'user_id'    => (int) $event['user_id'],
				'ip_address' => $event['ip_address'],
				'event_time' => $event['event_time'],
				'details'    => is_array( $event['details'] ) ? wp_json_encode( $event['details'] ) : (string) $event['details'],
			);
		}

		\WP_CLI\Utils\format_items( $format, $items, $fields );

		/* translators: 1: current page, 2: total pages, 3: total events */
		WP_CLI::log( sprintf( __( 'Page %1$d/%2$d — %3$d total event(s).', 'mcp-ai-wpoos' ), (int) $data['page'], (int) $data['total_pages'], (int) $data['total'] ) );
	}

	/**
	 * Purge audit events older than the retention window.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Purge expired audit events.
	 *     $ wp mcp-ai security purge-audit --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function purge_audit( $args, $assoc_args ) {
		$this->require_capability( 'manage_options' );

		if ( ! class_exists( 'WP_MCP_AI_Security_Audit_Logger' ) ) {
			WP_CLI::error( __( 'Security audit logger is not available.', 'mcp-ai-wpoos' ) );
		}

		if ( ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false ) ) {
			WP_CLI::confirm( __( 'Purge audit events older than the retention window?', 'mcp-ai-wpoos' ) );
		}

		WP_MCP_AI_Security_Audit_Logger::purge_old_events();
		WP_CLI::success( __( 'Expired audit events purged.', 'mcp-ai-wpoos' ) );
	}

	/**
	 * Show the destructive-ops confirmation gate status.
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
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai security gate
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function gate( $args, $assoc_args ) {
		$format = $this->get_format( $assoc_args );
		$items  = array(
			array(
				'property' => __( 'Confirmation required for destructive tool calls', 'mcp-ai-wpoos' ),
				'value'    => $this->destructive_ops_enabled() ? __( 'enabled', 'mcp-ai-wpoos' ) : __( 'disabled', 'mcp-ai-wpoos' ),
			),
		);

		\WP_CLI\Utils\format_items( $format, $items, array( 'property', 'value' ) );
	}

	/**
	 * Show API-key store status (encryption coverage, plaintext leftovers).
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
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated columns (default: key,label,status).
	 *
	 * ## EXAMPLES
	 *
	 *     # Show which provider keys are encrypted at rest.
	 *     $ wp mcp-ai security keys
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function keys( $args, $assoc_args ) {
		$format = $this->get_format( $assoc_args );
		$fields = $this->get_fields( $assoc_args, array( 'key', 'label', 'status' ) );

		if ( ! class_exists( 'WP_MCP_AI_Api_Key_Store' ) ) {
			WP_CLI::error( __( 'API key store is not available.', 'mcp-ai-wpoos' ) );
		}

		$plaintext = WP_MCP_AI_Api_Key_Store::find_remaining_plaintext();

		$items = array();
		foreach ( WP_MCP_AI_Api_Key_Store::get_managed_key_suffixes() as $suffix ) {
			$stored = '' !== WP_MCP_AI_Api_Key_Store::get( $suffix );

			$items[] = array(
				'key'    => $suffix,
				'label'  => WP_MCP_AI_Api_Key_Store::get_label( $suffix ),
				'status' => in_array( $suffix, $plaintext, true )
					? __( 'plaintext (migration pending)', 'mcp-ai-wpoos' )
					: ( $stored ? __( 'encrypted', 'mcp-ai-wpoos' ) : __( 'not set', 'mcp-ai-wpoos' ) ),
			);
		}

		if ( empty( $items ) ) {
			WP_CLI::log( __( 'No managed provider keys found.', 'mcp-ai-wpoos' ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}

	/**
	 * Mirror the destructive-ops gate's enablement lookup.
	 *
	 * @return bool Whether confirmation for destructive ops is required.
	 */
	private function destructive_ops_enabled() {
		$value = null;

		if ( class_exists( 'WP_MCP_AI_Admin_Settings_Base' ) ) {
			$settings = WP_MCP_AI_Admin_Settings_Base::get_settings();
			if ( is_array( $settings ) && array_key_exists( 'require_confirm_destructive_ops', $settings ) ) {
				$value = $settings['require_confirm_destructive_ops'];
			}
		}

		if ( null === $value && function_exists( 'wp_mcp_ai_get_settings_repository' ) ) {
			$repository = wp_mcp_ai_get_settings_repository();
			if ( null !== $repository ) {
				$value = $repository->get( 'require_confirm_destructive_ops', null );
			}
		}

		// Default: enabled (fail-safe), matching the gate class.
		return null === $value ? true : (bool) $value;
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai security', 'WP_MCP_AI_CLI_Security_Command' );
}
