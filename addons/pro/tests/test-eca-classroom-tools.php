<?php
/**
 * Tests for the Google Classroom ECA tools.
 *
 * Covers availability gating, schema contracts, capability enforcement, and
 * envelope behaviour with a mocked HTTP transport — no live Google calls.
 *
 * @package WP_MCP_AI
 */

/**
 * Google Classroom ECA tools test case.
 */
class Test_ECA_Classroom_Tools extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_user;

	/**
	 * Seeded connection ID.
	 *
	 * @var string
	 */
	private $connection_id;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user );

		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_eca_management'            => true,
				'enable_eca_classroom_integration' => true,
			)
		);

		if ( ! class_exists( 'WP_MCP_AI_ECA_CPT' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-eca-cpt.php';
		}

		WP_MCP_AI_ECA_CPT::register_post_types();

		require_once dirname( __DIR__ ) . '/includes/eca/class-wp-mcp-ai-eca-classroom-helper.php';
		require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-classroom-scopes.php';
		require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-oauth-service.php';
		require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-classroom-client.php';
		require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-classroom-credentials.php';

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Classroom Test',
				'url'             => 'https://classroom.googleapis.com/v1',
				'connection_type' => 'google_classroom',
				'auth_type'       => 'none',
				'client_id'       => 'test-client-id.apps.googleusercontent.com',
				'client_secret'   => 'test-client-secret',
				'refresh_token'   => 'test-refresh-token',
				'user_email'      => 'teacher@example.com',
				'scope_profile'   => 'write',
				'granted_scopes'  => WP_MCP_AI_Google_Classroom_Scopes::get_profile_scope_string( 'write' ),
				'enabled'         => true,
			)
		);

		if ( ! is_wp_error( $result ) ) {
			$this->connection_id = $result;
		}

		// Mock every HTTP call: token minting succeeds and Classroom endpoints
		// return fixtures, so no test reaches the real network.
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Mock HTTP transport.
	 *
	 * @param false|array|WP_Error $preempt Preempt value.
	 * @param array                $args    Request args.
	 * @param string               $url     Request URL.
	 * @return array|false Mock response or false to proceed.
	 */
	public function mock_http( $preempt, $args, $url ) {
		if ( false !== strpos( $url, 'oauth2.googleapis.com/token' ) ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'access_token' => 'tok',
						'expires_in'   => 3600,
					)
				),
			);
		}

		if ( false !== strpos( $url, 'classroom.googleapis.com' ) ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'courses' => array() ) ),
			);
		}

		return false;
	}

	/**
	 * Load a tool class from the ECA management folder.
	 *
	 * @param string $tool_class Tool class name.
	 * @return void
	 */
	private function load_tool( $tool_class ) {
		$slug = strtolower( str_replace( '_', '-', substr( $tool_class, strlen( 'WP_MCP_AI_Tool_' ) ) ) );

		require_once dirname( __DIR__ ) . '/includes/tools/eca-management/class-wp-mcp-ai-tool-' . $slug . '.php';
	}

	/**
	 * The integration must be unavailable when the feature flag is off.
	 */
	public function test_tools_unavailable_without_flag() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_eca_management' => true,
			)
		);

		$this->load_tool( 'WP_MCP_AI_Tool_List_Classroom_Courses' );

		$this->assertFalse( WP_MCP_AI_Tool_List_Classroom_Courses::is_available() );
	}

	/**
	 * All twelve tools must be available when the flag is on.
	 */
	public function test_tools_available_with_flag() {
		$classes = array(
			'WP_MCP_AI_Tool_List_Classroom_Courses',
			'WP_MCP_AI_Tool_Sync_Classroom_Roster_To_Students',
			'WP_MCP_AI_Tool_Sync_Classroom_Courses_To_ECAs',
			'WP_MCP_AI_Tool_Link_Classroom_Course_To_ECA',
			'WP_MCP_AI_Tool_Create_Classroom_Course',
			'WP_MCP_AI_Tool_Update_Classroom_Course',
			'WP_MCP_AI_Tool_Post_Classroom_Announcement',
			'WP_MCP_AI_Tool_Create_Classroom_Coursework',
			'WP_MCP_AI_Tool_List_Classroom_Submissions',
			'WP_MCP_AI_Tool_Classroom_Course_Analytics',
			'WP_MCP_AI_Tool_List_Classroom_Guardians',
			'WP_MCP_AI_Tool_Manage_Classroom_Push_Watch',
		);

		foreach ( $classes as $tool_class ) {
			$this->load_tool( $tool_class );
			$this->assertTrue( $tool_class::is_available(), $tool_class . ' should be available' );
		}
	}

	/**
	 * Every tool schema must expose connection_id.
	 */
	public function test_all_schemas_expose_connection_id() {
		$classes = array(
			'WP_MCP_AI_Tool_List_Classroom_Courses',
			'WP_MCP_AI_Tool_Sync_Classroom_Roster_To_Students',
			'WP_MCP_AI_Tool_Sync_Classroom_Courses_To_ECAs',
			'WP_MCP_AI_Tool_Link_Classroom_Course_To_ECA',
			'WP_MCP_AI_Tool_Create_Classroom_Course',
			'WP_MCP_AI_Tool_Update_Classroom_Course',
			'WP_MCP_AI_Tool_Post_Classroom_Announcement',
			'WP_MCP_AI_Tool_Create_Classroom_Coursework',
			'WP_MCP_AI_Tool_List_Classroom_Submissions',
			'WP_MCP_AI_Tool_Classroom_Course_Analytics',
			'WP_MCP_AI_Tool_List_Classroom_Guardians',
			'WP_MCP_AI_Tool_Manage_Classroom_Push_Watch',
		);

		foreach ( $classes as $tool_class ) {
			$this->load_tool( $tool_class );

			$tool   = new $tool_class();
			$schema = $tool->get_parameters_schema();

			$this->assertArrayHasKey( 'connection_id', $schema['properties'], $tool_class . ' schema must expose connection_id' );
		}
	}

	/**
	 * A subscriber must be blocked from every tool.
	 */
	public function test_subscriber_blocked() {
		$this->load_tool( 'WP_MCP_AI_Tool_List_Classroom_Courses' );

		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$tool = new WP_MCP_AI_Tool_List_Classroom_Courses();

		$result = $tool->execute(
			array( 'connection_id' => $this->connection_id ),
			array( 'user_id' => $subscriber )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Listing courses returns the canonical envelope with the connection ID.
	 */
	public function test_list_courses_envelope() {
		$this->load_tool( 'WP_MCP_AI_Tool_List_Classroom_Courses' );

		$tool   = new WP_MCP_AI_Tool_List_Classroom_Courses();
		$result = $tool->execute(
			array( 'connection_id' => $this->connection_id ),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( $this->connection_id, $result['connection_id'] );
		$this->assertArrayHasKey( 'courses', $result );
	}

	/**
	 * An unknown connection must produce the not-found error.
	 */
	public function test_unknown_connection_errors() {
		$this->load_tool( 'WP_MCP_AI_Tool_List_Classroom_Courses' );

		$tool   = new WP_MCP_AI_Tool_List_Classroom_Courses();
		$result = $tool->execute(
			array( 'connection_id' => 'conn_missing' ),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_classroom_connection_not_found', $result->get_error_code() );
	}

	/**
	 * Roster sync in dry-run mode must preview without writing any student.
	 */
	public function test_roster_sync_dry_run_writes_nothing() {
		$this->load_tool( 'WP_MCP_AI_Tool_Sync_Classroom_Roster_To_Students' );

		$tool   = new WP_MCP_AI_Tool_Sync_Classroom_Roster_To_Students();
		$result = $tool->execute(
			array(
				'connection_id' => $this->connection_id,
				'course_id'     => '12345',
				'dry_run'       => true,
			),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );

		$students = get_posts(
			array(
				'post_type'   => 'mcp_ai_student',
				'post_status' => 'any',
			)
		);

		$this->assertCount( 0, $students );
	}

	/**
	 * Linking a course must persist both mapping meta keys.
	 */
	public function test_link_course_persists_meta() {
		$this->load_tool( 'WP_MCP_AI_Tool_Link_Classroom_Course_To_ECA' );

		$eca_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_eca',
				'post_status' => 'publish',
				'post_title'  => 'Chess Club',
			)
		);

		$tool   = new WP_MCP_AI_Tool_Link_Classroom_Course_To_ECA();
		$result = $tool->execute(
			array(
				'connection_id'  => $this->connection_id,
				'eca_id'         => $eca_id,
				'course_id'      => '12345',
				'sync_direction' => 'roster-in',
			),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertSame( '12345', get_post_meta( $eca_id, '_eca_google_course_id', true ) );
		$this->assertSame( 'roster-in', get_post_meta( $eca_id, '_eca_classroom_sync', true ) );
	}

	/**
	 * An invalid sync direction must fall back to the roster-in default.
	 */
	public function test_link_course_rejects_bad_direction() {
		$this->load_tool( 'WP_MCP_AI_Tool_Link_Classroom_Course_To_ECA' );

		$eca_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_eca',
				'post_status' => 'publish',
				'post_title'  => 'Chess Club',
			)
		);

		$tool   = new WP_MCP_AI_Tool_Link_Classroom_Course_To_ECA();
		$result = $tool->execute(
			array(
				'connection_id'  => $this->connection_id,
				'eca_id'         => $eca_id,
				'course_id'      => '12345',
				'sync_direction' => 'sideways',
			),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'roster-in', get_post_meta( $eca_id, '_eca_classroom_sync', true ) );
	}

	/**
	 * Write tools must require the write scope from the granted set.
	 */
	public function test_write_tool_requires_granted_scope() {
		$this->load_tool( 'WP_MCP_AI_Tool_Post_Classroom_Announcement' );

		// Re-seed the connection with the read-only grant so the announcement
		// scope is missing.
		$connections = get_option( 'wp_mcp_ai_pro_remote_sites', array() );
		$connections[ $this->connection_id ]['granted_scopes'] = WP_MCP_AI_Google_Classroom_Scopes::get_profile_scope_string( 'readonly' );
		update_option( 'wp_mcp_ai_pro_remote_sites', $connections );

		$tool   = new WP_MCP_AI_Tool_Post_Classroom_Announcement();
		$result = $tool->execute(
			array(
				'connection_id' => $this->connection_id,
				'text'          => 'Session moved to Thursday.',
			),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_classroom_missing_scope', $result->get_error_code() );
	}

	/**
	 * Update tool must refuse an empty update set.
	 */
	public function test_update_course_requires_a_field() {
		$this->load_tool( 'WP_MCP_AI_Tool_Update_Classroom_Course' );

		$tool   = new WP_MCP_AI_Tool_Update_Classroom_Course();
		$result = $tool->execute(
			array(
				'connection_id' => $this->connection_id,
				'course_id'     => '12345',
			),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_classroom_nothing_to_update', $result->get_error_code() );
	}
}
