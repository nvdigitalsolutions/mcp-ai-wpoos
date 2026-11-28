<?php
/**
 * Mailjet credential resolution across Remote Sites connections and site settings.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves which Mailjet API key pair a tool call should use.
 *
 * Three sources, in strict order:
 *
 *  1. An explicit `connection_id` argument.
 *  2. The single enabled `mailjet_mcp` connection the assistant may access.
 *  3. The site-wide `mailjet_api_key` / `mailjet_api_secret` settings — but only
 *     when the assistant is unrestricted, or has the synthetic `settings` entry
 *     checked in the assistant editor's Remote Site Connections metabox.
 *
 * The contract mirrors {@see WP_MCP_AI_Pro_Gmail_Client}: the same
 * `_wp_mcp_ai_pro_remote_connections` allowlist, the same "unrestricted when the
 * meta is absent" semantics, and the same refusal to guess between candidates.
 *
 * **Why guessing is refused here.** A Mailjet API key is effectively a
 * subaccount: sender addresses are validated per key and statistics are
 * accounted per key. Picking the wrong one does not merely misroute a request —
 * it reports the wrong subaccount's analytics, or fails a send outright because
 * the sender is not validated for that key.
 *
 * @since 1.2.25
 */
class WP_MCP_AI_Pro_Mailjet_Credentials {

	/**
	 * The connection type that carries a Mailjet key pair.
	 *
	 * @var string
	 */
	const CONNECTION_TYPE = 'mailjet_mcp';

	/**
	 * Assistant meta key holding the per-assistant connection allowlist.
	 *
	 * Shared with every other integration; owned by
	 * {@see WP_MCP_AI_Pro_Metabox_Remote_Connections}.
	 *
	 * @var string
	 */
	const ASSISTANT_META_KEY = '_wp_mcp_ai_pro_remote_connections';

	/**
	 * Synthetic allowlist entry that grants the site-wide settings fallback.
	 *
	 * @var string
	 */
	const SETTINGS_ENTRY = 'settings';

	/**
	 * Resolve credentials for a tool call.
	 *
	 * @since 1.2.25
	 *
	 * @param array  $context       Execution context (uses `assistant_id`).
	 * @param string $connection_id Optional explicit connection ID.
	 * @return array|WP_Error {
	 *     @type string $api_key       Mailjet API key.
	 *     @type string $api_secret    Mailjet API secret.
	 *     @type string $region        'us' or 'eu'.
	 *     @type string $from_email    Sender address override ('' when unset).
	 *     @type string $from_name     Sender name override ('' when unset).
	 *     @type string $source        'connection' or 'settings'.
	 *     @type string $connection_id Connection ID ('' for the settings source).
	 * }
	 */
	public static function resolve( array $context = array(), $connection_id = '' ) {
		$connection_id = is_string( $connection_id ) ? trim( $connection_id ) : '';

		if ( '' !== $connection_id ) {
			return self::resolve_explicit( $connection_id, $context );
		}

		$resolved = self::resolve_auto_connection( $context );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		if ( is_array( $resolved ) ) {
			return $resolved;
		}

		return self::resolve_settings( $context );
	}

	/**
	 * Resolve an explicitly named connection.
	 *
	 * A wrong type, a disabled connection, a foreign connection or a missing key
	 * pair is an error — never a silent fall through to site settings. A tool
	 * that quietly used a different account than the caller named would be far
	 * worse than one that refuses.
	 *
	 * @since 1.2.25
	 *
	 * @param string $connection_id Connection ID.
	 * @param array  $context       Execution context.
	 * @return array|WP_Error
	 */
	private static function resolve_explicit( $connection_id, array $context ) {
		self::load_manager();

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return new WP_Error(
				'wp_mcp_ai_mailjet_manager_missing',
				__( 'The Remote Sites manager is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( ! is_array( $connection ) ) {
			return new WP_Error(
				'wp_mcp_ai_mailjet_connection_not_found',
				/* translators: %s: connection ID. */
				sprintf( __( 'No remote site connection with the ID "%s".', 'mcp-ai-wpoos-pro' ), $connection_id ),
				array( 'status' => 404 )
			);
		}

		$connection_type = isset( $connection['connection_type'] ) ? sanitize_key( (string) $connection['connection_type'] ) : '';

		if ( self::CONNECTION_TYPE !== $connection_type ) {
			return new WP_Error(
				'wp_mcp_ai_mailjet_wrong_connection_type',
				/* translators: 1: connection type, 2: expected type. */
				sprintf( __( 'Connection "%1$s" is a %2$s connection, not a Mailjet connection.', 'mcp-ai-wpoos-pro' ), $connection_id, $connection_type ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $connection['enabled'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_mailjet_connection_disabled',
				/* translators: %s: connection name. */
				sprintf( __( 'The Mailjet connection "%s" is disabled.', 'mcp-ai-wpoos-pro' ), isset( $connection['name'] ) ? (string) $connection['name'] : $connection_id ),
				array( 'status' => 400 )
			);
		}

		if ( ! self::is_connection_enabled_for_assistant( $connection_id, $context ) ) {
			return new WP_Error(
				'wp_mcp_ai_mailjet_connection_not_assigned',
				/* translators: %s: connection name. */
				sprintf( __( 'The Mailjet connection "%s" is not assigned to this assistant. Enable it under Remote Site Connections in the assistant editor.', 'mcp-ai-wpoos-pro' ), isset( $connection['name'] ) ? (string) $connection['name'] : $connection_id ),
				array( 'status' => 403 )
			);
		}

		$credentials = self::read_connection_credentials( $connection );

		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		return $credentials;
	}

	/**
	 * Pick the only enabled Mailjet connection the assistant may use.
	 *
	 * @since 1.2.25
	 *
	 * @param array $context Execution context.
	 * @return array|WP_Error|null Resolved credentials, an ambiguity error, or
	 *                             null when there is nothing to pick.
	 */
	public static function resolve_auto_connection( array $context = array() ) {
		$candidates = self::candidate_connections( $context );

		if ( empty( $candidates ) ) {
			return null;
		}

		if ( count( $candidates ) > 1 ) {
			$labels = array();

			foreach ( $candidates as $candidate_id => $candidate ) {
				$labels[] = sprintf(
					'%1$s (%2$s)',
					$candidate_id,
					isset( $candidate['name'] ) ? (string) $candidate['name'] : $candidate_id
				);
			}

			return new WP_Error(
				'wp_mcp_ai_mailjet_ambiguous_connection',
				sprintf(
					/* translators: %s: comma-separated connection IDs and names. */
					__( 'Several Mailjet connections are available, so no account was chosen automatically — the tool will not guess. Each API key is its own Mailjet subaccount with its own validated senders and its own statistics. Pass one of: %s. Call list_mailjet_connections for their names, regions and enabled state.', 'mcp-ai-wpoos-pro' ),
					implode( ', ', $labels )
				),
				array( 'status' => 400 )
			);
		}

		$auto_id  = (string) array_key_first( $candidates );
		$resolved = self::read_connection_credentials( $candidates[ $auto_id ] );

		return $resolved;
	}

	/**
	 * The site-wide settings fallback.
	 *
	 * @since 1.2.25
	 *
	 * @param array $context Execution context.
	 * @return array|WP_Error
	 */
	private static function resolve_settings( array $context ) {
		if ( ! self::is_settings_fallback_allowed( $context ) ) {
			return new WP_Error(
				'wp_mcp_ai_mailjet_settings_not_assigned',
				__( 'This assistant has no Mailjet connection assigned and is not allowed to use the site-wide Mailjet settings. Assign a Mailjet connection under Remote Site Connections, or tick the site-wide settings row.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		$settings = class_exists( 'WP_MCP_AI_Admin_Settings' ) ? WP_MCP_AI_Admin_Settings::get_settings() : array();

		$api_key    = isset( $settings['mailjet_api_key'] ) ? trim( (string) $settings['mailjet_api_key'] ) : '';
		$api_secret = isset( $settings['mailjet_api_secret'] ) ? trim( (string) $settings['mailjet_api_secret'] ) : '';

		if ( '' === $api_key || '' === $api_secret ) {
			return new WP_Error(
				'wp_mcp_ai_mailjet_missing_credentials',
				__( 'Mailjet API credentials have not been configured. Add a Mailjet connection under Remote Sites, or set the API key and secret in the NV oOS settings.', 'mcp-ai-wpoos-pro' )
			);
		}

		return array(
			'api_key'       => $api_key,
			'api_secret'    => $api_secret,
			'region'        => 'us',
			'from_email'    => isset( $settings['mailjet_from_email'] ) ? (string) $settings['mailjet_from_email'] : '',
			'from_name'     => isset( $settings['mailjet_from_name'] ) ? (string) $settings['mailjet_from_name'] : '',
			'source'        => 'settings',
			'connection_id' => '',
		);
	}

	/**
	 * Every enabled Mailjet connection the assistant may access, keyed by ID.
	 *
	 * @since 1.2.25
	 *
	 * @param array $context       Execution context.
	 * @param bool  $require_keys  When true, connections without a usable key
	 *                             pair are skipped (the shape a tool call needs).
	 * @return array<string, array>
	 */
	public static function candidate_connections( array $context = array(), $require_keys = true ) {
		self::load_manager();

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return array();
		}

		$allowed_ids = self::get_allowed_connection_ids( $context );
		$candidates  = array();

		foreach ( WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections() as $candidate_id => $connection ) {
			if ( ! is_array( $connection ) ) {
				continue;
			}

			$candidate_id = (string) $candidate_id;

			if ( null !== $allowed_ids && ! in_array( $candidate_id, $allowed_ids, true ) ) {
				continue;
			}

			$connection_type = isset( $connection['connection_type'] ) ? sanitize_key( (string) $connection['connection_type'] ) : '';

			if ( self::CONNECTION_TYPE !== $connection_type || empty( $connection['enabled'] ) ) {
				continue;
			}

			if ( $require_keys ) {
				$api_key    = isset( $connection['api_key'] ) ? trim( (string) WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( (string) $connection['api_key'] ) ) : '';
				$api_secret = isset( $connection['api_secret'] ) ? trim( (string) WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( (string) $connection['api_secret'] ) ) : '';

				if ( '' === $api_key || '' === $api_secret ) {
					continue;
				}
			}

			$candidates[ $candidate_id ] = $connection;
		}

		return $candidates;
	}

	/**
	 * Build the runtime credential array from a stored connection.
	 *
	 * @since 1.2.25
	 *
	 * @param array $connection Stored connection array.
	 * @return array|WP_Error
	 */
	private static function read_connection_credentials( array $connection ) {
		self::load_manager();

		$connection_id = isset( $connection['id'] ) ? (string) $connection['id'] : '';

		$api_key    = isset( $connection['api_key'] ) ? trim( (string) WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( (string) $connection['api_key'] ) ) : '';
		$api_secret = isset( $connection['api_secret'] ) ? trim( (string) WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( (string) $connection['api_secret'] ) ) : '';

		if ( '' === $api_key || '' === $api_secret ) {
			return new WP_Error(
				'wp_mcp_ai_mailjet_connection_incomplete',
				/* translators: %s: connection name. */
				sprintf( __( 'The Mailjet connection "%s" has no complete API key pair.', 'mcp-ai-wpoos-pro' ), isset( $connection['name'] ) ? (string) $connection['name'] : $connection_id ),
				array( 'status' => 400 )
			);
		}

		$region = isset( $connection['mailjet_region'] ) && 'eu' === $connection['mailjet_region'] ? 'eu' : 'us';

		$settings = class_exists( 'WP_MCP_AI_Admin_Settings' ) ? WP_MCP_AI_Admin_Settings::get_settings() : array();

		// The connection's sender wins over the site-wide default: sender
		// addresses are validated per API key, so the subaccount that owns the
		// key is the only one that can send from its own addresses.
		$from_email = isset( $connection['mailjet_from_email'] ) ? trim( (string) $connection['mailjet_from_email'] ) : '';
		if ( '' === $from_email ) {
			$from_email = isset( $settings['mailjet_from_email'] ) ? trim( (string) $settings['mailjet_from_email'] ) : '';
		}

		$from_name = isset( $connection['mailjet_from_name'] ) ? trim( (string) $connection['mailjet_from_name'] ) : '';
		if ( '' === $from_name ) {
			$from_name = isset( $settings['mailjet_from_name'] ) ? trim( (string) $settings['mailjet_from_name'] ) : '';
		}

		return array(
			'api_key'       => $api_key,
			'api_secret'    => $api_secret,
			'region'        => $region,
			'from_email'    => $from_email,
			'from_name'     => $from_name,
			'source'        => 'connection',
			'connection_id' => $connection_id,
		);
	}

	/**
	 * The assistant's allowed connection IDs.
	 *
	 * @since 1.2.25
	 *
	 * @param array $context Execution context.
	 * @return string[]|null Allowed IDs, or null when unrestricted.
	 */
	public static function get_allowed_connection_ids( array $context = array() ) {
		$assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;

		if ( ! $assistant_id ) {
			return null;
		}

		$enabled_connections = get_post_meta( $assistant_id, self::ASSISTANT_META_KEY, true );

		if ( ! is_array( $enabled_connections ) ) {
			// Not configured — legacy behaviour allows every connection.
			return null;
		}

		return array_map( 'strval', $enabled_connections );
	}

	/**
	 * Whether a connection is enabled for the assistant in this context.
	 *
	 * @since 1.2.25
	 *
	 * @param string $connection_id Connection ID.
	 * @param array  $context       Execution context.
	 * @return bool
	 */
	public static function is_connection_enabled_for_assistant( $connection_id, array $context = array() ) {
		$allowed_ids = self::get_allowed_connection_ids( $context );

		if ( null === $allowed_ids ) {
			return true;
		}

		return in_array( (string) $connection_id, $allowed_ids, true );
	}

	/**
	 * Whether the site-wide settings fallback is allowed in this context.
	 *
	 * @since 1.2.25
	 *
	 * @param array $context Execution context.
	 * @return bool
	 */
	public static function is_settings_fallback_allowed( array $context = array() ) {
		$allowed_ids = self::get_allowed_connection_ids( $context );

		if ( null === $allowed_ids ) {
			return true;
		}

		return in_array( self::SETTINGS_ENTRY, $allowed_ids, true );
	}

	/**
	 * Non-secret descriptors for the connections this assistant may use.
	 *
	 * Backs `list_mailjet_connections`. Never returns key material.
	 *
	 * @since 1.2.25
	 *
	 * @param array $context Execution context.
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_connections( array $context = array() ) {
		$list = array();

		foreach ( self::candidate_connections( $context, false ) as $connection_id => $connection ) {
			$api_key    = isset( $connection['api_key'] ) ? trim( (string) WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( (string) $connection['api_key'] ) ) : '';
			$api_secret = isset( $connection['api_secret'] ) ? trim( (string) WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( (string) $connection['api_secret'] ) ) : '';

			$list[] = array(
				'id'                => (string) $connection_id,
				'name'              => isset( $connection['name'] ) ? (string) $connection['name'] : '',
				'region'            => isset( $connection['mailjet_region'] ) && 'eu' === $connection['mailjet_region'] ? 'eu' : 'us',
				'from_email'        => isset( $connection['mailjet_from_email'] ) ? (string) $connection['mailjet_from_email'] : '',
				'from_name'         => isset( $connection['mailjet_from_name'] ) ? (string) $connection['mailjet_from_name'] : '',
				'has_credentials'   => '' !== $api_key && '' !== $api_secret,
				'has_mcp_bridge'    => '' !== trim( isset( $connection['mailjet_mcp_url'] ) ? (string) $connection['mailjet_mcp_url'] : '' ),
				'last_test_status'  => isset( $connection['mcp_last_test']['success'] ) ? ( ! empty( $connection['mcp_last_test']['success'] ) ? 'ok' : 'failed' ) : '',
				'last_tested_at'    => isset( $connection['updated'] ) ? (string) $connection['updated'] : '',
			);
		}

		return $list;
	}

	/**
	 * Base URL for the requested region.
	 *
	 * @since 1.2.25
	 *
	 * @param string $region 'us' or 'eu'.
	 * @return string
	 */
	public static function api_base( $region ) {
		self::load_manager();

		$hosts = class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' )
			? WP_MCP_AI_Pro_Remote_Site_Manager::MAILJET_API_HOSTS
			: array(
				'us' => 'https://api.mailjet.com',
				'eu' => 'https://api.eu.mailjet.com',
			);

		return 'eu' === $region && isset( $hosts['eu'] ) ? $hosts['eu'] : $hosts['us'];
	}

	/**
	 * HTTP Basic header value for a key pair.
	 *
	 * @since 1.2.25
	 *
	 * @param string $api_key    API key.
	 * @param string $api_secret API secret.
	 * @return string
	 */
	public static function authorization_header( $api_key, $api_secret ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic credential encoding, not obfuscation.
		return 'Basic ' . base64_encode( (string) $api_key . ':' . (string) $api_secret );
	}

	/**
	 * Lazy-load the Remote Sites manager outside the admin bootstrap.
	 *
	 * @since 1.2.25
	 *
	 * @return void
	 */
	private static function load_manager() {
		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) || ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			return;
		}

		$manager_file = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';

		if ( file_exists( $manager_file ) ) {
			require_once $manager_file;
		}
	}
}
