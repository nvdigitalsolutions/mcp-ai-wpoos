<?php
/**
 * WP-CLI credential management commands for NV oOS.
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
 * Manage assistant credentials (API tokens) from the command line.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_CLI_Credential_Command extends WP_MCP_AI_CLI_Base_Command {

	/**
	 * List credentials issued for an assistant, or across all assistants.
	 *
	 * When an assistant ID is provided, lists credentials for that assistant
	 * only. When omitted, lists credentials across all assistants.
	 *
	 * ## OPTIONS
	 *
	 * [<assistant-id>]
	 * : The assistant post ID. If omitted, all assistants are queried.
	 *
	 * [--format=<format>]
	 * : Render output in the given format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List all credentials for assistant 42.
	 *     $ wp mcp-ai credential list 42
	 *
	 *     # List credentials across all assistants.
	 *     $ wp mcp-ai credential list
	 *
	 *     # Output as JSON.
	 *     $ wp mcp-ai credential list 42 --format=json
	 *
	 *     # Export credential IDs only.
	 *     $ wp mcp-ai credential list --format=ids
	 *
	 * @subcommand list
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function list( $args, $assoc_args ) {
		$assistant_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
		$format       = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( ! class_exists( 'WP_MCP_AI_Credentials' ) ) {
			WP_CLI::error( __( 'Credentials class is not available.', 'mcp-ai-wpoos' ) );
		}

		$items = array();

		if ( $assistant_id ) {
			// Single assistant: keep existing behaviour.
			$this->assert_assistant_exists( $assistant_id );

			$credentials = WP_MCP_AI_Credentials::get_credentials( $assistant_id );

			if ( empty( $credentials ) ) {
				WP_CLI::log(
					/* translators: %d: assistant ID */
					sprintf( __( 'No credentials found for assistant %d.', 'mcp-ai-wpoos' ), $assistant_id )
				);
				return;
			}

			foreach ( $credentials as $cred ) {
				$items[] = array(
					'id'           => isset( $cred['id'] ) ? $cred['id'] : '',
					'assistant_id' => $assistant_id,
					'user_id'      => isset( $cred['user_id'] ) ? $cred['user_id'] : '',
					'created_at'   => isset( $cred['created_at'] ) ? $cred['created_at'] : '',
					'label'        => isset( $cred['label'] ) ? $cred['label'] : '',
				);
			}
		} else {
			// No assistant ID: query all assistants.
			$posts = get_posts(
				array(
					'post_type'      => 'mcp_ai_assistant',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);

			if ( empty( $posts ) ) {
				WP_CLI::log( __( 'No assistants found.', 'mcp-ai-wpoos' ) );
				return;
			}

			foreach ( $posts as $aid ) {
				$credentials = WP_MCP_AI_Credentials::get_credentials( $aid );

				if ( empty( $credentials ) ) {
					continue;
				}

				foreach ( $credentials as $cred ) {
					$items[] = array(
						'id'           => isset( $cred['id'] ) ? $cred['id'] : '',
						'assistant_id' => $aid,
						'user_id'      => isset( $cred['user_id'] ) ? $cred['user_id'] : '',
						'created_at'   => isset( $cred['created_at'] ) ? $cred['created_at'] : '',
						'label'        => isset( $cred['label'] ) ? $cred['label'] : '',
					);
				}
			}

			if ( empty( $items ) ) {
				WP_CLI::log( __( 'No credentials found across any assistant.', 'mcp-ai-wpoos' ) );
				return;
			}
		}

		if ( 'ids' === $format ) {
			$ids = array();
			foreach ( $items as $item ) {
				if ( ! empty( $item['id'] ) ) {
					$ids[] = $item['id'];
				}
			}
			WP_CLI::line( implode( ' ', $ids ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $items, array( 'id', 'assistant_id', 'user_id', 'created_at', 'label' ) );
	}

	/**
	 * Issue a new credential (API token) for an assistant.
	 *
	 * The plaintext token is printed once and cannot be retrieved again.
	 *
	 * ## OPTIONS
	 *
	 * <assistant-id>
	 * : The assistant post ID.
	 *
	 * [--user=<id>]
	 * : WordPress user ID to associate with the credential (default: 1).
	 *
	 * [--porcelain]
	 * : Output the raw token only.
	 *
	 * ## EXAMPLES
	 *
	 *     # Issue a credential for assistant 42.
	 *     $ wp mcp-ai credential issue 42
	 *
	 *     # Issue and capture the token in a variable.
	 *     $ TOKEN=$(wp mcp-ai credential issue 42 --porcelain)
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function issue( $args, $assoc_args ) {
		$assistant_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
		$user_id      = absint( \WP_CLI\Utils\get_flag_value( $assoc_args, 'user', 1 ) );
		$porcelain    = \WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false );

		if ( ! $assistant_id ) {
			WP_CLI::error( __( 'Please provide a valid assistant ID.', 'mcp-ai-wpoos' ) );
		}

		$this->require_capability( 'manage_options' );

		$this->assert_assistant_exists( $assistant_id );

		if ( ! class_exists( 'WP_MCP_AI_Credentials' ) ) {
			WP_CLI::error( __( 'Credentials class is not available.', 'mcp-ai-wpoos' ) );
		}

		$result = WP_MCP_AI_Credentials::issue_credential( $assistant_id, $user_id );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$token = $result['token'] ?? '';

		if ( ! $token ) {
			WP_CLI::error( __( 'Credential issued but token was empty. Check plugin logs.', 'mcp-ai-wpoos' ) );
		}

		if ( $porcelain ) {
			WP_CLI::line( $token );
			return;
		}

		WP_CLI::success(
			/* translators: %d: assistant ID */
			sprintf( __( 'Credential issued for assistant %d.', 'mcp-ai-wpoos' ), $assistant_id )
		);
		WP_CLI::log( __( 'Token (save this — it will not be shown again):', 'mcp-ai-wpoos' ) );
		WP_CLI::line( $token );
	}

	/**
	 * Revoke a credential for an assistant.
	 *
	 * ## OPTIONS
	 *
	 * <assistant-id>
	 * : The assistant post ID.
	 *
	 * <credential-id>
	 * : The credential identifier (e.g. cred_abc123).
	 *
	 * [--user=<id>]
	 * : WordPress user ID authorising the revocation (default: 1).
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Revoke a credential.
	 *     $ wp mcp-ai credential revoke 42 cred_abc123
	 *
	 *     # Revoke without prompting.
	 *     $ wp mcp-ai credential revoke 42 cred_abc123 --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function revoke( $args, $assoc_args ) {
		$assistant_id  = isset( $args[0] ) ? absint( $args[0] ) : 0;
		$credential_id = isset( $args[1] ) ? sanitize_key( $args[1] ) : '';
		$user_id       = absint( \WP_CLI\Utils\get_flag_value( $assoc_args, 'user', 1 ) );
		$yes           = \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false );

		if ( ! $assistant_id ) {
			WP_CLI::error( __( 'Please provide a valid assistant ID.', 'mcp-ai-wpoos' ) );
		}

		if ( ! $credential_id ) {
			WP_CLI::error( __( 'Please provide a credential ID.', 'mcp-ai-wpoos' ) );
		}

		$this->require_capability( 'manage_options' );

		$this->assert_assistant_exists( $assistant_id );

		if ( ! class_exists( 'WP_MCP_AI_Credentials' ) ) {
			WP_CLI::error( __( 'Credentials class is not available.', 'mcp-ai-wpoos' ) );
		}

		if ( ! $yes ) {
			WP_CLI::confirm(
				/* translators: 1: credential ID, 2: assistant ID */
				sprintf( __( 'Revoke credential "%1$s" from assistant %2$d?', 'mcp-ai-wpoos' ), $credential_id, $assistant_id )
			);
		}

		$result = WP_MCP_AI_Credentials::revoke_credential( $assistant_id, $credential_id, $user_id );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		/* translators: 1: credential ID, 2: assistant ID */
		WP_CLI::success( sprintf( __( 'Credential "%1$s" revoked from assistant %2$d.', 'mcp-ai-wpoos' ), $credential_id, $assistant_id ) );
	}

	/**
	 * Assert that an assistant post exists and is the correct post type.
	 *
	 * Calls WP_CLI::error and exits if the assertion fails.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return void
	 */
	protected function assert_assistant_exists( $assistant_id ) {
		$post = get_post( $assistant_id );

		if ( ! $post || 'mcp_ai_assistant' !== $post->post_type ) {
			/* translators: %d: assistant ID */
			WP_CLI::error( sprintf( __( 'Assistant %d not found.', 'mcp-ai-wpoos' ), $assistant_id ) );
		}
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai credential', 'WP_MCP_AI_CLI_Credential_Command' );
}
