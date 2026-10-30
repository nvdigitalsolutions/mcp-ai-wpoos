<?php
/**
 * PHPUnit tests for the Proposal 092 Phase 2.3 rebuild webhook.
 *
 * @package NvoosAstroBridge\Tests
 */

use NvoosAstroBridge\RebuildWebhook;
use NvoosAstroBridge\Schema;

/**
 * Rebuild webhook tests.
 *
 * @covers \NvoosAstroBridge\RebuildWebhook
 * @group nvoos-astro-bridge
 */
class TestRebuildWebhook extends WP_UnitTestCase {

	/**
	 * Fixture product post ID.
	 *
	 * @var int
	 */
	private $product_id;

	/**
	 * Set up test state.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		delete_option( RebuildWebhook::OPTION_URL );
		delete_option( RebuildWebhook::OPTION_TOKEN );
		delete_transient( 'funiq_bridge_rebuild_webhook_sent' );
		RebuildWebhook::reset_pending();

		$plugin = \NvoosAstroBridge\Plugin::instance();
		if ( ! post_type_exists( Schema::CPT_PRODUCT ) ) {
			$plugin->register_post_types();
			$plugin->register_taxonomies();
		}

		$this->product_id = $this->factory->post->create(
			array(
				'post_type'   => Schema::CPT_PRODUCT,
				'post_title'  => 'Webhook Product',
				'post_status' => 'publish',
			)
		);

		RebuildWebhook::init();
	}

	/**
	 * No URL configured — the hooks no-op and nothing is sent.
	 */
	public function test_noop_without_url(): void {
		$requests = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$requests ) {
				$requests++;
				return array( 'response' => array( 'code' => 200 ), 'body' => '' );
			},
			10,
			3
		);

		do_action( 'save_post_funiq_product', $this->product_id );
		RebuildWebhook::send_pending();

		$this->assertSame( 0, $requests );
	}

	/**
	 * A configured URL fires exactly one debounced request on shutdown.
	 */
	public function test_fires_once_with_url(): void {
		update_option( RebuildWebhook::OPTION_URL, 'https://hooks.example.com/rebuild' );

		$captured = array();
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$captured ) {
				if ( 'https://hooks.example.com/rebuild' === $url ) {
					$captured[] = $args;
				}
				return array(
					'response' => array( 'code' => 204 ),
					'body'     => '',
				);
			},
			10,
			3
		);

		do_action( 'save_post_funiq_product', $this->product_id );
		do_action( 'save_post_funiq_product', $this->product_id );
		RebuildWebhook::send_pending();

		$this->assertCount( 1, $captured );

		$body = json_decode( $captured[0]['body'], true );
		$this->assertSame( 'nvoos-storefront-content-changed', $body['event_type'] );
		$this->assertSame( 'content', $body['client_payload']['type'] );

		// Debounce: a second save within the window does not re-fire.
		do_action( 'save_post_funiq_product', $this->product_id );
		RebuildWebhook::send_pending();
		$this->assertCount( 1, $captured );
	}

	/**
	 * The bearer token is attached when configured.
	 */
	public function test_sends_bearer_token(): void {
		update_option( RebuildWebhook::OPTION_URL, 'https://hooks.example.com/rebuild' );
		update_option( RebuildWebhook::OPTION_TOKEN, 'secret-token' );

		$headers = null;
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$headers ) {
				if ( 'https://hooks.example.com/rebuild' === $url ) {
					$headers = $args['headers'];
				}
				return array(
					'response' => array( 'code' => 204 ),
					'body'     => '',
				);
			},
			10,
			3
		);

		do_action( 'save_post_funiq_product', $this->product_id );
		RebuildWebhook::send_pending();

		$this->assertSame( 'Bearer secret-token', $headers['Authorization'] );
	}

	/**
	 * Term + global changes also schedule notifications.
	 */
	public function test_term_and_global_changes_fire(): void {
		update_option( RebuildWebhook::OPTION_URL, 'https://hooks.example.com/rebuild' );

		$payloads = array();
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$payloads ) {
				if ( 'https://hooks.example.com/rebuild' === $url ) {
					$body       = json_decode( $args['body'], true );
					$payloads[] = $body['client_payload']['type'];
				}
				return array(
					'response' => array( 'code' => 204 ),
					'body'     => '',
				);
			},
			10,
			3
		);

		$brand_id = $this->factory->term->create(
			array(
				'taxonomy' => Schema::TAX_BRAND,
				'name'     => 'NV Home',
			)
		);
		$brand = get_term( $brand_id );
		do_action( 'edited_term', $brand_id, $brand->term_taxonomy_id, Schema::TAX_BRAND );
		do_action( 'updated_option', Schema::OPTION_BANNER, array(), array( 'image' => 1 ) );
		RebuildWebhook::send_pending();

		// Debounced to one payload — the first change in the window wins.
		$this->assertCount( 1, $payloads );
		$this->assertSame( 'term', $payloads[0] );
	}
}
