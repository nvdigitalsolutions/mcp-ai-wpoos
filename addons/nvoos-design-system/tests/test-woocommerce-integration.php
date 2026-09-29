<?php
/**
 * Test: WooCommerce Email Rebrand Integration.
 *
 * Uses lightweight WooCommerce stubs so the integration's hook wiring can be
 * exercised without a full WooCommerce install.
 *
 * @package NV_oOS_Design_System
 */

// Stubs — only defined when WooCommerce is not present in the environment.
if ( ! class_exists( 'WooCommerce' ) ) {
	/**
	 * WooCommerce facade stub.
	 */
	class WooCommerce {
		/**
		 * Mailer accessor.
		 *
		 * @return WC_Emails
		 */
		public function mailer() {
			return $GLOBALS['nds_test_mailer'];
		}
	}
}

if ( ! class_exists( 'WC_Email' ) ) {
	/**
	 * WC_Email stub.
	 */
	class WC_Email {}
}

if ( ! class_exists( 'WC_Emails' ) ) {
	/**
	 * WC_Emails stub with the default header/footer handlers.
	 */
	class WC_Emails {
		/**
		 * Default header handler.
		 *
		 * @param string   $heading Heading.
		 * @param WC_Email $email   Email object.
		 * @return void
		 */
		public function email_header( $heading, $email ) {
			echo 'DEFAULT-HEADER';
		}

		/**
		 * Default footer handler.
		 *
		 * @param WC_Email $email Email object.
		 * @return void
		 */
		public function email_footer( $email ) {
			echo 'DEFAULT-FOOTER';
		}
	}
}

if ( ! function_exists( 'WC' ) ) {
	/**
	 * WooCommerce facade accessor.
	 *
	 * @return WooCommerce
	 */
	function WC() {
		return $GLOBALS['nds_test_wc'];
	}
}

/**
 * Tests for the WooCommerce rebrand integration.
 *
 * @covers NV_oOS_Design_System_Integration_WooCommerce
 */
class Test_WooCommerce_Integration extends WP_UnitTestCase {

	/**
	 * Set up stubs and enable rebranding.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['nds_test_mailer'] = new WC_Emails();
		$GLOBALS['nds_test_wc']     = new WooCommerce();

		update_option( 'nvoos_nds_wc_rebrand', 1 );

		remove_all_actions( 'woocommerce_init' );
		remove_all_actions( 'woocommerce_email_header' );
		remove_all_actions( 'woocommerce_email_footer' );
		remove_all_filters( 'woocommerce_email_styles' );
		remove_all_filters( 'woocommerce_email_get_option' );
		remove_all_filters( 'nds_email_wc_rebrand' );

		// Reset the integration's static guard so init() re-wires per test.
		$registered = new ReflectionProperty( 'NV_oOS_Design_System_Integration_WooCommerce', 'registered' );
		$registered->setAccessible( true );
		$registered->setValue( null, false );
	}

	/**
	 * Clean up global stubs.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		parent::tearDown();
		unset( $GLOBALS['nds_test_mailer'], $GLOBALS['nds_test_wc'] );
		delete_option( 'nvoos_nds_wc_rebrand' );
	}

	/**
	 * Binding replaces WC's default header/footer handlers with NDS fragments.
	 *
	 * @return void
	 */
	public function test_bind_hooks_replaces_default_handlers() {
		NV_oOS_Design_System_Integration_WooCommerce::init();
		do_action( 'woocommerce_init' );

		$mailer = WC()->mailer();

		$this->assertFalse(
			has_action( 'woocommerce_email_header', array( $mailer, 'email_header' ) ),
			'WC default header handler must be removed.'
		);
		$this->assertFalse(
			has_action( 'woocommerce_email_footer', array( $mailer, 'email_footer' ) ),
			'WC default footer handler must be removed.'
		);
		$this->assertNotFalse(
			has_action( 'woocommerce_email_header', array( 'NV_oOS_Design_System_Integration_WooCommerce', 'render_header' ) )
		);
		$this->assertNotFalse(
			has_filter( 'woocommerce_email_styles', array( 'NV_oOS_Design_System_Integration_WooCommerce', 'inject_styles' ) )
		);
		$this->assertNotFalse(
			has_filter( 'woocommerce_email_get_option', array( 'NV_oOS_Design_System_Integration_WooCommerce', 'sync_option' ) )
		);
	}

	/**
	 * The styles filter appends token-resolved palette CSS.
	 *
	 * @return void
	 */
	public function test_inject_styles_appends_palette() {
		$css = NV_oOS_Design_System_Integration_WooCommerce::inject_styles( '/* base */', false );

		$this->assertStringContainsString( '/* base */', $css, 'Existing WC styles must be preserved.' );
		$this->assertStringContainsString( '#template_header', $css );
		$this->assertStringContainsString( '#0f1e18', $css, 'The default email header background token should be inlined.' );
		$this->assertStringNotContainsString( 'var(--nds-email-', $css, 'No unresolved CSS variables in email CSS.' );
	}

	/**
	 * When rebranding is disabled, styles pass through untouched.
	 *
	 * @return void
	 */
	public function test_inject_styles_disabled_passthrough() {
		update_option( 'nvoos_nds_wc_rebrand', 0 );

		$this->assertSame( '/* base */', NV_oOS_Design_System_Integration_WooCommerce::inject_styles( '/* base */', false ) );
	}

	/**
	 * The per-email filter can disable rebranding for a specific email.
	 *
	 * @return void
	 */
	public function test_per_email_filter_disables() {
		add_filter( 'nds_email_wc_rebrand', '__return_false' );

		$this->assertSame( '/* base */', NV_oOS_Design_System_Integration_WooCommerce::inject_styles( '/* base */', new WC_Email() ) );
	}

	/**
	 * The header fragment renders the site name and heading, not WC defaults.
	 *
	 * @return void
	 */
	public function test_render_header_outputs_branded_fragment() {
		ob_start();
		NV_oOS_Design_System_Integration_WooCommerce::render_header( 'Your order', new WC_Email() );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'DEFAULT-HEADER', $output );
		$this->assertStringContainsString( get_bloginfo( 'name' ), $output );
		$this->assertStringContainsString( 'Your order', $output );
		$this->assertStringContainsString( 'role="presentation"', $output, 'Fragments must keep layout tables non-announced.' );
	}

	/**
	 * The footer fragment renders the domain and year.
	 *
	 * @return void
	 */
	public function test_render_footer_outputs_branded_fragment() {
		ob_start();
		NV_oOS_Design_System_Integration_WooCommerce::render_footer( new WC_Email() );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'DEFAULT-FOOTER', $output );
		$this->assertStringContainsString( (string) wp_parse_url( home_url(), PHP_URL_HOST ), $output );
		$this->assertStringContainsString( gmdate( 'Y' ), $output );
	}

	/**
	 * Colour options are synced from the email token group.
	 *
	 * @return void
	 */
	public function test_sync_option_maps_tokens() {
		$value = NV_oOS_Design_System_Integration_WooCommerce::sync_option( '#123456', new WC_Email(), 'base_color' );
		$this->assertSame( '#0f1e18', $value, 'base_color should map to the email header background token.' );

		$value = NV_oOS_Design_System_Integration_WooCommerce::sync_option( '#123456', new WC_Email(), 'body_text_color' );
		$this->assertSame( '#1a2420', $value, 'body_text_color should map to the email body text token.' );
	}

	/**
	 * Unmapped keys pass through untouched.
	 *
	 * @return void
	 */
	public function test_sync_option_passthrough_for_unmapped_keys() {
		$this->assertSame(
			'original',
			NV_oOS_Design_System_Integration_WooCommerce::sync_option( 'original', new WC_Email(), 'footer_text' )
		);
	}
}
