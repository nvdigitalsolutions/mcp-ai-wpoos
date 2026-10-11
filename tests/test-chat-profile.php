<?php
/**
 * Tests for the Chat Profile system — domain value object, registry, and
 * server-side manager (proposal 015, read-only mode).
 *
 * The manager is the trust boundary of the feature: every chat request
 * resolves its governing profile server-side (user meta → site default →
 * write fail-safe) and a client-sent profile is honoured only for users who
 * hold the switch capability. This suite pins that resolution chain and the
 * downgrade/upgrade selection policy.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Chat profile domain + registry + manager test suite.
 *
 * @group chat-profile
 */
class Test_Chat_Profile extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Subscriber user ID (read capability only).
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Set up fixtures and reset the static caches the feature relies on.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		WP_MCP_AI_Chat_Profile_Registry::reset_cache();
		WP_MCP_AI_Chat_Profile_Manager::reset_cache();
		WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();
	}

	/**
	 * Tear down: restore pristine settings and caches.
	 */
	public function tearDown(): void {
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings_Base::get_default_settings() );
		WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();
		WP_MCP_AI_Chat_Profile_Manager::reset_cache();
		WP_MCP_AI_Chat_Profile_Registry::reset_cache();
		remove_all_filters( 'wp_mcp_ai_chat_profiles' );

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Merge settings overrides into the combined settings option the way the
	 * admin UI persists them, then drop every static cache that reads it.
	 *
	 * @param array $overrides Settings keys to override.
	 * @return void
	 */
	private function set_settings( array $overrides ) {
		$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$settings = array_merge( is_array( $settings ) ? $settings : array(), $overrides );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();
		WP_MCP_AI_Chat_Profile_Manager::reset_cache();
	}

	// -------------------------------------------------------------------------
	// Domain value object.
	// -------------------------------------------------------------------------

	/**
	 * The write profile gates nothing — no tool is ever blocked.
	 */
	public function test_write_profile_never_blocks() {
		$write = WP_MCP_AI_Chat_Profile_Registry::get_profile( WP_MCP_AI_Chat_Profile::PROFILE_WRITE );

		$this->assertNotNull( $write );
		$this->assertFalse( $write->is_restrictive() );
		$this->assertFalse( $write->blocks_tool( 'delete_everything', array( 'destructive', 'write' ) ) );
	}

	/**
	 * The read-only profile blocks gated flags but lets unflagged tools pass.
	 */
	public function test_read_only_blocks_tool_matrix() {
		$read_only = WP_MCP_AI_Chat_Profile_Registry::get_profile( WP_MCP_AI_Chat_Profile::PROFILE_READ_ONLY );

		$this->assertNotNull( $read_only );
		$this->assertTrue( $read_only->is_restrictive() );

		// Gated flags are blocked.
		foreach ( array( 'write', 'destructive', 'state-changing', 'financial-impact', 'mass-email' ) as $flag ) {
			$this->assertTrue(
				$read_only->blocks_tool( 'some_tool', array( $flag ) ),
				"Flag {$flag} should be blocked under read-only."
			);
		}

		// Unflagged and read-only-flagged tools pass.
		$this->assertFalse( $read_only->blocks_tool( 'read_tool', array( 'read-only', 'cacheable' ) ) );
		$this->assertFalse( $read_only->blocks_tool( 'plain_tool', array() ) );
	}

	/**
	 * An explicitly allowlisted slug executes despite its gated flags.
	 */
	public function test_allowlisted_slug_overrides_flags() {
		$profile = new WP_MCP_AI_Chat_Profile(
			'custom',
			'Custom',
			'Custom profile',
			array( 'write' ),
			array( 'safe_write_tool' )
		);

		$this->assertFalse( $profile->blocks_tool( 'safe_write_tool', array( 'write' ) ) );
		$this->assertTrue( $profile->blocks_tool( 'other_write_tool', array( 'write' ) ) );
	}

	/**
	 * The from_array() factory tolerates unknown keys, sanitises the slug,
	 * and rejects definitions without a slug.
	 */
	public function test_from_array_shape() {
		$profile = WP_MCP_AI_Chat_Profile::from_array(
			array(
				'slug'        => 'My-Profile!',
				'label'       => 'My Profile',
				'gated_flags' => array( 'write' ),
				'unknown_key' => 'ignored',
				'is_default'  => true,
			)
		);

		$this->assertInstanceOf( 'WP_MCP_AI_Chat_Profile', $profile );
		$this->assertSame( 'my-profile', $profile->get_slug() );
		$this->assertSame( array( 'write' ), $profile->get_gated_flags() );
		$this->assertTrue( $profile->is_default() );

		$this->assertNull( WP_MCP_AI_Chat_Profile::from_array( array( 'label' => 'No slug' ) ) );
	}

	/**
	 * The to_array() method serialises the REST/localization shape.
	 */
	public function test_to_array_shape() {
		$profile = WP_MCP_AI_Chat_Profile_Registry::get_profile( WP_MCP_AI_Chat_Profile::PROFILE_READ_ONLY );

		$data = $profile->to_array( false );

		$this->assertSame( 'read-only', $data['slug'] );
		$this->assertArrayHasKey( 'label', $data );
		$this->assertArrayHasKey( 'description', $data );
		$this->assertFalse( $data['selectable'] );
		$this->assertFalse( $data['is_default'] );
	}

	// -------------------------------------------------------------------------
	// Registry.
	// -------------------------------------------------------------------------

	/**
	 * Both built-ins are registered; write is the default, read-only gates.
	 */
	public function test_builtin_profiles_registered() {
		$profiles = WP_MCP_AI_Chat_Profile_Registry::get_profiles();

		$this->assertArrayHasKey( 'write', $profiles );
		$this->assertArrayHasKey( 'read-only', $profiles );
		$this->assertTrue( $profiles['write']->is_default() );
		$this->assertTrue( $profiles['read-only']->is_restrictive() );
		$this->assertNull( WP_MCP_AI_Chat_Profile_Registry::get_profile( 'bogus' ) );
	}

	/**
	 * A filter that removes the write profile cannot remove the fail-safe
	 * floor — write is re-added so no site can be locked out of tool access.
	 */
	public function test_write_profile_is_fail_safe_floor() {
		add_filter(
			'wp_mcp_ai_chat_profiles',
			function () {
				return array(
					array(
						'slug'        => 'read-only',
						'label'       => 'Read-only',
						'description' => 'Read-only only',
						'gated_flags' => WP_MCP_AI_Chat_Profile::READ_ONLY_GATED_FLAGS,
					),
				);
			}
		);
		WP_MCP_AI_Chat_Profile_Registry::reset_cache();

		$profiles = WP_MCP_AI_Chat_Profile_Registry::get_profiles();

		$this->assertArrayHasKey( 'write', $profiles );
		$this->assertArrayHasKey( 'read-only', $profiles );
	}

	/**
	 * Third parties can register additional restrictive profiles.
	 */
	public function test_custom_profile_registration() {
		add_filter(
			'wp_mcp_ai_chat_profiles',
			function ( $definitions ) {
				$definitions[] = array(
					'slug'                => 'audit',
					'label'               => 'Audit',
					'description'         => 'Audit mode',
					'gated_flags'         => array( 'write', 'destructive' ),
					'required_capability' => 'read',
				);
				return $definitions;
			}
		);
		WP_MCP_AI_Chat_Profile_Registry::reset_cache();

		$audit = WP_MCP_AI_Chat_Profile_Registry::get_profile( 'audit' );

		$this->assertInstanceOf( 'WP_MCP_AI_Chat_Profile', $audit );
		$this->assertTrue( $audit->blocks_tool( 'write_tool', array( 'write' ) ) );
	}

	/**
	 * Guests (user_id 0) select nothing; admins select everything; subscribers
	 * can downgrade to read-only but cannot upgrade to write.
	 */
	public function test_list_selectable_for_user_applies_policy() {
		$this->assertSame( array(), WP_MCP_AI_Chat_Profile_Registry::list_selectable_for_user( 0 ) );

		$admin_selectable = WP_MCP_AI_Chat_Profile_Registry::list_selectable_for_user( $this->admin_id );
		$this->assertArrayHasKey( 'write', $admin_selectable );
		$this->assertArrayHasKey( 'read-only', $admin_selectable );

		$subscriber_selectable = WP_MCP_AI_Chat_Profile_Registry::list_selectable_for_user( $this->subscriber_id );
		$this->assertArrayHasKey( 'read-only', $subscriber_selectable );
		$this->assertArrayNotHasKey( 'write', $subscriber_selectable );
	}

	// -------------------------------------------------------------------------
	// Manager — capability mapping.
	// -------------------------------------------------------------------------

	/**
	 * The switch capability maps to manage_options.
	 */
	public function test_map_meta_cap_maps_to_manage_options() {
		$caps = WP_MCP_AI_Chat_Profile_Manager::map_meta_cap(
			array(),
			WP_MCP_AI_Chat_Profile_Manager::SWITCH_CAPABILITY,
			$this->admin_id,
			array()
		);

		$this->assertSame( array( 'manage_options' ), $caps );

		// Unrelated capabilities pass through untouched.
		$caps = WP_MCP_AI_Chat_Profile_Manager::map_meta_cap( array( 'read' ), 'read', $this->admin_id, array() );
		$this->assertSame( array( 'read' ), $caps );
	}

	/**
	 * The user_can() check resolves the switch capability through the
	 * map_meta_cap filter: administrators hold it, subscribers do not.
	 */
	public function test_switch_capability_resolves_via_user_can() {
		// phpcs:disable WordPress.WP.Capabilities.Undetermined -- The switch capability is a plugin-registered custom capability mapped via map_meta_cap; dynamic by design.
		$this->assertTrue( user_can( $this->admin_id, WP_MCP_AI_Chat_Profile_Manager::SWITCH_CAPABILITY ) );
		$this->assertFalse( user_can( $this->subscriber_id, WP_MCP_AI_Chat_Profile_Manager::SWITCH_CAPABILITY ) );
		// phpcs:enable WordPress.WP.Capabilities.Undetermined
	}

	// -------------------------------------------------------------------------
	// Manager — resolution chain.
	// -------------------------------------------------------------------------

	/**
	 * Feature disabled → write for everyone (zero behaviour change).
	 */
	public function test_disabled_feature_resolves_write() {
		$this->set_settings( array( 'chat_profile_enabled' => false ) );

		$this->assertFalse( WP_MCP_AI_Chat_Profile_Manager::is_enabled() );
		$this->assertSame( 'write', WP_MCP_AI_Chat_Profile_Manager::resolve_slug( $this->admin_id, null ) );
		$this->assertSame( 'write', WP_MCP_AI_Chat_Profile_Manager::resolve_slug( 0, null ) );
	}

	/**
	 * Guests resolve to the guest profile — read-only by default.
	 */
	public function test_guest_resolves_to_guest_profile() {
		$this->assertSame( 'read-only', WP_MCP_AI_Chat_Profile_Manager::resolve_slug( 0, null ) );

		$this->set_settings( array( 'guest_chat_profile' => 'write' ) );
		$this->assertSame( 'write', WP_MCP_AI_Chat_Profile_Manager::resolve_slug( 0, null ) );
	}

	/**
	 * Per-user meta wins over the site default; unknown meta values fall back.
	 */
	public function test_user_meta_wins_over_site_default() {
		update_user_meta( $this->subscriber_id, WP_MCP_AI_Chat_Profile_Manager::META_KEY, 'read-only' );

		$this->assertSame( 'read-only', WP_MCP_AI_Chat_Profile_Manager::resolve_slug( $this->subscriber_id, null ) );

		// Corrupt meta falls back to the site default (write).
		update_user_meta( $this->subscriber_id, WP_MCP_AI_Chat_Profile_Manager::META_KEY, 'not-a-profile' );
		WP_MCP_AI_Chat_Profile_Manager::reset_cache();
		$this->assertSame( 'write', WP_MCP_AI_Chat_Profile_Manager::resolve_slug( $this->subscriber_id, null ) );
	}

	/**
	 * The site default setting governs users without a personal selection.
	 */
	public function test_site_default_setting_governs() {
		$this->set_settings( array( 'default_chat_profile' => 'read-only' ) );

		$this->assertSame( 'read-only', WP_MCP_AI_Chat_Profile_Manager::resolve_slug( $this->subscriber_id, null ) );
		$this->assertSame( 'read-only', WP_MCP_AI_Chat_Profile_Manager::resolve_slug( $this->admin_id, null ) );
	}

	/**
	 * The resolve() method returns the governing profile object and never
	 * null.
	 */
	public function test_resolve_returns_profile_object() {
		$profile = WP_MCP_AI_Chat_Profile_Manager::resolve( $this->admin_id, null );
		$this->assertInstanceOf( 'WP_MCP_AI_Chat_Profile', $profile );
		$this->assertSame( 'write', $profile->get_slug() );
	}

	// -------------------------------------------------------------------------
	// Manager — client override / escalation policy.
	// -------------------------------------------------------------------------

	/**
	 * A capability holder may adopt a client-requested profile per request.
	 */
	public function test_client_override_honoured_for_capability_holder() {
		$this->assertSame(
			'read-only',
			WP_MCP_AI_Chat_Profile_Manager::resolve_slug( $this->admin_id, 'read-only' )
		);
	}

	/**
	 * A downgraded user's upgrade attempt is dropped and logged as an
	 * escalation attempt — the resolved value stands.
	 */
	public function test_escalation_attempt_dropped_and_logged() {
		update_user_meta( $this->subscriber_id, WP_MCP_AI_Chat_Profile_Manager::META_KEY, 'read-only' );
		WP_MCP_AI_Chat_Profile_Manager::reset_cache();

		$events = array();
		add_action(
			'wp_mcp_ai_security_event',
			function ( $event_type, $user_id, $ip_address, $details ) use ( &$events ) {
				unset( $ip_address );
				$events[] = array(
					'type'    => $event_type,
					'user_id' => $user_id,
					'details' => $details,
				);
			},
			10,
			4
		);

		$resolved = WP_MCP_AI_Chat_Profile_Manager::resolve_slug( $this->subscriber_id, 'write' );

		$this->assertSame( 'read-only', $resolved );

		$escalations = array_values(
			array_filter(
				$events,
				function ( $event ) {
					return WP_MCP_AI_Security_Audit_Logger::EVENT_CHAT_PROFILE_ESCALATION_ATTEMPT === $event['type'];
				}
			)
		);
		$this->assertNotEmpty( $escalations, 'Escalation attempt should be audited.' );
		$this->assertSame( $this->subscriber_id, $escalations[0]['user_id'] );
		$this->assertSame( 'write', $escalations[0]['details']['requested'] );
		$this->assertSame( 'read-only', $escalations[0]['details']['resolved'] );

		remove_all_filters( 'wp_mcp_ai_security_event' );
	}

	/**
	 * A downgrade (write → read-only) is allowed for any logged-in user.
	 */
	public function test_downgrade_allowed_for_subscriber() {
		// Subscriber has no personal selection (site default write) but may
		// adopt read-only per request — the safe direction needs no special cap.
		$this->assertSame(
			'read-only',
			WP_MCP_AI_Chat_Profile_Manager::resolve_slug( $this->subscriber_id, 'read-only' )
		);
	}

	// -------------------------------------------------------------------------
	// Manager — persistence.
	// -------------------------------------------------------------------------

	/**
	 * Guests cannot persist a selection.
	 */
	public function test_set_user_profile_rejects_guest() {
		$result = WP_MCP_AI_Chat_Profile_Manager::set_user_profile( 0, 'read-only' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_chat_profile_invalid_user', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Unknown slugs are rejected with 400.
	 */
	public function test_set_user_profile_rejects_unknown_slug() {
		$result = WP_MCP_AI_Chat_Profile_Manager::set_user_profile( $this->admin_id, 'bogus' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_chat_profile_invalid', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * A subscriber may persist the read-only downgrade.
	 */
	public function test_set_user_profile_downgrade_persists() {
		$result = WP_MCP_AI_Chat_Profile_Manager::set_user_profile( $this->subscriber_id, 'read-only' );

		$this->assertTrue( $result );
		$this->assertSame(
			'read-only',
			get_user_meta( $this->subscriber_id, WP_MCP_AI_Chat_Profile_Manager::META_KEY, true )
		);
	}

	/**
	 * A subscriber may not persist an upgrade to write.
	 */
	public function test_set_user_profile_upgrade_forbidden() {
		$result = WP_MCP_AI_Chat_Profile_Manager::set_user_profile( $this->subscriber_id, 'write' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_chat_profile_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( '', get_user_meta( $this->subscriber_id, WP_MCP_AI_Chat_Profile_Manager::META_KEY, true ) );
	}

	/**
	 * Selecting the site default clears the personal override.
	 */
	public function test_set_user_profile_default_clears_meta() {
		update_user_meta( $this->admin_id, WP_MCP_AI_Chat_Profile_Manager::META_KEY, 'read-only' );

		$result = WP_MCP_AI_Chat_Profile_Manager::set_user_profile( $this->admin_id, 'write' );

		$this->assertTrue( $result );
		$this->assertSame( '', get_user_meta( $this->admin_id, WP_MCP_AI_Chat_Profile_Manager::META_KEY, true ) );
	}

	/**
	 * The bypass flag (WP-CLI / admin surfaces) skips the selection policy.
	 */
	public function test_set_user_profile_bypass_policy() {
		// Register a profile whose required capability the subscriber lacks,
		// so only the bypass flag can get them onto it.
		add_filter(
			'wp_mcp_ai_chat_profiles',
			function ( $definitions ) {
				$definitions[] = array(
					'slug'                => 'audit',
					'label'               => 'Audit',
					'description'         => 'Audit mode',
					'gated_flags'         => array( 'write' ),
					'required_capability' => 'manage_options',
				);
				return $definitions;
			}
		);
		WP_MCP_AI_Chat_Profile_Registry::reset_cache();

		// Without bypass the policy refuses.
		$refused = WP_MCP_AI_Chat_Profile_Manager::set_user_profile( $this->subscriber_id, 'audit' );
		$this->assertWPError( $refused );
		$this->assertSame( 'wp_mcp_ai_chat_profile_forbidden', $refused->get_error_code() );

		// With bypass the selection persists.
		$result = WP_MCP_AI_Chat_Profile_Manager::set_user_profile( $this->subscriber_id, 'audit', true );
		$this->assertTrue( $result );
		$this->assertSame(
			'audit',
			get_user_meta( $this->subscriber_id, WP_MCP_AI_Chat_Profile_Manager::META_KEY, true )
		);
	}

	/**
	 * Persistence is refused while the feature is disabled.
	 */
	public function test_set_user_profile_disabled() {
		$this->set_settings( array( 'chat_profile_enabled' => false ) );

		$result = WP_MCP_AI_Chat_Profile_Manager::set_user_profile( $this->admin_id, 'read-only' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_chat_profile_disabled', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}
}
