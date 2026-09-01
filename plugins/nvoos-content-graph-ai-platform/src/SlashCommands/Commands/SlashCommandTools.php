<?php
/**
 * /tools slash command.
 *
 * Lists, filters, and inspects registered tools from the Tool Registry.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since   2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SlashCommands\Commands;

use NvoosContentGraphAiPlatform\SlashCommands\SlashCommandHandler;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SlashCommandTools
 *
 * @since 2.1.0
 */
class SlashCommandTools {
	/**
	 * Log an event through the base plugin's logger when present
	 * (monolith mode), falling back to error_log in standalone mode.
	 *
	 * @since 2.0.0
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 */
	private static function log_event( $level, $message ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $level, $message );
			return;
		}
		error_log( sprintf( '[nvoos-content-graph-ai-platform] %s: %s', $level, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Standalone fallback when the base logger is absent.
	}

	/**
	 * Items per page for listing.
	 */
	const PER_PAGE = 20;

	/**
	 * Execute the /tools command.
	 *
	 * @param array $args    Positional arguments. args[0] = optional search term.
	 * @param array $flags   Parsed flag map.
	 * @param array $context Execution context.
	 * @return array|WP_Error Command response or error.
	 */
	public function execute( $args, $flags, $context ) {
		// Block guest requests.
		if ( ! empty( $context['guest_request'] ) ) {
			return new \WP_Error( 'guest_forbidden', __( 'This command requires authentication.', 'nvoos-content-graph-ai-platform' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Capability gate.
		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new \WP_Error( 'insufficient_capability', __( 'You do not have permission to use /tools.', 'nvoos-content-graph-ai-platform' ) );
		}

		if ( ! defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return new \WP_Error( 'wp_mcp_ai_error', __( 'Tool registry is not available.', 'nvoos-content-graph-ai-platform' ) );
		}

		$as_json   = isset( $flags['json'] );
		$show_slug = isset( $flags['show'] ) ? sanitize_key( $flags['show'] ) : '';
		$cap_flag  = isset( $flags['capability-flag'] ) ? sanitize_text_field( $flags['capability-flag'] ) : '';
		$page      = isset( $flags['page'] ) ? max( 1, absint( $flags['page'] ) ) : 1;
		$search    = isset( $args[0] ) ? sanitize_text_field( $args[0] ) : '';

		$registry = \WP_MCP_AI_Tool_Registry::get_instance();

		// --show: display single tool definition.
		if ( $show_slug ) {
			return $this->show_tool( $registry, $show_slug, $as_json );
		}

		// Load tools.
		if ( $cap_flag ) {
			$tools = $registry->get_tools_by_capability_flag( $cap_flag );
		} else {
			$tools = $registry->get_tools();
		}

		if ( ! is_array( $tools ) ) {
			$tools = array();
		}

		// Filter by search term.
		if ( $search ) {
			$search_lower = strtolower( $search );
			$filtered     = array();
			foreach ( $tools as $slug => $tool ) {
				$slug_lower = strtolower( (string) $slug );
				$desc       = '';
				if ( is_array( $tool ) && isset( $tool['description'] ) ) {
					$desc = strtolower( (string) $tool['description'] );
				} elseif ( is_object( $tool ) && method_exists( $tool, 'get_definition' ) ) {
					$def  = $tool->get_definition();
					$desc = isset( $def['description'] ) ? strtolower( (string) $def['description'] ) : '';
				}
				if ( strpos( $slug_lower, $search_lower ) !== false || strpos( $desc, $search_lower ) !== false ) {
					$filtered[ $slug ] = $tool;
				}
			}
			$tools = $filtered;
		}

		// Paginate.
		$total      = count( $tools );
		$offset     = ( $page - 1 ) * self::PER_PAGE;
		$page_tools = array_slice( $tools, $offset, self::PER_PAGE, true );

		$data = array(
			'total' => $total,
			'page'  => $page,
			'pages' => (int) ceil( $total / self::PER_PAGE ),
			'tools' => $this->normalize_tools( $page_tools, $registry ),
		);

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
				'data'    => $data,
			);
		}

		return array(
			'success' => true,
			'message' => $this->render_table( $data ),
			'data'    => $data,
		);
	}

	/**
	 * Show details for a single tool.
	 *
	 * @param WP_MCP_AI_Tool_Registry $registry Tool registry instance.
	 * @param string                  $slug     Tool slug.
	 * @param bool                    $as_json  Return JSON format.
	 * @return array|WP_Error
	 */
	private function show_tool( $registry, $slug, $as_json ) {
		$definition = $registry->get_tool_definition( $slug );

		if ( ! $definition ) {
			return new \WP_Error(
				'tool_not_found',
				sprintf(
					/* translators: %s: tool slug */
					__( 'Tool "%s" not found.', 'nvoos-content-graph-ai-platform' ),
					esc_html( $slug )
				)
			);
		}

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => wp_json_encode( $definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
				'data'    => $definition,
			);
		}

		$name = isset( $definition['name'] ) ? esc_html( (string) $definition['name'] ) : esc_html( $slug );
		$desc = isset( $definition['description'] ) ? esc_html( (string) $definition['description'] ) : '—';
		$cap  = isset( $definition['required_capability'] ) ? esc_html( (string) $definition['required_capability'] ) : '—';

		$out  = "## Tool: `{$slug}`\n\n";
		$out .= "**Name:** {$name}\n\n";
		$out .= "**Description:** {$desc}\n\n";
		$out .= "**Capability:** `{$cap}`\n\n";

		if ( isset( $definition['parameters'] ) && is_array( $definition['parameters'] ) ) {
			$out .= "**Parameters:**\n\n";
			$out .= "```json\n" . wp_json_encode( $definition['parameters'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n```\n";
		}

		return array(
			'success' => true,
			'message' => $out,
			'data'    => $definition,
		);
	}

	/**
	 * Normalize a page of tools into a simple array for display.
	 *
	 * @param array                   $tools    Keyed tool data.
	 * @param WP_MCP_AI_Tool_Registry $registry Registry instance.
	 * @return array
	 */
	private function normalize_tools( array $tools, $registry ) {
		$rows = array();
		foreach ( $tools as $slug => $tool ) {
			$name = $slug;
			$desc = '';
			$cap  = '';

			if ( is_array( $tool ) ) {
				$name = isset( $tool['name'] ) ? (string) $tool['name'] : $slug;
				$desc = isset( $tool['description'] ) ? (string) $tool['description'] : '';
				$cap  = isset( $tool['required_capability'] ) ? (string) $tool['required_capability'] : '';
			} elseif ( is_object( $tool ) && method_exists( $tool, 'get_definition' ) ) {
				$def  = $tool->get_definition();
				$name = isset( $def['name'] ) ? (string) $def['name'] : $slug;
				$desc = isset( $def['description'] ) ? (string) $def['description'] : '';
				$cap  = isset( $def['required_capability'] ) ? (string) $def['required_capability'] : '';
			}

			// Capability flags.
			$flags_raw = '';
			if ( method_exists( $registry, 'get_tool_capability_flags' ) ) {
				$flags_arr = $registry->get_tool_capability_flags( $slug );
				if ( is_array( $flags_arr ) ) {
					$flags_raw = implode( ', ', $flags_arr );
				}
			}

			$rows[] = array(
				'slug'  => (string) $slug,
				'name'  => $name,
				'cap'   => $cap,
				'flags' => $flags_raw,
				'desc'  => $desc,
			);
		}
		return $rows;
	}

	/**
	 * Render tool list as Markdown table.
	 *
	 * @param array $data Paginated data.
	 * @return string
	 */
	private function render_table( array $data ) {
		$total = isset( $data['total'] ) ? (int) $data['total'] : 0;
		$page  = isset( $data['page'] ) ? (int) $data['page'] : 1;
		$pages = isset( $data['pages'] ) ? (int) $data['pages'] : 1;
		$tools = isset( $data['tools'] ) && is_array( $data['tools'] ) ? $data['tools'] : array();

		$out = sprintf(
			"## Tools (%d total — page %d of %d)\n\n",
			$total,
			$page,
			$pages
		);

		if ( empty( $tools ) ) {
			$out .= '_No tools found._';
			return $out;
		}

		$out .= "| Slug | Name | Capability | Flags |\n";
		$out .= "|------|------|-----------|-------|\n";

		foreach ( $tools as $row ) {
			$out .= sprintf(
				"| `%s` | %s | `%s` | %s |\n",
				esc_html( $row['slug'] ),
				esc_html( $row['name'] ),
				esc_html( $row['cap'] ),
				esc_html( $row['flags'] )
			);
		}

		if ( $page < $pages ) {
			$out .= sprintf(
				"\n_Use `--page=%d` for the next page._\n",
				$page + 1
			);
		}

		return $out;
	}
}
