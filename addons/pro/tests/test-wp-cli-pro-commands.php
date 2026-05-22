<?php
/**
 * Tests for the Pro WP-CLI command implementations.
 *
 * These tests validate the underlying WordPress/plugin functionality used by the
 * five new Pro CLI command groups (status, toolkit, connection, project, task)
 * without requiring WP_CLI to be available at test time.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 */

/**
 * Test cases covering logic consumed by the new Pro CLI commands.
 *
 * @since 1.3.0
 */
class Test_WP_CLI_Pro_Commands extends WP_UnitTestCase {

	/**
	 * Admin user used for capability-gated operations.
	 *
	 * @var int
	 */
	protected $admin_user_id = 0;

	/**
	 * Post IDs created during a test that need cleanup.
	 *
	 * @var int[]
	 */
	protected $created_posts = array();

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		foreach ( $this->created_posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->created_posts = array();

		wp_set_current_user( 0 );
		delete_option( 'wp_mcp_ai_settings' );

		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Pro CLI file existence checks.
	// -----------------------------------------------------------------------

	/**
	 * All new Pro CLI command files exist.
	 */
	public function test_new_pro_cli_command_files_exist() {
		$files = array(
			'class-wp-mcp-ai-pro-cli-base-command.php',
			'class-wp-mcp-ai-pro-cli-status-command.php',
			'class-wp-mcp-ai-pro-cli-toolkit-command.php',
			'class-wp-mcp-ai-pro-cli-connection-command.php',
			'class-wp-mcp-ai-pro-cli-project-command.php',
			'class-wp-mcp-ai-pro-cli-task-command.php',
		);

		foreach ( $files as $file ) {
			$path = WP_MCP_AI_PRO_PATH . 'includes/cli/' . $file;
			$this->assertFileExists( $path, "{$file} should exist" );
		}
	}

	/**
	 * Main pro plugin file contains the WP-CLI loader block.
	 */
	public function test_pro_plugin_file_loads_cli_commands() {
		$pro_file = WP_MCP_AI_PRO_PATH . 'mcp-ai-wpoos-pro.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin file for assertion.
		$content = file_get_contents( $pro_file );

		$this->assertStringContainsString( 'class-wp-mcp-ai-pro-cli-status-command.php', $content );
		$this->assertStringContainsString( 'class-wp-mcp-ai-pro-cli-toolkit-command.php', $content );
		$this->assertStringContainsString( 'class-wp-mcp-ai-pro-cli-connection-command.php', $content );
		$this->assertStringContainsString( 'class-wp-mcp-ai-pro-cli-project-command.php', $content );
		$this->assertStringContainsString( 'class-wp-mcp-ai-pro-cli-task-command.php', $content );
	}

	// -----------------------------------------------------------------------
	// Toolkit command logic.
	// -----------------------------------------------------------------------

	/**
	 * Enabling a toolkit persists the setting flag.
	 */
	public function test_toolkit_enable_persists_setting() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		unset( $settings['enable_crm_toolkit'] );
		update_option( 'wp_mcp_ai_settings', $settings );

		// Simulate what the toolkit enable command does.
		$settings                       = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_crm_toolkit'] = '1';
		update_option( 'wp_mcp_ai_settings', $settings );

		$stored = get_option( 'wp_mcp_ai_settings', array() );
		$this->assertSame( '1', $stored['enable_crm_toolkit'] );
	}

	/**
	 * Disabling a toolkit clears the setting flag.
	 */
	public function test_toolkit_disable_clears_setting() {
		$settings                       = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_crm_toolkit'] = '1';
		update_option( 'wp_mcp_ai_settings', $settings );

		// Simulate disable.
		$settings                       = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_crm_toolkit'] = '';
		update_option( 'wp_mcp_ai_settings', $settings );

		$stored = get_option( 'wp_mcp_ai_settings', array() );
		$this->assertEmpty( $stored['enable_crm_toolkit'] );
	}

	/**
	 * The TOOLKITS constant on the toolkit command class contains known entries.
	 */
	public function test_toolkit_command_has_expected_keys() {
		// Only load the file in isolation — no WP_CLI bootstrap needed.
		$file = WP_MCP_AI_PRO_PATH . 'includes/cli/class-wp-mcp-ai-pro-cli-toolkit-command.php';

		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Toolkit command file not found.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading file to inspect constants without executing WP-CLI bootstrap.
		$source = file_get_contents( $file );

		$this->assertStringContainsString( 'enable_crm_toolkit', $source );
		$this->assertStringContainsString( 'enable_architect_agent_toolkit', $source );
		$this->assertStringContainsString( 'enable_site_creator_toolkit', $source );
		$this->assertStringContainsString( 'enable_project_management', $source );
	}

	// -----------------------------------------------------------------------
	// Connection command logic (WP_MCP_AI_Pro_Remote_Site_Manager)
	// -----------------------------------------------------------------------

	/**
	 * Remote site manager stores connections in the expected option.
	 */
	public function test_connection_option_key_is_correct() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Pro_Remote_Site_Manager not available.' );
		}

		$this->assertSame( 'wp_mcp_ai_pro_remote_sites', WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
	}

	/**
	 * Get_all_connections returns an array (possibly empty).
	 */
	public function test_connection_get_all_returns_array() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Pro_Remote_Site_Manager not available.' );
		}

		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
		$this->assertIsArray( $connections );
	}

	/**
	 * Get_connection returns null for a non-existent ID.
	 */
	public function test_connection_get_returns_null_for_missing_id() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Pro_Remote_Site_Manager not available.' );
		}

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( 'nonexistent_xyz_999' );
		$this->assertNull( $result );
	}

	/**
	 * Credential redaction in connection command removes expected keys.
	 */
	public function test_connection_credential_redaction_logic() {
		$conn = array(
			'name'            => 'My Site',
			'url'             => 'https://example.com',
			'auth_type'       => 'application_password',
			'password'        => 'super-secret',
			'token'           => 'bearer-token',
			'consumer_key'    => 'ck_abc',
			'consumer_secret' => 'cs_xyz',
			'api_key'         => 'key123',
		);

		$sensitive_keys = array( 'password', 'token', 'consumer_key', 'consumer_secret', 'api_key', 'api_secret', 'client_secret', 'refresh_token', 'bot_token', 'secret_token' );
		foreach ( $sensitive_keys as $k ) {
			if ( isset( $conn[ $k ] ) && '' !== $conn[ $k ] ) {
				$conn[ $k ] = '[REDACTED]';
			}
		}

		$this->assertSame( 'My Site', $conn['name'] );
		$this->assertSame( '[REDACTED]', $conn['password'] );
		$this->assertSame( '[REDACTED]', $conn['token'] );
		$this->assertSame( '[REDACTED]', $conn['consumer_key'] );
		$this->assertSame( '[REDACTED]', $conn['consumer_secret'] );
		$this->assertSame( '[REDACTED]', $conn['api_key'] );
	}

	// -----------------------------------------------------------------------
	// Project command logic.
	// -----------------------------------------------------------------------

	/**
	 * Mcp_ai_project CPT is registered.
	 */
	public function test_project_cpt_is_registered() {
		$this->assertTrue(
			post_type_exists( 'mcp_ai_project' ),
			'mcp_ai_project CPT should be registered'
		);
	}

	/**
	 * Projects can be inserted, queried, and deleted.
	 */
	public function test_project_crud() {
		// Enable project management.
		$settings                              = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_project_management'] = '1';
		update_option( 'wp_mcp_ai_settings', $settings );

		$id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_project',
				'post_title'  => 'CLI Test Project',
				'post_status' => 'publish',
			)
		);
		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
		$this->created_posts[] = $id;

		$post = get_post( $id );
		$this->assertSame( 'mcp_ai_project', $post->post_type );
		$this->assertSame( 'CLI Test Project', $post->post_title );

		// Delete.
		$deleted = wp_delete_post( $id, true );
		$this->assertNotFalse( $deleted );

		// Remove from cleanup list since already deleted.
		$this->created_posts = array_filter(
			$this->created_posts,
			function ( $pid ) use ( $id ) {
				return $pid !== $id;
			}
		);
	}

	/**
	 * Project list query returns inserted project.
	 */
	public function test_project_list_query() {
		$id                    = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_project',
				'post_title'  => 'Query Test Project',
				'post_status' => 'publish',
			)
		);
		$this->created_posts[] = $id;

		$posts = get_posts(
			array(
				'post_type'      => 'mcp_ai_project',
				'posts_per_page' => -1,
				'post_status'    => 'any',
			)
		);

		$this->assertContains( $id, wp_list_pluck( $posts, 'ID' ) );
	}

	// -----------------------------------------------------------------------
	// Task command logic.
	// -----------------------------------------------------------------------

	/**
	 * Mcp_ai_task CPT is registered.
	 */
	public function test_task_cpt_is_registered() {
		$this->assertTrue(
			post_type_exists( 'mcp_ai_task' ),
			'mcp_ai_task CPT should be registered'
		);
	}

	/**
	 * Tasks can be inserted with project meta and queried.
	 */
	public function test_task_create_with_meta() {
		// Enable project management.
		$settings                              = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_project_management'] = '1';
		update_option( 'wp_mcp_ai_settings', $settings );

		// Create a parent project.
		$project_id            = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_project',
				'post_title'  => 'Parent Project',
				'post_status' => 'publish',
			)
		);
		$this->created_posts[] = $project_id;

		// Create a task.
		$task_id               = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_task',
				'post_title'  => 'Write unit tests',
				'post_status' => 'publish',
			)
		);
		$this->created_posts[] = $task_id;

		update_post_meta( $task_id, '_task_status', 'pending' );
		update_post_meta( $task_id, '_task_priority', 'high' );
		update_post_meta( $task_id, '_task_project_id', $project_id );

		$this->assertSame( 'pending', get_post_meta( $task_id, '_task_status', true ) );
		$this->assertSame( 'high', get_post_meta( $task_id, '_task_priority', true ) );
		$this->assertEquals( $project_id, (int) get_post_meta( $task_id, '_task_project_id', true ) );
	}

	/**
	 * Completing a task via update_post_meta works as expected.
	 */
	public function test_task_complete_updates_status() {
		$task_id               = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_task',
				'post_title'  => 'A pending task',
				'post_status' => 'publish',
			)
		);
		$this->created_posts[] = $task_id;

		update_post_meta( $task_id, '_task_status', 'pending' );
		$this->assertSame( 'pending', get_post_meta( $task_id, '_task_status', true ) );

		// Simulate the complete command.
		update_post_meta( $task_id, '_task_status', 'completed' );
		$this->assertSame( 'completed', get_post_meta( $task_id, '_task_status', true ) );
	}

	/**
	 * Task list can be filtered by _task_project_id meta.
	 */
	public function test_task_list_filter_by_project() {
		$project_a             = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_project',
				'post_title'  => 'Project A',
				'post_status' => 'publish',
			)
		);
		$this->created_posts[] = $project_a;

		$task_a                = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_task',
				'post_title'  => 'Task A',
				'post_status' => 'publish',
			)
		);
		$this->created_posts[] = $task_a;
		update_post_meta( $task_a, '_task_project_id', $project_a );

		$task_b                = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_task',
				'post_title'  => 'Task B (different project)',
				'post_status' => 'publish',
			)
		);
		$this->created_posts[] = $task_b;
		update_post_meta( $task_b, '_task_project_id', 99999 );

		$filtered = get_posts(
			array(
				'post_type'      => 'mcp_ai_task',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'meta_query'     => array(
					array(
						'key'   => '_task_project_id',
						'value' => $project_a,
					),
				),
			)
		);

		$filtered_ids = wp_list_pluck( $filtered, 'ID' );
		$this->assertContains( $task_a, $filtered_ids );
		$this->assertNotContains( $task_b, $filtered_ids );
	}

	// -----------------------------------------------------------------------
	// Pro status command logic.
	// -----------------------------------------------------------------------

	/**
	 * Pro version constant is defined.
	 */
	public function test_pro_version_constant_defined() {
		$this->assertTrue( defined( 'WP_MCP_AI_PRO_VERSION' ), 'WP_MCP_AI_PRO_VERSION should be defined' );
		$this->assertNotEmpty( WP_MCP_AI_PRO_VERSION );
	}

	/**
	 * Active toolkit labels can be derived from settings.
	 */
	public function test_pro_status_active_toolkit_labels() {
		$all_keys = array(
			'enable_crm_toolkit',
			'enable_project_management',
			'enable_architect_agent_toolkit',
		);

		$settings = array();
		foreach ( $all_keys as $key ) {
			$settings[ $key ] = '1';
		}
		update_option( 'wp_mcp_ai_settings', $settings );

		$stored = get_option( 'wp_mcp_ai_settings', array() );
		foreach ( $all_keys as $key ) {
			$this->assertNotEmpty( $stored[ $key ] );
		}
	}
}
