<?php
/**
 * Google Classroom credential resolution.
 *
 * Resolves OAuth credentials for Classroom API requests from a Pro Remote
 * Sites `google_classroom` connection and builds a configured client. Mirrors
 * the Calendar credentials class so token minting, decrypt-on-read, and
 * granular-consent gating behave identically across Google integrations.
 *
 * Google Classroom is a Pro-only integration: unlike Calendar there is no
 * base-plugin settings surface, so resolution requires a Remote Sites
 * connection ID. The class still lives in the shared `includes/google/`
 * folder because it is Google infrastructure, not ECA business logic.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 * @since     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Google_Classroom_Credentials' ) ) {
	/**
	 * Google Classroom credential resolver.
	 */
	class WP_MCP_AI_Google_Classroom_Credentials {

		/**
		 * Remote Sites connection type slug for Google Classroom.
		 *
		 * @var string
		 */
		const CONNECTION_TYPE = 'google_classroom';

		/**
		 * Resolve credentials for a Classroom request.
		 *
		 * @since 1.0.0
		 *
		 * @param string $connection_id Remote Sites connection ID.
		 * @return array<string,mixed>|WP_Error {
		 *     Resolved credentials on success.
		 *
		 *     @type string $source         Always `connection` today.
		 *     @type string $connection_id  Connection ID.
		 *     @type string $client_id      OAuth client ID.
		 *     @type string $client_secret  OAuth client secret (plaintext).
		 *     @type string $refresh_token  OAuth refresh token (plaintext).
		 *     @type string $access_token   Always empty; minted lazily by the client.
		 *     @type string $user_email     Authorised account email.
		 *     @type string $default_course_id Default course for the connection.
		 *     @type string $granted_scopes Space-delimited granted scopes.
		 *     @type string $scope_profile  Scope profile slug.
		 *     @type string $cache_key      Stable identity for the access-token cache.
		 * }
		 */
		public static function resolve( $connection_id ) {
			$connection_id = is_string( $connection_id ) ? sanitize_key( $connection_id ) : '';

			if ( '' === $connection_id ) {
				return new WP_Error(
					'wp_mcp_ai_classroom_connection_required',
					__( 'A Google Classroom Remote Sites connection is required. Create one under Pro → Remote Sites and pass its connection_id.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
					return new WP_Error(
						'wp_mcp_ai_classroom_pro_required',
						__( 'Google Classroom integration requires the NV oOS Pro addon.', 'mcp-ai-wpoos' ),
						array( 'status' => 400 )
					);
				}

				$manager_file = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';

				if ( ! file_exists( $manager_file ) ) {
					return new WP_Error(
						'wp_mcp_ai_classroom_pro_required',
						__( 'The Remote Site connection manager is unavailable.', 'mcp-ai-wpoos' ),
						array( 'status' => 500 )
					);
				}

				require_once $manager_file;
			}

			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

			if ( empty( $connection ) ) {
				return new WP_Error(
					'wp_mcp_ai_classroom_connection_not_found',
					sprintf(
						/* translators: %s: connection ID. */
						__( 'Google Classroom connection "%s" was not found. Check your Remote Sites configuration.', 'mcp-ai-wpoos' ),
						$connection_id
					),
					array( 'status' => 404 )
				);
			}

			$type = isset( $connection['connection_type'] ) ? (string) $connection['connection_type'] : '';

			if ( '' !== $type && self::CONNECTION_TYPE !== $type ) {
				return new WP_Error(
					'wp_mcp_ai_classroom_wrong_connection_type',
					sprintf(
						/* translators: %s: connection type slug. */
						__( 'Connection "%s" is not a Google Classroom connection. Select a Google Classroom connection type.', 'mcp-ai-wpoos' ),
						$type
					),
					array( 'status' => 400 )
				);
			}

			if ( isset( $connection['enabled'] ) && ! $connection['enabled'] ) {
				return new WP_Error(
					'wp_mcp_ai_classroom_connection_disabled',
					__( 'This Google Classroom connection is disabled.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$client_id     = isset( $connection['client_id'] ) ? trim( (string) $connection['client_id'] ) : '';
			$client_secret = isset( $connection['client_secret'] ) ? trim( (string) $connection['client_secret'] ) : '';
			$refresh_token = isset( $connection['refresh_token'] ) ? trim( (string) $connection['refresh_token'] ) : '';

			if ( '' !== $client_secret ) {
				$client_secret = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $client_secret );
			}

			if ( '' !== $refresh_token ) {
				$refresh_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $refresh_token );
			}

			if ( '' === $client_id || '' === $client_secret || '' === $refresh_token ) {
				return new WP_Error(
					'wp_mcp_ai_classroom_connection_incomplete',
					__( 'This Google Classroom connection is not fully authorised. Open the connection and complete the OAuth flow.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			return array(
				'source'            => 'connection',
				'connection_id'     => $connection_id,
				'client_id'         => $client_id,
				'client_secret'     => $client_secret,
				'refresh_token'     => $refresh_token,
				'access_token'      => '',
				'user_email'        => isset( $connection['user_email'] ) ? trim( (string) $connection['user_email'] ) : '',
				'default_course_id' => isset( $connection['classroom_course_id'] ) ? trim( (string) $connection['classroom_course_id'] ) : '',
				'granted_scopes'    => isset( $connection['granted_scopes'] ) ? (string) $connection['granted_scopes'] : '',
				'scope_profile'     => WP_MCP_AI_Google_Classroom_Scopes::normalise_profile(
					isset( $connection['scope_profile'] ) ? $connection['scope_profile'] : ''
				),
				'cache_key'         => 'classroom-connection:' . $connection_id,
			);
		}

		/**
		 * Build a configured Classroom API client from resolved credentials.
		 *
		 * The token provider is a closure so the access token is minted lazily
		 * and only when a request is actually made.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,mixed> $credentials Output of `resolve()`.
		 * @param array<string,mixed> $options     Optional client options.
		 * @return WP_MCP_AI_Google_Classroom_Client|WP_Error
		 */
		public static function make_client( array $credentials, array $options = array() ) {
			$client_id     = isset( $credentials['client_id'] ) ? (string) $credentials['client_id'] : '';
			$client_secret = isset( $credentials['client_secret'] ) ? (string) $credentials['client_secret'] : '';
			$refresh_token = isset( $credentials['refresh_token'] ) ? (string) $credentials['refresh_token'] : '';
			$cache_key     = isset( $credentials['cache_key'] ) ? (string) $credentials['cache_key'] : '';

			if ( '' === $client_id || '' === $client_secret || '' === $refresh_token ) {
				return new WP_Error(
					'wp_mcp_ai_classroom_missing_credentials',
					__( 'Google Classroom credentials are incomplete.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			if ( ! class_exists( 'WP_MCP_AI_Google_OAuth_Service' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-oauth-service.php';
			}

			$provider = static function () use ( $client_id, $client_secret, $refresh_token, $cache_key ) {
				return WP_MCP_AI_Google_OAuth_Service::mint_access_token(
					array(
						'client_id'     => $client_id,
						'client_secret' => $client_secret,
						'refresh_token' => $refresh_token,
						'cache_key'     => $cache_key,
					)
				);
			};

			// Attribute per-user quota to the authorised account where known.
			if ( ! isset( $options['quota_user'] ) && ! empty( $credentials['user_email'] ) ) {
				$options['quota_user'] = (string) $credentials['user_email'];
			}

			return new WP_MCP_AI_Google_Classroom_Client( $provider, $options );
		}

		/**
		 * Gate a credential set on a required scope.
		 *
		 * Granular consent lets users approve a subset of the requested
		 * scopes, so every consumer must check the recorded grant.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,mixed> $credentials Output of `resolve()`.
		 * @param string              $required    Required scope URL.
		 * @return true|WP_Error True when the scope is granted.
		 */
		public static function require_scope( array $credentials, $required ) {
			$granted = isset( $credentials['granted_scopes'] ) ? (string) $credentials['granted_scopes'] : '';

			if ( WP_MCP_AI_Google_Classroom_Scopes::has_scope( $granted, $required ) ) {
				return true;
			}

			return WP_MCP_AI_Google_Classroom_Scopes::missing_scope_error( $required );
		}

		/**
		 * Resolve the default course ID for a credential set.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,mixed> $credentials Output of `resolve()`.
		 * @param string              $course_id  Caller-supplied course ID.
		 * @return string|WP_Error Course ID, or WP_Error when none is available.
		 */
		public static function resolve_default_course_id( array $credentials, $course_id = '' ) {
			$course_id = is_string( $course_id ) ? trim( $course_id ) : '';

			if ( '' === $course_id ) {
				$course_id = isset( $credentials['default_course_id'] ) ? trim( (string) $credentials['default_course_id'] ) : '';
			}

			if ( '' === $course_id ) {
				return new WP_Error(
					'wp_mcp_ai_classroom_course_required',
					__( 'No Google Classroom course was specified and this connection has no default course. Pass course_id explicitly.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			return $course_id;
		}
	}
}
