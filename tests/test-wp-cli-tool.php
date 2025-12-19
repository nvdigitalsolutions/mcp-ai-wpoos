<?php

require_once WP_MCP_AI_PATH . 'addons/pro/includes/tools/class-wp-mcp-ai-tool-check-wp-cli.php';

/**
 * Tests for the WP-CLI status tool.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_WP_CLI_Tool_Test extends WP_UnitTestCase {
	/**
	 * Track any temporary files created during testing.
	 *
	 * @var string[]
	 */
	protected $temp_files = array();

	/**
	 * Clean up temporary files and reset the current user.
	 */
	public function tearDown(): void {
		foreach ( $this->temp_files as $file ) {
			if ( $file && file_exists( $file ) ) {
				unlink( $file );
			}
		}

		$this->temp_files = array();

		wp_set_current_user( 0 );

		// Reset settings.
		delete_option( 'wp_mcp_ai_settings' );

		parent::tearDown();
	}

	/**
	 * Test that the tool returns an error when the feature is disabled.
	 */
	public function test_execute_returns_error_when_feature_disabled() {
		// Ensure feature is disabled.
		update_option( 'wp_mcp_ai_settings', array() );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$tool   = new WP_MCP_AI_Tool_Check_WP_CLI();
		$result = $tool->execute( array(), array( 'user_id' => $admin_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_feature_disabled', $result->get_error_code() );
	}

	/**
	 * Test that the tool returns an error when site creator is enabled but WP-CLI tools are not.
	 */
	public function test_execute_returns_error_when_wp_cli_tools_disabled() {
		// Enable site creator but not WP-CLI tools.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_site_creator' => true,
			)
		);

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$tool   = new WP_MCP_AI_Tool_Check_WP_CLI();
		$result = $tool->execute( array(), array( 'user_id' => $admin_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_feature_disabled', $result->get_error_code() );
	}

	/**
	 * Ensure the tool enforces the manage_options capability.
	 */
	public function test_execute_requires_manage_options() {
		// Enable the feature first.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_site_creator'             => true,
				'site_creator_allow_wp_cli_tools' => true,
			)
		);

		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$tool   = new WP_MCP_AI_Tool_Check_WP_CLI();
		$result = $tool->execute( array(), array( 'user_id' => $subscriber_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * The tool should report when the binary cannot be located.
	 */
	public function test_execute_reports_missing_binary() {
		// Enable the feature first.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_site_creator'             => true,
				'site_creator_allow_wp_cli_tools' => true,
			)
		);

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$filter = static function () {
			return array( WP_CONTENT_DIR . '/no-such-wp-cli' );
		};

		add_filter( 'wp_mcp_ai_wp_cli_candidate_paths', $filter );

		try {
			$tool   = new WP_MCP_AI_Tool_Check_WP_CLI();
			$result = $tool->execute( array(), array( 'user_id' => $admin_id ) );
		} finally {
			remove_filter( 'wp_mcp_ai_wp_cli_candidate_paths', $filter );
		}

		$this->assertNotWPError( $result );
		$this->assertFalse( $result['available'] );
		$this->assertSame( '', $result['binary_path'] );
		$this->assertNotEmpty( $result['notes'] );
	}

	/**
	 * When a binary is present the tool should expose the version output.
	 */
	public function test_execute_reports_version_for_detected_binary() {
		if ( ! $this->can_execute_processes() ) {
			$this->markTestSkipped( 'proc_open is not available in this test environment.' );
		}

		// Enable the feature first.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_site_creator'             => true,
				'site_creator_allow_wp_cli_tools' => true,
			)
		);

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$binary   = $this->create_fake_wp_cli_binary( "#!/usr/bin/env php\n<?php echo \"WP-CLI 9.9.9\\n\";" );

		$filter = static function () use ( $binary ) {
			return array( $binary );
		};

		add_filter( 'wp_mcp_ai_wp_cli_candidate_paths', $filter );

		try {
			$tool   = new WP_MCP_AI_Tool_Check_WP_CLI();
			$result = $tool->execute( array(), array( 'user_id' => $admin_id ) );
		} finally {
			remove_filter( 'wp_mcp_ai_wp_cli_candidate_paths', $filter );
		}

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['available'] );
		$this->assertSame( wp_normalize_path( $binary ), $result['binary_path'] );
		$this->assertSame( 'binary', $result['binary_type'] );
		$this->assertTrue( $result['can_execute'] );
		$this->assertSame( '9.9.9', $result['version'] );
		$this->assertArrayHasKey( 'version_output', $result );
		$this->assertStringContainsString( 'WP-CLI 9.9.9', $result['version_output'] );
	}

	/**
	 * Ensure Cloudways-specific installation paths are included by default.
	 */
	public function test_candidate_paths_include_cloudways_locations() {
		$tool = new WP_MCP_AI_Tool_Check_WP_CLI();

		$method = new ReflectionMethod( $tool, 'get_candidate_paths' );
		$method->setAccessible( true );

		$candidates = $method->invoke( $tool );

		$this->assertContains( wp_normalize_path( '/usr/local/bin/wp' ), $candidates );
		$this->assertContains( wp_normalize_path( '/home/master/bin/wp' ), $candidates );
		$this->assertContains( wp_normalize_path( '/home/master/.wp-cli/wp-cli.phar' ), $candidates );
	}

	/**
	 * Determine whether proc_open is available.
	 *
	 * @return bool
	 */
	protected function can_execute_processes() {
		if ( ! function_exists( 'proc_open' ) ) {
			return false;
		}

		$disabled = ini_get( 'disable_functions' );

		if ( ! $disabled ) {
			return true;
		}

		$disabled_functions = array_map( 'trim', explode( ',', (string) $disabled ) );

		return ! in_array( 'proc_open', $disabled_functions, true );
	}

	/**
	 * Create a fake WP-CLI binary for testing.
	 *
	 * @param string $content File contents.
	 * @return string
	 */
	protected function create_fake_wp_cli_binary( $content ) {
		$file = tempnam( sys_get_temp_dir(), 'wp-cli-test' );

		file_put_contents( $file, $content );
		chmod( $file, 0755 );

		$file = wp_normalize_path( $file );

		$this->temp_files[] = $file;

		return $file;
	}
}
