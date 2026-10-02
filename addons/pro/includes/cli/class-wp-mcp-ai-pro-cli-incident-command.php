<?php
/**
 * WP-CLI incident management commands for NV oOS Pro.
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
 * Manage NV oOS Pro operational incidents (mcp_ai_incident CPT) from the
 * command line.
 *
 * ## EXAMPLES
 *
 *     # List incidents currently being investigated.
 *     $ wp mcp-ai incident list --status=investigating
 *
 *     # Resolve an incident without a confirmation prompt.
 *     $ wp mcp-ai incident resolve 42 --yes
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_CLI_Incident_Command extends WP_MCP_AI_Pro_CLI_Base_Command {

	/**
	 * List all incidents.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<phase>]
	 * : Filter by incident phase.
	 * ---
	 * options:
	 *   - detected
	 *   - investigating
	 *   - identified
	 *   - monitoring
	 *   - resolved
	 * ---
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
	 * : Comma-separated list of fields to display.
	 *
	 * ## EXAMPLES
	 *
	 *     # List all incidents.
	 *     $ wp mcp-ai incident list
	 *
	 *     # List only monitoring-phase incidents.
	 *     $ wp mcp-ai incident list --status=monitoring
	 *
	 *     # Export incident IDs.
	 *     $ wp mcp-ai incident list --format=ids
	 *
	 * @subcommand list
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function list( $args, $assoc_args ) {
		$this->assert_pro_loaded();

		$phase  = sanitize_key( \WP_CLI\Utils\get_flag_value( $assoc_args, 'status', '' ) );
		$format = $this->get_format( $assoc_args, 'table' );
		$fields = $this->get_fields( $assoc_args, array( 'ID', 'title', 'phase', 'severity', 'date' ) );

		$query_args = array(
			'post_type'      => 'mcp_ai_incident',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( '' !== $phase ) {
			$query_args['meta_query'] = array(
				array(
					'key'   => '_mcp_ai_incident_phase',
					'value' => $phase,
				),
			);
		}

		$posts = get_posts( $query_args );

		if ( empty( $posts ) ) {
			WP_CLI::log( __( 'No incidents found.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$items = array();
		foreach ( $posts as $post ) {
			$items[] = array(
				'ID'       => $post->ID,
				'title'    => $post->post_title,
				'phase'    => get_post_meta( $post->ID, '_mcp_ai_incident_phase', true ),
				'severity' => get_post_meta( $post->ID, '_mcp_ai_incident_severity', true ),
				'date'     => $post->post_date,
			);
		}

		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}

	/**
	 * Get details for a single incident.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The incident post ID.
	 *
	 * ## EXAMPLES
	 *
	 *     # Get incident 42.
	 *     $ wp mcp-ai incident get 42
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function get( $args, $assoc_args ) {
		$this->assert_pro_loaded();

		$id = isset( $args[0] ) ? absint( $args[0] ) : 0;

		if ( ! $id ) {
			WP_CLI::error( __( 'Please provide a valid incident ID.', 'mcp-ai-wpoos-pro' ) );
		}

		$post = get_post( $id );

		if ( ! $post || 'mcp_ai_incident' !== $post->post_type ) {
			/* translators: %d: incident ID */
			WP_CLI::error( sprintf( __( 'Incident %d not found.', 'mcp-ai-wpoos-pro' ), $id ) );
		}

		$data = array(
			'ID'      => $post->ID,
			'title'   => $post->post_title,
			'created' => $post->post_date,
			'updated' => $post->post_modified,
		);

		// Append all _mcp_ai_incident_* meta.
		foreach ( get_post_meta( $id ) as $meta_key => $values ) {
			if ( 0 === strpos( $meta_key, '_mcp_ai_incident_' ) ) {
				$data[ $meta_key ] = $values[0] ?? '';
			}
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
	 * Resolve an incident.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The incident post ID.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Resolve incident 42 (prompts for confirmation).
	 *     $ wp mcp-ai incident resolve 42
	 *
	 *     # Resolve without a prompt.
	 *     $ wp mcp-ai incident resolve 42 --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function resolve( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->require_capability( 'manage_options' );

		$id = isset( $args[0] ) ? absint( $args[0] ) : 0;

		if ( ! $id ) {
			WP_CLI::error( __( 'Please provide a valid incident ID.', 'mcp-ai-wpoos-pro' ) );
		}

		$post = get_post( $id );

		if ( ! $post || 'mcp_ai_incident' !== $post->post_type ) {
			/* translators: %d: incident ID */
			WP_CLI::error( sprintf( __( 'Incident %d not found.', 'mcp-ai-wpoos-pro' ), $id ) );
		}

		/* translators: 1: incident title, 2: incident ID */
		$this->confirm(
			sprintf( __( 'Resolve incident "%1$s" (ID %2$d)?', 'mcp-ai-wpoos-pro' ), $post->post_title, $id ),
			$assoc_args
		);

		$message = __( 'This incident has been resolved.', 'mcp-ai-wpoos-pro' );

		if ( class_exists( 'WP_MCP_AI_Incident_CPT' ) ) {
			$resolved = WP_MCP_AI_Incident_CPT::transition_phase(
				$id,
				WP_MCP_AI_Incident_CPT::PHASE_RESOLVED,
				$message
			);
		} else {
			// Defensive fallback when the backing class is unavailable.
			update_post_meta( $id, '_mcp_ai_incident_phase', 'resolved' );
			update_post_meta( $id, '_mcp_ai_incident_resolved_at', gmdate( 'c' ) );
			$resolved = true;
		}

		if ( ! $resolved ) {
			WP_CLI::error( __( 'Cannot resolve this incident in its current state.', 'mcp-ai-wpoos-pro' ) );
		}

		/* translators: 1: incident title, 2: incident ID */
		WP_CLI::success( sprintf( __( 'Resolved incident "%1$s" (ID %2$d).', 'mcp-ai-wpoos-pro' ), $post->post_title, $id ) );
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai incident', 'WP_MCP_AI_Pro_CLI_Incident_Command' );
}
