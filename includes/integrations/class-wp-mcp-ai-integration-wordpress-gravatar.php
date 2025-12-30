<?php
/**
 * WordPress.com/Gravatar identity bridge for NV oOS.
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

if ( ! class_exists( 'WP_MCP_AI_Integration_WordPress_Gravatar' ) ) {

	/**
	 * Maps WordPress.com/Gravatar identities to WordPress users.
	 */
	class WP_MCP_AI_Integration_WordPress_Gravatar {
		const META_SUBJECT       = '_wp_mcp_ai_wordpress_gravatar_subject';
		const META_WORDPRESS_ID  = '_wp_mcp_ai_wordpress_id';
		const META_GRAVATAR_HASH = '_wp_mcp_ai_gravatar_hash';

		/**
		 * Tracks whether hooks have been registered.
		 *
		 * @var bool
		 */
		protected static $bootstrapped = false;

		/**
		 * Cached profile lookups keyed by subject.
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
		 * Inspect the payload for WordPress.com/Gravatar subjects and expose helper claims.
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

			if ( '' === $subject || ! self::is_wordpress_gravatar_subject( $subject ) ) {
				return $payload;
			}

			$profile                         = self::normalise_profile_data( $payload );
			self::$profile_cache[ $subject ] = self::merge_profile_details( self::get_cached_profile( $subject ), $profile );

			if ( ! empty( $profile['wordpress_id'] ) ) {
				$payload['wordpress_user_id'] = $profile['wordpress_id'];
			}

			if ( ! empty( $profile['gravatar_hash'] ) ) {
				$payload['gravatar_hash'] = $profile['gravatar_hash'];
			}

			if ( ! empty( $profile['display_name'] ) && empty( $payload['display_name'] ) ) {
				$payload['display_name'] = $profile['display_name'];
			}

			if ( ! empty( $profile['avatar_url'] ) && empty( $payload['picture'] ) ) {
				$payload['picture'] = $profile['avatar_url'];
			}

			$existing_user_id = self::find_user_id_for_subject( $subject );
			if ( $existing_user_id ) {
				$stored_email = get_userdata( $existing_user_id );
				if ( $stored_email && empty( $payload['email'] ) ) {
					$payload['email'] = $stored_email->user_email;
				}
			}

			return $payload;
		}

		/**
		 * Map the WordPress.com/Gravatar identity to a WordPress user, creating one if needed.
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

			if ( '' === $subject || ! self::is_wordpress_gravatar_subject( $subject ) ) {
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

			if ( empty( $profile['email'] ) ) {
				$remote_profile = self::fetch_remote_profile( $subject, $request );
				if ( is_wp_error( $remote_profile ) ) {
					return $remote_profile;
				}
				$profile = self::merge_profile_details( $profile, $remote_profile );
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
				'wp_mcp_ai_wordpress_gravatar_unmapped',
				__( 'WordPress.com/Gravatar identity could not be mapped to a WordPress user.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		/**
		 * Check if subject is a WordPress.com or Gravatar identity.
		 *
		 * @param string $subject Subject identifier.
		 * @return bool
		 */
		protected static function is_wordpress_gravatar_subject( $subject ) {
			return 0 === strpos( $subject, 'wordpress.com|' ) || 0 === strpos( $subject, 'gravatar|' );
		}

		/**
		 * Determine whether the integration is enabled via plugin settings.
		 *
		 * @return bool
		 */
		protected static function is_enabled() {
			$settings = self::get_settings();

			if ( empty( $settings['enable_wordpress_gravatar_bridge'] ) ) {
				return false;
			}

			return true;
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
		 * @param string $subject Subject identifier.
		 * @return array|WP_Error|null
		 */
		protected static function get_cached_profile( $subject ) {
			if ( isset( self::$profile_cache[ $subject ] ) ) {
				return self::$profile_cache[ $subject ];
			}

			return null;
		}

		/**
		 * Fetch profile information from OAuth provider when the payload is insufficient.
		 *
		 * @param string          $subject Subject identifier.
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

			// Try to fetch userinfo from OAuth provider.
			$userinfo_url = isset( $settings['wordpress_gravatar_userinfo_endpoint'] )
				? $settings['wordpress_gravatar_userinfo_endpoint']
				: '';

			if ( empty( $userinfo_url ) ) {
				return new WP_Error(
					'wp_mcp_ai_wordpress_gravatar_missing_endpoint',
					__( 'WordPress/Gravatar userinfo endpoint is not configured.', 'wp-mcp-ai' ),
					array( 'status' => 500 )
				);
			}

			$userinfo = self::request_userinfo( $userinfo_url, $token );
			if ( is_array( $userinfo ) ) {
				$profile                         = self::normalise_profile_data( $userinfo );
				self::$profile_cache[ $subject ] = self::merge_profile_details( self::get_cached_profile( $subject ), $profile );
				return self::$profile_cache[ $subject ];
			}

			return $userinfo;
		}

		/**
		 * Attempt to fetch the userinfo payload using the supplied bearer token.
		 *
		 * @param string $url   Userinfo endpoint URL.
		 * @param string $token Raw bearer token from the request.
		 * @return array|WP_Error
		 */
		protected static function request_userinfo( $url, $token ) {
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
					'wp_mcp_ai_wordpress_gravatar_userinfo_failed',
					__( 'Failed to fetch WordPress/Gravatar profile information.', 'wp-mcp-ai' ),
					array( 'status' => 403 )
				);
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			return is_array( $data ) ? $data : new WP_Error(
				'wp_mcp_ai_wordpress_gravatar_userinfo_invalid',
				__( 'Userinfo endpoint returned an unexpected response.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		/**
		 * Locate the WordPress user for the given subject.
		 *
		 * @param string $subject Subject identifier.
		 * @return int
		 */
		protected static function find_user_id_for_subject( $subject ) {
			if ( '' === $subject ) {
				return 0;
			}

			$user_ids = get_users(
				array(
					'meta_key'   => self::META_SUBJECT,
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
					'meta_key'   => self::META_WORDPRESS_ID,
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
			$wordpress_id = isset( $profile['wordpress_id'] ) ? trim( (string) $profile['wordpress_id'] ) : '';
			$display_name = isset( $profile['display_name'] ) ? wp_strip_all_tags( $profile['display_name'] ) : '';
			$username     = isset( $profile['username'] ) ? sanitize_user( $profile['username'], true ) : '';

			if ( $email ) {
				$existing_user = get_user_by( 'email', $email );
				if ( $existing_user instanceof WP_User ) {
					return (int) $existing_user->ID;
				}
			}

			if ( ! $email ) {
				return new WP_Error(
					'wp_mcp_ai_wordpress_gravatar_missing_email',
					__( 'WordPress/Gravatar identity did not return an email address, preventing automatic user creation.', 'wp-mcp-ai' ),
					array( 'status' => 403 )
				);
			}

			if ( '' === $username ) {
				if ( '' !== $wordpress_id ) {
					$username = 'wordpress_' . strtolower( preg_replace( '/[^a-zA-Z0-9_]+/', '', $wordpress_id ) );
				} else {
					$username = 'wordpress_' . strtolower( wp_generate_password( 8, false ) );
				}
			}

			$base_login = $username;
			$suffix     = 1;
			while ( username_exists( $username ) ) {
				$username = $base_login . '_' . $suffix;
				++$suffix;
			}

			$user_data = array(
				'user_login'   => $username,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'user_email'   => $email,
				'display_name' => $display_name ? $display_name : $username,
				'role'         => 'subscriber',
			);

			$user_id = wp_insert_user( $user_data );

			if ( is_wp_error( $user_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_wordpress_gravatar_user_creation_failed',
					__( 'Failed to create a WordPress user for the WordPress/Gravatar identity.', 'wp-mcp-ai' ),
					array(
						'status'  => 500,
						'details' => array( 'error' => $user_id->get_error_message() ),
					)
				);
			}

			return (int) $user_id;
		}

		/**
		 * Persist metadata on the mapped user.
		 *
		 * @param int   $user_id User identifier.
		 * @param array $profile Normalised profile data.
		 */
		protected static function sync_user_metadata( $user_id, $profile ) {
			if ( empty( $user_id ) ) {
				return;
			}

			if ( ! empty( $profile['sub'] ) ) {
				update_user_meta( $user_id, self::META_SUBJECT, $profile['sub'] );
			}

			if ( ! empty( $profile['wordpress_id'] ) ) {
				update_user_meta( $user_id, self::META_WORDPRESS_ID, $profile['wordpress_id'] );
			}

			if ( ! empty( $profile['gravatar_hash'] ) ) {
				update_user_meta( $user_id, self::META_GRAVATAR_HASH, $profile['gravatar_hash'] );
			}

			if ( ! empty( $profile['display_name'] ) ) {
				$user = get_user_by( 'id', $user_id );
				if ( $user instanceof WP_User && $user->display_name !== $profile['display_name'] ) {
					wp_update_user(
						array(
							'ID'           => $user_id,
							'display_name' => $profile['display_name'],
						)
					);
				}
			}
		}

		/**
		 * Normalise OAuth payloads into a predictable structure.
		 *
		 * @param array $data Raw OAuth payload (JWT claims, userinfo data).
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

			$email        = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';
			$display_name = isset( $data['name'] ) ? wp_strip_all_tags( $data['name'] ) : '';
			$username     = '';

			// Try various username field aliases.
			$aliases = array( 'username', 'preferred_username', 'nickname', 'login' );
			foreach ( $aliases as $alias ) {
				if ( ! empty( $data[ $alias ] ) ) {
					$username = sanitize_user( $data[ $alias ], true );
					if ( '' !== $username ) {
						break;
					}
				}
			}

			// Extract display name if not already set.
			if ( '' === $display_name ) {
				if ( ! empty( $data['display_name'] ) ) {
					$display_name = wp_strip_all_tags( $data['display_name'] );
				} elseif ( ! empty( $data['nickname'] ) ) {
					$display_name = wp_strip_all_tags( $data['nickname'] );
				}
			}

			// Extract avatar URL.
			$avatar_url = '';
			if ( ! empty( $data['picture'] ) ) {
				$avatar_url = esc_url_raw( $data['picture'] );
			} elseif ( ! empty( $data['avatar'] ) ) {
				$avatar_url = esc_url_raw( $data['avatar'] );
			} elseif ( ! empty( $data['avatar_url'] ) ) {
				$avatar_url = esc_url_raw( $data['avatar_url'] );
			}

			// Extract Gravatar hash.
			$gravatar_hash = '';
			if ( ! empty( $data['gravatar_hash'] ) ) {
				$gravatar_hash = sanitize_text_field( $data['gravatar_hash'] );
			} elseif ( $email ) {
				$gravatar_hash = md5( strtolower( trim( $email ) ) );
			}

			return array(
				'sub'           => $subject,
				'email'         => $email,
				'display_name'  => $display_name,
				'username'      => $username,
				'wordpress_id'  => $identifier,
				'gravatar_hash' => $gravatar_hash,
				'avatar_url'    => $avatar_url,
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

			if ( ! $header && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
				$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
			}

			if ( '' === $header ) {
				return new WP_Error(
					'wp_mcp_ai_wordpress_gravatar_missing_header',
					__( 'The Authorization header is required to resolve WordPress/Gravatar identities.', 'wp-mcp-ai' ),
					array( 'status' => 401 )
				);
			}

			if ( ! preg_match( '/^Bearer\s+(.*)$/i', $header, $matches ) ) {
				return new WP_Error(
					'wp_mcp_ai_wordpress_gravatar_invalid_header',
					__( 'The Authorization header is not a valid bearer token.', 'wp-mcp-ai' ),
					array( 'status' => 401 )
				);
			}

			return trim( $matches[1] );
		}

		/**
		 * Split the subject into provider and identifier.
		 *
		 * @param string $subject Subject identifier.
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
