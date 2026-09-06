<?php
/**
 * Google Calendar credential resolution (Wave E4, sub-cluster 3).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Google_Calendar_Credentials`:
 * byte-identical source constants, the connection → settings → filter
 * resolution order, the Pro Remote Sites connection validation and
 * decryption flow, the settings-level single-connection surface, the
 * legacy filter surface, the lazy token-provider `make_client()`, the
 * JWT bearer service-account minting with the
 * `wp_mcp_ai_google_access_token_{md5}` cache, RS256 assertion signing,
 * the IANA timezone fallback, and the scope assertion / calendar-ID
 * resolution helpers.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Class name/namespace — the platform's PSR-4 tree.
 *  - Per-mode collaborator seams (the discriminator is always
 *    `defined( 'WP_MCP_AI_PATH' )`): the OAuth service / scope registry /
 *    Calendar client classes resolve to the base monolith classes in
 *    monolith installs and to this package's `Google\*` classes
 *    standalone; settings resolve via
 *    `WP_MCP_AI_Admin_Settings::get_settings()` monolith and the
 *    `wp_mcp_ai_settings` option standalone; Pro Remote Sites
 *    resolution is monolith-only — standalone degrades with the
 *    documented `wp_mcp_ai_calendar_pro_required` error.
 *  - `WP_Error` is fully qualified (`\WP_Error`).
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\Google
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Google;

/**
 * Resolves Google Calendar credentials and builds configured API clients.
 *
 * @since 2.1.0
 */
class GoogleCalendarCredentials {

	/**
	 * Credential source: Pro Remote Sites connection.
	 *
	 * @var string
	 */
	const SOURCE_CONNECTION = 'connection';

	/**
	 * Credential source: base-plugin settings.
	 *
	 * @var string
	 */
	const SOURCE_SETTINGS = 'settings';

	/**
	 * Credential source: `wp_mcp_ai_google_calendar_*` filters.
	 *
	 * @var string
	 */
	const SOURCE_FILTER = 'filter';

	/**
	 * Remote Sites connection type slug for Google Calendar.
	 *
	 * @var string
	 */
	const CONNECTION_TYPE = 'google_calendar';

	/**
	 * Resolve credentials for a Calendar request.
	 *
	 * @since 2.1.0
	 *
	 * @param string              $connection_id Optional Remote Sites connection ID.
	 * @param array<string,mixed> $context       Optional tool context, forwarded to filters.
	 * @param array<string,mixed> $arguments     Optional tool arguments, forwarded to filters.
	 * @return array<string,mixed>|\WP_Error {
	 *     Resolved credentials on success.
	 *
	 *     @type string $source         One of the SOURCE_* constants.
	 *     @type string $client_id      OAuth client ID.
	 *     @type string $client_secret  OAuth client secret (plaintext).
	 *     @type string $refresh_token  OAuth refresh token (plaintext).
	 *     @type string $access_token   Pre-minted access token, when supplied by filter.
	 *     @type string $user_email     Authorised account email.
	 *     @type string $calendar_id    Default calendar identifier.
	 *     @type string $granted_scopes Space-delimited granted scopes.
	 *     @type string $scope_profile  Scope profile slug.
	 *     @type string $timezone       IANA timezone identifier.
	 *     @type string $cache_key      Stable identity for the access-token cache.
	 *     @type array  $service_account Service-account credentials, when supplied by filter.
	 * }
	 */
	public static function resolve( $connection_id = '', array $context = array(), array $arguments = array() ) {
		$connection_id = is_string( $connection_id ) ? sanitize_key( $connection_id ) : '';

		if ( '' !== $connection_id ) {
			return self::resolve_from_connection( $connection_id );
		}

		$settings = self::resolve_from_settings();

		if ( ! is_wp_error( $settings ) ) {
			return $settings;
		}

		$filtered = self::resolve_from_filters( $context, $arguments );

		if ( ! is_wp_error( $filtered ) ) {
			return $filtered;
		}

		// Prefer the settings-level error: it is the actionable one for admins.
		return $settings;
	}

	/**
	 * Resolve credentials from a Pro Remote Sites connection.
	 *
	 * Monolith-only: the Pro addon does not ship with the platform addon, so
	 * standalone installs degrade with the documented error instead of probing
	 * a class the monorepo autoloader might resolve to a missing file.
	 *
	 * @since 2.1.0
	 *
	 * @param string $connection_id Connection ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	protected static function resolve_from_connection( $connection_id ) {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			return new \WP_Error(
				'wp_mcp_ai_calendar_pro_required',
				__( 'Remote Site connections require the NV oOS Pro addon. Configure Google Calendar in Tools → Connections instead.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
				return new \WP_Error(
					'wp_mcp_ai_calendar_pro_required',
					__( 'Remote Site connections require the NV oOS Pro addon. Configure Google Calendar in Tools → Connections instead.', 'nvoos-content-graph-ai-platform' ),
					array( 'status' => 400 )
				);
			}

			$manager_file = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';

			if ( ! file_exists( $manager_file ) ) {
				return new \WP_Error(
					'wp_mcp_ai_calendar_pro_required',
					__( 'The Remote Site connection manager is unavailable.', 'nvoos-content-graph-ai-platform' ),
					array( 'status' => 500 )
				);
			}

			require_once $manager_file;
		}

		$connection = \WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( empty( $connection ) ) {
			return new \WP_Error(
				'wp_mcp_ai_calendar_connection_not_found',
				sprintf(
					/* translators: %s: connection ID. */
					__( 'Google Calendar connection "%s" was not found. Check your Remote Sites configuration.', 'nvoos-content-graph-ai-platform' ),
					$connection_id
				),
				array( 'status' => 404 )
			);
		}

		$type = isset( $connection['connection_type'] ) ? (string) $connection['connection_type'] : '';

		if ( '' !== $type && self::CONNECTION_TYPE !== $type ) {
			return new \WP_Error(
				'wp_mcp_ai_calendar_wrong_connection_type',
				sprintf(
					/* translators: %s: connection type slug. */
					__( 'Connection "%s" is not a Google Calendar connection. Select a Google Calendar connection type.', 'nvoos-content-graph-ai-platform' ),
					$type
				),
				array( 'status' => 400 )
			);
		}

		if ( isset( $connection['enabled'] ) && ! $connection['enabled'] ) {
			return new \WP_Error(
				'wp_mcp_ai_calendar_connection_disabled',
				__( 'This Google Calendar connection is disabled.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		$client_id     = isset( $connection['client_id'] ) ? trim( (string) $connection['client_id'] ) : '';
		$client_secret = isset( $connection['client_secret'] ) ? trim( (string) $connection['client_secret'] ) : '';
		$refresh_token = isset( $connection['refresh_token'] ) ? trim( (string) $connection['refresh_token'] ) : '';

		if ( '' !== $client_secret ) {
			$client_secret = \WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $client_secret );
		}

		if ( '' !== $refresh_token ) {
			$refresh_token = \WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $refresh_token );
		}

		if ( '' === $client_id || '' === $client_secret || '' === $refresh_token ) {
			return new \WP_Error(
				'wp_mcp_ai_calendar_connection_incomplete',
				__( 'This Google Calendar connection is not fully authorised. Open the connection and complete the OAuth flow.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		$scopes = self::scopes_class();

		return array(
			'source'          => self::SOURCE_CONNECTION,
			'connection_id'   => $connection_id,
			'client_id'       => $client_id,
			'client_secret'   => $client_secret,
			'refresh_token'   => $refresh_token,
			'access_token'    => '',
			'user_email'      => isset( $connection['user_email'] ) ? trim( (string) $connection['user_email'] ) : '',
			'calendar_id'     => isset( $connection['calendar_id'] ) && '' !== trim( (string) $connection['calendar_id'] )
				? trim( (string) $connection['calendar_id'] )
				: 'primary',
			'granted_scopes'  => isset( $connection['granted_scopes'] ) ? (string) $connection['granted_scopes'] : '',
			'scope_profile'   => $scopes::normalise_profile(
				isset( $connection['scope_profile'] ) ? $connection['scope_profile'] : ''
			),
			'timezone'        => self::default_timezone(),
			'cache_key'       => 'connection:' . $connection_id,
			'service_account' => array(),
		);
	}

	/**
	 * Resolve credentials from base-plugin settings.
	 *
	 * @since 2.1.0
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	protected static function resolve_from_settings() {
		$settings = self::settings();

		$client_id     = isset( $settings['google_calendar_client_id'] ) ? trim( (string) $settings['google_calendar_client_id'] ) : '';
		$client_secret = isset( $settings['google_calendar_client_secret'] ) ? trim( (string) $settings['google_calendar_client_secret'] ) : '';
		$refresh_token = isset( $settings['google_calendar_refresh_token'] ) ? trim( (string) $settings['google_calendar_refresh_token'] ) : '';

		if ( '' === $client_id || '' === $client_secret || '' === $refresh_token ) {
			return new \WP_Error(
				'wp_mcp_ai_calendar_missing_credentials',
				__( 'Google Calendar is not connected. Open Tools → Connections → Google Calendar, save your OAuth client ID and secret, then click Connect. Pro sites can also use a Google Calendar connection under Remote Sites.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		$calendar_id = isset( $settings['google_calendar_default_calendar_id'] )
			? trim( (string) $settings['google_calendar_default_calendar_id'] )
			: '';

		$timezone = isset( $settings['google_calendar_timezone'] ) ? trim( (string) $settings['google_calendar_timezone'] ) : '';

		$scopes = self::scopes_class();

		return array(
			'source'          => self::SOURCE_SETTINGS,
			'connection_id'   => '',
			'client_id'       => $client_id,
			'client_secret'   => $client_secret,
			'refresh_token'   => $refresh_token,
			'access_token'    => '',
			'user_email'      => isset( $settings['google_calendar_user_email'] ) ? trim( (string) $settings['google_calendar_user_email'] ) : '',
			'calendar_id'     => '' !== $calendar_id ? $calendar_id : 'primary',
			'granted_scopes'  => isset( $settings['google_calendar_granted_scopes'] ) ? (string) $settings['google_calendar_granted_scopes'] : '',
			'scope_profile'   => $scopes::normalise_profile(
				isset( $settings['google_calendar_scope_profile'] ) ? $settings['google_calendar_scope_profile'] : ''
			),
			'timezone'        => '' !== $timezone ? $timezone : self::default_timezone(),
			'cache_key'       => 'settings:' . get_current_blog_id(),
			'service_account' => array(),
		);
	}

	/**
	 * Resolve credentials from the legacy filter surface.
	 *
	 * Preserves backward compatibility with sites that supplied a pre-minted
	 * access token or a Google service account via filters before connections
	 * existed. Service accounts cannot be used with consumer `@gmail.com`
	 * accounts — domain-wide delegation is authorised only in the Google
	 * Workspace Admin console.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string,mixed> $context   Tool context.
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @return array<string,mixed>|\WP_Error
	 */
	protected static function resolve_from_filters( array $context = array(), array $arguments = array() ) {
		/**
		 * Filters a pre-minted Google Calendar access token.
		 *
		 * @since 2.1.0
		 *
		 * @param string $token     Access token. Default empty.
		 * @param array  $context   Tool context.
		 * @param array  $arguments Tool arguments.
		 */
		$token = apply_filters( 'wp_mcp_ai_google_calendar_access_token', '', $context, $arguments, null );
		$token = is_string( $token ) ? trim( $token ) : '';

		/**
		 * Filters Google service-account credentials for Calendar access.
		 *
		 * @since 2.1.0
		 *
		 * @param array $credentials Service-account credentials. Default empty.
		 * @param array $context     Tool context.
		 * @param array $arguments   Tool arguments.
		 */
		$service_account = apply_filters( 'wp_mcp_ai_google_calendar_service_account_credentials', array(), $context, $arguments, null );
		$service_account = is_array( $service_account ) ? $service_account : array();

		if ( '' === $token && empty( $service_account ) ) {
			return new \WP_Error(
				'wp_mcp_ai_calendar_missing_credentials',
				__( 'Google Calendar credentials were not provided.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		/**
		 * Filters the default Google Calendar identifier.
		 *
		 * @since 2.1.0
		 *
		 * @param string $calendar_id Calendar identifier. Default empty.
		 * @param array  $context     Tool context.
		 * @param array  $arguments   Tool arguments.
		 */
		$calendar_id = apply_filters( 'wp_mcp_ai_google_calendar_default_calendar_id', '', $context, $arguments, null );
		$calendar_id = is_string( $calendar_id ) ? sanitize_text_field( $calendar_id ) : '';

		$scopes = self::scopes_class();

		return array(
			'source'          => self::SOURCE_FILTER,
			'connection_id'   => '',
			'client_id'       => '',
			'client_secret'   => '',
			'refresh_token'   => '',
			'access_token'    => $token,
			'user_email'      => '',
			'calendar_id'     => '' !== $calendar_id ? $calendar_id : 'primary',
			// Filter-supplied credentials predate scope tracking; treat as unrestricted.
			'granted_scopes'  => '',
			'scope_profile'   => $scopes::DEFAULT_PROFILE,
			'timezone'        => self::default_timezone(),
			'cache_key'       => 'filter:' . get_current_blog_id(),
			'service_account' => $service_account,
		);
	}

	/**
	 * Build a configured Calendar API client from resolved credentials.
	 *
	 * The token provider is a closure so the access token is minted lazily and
	 * only when a request is actually made.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string,mixed> $credentials Output of `resolve()`.
	 * @param array<string,mixed> $options     Optional client options.
	 * @return GoogleCalendarClient|\WP_Error
	 */
	public static function make_client( array $credentials, array $options = array() ) {
		$access_token    = isset( $credentials['access_token'] ) ? (string) $credentials['access_token'] : '';
		$service_account = isset( $credentials['service_account'] ) ? (array) $credentials['service_account'] : array();

		if ( '' !== $access_token ) {
			$provider = $access_token;
		} elseif ( ! empty( $service_account ) ) {
			$provider = static function () use ( $service_account, $credentials ) {
				return self::mint_service_account_token( $service_account, $credentials );
			};
		} else {
			$client_id     = isset( $credentials['client_id'] ) ? (string) $credentials['client_id'] : '';
			$client_secret = isset( $credentials['client_secret'] ) ? (string) $credentials['client_secret'] : '';
			$refresh_token = isset( $credentials['refresh_token'] ) ? (string) $credentials['refresh_token'] : '';
			$cache_key     = isset( $credentials['cache_key'] ) ? (string) $credentials['cache_key'] : '';

			if ( '' === $client_id || '' === $client_secret || '' === $refresh_token ) {
				return new \WP_Error(
					'wp_mcp_ai_calendar_missing_credentials',
					__( 'Google Calendar credentials are incomplete.', 'nvoos-content-graph-ai-platform' ),
					array( 'status' => 400 )
				);
			}

			$oauth = self::oauth_service_class();

			$provider = static function () use ( $client_id, $client_secret, $refresh_token, $cache_key, $oauth ) {
				return $oauth::mint_access_token(
					array(
						'client_id'     => $client_id,
						'client_secret' => $client_secret,
						'refresh_token' => $refresh_token,
						'cache_key'     => $cache_key,
					)
				);
			};
		}

		// Attribute per-user quota to the impersonated account where known.
		if ( ! isset( $options['quota_user'] ) && ! empty( $credentials['user_email'] ) ) {
			$options['quota_user'] = (string) $credentials['user_email'];
		}

		$client_class = self::client_class();

		return new $client_class( $provider, $options );
	}

	/**
	 * Mint an access token from Google service-account credentials.
	 *
	 * Uses the JWT bearer grant with an optional `sub` claim for domain-wide
	 * delegation.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string,mixed> $service_account Service-account credentials.
	 * @param array<string,mixed> $credentials     Resolved credential bundle.
	 * @return string|\WP_Error Access token or WP_Error.
	 */
	protected static function mint_service_account_token( array $service_account, array $credentials ) {
		$client_email = isset( $service_account['client_email'] ) ? sanitize_email( $service_account['client_email'] ) : '';
		$private_key  = isset( $service_account['private_key'] ) ? trim( (string) $service_account['private_key'] ) : '';
		$token_uri    = isset( $service_account['token_uri'] ) ? esc_url_raw( $service_account['token_uri'] ) : '';
		$delegated    = isset( $service_account['delegated_email'] ) ? sanitize_email( $service_account['delegated_email'] ) : '';

		if ( '' === $client_email || '' === $private_key ) {
			return new \WP_Error(
				'wp_mcp_ai_calendar_invalid_credentials',
				__( 'Incomplete Google service account credentials.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 500 )
			);
		}

		$oauth = self::oauth_service_class();

		if ( '' === $token_uri ) {
			$token_uri = $oauth::TOKEN_ENDPOINT;
		}

		$scopes = isset( $service_account['scopes'] ) ? $service_account['scopes'] : '';

		if ( is_array( $scopes ) ) {
			$scopes = implode( ' ', array_filter( array_map( 'trim', $scopes ) ) );
		} else {
			$scopes = trim( (string) $scopes );
		}

		$scopes_class = self::scopes_class();

		if ( '' === $scopes ) {
			$profile = isset( $credentials['scope_profile'] ) ? (string) $credentials['scope_profile'] : '';
			$scopes  = $scopes_class::get_profile_scope_string( $profile );
		}

		$cache_key = 'sa:' . md5( $client_email . '|' . $delegated . '|' . $scopes );
		$transient = 'wp_mcp_ai_google_access_token_' . md5( $cache_key );
		$cached    = get_transient( $transient );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$now    = time();
		$claims = array(
			'iss'   => $client_email,
			'scope' => $scopes,
			'aud'   => $token_uri,
			'iat'   => $now,
			'exp'   => $now + 3600,
		);

		if ( '' !== $delegated ) {
			$claims['sub'] = $delegated;
		}

		$assertion = self::build_jwt_assertion( $claims, $private_key );

		if ( is_wp_error( $assertion ) ) {
			return $assertion;
		}

		$response = wp_remote_post(
			$token_uri,
			array(
				'timeout' => $oauth::DEFAULT_TIMEOUT,
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept'       => 'application/json',
				),
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $assertion,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'wp_mcp_ai_calendar_token_error',
				__( 'Unable to obtain a Google access token for the service account.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 500 )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$data   = is_array( $data ) ? $data : array();

		if ( $status < 200 || $status >= 300 || empty( $data['access_token'] ) ) {
			$message = __( 'Google rejected the service account token request.', 'nvoos-content-graph-ai-platform' );

			if ( ! empty( $data['error_description'] ) ) {
				$message = sprintf( '%s %s', $message, (string) $data['error_description'] );
			}

			return new \WP_Error(
				'wp_mcp_ai_calendar_token_error',
				$message,
				array(
					'status'   => $status,
					'response' => $data,
				)
			);
		}

		$access_token = (string) $data['access_token'];
		$expires_in   = isset( $data['expires_in'] ) ? absint( $data['expires_in'] ) : 3600;
		$ttl          = $expires_in - $oauth::TOKEN_CACHE_MARGIN;

		if ( $ttl > 0 ) {
			set_transient( $transient, $access_token, $ttl );
		}

		return $access_token;
	}

	/**
	 * Build a signed RS256 JWT assertion.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string,mixed> $claims      JWT claim set.
	 * @param string              $private_key PEM-encoded private key.
	 * @return string|\WP_Error Assertion or WP_Error.
	 */
	protected static function build_jwt_assertion( array $claims, $private_key ) {
		if ( ! function_exists( 'openssl_sign' ) ) {
			return new \WP_Error(
				'wp_mcp_ai_calendar_signing_unavailable',
				__( 'The OpenSSL extension is required to sign Google service account assertions.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 500 )
			);
		}

		$segments = array(
			self::base64url_encode(
				wp_json_encode(
					array(
						'alg' => 'RS256',
						'typ' => 'JWT',
					)
				)
			),
			self::base64url_encode( wp_json_encode( $claims ) ),
		);

		$signature = '';

		if ( ! openssl_sign( implode( '.', $segments ), $signature, $private_key, 'sha256' ) ) {
			return new \WP_Error(
				'wp_mcp_ai_calendar_signing_failed',
				__( 'Unable to sign the Google service account assertion.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 500 )
			);
		}

		$segments[] = self::base64url_encode( $signature );

		return implode( '.', $segments );
	}

	/**
	 * Base64url-encode a value without padding.
	 *
	 * @since 2.1.0
	 *
	 * @param string $data Raw data.
	 * @return string Encoded value.
	 */
	protected static function base64url_encode( $data ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWT encoding, not obfuscation.
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Resolve the site's IANA timezone identifier.
	 *
	 * Always sending an explicit `timeZone` avoids depending on the calendar's
	 * implicit default, which changes silently if the user edits their Google
	 * Calendar settings.
	 *
	 * @since 2.1.0
	 *
	 * @return string IANA timezone identifier.
	 */
	public static function default_timezone() {
		$timezone = wp_timezone_string();

		// `wp_timezone_string()` can return a UTC offset such as "+05:30",
		// which the Calendar API rejects — it requires an IANA name.
		if ( '' === $timezone || preg_match( '/^[+-]\d{2}:\d{2}$/', $timezone ) ) {
			return 'UTC';
		}

		return $timezone;
	}

	/**
	 * Assert that resolved credentials carry a required scope.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string,mixed> $credentials Resolved credentials.
	 * @param string              $required    Required scope URL.
	 * @return true|\WP_Error True when satisfied, WP_Error otherwise.
	 */
	public static function require_scope( array $credentials, $required ) {
		$granted = isset( $credentials['granted_scopes'] ) ? (string) $credentials['granted_scopes'] : '';

		$scopes = self::scopes_class();

		if ( $scopes::has_scope( $granted, $required ) ) {
			return true;
		}

		return $scopes::missing_scope_error( $required );
	}

	/**
	 * Resolve the effective calendar identifier for a request.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string,mixed> $credentials Resolved credentials.
	 * @param string              $override    Optional per-request calendar ID.
	 * @return string Calendar identifier.
	 */
	public static function resolve_calendar_id( array $credentials, $override = '' ) {
		$override = is_string( $override ) ? sanitize_text_field( trim( $override ) ) : '';

		if ( '' !== $override ) {
			return $override;
		}

		$configured = isset( $credentials['calendar_id'] ) ? trim( (string) $credentials['calendar_id'] ) : '';

		return '' !== $configured ? $configured : 'primary';
	}

	/**
	 * Resolve the OAuth service class per install mode.
	 *
	 * The discriminator is `defined( 'WP_MCP_AI_PATH' )` — never bare
	 * `class_exists()` — because the monorepo autoloader resolves base classes
	 * to disk even when the base plugin is inactive.
	 *
	 * @return string Class name.
	 */
	protected static function oauth_service_class() {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			if ( ! class_exists( 'WP_MCP_AI_Google_OAuth_Service' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-oauth-service.php';
			}

			return 'WP_MCP_AI_Google_OAuth_Service';
		}

		return GoogleOAuthService::class;
	}

	/**
	 * Resolve the scope registry class per install mode.
	 *
	 * @return string Class name.
	 */
	protected static function scopes_class() {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			if ( ! class_exists( 'WP_MCP_AI_Google_Calendar_Scopes' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-calendar-scopes.php';
			}

			return 'WP_MCP_AI_Google_Calendar_Scopes';
		}

		return GoogleCalendarScopes::class;
	}

	/**
	 * Resolve the Calendar API client class per install mode.
	 *
	 * @return string Class name.
	 */
	protected static function client_class() {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			if ( ! class_exists( 'WP_MCP_AI_Google_Calendar_Client' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-calendar-client.php';
			}

			return 'WP_MCP_AI_Google_Calendar_Client';
		}

		return GoogleCalendarClient::class;
	}

	/**
	 * Resolve the plugin settings per install mode.
	 *
	 * Monolith installs read through the base admin-settings component (which
	 * merges the full defaults set); standalone reads the raw
	 * `wp_mcp_ai_settings` option, matching the E4 OAuth sub-cluster seam.
	 *
	 * @return array<string,mixed> Settings.
	 */
	protected static function settings() {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
			}

			return \WP_MCP_AI_Admin_Settings::get_settings();
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );

		return is_array( $settings ) ? $settings : array();
	}
}
