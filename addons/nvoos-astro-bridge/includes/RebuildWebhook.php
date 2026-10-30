<?php
/**
 * Rebuild webhook — notifies the storefront deploy pipeline when catalog
 * content changes (Proposal 092 Phase 2.3, the R9 revalidation loop).
 *
 * Pattern: the industry-standard CMS→CI webhook (Contentful "build on
 * publish" / repository_dispatch). The operator configures a webhook URL
 * (typically the GitHub dispatches endpoint) plus an optional bearer token;
 * every catalog write (products, promotions, promocodes, terms, globals)
 * fires one debounced POST on shutdown so saves are never held up.
 *
 * Disabled by default — an empty URL means the hooks no-op.
 *
 * @package NvoosAstroBridge
 */

namespace NvoosAstroBridge;

/**
 * Content-changed → rebuild webhook.
 */
final class RebuildWebhook {

	/**
	 * Webhook URL option name (empty = disabled).
	 *
	 * @var string
	 */
	public const OPTION_URL = 'funiq_bridge_rebuild_webhook_url';

	/**
	 * Optional bearer token option name.
	 *
	 * @var string
	 */
	public const OPTION_TOKEN = 'funiq_bridge_rebuild_webhook_token';

	/**
	 * Repository dispatch event type.
	 *
	 * @var string
	 */
	public const EVENT_TYPE = 'nvoos-storefront-content-changed';

	/**
	 * Debounce window (seconds) between fired notifications.
	 *
	 * @var int
	 */
	public const DEBOUNCE_SECONDS = 600;

	/**
	 * Debounce transient key.
	 *
	 * @var string
	 */
	private const TRANSIENT_SENT = 'funiq_bridge_rebuild_webhook_sent';

	/**
	 * In-request dedupe flag (multiple saves in one request = one send).
	 *
	 * @var bool
	 */
	private static $pending = false;

	/**
	 * Kind of the pending notification (content|term|global).
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
		add_action( 'save_post_funiq_product', array( self::class, 'on_content_changed' ) );
		add_action( 'save_post_funiq_promotion', array( self::class, 'on_content_changed' ) );
		add_action( 'save_post_funiq_promocode', array( self::class, 'on_content_changed' ) );
		add_action( 'created_term', array( self::class, 'on_term_changed' ) );
		add_action( 'edited_term', array( self::class, 'on_term_changed' ) );
		add_action( 'delete_term', array( self::class, 'on_term_changed' ) );
		add_action( 'updated_option', array( self::class, 'on_option_changed' ) );
	}

	/**
	 * Catalog CPT save hook.
	 *
	 * @return void
	 */
	public static function on_content_changed(): void {
		self::schedule( 'content' );
	}

	/**
	 * Term hook — only bridge taxonomies matter.
	 *
	 * @param int $term_id Term ID.
	 * @return void
	 */
	public static function on_term_changed( $term_id ): void {
		$term = get_term( (int) $term_id );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}
		$taxonomies = array(
			Schema::TAX_CATEGORY,
			Schema::TAX_BRAND,
			Schema::TAX_COLOR,
			Schema::TAX_STATUS,
		);
		if ( in_array( $term->taxonomy, $taxonomies, true ) ) {
			self::schedule( 'term' );
		}
	}

	/**
	 * Option hook — banner/carousel globals only.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	public static function on_option_changed( $option ): void {
		if ( in_array( $option, array( Schema::OPTION_BANNER, Schema::OPTION_CAROUSEL ), true ) ) {
			self::schedule( 'global' );
		}
	}

	/**
	 * Debounce + defer the notification to shutdown.
	 *
	 * @param string $type Change kind (content|term|global).
	 * @return void
	 */
	private static function schedule( string $type ): void {
		// In-request dedupe first — multiple hooks firing for one save
		// (e.g. save_post + save_post_{type}) produce a single notification.
		if ( self::$pending ) {
			return;
		}

		$url = get_option( self::OPTION_URL );
		if ( empty( $url ) ) {
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

		$url = get_option( self::OPTION_URL );
		if ( empty( $url ) ) {
			return;
		}

		self::send( $url, self::$pending_type );
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
	 * POST the content-changed notification (bounded timeout, never logged).
	 *
	 * @param string $url  Webhook URL (GitHub dispatches endpoint).
	 * @param string $type Change kind.
	 * @return void
	 */
	public static function send( string $url, string $type ): void {
		$args = array(
			'timeout' => 5,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/vnd.github+json',
				'User-Agent'   => 'nvoos-bridge-rebuild-webhook',
			),
			'body'    => wp_json_encode(
				array(
					'event_type'     => self::EVENT_TYPE,
					'client_payload' => array(
						'type'    => $type,
						'sent_at' => gmdate( 'c' ),
					),
				)
			),
		);

		$token = get_option( self::OPTION_TOKEN );
		if ( ! empty( $token ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		wp_remote_post( $url, $args );
	}

	/** Private — static utility only. */
	private function __construct() {}
}
