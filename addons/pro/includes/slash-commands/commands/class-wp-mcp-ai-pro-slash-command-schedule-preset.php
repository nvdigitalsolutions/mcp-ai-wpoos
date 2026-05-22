<?php
/**
 * Schedule Preset Pro Slash Command
 *
 * Browse and install Pro schedule presets.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Slash_Commands
 * @since 2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedule Preset Command Class
 *
 * Flags:
 *   --list          List presets (default), optionally filtered by --toolkit=<cat>
 *   --show=<id>     Show full preset details
 *   --install=<id>  Install preset (requires manage_options)
 *   --categories    List available categories/toolkits
 *   --toolkit=<cat> Filter by toolkit/category
 *   --json          JSON output
 *
 * @since 2.1.0
 */
class WP_MCP_AI_Pro_Slash_Command_Schedule_Preset {

	/**
	 * Execute schedule-preset command.
	 *
	 * @param array $args    Positional arguments.
	 * @param array $flags   Command flags.
	 * @param array $context Execution context.
	 * @return string|array|WP_Error
	 */
	public function execute( $args, $flags, $context ) {
		// Block guest requests.
		if ( ! empty( $context['guest_request'] ) ) {
			return new WP_Error(
				'guest_forbidden',
				__( 'This command requires authentication.', 'mcp-ai-wpoos-pro' )
			);
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$as_json = isset( $flags['json'] );

		// Require edit_posts for browsing.
		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'forbidden',
				__( 'Permission denied. Requires edit_posts capability.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Ensure Schedule Presets service is available.
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Presets' ) ) {
			return new WP_Error(
				'service_unavailable',
				__( 'Schedule Presets service is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		// --categories: list available categories.
		if ( isset( $flags['categories'] ) ) {
			return $this->list_categories( $as_json );
		}

		// --show=<id>: show a specific preset.
		if ( isset( $flags['show'] ) ) {
			$preset_id = sanitize_key( $flags['show'] );
			return $this->show_preset( $preset_id, $as_json );
		}

		// --install=<id>: install a preset (requires manage_options).
		if ( isset( $flags['install'] ) ) {
			if ( ! user_can( $user_id, 'manage_options' ) ) {
				return new WP_Error(
					'forbidden',
					__( 'Installing presets requires manage_options capability.', 'mcp-ai-wpoos-pro' )
				);
			}
			$preset_id = sanitize_key( $flags['install'] );
			return $this->install_preset( $preset_id, $user_id, $as_json );
		}

		// Default: list presets, optionally filtered by toolkit.
		$toolkit = isset( $flags['toolkit'] ) ? sanitize_key( $flags['toolkit'] ) : '';
		return $this->list_presets( $toolkit, $as_json );
	}

	/**
	 * List available categories/toolkits.
	 *
	 * @param bool $as_json JSON output.
	 * @return string|array|WP_Error
	 */
	private function list_categories( $as_json ) {
		$categories = WP_MCP_AI_Pro_Schedule_Presets::get_categories();

		if ( is_wp_error( $categories ) ) {
			return $categories;
		}

		$categories = is_array( $categories ) ? $categories : array();

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => __( 'Categories retrieved.', 'mcp-ai-wpoos-pro' ),
				'data'    => $categories,
			);
		}

		if ( empty( $categories ) ) {
			return __( 'No categories found.', 'mcp-ai-wpoos-pro' );
		}

		$output = '## ' . __( 'Schedule Preset Categories', 'mcp-ai-wpoos-pro' ) . "\n\n";
		foreach ( $categories as $cat ) {
			$output .= '- ' . esc_html( is_array( $cat ) ? ( $cat['label'] ?? $cat['id'] ?? $cat ) : $cat ) . "\n";
		}

		return $output;
	}

	/**
	 * Show full preset details.
	 *
	 * @param string $preset_id Preset ID.
	 * @param bool   $as_json   JSON output.
	 * @return string|array|WP_Error
	 */
	private function show_preset( $preset_id, $as_json ) {
		if ( empty( $preset_id ) ) {
			return new WP_Error( 'missing_id', __( 'Preset ID required. Usage: --show=<id>', 'mcp-ai-wpoos-pro' ) );
		}

		$preset = WP_MCP_AI_Pro_Schedule_Presets::get_preset( $preset_id );

		if ( is_wp_error( $preset ) ) {
			return $preset;
		}

		if ( ! $preset ) {
			return new WP_Error( 'not_found', __( 'Preset not found.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => __( 'Preset retrieved.', 'mcp-ai-wpoos-pro' ),
				'data'    => $preset,
			);
		}

		$name = isset( $preset['name'] ) ? esc_html( $preset['name'] ) : esc_html( $preset_id );
		$desc = isset( $preset['description'] ) ? esc_html( $preset['description'] ) : '–';
		$cat  = isset( $preset['category'] ) ? esc_html( $preset['category'] ) : '–';
		$cron = isset( $preset['schedule'] ) ? esc_html( $preset['schedule'] ) : '–';
		$type = isset( $preset['schedule_type'] ) ? esc_html( $preset['schedule_type'] ) : '–';

		$output  = "## Preset: {$name}\n\n";
		$output .= '- **ID:** ' . esc_html( $preset_id ) . "\n";
		$output .= "- **Category:** {$cat}\n";
		$output .= "- **Type:** {$type}\n";
		$output .= "- **Cron:** {$cron}\n";
		$output .= "- **Description:** {$desc}\n";

		return $output;
	}

	/**
	 * List presets, optionally filtered by toolkit/category.
	 *
	 * @param string $toolkit Toolkit/category filter.
	 * @param bool   $as_json JSON output.
	 * @return string|array|WP_Error
	 */
	private function list_presets( $toolkit, $as_json ) {
		if ( $toolkit ) {
			$presets = WP_MCP_AI_Pro_Schedule_Presets::get_presets_by_category( $toolkit );
		} else {
			$presets = WP_MCP_AI_Pro_Schedule_Presets::get_presets();
		}

		if ( is_wp_error( $presets ) ) {
			return $presets;
		}

		$presets = is_array( $presets ) ? $presets : array();

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => __( 'Presets retrieved.', 'mcp-ai-wpoos-pro' ),
				'data'    => $presets,
			);
		}

		if ( empty( $presets ) ) {
			return __( 'No schedule presets found.', 'mcp-ai-wpoos-pro' );
		}

		$header = $toolkit
			? sprintf(
				/* translators: %s: toolkit/category name */
				__( 'Schedule Presets — %s', 'mcp-ai-wpoos-pro' ),
				esc_html( $toolkit )
			)
			: __( 'All Schedule Presets', 'mcp-ai-wpoos-pro' );

		$output  = "## {$header}\n\n";
		$output .= "| ID | Name | Category | Type | Cron |\n";
		$output .= "|----|------|----------|------|------|\n";

		foreach ( $presets as $id => $preset ) {
			$p_id    = esc_html( is_array( $preset ) ? ( $preset['id'] ?? $id ) : $id );
			$name    = is_array( $preset ) ? esc_html( $preset['name'] ?? '–' ) : esc_html( $preset );
			$cat     = is_array( $preset ) ? esc_html( $preset['category'] ?? '–' ) : '–';
			$type    = is_array( $preset ) ? esc_html( $preset['schedule_type'] ?? '–' ) : '–';
			$cron    = is_array( $preset ) ? esc_html( $preset['schedule'] ?? '–' ) : '–';
			$output .= "| {$p_id} | {$name} | {$cat} | {$type} | {$cron} |\n";
		}

		$output .= "\n_Use `--install=<id>` to install a preset (requires manage\\_options)._\n";

		return $output;
	}

	/**
	 * Install a preset by creating a schedule from it.
	 *
	 * @param string $preset_id Preset ID.
	 * @param int    $user_id   User ID.
	 * @param bool   $as_json   JSON output.
	 * @return string|array|WP_Error
	 */
	private function install_preset( $preset_id, $user_id, $as_json ) {
		if ( empty( $preset_id ) ) {
			return new WP_Error( 'missing_id', __( 'Preset ID required. Usage: --install=<id>', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			return new WP_Error(
				'service_unavailable',
				__( 'Schedule Manager service is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$preset = WP_MCP_AI_Pro_Schedule_Presets::get_preset( $preset_id );

		if ( is_wp_error( $preset ) ) {
			return $preset;
		}

		if ( ! $preset || ! is_array( $preset ) ) {
			return new WP_Error( 'not_found', __( 'Preset not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Build schedule data from preset.
		$data = array(
			'name'              => isset( $preset['name'] ) ? sanitize_text_field( $preset['name'] ) : $preset_id,
			'schedule_type'     => isset( $preset['schedule_type'] ) ? sanitize_key( $preset['schedule_type'] ) : 'task',
			'schedule'          => isset( $preset['schedule'] ) ? sanitize_text_field( $preset['schedule'] ) : 'daily',
			'notify_on_failure' => ! empty( $preset['notify_on_failure'] ),
		);

		if ( isset( $preset['workflow_steps'] ) ) {
			$data['workflow_steps'] = $preset['workflow_steps'];
		}

		$result = WP_MCP_AI_Pro_Schedule_Manager::create_schedule( $data, $user_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %s: preset ID */
					__( 'Preset "%s" installed as a new schedule.', 'mcp-ai-wpoos-pro' ),
					esc_html( $preset_id )
				),
				'data'    => $result,
			);
		}

		return sprintf(
			/* translators: %s: preset name */
			__( '✅ Preset "%s" installed successfully as a new schedule.', 'mcp-ai-wpoos-pro' ),
			esc_html( $data['name'] )
		);
	}
}
