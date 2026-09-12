<?php
/**
 * Tests for the Docs Hub cache staging/transient isolation.
 *
 * @package NV_oOS_Docs_Hub
 * @since   0.4.4
 */

/**
 * Cache staging isolation tests.
 *
 * Verifies that staged rebuilds never read or write the live page
 * transients (wp.org review follow-up: a staged rebuild must not serve or
 * populate live cache data before the staging cache is promoted).
 */
class Test_Docs_Hub_Cache_Staging extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'NVOOS_DOCS_HUB_VERSION' ) ) {
			define( 'NVOOS_DOCS_HUB_VERSION', '1.0.0' );
		}
		if ( ! defined( 'NVOOS_DOCS_HUB_PATH' ) ) {
			define( 'NVOOS_DOCS_HUB_PATH', dirname( __DIR__ ) . '/' );
		}
		if ( ! defined( 'NVOOS_DOCS_HUB_URL' ) ) {
			define( 'NVOOS_DOCS_HUB_URL', 'http://example.com/wp-content/plugins/nvoos-docs-hub/' );
		}
		if ( ! defined( 'NVOOS_DOCS_HUB_FILE' ) ) {
			define( 'NVOOS_DOCS_HUB_FILE', NVOOS_DOCS_HUB_PATH . 'nvoos-docs-hub.php' );
		}

		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-cache.php';
	}

	/**
	 * Tear down after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		$cache = new NV_oOS_Docs_Hub_Cache();
		$cache->clear();
		$cache->clear_staging();

		delete_transient( NV_oOS_Docs_Hub_Cache::TRANSIENT_PREFIX . 'p_' . md5( 'staged-slug' ) );
		delete_transient( NV_oOS_Docs_Hub_Cache::TRANSIENT_PREFIX . 'p_' . md5( 'live-slug' ) );

		parent::tearDown();
	}

	/**
	 * Build a minimal page payload.
	 *
	 * @param string $title Payload title.
	 * @return array
	 */
	private function page_payload( $title ) {
		return array(
			'slug'        => 'test-page',
			'title'       => $title,
			'content'     => '<p>Body</p>',
			'source'      => 'base',
			'plugin_name' => 'Core',
			'toc'         => array(),
		);
	}

	/**
	 * Staged page writes must not populate the live transient.
	 *
	 * @return void
	 */
	public function test_staged_page_write_does_not_set_live_transient() {
		$cache = new NV_oOS_Docs_Hub_Cache();
		$cache->use_staging( true );

		$result = $cache->set_page( 'staged-slug', $this->page_payload( 'Staged' ) );
		$this->assertTrue( $result );

		$transient_key = NV_oOS_Docs_Hub_Cache::TRANSIENT_PREFIX . 'p_' . md5( 'staged-slug' );
		$this->assertFalse( get_transient( $transient_key ), 'Staging writes must not write the live page transient' );
	}

	/**
	 * Staged page reads must ignore the live transient and read the staged
	 * filesystem copy.
	 *
	 * @return void
	 */
	public function test_staged_page_read_ignores_live_transient() {
		$transient_key = NV_oOS_Docs_Hub_Cache::TRANSIENT_PREFIX . 'p_' . md5( 'live-slug' );
		set_transient( $transient_key, $this->page_payload( 'Live transient copy' ), 3600 );

		$cache = new NV_oOS_Docs_Hub_Cache();
		$cache->use_staging( true );
		$cache->set_page( 'live-slug', $this->page_payload( 'Staged filesystem copy' ) );

		$read = $cache->get_page( 'live-slug' );
		$this->assertIsArray( $read );
		$this->assertSame( 'Staged filesystem copy', $read['title'], 'Staging reads must come from the staged filesystem, not the live transient' );
	}

	/**
	 * Live page writes still set the live transient (regression guard).
	 *
	 * @return void
	 */
	public function test_live_page_write_still_sets_transient() {
		$cache = new NV_oOS_Docs_Hub_Cache();
		$cache->set_page( 'live-slug', $this->page_payload( 'Live' ) );

		$transient_key = NV_oOS_Docs_Hub_Cache::TRANSIENT_PREFIX . 'p_' . md5( 'live-slug' );
		$cached        = get_transient( $transient_key );
		$this->assertIsArray( $cached, 'Live writes must keep populating the live transient' );
		$this->assertSame( 'Live', $cached['title'] );
	}
}
