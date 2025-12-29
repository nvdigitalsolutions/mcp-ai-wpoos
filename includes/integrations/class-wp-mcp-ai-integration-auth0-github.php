<?php
/**
 * Auth0 GitHub identity bridge for NV oOS.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

if ( ! class_exists( 'WP_MCP_AI_Integration_Auth0_Github' ) ) {

	/**
	 * Maps Auth0 GitHub identities to WordPress users.
	 */
	class WP_MCP_AI_Integration_Auth0_Github {
		const META_AUTH0_SUBJECT = '_wp_mcp_ai_auth0_subject';
		const META_GITHUB_ID     = '_wp_mcp_ai_github_id';
		const META_GITHUB_LOGIN  = '_wp_mcp_ai_github_login';

		/**
		 * Tracks whether hooks have been registered.
		 *
		 * @var bool
		 */
		protected static $bootstrapped = false;

		/**
		 * Cached profile lookups keyed by Auth0 subject.
		 *
		 * @var array<string, array|WP_Error>
		 */
		protected static $profile_cache = array();

		/**
		 * Register filters used by the integration.
		 */
		public static function init() {
			if ( self::$bootstrapped ) {
				return;
			}

			self::$bootstrapped = true;

			add_filter( 'wp_mcp_ai_bearer_token_payload', array( __CLASS__, 'maybe_enrich_payload' ), 10, 2 );
			add_filter( 'wp_mcp_ai_map_bearer_to_user_id', array( __CLASS__, 'map_bearer_to_user_id' ), 10, 3 );
		}

		/**
		 * Reset cached state (primarily for automated tests).
		 */
		public static function reset_cache() {
			self::$profile_cache = array();
		}

		/**
		 * Inspect the payload for GitHub-authenticated subjects and expose helper claims.
		 *
		 * @param array           $payload JWT payload.
		 * @param WP_REST_Request $request Current REST request.
		 * @return array
		 */
		public static function maybe_enrich_payload( $payload, $request ) {
			if ( ! self::is_enabled() || ! is_array( $payload ) ) {
				return $payload;
			}

			$subject = isset( $payload['sub'] ) ? (string) $payload['sub'] : '';

			if ( '' === $subject || 0 !== strpos( $subject, 'github|' ) ) {
				return $payload;
			}

			$profile                         = self::normalise_profile_data( $payload );
			self::$profile_cache[ $subject ] = self::merge_profile_details( self::get_cached_profile( $subject ), $profile );

			if ( ! empty( $profile['github_id'] ) ) {
				$payload['github_user_id'] = $profile['github_id'];
			}

			if ( empty( $payload['github_username'] ) && ! empty( $profile['github_login'] ) ) {
				$payload['github_username'] = $profile['github_login'];
			}

			$existing_user_id = self::find_user_id_for_subject( $subject );
			if ( $existing_user_id ) {
				$stored_login = get_user_meta( $existing_user_id, self::META_GITHUB_LOGIN, true );
				if ( $stored_login && empty( $payload['github_username'] ) ) {
					$payload['github_username'] = (string) $stored_login;
				}
			}

			return $payload;
		}

		/**
		 * Map the Auth0 GitHub identity to a WordPress user, creating one if needed.
		 *
		 * @param null|int|WP_Error $mapped  Previously mapped user.
		 * @param array|null        $payload Token payload.
		 * @param WP_REST_Request   $request Current REST request.
		 * @return int|null|WP_Error
		 */
		public static function map_bearer_to_user_id( $mapped, $payload, $request ) {
			if ( $mapped instanceof WP_Error || ! self::is_enabled() ) {
				return $mapped;
			}

			if ( null !== $mapped || ! is_array( $payload ) ) {
				return $mapped;
			}

			$profile = self::normalise_profile_data( $payload );
			$subject = $profile['sub'];

			if ( '' === $subject || 0 !== strpos( $subject, 'github|' ) ) {
				return $mapped;
			}

			$existing_user_id = self::find_user_id_for_subject( $subject );
			if ( $existing_user_id ) {
				self::sync_user_metadata( $existing_user_id, $profile );
				return (int) $existing_user_id;
			}

			$cached = self::get_cached_profile( $subject );
			if ( is_array( $cached ) ) {
				$profile = self::merge_profile_details( $profile, $cached );
			}

			if ( empty( $profile['email'] ) || empty( $profile['github_login'] ) ) {
				$remote_profile = self::fetch_remote_profile( $subject, $request );
				if ( is_wp_error( $remote_profile ) ) {
					if ( empty( $profile['email'] ) ) {
						return $remote_profile;
					}
				} else {
					$profile = self::merge_profile_details( $profile, $remote_profile );
				}
			}

			$user_id = self::locate_or_create_user( $profile );
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}

			if ( $user_id ) {
				self::sync_user_metadata( $user_id, $profile );
				return (int) $user_id;
			}

			return new WP_Error(
				'wp_mcp_ai_auth0_github_unmapped',
				__( 'Auth0 GitHub identity could not be mapped to a WordPress user.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		/**
		 * Determine whether the integration is enabled via plugin settings.
		 *
		 * @return bool
		 */
		protected static function is_enabled() {
			$settings = self::get_settings();

			if ( empty( $settings['enable_auth0_github_bridge'] ) ) {
				return false;
			}

			return ! empty( $settings['auth0_domain'] );
		}

		/**
		 * Retrieve the plugin settings array.
		 *
		 * @return array
		 */
		protected static function get_settings() {
			$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

			return is_array( $settings ) ? $settings : array();
		}

		/**
		 * Retrieve a cached profile when available.
		 *
		 * @param string $subject Auth0 subject identifier.
		 * @return array|WP_Error|null
		 */
		protected static function get_cached_profile( $subject ) {
			if ( isset( self::$profile_cache[ $subject ] ) ) {
				return self::$profile_cache[ $subject ];
			}

			return null;
		}

		/**
		 * Fetch profile information from Auth0 when the payload is insufficient.
		 *
		 * @param string          $subject Auth0 subject identifier.
		 * @param WP_REST_Request $request Current REST request.
		 * @return array|WP_Error Normalised profile data or WP_Error on failure.
		 */
		protected static function fetch_remote_profile( $subject, $request ) {
			if ( isset( self::$profile_cache[ $subject ] ) && self::$profile_cache[ $subject ] instanceof WP_Error ) {
				return self::$profile_cache[ $subject ];
			}

			$settings = self::get_settings();
			$token    = self::extract_bearer_token( $request );

			if ( is_wp_error( $token ) ) {
				self::$profile_cache[ $subject ] = $token;
				return $token;
			}

			$userinfo = self::request_userinfo( $settings, $token );
			if ( is_array( $userinfo ) ) {
				$profile                         = self::normalise_profile_data( $userinfo );
				self::$profile_cache[ $subject ] = self::merge_profile_details( self::get_cached_profile( $subject ), $profile );
				return self::$profile_cache[ $subject ];
			}

			$management_profile = self::request_management_profile( $subject, $settings );
			if ( is_wp_error( $management_profile ) ) {
				self::$profile_cache[ $subject ] = $management_profile;
				return $management_profile;
			}

			$profile                         = self::normalise_profile_data( $management_profile );
			self::$profile_cache[ $subject ] = self::merge_profile_details( self::get_cached_profile( $subject ), $profile );

			return self::$profile_cache[ $subject ];
		}

		/**
		 * Attempt to fetch the Auth0 userinfo payload using the supplied bearer token.
		 *
		 * @param array  $settings Plugin settings.
		 * @param string $token    Raw bearer token from the request.
		 * @return array|WP_Error
		 */
		protected static function request_userinfo( $settings, $token ) {
			$domain = isset( $settings['auth0_domain'] ) ? trim( (string) $settings['auth0_domain'] ) : '';
			if ( '' === $domain ) {
				return new WP_Error(
					'wp_mcp_ai_auth0_github_missing_domain',
					__( 'Auth0 domain is not configured for the GitHub bridge.', 'wp-mcp-ai' ),
					array( 'status' => 500 )
				);
			}

			$url      = 'https://' . $domain . '/userinfo';
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 10,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Accept'        => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				return new WP_Error(
					'wp_mcp_ai_auth0_github_userinfo_failed',
					__( 'Auth0 rejected the userinfo request for the supplied GitHub token.', 'wp-mcp-ai' ),
					array( 'status' => 403 )
				);
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			return is_array( $data ) ? $data : new WP_Error(
				'wp_mcp_ai_auth0_github_userinfo_invalid',
				__( 'Auth0 returned an unexpected response while resolving the GitHub user.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		/**
		 * Fetch profile data from the Auth0 Management API.
		 *
		 * @param string $subject  Auth0 subject identifier.
		 * @param array  $settings Plugin settings.
		 * @return array|WP_Error
		 */
		protected static function request_management_profile( $subject, $settings ) {
			$token = self::get_management_token( $settings );
			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$domain = isset( $settings['auth0_domain'] ) ? trim( (string) $settings['auth0_domain'] ) : '';
			if ( '' === $domain ) {
				return new WP_Error(
					'wp_mcp_ai_auth0_github_missing_domain',
					__( 'Auth0 domain is not configured for the GitHub bridge.', 'wp-mcp-ai' ),
					array( 'status' => 500 )
				);
			}

			$url      = sprintf( 'https://%s/api/v2/users/%s', $domain, rawurlencode( $subject ) );
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 10,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Accept'        => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				return new WP_Error(
					'wp_mcp_ai_auth0_github_profile_fetch_failed',
					__( 'Auth0 Management API did not return a profile for the GitHub identity.', 'wp-mcp-ai' ),
					array( 'status' => 403 )
				);
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			return is_array( $data ) ? $data : new WP_Error(
				'wp_mcp_ai_auth0_github_profile_invalid',
				__( 'Auth0 Management API returned an unexpected payload for the GitHub identity.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		/**
		 * Acquire an Auth0 Management API token using the configured credentials.
		 *
		 * @param array $settings Plugin settings.
		 * @return string|WP_Error
		 */
		protected static function get_management_token( $settings ) {
			$client_id     = isset( $settings['auth0_management_client_id'] ) ? trim( (string) $settings['auth0_management_client_id'] ) : '';
			$client_secret = isset( $settings['auth0_management_client_secret'] ) ? trim( (string) $settings['auth0_management_client_secret'] ) : '';
			$domain        = isset( $settings['auth0_domain'] ) ? trim( (string) $settings['auth0_domain'] ) : '';

			if ( '' === $client_id || '' === $client_secret ) {
				return new WP_Error(
					'wp_mcp_ai_auth0_github_missing_credentials',
					__( 'Auth0 Management API credentials are required to resolve GitHub identities.', 'wp-mcp-ai' ),
					array( 'status' => 500 )
				);
			}

			if ( '' === $domain ) {
				return new WP_Error(
					'wp_mcp_ai_auth0_github_missing_domain',
					__( 'Auth0 domain is not configured for the GitHub bridge.', 'wp-mcp-ai' ),
					array( 'status' => 500 )
				);
			}

			$cache_key = 'wp_mcp_ai_auth0_mgmt_' . md5( $domain . '|' . $client_id );
			$cached    = get_transient( $cache_key );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}

			$url      = 'https://' . $domain . '/oauth/token';
			$body     = wp_json_encode(
				array(
					'grant_type'    => 'client_credentials',
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'audience'      => 'https://' . $domain . '/api/v2/',
				)
			);
			$response = wp_remote_post(
				$url,
				array(
					'timeout' => 10,
					'headers' => array(
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					),
					'body'    => $body,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				return new WP_Error(
					'wp_mcp_ai_auth0_github_token_failed',
					__( 'Unable to obtain an Auth0 Management API token for the GitHub bridge.', 'wp-mcp-ai' ),
					array( 'status' => 500 )
				);
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( ! is_array( $data ) || empty( $data['access_token'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_auth0_github_token_invalid',
					__( 'Auth0 returned an unexpected response while requesting a Management API token.', 'wp-mcp-ai' ),
					array( 'status' => 500 )
				);
			}

			$token     = (string) $data['access_token'];
			$lifetime  = isset( $data['expires_in'] ) ? absint( $data['expires_in'] ) : 3600;
			$cache_ttl = max( 300, min( $lifetime - 60, 86400 ) );

			set_transient( $cache_key, $token, $cache_ttl );

			return $token;
		}

		/**
		 * Locate the WordPress user for the given Auth0 subject.
		 *
		 * @param string $subject Auth0 subject identifier.
		 * @return int
		 */
		protected static function find_user_id_for_subject( $subject ) {
			if ( '' === $subject ) {
				return 0;
			}

			$user_ids = get_users(
				array(
					'meta_key'   => self::META_AUTH0_SUBJECT,
					'meta_value' => $subject,
					'fields'     => 'ids',
					'number'     => 1,
				)
			);

			if ( ! empty( $user_ids ) ) {
				return (int) $user_ids[0];
			}

			list( , $identifier ) = self::split_subject( $subject );
			if ( '' === $identifier ) {
				return 0;
			}

			$user_ids = get_users(
				array(
					'meta_key'   => self::META_GITHUB_ID,
					'meta_value' => $identifier,
					'fields'     => 'ids',
					'number'     => 1,
				)
			);

			return ! empty( $user_ids ) ? (int) $user_ids[0] : 0;
		}

		/**
		 * Create or locate a WordPress user using the supplied profile details.
		 *
		 * @param array $profile Normalised profile data.
		 * @return int|WP_Error
		 */
		protected static function locate_or_create_user( $profile ) {
			$email        = isset( $profile['email'] ) ? sanitize_email( $profile['email'] ) : '';
			$github_id    = isset( $profile['github_id'] ) ? trim( (string) $profile['github_id'] ) : '';
			$github_login = isset( $profile['github_login'] ) ? sanitize_user( $profile['github_login'], true ) : '';
			$display_name = isset( $profile['name'] ) ? wp_strip_all_tags( $profile['name'] ) : '';

			if ( $email ) {
				$existing_user = get_user_by( 'email', $email );
				if ( $existing_user instanceof WP_User ) {
					return (int) $existing_user->ID;
				}
			}

			if ( ! $email ) {
				return new WP_Error(
					'wp_mcp_ai_auth0_github_missing_email',
					__( 'Auth0 did not return an email address for the GitHub identity, preventing automatic user creation.', 'wp-mcp-ai' ),
					array( 'status' => 403 )
				);
			}

			if ( '' === $github_login ) {
				if ( '' !== $github_id ) {
					$github_login = 'github_' . strtolower( preg_replace( '/[^a-zA-Z0-9_]+/', '', $github_id ) );
				} else {
					$github_login = 'github_' . strtolower( wp_generate_password( 8, false ) );
				}
			}

			$base_login = $github_login;
			$suffix     = 1;
			while ( username_exists( $github_login ) ) {
				$github_login = $base_login . '_' . $suffix;
				++$suffix; // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			}

			$user_data = array(
				'user_login'   => $github_login,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'user_email'   => $email,
				'display_name' => $display_name ? $display_name : $github_login,
				'role'         => 'subscriber',
			);

			$user_id = wp_insert_user( $user_data );

			if ( is_wp_error( $user_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_auth0_github_user_creation_failed',
					__( 'Failed to create a WordPress user for the Auth0 GitHub identity.', 'wp-mcp-ai' ),
					array(
						'status'  => 500,
						'details' => array( 'error' => $user_id->get_error_message() ),
					)
				);
			}

			return (int) $user_id;
		}

		/**
		 * Persist GitHub metadata on the mapped user.
		 *
		 * @param int   $user_id User identifier.
		 * @param array $profile Normalised profile data.
		 */
		protected static function sync_user_metadata( $user_id, $profile ) {
			if ( empty( $user_id ) ) {
				return;
			}

			if ( ! empty( $profile['sub'] ) ) {
				update_user_meta( $user_id, self::META_AUTH0_SUBJECT, $profile['sub'] );
			}

			if ( ! empty( $profile['github_id'] ) ) {
				update_user_meta( $user_id, self::META_GITHUB_ID, $profile['github_id'] );
			}

			if ( ! empty( $profile['github_login'] ) ) {
				update_user_meta( $user_id, self::META_GITHUB_LOGIN, $profile['github_login'] );
			}

			if ( ! empty( $profile['name'] ) ) {
				$user = get_user_by( 'id', $user_id );
				if ( $user instanceof WP_User && $user->display_name !== $profile['name'] ) {
					wp_update_user(
						array(
							'ID'           => $user_id,
							'display_name' => $profile['name'],
						)
					);
				}
			}
		}

		/**
		 * Normalise Auth0 payloads into a predictable structure.
		 *
		 * @param array $data Raw Auth0 payload (JWT claims, userinfo, or Management API data).
		 * @return array
		 */
		protected static function normalise_profile_data( $data ) {
			$subject = '';
			if ( isset( $data['sub'] ) ) {
				$subject = (string) $data['sub'];
			} elseif ( isset( $data['user_id'] ) ) {
				$subject = (string) $data['user_id'];
			}

			list( , $identifier ) = self::split_subject( $subject );

			$email   = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';
			$name    = isset( $data['name'] ) ? wp_strip_all_tags( $data['name'] ) : '';
			$login   = '';
			$aliases = array( 'github_username', 'github_login', 'nickname', 'preferred_username', 'username' );

			foreach ( $aliases as $alias ) {
				if ( ! empty( $data[ $alias ] ) ) {
					$login = sanitize_user( $data[ $alias ], true );
					if ( '' !== $login ) {
						break;
					}
				}
			}

			if ( '' === $login && ! empty( $data['identities'] ) && is_array( $data['identities'] ) ) {
				foreach ( $data['identities'] as $identity ) {
					if ( isset( $identity['provider'] ) && 'github' === $identity['provider'] ) {
						if ( ! empty( $identity['profileData']['login'] ) ) {
							$login = sanitize_user( $identity['profileData']['login'], true );
						} elseif ( ! empty( $identity['user_id'] ) ) {
							$login = sanitize_user( $identity['user_id'], true );
						}

						if ( '' !== $login ) {
							break;
						}
					}
				}
			}

			return array(
				'sub'          => $subject,
				'email'        => $email,
				'name'         => $name,
				'github_id'    => $identifier,
				'github_login' => $login,
			);
		}

		/**
		 * Merge profile data, preferring the primary array's values when available.
		 *
		 * @param array|null $primary   Primary data source.
		 * @param array|null $secondary Secondary data source.
		 * @return array
		 */
		protected static function merge_profile_details( $primary, $secondary ) {
			$primary   = is_array( $primary ) ? $primary : array();
			$secondary = is_array( $secondary ) ? $secondary : array();

			foreach ( $secondary as $key => $value ) {
				if ( '' === $value || null === $value ) {
					continue;
				}

				if ( ! isset( $primary[ $key ] ) || '' === $primary[ $key ] ) {
					$primary[ $key ] = $value;
				}
			}

			return $primary;
		}

		/**
		 * Extract the bearer token from the REST request headers.
		 *
		 * @param WP_REST_Request $request Current REST request.
		 * @return string|WP_Error
		 */
		protected static function extract_bearer_token( $request ) {
			$header = '';

			if ( $request instanceof WP_REST_Request ) {
				$header = (string) $request->get_header( 'Authorization' );
			}

			if ( ! $header && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$header = (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}

			if ( '' === $header ) {
				return new WP_Error(
					'wp_mcp_ai_auth0_github_missing_header',
					__( 'The Authorization header is required to resolve Auth0 GitHub identities.', 'wp-mcp-ai' ),
					array( 'status' => 401 )
				);
			}

			if ( ! preg_match( '/^Bearer\s+(.*)$/i', $header, $matches ) ) {
				return new WP_Error(
					'wp_mcp_ai_auth0_github_invalid_header',
					__( 'The Authorization header is not a valid bearer token.', 'wp-mcp-ai' ),
					array( 'status' => 401 )
				);
			}

			return trim( $matches[1] );
		}

		/**
		 * Split the Auth0 subject into provider and identifier.
		 *
		 * @param string $subject Auth0 subject identifier.
		 * @return array{0:string,1:string}
		 */
		protected static function split_subject( $subject ) {
			$parts = explode( '|', (string) $subject, 2 );

			if ( 2 !== count( $parts ) ) {
				return array( '', '' );
			}

			return array( $parts[0], $parts[1] );
		}
	}
}
