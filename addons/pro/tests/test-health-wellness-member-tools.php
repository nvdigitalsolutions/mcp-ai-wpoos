<?php
/**
 * Tests for Health & Wellness member management tools.
 *
 * @package WP_MCP_AI
 */

/**
 * Test member management tools (get_member, update_member, delete_member).
 */
class Test_Health_Wellness_Member_Tools extends WP_UnitTestCase {
	/**
	 * Test member ID.
	 *
	 * @var int
	 */
	private $test_member_id;

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	private $test_user_id;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Enable health wellness management.
		$settings                                      = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_health_wellness_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Create test user.
		$this->test_user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		// Create test member.
		$this->test_member_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_member',
				'post_title'  => 'John Doe',
				'post_status' => 'publish',
				'post_author' => $this->test_user_id,
			)
		);

		// Set member metadata.
		update_post_meta( $this->test_member_id, '_member_date_of_birth', '1990-05-15' );
		update_post_meta( $this->test_member_id, '_member_gender', 'Male' );
		update_post_meta( $this->test_member_id, '_member_blood_type', 'O+' );
		update_post_meta( $this->test_member_id, '_member_email', 'john@example.com' );
		update_post_meta( $this->test_member_id, '_member_phone', '555-1234' );

		// Set member type taxonomy.
		wp_set_object_terms( $this->test_member_id, 'person', 'mcp_ai_member_type' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		// Clean up test data.
		if ( $this->test_member_id ) {
			wp_delete_post( $this->test_member_id, true );
		}

		parent::tearDown();
	}

	/**
	 * Test get_member tool retrieves member details correctly.
	 */
	public function test_get_member_retrieves_details() {
		$tool = new WP_MCP_AI_Tool_Get_Member();

		$arguments = array(
			'member_id' => $this->test_member_id,
		);

		$context = array(
			'user_id' => $this->test_user_id,
		);

		$result = $tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'member', $result );

		$member = $result['member'];
		$this->assertEquals( $this->test_member_id, $member['id'] );
		$this->assertEquals( 'John Doe', $member['name'] );
		$this->assertEquals( 'person', $member['type'] );
		$this->assertEquals( '1990-05-15', $member['date_of_birth'] );
		$this->assertEquals( 'Male', $member['gender'] );
		$this->assertEquals( 'O+', $member['blood_type'] );
		$this->assertEquals( 'john@example.com', $member['email'] );
		$this->assertEquals( '555-1234', $member['phone'] );
		$this->assertArrayHasKey( 'related_records', $member );
	}

	/**
	 * Test get_member requires member_id parameter.
	 */
	public function test_get_member_requires_member_id() {
		$tool = new WP_MCP_AI_Tool_Get_Member();

		$arguments = array();

		$context = array(
			'user_id' => $this->test_user_id,
		);

		$result = $tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_missing_member_id', $result->get_error_code() );
	}

	/**
	 * Test get_member validates member exists.
	 */
	public function test_get_member_validates_member_exists() {
		$tool = new WP_MCP_AI_Tool_Get_Member();

		$arguments = array(
			'member_id' => 999999,
		);

		$context = array(
			'user_id' => $this->test_user_id,
		);

		$result = $tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_member_not_found', $result->get_error_code() );
	}

	/**
	 * Test update_member updates member name.
	 */
	public function test_update_member_updates_name() {
		$tool = new WP_MCP_AI_Tool_Update_Member();

		$arguments = array(
			'member_id' => $this->test_member_id,
			'name'      => 'Jane Smith',
		);

		$context = array(
			'user_id' => $this->test_user_id,
		);

		$result = $tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'updated_fields', $result );
		$this->assertContains( 'name', $result['updated_fields'] );

		// Verify the update.
		$member = get_post( $this->test_member_id );
		$this->assertEquals( 'Jane Smith', $member->post_title );
	}

	/**
	 * Test update_member updates multiple fields.
	 */
	public function test_update_member_updates_multiple_fields() {
		$tool = new WP_MCP_AI_Tool_Update_Member();

		$arguments = array(
			'member_id'  => $this->test_member_id,
			'email'      => 'newemail@example.com',
			'phone'      => '555-9999',
			'blood_type' => 'A+',
		);

		$context = array(
			'user_id' => $this->test_user_id,
		);

		$result = $tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertCount( 3, $result['updated_fields'] );

		// Verify the updates.
		$this->assertEquals( 'newemail@example.com', get_post_meta( $this->test_member_id, '_member_email', true ) );
		$this->assertEquals( '555-9999', get_post_meta( $this->test_member_id, '_member_phone', true ) );
		$this->assertEquals( 'A+', get_post_meta( $this->test_member_id, '_member_blood_type', true ) );
	}

	/**
	 * Test update_member validates email format.
	 */
	public function test_update_member_validates_email() {
		$tool = new WP_MCP_AI_Tool_Update_Member();

		$arguments = array(
			'member_id' => $this->test_member_id,
			'email'     => 'invalid-email',
		);

		$context = array(
			'user_id' => $this->test_user_id,
		);

		$result = $tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_email', $result->get_error_code() );
	}

	/**
	 * Test update_member requires permission.
	 */
	public function test_update_member_requires_permission() {
		// Create another user without permission.
		$other_user_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);

		$tool = new WP_MCP_AI_Tool_Update_Member();

		$arguments = array(
			'member_id' => $this->test_member_id,
			'name'      => 'Unauthorized Update',
		);

		$context = array(
			'user_id' => $other_user_id,
		);

		$result = $tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test delete_member deletes member.
	 */
	public function test_delete_member_deletes_member() {
		$tool = new WP_MCP_AI_Tool_Delete_Member();

		$arguments = array(
			'member_id' => $this->test_member_id,
			'force'     => true,
		);

		$context = array(
			'user_id' => $this->test_user_id,
		);

		$result = $tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( $this->test_member_id, $result['member_id'] );

		// Verify member is deleted.
		$member = get_post( $this->test_member_id );
		$this->assertNull( $member );

		// Prevent tearDown from trying to delete again.
		$this->test_member_id = null;
	}

	/**
	 * Test delete_member moves to trash by default.
	 */
	public function test_delete_member_moves_to_trash() {
		$tool = new WP_MCP_AI_Tool_Delete_Member();

		$arguments = array(
			'member_id' => $this->test_member_id,
		);

		$context = array(
			'user_id' => $this->test_user_id,
		);

		$result = $tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		// Verify member is in trash.
		$member = get_post( $this->test_member_id );
		$this->assertEquals( 'trash', $member->post_status );
	}

	/**
	 * Test delete_member deletes related records when requested.
	 */
	public function test_delete_member_deletes_related_records() {
		// Create related allergy record.
		$allergy_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_allergy',
				'post_title'  => 'Peanuts',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $allergy_id, '_allergy_member_id', $this->test_member_id );

		// Create related prescription.
		$prescription_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_prescription',
				'post_title'  => 'Test Medication',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $prescription_id, '_prescription_member_id', $this->test_member_id );

		$tool = new WP_MCP_AI_Tool_Delete_Member();

		$arguments = array(
			'member_id'      => $this->test_member_id,
			'delete_related' => true,
			'force'          => true,
		);

		$context = array(
			'user_id' => $this->test_user_id,
		);

		$result = $tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'deleted_related', $result );

		// Verify related records are deleted.
		$this->assertNull( get_post( $allergy_id ) );
		$this->assertNull( get_post( $prescription_id ) );

		// Prevent tearDown from trying to delete again.
		$this->test_member_id = null;
	}

	/**
	 * Test delete_member requires permission.
	 */
	public function test_delete_member_requires_permission() {
		// Create another user without permission.
		$other_user_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);

		$tool = new WP_MCP_AI_Tool_Delete_Member();

		$arguments = array(
			'member_id' => $this->test_member_id,
		);

		$context = array(
			'user_id' => $other_user_id,
		);

		$result = $tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test all tools check if health wellness management is enabled.
	 */
	public function test_tools_check_feature_enabled() {
		// Disable health wellness management.
		$settings                                      = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_health_wellness_management'] = false;
		update_option( 'wp_mcp_ai_settings', $settings );

		$this->assertFalse( WP_MCP_AI_Tool_Get_Member::is_available() );
		$this->assertFalse( WP_MCP_AI_Tool_Update_Member::is_available() );
		$this->assertFalse( WP_MCP_AI_Tool_Delete_Member::is_available() );

		// Re-enable for other tests.
		$settings['enable_health_wellness_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );
	}
}
