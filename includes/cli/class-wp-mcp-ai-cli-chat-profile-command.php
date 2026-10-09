<?php
/**
 * WP-CLI command for managing per-user chat profiles.
 *
 * Provides the no-JS management surface for sites that do not use the Pro
 * SPA: administrators can list, inspect, and set chat profiles from the
 * command line, and the audit-flag coverage reporter helps harden the
 * read-only boundary (proposal 015, Phase B.7).
 *
 * @package WP_MCP_AI
 * @since   2.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-cli-base-command.php';

/**
 * Manage chat profiles from the command line.
 *
 * @since 2.2.0
 */
class WP_MCP_AI_CLI_Chat_Profile_Command extends WP_MCP_AI_CLI_Base_Command {

	/**
	 * List the registered chat profiles and the site defaults.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai chat-profile list
	 *     $ wp mcp-ai chat-profile list --format=json
	 *
	 * @subcommand list
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$format  = $assoc_args['format'] ?? 'table';
		$rows    = array();
		$default = WP_MCP_AI_Chat_Profile_Manager::get_site_default_slug();
		$guest   = WP_MCP_AI_Chat_Profile_Manager::get_guest_slug();

		foreach ( WP_MCP_AI_Chat_Profile_Registry::get_profiles() as $profile ) {
			$rows[] = array(
				'slug'        => $profile->get_slug(),
				'label'       => $profile->get_label(),
				'description' => $profile->get_description(),
				'is_default'  => $profile->get_slug() === $default ? 'yes' : 'no',
				'guest_slug'  => $profile->get_slug() === $guest ? 'yes' : 'no',
				'restricts'   => $profile->is_restrictive() ? 'yes' : 'no',
			);
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'slug', 'label', 'description', 'is_default', 'guest_slug', 'restricts' ) );
	}

	/**
	 * Get the effective chat profile for one or more users.
	 *
	 * ## OPTIONS
	 *
	 * <user>...
	 * : One or more user IDs, logins, or emails.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai chat-profile get 1 admin@example.com
	 *
	 * @subcommand get
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function get( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$format = $assoc_args['format'] ?? 'table';
		$rows   = array();

		foreach ( $args as $identifier ) {
			$user = get_user_by( 'login', $identifier );
			if ( ! $user ) {
				$user = get_user_by( 'email', $identifier );
			}
			if ( ! $user && is_numeric( $identifier ) ) {
				$user = get_user_by( 'ID', (int) $identifier );
			}
			if ( ! $user ) {
				\WP_CLI::warning( sprintf( 'Unknown user: %s', $identifier ) );
				continue;
			}

			$rows[] = array(
				'user_id' => $user->ID,
				'login'   => $user->user_login,
				'profile' => WP_MCP_AI_Chat_Profile_Manager::resolve_slug( $user->ID, null ),
			);
		}

		if ( empty( $rows ) ) {
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'user_id', 'login', 'profile' ) );
	}

	/**
	 * Set the chat profile for a user.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email.
	 *
	 * <profile>
	 * : Profile slug (e.g. write, read-only).
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai chat-profile set 42 read-only
	 *     $ wp mcp-ai chat-profile set editor-user write
	 *
	 * @subcommand set
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function set( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		list( $identifier, $slug ) = array_pad( $args, 2, '' );

		if ( '' === $identifier || '' === $slug ) {
			\WP_CLI::error( 'Usage: wp mcp-ai chat-profile set <user> <profile>' );
		}

		$user = get_user_by( 'login', $identifier );
		if ( ! $user ) {
			$user = get_user_by( 'email', $identifier );
		}
		if ( ! $user && is_numeric( $identifier ) ) {
			$user = get_user_by( 'ID', (int) $identifier );
		}
		if ( ! $user ) {
			\WP_CLI::error( sprintf( 'Unknown user: %s', $identifier ) );
		}

		// The CLI is an administrator surface (server access implied): set the
		// profile on the operator's authority regardless of the target user's
		// own capabilities.
		$result = WP_MCP_AI_Chat_Profile_Manager::set_user_profile( $user->ID, $slug, true );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success(
			sprintf(
				/* translators: 1: user login, 2: profile slug */
				'Chat profile for %1$s set to %2$s.',
				$user->user_login,
				$slug
			)
		);
	}

	/**
	 * Audit capability-flag coverage for the read-only boundary.
	 *
	 * Lists registered tools that declare no capability flags — they pass the
	 * read-only gate by default. Output is a hardening worklist (proposal 015,
	 * Phase B.7), not an enforcement change.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai chat-profile audit-flags
	 *
	 * @subcommand audit-flags
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function audit_flags( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$format  = $assoc_args['format'] ?? 'table';
		$rows    = array();
		$flagged = 0;
		$total   = 0;

		if ( function_exists( 'wp_mcp_ai_container' ) ) {
			$container = wp_mcp_ai_container();
			$registry  = $container ? $container->get( 'tool.registry' ) : null;
			if ( $registry instanceof WP_MCP_AI_Tool_Registry ) {
				foreach ( $registry->get_all_tools() as $slug => $tool ) {
					++$total;
					$flags = $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface
						? (array) $tool->get_capability_flags()
						: array();
					if ( ! empty( $flags ) ) {
						++$flagged;
						continue;
					}
					$rows[] = array(
						'slug' => $slug,
						'name' => method_exists( $tool, 'get_name' ) ? $tool->get_name() : $slug,
					);
				}
			}
		}

		if ( empty( $rows ) ) {
			\WP_CLI::success(
				sprintf(
					/* translators: %d: number of flagged tools */
					'Flag coverage complete: all %d registered tools declare capability flags.',
					$total
				)
			);
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'slug', 'name' ) );
		\WP_CLI::warning(
			sprintf(
				/* translators: 1: unflagged count, 2: flagged count */
				'%1$d of %2$d tools declare no capability flags and pass the read-only gate by default.',
				count( $rows ),
				$total
			)
		);
	}
}

WP_CLI::add_command( 'mcp-ai chat-profile', 'WP_MCP_AI_CLI_Chat_Profile_Command' );
