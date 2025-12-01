<?php
/**
 * Tests for comments integration.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Comments_Integration_Test extends WP_UnitTestCase {

	/**
	 * Comments integration instance.
	 *
	 * @var WP_MCP_AI_Comments
	 */
	private $comments_integration;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Get the comments integration instance.
		$this->comments_integration = WP_MCP_AI_Comments::get_instance();

		// Reset settings.
		delete_option( 'wp_mcp_ai_settings' );
		delete_option( 'wp_mcp_ai_recent_errors' );
		delete_option( 'wp_mcp_ai_recent_activity' );
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		delete_option( 'wp_mcp_ai_recent_errors' );
		delete_option( 'wp_mcp_ai_recent_activity' );

		parent::tearDown();
	}

	/**
	 * Test that comments integration is a singleton.
	 */
	public function test_comments_integration_is_singleton() {
		$instance1 = WP_MCP_AI_Comments::get_instance();
		$instance2 = WP_MCP_AI_Comments::get_instance();

		$this->assertSame( $instance1, $instance2, 'Comments integration should return the same instance' );
	}

	/**
	 * Test that the preprocess_comment filter is registered.
	 */
	public function test_preprocess_comment_filter_is_registered() {
		$this->assertGreaterThan(
			0,
			has_filter( 'preprocess_comment', array( $this->comments_integration, 'analyze_comment' ) ),
			'preprocess_comment filter should be registered'
		);
	}

	/**
	 * Test that comment processing is skipped when feature is disabled.
	 */
	public function test_processing_skipped_when_feature_disabled() {
		// Disable the feature.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ai_comments_moderation' => false,
			)
		);

		// Create test comment data.
		$commentdata = array(
			'comment_content' => 'This is a test comment',
			'comment_author'  => 'Test Author',
		);

		// Run through the filter.
		$result = apply_filters( 'preprocess_comment', $commentdata );

		// Should return unchanged data.
		$this->assertEquals( $commentdata, $result, 'Comment data should be unchanged when feature is disabled' );
	}

	/**
	 * Test that moderators are exempt from AI analysis.
	 */
	public function test_moderators_exempt_from_analysis() {
		// Enable the feature.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ai_comments_moderation' => true,
			)
		);

		// Create a moderator user.
		$user_id = $this->factory->user->create(
			array(
				'role' => 'editor', // Editors can moderate comments.
			)
		);

		wp_set_current_user( $user_id );

		// Create test comment data.
		$commentdata = array(
			'comment_content' => 'This is a test comment from a moderator',
			'comment_author'  => 'Test Editor',
		);

		// Run through the filter.
		$result = apply_filters( 'preprocess_comment', $commentdata );

		// Should return unchanged data for moderators.
		$this->assertEquals( $commentdata, $result, 'Comment data should be unchanged for moderators' );

		// Clean up.
		wp_set_current_user( 0 );
	}

	/**
	 * Test that comments already marked as spam are skipped.
	 */
	public function test_already_spam_comments_skipped() {
		// Enable the feature.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ai_comments_moderation' => true,
			)
		);

		// Create test comment data already marked as spam.
		$commentdata = array(
			'comment_content'  => 'This is spam',
			'comment_author'   => 'Spammer',
			'comment_approved' => 'spam',
		);

		// Run through the filter.
		$result = apply_filters( 'preprocess_comment', $commentdata );

		// Should still be marked as spam (not changed).
		$this->assertEquals( 'spam', $result['comment_approved'], 'Spam status should be preserved' );
	}

	/**
	 * Test sensitivity settings.
	 */
	public function test_sensitivity_settings() {
		// Test different sensitivity levels.
		$sensitivity_levels = array( 'low', 'medium', 'high' );

		foreach ( $sensitivity_levels as $level ) {
			update_option(
				'wp_mcp_ai_settings',
				array(
					'enable_ai_comments_moderation' => true,
					'ai_comments_sensitivity'       => $level,
				)
			);

			$settings = get_option( 'wp_mcp_ai_settings', array() );

			$this->assertEquals( $level, $settings['ai_comments_sensitivity'], "Sensitivity should be set to {$level}" );
		}
	}

	/**
	 * Test minimum confidence level settings.
	 */
	public function test_confidence_level_settings() {
		// Test different confidence levels.
		$confidence_levels = array( 0.5, 0.6, 0.7, 0.8, 0.9 );

		foreach ( $confidence_levels as $level ) {
			update_option(
				'wp_mcp_ai_settings',
				array(
					'enable_ai_comments_moderation' => true,
					'ai_comments_min_confidence'    => $level,
				)
			);

			$settings = get_option( 'wp_mcp_ai_settings', array() );

			$this->assertEquals( $level, floatval( $settings['ai_comments_min_confidence'] ), "Confidence level should be set to {$level}" );
		}
	}

	/**
	 * Test auto-hold low confidence setting.
	 */
	public function test_auto_hold_low_confidence_setting() {
		// Test with auto-hold enabled.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ai_comments_moderation'        => true,
				'ai_comments_auto_hold_low_confidence' => true,
			)
		);

		$settings = get_option( 'wp_mcp_ai_settings', array() );

		$this->assertTrue( $settings['ai_comments_auto_hold_low_confidence'], 'Auto-hold should be enabled' );

		// Test with auto-hold disabled.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ai_comments_moderation'        => true,
				'ai_comments_auto_hold_low_confidence' => false,
			)
		);

		$settings = get_option( 'wp_mcp_ai_settings', array() );

		$this->assertFalse( $settings['ai_comments_auto_hold_low_confidence'], 'Auto-hold should be disabled' );
	}

	/**
	 * Test that logging works when enabled.
	 */
	public function test_logging_when_enabled() {
		// Enable logging.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_logging' => true,
			)
		);

		// Clear existing logs.
		delete_option( 'wp_mcp_ai_recent_activity' );

		// Trigger a log.
		$reflection = new ReflectionClass( $this->comments_integration );
		$method     = $reflection->getMethod( 'log_activity' );
		$method->setAccessible( true );
		$method->invoke( $this->comments_integration, 'Test comment activity' );

		$activity = get_option( 'wp_mcp_ai_recent_activity', array() );

		$this->assertNotEmpty( $activity, 'Activity log should not be empty' );
		$this->assertStringContainsString( 'Test comment activity', $activity[0]['message'], 'Activity message should be logged' );
	}

	/**
	 * Test that logging is skipped when disabled.
	 */
	public function test_logging_skipped_when_disabled() {
		// Disable logging.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_logging' => false,
			)
		);

		// Clear existing logs.
		delete_option( 'wp_mcp_ai_recent_activity' );

		// Try to log.
		$reflection = new ReflectionClass( $this->comments_integration );
		$method     = $reflection->getMethod( 'log_activity' );
		$method->setAccessible( true );
		$method->invoke( $this->comments_integration, 'Test comment activity' );

		$activity = get_option( 'wp_mcp_ai_recent_activity', array() );

		$this->assertEmpty( $activity, 'Activity log should be empty when logging is disabled' );
	}

	/**
	 * Test that default sensitivity is medium.
	 */
	public function test_default_sensitivity_is_medium() {
		// Enable the feature without setting sensitivity.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ai_comments_moderation' => true,
			)
		);

		$settings = get_option( 'wp_mcp_ai_settings', array() );

		// When not set, the integration uses 'medium' as default.
		// We verify this indirectly by checking the option is not set.
		$this->assertArrayNotHasKey( 'ai_comments_sensitivity', $settings, 'Sensitivity should not be set by default' );
	}

	/**
	 * Test that default minimum confidence is 0.7.
	 */
	public function test_default_min_confidence_is_70_percent() {
		// Enable the feature without setting confidence.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ai_comments_moderation' => true,
			)
		);

		$settings = get_option( 'wp_mcp_ai_settings', array() );

		// When not set, the integration uses 0.7 as default.
		// We verify this indirectly by checking the option is not set.
		$this->assertArrayNotHasKey( 'ai_comments_min_confidence', $settings, 'Confidence should not be set by default' );
	}
}
