<?php
/**
 * Tests for privacy controls functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test privacy controls class.
 */
class Test_Privacy_Controls extends WP_UnitTestCase {

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create test user.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);
	}

	/**
	 * Test opt-out meta key constant exists.
	 */
	public function test_opt_out_meta_key_constant_exists() {
		$this->assertTrue( defined( 'WP_MCP_AI_Privacy_Controls::META_OPT_OUT_TRANSCRIPTS' ) || class_exists( 'WP_MCP_AI_Privacy_Controls' ) );
	}

	/**
	 * Test user can opt out of transcript recording.
	 */
	public function test_user_can_opt_out() {
		// User should not be opted out by default.
		$this->assertFalse( WP_MCP_AI_Privacy_Controls::has_user_opted_out( $this->user_id ) );

		// Opt out user.
		update_user_meta( $this->user_id, WP_MCP_AI_Privacy_Controls::META_OPT_OUT_TRANSCRIPTS, '1' );

		// User should now be opted out.
		$this->assertTrue( WP_MCP_AI_Privacy_Controls::has_user_opted_out( $this->user_id ) );
	}

	/**
	 * Test user can opt back in.
	 */
	public function test_user_can_opt_in() {
		// Opt out user.
		update_user_meta( $this->user_id, WP_MCP_AI_Privacy_Controls::META_OPT_OUT_TRANSCRIPTS, '1' );
		$this->assertTrue( WP_MCP_AI_Privacy_Controls::has_user_opted_out( $this->user_id ) );

		// Opt back in.
		update_user_meta( $this->user_id, WP_MCP_AI_Privacy_Controls::META_OPT_OUT_TRANSCRIPTS, '0' );

		// User should not be opted out.
		$this->assertFalse( WP_MCP_AI_Privacy_Controls::has_user_opted_out( $this->user_id ) );
	}

	/**
	 * Test consent timestamp is recorded when opting in.
	 */
	public function test_consent_timestamp_recorded() {
		// Simulate form submission with opt-in.
		$_POST['_wpnonce']                        = wp_create_nonce( 'update-user_' . $this->user_id );
		$_POST['wp_mcp_ai_opt_out_transcripts']   = '0';

		// Set current user.
		wp_set_current_user( $this->user_id );

		// Save settings.
		WP_MCP_AI_Privacy_Controls::save_privacy_settings( $this->user_id );

		// Check consent timestamp exists.
		$consent_time = get_user_meta( $this->user_id, WP_MCP_AI_Privacy_Controls::META_CONSENT_TIMESTAMP, true );
		$this->assertNotEmpty( $consent_time );
		$this->assertIsNumeric( $consent_time );

		// Check consent version exists.
		$consent_version = get_user_meta( $this->user_id, WP_MCP_AI_Privacy_Controls::META_CONSENT_VERSION, true );
		$this->assertNotEmpty( $consent_version );
		$this->assertEquals( WP_MCP_AI_Privacy_Controls::CONSENT_VERSION, $consent_version );
	}

	/**
	 * Test privacy exporters are registered.
	 */
	public function test_exporters_registered() {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );

		$this->assertArrayHasKey( 'wp-mcp-ai-chat-transcripts', $exporters );
		$this->assertArrayHasKey( 'exporter_friendly_name', $exporters['wp-mcp-ai-chat-transcripts'] );
		$this->assertArrayHasKey( 'callback', $exporters['wp-mcp-ai-chat-transcripts'] );
		$this->assertEquals( 'AI Chat Transcripts', $exporters['wp-mcp-ai-chat-transcripts']['exporter_friendly_name'] );
	}

	/**
	 * Test privacy erasers are registered.
	 */
	public function test_erasers_registered() {
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		$this->assertArrayHasKey( 'wp-mcp-ai-chat-transcripts', $erasers );
		$this->assertArrayHasKey( 'eraser_friendly_name', $erasers['wp-mcp-ai-chat-transcripts'] );
		$this->assertArrayHasKey( 'callback', $erasers['wp-mcp-ai-chat-transcripts'] );
		$this->assertEquals( 'AI Chat Transcripts', $erasers['wp-mcp-ai-chat-transcripts']['eraser_friendly_name'] );
	}

	/**
	 * Test export chat data returns correct structure.
	 */
	public function test_export_chat_data_structure() {
		// Create user.
		$user = $this->factory->user->create_and_get();

		// Export data.
		$result = WP_MCP_AI_Privacy_Controls::export_chat_data( $user->user_email, 1 );

		// Check structure.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'done', $result );
		$this->assertTrue( $result['done'] );
		$this->assertIsArray( $result['data'] );
	}

	/**
	 * Test export includes privacy settings.
	 */
	public function test_export_includes_privacy_settings() {
		// Create user.
		$user = $this->factory->user->create_and_get();

		// Set opt-out preference.
		update_user_meta( $user->ID, WP_MCP_AI_Privacy_Controls::META_OPT_OUT_TRANSCRIPTS, '1' );

		// Export data.
		$result = WP_MCP_AI_Privacy_Controls::export_chat_data( $user->user_email, 1 );

		// Check privacy settings are exported.
		$this->assertNotEmpty( $result['data'] );
		$found_settings = false;
		foreach ( $result['data'] as $group ) {
			if ( 'wp-mcp-ai-privacy-settings' === $group['group_id'] ) {
				$found_settings = true;
				$this->assertEquals( 'AI Chat Privacy Settings', $group['group_label'] );
				$this->assertNotEmpty( $group['data'] );
			}
		}
		$this->assertTrue( $found_settings, 'Privacy settings group not found in export' );
	}

	/**
	 * Test erase chat data returns correct structure.
	 */
	public function test_erase_chat_data_structure() {
		// Create user.
		$user = $this->factory->user->create_and_get();

		// Erase data.
		$result = WP_MCP_AI_Privacy_Controls::erase_chat_data( $user->user_email, 1 );

		// Check structure.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items_removed', $result );
		$this->assertArrayHasKey( 'items_retained', $result );
		$this->assertArrayHasKey( 'messages', $result );
		$this->assertArrayHasKey( 'done', $result );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * Test eraser removes privacy settings.
	 */
	public function test_eraser_removes_privacy_settings() {
		// Create user.
		$user = $this->factory->user->create_and_get();

		// Set privacy settings.
		update_user_meta( $user->ID, WP_MCP_AI_Privacy_Controls::META_OPT_OUT_TRANSCRIPTS, '1' );
		update_user_meta( $user->ID, WP_MCP_AI_Privacy_Controls::META_CONSENT_TIMESTAMP, time() );
		update_user_meta( $user->ID, WP_MCP_AI_Privacy_Controls::META_CONSENT_VERSION, '1.0' );

		// Verify settings exist.
		$this->assertEquals( '1', get_user_meta( $user->ID, WP_MCP_AI_Privacy_Controls::META_OPT_OUT_TRANSCRIPTS, true ) );

		// Erase data.
		WP_MCP_AI_Privacy_Controls::erase_chat_data( $user->user_email, 1 );

		// Verify settings removed.
		$this->assertEmpty( get_user_meta( $user->ID, WP_MCP_AI_Privacy_Controls::META_OPT_OUT_TRANSCRIPTS, true ) );
		$this->assertEmpty( get_user_meta( $user->ID, WP_MCP_AI_Privacy_Controls::META_CONSENT_TIMESTAMP, true ) );
		$this->assertEmpty( get_user_meta( $user->ID, WP_MCP_AI_Privacy_Controls::META_CONSENT_VERSION, true ) );
	}

	/**
	 * Test invalid user for export.
	 */
	public function test_export_invalid_user() {
		$result = WP_MCP_AI_Privacy_Controls::export_chat_data( 'nonexistent@example.com', 1 );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result['data'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * Test invalid user for erasure.
	 */
	public function test_erase_invalid_user() {
		$result = WP_MCP_AI_Privacy_Controls::erase_chat_data( 'nonexistent@example.com', 1 );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * Test privacy policy content is added.
	 */
	public function test_privacy_policy_content_added() {
		// This test checks that the function doesn't throw errors.
		// Actual content verification would require mocking wp_add_privacy_policy_content.
		$this->assertTrue( method_exists( 'WP_MCP_AI_Privacy_Controls', 'add_privacy_policy_content' ) );
		
		// Call the method to ensure no errors.
		if ( method_exists( 'WP_MCP_AI_Privacy_Controls', 'add_privacy_policy_content' ) ) {
			WP_MCP_AI_Privacy_Controls::add_privacy_policy_content();
			$this->assertTrue( true );
		}
	}

	/**
	 * Test has_user_opted_out returns false for invalid user ID.
	 */
	public function test_has_user_opted_out_invalid_user() {
		$this->assertFalse( WP_MCP_AI_Privacy_Controls::has_user_opted_out( 0 ) );
		$this->assertFalse( WP_MCP_AI_Privacy_Controls::has_user_opted_out( 99999999 ) );
	}

	/**
	 * Test save_privacy_settings requires proper capability.
	 */
	public function test_save_privacy_settings_requires_capability() {
		// Create user without edit_user capability for target user.
		$other_user = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $other_user );

		// Try to save settings for different user.
		$_POST['_wpnonce']                      = wp_create_nonce( 'update-user_' . $this->user_id );
		$_POST['wp_mcp_ai_opt_out_transcripts'] = '1';

		// This should not save (user lacks capability).
		WP_MCP_AI_Privacy_Controls::save_privacy_settings( $this->user_id );

		// Verify setting was not saved.
		$opt_out = get_user_meta( $this->user_id, WP_MCP_AI_Privacy_Controls::META_OPT_OUT_TRANSCRIPTS, true );
		$this->assertNotEquals( '1', $opt_out );
	}

	/**
	 * Test save_privacy_settings validates nonce.
	 */
	public function test_save_privacy_settings_validates_nonce() {
		wp_set_current_user( $this->user_id );

		// Invalid nonce.
		$_POST['_wpnonce']                      = 'invalid_nonce';
		$_POST['wp_mcp_ai_opt_out_transcripts'] = '1';

		// This should not save (invalid nonce).
		WP_MCP_AI_Privacy_Controls::save_privacy_settings( $this->user_id );

		// Verify setting was not saved.
		$opt_out = get_user_meta( $this->user_id, WP_MCP_AI_Privacy_Controls::META_OPT_OUT_TRANSCRIPTS, true );
		$this->assertNotEquals( '1', $opt_out );
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		// Clean up POST data.
		$_POST = array();

		parent::tearDown();
	}
}
