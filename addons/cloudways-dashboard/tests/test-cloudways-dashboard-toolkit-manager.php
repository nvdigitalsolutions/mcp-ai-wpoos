<?php
/**
 * Tests for NV oOS Cloudways Dashboard Toolkit Manager.
 *
 * @package NV_oOS_CloudwaysDashboard
 * @since   0.1.0
 */

/**
 * Class Test_Cloudways_Dashboard_Toolkit_Manager
 *
 * @group cloudways-dashboard
 * @group toolkits
 */
class Test_Cloudways_Dashboard_Toolkit_Manager extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'NV_oOS_CloudwaysDashboard_Toolkit_Manager' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-nvoos-cloudways-dashboard-toolkit-manager.php';
		}
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		// Clean up test options.
		delete_option( NV_oOS_CloudwaysDashboard_Toolkit_Manager::state_key( 999 ) );
		delete_option( NV_oOS_CloudwaysDashboard_Toolkit_Manager::state_key( 998 ) );
		parent::tearDown();
	}

	/**
	 * Test state key format returns expected string.
	 */
	public function test_state_key_format() {
		$key = NV_oOS_CloudwaysDashboard_Toolkit_Manager::state_key( 42 );
		$this->assertSame( 'nvoos_cw_site_toolkits_42', $key );
	}

	/**
	 * Test that getting toolkits for a new site returns empty array.
	 */
	public function test_get_site_toolkits_returns_empty_array_for_new_site() {
		$result = NV_oOS_CloudwaysDashboard_Toolkit_Manager::get_site_toolkits( 999 );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test that applying toolkits updates state correctly.
	 */
	public function test_apply_toolkits() {
		$results = NV_oOS_CloudwaysDashboard_Toolkit_Manager::apply_toolkits( 999, array( 'crm', 'ecommerce' ) );

		$this->assertArrayHasKey( 'crm', $results );
		$this->assertArrayHasKey( 'ecommerce', $results );
		$this->assertSame( 'applied', $results['crm']['status'] );

		$state = NV_oOS_CloudwaysDashboard_Toolkit_Manager::get_site_toolkits( 999 );
		$this->assertCount( 2, $state );
		$this->assertSame( 'active', $state['crm']['status'] );
		$this->assertSame( 'active', $state['ecommerce']['status'] );
		$this->assertIsInt( $state['crm']['applied_at'] );
	}

	/**
	 * Test that applying a duplicate toolkit returns already_active.
	 */
	public function test_apply_duplicate_toolkit_returns_already_active() {
		NV_oOS_CloudwaysDashboard_Toolkit_Manager::apply_toolkits( 998, array( 'crm' ) );
		$results = NV_oOS_CloudwaysDashboard_Toolkit_Manager::apply_toolkits( 998, array( 'crm' ) );

		$this->assertArrayHasKey( 'crm', $results );
		$this->assertSame( 'already_active', $results['crm']['status'] );
	}

	/**
	 * Test that removing toolkits updates state correctly.
	 */
	public function test_remove_toolkits() {
		NV_oOS_CloudwaysDashboard_Toolkit_Manager::apply_toolkits( 999, array( 'crm', 'ecommerce', 'calendar' ) );

		$results = NV_oOS_CloudwaysDashboard_Toolkit_Manager::remove_toolkits( 999, array( 'crm' ) );
		$this->assertArrayHasKey( 'crm', $results );
		$this->assertSame( 'removed', $results['crm']['status'] );

		$state = NV_oOS_CloudwaysDashboard_Toolkit_Manager::get_site_toolkits( 999 );
		$this->assertCount( 2, $state );
		$this->assertArrayNotHasKey( 'crm', $state );
	}

	/**
	 * Test that removing a nonexistent toolkit returns not_found.
	 */
	public function test_remove_nonexistent_toolkit_returns_not_found() {
		$results = NV_oOS_CloudwaysDashboard_Toolkit_Manager::remove_toolkits( 999, array( 'nonexistent' ) );
		$this->assertSame( 'not_found', $results['nonexistent']['status'] );
	}

	/**
	 * Test that toolkit slugs are sanitized via sanitize_key.
	 */
	public function test_slugs_are_sanitized() {
		NV_oOS_CloudwaysDashboard_Toolkit_Manager::apply_toolkits( 999, array( 'CRM-UPPER', 'with spaces' ) );

		$state = NV_oOS_CloudwaysDashboard_Toolkit_Manager::get_site_toolkits( 999 );
		$slugs = array_keys( $state );
		$this->assertContains( 'crm-upper', $slugs );
		// 'with spaces' becomes 'withspaces' via sanitize_key
		$this->assertContains( 'withspaces', $slugs );
	}

	/**
	 * Test global summary returns correct counts across all sites.
	 */
	public function test_global_summary() {
		NV_oOS_CloudwaysDashboard_Toolkit_Manager::apply_toolkits( 999, array( 'crm', 'ecommerce' ) );
		NV_oOS_CloudwaysDashboard_Toolkit_Manager::apply_toolkits( 998, array( 'crm' ) );

		$summary = NV_oOS_CloudwaysDashboard_Toolkit_Manager::get_global_summary();
		$this->assertArrayHasKey( 'sites', $summary );
		$this->assertArrayHasKey( 'toolkit_counts', $summary );
		$this->assertArrayHasKey( 'total_applications', $summary );

		$this->assertArrayHasKey( 999, $summary['sites'] );
		$this->assertSame( 2, $summary['sites'][999]['total'] );
		$this->assertSame( 2, $summary['toolkit_counts']['crm'] );
		$this->assertSame( 3, $summary['total_applications'] );
	}
}
