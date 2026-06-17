<?php
/**
 * Workflow Preset Pro Slash Command
 *
 * Browse and install Workflow Builder presets.
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
 * Workflow Preset Command Class
 *
 * Flags:
 *   --list            List all presets (default)
 *   --category=<cat>  Filter by category
 *   --categories      List available categories
 *   --show=<id>       Show preset details
 *   --install=<id>    Install preset (requires manage_options)
 *   --json            JSON output
 *
 * @since 2.1.0
 */
class WP_MCP_AI_Pro_Slash_Command_Workflow_Preset {

	/**
	 * Execute workflow-preset command.
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

		// Ensure Workflow Presets service is available.
		if ( ! class_exists( 'WP_MCP_AI_Pro_Workflow_Presets' ) ) {
			return new WP_Error(
				'service_unavailable',
				__( 'Workflow Presets service is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		// --categories: list available categories.
		if ( isset( $flags['categories'] ) ) {
			return $this->list_categories( $as_json );
		}

		// --show=<id>: show preset details.
		if ( isset( $flags['show'] ) ) {
			$preset_id = sanitize_key( $flags['show'] );
			return $this->show_preset( $preset_id, $as_json );
		}

		// --install=<id>: install preset (requires manage_options).
		if ( isset( $flags['install'] ) ) {
			if ( ! user_can( $user_id, 'manage_options' ) ) {
				return new WP_Error(
					'forbidden',
					__( 'Installing presets requires manage_options capability.', 'mcp-ai-wpoos-pro' )
				);
			}
			$preset_id = sanitize_key( $flags['install'] );
			return $this->install_preset( $preset_id, $as_json );
		}

		// Default: list presets, optionally filtered by category.
		$category = isset( $flags['category'] ) ? sanitize_key( $flags['category'] ) : '';
		return $this->list_presets( $category, $as_json );
	}

	/**
	 * List available categories.
	 *
	 * @param bool $as_json JSON output.
	 * @return string|array|WP_Error
	 */
	private function list_categories( $as_json ) {
		$categories = WP_MCP_AI_Pro_Workflow_Presets::get_categories();

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
			return __( 'No workflow preset categories found.', 'mcp-ai-wpoos-pro' );
		}

		$output = '## ' . __( 'Workflow Preset Categories', 'mcp-ai-wpoos-pro' ) . "\n\n";
		foreach ( $categories as $cat ) {
			$label   = is_array( $cat ) ? ( $cat['label'] ?? $cat['id'] ?? '' ) : $cat;
			$output .= '- ' . esc_html( $label ) . "\n";
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

		$preset = WP_MCP_AI_Pro_Workflow_Presets::get_preset( $preset_id );

		if ( is_wp_error( $preset ) ) {
			return $preset;
		}

		if ( ! $preset ) {
			return new WP_Error( 'not_found', __( 'Workflow preset not found.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => __( 'Preset retrieved.', 'mcp-ai-wpoos-pro' ),
				'data'    => $preset,
			);
		}

		$name  = isset( $preset['name'] ) ? esc_html( $preset['name'] ) : esc_html( $preset_id );
		$desc  = isset( $preset['description'] ) ? esc_html( $preset['description'] ) : '–';
		$cat   = isset( $preset['category'] ) ? esc_html( $preset['category'] ) : '–';
		$nodes = isset( $preset['nodes'] ) ? count( (array) $preset['nodes'] ) : '–';
		$edges = isset( $preset['edges'] ) ? count( (array) $preset['edges'] ) : '–';

		$output  = "## Workflow Preset: {$name}\n\n";
		$output .= '- **ID:** ' . esc_html( $preset_id ) . "\n";
		$output .= "- **Category:** {$cat}\n";
		$output .= "- **Nodes:** {$nodes}\n";
		$output .= "- **Edges:** {$edges}\n";
		$output .= "- **Description:** {$desc}\n";

		return $output;
	}

	/**
	 * List presets, optionally filtered by category.
	 *
	 * @param string $category Category filter.
	 * @param bool   $as_json  JSON output.
	 * @return string|array|WP_Error
	 */
	private function list_presets( $category, $as_json ) {
		if ( $category ) {
			$presets = WP_MCP_AI_Pro_Workflow_Presets::get_presets_by_category( $category );
		} else {
			$presets = WP_MCP_AI_Pro_Workflow_Presets::get_presets();
		}

		if ( is_wp_error( $presets ) ) {
			return $presets;
		}

		$presets = is_array( $presets ) ? $presets : array();

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => __( 'Workflow presets retrieved.', 'mcp-ai-wpoos-pro' ),
				'data'    => $presets,
			);
		}

		if ( empty( $presets ) ) {
			return __( 'No workflow presets found.', 'mcp-ai-wpoos-pro' );
		}

		$header = $category
			? sprintf(
				/* translators: %s: category name */
				__( 'Workflow Presets — %s', 'mcp-ai-wpoos-pro' ),
				esc_html( $category )
			)
			: __( 'All Workflow Presets', 'mcp-ai-wpoos-pro' );

		$output  = "## {$header}\n\n";
		$output .= "| ID | Name | Category |\n";
		$output .= "|----|------|----------|\n";

		foreach ( $presets as $id => $preset ) {
			$p_id    = esc_html( is_array( $preset ) ? ( $preset['id'] ?? $id ) : $id );
			$name    = is_array( $preset ) ? esc_html( $preset['name'] ?? '–' ) : esc_html( $preset );
			$cat     = is_array( $preset ) ? esc_html( $preset['category'] ?? '–' ) : '–';
			$output .= "| {$p_id} | {$name} | {$cat} |\n";
		}

		$output .= "\n_Use `--install=<id>` to install a preset (requires manage\\_options)._\n";

		return $output;
	}

	/**
	 * Install a workflow preset.
	 *
	 * @param string $preset_id Preset ID.
	 * @param bool   $as_json   JSON output.
	 * @return string|array|WP_Error
	 */
	private function install_preset( $preset_id, $as_json ) {
		if ( empty( $preset_id ) ) {
			return new WP_Error( 'missing_id', __( 'Preset ID required. Usage: --install=<id>', 'mcp-ai-wpoos-pro' ) );
		}

		$result = WP_MCP_AI_Pro_Workflow_Presets::install_preset( $preset_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %s: preset ID */
					__( 'Workflow preset "%s" installed.', 'mcp-ai-wpoos-pro' ),
					esc_html( $preset_id )
				),
				'data'    => $result,
			);
		}

		return sprintf(
			/* translators: %s: preset ID */
			__( '✅ Workflow preset "%s" installed successfully.', 'mcp-ai-wpoos-pro' ),
			esc_html( $preset_id )
		);
	}
}
