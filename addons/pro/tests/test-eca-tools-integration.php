<?php
/**
 * Tests for ECA tools integration with CPT.
 *
 * @package WP_MCP_AI
 */

/**
 * Test ECA tool functionality with the new CPT structure.
 */
class Test_ECA_Tools_Integration extends WP_UnitTestCase {
	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_user;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create admin user.
		$this->admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user );

		// Enable ECA management.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_eca_management' => true,
			)
		);

		// Load ECA CPT class.
		if ( ! class_exists( 'WP_MCP_AI_ECA_CPT' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-eca-cpt.php';
		}

		// Register post types.
		WP_MCP_AI_ECA_CPT::register_post_types();

		// Load tools.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Create_ECA' ) ) {
			require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-create-eca.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_List_ECAs' ) ) {
			require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-list-ecas.php';
		}
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test create_eca tool creates ECA with proper meta fields.
	 */
	public function test_create_eca_tool() {
		$arguments = array(
			'name'              => 'Chess Club',
			'eca_code'          => 'CHESS-001',
			'description'       => 'Learn and play chess',
			'eca_type'          => 'club',
			'day'               => 'Monday',
			'start_time'        => '3:30 PM',
			'end_time'          => '4:30 PM',
			'venue'             => 'Library',
			'year_groups'       => array( 'Year 7', 'Year 8', 'Year 9' ),
			'max_students'      => 20,
			'teachers'          => array( 'Mr. Smith' ),
			'is_paid'           => false,
			'requires_audition' => false,
			'booking_type'      => 'first_come_first_served',
			'status'            => 'active',
		);

		$tool   = new WP_MCP_AI_Tool_Create_ECA();
		$result = $tool->execute( $arguments, array( 'user_id' => $this->admin_user ) );

		// Verify tool execution succeeded.
		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertArrayHasKey( 'eca_id', $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );

		$eca_id = $result['eca_id'];

		// Verify the post was created.
		$post = get_post( $eca_id );
		$this->assertNotNull( $post );
		$this->assertEquals( 'Chess Club', $post->post_title );
		$this->assertEquals( 'mcp_ai_eca', $post->post_type );

		// Verify meta fields match what the metaboxes expect.
		$this->assertEquals( 'CHESS-001', get_post_meta( $eca_id, '_eca_code', true ) );
		$this->assertEquals( 'club', get_post_meta( $eca_id, '_eca_type', true ) );
		$this->assertEquals( 'Monday', get_post_meta( $eca_id, '_eca_day', true ) );
		$this->assertEquals( '3:30 PM', get_post_meta( $eca_id, '_eca_start_time', true ) );
		$this->assertEquals( '4:30 PM', get_post_meta( $eca_id, '_eca_end_time', true ) );
		$this->assertEquals( 'Library', get_post_meta( $eca_id, '_eca_venue', true ) );
		$this->assertEquals( 20, get_post_meta( $eca_id, '_eca_max_students', true ) );
		$this->assertEquals( 'no', get_post_meta( $eca_id, '_eca_is_paid', true ) );
		$this->assertEquals( 'active', get_post_meta( $eca_id, '_eca_status', true ) );
		$this->assertEquals( 'first_come_first_served', get_post_meta( $eca_id, '_eca_booking_type', true ) );
		$this->assertEquals( 0, get_post_meta( $eca_id, '_eca_current_enrollment', true ) );

		// Verify arrays are properly stored.
		$year_groups = get_post_meta( $eca_id, '_eca_year_groups', true );
		$this->assertIsArray( $year_groups );
		$this->assertCount( 3, $year_groups );
		$this->assertContains( 'Year 7', $year_groups );

		$teachers = get_post_meta( $eca_id, '_eca_teachers', true );
		$this->assertIsArray( $teachers );
		$this->assertContains( 'Mr. Smith', $teachers );

		// Clean up.
		wp_delete_post( $eca_id, true );
	}

	/**
	 * Test list_ecas tool retrieves ECAs with correct data.
	 */
	public function test_list_ecas_tool() {
		// Create test ECAs using the tool.
		$tool = new WP_MCP_AI_Tool_Create_ECA();

		$eca1_result = $tool->execute(
			array(
				'name'         => 'Football Squad',
				'eca_type'     => 'sport_squad',
				'day'          => 'Tuesday',
				'max_students' => 25,
				'status'       => 'active',
			),
			array( 'user_id' => $this->admin_user )
		);

		$eca2_result = $tool->execute(
			array(
				'name'         => 'Drama Club',
				'eca_type'     => 'club',
				'day'          => 'Wednesday',
				'max_students' => 15,
				'status'       => 'active',
			),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertNotInstanceOf( 'WP_Error', $eca1_result );
		$this->assertNotInstanceOf( 'WP_Error', $eca2_result );

		// Now test the list tool.
		$list_tool = new WP_MCP_AI_Tool_List_ECAs();
		$result    = $list_tool->execute( array(), array( 'user_id' => $this->admin_user ) );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertArrayHasKey( 'ecas', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertTrue( $result['success'] );
		$this->assertGreaterThanOrEqual( 2, $result['total'] );
		$this->assertCount( 2, $result['ecas'] );

		// Verify data structure.
		$eca = $result['ecas'][0];
		$this->assertArrayHasKey( 'eca_id', $eca );
		$this->assertArrayHasKey( 'name', $eca );
		$this->assertArrayHasKey( 'type', $eca );
		$this->assertArrayHasKey( 'day', $eca );
		$this->assertArrayHasKey( 'max_students', $eca );
		$this->assertArrayHasKey( 'current_enrollment', $eca );
		$this->assertArrayHasKey( 'available_spots', $eca );
		$this->assertArrayHasKey( 'is_full', $eca );
		$this->assertArrayHasKey( 'status', $eca );

		// Clean up.
		wp_delete_post( $eca1_result['eca_id'], true );
		wp_delete_post( $eca2_result['eca_id'], true );
	}

	/**
	 * Test filtering ECAs by type.
	 */
	public function test_list_ecas_filter_by_type() {
		$tool = new WP_MCP_AI_Tool_Create_ECA();

		// Create club.
		$club_result = $tool->execute(
			array(
				'name'     => 'Art Club',
				'eca_type' => 'club',
				'status'   => 'active',
			),
			array( 'user_id' => $this->admin_user )
		);

		// Create sport squad.
		$sport_result = $tool->execute(
			array(
				'name'     => 'Basketball Squad',
				'eca_type' => 'sport_squad',
				'status'   => 'active',
			),
			array( 'user_id' => $this->admin_user )
		);

		// List only clubs.
		$list_tool = new WP_MCP_AI_Tool_List_ECAs();
		$result    = $list_tool->execute(
			array( 'eca_type' => 'club' ),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );

		// Verify all returned ECAs are clubs.
		foreach ( $result['ecas'] as $eca ) {
			$this->assertEquals( 'club', $eca['type'] );
		}

		// Clean up.
		wp_delete_post( $club_result['eca_id'], true );
		wp_delete_post( $sport_result['eca_id'], true );
	}

	/**
	 * Test ECA with paid activity.
	 */
	public function test_create_paid_eca() {
		$tool = new WP_MCP_AI_Tool_Create_ECA();

		$result = $tool->execute(
			array(
				'name'        => 'Music Lessons',
				'eca_type'    => 'activity',
				'is_paid'     => true,
				'cost'        => 50.00,
				'cost_period' => 'term',
				'status'      => 'active',
			),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertTrue( $result['is_paid'] );
		$this->assertEquals( 50.0, $result['cost'] );

		$eca_id = $result['eca_id'];

		// Verify meta fields.
		$this->assertEquals( 'yes', get_post_meta( $eca_id, '_eca_is_paid', true ) );
		$this->assertEquals( 50.0, (float) get_post_meta( $eca_id, '_eca_cost', true ) );
		$this->assertEquals( 'term', get_post_meta( $eca_id, '_eca_cost_period', true ) );

		// Clean up.
		wp_delete_post( $eca_id, true );
	}

	/**
	 * Test ECA capacity and enrollment tracking.
	 */
	public function test_eca_capacity_tracking() {
		$tool = new WP_MCP_AI_Tool_Create_ECA();

		$result = $tool->execute(
			array(
				'name'         => 'Limited ECA',
				'max_students' => 10,
				'status'       => 'active',
			),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 10, $result['max_students'] );
		$this->assertEquals( 0, $result['current_enrollment'] );

		$eca_id = $result['eca_id'];

		// Simulate enrollment by updating meta.
		update_post_meta( $eca_id, '_eca_current_enrollment', 7 );

		// Verify via list tool.
		$list_tool = new WP_MCP_AI_Tool_List_ECAs();
		$list_result = $list_tool->execute( array(), array( 'user_id' => $this->admin_user ) );

		$found_eca = null;
		foreach ( $list_result['ecas'] as $eca ) {
			if ( $eca['eca_id'] === $eca_id ) {
				$found_eca = $eca;
				break;
			}
		}

		$this->assertNotNull( $found_eca );
		$this->assertEquals( 7, $found_eca['current_enrollment'] );
		$this->assertEquals( 3, $found_eca['available_spots'] );
		$this->assertFalse( $found_eca['is_full'] );

		// Clean up.
		wp_delete_post( $eca_id, true );
	}
}
