<?php
/**
 * Embed-page provisioning tests.
 *
 * @package NV_oOS_Toolkit_Shell
 */
class Test_Toolkit_Shell_Embed_Page extends WP_UnitTestCase {

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'NVOOS_TOOLKIT_SHELL_VERSION' ) ) {
			define( 'NVOOS_TOOLKIT_SHELL_VERSION', '0.3.0' );
		}
		if ( ! defined( 'NVOOS_TOOLKIT_SHELL_PATH' ) ) {
			define( 'NVOOS_TOOLKIT_SHELL_PATH', dirname( __DIR__ ) . '/' );
		}
		if ( ! defined( 'NVOOS_TOOLKIT_SHELL_URL' ) ) {
			define( 'NVOOS_TOOLKIT_SHELL_URL', 'http://example.com/wp-content/plugins/nvoos-toolkit-shell/' );
		}
		require_once NVOOS_TOOLKIT_SHELL_PATH . 'includes/class-nvoos-toolkit-shell-plugin.php';

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	/**
	 * Clean up the auto-provisioned page + option between tests.
	 */
	public function tearDown(): void {
		delete_option( NV_oOS_Toolkit_Shell_Plugin::EMBED_PAGE_OPTION );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Provisioning creates one published page embedding the shortcode.
	 */
	public function test_provision_creates_embed_page() {
		NV_oOS_Toolkit_Shell_Plugin::maybe_provision_embed_page();

		$page_id = (int) get_option( NV_oOS_Toolkit_Shell_Plugin::EMBED_PAGE_OPTION, 0 );
		$this->assertGreaterThan( 0, $page_id );

		$page = get_post( $page_id );
		$this->assertNotNull( $page );
		$this->assertSame( 'publish', $page->post_status );
		$this->assertSame( 'page', $page->post_type );
		$this->assertStringContainsString( '[nvoos_toolkit_app', $page->post_content );
	}

	/**
	 * Provisioning is idempotent — a second run reuses the same page.
	 */
	public function test_provision_is_idempotent() {
		NV_oOS_Toolkit_Shell_Plugin::maybe_provision_embed_page();
		$first = (int) get_option( NV_oOS_Toolkit_Shell_Plugin::EMBED_PAGE_OPTION, 0 );

		NV_oOS_Toolkit_Shell_Plugin::maybe_provision_embed_page();
		$second = (int) get_option( NV_oOS_Toolkit_Shell_Plugin::EMBED_PAGE_OPTION, 0 );

		$this->assertSame( $first, $second );
	}

	/**
	 * Provisioning does not overwrite an existing page that already renders the
	 * shortcode (guards against creating a duplicate).
	 */
	public function test_provision_reuses_existing_shortcode_page() {
		$existing = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[nvoos_toolkit_app toolkit="calendar-booking"]',
			)
		);

		NV_oOS_Toolkit_Shell_Plugin::maybe_provision_embed_page();
		$page_id = (int) get_option( NV_oOS_Toolkit_Shell_Plugin::EMBED_PAGE_OPTION, 0 );

		$this->assertSame( $existing, $page_id );
	}
}
