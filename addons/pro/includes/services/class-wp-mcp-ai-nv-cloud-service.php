<?php
/**
 * NV oOS Cloud — Service singleton.
 *
 * Holds connect-token storage, balance cache, markup constants and ledger
 * accessors for the hosted "Managed Tokens" feature. The Cloudflare AI Gateway
 * (front of OpenRouter) acts as the wholesale-inference backbone; this class
 * exposes only the local plugin-side state.
 *
 * Stripe is the merchant of record. Markup is **7% on wholesale + Stripe
 * processor pass-through** — the 7% covers operational overhead while the
 * pass-through is shown as a transparent line item on every invoice so the
 * customer sees exactly what the markup was.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.7.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_NV_Cloud_Service' ) ) {

	/**
	 * Singleton holding NV oOS Cloud connection state.
	 */
	class WP_MCP_AI_NV_Cloud_Service {

		/**
		 * Default base URL for the hosted gateway. Overridable via the
		 * `WP_MCP_AI_NV_CLOUD_BASE_URL` constant or the
		 * `wp_mcp_ai_nv_cloud_base_url` filter.
		 *
		 * @var string
		 */
		const DEFAULT_BASE_URL = 'https://nvoos.cloud/v1';

		/**
		 * Service-fee markup applied to upstream wholesale cost (7%).
		 *
		 * @var float
		 */
		const MARKUP_RATE = 0.07;

		/**
		 * Stripe pass-through fee components used to derive the per-top-up
		 * processor cost surfaced as a transparent line item.
		 *
		 * Stripe's standard rate is 2.9% + $0.30 for cards in 2026.
		 *
		 * @var float
		 */
		const STRIPE_PERCENT = 0.029;

		/**
		 * Stripe fixed fee component in USD.
		 *
		 * @var float
		 */
		const STRIPE_FIXED_USD = 0.30;

		/**
		 * Default minimum top-up amount (USD).
		 *
		 * Set high enough that Stripe fees don't wipe out margin on small
		 * top-ups. See unit-economics check in plan §6.
		 *
		 * @var float
		 */
		const DEFAULT_MIN_TOPUP_USD = 25.0;

		/**
		 * Low-balance banner threshold (USD).
		 *
		 * @var float
		 */
		const LOW_BALANCE_THRESHOLD_USD = 2.0;

		/**
		 * Option key for the encrypted connect-token storage and metadata.
		 *
		 * @var string
		 */
		const OPTION_CONNECT = 'wp_mcp_ai_nv_cloud_connection';

		/**
		 * Option key for the cached balance + last-refresh timestamp.
		 *
		 * @var string
		 */
		const OPTION_BALANCE = 'wp_mcp_ai_nv_cloud_balance';

		/**
		 * Option key for the local ledger (most-recent N entries).
		 *
		 * The authoritative ledger lives on the SaaS; this is a local mirror
		 * for the in-plugin dashboard and works offline.
		 *
		 * @var string
		 */
		const OPTION_LEDGER = 'wp_mcp_ai_nv_cloud_ledger';

		/**
		 * Option key for user preferences (auto-topup, default-provider, etc.).
		 *
		 * @var string
		 */
		const OPTION_PREFS = 'wp_mcp_ai_nv_cloud_prefs';

		/**
		 * Maximum number of ledger entries kept locally.
		 *
		 * @var int
		 */
		const LEDGER_MAX_ENTRIES = 200;

		/**
		 * Singleton instance.
		 *
		 * @var WP_MCP_AI_NV_Cloud_Service|null
		 */
		protected static $instance = null;

		/**
		 * Get the singleton instance.
		 *
		 * @return WP_MCP_AI_NV_Cloud_Service
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Reset the singleton (for tests).
		 */
		public static function reset_instance() {
			self::$instance = null;
		}

		/**
		 * Resolve the configured gateway base URL.
		 *
		 * @return string Base URL without trailing slash.
		 */
		public function get_base_url() {
			$url = defined( 'WP_MCP_AI_NV_CLOUD_BASE_URL' ) ? WP_MCP_AI_NV_CLOUD_BASE_URL : self::DEFAULT_BASE_URL;
			/**
			 * Filter the NV oOS Cloud gateway base URL.
			 *
			 * Used in tests and for staging environments.
			 *
			 * @since 1.7.0
			 *
			 * @param string $url Default base URL.
			 */
			$url = apply_filters( 'wp_mcp_ai_nv_cloud_base_url', $url );
			$url = is_string( $url ) ? trim( $url ) : '';
			return '' !== $url ? untrailingslashit( esc_url_raw( $url ) ) : self::DEFAULT_BASE_URL;
		}

		/**
		 * Whether a connect token has been stored on this site.
		 *
		 * @return bool
		 */
		public function is_connected() {
			$token = $this->get_connect_token();
			return ! empty( $token );
		}

		/**
		 * Retrieve the stored connect token (decrypted).
		 *
		 * @return string Empty string when not connected.
		 */
		public function get_connect_token() {
			$record = get_option( self::OPTION_CONNECT, array() );
			if ( ! is_array( $record ) || empty( $record['token'] ) ) {
				return '';
			}
			return $this->decrypt_value( (string) $record['token'] );
		}

		/**
		 * Get the connection metadata (account id, connected_at, etc.).
		 *
		 * Does NOT include the token itself — callers should use
		 * `get_connect_token()` for that.
		 *
		 * @return array
		 */
		public function get_connection_meta() {
			$record = get_option( self::OPTION_CONNECT, array() );
			if ( ! is_array( $record ) ) {
				return array();
			}
			$record = wp_parse_args(
				$record,
				array(
					'account_id'   => '',
					'connected_at' => 0,
					'site_url'     => '',
					'token'        => '',
				)
			);
			unset( $record['token'] );
			return $record;
		}

		/**
		 * Persist a new connect token + metadata. Token is encrypted at rest
		 * with the WordPress AUTH_KEY when available.
		 *
		 * @param string $token       Connect token issued by the SaaS.
		 * @param array  $meta        Optional metadata: account_id.
		 * @return bool True on success.
		 */
		public function save_connection( $token, array $meta = array() ) {
			$token = is_string( $token ) ? trim( $token ) : '';
			if ( '' === $token ) {
				return false;
			}

			$record = array(
				'token'        => $this->encrypt_value( $token ),
				'account_id'   => isset( $meta['account_id'] ) ? sanitize_text_field( (string) $meta['account_id'] ) : '',
				'connected_at' => time(),
				'site_url'     => function_exists( 'home_url' ) ? esc_url_raw( home_url( '/' ) ) : '',
			);

			update_option( self::OPTION_CONNECT, $record, false );

			/**
			 * Fires after a Connect Token has been stored.
			 *
			 * @since 1.7.0
			 *
			 * @param array $meta Metadata (without the raw token).
			 */
			do_action( 'wp_mcp_ai_nv_cloud_connected', $this->get_connection_meta() );

			return true;
		}

		/**
		 * Wipe local connection state. Does NOT revoke the token at the SaaS;
		 * callers should make a separate `/account/revoke` call when the user
		 * explicitly disconnects.
		 *
		 * @return void
		 */
		public function forget_connection() {
			delete_option( self::OPTION_CONNECT );
			delete_option( self::OPTION_BALANCE );
			delete_option( self::OPTION_LEDGER );

			/**
			 * Fires after the connection has been wiped from local storage.
			 *
			 * @since 1.7.0
			 */
			do_action( 'wp_mcp_ai_nv_cloud_disconnected' );
		}

		/**
		 * Get the cached balance (USD) and refresh timestamp.
		 *
		 * @return array { balance: float, refreshed_at: int }
		 */
		public function get_cached_balance() {
			$cached = get_option( self::OPTION_BALANCE, array() );
			if ( ! is_array( $cached ) ) {
				$cached = array();
			}
			return wp_parse_args(
				$cached,
				array(
					'balance'      => 0.0,
					'refreshed_at' => 0,
					'currency'     => 'USD',
				)
			);
		}

		/**
		 * Update the cached balance.
		 *
		 * @param float  $balance USD balance from the SaaS.
		 * @param string $currency ISO code, defaults to USD.
		 */
		public function set_cached_balance( $balance, $currency = 'USD' ) {
			update_option(
				self::OPTION_BALANCE,
				array(
					'balance'      => (float) $balance,
					'refreshed_at' => time(),
					'currency'     => sanitize_text_field( (string) $currency ),
				),
				false
			);
		}

		/**
		 * Compute the markup amount for a given wholesale cost.
		 *
		 * @param float $wholesale_usd Upstream wholesale cost in USD.
		 * @return float Markup amount in USD (rounded to 6 decimals).
		 */
		public function compute_markup( $wholesale_usd ) {
			$wholesale_usd = max( 0.0, (float) $wholesale_usd );
			return round( $wholesale_usd * self::MARKUP_RATE, 6 );
		}

		/**
		 * Compute the Stripe processor pass-through for a top-up amount.
		 *
		 * @param float $amount_usd Top-up amount.
		 * @return float Stripe fee in USD.
		 */
		public function compute_stripe_passthrough( $amount_usd ) {
			$amount_usd = max( 0.0, (float) $amount_usd );
			return round( ( $amount_usd * self::STRIPE_PERCENT ) + self::STRIPE_FIXED_USD, 2 );
		}

		/**
		 * Append an entry to the local ledger (most-recent first, capped to
		 * LEDGER_MAX_ENTRIES).
		 *
		 * @param array $entry Ledger entry. Must include keys:
		 *                     - kind: 'usage' | 'topup' | 'refund' | 'fee'
		 *                     - wholesale_usd: float
		 *                     - service_fee_usd: float
		 *                     - total_usd: float (charged amount, what the customer pays)
		 *                     - model: string (optional)
		 *                     - assistant_id: int (optional)
		 *                     - timestamp: int (defaults to now).
		 */
		public function append_ledger_entry( array $entry ) {
			$entry = wp_parse_args(
				$entry,
				array(
					'kind'            => 'usage',
					'wholesale_usd'   => 0.0,
					'service_fee_usd' => 0.0,
					'total_usd'       => 0.0,
					'model'           => '',
					'assistant_id'    => 0,
					'timestamp'       => time(),
				)
			);

			$entry['kind']            = sanitize_key( (string) $entry['kind'] );
			$entry['wholesale_usd']   = round( (float) $entry['wholesale_usd'], 6 );
			$entry['service_fee_usd'] = round( (float) $entry['service_fee_usd'], 6 );
			$entry['total_usd']       = round( (float) $entry['total_usd'], 6 );
			$entry['model']           = sanitize_text_field( (string) $entry['model'] );
			$entry['assistant_id']    = absint( $entry['assistant_id'] );
			$entry['timestamp']       = absint( $entry['timestamp'] );

			$ledger = get_option( self::OPTION_LEDGER, array() );
			if ( ! is_array( $ledger ) ) {
				$ledger = array();
			}
			array_unshift( $ledger, $entry );
			if ( count( $ledger ) > self::LEDGER_MAX_ENTRIES ) {
				$ledger = array_slice( $ledger, 0, self::LEDGER_MAX_ENTRIES );
			}
			update_option( self::OPTION_LEDGER, $ledger, false );
		}

		/**
		 * Read the local ledger (most-recent first).
		 *
		 * @param int $limit Optional cap.
		 * @return array
		 */
		public function get_ledger( $limit = 50 ) {
			$ledger = get_option( self::OPTION_LEDGER, array() );
			if ( ! is_array( $ledger ) ) {
				return array();
			}
			$limit = max( 1, min( self::LEDGER_MAX_ENTRIES, absint( $limit ) ) );
			return array_slice( $ledger, 0, $limit );
		}

		/**
		 * Retrieve user prefs (auto-topup, etc.).
		 *
		 * @return array
		 */
		public function get_prefs() {
			$prefs = get_option( self::OPTION_PREFS, array() );
			if ( ! is_array( $prefs ) ) {
				$prefs = array();
			}
			return wp_parse_args(
				$prefs,
				array(
					'use_as_default'        => false,
					'auto_topup_enabled'    => false,
					'auto_topup_amount_usd' => self::DEFAULT_MIN_TOPUP_USD,
					'min_topup_usd'         => self::DEFAULT_MIN_TOPUP_USD,
				)
			);
		}

		/**
		 * Persist user prefs.
		 *
		 * @param array $prefs Prefs payload.
		 */
		public function save_prefs( array $prefs ) {
			$current = $this->get_prefs();
			$merged  = array_merge( $current, $prefs );

			$merged['use_as_default']        = (bool) $merged['use_as_default'];
			$merged['auto_topup_enabled']    = (bool) $merged['auto_topup_enabled'];
			$merged['auto_topup_amount_usd'] = max( self::DEFAULT_MIN_TOPUP_USD, (float) $merged['auto_topup_amount_usd'] );
			$merged['min_topup_usd']         = max( self::DEFAULT_MIN_TOPUP_USD, (float) $merged['min_topup_usd'] );

			update_option( self::OPTION_PREFS, $merged, false );
		}

		/**
		 * Convenience: should the router prefer NV oOS Cloud when the per-assistant
		 * provider isn't set? Driven by user prefs + connection state.
		 *
		 * @return bool
		 */
		public function is_default_provider() {
			$prefs = $this->get_prefs();
			return $this->is_connected() && ! empty( $prefs['use_as_default'] );
		}

		/**
		 * Return the encryption key used for at-rest token encryption.
		 *
		 * @return string
		 */
		protected function get_encryption_key() {
			$secret = '';
			if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
				$secret .= (string) AUTH_KEY;
			}
			if ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY ) {
				$secret .= (string) SECURE_AUTH_KEY;
			}
			if ( '' === $secret ) {
				$secret = (string) get_option( 'siteurl', '' );
			}
			return hash( 'sha256', 'wp_mcp_ai_nv_cloud|' . $secret, true );
		}

		/**
		 * Encrypt a value at rest. Falls back to base64 when openssl is missing.
		 *
		 * @param string $value Plaintext.
		 * @return string Cipher payload (base64 with iv prefix).
		 */
		protected function encrypt_value( $value ) {
			$value = (string) $value;
			if ( '' === $value ) {
				return '';
			}
			if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_random_pseudo_bytes' ) ) {
				return 'b64:' . base64_encode( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			}
			$key    = $this->get_encryption_key();
			$iv_len = openssl_cipher_iv_length( 'aes-256-cbc' );
			$iv     = openssl_random_pseudo_bytes( $iv_len );
			$cipher = openssl_encrypt( $value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
			if ( false === $cipher ) {
				return 'b64:' . base64_encode( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			}
			return 'enc:' . base64_encode( $iv . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		/**
		 * Decrypt a value previously encrypted by encrypt_value().
		 *
		 * @param string $value Cipher payload.
		 * @return string Plaintext.
		 */
		protected function decrypt_value( $value ) {
			$value = (string) $value;
			if ( '' === $value ) {
				return '';
			}
			if ( 0 === strpos( $value, 'b64:' ) ) {
				$decoded = base64_decode( substr( $value, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				return false === $decoded ? '' : (string) $decoded;
			}
			if ( 0 !== strpos( $value, 'enc:' ) ) {
				// Legacy / unencrypted — return as-is for forward compatibility.
				return $value;
			}
			if ( ! function_exists( 'openssl_decrypt' ) ) {
				return '';
			}
			$payload = base64_decode( substr( $value, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $payload ) {
				return '';
			}
			$iv_len = openssl_cipher_iv_length( 'aes-256-cbc' );
			if ( strlen( $payload ) <= $iv_len ) {
				return '';
			}
			$iv     = substr( $payload, 0, $iv_len );
			$cipher = substr( $payload, $iv_len );
			$key    = $this->get_encryption_key();
			$plain  = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
			return false === $plain ? '' : (string) $plain;
		}
	}
}
