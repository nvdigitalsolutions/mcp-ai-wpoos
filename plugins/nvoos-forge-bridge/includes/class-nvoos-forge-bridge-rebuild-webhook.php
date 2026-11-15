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
	 * Cache tags accumulated for the pending notification (Proposal 135).
	 * Empty means "no targeted invalidation" — the dispatch rebuild is the
	 * backstop.
	 *
	 * @var array<int, string>
	 */
	private static $pending_tags = array();

	/**
	 * Register the change hooks. Called once from Plugin::init().
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'save_post', array( self::class, 'on_post_changed' ), 10, 1 );
		add_action( 'delete_post', array( self::class, 'on_post_changed' ), 10, 1 );
		add_action( 'trashed_post', array( self::class, 'on_post_changed' ), 10, 1 );
		add_action( 'created_term', array( self::class, 'on_content_changed' ) );
		add_action( 'edited_term', array( self::class, 'on_content_changed' ) );
		add_action( 'delete_term', array( self::class, 'on_content_changed' ) );
		add_action( 'updated_option', array( self::class, 'on_option_changed' ) );
	}

	/**
	 * Post save/delete/trash hook — skips revision, autosave, and auto-draft
	 * noise so editing never wastes a dispatch on content that does not ship.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function on_post_changed( $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'auto-draft' === get_post_status( $post_id ) ) {
			return;
		}
		self::note_tags( (string) get_post_type( $post_id ), (int) $post_id );
		self::schedule( 'content' );
	}

	/**
	 * Term save hook.
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
		if ( ! Settings::rebuild_configured() && ! Settings::revalidate_configured() ) {
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

		// Two independent consumers: the GitHub dispatch (full rebuild) and
		// the managed frontend's tag purge (seconds). One failing must never
		// suppress the other (Proposal 135).
		if ( Settings::rebuild_configured() ) {
			self::send( self::$pending_type );
		}
		self::send_revalidate();
	}

	/**
	 * Note the cache tags a change affects (Proposal 135).
	 *
	 * Only post types the frontend actually tags are recorded; an unknown type
	 * records nothing and relies on the rebuild backstop.
	 *
	 * @param string $post_type Post type.
	 * @param int    $post_id   Post ID.
	 * @return void
	 */
	private static function note_tags( string $post_type, int $post_id ): void {
		$map = array(
			'funiq_product'     => 'products',
			'product'           => 'products',
			'product_variation' => 'products',
			'post'              => 'posts',
			'page'              => 'pages',
		);

		if ( ! isset( $map[ $post_type ] ) ) {
			return;
		}

		$collection           = $map[ $post_type ];
		self::$pending_tags[] = $collection;
		self::$pending_tags[] = $collection . ':' . $post_id;
	}

	/**
	 * POST the accumulated tags to the managed frontend's /api/revalidate.
	 *
	 * Fail-soft: the result is returned (never thrown) and the caller ignores
	 * it — a revalidation outage must not break the editor's request. The
	 * token is never logged.
	 *
	 * @return array|\WP_Error|null Null when not configured or nothing to tag.
	 */
	public static function send_revalidate() {
		$tags               = array_values( array_unique( array_filter( self::$pending_tags ) ) );
		self::$pending_tags = array();

		if ( ! Settings::revalidate_configured() || empty( $tags ) ) {
			return null;
		}

		$args = array(
			'timeout' => 5,
			'headers' => array(
				'Authorization' => 'Bearer ' . Settings::revalidate_token(),
				'Content-Type'  => 'application/json',
				'User-Agent'    => 'nvoos-forge-bridge/' . NVOOS_FORGE_BRIDGE_VERSION,
			),
			'body'    => wp_json_encode( array( 'tags' => $tags ) ),
		);

		/**
		 * Filter the revalidate request args.
		 *
		 * @param array<string, mixed> $args Request args.
		 * @param array<int, string>   $tags Cache tags.
		 */
		$args = apply_filters( 'nvoos_forge_bridge_revalidate_args', $args, $tags );

		return wp_remote_post( Settings::revalidate_url(), $args );
	}

	/**
	 * Test seam — clear the in-request pending flag between test cases.
	 *
	 * @return void
	 */
	public static function reset_pending(): void {
		self::$pending      = false;
		self::$pending_tags = array();
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
	 * @return array|\WP_Error wp_remote_post result — `WP_Error` when the
	 *                         request fails. No declared return type: the
	 *                         plugin supports PHP 7.4, which has no union
	 *                         types (declaring `array` made a failed request
	 *                         raise a TypeError at shutdown).
	 */
	public static function send( string $type ) {
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
