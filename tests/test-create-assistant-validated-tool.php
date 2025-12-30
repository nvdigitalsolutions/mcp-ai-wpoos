<?php
/**
 * Tests for Create Assistant Validated Tool
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Tool_Create_Assistant_Validated
 *
 * Tests for the validated create_assistant tool using Symfony Validator.
 */
class Test_WP_MCP_AI_Tool_Create_Assistant_Validated extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Create_Assistant_Validated
	 */
	private $tool;

	/**
	 * Test user ID (editor role).
	 *
	 * @var int
	 */
	private $editor_user_id;

	/**
	 * Test user ID (subscriber role).
	 *
	 * @var int
	 */
	private $subscriber_user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load dependencies.
		require_once dirname( __DIR__ ) . '/includes/validators/class-wp-mcp-ai-validator-service.php';
		require_once dirname( __DIR__ ) . '/includes/validators/class-wp-mcp-ai-validated-tool.php';
		require_once dirname( __DIR__ ) . '/includes/validators/arguments/class-create-assistant-arguments.php';
		require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-cron-manager.php';
		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-create-assistant.php';
		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-create-assistant-validated.php';

		// Create test user with edit_posts capability.
		$this->editor_user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);

		// Create test user without edit_posts capability.
		$this->subscriber_user_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);

		wp_set_current_user( $this->editor_user_id );

		$this->tool = new WP_MCP_AI_Tool_Create_Assistant_Validated();
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		// Delete any created assistants.
		$assistants = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'posts_per_page' => -1,
				'post_status'    => 'any',
			)
		);

		foreach ( $assistants as $assistant ) {
			wp_delete_post( $assistant->ID, true );
		}

		// Clear all scheduled cron events.
		$crons = _get_cron_array();
		if ( ! empty( $crons ) ) {
			foreach ( $crons as $timestamp => $cron ) {
				foreach ( $cron as $hook => $events ) {
					foreach ( $events as $key => $event ) {
						wp_unschedule_event( $timestamp, $hook, $event['args'] );
					}
				}
			}
		}

		parent::tearDown();
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertEquals( 'create_assistant_validated', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertStringContainsString( 'Validated', $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
		$this->assertStringContainsString( 'Symfony Validator', $this->tool->get_description() );
	}

	/**
	 * Test creating an assistant with minimal valid data.
	 */
	public function test_create_assistant_with_minimal_data() {
		$arguments = array(
			'title' => 'Test Tax Assistant',
		);

		$context = array( 'user_id' => $this->editor_user_id );
		$result  = $this->tool->execute( $arguments, $context );

		// The tool should return either an array (success) or WP_Error.
		// Since we don't have all the assistant infrastructure set up,.
		// we just verify it's not a validation error.
		if ( is_wp_error( $result ) ) {
			// If it's an error, it should NOT be a validation error.
			$this->assertNotEquals( 'validation_failed', $result->get_error_code() );
		} else {
			// If successful, verify it's an array with expected keys.
			$this->assertIsArray( $result );
		}
	}

	/**
	 * Test validation fails when title is missing.
	 */
	public function test_validation_fails_without_title() {
		$arguments = array(
			'description' => 'A test assistant',
		);

		$context = array( 'user_id' => $this->editor_user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error for missing title' );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with empty title.
	 */
	public function test_validation_fails_with_empty_title() {
		$arguments = array(
			'title' => '',
		);

		$context = array( 'user_id' => $this->editor_user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error for empty title' );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with title exceeding maximum length.
	 */
	public function test_validation_fails_with_long_title() {
		$arguments = array(
			'title' => str_repeat( 'A', 201 ), // 201 characters, exceeds 200 limit.
		);

		$context = array( 'user_id' => $this->editor_user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error for title too long' );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
	}

	/**
	 * Test creating assistant with professions and regions.
	 */
	public function test_create_assistant_with_professions_and_regions() {
		$arguments = array(
			'title'       => 'Jamaica Tax Advisor',
			'professions' => array( 'tax_advisor', 'financial_advisor' ),
			'regions'     => array( 'jamaica' ),
		);

		$context = array( 'user_id' => $this->editor_user_id );
		$result  = $this->tool->execute( $arguments, $context );

		// Verify it's not a validation error.
		if ( is_wp_error( $result ) ) {
			$this->assertNotEquals( 'validation_failed', $result->get_error_code() );
		} else {
			$this->assertIsArray( $result );
		}
	}

	/**
	 * Test validation fails with too many professions.
	 */
	public function test_validation_fails_with_too_many_professions() {
		$arguments = array(
			'title'       => 'Multi-profession Assistant',
			'professions' => array( 'tax_advisor', 'financial_advisor', 'legal_advisor', 'customs_broker' ), // 4 professions, exceeds 3 limit.
		);

		$context = array( 'user_id' => $this->editor_user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error for too many professions' );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid profession.
	 */
	public function test_validation_fails_with_invalid_profession() {
		$arguments = array(
			'title'       => 'Invalid Profession Assistant',
			'professions' => array( 'invalid_profession' ),
		);

		$context = array( 'user_id' => $this->editor_user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error for invalid profession' );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with too many regions.
	 */
	public function test_validation_fails_with_too_many_regions() {
		$arguments = array(
			'title'   => 'Multi-region Assistant',
			'regions' => array( 'jamaica', 'sri_lanka', 'global' ), // 3 regions, exceeds 2 limit.
		);

		$context = array( 'user_id' => $this->editor_user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error for too many regions' );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid region.
	 */
	public function test_validation_fails_with_invalid_region() {
		$arguments = array(
			'title'   => 'Invalid Region Assistant',
			'regions' => array( 'invalid_region' ),
		);

		$context = array( 'user_id' => $this->editor_user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error for invalid region' );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with invalid email.
	 */
	public function test_validation_fails_with_invalid_email() {
		$arguments = array(
			'title'              => 'Test Assistant',
			'notification_email' => 'not-an-email',
		);

		$context = array( 'user_id' => $this->editor_user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error for invalid email' );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
	}

	/**
	 * Test creating assistant with valid email.
	 */
	public function test_create_assistant_with_valid_email() {
		$arguments = array(
			'title'              => 'Test Assistant',
			'notification_email' => 'test@example.com',
		);

		$context = array( 'user_id' => $this->editor_user_id );
		$result  = $this->tool->execute( $arguments, $context );

		// Verify it's not a validation error.
		if ( is_wp_error( $result ) ) {
			$this->assertNotEquals( 'validation_failed', $result->get_error_code() );
		} else {
			$this->assertIsArray( $result );
		}
	}

	/**
	 * Test validation fails with too many attachment IDs.
	 */
	public function test_validation_fails_with_too_many_attachments() {
		// Create 21 attachment IDs (exceeds 20 limit).
		$attachment_ids = range( 1, 21 );

		$arguments = array(
			'title'          => 'Test Assistant',
			'attachment_ids' => $attachment_ids,
		);

		$context = array( 'user_id' => $this->editor_user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error for too many attachments' );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
	}

	/**
	 * Test permission check fails for user without edit_posts capability.
	 */
	public function test_permission_check_fails_without_capability() {
		$arguments = array(
			'title' => 'Test Assistant',
		);

		$context = array( 'user_id' => $this->subscriber_user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error for user without permission' );
		// The error should be a permission error, not a validation error.
		$this->assertNotEquals( 'validation_failed', $result->get_error_code() );
	}

	/**
	 * Test creating assistant with full configuration.
	 */
	public function test_create_assistant_with_full_configuration() {
		$arguments = array(
			'title'          => 'Jamaica Tax & Customs Assistant',
			'description'    => 'An AI assistant specialized in Jamaican tax law and customs regulations.',
			'system_prompt'  => 'You are an expert in Jamaican tax and customs regulations.',
			'professions'    => array( 'tax_advisor', 'customs_broker' ),
			'regions'        => array( 'jamaica' ),
			'industry_focus' => 'Import/Export Business',
		);

		$context = array( 'user_id' => $this->editor_user_id );
		$result  = $this->tool->execute( $arguments, $context );

		// Verify it's not a validation error.
		if ( is_wp_error( $result ) ) {
			$this->assertNotEquals( 'validation_failed', $result->get_error_code() );
		} else {
			$this->assertIsArray( $result );
		}
	}

	/**
	 * Test tool capability flags.
	 */
	public function test_capability_flags() {
		$flags = $this->tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'requires-capability', $flags );
	}
}
