<?php
/**
 * Rebuild webhook — fires a signed GitHub repository_dispatch when content
 * changes (Proposal 096 Phase 2).
 *
 * Pattern: the industry-standard CMS→CI webhook (Contentful "build on
 * publish" / repository_dispatch). Every content change schedules one
 * debounced POST on shutdown so saves are never held up. The payload is
 * HMAC-signed with the shared secret (header `X-Forge-Signature:
 * sha256=<hex>`) so the CI pipeline can fail closed on spoofed triggers.
 *
 * Disabled by default — an unconfigured plugin means the hooks no-op.
 *
 * @package NvoosForgeBridge
 */

namespace NvoosForgeBridge;

/**
 * Content-changed → rebuild webhook.
 */
final class Rebuild_Webhook {

	/**
	 * Debounce window (seconds) between fired notifications.
	 */
	public const DEBOUNCE_SECONDS = 600;

	/**
	 * Debounce transient key.
	 */
	private const TRANSIENT_SENT = 'nvoos_forge_bridge_webhook_sent';

	/**
	 * In-request dedupe flag (multiple saves in one request = one send).
	 *
	 * @var bool
	 */
	private static $pending = false;

	/**
	 * Kind of the pending notification (content|term|option).
	 *
	 * @var string
	 */
	private static $pending_type = 'content';

	/**
	 * Register the change hooks. Called once from Plugin::init().
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'save_post', array( self::class, 'on_content_changed' ) );
		add_action( 'created_term', array( self::class, 'on_content_changed' ) );
		add_action( 'edited_term', array( self::class, 'on_content_changed' ) );
		add_action( 'delete_term', array( self::class, 'on_content_changed' ) );
		add_action( 'updated_option', array( self::class, 'on_option_changed' ) );
	}

	/**
	 * Post/term save hook.
	 *
	 * @return void
	 */
	public static function on_content_changed(): void {
		self::schedule( 'content' );
	}

	/**
	 * Option hook — rebuild when the front page/permalink surface moves.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	public static function on_option_changed( $option ): void {
		$watched = array( 'page_on_front', 'show_on_front', 'blogname', 'blogdescription' );
		if ( in_array( $option, $watched, true ) ) {
			self::schedule( 'option' );
		}
	}

	/**
	 * Debounce + defer the notification to shutdown.
	 *
	 * @param string $type Change kind (content|option).
	 * @return void
	 */
	public static function schedule( string $type = 'content' ): void {
		// In-request dedupe first — multiple hooks firing for one save
		// (e.g. save_post + save_post_{type}) produce one notification.
		if ( self::$pending ) {
			return;
		}
		if ( ! Settings::rebuild_configured() ) {
			return;
		}
		if ( get_transient( self::TRANSIENT_SENT ) ) {
			return;
		}

		self::$pending      = true;
		self::$pending_type = $type;
		set_transient( self::TRANSIENT_SENT, 1, self::DEBOUNCE_SECONDS );

		if ( ! has_action( 'shutdown', array( self::class, 'send_pending' ) ) ) {
			add_action( 'shutdown', array( self::class, 'send_pending' ) );
		}
	}

	/**
	 * Shutdown callback — sends the pending notification once.
	 *
	 * @return void
	 */
	public static function send_pending(): void {
		if ( ! self::$pending ) {
			return;
		}
		self::$pending = false;

		if ( ! Settings::rebuild_configured() ) {
			return;
		}

		self::send( self::$pending_type );
	}

	/**
	 * Test seam — clear the in-request pending flag between test cases.
	 *
	 * @return void
	 */
	public static function reset_pending(): void {
		self::$pending = false;
	}

	/**
	 * POST the signed content-changed notification (bounded timeout, never
	 * logs the token or secret).
	 *
	 * The dispatch carries two proofs: the `X-Forge-Signature` header over the
	 * exact body (defense in depth for consumers that see the raw request),
	 * and a self-verifying `signature` inside `client_payload` — HMAC-SHA256
	 * over "<time>.<nonce>" — because GitHub's dispatch delivery re-serializes
	 * the body and the header does not survive into the workflow event. The
	 * generated pipeline (Forge Phase 3.3 `verify-dispatch.mjs`) re-verifies
	 * that payload proof and fails closed.
	 *
	 * @param string $type Change kind.
	 * @return array|\WP_Error wp_remote_post result.
	 */
	public static function send( string $type ): array {
		$url       = Settings::rebuild_url();
		$time      = time();
		$nonce     = bin2hex( random_bytes( 12 ) );
		$signature = self::payload_signature( (string) $time, $nonce );
		$body      = wp_json_encode(
			array(
				'event_type'     => Settings::rebuild_event(),
				'client_payload' => array(
					'site'      => wp_parse_url( home_url(), PHP_URL_HOST ),
					'type'      => $type,
					'time'      => $time,
					'nonce'     => $nonce,
					'signature' => $signature,
				),
			)
		);
		$args      = array(
			'method'  => 'POST',
			'timeout' => 10,
			'headers' => array(
				'Accept'            => 'application/vnd.github+json',
				'Authorization'     => 'Bearer ' . Settings::rebuild_token(),
				'Content-Type'      => 'application/json',
				'User-Agent'        => 'nvoos-forge-bridge/' . NVOOS_FORGE_BRIDGE_VERSION,
				'X-Forge-Signature' => self::sign( $body ),
			),
			'body'    => $body,
		);
		$args      = apply_filters( 'nvoos_forge_bridge_http_args', $args, $type );
		return wp_remote_post( $url, $args );
	}

	/**
	 * The payload-level proof: HMAC-SHA256 over "<time>.<nonce>", hex.
	 *
	 * Survives GitHub's dispatch re-serialization (the header signature does
	 * not) — the generated pipeline re-verifies this value. Empty string when
	 * no secret is set.
	 *
	 * @param string $time  Unix timestamp string.
	 * @param string $nonce Random hex nonce.
	 * @return string
	 */
	public static function payload_signature( string $time, string $nonce ): string {
		$secret = Settings::rebuild_secret();
		if ( '' === $secret ) {
			return '';
		}
		return hash_hmac( 'sha256', $time . '.' . $nonce, $secret );
	}

	/**
	 * HMAC-SHA256 signature over a payload, hex-encoded.
	 *
	 * @param string $payload Raw payload.
	 * @return string `sha256=<hex>` — empty string when no secret is set.
	 */
	public static function sign( string $payload ): string {
		$secret = Settings::rebuild_secret();
		if ( '' === $secret ) {
			return '';
		}
		return 'sha256=' . hash_hmac( 'sha256', $payload, $secret );
	}

	/**
	 * Constant-time signature verification (fail closed on malformed input).
	 *
	 * @param string $payload   Raw payload.
	 * @param string $signature The `sha256=<hex>` header value.
	 * @return bool
	 */
	public static function verify( string $payload, string $signature ): bool {
		$expected = self::sign( $payload );
		if ( '' === $expected || '' === $signature || ! is_string( $signature ) ) {
			return false;
		}
		return hash_equals( $expected, $signature );
	}
}
