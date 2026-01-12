<?php
/**
 * Tests for ECA Management Tools
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for ECA management functionality.
 */
class Test_ECA_Management extends WP_UnitTestCase {
	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Enable ECA management in settings.
		$settings                          = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_eca_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );
	}

	/**
	 * Test ECA post type is registered.
	 */
	public function test_eca_post_type_registered() {
		// Trigger init action to register post types.
		do_action( 'init' );

		$post_type_exists = post_type_exists( 'mcp_ai_eca' );
		$this->assertTrue( $post_type_exists, 'ECA post type should be registered' );
	}

	/**
	 * Test ECA booking post type is registered.
	 */
	public function test_eca_booking_post_type_registered() {
		// Trigger init action to register post types.
		do_action( 'init' );

		$post_type_exists = post_type_exists( 'mcp_ai_eca_booking' );
		$this->assertTrue( $post_type_exists, 'ECA booking post type should be registered' );
	}

	/**
	 * Test Create ECA tool is available.
	 */
	public function test_create_eca_tool_available() {
		// Require the tool file.
		require_once dirname( __DIR__ ) . '/addons/pro/includes/tools/class-wp-mcp-ai-tool-create-eca.php';

		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Create_ECA' ), 'Create ECA tool class should exist' );
		$this->assertTrue( WP_MCP_AI_Tool_Create_ECA::is_available(), 'Create ECA tool should be available when enabled' );
	}

	/**
	 * Test Create ECA tool has correct slug.
	 */
	public function test_create_eca_tool_slug() {
		require_once dirname( __DIR__ ) . '/addons/pro/includes/tools/class-wp-mcp-ai-tool-create-eca.php';

		$tool = new WP_MCP_AI_Tool_Create_ECA();
		$this->assertEquals( 'create_eca', $tool->get_slug(), 'Create ECA tool should have correct slug' );
	}

	/**
	 * Test List ECAs tool is available.
	 */
	public function test_list_ecas_tool_available() {
		require_once dirname( __DIR__ ) . '/addons/pro/includes/tools/class-wp-mcp-ai-tool-list-ecas.php';

		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_List_ECAs' ), 'List ECAs tool class should exist' );
		$this->assertTrue( WP_MCP_AI_Tool_List_ECAs::is_available(), 'List ECAs tool should be available when enabled' );
	}

	/**
	 * Test Update ECA tool is available.
	 */
	public function test_update_eca_tool_available() {
		require_once dirname( __DIR__ ) . '/addons/pro/includes/tools/class-wp-mcp-ai-tool-update-eca.php';

		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Update_ECA' ), 'Update ECA tool class should exist' );
		$this->assertTrue( WP_MCP_AI_Tool_Update_ECA::is_available(), 'Update ECA tool should be available when enabled' );
	}

	/**
	 * Test Delete ECA tool is available.
	 */
	public function test_delete_eca_tool_available() {
		require_once dirname( __DIR__ ) . '/addons/pro/includes/tools/class-wp-mcp-ai-tool-delete-eca.php';

		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Delete_ECA' ), 'Delete ECA tool class should exist' );
		$this->assertTrue( WP_MCP_AI_Tool_Delete_ECA::is_available(), 'Delete ECA tool should be available when enabled' );
	}

	/**
	 * Test Manage ECA Bookings tool is available.
	 */
	public function test_manage_eca_bookings_tool_available() {
		require_once dirname( __DIR__ ) . '/addons/pro/includes/tools/class-wp-mcp-ai-tool-manage-eca-bookings.php';

		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Manage_ECA_Bookings' ), 'Manage ECA Bookings tool class should exist' );
		$this->assertTrue( WP_MCP_AI_Tool_Manage_ECA_Bookings::is_available(), 'Manage ECA Bookings tool should be available when enabled' );
	}

	/**
	 * Test iSAMS Sync tool is available.
	 */
	public function test_isams_sync_tool_available() {
		require_once dirname( __DIR__ ) . '/addons/pro/includes/tools/class-wp-mcp-ai-tool-isams-sync.php';

		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_ISAMS_Sync' ), 'iSAMS Sync tool class should exist' );
		$this->assertTrue( WP_MCP_AI_Tool_ISAMS_Sync::is_available(), 'iSAMS Sync tool should be available when enabled' );
	}

	/**
	 * Test creating an ECA via the tool.
	 */
	public function test_create_eca_execution() {
		require_once dirname( __DIR__ ) . '/addons/pro/includes/tools/class-wp-mcp-ai-tool-create-eca.php';

		// Trigger init to register post types.
		do_action( 'init' );

		$tool = new WP_MCP_AI_Tool_Create_ECA();

		// Create admin user for context.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$arguments = array(
			'name'         => 'Chess Club',
			'description'  => 'A club for chess enthusiasts',
			'eca_code'     => '9',
			'eca_type'     => 'club',
			'day'          => 'Tuesday',
			'time_start'   => '14:45',
			'time_end'     => '15:45',
			'venue'        => 'Room 4',
			'year_groups'  => array( 'Year 7', 'Year 8' ),
			'max_capacity' => 20,
			'is_paid'      => true,
			'cost'         => 'Rs 7,500 per term',
		);

		$context = array( 'user_id' => $user_id );

		$result = $tool->execute( $arguments, $context );

		$this->assertIsArray( $result, 'Tool should return an array' );
		$this->assertTrue( $result['success'], 'ECA creation should succeed' );
		$this->assertArrayHasKey( 'eca_id', $result, 'Result should contain eca_id' );
		$this->assertGreaterThan( 0, $result['eca_id'], 'ECA ID should be positive' );

		// Verify the post was created.
		$post = get_post( $result['eca_id'] );
		$this->assertNotNull( $post, 'Post should exist' );
		$this->assertEquals( 'mcp_ai_eca', $post->post_type, 'Post should be ECA type' );
		$this->assertEquals( 'Chess Club', $post->post_title, 'Post title should match' );
	}

	/**
	 * Test listing ECAs via the tool.
	 */
	public function test_list_ecas_execution() {
		require_once dirname( __DIR__ ) . '/addons/pro/includes/tools/class-wp-mcp-ai-tool-list-ecas.php';

		// Trigger init to register post types.
		do_action( 'init' );

		// Create a test ECA.
		$eca_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_eca',
				'post_title'   => 'Test ECA',
				'post_status'  => 'publish',
				'post_content' => 'Test description',
			)
		);
		update_post_meta( $eca_id, '_eca_type', 'club' );

		$tool = new WP_MCP_AI_Tool_List_ECAs();

		// Create user for context.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$arguments = array( 'limit' => 50 );
		$context   = array( 'user_id' => $user_id );

		$result = $tool->execute( $arguments, $context );

		$this->assertIsArray( $result, 'Tool should return an array' );
		$this->assertTrue( $result['success'], 'List should succeed' );
		$this->assertArrayHasKey( 'ecas', $result, 'Result should contain ecas array' );
		$this->assertGreaterThan( 0, $result['count'], 'Should return at least one ECA' );
	}
}
