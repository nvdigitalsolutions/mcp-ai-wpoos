<?php
/**
 * WP-CLI tool management commands for NV oOS.
 *
 * @package WP_MCP_AI
 * @subpackage CLI
 * @since 1.3.0
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
 * List, enable, and disable NV oOS tools from the command line.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_CLI_Tool_Command extends WP_MCP_AI_CLI_Base_Command {

	/**
	 * List all registered tools and their enabled/disabled state.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by tool status.
	 * ---
	 * options:
	 *   - enabled
	 *   - disabled
	 * ---
	 *
	 * [--format=<format>]
	 * : Render output in the given format.
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
	 *     # List all tools.
	 *     $ wp mcp-ai tool list
	 *
	 *     # List only disabled tools.
	 *     $ wp mcp-ai tool list --status=disabled
	 *
	 *     # Export tool list as JSON.
	 *     $ wp mcp-ai tool list --format=json
	 *
	 * @subcommand list
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function list( $args, $assoc_args ) {
		$status_filter = \WP_CLI\Utils\get_flag_value( $assoc_args, 'status', '' );
		$format        = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			WP_CLI::error( __( 'Tool registry is not available.', 'mcp-ai-wpoos' ) );
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tools    = $registry->get_all_tools();

		if ( empty( $tools ) ) {
			WP_CLI::log( __( 'No tools are registered.', 'mcp-ai-wpoos' ) );
			return;
		}

		$items = array();
		foreach ( $tools as $slug => $tool ) {
			$enabled = $registry->is_tool_enabled( $slug );
			$status  = $enabled ? 'enabled' : 'disabled';

			if ( $status_filter && $status_filter !== $status ) {
				continue;
			}

			$definition = method_exists( $tool, 'get_definition' ) ? $tool->get_definition() : array();
			$name       = isset( $definition['name'] ) ? $definition['name'] : $slug;
			$capability = isset( $definition['required_capability'] ) ? $definition['required_capability'] : '';

			$items[] = array(
				'slug'       => $slug,
				'name'       => $name,
				'status'     => $status,
				'capability' => $capability,
			);
		}

		if ( empty( $items ) ) {
			WP_CLI::log( __( 'No tools match the given filter.', 'mcp-ai-wpoos' ) );
			return;
		}

		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', wp_list_pluck( $items, 'slug' ) ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $items, array( 'slug', 'name', 'status', 'capability' ) );
	}

	/**
	 * Enable a tool.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The tool slug to enable.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai tool enable search_posts
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function enable( $args, $assoc_args ) {
		$slug = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';

		if ( ! $slug ) {
			WP_CLI::error( __( 'Please provide a tool slug.', 'mcp-ai-wpoos' ) );
		}

		$this->require_capability( 'manage_options' );

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			WP_CLI::error( __( 'Tool registry is not available.', 'mcp-ai-wpoos' ) );
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		if ( ! $registry->is_tool_registered( $slug ) ) {
			/* translators: %s: tool slug */
			WP_CLI::error( sprintf( __( 'Tool "%s" is not registered.', 'mcp-ai-wpoos' ), $slug ) );
		}

		if ( $registry->is_tool_enabled( $slug ) ) {
			/* translators: %s: tool slug */
			WP_CLI::success( sprintf( __( 'Tool "%s" is already enabled.', 'mcp-ai-wpoos' ), $slug ) );
			return;
		}

		$result = $registry->enable_tool( $slug );

		if ( ! $result ) {
			/* translators: %s: tool slug */
			WP_CLI::error( sprintf( __( 'Failed to enable tool "%s".', 'mcp-ai-wpoos' ), $slug ) );
		}

		/* translators: %s: tool slug */
		WP_CLI::success( sprintf( __( 'Tool "%s" enabled.', 'mcp-ai-wpoos' ), $slug ) );
	}

	/**
	 * Disable a tool.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The tool slug to disable.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai tool disable search_posts
	 *     $ wp mcp-ai tool disable search_posts --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function disable( $args, $assoc_args ) {
		$slug = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';

		if ( ! $slug ) {
			WP_CLI::error( __( 'Please provide a tool slug.', 'mcp-ai-wpoos' ) );
		}

		$this->require_capability( 'manage_options' );

		$yes = \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false );

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			WP_CLI::error( __( 'Tool registry is not available.', 'mcp-ai-wpoos' ) );
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		if ( ! $registry->is_tool_registered( $slug ) ) {
			/* translators: %s: tool slug */
			WP_CLI::error( sprintf( __( 'Tool "%s" is not registered.', 'mcp-ai-wpoos' ), $slug ) );
		}

		if ( ! $registry->is_tool_enabled( $slug ) ) {
			/* translators: %s: tool slug */
			WP_CLI::success( sprintf( __( 'Tool "%s" is already disabled.', 'mcp-ai-wpoos' ), $slug ) );
			return;
		}

		if ( ! $yes ) {
			WP_CLI::confirm(
				sprintf(
					/* translators: %s: tool slug */
					__( 'Disable tool "%s"? This will remove it from all assistants.', 'mcp-ai-wpoos' ),
					$slug
				)
			);
		}

		$result = $registry->disable_tool( $slug );

		if ( ! $result ) {
			/* translators: %s: tool slug */
			WP_CLI::error( sprintf( __( 'Failed to disable tool "%s".', 'mcp-ai-wpoos' ), $slug ) );
		}

		/* translators: %s: tool slug */
		WP_CLI::success( sprintf( __( 'Tool "%s" disabled.', 'mcp-ai-wpoos' ), $slug ) );
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai tool', 'WP_MCP_AI_CLI_Tool_Command' );
}
