<?php
/**
 * /mcp-server — Toolkit MCP Server management slash command.
 *
 * Inspect and toggle the per-toolkit MCP servers registered through
 * `WP_MCP_AI_Toolkit_Server_Registry`. Mirrors the existing slash-command
 * convention (sub-action positional + `--json` flag).
 *
 * Sub-actions:
 *   - list                       Default. List all registered servers.
 *   - show     <slug>            Show a single server descriptor.
 *   - enable   <slug>            Enable the server (requires manage_options).
 *   - disable  <slug>            Disable the server (requires manage_options).
 *   - tools    <slug>            List effective tool slugs for a server.
 *
 * Flags:
 *   --json                      Return a JSON envelope.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage Slash_Commands
 * @since      1.3.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions
 * @license    Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Pro_Slash_Command_Mcp_Server
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_Slash_Command_Mcp_Server {

	/**
	 * Mutating sub-actions that require manage_options.
	 *
	 * @var string[]
	 */
	const MUTATING_ACTIONS = array( 'enable', 'disable' );

	/**
	 * Execute the /mcp-server command.
	 *
	 * @param array $args    Positional arguments.
	 * @param array $flags   Parsed flag map.
	 * @param array $context Execution context.
	 * @return array|WP_Error
	 */
	public function execute( $args, $flags, $context ) {
		if ( ! empty( $context['guest_request'] ) ) {
			return new WP_Error(
				'guest_forbidden',
				__( 'This command requires authentication.', 'mcp-ai-wpoos-pro' )
			);
		}

		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$as_json = isset( $flags['json'] );

		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'forbidden',
				__( 'Permission denied. Requires edit_posts capability.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Toolkit_Server_Registry' ) ) {
			return new WP_Error(
				'registry_unavailable',
				__( 'Toolkit MCP Server Registry is not loaded.', 'mcp-ai-wpoos-pro' )
			);
		}

		$action = isset( $args[0] ) ? sanitize_key( (string) $args[0] ) : 'list';
		$slug   = isset( $args[1] ) ? sanitize_key( (string) $args[1] ) : '';

		// Mutating actions require manage_options.
		if ( in_array( $action, self::MUTATING_ACTIONS, true ) && ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'forbidden',
				__( 'Permission denied. Requires manage_options capability.', 'mcp-ai-wpoos-pro' )
			);
		}

		switch ( $action ) {
			case 'list':
				return $this->handle_list( $as_json );

			case 'show':
				return $this->handle_show( $slug, $as_json );

			case 'enable':
				return $this->handle_toggle( $slug, true, $as_json );

			case 'disable':
				return $this->handle_toggle( $slug, false, $as_json );

			case 'tools':
				return $this->handle_tools( $slug, $as_json );

			default:
				return new WP_Error(
					'unknown_action',
					sprintf(
						/* translators: %s: unknown sub-action */
						__( 'Unknown sub-action "%s". Valid: list, show, enable, disable, tools.', 'mcp-ai-wpoos-pro' ),
						$action
					)
				);
		}
	}

	/**
	 * Handle `list`.
	 *
	 * @param bool $as_json JSON envelope flag.
	 * @return array
	 */
	private function handle_list( $as_json ) {
		$registry = WP_MCP_AI_Toolkit_Server_Registry::get_instance();
		$servers  = $registry->all();
		$rows     = array();
		foreach ( $servers as $server ) {
			$rows[] = array(
				'slug'       => $server->get_slug(),
				'name'       => $server->get_name(),
				'enabled'    => (bool) $server->is_enabled(),
				'version'    => $server->get_version(),
				'tool_count' => $server instanceof WP_MCP_AI_Toolkit_Server_Base
					? count( $server->effective_tool_slugs() )
					: 0,
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( (string) $a['slug'], (string) $b['slug'] );
			}
		);

		$data = array(
			'count'   => count( $rows ),
			'servers' => $rows,
		);

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
				'data'    => $data,
			);
		}

		$out = sprintf(
			/* translators: %d: server count */
			_n( '## Toolkit MCP Servers (%d)', '## Toolkit MCP Servers (%d)', count( $rows ), 'mcp-ai-wpoos-pro' ),
			count( $rows )
		) . "\n\n";

		if ( empty( $rows ) ) {
			$out .= __( '_No toolkit MCP servers registered._', 'mcp-ai-wpoos-pro' );
			return array(
				'success' => true,
				'message' => $out,
				'data'    => $data,
			);
		}

		$out .= "| Slug | Name | Enabled | Tools | Version |\n";
		$out .= "|------|------|---------|-------|---------|\n";
		foreach ( $rows as $row ) {
			$out .= sprintf(
				"| `%s` | %s | %s | %d | %s |\n",
				esc_html( $row['slug'] ),
				esc_html( $row['name'] ),
				$row['enabled'] ? '✅' : '⛔',
				(int) $row['tool_count'],
				esc_html( $row['version'] )
			);
		}

		return array(
			'success' => true,
			'message' => $out,
			'data'    => $data,
		);
	}

	/**
	 * Handle `show <slug>`.
	 *
	 * @param string $slug    Server slug.
	 * @param bool   $as_json JSON envelope flag.
	 * @return array|WP_Error
	 */
	private function handle_show( $slug, $as_json ) {
		$server = $this->require_server( $slug );
		if ( is_wp_error( $server ) ) {
			return $server;
		}

		$descriptor = $server->get_descriptor();

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => wp_json_encode( $descriptor, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
				'data'    => $descriptor,
			);
		}

		$out  = sprintf( "## MCP Server: `%s`\n\n", esc_html( $descriptor['slug'] ) );
		$out .= sprintf( "- **Name:** %s\n", esc_html( $descriptor['name'] ) );
		$out .= sprintf( "- **Version:** %s\n", esc_html( $descriptor['version'] ) );
		$out .= sprintf( "- **Enabled:** %s\n", ! empty( $descriptor['enabled'] ) ? '✅' : '⛔' );
		$out .= sprintf( "- **Tools:** %d\n", (int) $descriptor['tool_count'] );
		$out .= sprintf( "- **Native surfaces:** %d\n", count( (array) $descriptor['native_surfaces'] ) );
		$out .= sprintf( "- **Mounted surfaces:** %d\n", count( (array) $descriptor['mounted_surfaces'] ) );
		if ( isset( $descriptor['limits'] ) && is_array( $descriptor['limits'] ) ) {
			$out .= sprintf(
				"- **Limits:** %d req/min (0 = unlimited), %d byte payload cap (0 = no limit), %d max iterations (0 = inherit global)\n",
				(int) ( $descriptor['limits']['requests_per_minute'] ?? 0 ),
				(int) ( $descriptor['limits']['max_payload_bytes'] ?? 0 ),
				(int) ( $descriptor['limits']['max_iterations'] ?? 0 )
			);
		}
		if ( isset( $descriptor['endpoints']['jsonrpc'] ) ) {
			$out .= sprintf( "- **JSON-RPC endpoint:** `%s`\n", esc_url_raw( (string) $descriptor['endpoints']['jsonrpc'] ) );
		}

		if ( ! empty( $descriptor['description'] ) ) {
			$out .= "\n" . esc_html( $descriptor['description'] ) . "\n";
		}

		return array(
			'success' => true,
			'message' => $out,
			'data'    => $descriptor,
		);
	}

	/**
	 * Handle `enable` / `disable`.
	 *
	 * @param string $slug    Server slug.
	 * @param bool   $enabled Target enabled state.
	 * @param bool   $as_json JSON envelope flag.
	 * @return array|WP_Error
	 */
	private function handle_toggle( $slug, $enabled, $as_json ) {
		$server = $this->require_server( $slug );
		if ( is_wp_error( $server ) ) {
			return $server;
		}

		if ( ! ( $server instanceof WP_MCP_AI_Toolkit_Server_Base ) ) {
			return new WP_Error(
				'unsupported_server',
				__( 'Server does not extend WP_MCP_AI_Toolkit_Server_Base and cannot be toggled.', 'mcp-ai-wpoos-pro' )
			);
		}

		$config            = $server->get_configuration();
		$config['enabled'] = (bool) $enabled;
		$server->update_configuration( $config );

		$data = array(
			'slug'    => $server->get_slug(),
			'enabled' => (bool) $server->is_enabled(),
		);

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
				'data'    => $data,
			);
		}

		$message = $enabled
			? sprintf(
				/* translators: %s: server slug */
				__( '✅ Enabled MCP server `%s`.', 'mcp-ai-wpoos-pro' ),
				$server->get_slug()
			)
			: sprintf(
				/* translators: %s: server slug */
				__( '⛔ Disabled MCP server `%s`.', 'mcp-ai-wpoos-pro' ),
				$server->get_slug()
			);

		return array(
			'success' => true,
			'message' => $message,
			'data'    => $data,
		);
	}

	/**
	 * Handle `tools <slug>`.
	 *
	 * @param string $slug    Server slug.
	 * @param bool   $as_json JSON envelope flag.
	 * @return array|WP_Error
	 */
	private function handle_tools( $slug, $as_json ) {
		$server = $this->require_server( $slug );
		if ( is_wp_error( $server ) ) {
			return $server;
		}

		$tools = $server instanceof WP_MCP_AI_Toolkit_Server_Base
			? $server->effective_tool_slugs()
			: array();
		sort( $tools );

		$data = array(
			'slug'  => $server->get_slug(),
			'count' => count( $tools ),
			'tools' => $tools,
		);

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
				'data'    => $data,
			);
		}

		$out = sprintf(
			/* translators: 1: server slug, 2: tool count */
			__( "## Tools on `%1\$s` (%2\$d)\n\n", 'mcp-ai-wpoos-pro' ),
			$server->get_slug(),
			count( $tools )
		);

		if ( empty( $tools ) ) {
			$out .= __( '_No tools exposed by this server._', 'mcp-ai-wpoos-pro' );
		} else {
			foreach ( $tools as $tool_slug ) {
				$out .= sprintf( "- `%s`\n", esc_html( $tool_slug ) );
			}
		}

		return array(
			'success' => true,
			'message' => $out,
			'data'    => $data,
		);
	}

	/**
	 * Resolve a server slug or return a WP_Error.
	 *
	 * @param string $slug Server slug.
	 * @return WP_MCP_AI_Toolkit_Server_Interface|WP_Error
	 */
	private function require_server( $slug ) {
		if ( '' === $slug ) {
			return new WP_Error(
				'missing_slug',
				__( 'Server slug is required. Try /mcp-server list.', 'mcp-ai-wpoos-pro' )
			);
		}

		$server = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( $slug );
		if ( null === $server ) {
			return new WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: server slug */
					__( 'No MCP server registered with slug `%s`.', 'mcp-ai-wpoos-pro' ),
					$slug
				)
			);
		}
		return $server;
	}
}
