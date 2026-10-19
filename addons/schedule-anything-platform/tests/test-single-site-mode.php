<?php
/**
 * Test: Single-Site (Sole Tenant) Mode
 *
 * Verifies the platform plugin's single-site degradation (Proposal 082):
 * activation without wp_die, sole-tenant provisioning, offboard refusal,
 * the capability seam, notice dismissal state, and heartbeat gating.
 *
 * @package Schedule_Anything
 * @since   0.1.1
 */

/**
 * Tests for single-site (sole tenant) mode.
 *
 * @group schedule-anything
 * @group single-site
 */
class Test_Single_Site_Mode extends WP_UnitTestCase {

	/**
	 * Reset single-site state before every test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single-site behaviour only.' );
		}

		delete_option( 'sa_tenant_meta' );
		delete_option( 'wp_mcp_ai_settings' );
		delete_option( 'sa_single_site_notice_dismissed' );
		delete_option( 'sa_pending_deletion' );
	}

	/**
	 * Subdomain generation must not fatal on single-site (the uniqueness
	 * loop calls the multisite-only get_blog_id_from_url()).
	 */
	public function test_generate_subdomain_is_safe_on_single_site() {
		$result = SA_Multisite_Provisioner::generate_subdomain( 'ACME Corporation LLC' );

		$this->assertIsString( $result );
		$this->assertSame( 'acme-corporation', $result );
	}

	/**
	 * Provisioning on single-site returns the sole-tenant contract and is
	 * idempotent on replay.
	 */
	public function test_provision_returns_sole_tenant_contract() {
		$result = SA_Multisite_Provisioner::provision( $this->valid_tenant_data() );

		$this->assertNotWPError( $result );
		$this->assertSame( get_current_blog_id(), $result['blog_id'] );
		$this->assertSame( home_url(), $result['site_url'] );
		$this->assertSame( 'acme', $result['subdomain'] );
		$this->assertTrue( $result['single_site'] );
		$this->assertNotEmpty( $result['login_token'] );
		$this->assertNotEmpty( $result['login_url'] );

		// Tenant meta reflects the requested tier.
		$meta = get_option( 'sa_tenant_meta', array() );
		$this->assertSame( 'professional', $meta['tier'] );
		$this->assertTrue( $meta['single_site'] );

		// Replay is idempotent: same blog, same admin user, no duplicates.
		$replay = SA_Multisite_Provisioner::provision( $this->valid_tenant_data() );
		$this->assertNotWPError( $replay );
		$this->assertSame( get_current_blog_id(), $replay['blog_id'] );
		$this->assertSame( $result['admin_user_id'], $replay['admin_user_id'] );

		$users = get_users( array( 'search' => 'tenant@example.test' ) );
		$this->assertCount( 1, $users );

		// Tier presets are derived from the install-ready SPA catalogue.
		if ( class_exists( 'WP_MCP_AI_SA_Schedule_Presets' ) ) {
			$this->assertNotEmpty( $result['installed_presets'] );
			$catalogue = WP_MCP_AI_SA_Schedule_Presets::get_catalogue();
			foreach ( $result['installed_presets'] as $preset_id ) {
				$this->assertArrayHasKey( $preset_id, $catalogue );
			}
		}
	}

	/**
	 * Tier presets come from the Proposal 081 catalogue with per-tier caps.
	 */
	public function test_get_presets_for_tier_uses_catalogue() {
		if ( ! class_exists( 'WP_MCP_AI_SA_Schedule_Presets' ) ) {
			$this->markTestSkipped( 'Pro SA presets catalogue not available.' );
		}

		$catalogue = WP_MCP_AI_SA_Schedule_Presets::get_catalogue();

		$starter      = SA_Toolkit_Manager::get_presets_for_tier( 'starter' );
		$professional = SA_Toolkit_Manager::get_presets_for_tier( 'professional' );
		$enterprise   = SA_Toolkit_Manager::get_presets_for_tier( 'enterprise' );
		$invalid      = SA_Toolkit_Manager::get_presets_for_tier( 'platinum' );

		$this->assertCount( 5, $starter );
		$this->assertCount( 10, $professional );
		$this->assertGreaterThan( 10, count( $enterprise ) );
		$this->assertEmpty( $invalid );

		// Every returned id exists in the install-ready catalogue.
		foreach ( array_merge( $starter, $professional, $enterprise ) as $id ) {
			$this->assertArrayHasKey( $id, $catalogue );
		}
	}

	/**
	 * A freshly created tenant admin gets the administrator role so the SPA
	 * surface (manage_options seam) is usable.
	 */
	public function test_provision_creates_tenant_admin_as_administrator() {
		$result = SA_Multisite_Provisioner::provision( $this->valid_tenant_data() );

		$this->assertNotWPError( $result );

		$user = get_user_by( 'id', $result['admin_user_id'] );
		$this->assertInstanceOf( 'WP_User', $user );
		$this->assertContains( 'administrator', (array) $user->roles );
	}

	/**
	 * Existing users are located by email but never elevated by provisioning.
	 */
	public function test_provision_never_elevates_existing_users() {
		$user_id = $this->factory->user->create(
			array(
				'role'         => 'subscriber',
				'user_email'   => 'tenant@example.test',
				'user_login'   => 'tenant-existing',
				'display_name' => 'Keep Me',
			)
		);

		$result = SA_Multisite_Provisioner::provision( $this->valid_tenant_data() );

		$this->assertNotWPError( $result );
		$this->assertSame( $user_id, $result['admin_user_id'] );

		$user = get_user_by( 'id', $user_id );
		$this->assertContains( 'subscriber', (array) $user->roles );
		$this->assertSame( 'Keep Me', $user->display_name );
	}

	/**
	 * Field and tier validation matches the multisite contract.
	 */
	public function test_provision_validates_fields_on_single_site() {
		$result = SA_Multisite_Provisioner::provision( array() );
		$this->assertWPError( $result );
		$this->assertSame( 'sa_missing_field', $result->get_error_code() );

		$data         = $this->valid_tenant_data();
		$data['tier'] = 'invalid_tier';

		$result = SA_Multisite_Provisioner::provision( $data );
		$this->assertWPError( $result );
		$this->assertSame( 'sa_invalid_tier', $result->get_error_code() );
	}

	/**
	 * Offboarding the sole tenant is refused with a 409.
	 */
	public function test_offboard_refuses_sole_tenant() {
		$result = SA_Multisite_Provisioner::offboard( get_current_blog_id() );

		$this->assertWPError( $result );
		$this->assertSame( 'sa_cannot_offboard_sole_tenant', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	/**
	 * Unknown blog IDs on single-site resolve to not-found.
	 */
	public function test_offboard_unknown_blog_is_not_found() {
		$result = SA_Multisite_Provisioner::offboard( 999999 );

		$this->assertWPError( $result );
		$this->assertSame( 'sa_blog_not_found', $result->get_error_code() );
	}

	/**
	 * Activation seeds sole-tenant defaults with the enterprise tier.
	 */
	public function test_activation_seeds_sole_tenant_defaults() {
		SA_Plugin::on_activate();

		$meta = get_option( 'sa_tenant_meta', array() );
		$this->assertSame( 'enterprise', $meta['tier'] );
		$this->assertTrue( $meta['single_site'] );

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$this->assertNotEmpty( $settings );
		$this->assertTrue( $settings['enable_calendar_booking_toolkit'] );
		$this->assertTrue( $settings['enable_ecommerce_toolkit'] );
	}

	/**
	 * The sa_single_site_tier filter overrides the seeded tier.
	 */
	public function test_activation_tier_filter_override() {
		add_filter(
			'sa_single_site_tier',
			static function () {
				return 'starter';
			}
		);

		SA_Plugin::on_activate();

		$meta = get_option( 'sa_tenant_meta', array() );
		$this->assertSame( 'starter', $meta['tier'] );

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$this->assertTrue( $settings['enable_calendar_booking_toolkit'] );
		$this->assertFalse( $settings['enable_ecommerce_toolkit'] );
	}

	/**
	 * Unknown tier values fall back to enterprise.
	 */
	public function test_single_site_tier_rejects_unknown_values() {
		add_filter(
			'sa_single_site_tier',
			static function () {
				return 'platinum';
			}
		);

		$this->assertSame( 'enterprise', SA_Plugin::single_site_tier() );
	}

	/**
	 * Seeding never overwrites existing tenant meta or toolkit settings.
	 */
	public function test_seed_preserves_existing_tenant_meta() {
		update_option(
			'sa_tenant_meta',
			array(
				'tier'       => 'starter',
				'created_at' => 1234567890,
			)
		);
		update_option( 'wp_mcp_ai_settings', array( 'enable_crm_toolkit' => true ) );

		SA_Plugin::seed_single_site_defaults();

		$meta     = get_option( 'sa_tenant_meta', array() );
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		$this->assertSame( 'starter', $meta['tier'] );
		$this->assertSame( 1234567890, $meta['created_at'] );
		$this->assertSame( array( 'enable_crm_toolkit' => true ), $settings );
	}

	/**
	 * The capability seam resolves to manage_options on single-site.
	 */
	public function test_capability_seam_returns_manage_options_on_single_site() {
		$this->assertSame(
			'manage_options',
			SA_Plugin::filter_schedule_rest_capability( 'sa_manage_workspace' )
		);
	}

	/**
	 * The init() hook registers the capability seam filter.
	 */
	public function test_init_registers_capability_seam_filter() {
		SA_Plugin::init();

		$this->assertSame(
			'manage_options',
			apply_filters( 'wp_mcp_ai_pro_schedule_rest_capability', 'sa_manage_workspace' )
		);
	}

	/**
	 * The heartbeat never fires without a configured Cloud Worker API key.
	 */
	public function test_heartbeat_skips_without_api_key() {
		if ( defined( 'SA_SAAS_API_KEY' ) && SA_SAAS_API_KEY ) {
			$this->markTestSkipped( 'SA_SAAS_API_KEY is defined in this environment.' );
		}

		$hit = false;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$hit ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$hit = true;
				return new WP_Error( 'http_request_failed', 'blocked by test' );
			}
		);

		$result = SA_Usage_Tracker::send_heartbeat();

		$this->assertFalse( $result );
		$this->assertFalse( $hit, 'No HTTP request may be made without SA_SAAS_API_KEY.' );
	}

	/**
	 * Tenant lookup only matches the stored subdomain (or host label) on
	 * single-site and never fabricates tenants for unknown slugs.
	 */
	public function test_lookup_matches_stored_subdomain_only() {
		$request = new WP_REST_Request( 'GET', '/nvoos-saas/v1/tenants/lookup' );
		$request->set_param( 'slug', 'acme' );

		$response = SA_REST_Controller::lookup_tenant( $request );
		$this->assertWPError( $response );
		$this->assertSame( 'sa_tenant_not_found', $response->get_error_code() );

		SA_Multisite_Provisioner::provision( $this->valid_tenant_data() );

		$request = new WP_REST_Request( 'GET', '/nvoos-saas/v1/tenants/lookup' );
		$request->set_param( 'slug', 'acme' );

		$response = SA_REST_Controller::lookup_tenant( $request );
		$this->assertNotWPError( $response );

		$data = $response->get_data();
		$this->assertSame( get_current_blog_id(), $data['blog_id'] );
		$this->assertSame( 'acme', $data['slug'] );
		$this->assertTrue( $data['single_site'] );
	}

	/**
	 * Tenant status resolves the sole tenant and 404s other IDs.
	 */
	public function test_tenant_status_single_site() {
		$request = new WP_REST_Request( 'GET', '/nvoos-saas/v1/tenants/999999/status' );
		$request->set_param( 'id', 999999 );

		$response = SA_REST_Controller::get_tenant_status( $request );
		$this->assertWPError( $response );
		$this->assertSame( 'sa_blog_not_found', $response->get_error_code() );

		$request = new WP_REST_Request( 'GET', '/nvoos-saas/v1/tenants/1/status' );
		$request->set_param( 'id', get_current_blog_id() );

		$response = SA_REST_Controller::get_tenant_status( $request );
		$this->assertNotWPError( $response );
		$this->assertSame( get_current_blog_id(), $response->get_data()['blog_id'] );
	}

	/**
	 * The single-site notice is admin-only and dismissible.
	 */
	public function test_notice_visibility_and_dismissal_state() {
		// Guests never see the notice.
		$this->assertFalse( SA_Plugin::should_show_single_site_notice() );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->assertTrue( SA_Plugin::should_show_single_site_notice() );

		update_option( 'sa_single_site_notice_dismissed', 1 );
		$this->assertFalse( SA_Plugin::should_show_single_site_notice() );
	}

	/**
	 * Build a valid tenant payload for provisioning tests.
	 *
	 * @return array<string, string>
	 */
	private function valid_tenant_data() {
		return array(
			'slug'               => 'acme',
			'tier'               => 'professional',
			'stripe_customer_id' => 'cus_test123',
			'admin_email'        => 'tenant@example.test',
			'admin_name'         => 'Tenant Admin',
		);
	}
}
