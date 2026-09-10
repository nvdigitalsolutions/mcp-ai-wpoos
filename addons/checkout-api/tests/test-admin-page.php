<?php
/**
 * Tests for the Checkout API admin page.
 *
 * @package NV_oOS_Checkout_API
 * @since   0.1.0
 */

/**
 * Admin page tests (rendering + form structure).
 *
 * The regression core here: the settings form and the admin-post action
 * forms must never nest. Browsers silently drop an inner <form> tag, merging
 * its hidden `action` input into the outer form — which made every submit
 * land on a blank options.php instead of saving/redirecting.
 */
class Test_Checkout_Api_Admin_Page extends WP_UnitTestCase {

	/**
	 * Set up an administrator and the licenses table.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		NVOOS_Checkout_API_License_Store::install_table();

		$admin = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin->ID );
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		delete_option( NVOOS_Checkout_API_Settings::OPTION );
		parent::tearDown();
	}

	/**
	 * Render the admin page into a string.
	 *
	 * @return string
	 */
	private function render_page(): string {
		ob_start();
		NVOOS_Checkout_API_Admin_Page::render();
		return (string) ob_get_clean();
	}

	/**
	 * The settings form contains no nested forms.
	 *
	 * @return void
	 */
	public function test_settings_form_has_no_nested_forms(): void {
		$html = $this->render_page();

		$open_tag = '<form method="post" action="options.php">';
		$open     = strpos( $html, $open_tag );
		$this->assertNotFalse( $open, 'The settings form must render.' );

		// Skip past the opening tag — only the form body may not nest.
		$start = $open + strlen( $open_tag );
		$end   = strpos( $html, '</form>', $start );
		$this->assertNotFalse( $end, 'The settings form must close.' );

		$settings_form = substr( $html, $start, $end - $start );

		$this->assertStringNotContainsString( '<form', $settings_form );
		$this->assertStringNotContainsString( 'admin-post.php', $settings_form );
		$this->assertStringNotContainsString( 'nvoos_checkout_create_product', $settings_form );
		$this->assertStringNotContainsString( 'nvoos_checkout_test_connection', $settings_form );
	}

	/**
	 * The action forms render standalone, outside the settings form.
	 *
	 * @return void
	 */
	public function test_action_forms_are_standalone(): void {
		$html = $this->render_page();

		$this->assertStringContainsString( 'name="action" value="nvoos_checkout_create_product"', $html );
		$this->assertStringContainsString( 'name="action" value="nvoos_checkout_test_connection"', $html );

		// Both must appear after the settings form has closed.
		$settings_end = strpos( $html, '</form>' );
		$this->assertNotFalse( $settings_end );
		$this->assertGreaterThan(
			$settings_end,
			strpos( $html, 'nvoos_checkout_create_product', $settings_end )
		);
		$this->assertGreaterThan(
			$settings_end,
			strpos( $html, 'nvoos_checkout_test_connection', $settings_end )
		);
	}

	/**
	 * Product/Price IDs round-trip through the settings form as hidden fields.
	 *
	 * A plain settings save calls update_option(), which replaces the whole
	 * option array — without these hidden inputs the IDs recorded by the
	 * create-product action would be wiped on every save.
	 *
	 * @return void
	 */
	public function test_product_and_price_ids_are_preserved_on_save(): void {
		update_option(
			NVOOS_Checkout_API_Settings::OPTION,
			array(
				'product_id' => 'prod_abc123',
				'price_id'   => 'price_def456',
			)
		);

		$html = $this->render_page();

		$this->assertStringContainsString( 'name="nvoos_checkout_settings[product_id]" value="prod_abc123"', $html );
		$this->assertStringContainsString( 'name="nvoos_checkout_settings[price_id]" value="price_def456"', $html );
	}

	/**
	 * Sanitize passes Stripe product/price IDs through.
	 *
	 * @return void
	 */
	public function test_sanitize_accepts_stripe_ids(): void {
		$result = NVOOS_Checkout_API_Settings::sanitize(
			array(
				'product_id' => 'prod_abc123',
				'price_id'   => 'price_def456',
			)
		);

		$this->assertSame( 'prod_abc123', $result['product_id'] );
		$this->assertSame( 'price_def456', $result['price_id'] );
	}

	/**
	 * Sanitize rejects malformed product/price IDs.
	 *
	 * @return void
	 */
	public function test_sanitize_rejects_malformed_ids(): void {
		$result = NVOOS_Checkout_API_Settings::sanitize(
			array(
				'product_id' => 'prod_<script>alert(1)</script>',
				'price_id'   => 'price with spaces',
			)
		);

		$this->assertSame( '', $result['product_id'] );
		$this->assertSame( '', $result['price_id'] );
	}
}
