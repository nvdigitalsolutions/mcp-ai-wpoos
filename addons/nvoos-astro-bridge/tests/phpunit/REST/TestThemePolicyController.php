<?php
/**
 * PHPUnit tests for the theme policy controller (Proposal 105 §1):
 * default doc shape, public GET, PATCH happy/invalid paths, permission
 * gate, short cache headers + ETag rotation, the runtime filter, and the
 * rebuild-webhook $pending seam.
 *
 * @package NvoosAstroBridge\Tests
 */

use NvoosAstroBridge\RebuildWebhook;
use NvoosAstroBridge\Schema;

/**
 * Theme policy REST tests.
 *
 * @covers \NvoosAstroBridge\REST\ThemePolicyController
 * @covers \NvoosAstroBridge\REST\BaseController
 * @covers \NvoosAstroBridge\RebuildWebhook
 * @group nvoos-astro-bridge
 */
class TestThemePolicyController extends WP_Test_REST_TestCase {

	/**
	 * Admin user ID used by authenticated-path assertions.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up test state: REST routes, admin, clean policy option.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		do_action( 'rest_api_init' );

		delete_option( Schema::OPTION_THEME_POLICY );

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( 0 );
	}

	/**
	 * Reset the webhook seam + option between tests.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		RebuildWebhook::reset_pending();
		remove_action( 'shutdown', array( RebuildWebhook::class, 'send_pending' ) );
		delete_option( RebuildWebhook::OPTION_URL );
		delete_transient( 'funiq_bridge_rebuild_webhook_sent' );
		delete_option( Schema::OPTION_THEME_POLICY );
		remove_all_filters( 'funiq_bridge_theme_policy' );
		parent::tearDown();
	}

	/**
	 * Build a JSON PATCH request for the theme policy route.
	 *
	 * @param array<string, mixed> $body Request body.
	 * @return WP_REST_Request
	 */
	private function patch_request( array $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'PATCH', '/nvoos-commerce/v1/globals/theme' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return $request;
	}

	/**
	 * GET /globals/theme is registered on both namespaces and is public.
	 */
	public function test_route_registered_and_public(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/funiq/v1/globals/theme', $routes );
		$this->assertArrayHasKey( '/nvoos-commerce/v1/globals/theme', $routes );

		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/globals/theme' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * The default doc carries the exact contract shape.
	 */
	public function test_default_doc_shape(): void {
		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/globals/theme' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 'auto', $data['policy'] );
		$this->assertSame( 'auto', $data['contrast'] );
		$this->assertSame( 'dark', $data['defaultTheme'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $data['updatedAt'] );
		$this->assertSame( array(), $data['$extensions'] );
	}

	/**
	 * The runtime `funiq_bridge_theme_policy` filter overrides the stored
	 * doc (the product-source pattern).
	 */
	public function test_runtime_filter_overrides_stored_doc(): void {
		add_filter(
			'funiq_bridge_theme_policy',
			static function ( array $doc ): array {
				$doc['policy'] = 'dark';
				return $doc;
			}
		);

		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/globals/theme' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 'dark', $response->get_data()['policy'] );
	}

	/**
	 * PATCH happy paths per field.
	 */
	public function test_patch_policy(): void {
		wp_set_current_user( $this->admin_id );

		$response = rest_get_server()->dispatch( $this->patch_request( array( 'policy' => 'light' ) ) );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertSame( 'light', $response->get_data()['policy'] );
		$this->assertSame( 'auto', $response->get_data()['contrast'] );

		wp_set_current_user( 0 );
	}

	/**
	 * PATCH contrast + defaultTheme.
	 */
	public function test_patch_contrast_and_default_theme(): void {
		wp_set_current_user( $this->admin_id );

		$response = rest_get_server()->dispatch(
			$this->patch_request(
				array(
					'contrast'     => 'high',
					'defaultTheme' => 'light',
				)
			)
		);
		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'high', $data['contrast'] );
		$this->assertSame( 'light', $data['defaultTheme'] );
		$this->assertSame( 'auto', $data['policy'] );

		wp_set_current_user( 0 );
	}

	/**
	 * PATCH brand:<slug> policies.
	 */
	public function test_patch_brand_policy(): void {
		wp_set_current_user( $this->admin_id );

		$response = rest_get_server()->dispatch(
			$this->patch_request( array( 'policy' => 'brand:aurora-home' ) )
		);
		$this->assertEquals( 200, $response->get_status() );
		$this->assertSame( 'brand:aurora-home', $response->get_data()['policy'] );

		wp_set_current_user( 0 );
	}

	/**
	 * Invalid policies → typed 400.
	 *
	 * @dataProvider provide_invalid_policies
	 *
	 * @param mixed $policy Invalid policy value.
	 * @return void
	 */
	public function test_patch_invalid_policy( $policy ): void {
		wp_set_current_user( $this->admin_id );

		$response = rest_get_server()->dispatch( $this->patch_request( array( 'policy' => $policy ) ) );
		$this->assertEquals( 400, $response->get_status() );
		$this->assertSame( 'astro_theme_policy_invalid_policy', $response->as_error()->get_error_code() );

		wp_set_current_user( 0 );
	}

	/**
	 * Invalid policy values.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function provide_invalid_policies(): array {
		return array(
			'unknown enum'   => array( 'sepia' ),
			'bad brand slug' => array( 'brand:Bad Slug!' ),
			'empty brand'    => array( 'brand:' ),
			'non string'     => array( 42 ),
		);
	}

	/**
	 * Invalid contrast → typed 400.
	 */
	public function test_patch_invalid_contrast(): void {
		wp_set_current_user( $this->admin_id );

		$response = rest_get_server()->dispatch( $this->patch_request( array( 'contrast' => 'ultra' ) ) );
		$this->assertEquals( 400, $response->get_status() );
		$this->assertSame( 'astro_theme_policy_invalid_contrast', $response->as_error()->get_error_code() );

		wp_set_current_user( 0 );
	}

	/**
	 * Invalid defaultTheme → typed 400.
	 */
	public function test_patch_invalid_default_theme(): void {
		wp_set_current_user( $this->admin_id );

		$response = rest_get_server()->dispatch( $this->patch_request( array( 'defaultTheme' => 'midnight' ) ) );
		$this->assertEquals( 400, $response->get_status() );
		$this->assertSame( 'astro_theme_policy_invalid_default_theme', $response->as_error()->get_error_code() );

		wp_set_current_user( 0 );
	}

	/**
	 * Unknown fields → typed 400.
	 */
	public function test_patch_unknown_field_rejected(): void {
		wp_set_current_user( $this->admin_id );

		$response = rest_get_server()->dispatch( $this->patch_request( array( 'saturation' => 'high' ) ) );
		$this->assertEquals( 400, $response->get_status() );
		$this->assertSame( 'astro_theme_policy_invalid_field', $response->as_error()->get_error_code() );

		wp_set_current_user( 0 );
	}

	/**
	 * PATCH without the manage capability → 403.
	 */
	public function test_patch_requires_capability(): void {
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$response = rest_get_server()->dispatch( $this->patch_request( array( 'policy' => 'dark' ) ) );
		$this->assertEquals( 403, $response->get_status() );

		wp_set_current_user( 0 );
	}

	/**
	 * The short cache-header filter is registered and marks the route with
	 * s-maxage=60 + stale-while-revalidate=15 + an ETag.
	 */
	public function test_short_cache_headers(): void {
		$this->assertNotFalse(
			has_filter(
				'rest_post_dispatch',
				array( \NvoosAstroBridge\REST\BaseController::class, 'set_short_cache_headers_on_public_gets' )
			)
		);

		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/globals/theme' );
		$response = \NvoosAstroBridge\REST\BaseController::set_short_cache_headers_on_public_gets(
			rest_ensure_response( Schema::theme_policy_defaults() ),
			rest_get_server(),
			$request
		);

		$headers = $response->get_headers();
		$this->assertStringContainsString( 'public', $headers['Cache-Control'] );
		$this->assertStringContainsString( 's-maxage=60', $headers['Cache-Control'] );
		$this->assertStringContainsString( 'stale-while-revalidate=15', $headers['Cache-Control'] );
		$this->assertArrayHasKey( 'ETag', $headers );
	}

	/**
	 * Non-matching routes are left untouched by the short-cache filter.
	 */
	public function test_short_cache_headers_ignore_other_routes(): void {
		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/products' );
		$response = \NvoosAstroBridge\REST\BaseController::set_short_cache_headers_on_public_gets(
			rest_ensure_response( array( 'docs' => array() ) ),
			rest_get_server(),
			$request
		);

		$this->assertArrayNotHasKey( 'Cache-Control', $response->get_headers() );
	}

	/**
	 * The ETag rotates when the doc changes.
	 */
	public function test_etag_changes_after_update(): void {
		$etag = static function (): string {
			$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/globals/theme' );
			$response = \NvoosAstroBridge\REST\BaseController::set_short_cache_headers_on_public_gets(
				rest_get_server()->dispatch( $request ),
				rest_get_server(),
				$request
			);
			return $response->get_headers()['ETag'];
		};

		$before = $etag();

		wp_set_current_user( $this->admin_id );
		rest_get_server()->dispatch( $this->patch_request( array( 'policy' => 'light' ) ) );
		wp_set_current_user( 0 );

		$after = $etag();

		$this->assertNotSame( $before, $after );
	}

	/**
	 * A successful PATCH sets the rebuild-webhook $pending seam.
	 */
	public function test_patch_schedules_rebuild_webhook(): void {
		update_option( RebuildWebhook::OPTION_URL, 'https://hooks.example.com/rebuild' );
		delete_transient( 'funiq_bridge_rebuild_webhook_sent' );

		wp_set_current_user( $this->admin_id );
		$response = rest_get_server()->dispatch( $this->patch_request( array( 'policy' => 'dark' ) ) );
		$this->assertEquals( 200, $response->get_status() );

		$this->assertTrue( RebuildWebhook::is_pending() );

		wp_set_current_user( 0 );
	}
}
