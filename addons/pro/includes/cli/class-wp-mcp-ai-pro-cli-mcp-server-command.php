<?php
/**
 * WP-CLI MCP-server management commands for NV oOS Pro.
 *
 * Exposes per-toolkit MCP server state (enabled flag, effective tool slugs, and
 * the full descriptor) through the `wp mcp-ai mcp-server` parent command. Mutating
 * sub-commands write to the same option (`wp_mcp_ai_toolkit_mcp_server_{slug}`)
 * that the admin settings tab and the `/mcp-server` slash command use — so there
 * is a single source of truth.
 *
 * Commands:
 *   wp mcp-ai mcp-server list           [--status=<all|enabled|disabled>] [--format=<fmt>]
 *   wp mcp-ai mcp-server get            <slug>  [--format=<fmt>]
 *   wp mcp-ai mcp-server enable         <slug>
 *   wp mcp-ai mcp-server disable        <slug>  [--yes]
 *   wp mcp-ai mcp-server tools          <slug>  [--format=<fmt>]
 *   wp mcp-ai mcp-server token-generate <slug>  [--label=<label>]
 *   wp mcp-ai mcp-server token-list     <slug>  [--format=<fmt>]
 *   wp mcp-ai mcp-server token-revoke   <slug> <prefix> [--yes]
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage CLI
 * @since      1.3.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-pro-cli-base-command.php';

/**
 * Manage per-toolkit MCP servers from the command line.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_CLI_Mcp_Server_Command extends WP_MCP_AI_Pro_CLI_Base_Command {

	/**
	 * List all registered toolkit MCP servers and their state.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by server state.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - enabled
	 *   - disabled
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
	 * ## EXAMPLES
	 *
	 *     # List all servers.
	 *     $ wp mcp-ai mcp-server list
	 *
	 *     # Show only enabled servers.
	 *     $ wp mcp-ai mcp-server list --status=enabled
	 *
	 *     # Export as JSON.
	 *     $ wp mcp-ai mcp-server list --format=json
	 *
	 * @subcommand list
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function list_( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$registry = $this->require_registry();

		$status_filter = \WP_CLI\Utils\get_flag_value( $assoc_args, 'status', 'all' );
		$format        = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$servers = $registry->all();
		if ( empty( $servers ) ) {
			WP_CLI::log( __( 'No toolkit MCP servers are registered.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		if ( 'ids' === $format ) {
			$slugs = array_keys( $servers );
			if ( 'all' !== $status_filter ) {
				$want_enabled = ( 'enabled' === $status_filter );
				$slugs        = array_values(
					array_filter(
						$slugs,
						static function ( $slug ) use ( $servers, $want_enabled ) {
							return (bool) $servers[ $slug ]->is_enabled() === $want_enabled;
						}
					)
				);
			}
			WP_CLI::line( implode( ' ', $slugs ) );
			return;
		}

		$items = array();
		foreach ( $servers as $server ) {
			$is_enabled = (bool) $server->is_enabled();
			$status     = $is_enabled ? 'enabled' : 'disabled';
			if ( 'all' !== $status_filter && $status_filter !== $status ) {
				continue;
			}
			$tool_count = ( $server instanceof WP_MCP_AI_Toolkit_Server_Base )
				? count( $server->effective_tool_slugs() )
				: 0;
			$items[]    = array(
				'slug'       => $server->get_slug(),
				'name'       => $server->get_name(),
				'status'     => $status,
				'tool_count' => $tool_count,
				'version'    => $server->get_version(),
			);
		}

		if ( empty( $items ) ) {
			WP_CLI::log( __( 'No servers match the given filter.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		usort(
			$items,
			static function ( $a, $b ) {
				return strcmp( $a['slug'], $b['slug'] );
			}
		);

		\WP_CLI\Utils\format_items( $format, $items, array( 'slug', 'name', 'status', 'tool_count', 'version' ) );
	}

	/**
	 * Get detailed information about a single MCP server.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Server slug (e.g. crm, health, ecommerce).
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
	 *     # Show CRM server details.
	 *     $ wp mcp-ai mcp-server get crm
	 *
	 *     # Export as JSON.
	 *     $ wp mcp-ai mcp-server get crm --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function get( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$server = $this->require_server( $args );
		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$descriptor = $server->get_descriptor();
		$limits     = isset( $descriptor['limits'] ) && is_array( $descriptor['limits'] )
			? $descriptor['limits']
			: array(
				'requests_per_minute' => 0,
				'max_payload_bytes'   => 0,
				'max_iterations'      => 0,
			);

		$row = array(
			array(
				'key'   => 'slug',
				'value' => $descriptor['slug'],
			),
			array(
				'key'   => 'name',
				'value' => $descriptor['name'],
			),
			array(
				'key'   => 'version',
				'value' => $descriptor['version'],
			),
			array(
				'key'   => 'enabled',
				'value' => $descriptor['enabled'] ? 'yes' : 'no',
			),
			array(
				'key'   => 'tool_count',
				'value' => $descriptor['tool_count'],
			),
			array(
				'key'   => 'native_surfaces',
				'value' => count( (array) $descriptor['native_surfaces'] ),
			),
			array(
				'key'   => 'mounted_surfaces',
				'value' => count( (array) $descriptor['mounted_surfaces'] ),
			),
			array(
				'key'   => 'requests_per_minute',
				'value' => (int) $limits['requests_per_minute'],
			),
			array(
				'key'   => 'max_payload_bytes',
				'value' => (int) $limits['max_payload_bytes'],
			),
			array(
				'key'   => 'max_iterations',
				'value' => (int) $limits['max_iterations'],
			),
			array(
				'key'   => 'jsonrpc_endpoint',
				'value' => $descriptor['endpoints']['jsonrpc'] ?? '',
			),
		);

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $descriptor, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( 'yaml' === $format ) {
			foreach ( $row as $r ) {
				WP_CLI::line( $r['key'] . ': ' . $r['value'] );
			}
			return;
		}

		\WP_CLI\Utils\format_items( 'table', $row, array( 'key', 'value' ) );
	}

	/**
	 * Enable a toolkit MCP server.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Server slug (e.g. crm, health).
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai mcp-server enable crm
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function enable( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$server = $this->require_server( $args );
		$this->toggle_server( $server, true );
		/* translators: %s: server slug */
		WP_CLI::success( sprintf( __( 'MCP server "%s" enabled.', 'mcp-ai-wpoos-pro' ), $server->get_slug() ) );
	}

	/**
	 * Disable a toolkit MCP server.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Server slug (e.g. crm, health).
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai mcp-server disable crm --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function disable( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$server = $this->require_server( $args );
		$yes    = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false );

		if ( ! $yes ) {
			WP_CLI::confirm(
				sprintf(
					/* translators: %s: server slug */
					__( 'Disable MCP server "%s"? MCP clients targeting this endpoint will stop receiving tool responses.', 'mcp-ai-wpoos-pro' ),
					$server->get_slug()
				)
			);
		}

		$this->toggle_server( $server, false );
		/* translators: %s: server slug */
		WP_CLI::success( sprintf( __( 'MCP server "%s" disabled.', 'mcp-ai-wpoos-pro' ), $server->get_slug() ) );
	}

	/**
	 * List effective tool slugs for a toolkit MCP server.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Server slug (e.g. crm, health).
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
	 *     # List tools for the CRM server.
	 *     $ wp mcp-ai mcp-server tools crm
	 *
	 *     # One-liner to check a specific tool slug is exposed.
	 *     $ wp mcp-ai mcp-server tools crm --format=ids | tr ' ' '\n' | grep crm_manage_companies
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function tools( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$server = $this->require_server( $args );
		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$slugs = ( $server instanceof WP_MCP_AI_Toolkit_Server_Base )
			? $server->effective_tool_slugs()
			: array();
		sort( $slugs );

		if ( empty( $slugs ) ) {
			WP_CLI::log(
				sprintf(
					/* translators: %s: server slug */
					__( 'Server "%s" exposes no tools (allowlist may be empty or tool classes missing).', 'mcp-ai-wpoos-pro' ),
					$server->get_slug()
				)
			);
			return;
		}

		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', $slugs ) );
			return;
		}

		$items = array_map(
			static function ( $slug ) {
				return array( 'tool_slug' => $slug );
			},
			$slugs
		);

		\WP_CLI\Utils\format_items( $format, $items, array( 'tool_slug' ) );
	}

	// ─── Phase 3d — token management ─────────────────────────────────────────

	/**
	 * Generate a new API bearer token for a toolkit MCP server.
	 *
	 * The raw token is printed once and never stored — treat it like a password.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Server slug (e.g. crm, health).
	 *
	 * [--label=<label>]
	 * : Human-readable label for the token (e.g. "ci-pipeline", "staging-agent").
	 * ---
	 * default:
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai mcp-server token-generate crm --label=ci-pipeline
	 *
	 * @subcommand token-generate
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function token_generate( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$server = $this->require_server( $args );
		$slug   = $server->get_slug();
		$label  = sanitize_text_field( \WP_CLI\Utils\get_flag_value( $assoc_args, 'label', '' ) );

		if ( ! class_exists( 'WP_MCP_AI_Pro_Toolkit_Server_Token' ) ) {
			WP_CLI::error( __( 'Token service is not loaded.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = WP_MCP_AI_Pro_Toolkit_Server_Token::generate( $slug, $label );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success(
			sprintf(
				/* translators: %s: server slug */
				__( 'Token generated for server "%s". Copy it now — it will not be shown again.', 'mcp-ai-wpoos-pro' ),
				$slug
			)
		);
		WP_CLI::line( '' );
		WP_CLI::line( $result['token'] );
		WP_CLI::line( '' );
		WP_CLI::log(
			sprintf(
				/* translators: 1: token prefix, 2: token label */
				__( 'Prefix: %1$s  Label: %2$s', 'mcp-ai-wpoos-pro' ),
				$result['prefix'],
				$result['label']
			)
		);
	}

	/**
	 * List API tokens for a toolkit MCP server (metadata only — secrets omitted).
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Server slug (e.g. crm, health).
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
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai mcp-server token-list crm
	 *
	 * @subcommand token-list
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function token_list( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$server = $this->require_server( $args );
		$slug   = $server->get_slug();
		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( ! class_exists( 'WP_MCP_AI_Pro_Toolkit_Server_Token' ) ) {
			WP_CLI::error( __( 'Token service is not loaded.', 'mcp-ai-wpoos-pro' ) );
		}

		$tokens = WP_MCP_AI_Pro_Toolkit_Server_Token::list_tokens( $slug );
		if ( empty( $tokens ) ) {
			WP_CLI::log(
				sprintf(
					/* translators: %s: server slug */
					__( 'No tokens for server "%s".', 'mcp-ai-wpoos-pro' ),
					$slug
				)
			);
			return;
		}

		// Make timestamps human-readable for table/yaml/csv output.
		if ( 'json' !== $format ) {
			$tokens = array_map(
				static function ( $t ) {
					$t['created_at']   = $t['created_at'] ? gmdate( 'Y-m-d H:i:s', $t['created_at'] ) : '-';
					$t['last_used_at'] = $t['last_used_at'] ? gmdate( 'Y-m-d H:i:s', $t['last_used_at'] ) : 'never';
					return $t;
				},
				$tokens
			);
		}

		\WP_CLI\Utils\format_items( $format, $tokens, array( 'prefix', 'label', 'created_at', 'last_used_at' ) );
	}

	/**
	 * Revoke an API token for a toolkit MCP server.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Server slug (e.g. crm, health).
	 *
	 * <prefix>
	 * : 8-character token prefix (shown by `token-list`).
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai mcp-server token-revoke crm a1b2c3d4 --yes
	 *
	 * @subcommand token-revoke
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function token_revoke( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$server = $this->require_server( $args );
		$slug   = $server->get_slug();

		if ( empty( $args[1] ) ) {
			WP_CLI::error( __( 'Please provide the token prefix. Run `wp mcp-ai mcp-server token-list <slug>` to see prefixes.', 'mcp-ai-wpoos-pro' ) );
		}
		$prefix = sanitize_key( (string) $args[1] );

		if ( ! class_exists( 'WP_MCP_AI_Pro_Toolkit_Server_Token' ) ) {
			WP_CLI::error( __( 'Token service is not loaded.', 'mcp-ai-wpoos-pro' ) );
		}

		$yes = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false );
		if ( ! $yes ) {
			WP_CLI::confirm(
				sprintf(
					/* translators: 1: prefix, 2: server slug */
					__( 'Revoke token "%1$s" for server "%2$s"? Any client using this token will immediately lose access.', 'mcp-ai-wpoos-pro' ),
					$prefix,
					$slug
				)
			);
		}

		$removed = WP_MCP_AI_Pro_Toolkit_Server_Token::revoke( $slug, $prefix );
		if ( ! $removed ) {
			WP_CLI::error(
				sprintf(
					/* translators: %s: token prefix */
					__( 'Token "%s" not found.', 'mcp-ai-wpoos-pro' ),
					$prefix
				)
			);
		}

		WP_CLI::success(
			sprintf(
				/* translators: 1: prefix, 2: server slug */
				__( 'Token "%1$s" revoked for server "%2$s".', 'mcp-ai-wpoos-pro' ),
				$prefix,
				$slug
			)
		);
	}

	// ─── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Fetch and assert the registry is loaded.
	 *
	 * @return WP_MCP_AI_Toolkit_Server_Registry
	 */
	private function require_registry() {
		if ( ! class_exists( 'WP_MCP_AI_Toolkit_Server_Registry' ) ) {
			WP_CLI::error( __( 'Toolkit MCP Server Registry is not loaded. Ensure the Pro addon is active.', 'mcp-ai-wpoos-pro' ) );
		}
		// Bootstrap so servers are registered even when called very early.
		WP_MCP_AI_Toolkit_Server_Registry::get_instance()->bootstrap();
		return WP_MCP_AI_Toolkit_Server_Registry::get_instance();
	}

	/**
	 * Resolve $args[0] to a server or WP_CLI::error().
	 *
	 * @param array $args Positional argument array (element 0 = slug).
	 * @return WP_MCP_AI_Toolkit_Server_Interface
	 */
	private function require_server( $args ) {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( __( 'Please provide a server slug. Run `wp mcp-ai mcp-server list` to see all slugs.', 'mcp-ai-wpoos-pro' ) );
		}
		$slug     = sanitize_key( (string) $args[0] );
		$registry = $this->require_registry();
		$server   = $registry->get( $slug );
		if ( null === $server ) {
			/* translators: %s: slug */
			WP_CLI::error( sprintf( __( 'No MCP server registered with slug "%s".', 'mcp-ai-wpoos-pro' ), $slug ) );
		}
		return $server;
	}

	/**
	 * Toggle a server's enabled flag.
	 *
	 * @param WP_MCP_AI_Toolkit_Server_Interface $server  Server instance.
	 * @param bool                               $enabled Target state.
	 */
	private function toggle_server( $server, $enabled ) {
		if ( ! ( $server instanceof WP_MCP_AI_Toolkit_Server_Base ) ) {
			WP_CLI::error( __( 'Server does not extend WP_MCP_AI_Toolkit_Server_Base and cannot be toggled.', 'mcp-ai-wpoos-pro' ) );
		}
		$config            = $server->get_configuration();
		$config['enabled'] = (bool) $enabled;
		$server->update_configuration( $config );
	}
}

WP_CLI::add_command( 'mcp-ai mcp-server', 'WP_MCP_AI_Pro_CLI_Mcp_Server_Command' );
