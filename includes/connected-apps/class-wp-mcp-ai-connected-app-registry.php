<?php
/**
 * Connected-app discovery for the console (Proposal 090-i Phase 4 / 090-p W6).
 *
 * @package WP_MCP_AI
 * @since   1.2.22
 * @license GPL-3.0-or-later
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Connected_App_Registry' ) ) {
	/**
	 * Discovery for the four console keys supplied by separate NV oOS products.
	 *
	 * `contentGraph`, `docsHub`, `mediaStudio` and `documentEditor` are their
	 * own products with their own REST contracts. On this runtime they are
	 * reachable only when an operator points each one at its product base URL.
	 * This registry probes those bases, caches the answer, and reports the
	 * URLs through the capability manifest (`endpoint_urls` plus a flipped
	 * `endpoints` entry) — the WordPress twin of Laravel's
	 * `App\Support\ConnectedApps\ConnectedAppRegistry`.
	 *
	 * The probe is deliberately shallow and conservative:
	 *
	 *  - one GET per configured app, `redirection => 0` (a redirect is the
	 *    classic SSRF pivot from an allowed host to an internal one);
	 *  - HTTPS required, except a loopback host in the `local` environment;
	 *  - the hostname is resolved ONCE, every address is checked against
	 *    {@see WP_MCP_AI_Url_Guard} (a hostname allowlist alone does not stop
	 *    DNS rebinding), and the validated addresses are pinned with
	 *    `CURLOPT_RESOLVE` so the transport cannot re-resolve to a different
	 *    address. Resolving once and pinning that same set is deliberate: a
	 *    validate-then-resolve sequence leaves a window where the second
	 *    answer differs from the first;
	 *  - a 2xx/3xx, or a 401/403, means *reached* — a product that answers
	 *    with auth required is present, which is the fact the console needs.
	 *    404, 5xx and transport errors mean unavailable;
	 *  - an optional per-app `token` is sent as a bearer credential. It is read
	 *    from {@see WP_MCP_AI_Api_Key_Store} (AES-256-GCM at rest) via
	 *    {@see token_suffix()}; a descriptor `token` is only a fallback for
	 *    programmatic callers. It never appears in the manifest or the probe
	 *    result;
	 *  - results are cached, including failures, so an unreachable product
	 *    cannot slow every console boot.
	 *
	 * Pinning is only as strong as the transport: the cURL transport honours
	 * `CURLOPT_RESOLVE`, the streams transport ignores it. The resolved-address
	 * check above is the load-bearing control either way.
	 *
	 * Scope: this feeds `GET /features`, which is what the Complete Console
	 * reads. The Pro SPA's localized runtime config builds its endpoints from
	 * {@see WP_MCP_AI_Feature_Flags} and same-site `rest_url()` values, so a
	 * remote product is not mounted there — that app has no same-origin proxy.
	 */
	class WP_MCP_AI_Connected_App_Registry {

		/**
		 * Option holding the operator's app descriptors.
		 *
		 * @var string
		 */
		const OPTION = 'wp_mcp_ai_connected_apps';

		/**
		 * Filter: replace the descriptors (wins over the option).
		 *
		 * @var string
		 */
		const FILTER = 'wp_mcp_ai_connected_apps';

		/**
		 * Filter: probe result TTL in seconds.
		 *
		 * @var string
		 */
		const FILTER_TTL = 'wp_mcp_ai_connected_apps_ttl';

		/**
		 * Transient prefix for probe results.
		 *
		 * @var string
		 */
		const TRANSIENT_PREFIX = 'wp_mcp_ai_connected_apps_';

		/**
		 * Default probe path per console key.
		 *
		 * Each product exposes a health route; Content Graph uses its public
		 * graph route instead. A descriptor may override with `probe`.
		 *
		 * @var array<string,string>
		 */
		const DEFAULTS = array(
			'contentGraph'   => '/graph',
			'docsHub'        => '/health',
			'mediaStudio'    => '/health',
			'documentEditor' => '/health',
		);

		/**
		 * Console key => feature-flag key, so `/features` cannot report the
		 * product absent on one map and present on the other.
		 *
		 * @var array<string,string>
		 */
		const FEATURE_FLAGS = array(
			'contentGraph'   => 'knowledge_graph',
			'docsHub'        => 'docs_hub',
			'mediaStudio'    => 'media_studio',
			'documentEditor' => 'document_editor',
		);

		/**
		 * Console key => API-key-store suffix for that app's bearer token.
		 *
		 * Tokens are credentials that leave the site, so they live in
		 * {@see WP_MCP_AI_Api_Key_Store} (AES-256-GCM at rest), not in the
		 * descriptor panel and not in `wp_options` in the clear.
		 *
		 * @var array<string,string>
		 */
		const TOKEN_SUFFIXES = array(
			'contentGraph'   => 'connected_app_content_graph_token',
			'docsHub'        => 'connected_app_docs_hub_token',
			'mediaStudio'    => 'connected_app_media_studio_token',
			'documentEditor' => 'connected_app_document_editor_token',
		);

		/**
		 * Per-probe HTTP timeout in seconds.
		 *
		 * Four apps at the worst case is bounded on purpose: the result is
		 * cached, but the first console boot after a cache miss must not hang.
		 *
		 * @var int
		 */
		const TIMEOUT = 2;

		/**
		 * Request-scoped memo, so one request probes at most once.
		 *
		 * @var array<string,string>|null
		 */
		private $memo = null;

		/**
		 * Test seam: callable( string $host ): array<string>|false.
		 *
		 * @var callable|null
		 */
		private $resolver;

		/**
		 * Shared instance used by the REST surface.
		 *
		 * @var self|null
		 */
		private static $instance = null;

		/**
		 * Constructor.
		 *
		 * @param callable|null $resolver Optional resolver seam (host => IPs).
		 */
		public function __construct( $resolver = null ) {
			$this->resolver = is_callable( $resolver ) ? $resolver : null;
		}

		/**
		 * Shared instance.
		 *
		 * @return self
		 */
		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Reset the shared instance (tests).
		 *
		 * @return void
		 */
		public static function reset_instance() {
			self::$instance = null;
		}

		/**
		 * Configured apps that answered their probe.
		 *
		 * @return array<string,string> Console endpoint key => product REST base.
		 */
		public function available() {
			if ( null !== $this->memo ) {
				return $this->memo;
			}

			$configured = $this->configured();
			if ( array() === $configured ) {
				$this->memo = array();
				return $this->memo;
			}

			/**
			 * Filter the probe result TTL in seconds.
			 *
			 * Failures are cached for the same window, so a longer TTL also means a
			 * product that comes back stays invisible for longer.
			 *
			 * @param int $ttl Seconds. Default 300.
			 */
			$ttl = max( 1, (int) apply_filters( self::FILTER_TTL, 300 ) );
			$key = self::TRANSIENT_PREFIX . md5( (string) wp_json_encode( $configured ) );

			$results = get_transient( $key );
			if ( ! is_array( $results ) ) {
				$results = $this->probe( $configured );
				// Failures are cached too: a dead product must not be re-probed
				// on every console boot.
				set_transient( $key, $results, $ttl );
			}

			$urls = array();
			foreach ( $results as $endpoint => $result ) {
				if ( ! empty( $result['available'] ) && isset( $result['url'] ) ) {
					$urls[ $endpoint ] = (string) $result['url'];
				}
			}

			$this->memo = $urls;
			return $urls;
		}

		/**
		 * Apply discovered apps to a feature-flag map.
		 *
		 * A remote product that answered is available even though its plugin
		 * class is not loaded, so the local class check must not contradict
		 * the manifest.
		 *
		 * @param array<string,bool>   $features Feature flag map.
		 * @param array<string,string> $urls     Reached apps from {@see available()}.
		 * @return array<string,bool>
		 */
		public static function apply_feature_flags( array $features, array $urls ) {
			foreach ( array_keys( $urls ) as $key ) {
				if ( isset( self::FEATURE_FLAGS[ $key ] ) ) {
					$features[ self::FEATURE_FLAGS[ $key ] ] = true;
				}
			}
			return $features;
		}

		/**
		 * The API-key-store suffix holding a given app's bearer token.
		 *
		 * Public so an operator or CLI command can store a credential without
		 * duplicating the naming rule:
		 *
		 *     WP_MCP_AI_Api_Key_Store::set(
		 *         WP_MCP_AI_Connected_App_Registry::token_suffix( 'docsHub' ),
		 *         $token
		 *     );
		 *
		 * @param string $key Console endpoint key.
		 * @return string Option suffix, or '' for an unknown key.
		 */
		public static function token_suffix( $key ) {
			return self::TOKEN_SUFFIXES[ $key ] ?? '';
		}

		/**
		 * Normalise the operator configuration.
		 *
		 * Unknown keys and malformed entries are dropped rather than throwing:
		 * a typo in one descriptor must not take the console offline, and the
		 * fixed key list is what bounds the surface.
		 *
		 * @return array<string,array{base:string,token:string,probe:string,insecure:bool}>
		 */
		protected function configured() {
			$raw = get_option( self::OPTION, array() );

			/**
			 * Filter the connected-app descriptors.
			 *
			 * @param mixed $raw Descriptor map from the option.
			 */
			$raw = apply_filters( self::FILTER, $raw );

			if ( ! is_array( $raw ) ) {
				return array();
			}

			$out = array();

			foreach ( self::DEFAULTS as $key => $default_probe ) {
				if ( ! isset( $raw[ $key ] ) || ! is_array( $raw[ $key ] ) ) {
					continue;
				}

				$entry = $raw[ $key ];
				$base  = isset( $entry['base'] ) && is_string( $entry['base'] )
					? esc_url_raw( $entry['base'], array( 'http', 'https' ) )
					: '';

				if ( '' === $base ) {
					continue;
				}

				$probe = isset( $entry['probe'] ) && is_string( $entry['probe'] ) && '' !== $entry['probe']
					? '/' . ltrim( $entry['probe'], '/' )
					: $default_probe;

				$out[ $key ] = array(
					'base'     => untrailingslashit( $base ),
					'token'    => $this->token_for( $key, $entry ),
					'probe'    => $probe,
					'insecure' => ! empty( $entry['insecure'] ),
				);
			}

			return $out;
		}

		/**
		 * Resolve one app's bearer token.
		 *
		 * The encrypted key store is authoritative. A `token` in the descriptor is
		 * a fallback for programmatic callers (a filter that provisions config in
		 * code) and for tests; it is never the preferred home, and a descriptor
		 * token is ignored when the store already holds one — so rotating a
		 * credential in the store takes effect without editing the option.
		 *
		 * @param string $key   Console endpoint key.
		 * @param array  $entry Descriptor entry.
		 * @return string
		 */
		protected function token_for( $key, array $entry ) {
			$suffix = self::token_suffix( $key );

			if ( '' !== $suffix && class_exists( 'WP_MCP_AI_Api_Key_Store' ) ) {
				$stored = (string) WP_MCP_AI_Api_Key_Store::get( $suffix );
				if ( '' !== $stored ) {
					return $stored;
				}
			}

			return isset( $entry['token'] ) && is_string( $entry['token'] ) ? $entry['token'] : '';
		}

		/**
		 * Probe every configured app.
		 *
		 * @param array<string,array{base:string,token:string,probe:string,insecure:bool}> $configured Descriptors.
		 * @return array<string,array{url:string,available:bool,status:int|null,error?:string}>
		 */
		protected function probe( array $configured ) {
			$results = array();

			foreach ( $configured as $key => $app ) {
				$results[ $key ] = $this->probe_one( $app );
			}

			return $results;
		}

		/**
		 * Probe one app.
		 *
		 * @param array{base:string,token:string,probe:string,insecure:bool} $app Descriptor.
		 * @return array{url:string,available:bool,status:int|null,error?:string}
		 */
		protected function probe_one( array $app ) {
			$unavailable = array(
				'url'       => $app['base'],
				'available' => false,
				'status'    => null,
			);

			$target = $app['base'] . $app['probe'];

			$transport = $this->check_transport( $target );
			if ( is_wp_error( $transport ) ) {
				$unavailable['error'] = $transport->get_error_code();
				return $unavailable;
			}

			$pinned = $this->resolve_for_pin( $target );
			if ( is_wp_error( $pinned ) ) {
				$unavailable['error'] = $pinned->get_error_code();
				return $unavailable;
			}

			$args = array(
				'timeout'     => self::TIMEOUT,
				// Never follow a redirect: it is the pivot from an allowed host
				// to an internal one.
				'redirection' => 0,
				'sslverify'   => empty( $app['insecure'] ),
				'headers'     => array( 'Accept' => 'application/json' ),
			);

			if ( '' !== $app['token'] ) {
				$args['headers']['Authorization'] = 'Bearer ' . $app['token'];
			}

			$pin_hook = $this->pin( $target, $pinned );
			$response = wp_remote_get( $target, $args );
			$this->unpin( $pin_hook );

			if ( is_wp_error( $response ) ) {
				$unavailable['error'] = $response->get_error_code();
				return $unavailable;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );

			// A product that demands auth is present — that is the fact the
			// console needs. Any other 4xx, or a 5xx, means the surface is not
			// there.
			$available = ( $status >= 200 && $status < 400 ) || 401 === $status || 403 === $status;

			return array(
				'url'       => $app['base'],
				'available' => $available,
				'status'    => $status,
			);
		}

		/**
		 * Require HTTPS for anything that is not a local loopback host.
		 *
		 * @param string $url Probe URL.
		 * @return true|WP_Error
		 */
		protected function check_transport( $url ) {
			$parts = wp_parse_url( $url );

			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_connected_apps_invalid_url',
					__( 'Connected app base URL could not be parsed.', 'mcp-ai-wpoos' )
				);
			}

			$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				return new WP_Error(
					'wp_mcp_ai_connected_apps_invalid_scheme',
					__( 'Connected app base URL must use http or https.', 'mcp-ai-wpoos' )
				);
			}

			if ( 'https' === $scheme ) {
				return true;
			}

			// Plain HTTP is a local-development affordance only.
			if ( $this->is_local_loopback( (string) $parts['host'] ) ) {
				return true;
			}

			return new WP_Error(
				'wp_mcp_ai_connected_apps_insecure',
				__( 'Connected apps must be served over HTTPS.', 'mcp-ai-wpoos' )
			);
		}

		/**
		 * Resolve the target host and refuse any blocked address.
		 *
		 * Returns the addresses to pin. An empty array means "no pinning":
		 * either an operator whitelisted the host, or it is a local loopback
		 * development target.
		 *
		 * @param string $url Probe URL.
		 * @return array<string>|WP_Error
		 */
		protected function resolve_for_pin( $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );

			if ( ! is_string( $host ) || '' === $host ) {
				return new WP_Error(
					'wp_mcp_ai_connected_apps_invalid_url',
					__( 'Connected app base URL has no host.', 'mcp-ai-wpoos' )
				);
			}

			$host = strtolower( trim( $host, '[]' ) );

			/** This filter is documented in includes/security/class-wp-mcp-ai-url-guard.php */
			$allowed = (array) apply_filters( 'wp_mcp_ai_http_allowed_host', array(), $host, $url );
			if ( in_array( $host, $allowed, true ) ) {
				return array();
			}

			if ( $this->is_local_loopback( $host ) ) {
				return array();
			}

			$ips = null === $this->resolver
				? WP_MCP_AI_Url_Guard::resolve( $host )
				: call_user_func( $this->resolver, $host );

			if ( ! is_array( $ips ) || array() === $ips ) {
				return new WP_Error(
					'wp_mcp_ai_connected_apps_dns_failed',
					__( 'Connected app host could not be resolved.', 'mcp-ai-wpoos' )
				);
			}

			foreach ( $ips as $ip ) {
				$check = WP_MCP_AI_Url_Guard::is_blocked_ip( (string) $ip );
				if ( is_wp_error( $check ) ) {
					return $check;
				}
			}

			return array_values( array_map( 'strval', $ips ) );
		}

		/**
		 * Pin the validated addresses onto the cURL handle for one request.
		 *
		 * `CURLOPT_RESOLVE` is honoured by the cURL transport only; the
		 * streams transport ignores it, which is why the address check above
		 * is the load-bearing control.
		 *
		 * @param string        $url Probe URL.
		 * @param array<string> $ips Validated addresses.
		 * @return callable|null The hook to remove afterwards, or null.
		 */
		protected function pin( $url, array $ips ) {
			if ( array() === $ips || ! function_exists( 'curl_setopt' ) || ! defined( 'CURLOPT_RESOLVE' ) ) {
				return null;
			}

			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				return null;
			}

			$host = strtolower( trim( (string) $parts['host'], '[]' ) );
			$port = isset( $parts['port'] )
				? (int) $parts['port']
				: ( 'https' === strtolower( (string) ( isset( $parts['scheme'] ) ? $parts['scheme'] : '' ) ) ? 443 : 80 );

			$entries = array();
			foreach ( $ips as $ip ) {
				// gethostbynamel() is IPv4-only; skip anything unparseable rather
				// than emitting a malformed resolve entry.
				if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
					continue;
				}
				$entries[] = sprintf( '%s:%d:%s', $host, $port, $ip );
			}

			if ( array() === $entries ) {
				return null;
			}

			$callback = static function ( $handle, $args, $requested ) use ( $entries, $host ) {
				unset( $args ); // Signature requirement.

				if ( ! is_string( $requested ) ) {
					return;
				}

				$requested_host = wp_parse_url( $requested, PHP_URL_HOST );
				if ( ! is_string( $requested_host ) || strtolower( trim( $requested_host, '[]' ) ) !== $host ) {
					return;
				}

				// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Address pinning on the transport handle the HTTP API already created; wp_remote_get() has no equivalent option.
				curl_setopt( $handle, CURLOPT_RESOLVE, $entries );
			};

			add_action( 'http_api_curl', $callback, 10, 3 );

			return $callback;
		}

		/**
		 * Remove the pin hook.
		 *
		 * @param callable|null $callback Hook registered by {@see pin()}.
		 * @return void
		 */
		protected function unpin( $callback ) {
			if ( is_callable( $callback ) ) {
				remove_action( 'http_api_curl', $callback, 10 );
			}
		}

		/**
		 * Is this host loopback AND is the site running locally?
		 *
		 * The environment check is what keeps the plain-HTTP affordance out of
		 * production: a loopback host in `production` is still refused.
		 *
		 * @param string $host Hostname.
		 * @return bool
		 */
		protected function is_local_loopback( $host ) {
			$host = strtolower( trim( $host, '[]' ) );

			$loopback = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true )
				|| 0 === strpos( $host, '127.' );

			if ( ! $loopback ) {
				return false;
			}

			return function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type();
		}
	}
}
