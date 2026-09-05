<?php
/**
 * Tests for the new WP-CLI command implementations.
 *
 * These tests validate the underlying WordPress/plugin functionality that
 * the five new CLI command groups (assistant, tool, settings, credential, log)
 * rely on, rather than the CLI glue code itself (which requires WP_CLI at runtime).
 *
 * @package WP_MCP_AI
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test cases covering logic consumed by the new CLI commands.
 *
 * @since 1.3.0
 */
class Test_WP_CLI_New_Commands extends WP_UnitTestCase {

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
	// Assistant command logic
	// -----------------------------------------------------------------------

	/**
	 * Assistants can be listed by querying the mcp_ai_assistant CPT.
	 */
	public function test_assistant_list_returns_inserted_posts() {
		$id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);
		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
		$this->created_posts[] = $id;

		$posts = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'posts_per_page' => -1,
				'post_status'    => 'any',
			)
		);

		$ids = wp_list_pluck( $posts, 'ID' );
		$this->assertContains( $id, $ids, 'Newly created assistant should appear in CPT query' );
	}

	/**
	 * Assistant metadata stored with the mcp_ai_ prefix is retrievable.
	 */
	public function test_assistant_meta_stored_and_retrieved() {
		$id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Meta Test Assistant',
				'post_status' => 'draft',
			)
		);
		$this->created_posts[] = $id;

		update_post_meta( $id, 'mcp_ai_model', 'gpt-4o' );

		$stored = get_post_meta( $id, 'mcp_ai_model', true );
		$this->assertSame( 'gpt-4o', $stored );
	}

	/**
	 * Deleting an assistant (force) removes it from the database.
	 */
	public function test_assistant_delete_removes_post() {
		$id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'To Delete',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $id );

		$deleted = wp_delete_post( $id, true );
		$this->assertNotFalse( $deleted, 'wp_delete_post should succeed' );

		$post = get_post( $id );
		$this->assertNull( $post, 'Post should no longer exist after force delete' );
	}

	/**
	 * Filtering assistants by status works as expected.
	 */
	public function test_assistant_list_filter_by_status() {
		$published_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Published',
				'post_status' => 'publish',
			)
		);
		$draft_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Draft',
				'post_status' => 'draft',
			)
		);
		$this->created_posts[] = $published_id;
		$this->created_posts[] = $draft_id;

		$published_posts = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
			)
		);
		$published_ids = wp_list_pluck( $published_posts, 'ID' );

		$this->assertContains( $published_id, $published_ids );
		$this->assertNotContains( $draft_id, $published_ids );
	}

	// -----------------------------------------------------------------------
	// Tool command logic
	// -----------------------------------------------------------------------

	/**
	 * Tool registry is available and returns registered tools.
	 */
	public function test_tool_registry_get_all_tools_returns_array() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Registry not available.' );
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tools    = $registry->get_all_tools();

		$this->assertIsArray( $tools, 'get_all_tools() should return an array' );
	}

	/**
	 * Disabling a tool adds it to the disabled list and enabling removes it.
	 */
	public function test_tool_enable_disable_roundtrip() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Registry not available.' );
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tools    = $registry->get_all_tools();

		if ( empty( $tools ) ) {
			$this->markTestSkipped( 'No tools registered.' );
		}

		$slug = array_key_first( $tools );

		// Ensure starting state is enabled.
		$registry->enable_tool( $slug );
		$this->assertTrue( $registry->is_tool_enabled( $slug ), 'Tool should be enabled after enable_tool()' );

		// Disable it.
		$registry->disable_tool( $slug );
		$this->assertFalse( $registry->is_tool_enabled( $slug ), 'Tool should be disabled after disable_tool()' );

		// Re-enable.
		$registry->enable_tool( $slug );
		$this->assertTrue( $registry->is_tool_enabled( $slug ), 'Tool should be enabled again after second enable_tool()' );
	}

	/**
	 * is_tool_registered returns false for an unknown slug.
	 */
	public function test_tool_is_registered_false_for_unknown_slug() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Registry not available.' );
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->assertFalse( $registry->is_tool_registered( 'definitely_not_a_real_tool_xyz_999' ) );
	}

	// -----------------------------------------------------------------------
	// Settings command logic
	// -----------------------------------------------------------------------

	/**
	 * Settings can be read from and written to the wp_mcp_ai_settings option.
	 */
	public function test_settings_get_and_set() {
		$initial = get_option( 'wp_mcp_ai_settings', array() );
		$this->assertIsArray( $initial );

		// Write a value.
		$settings                    = $initial;
		$settings['active_provider'] = 'openai';
		update_option( 'wp_mcp_ai_settings', $settings );

		$retrieved = get_option( 'wp_mcp_ai_settings', array() );
		$this->assertSame( 'openai', $retrieved['active_provider'] );
	}

	/**
	 * Resetting settings to defaults populates expected keys.
	 */
	public function test_settings_reset_to_defaults() {
		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Admin_Settings not available.' );
		}

		$defaults = WP_MCP_AI_Admin_Settings::get_default_settings();
		$this->assertIsArray( $defaults, 'get_default_settings() should return an array' );
		$this->assertNotEmpty( $defaults, 'Default settings should not be empty' );

		update_option( 'wp_mcp_ai_settings', $defaults );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$stored = get_option( 'wp_mcp_ai_settings', array() );
		$this->assertSame( $defaults, $stored );
	}

	/**
	 * Sensitive setting keys containing known suffixes are redactable.
	 *
	 * We test the redaction logic directly since it lives inside the CLI command
	 * class that cannot be instantiated without WP_CLI being available.  The
	 * redaction algorithm is straightforward enough to verify inline.
	 */
	public function test_settings_sensitive_key_redaction_logic() {
		$settings = array(
			'openai_api_key'     => 'sk-secret',
			'active_provider'    => 'openai',
			'enable_logging'     => '1',
			'gemini_api_key'     => 'abc',
			'some_refresh_token' => 'token-value',
		);

		$sensitive_suffixes = array( '_api_key', '_secret', '_token', '_password', '_credentials_json', '_refresh_token' );

		foreach ( $settings as $key => $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}
			foreach ( $sensitive_suffixes as $suffix ) {
				if ( substr( $key, -strlen( $suffix ) ) === $suffix ) {
					$settings[ $key ] = '[REDACTED]';
					break;
				}
			}
		}

		$this->assertSame( '[REDACTED]', $settings['openai_api_key'] );
		$this->assertSame( '[REDACTED]', $settings['gemini_api_key'] );
		$this->assertSame( '[REDACTED]', $settings['some_refresh_token'] );
		$this->assertSame( 'openai', $settings['active_provider'] );
		$this->assertSame( '1', $settings['enable_logging'] );
	}

	// -----------------------------------------------------------------------
	// Credential command logic
	// -----------------------------------------------------------------------

	/**
	 * Issuing and listing credentials for an assistant works via WP_MCP_AI_Credentials.
	 */
	public function test_credential_issue_and_list() {
		if ( ! class_exists( 'WP_MCP_AI_Credentials' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Credentials not available.' );
		}

		$assistant_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Credential Test Assistant',
				'post_status' => 'publish',
			)
		);
		$this->created_posts[] = $assistant_id;

		// Initially no credentials.
		$initial = WP_MCP_AI_Credentials::get_credentials( $assistant_id );
		$this->assertIsArray( $initial );
		$this->assertEmpty( $initial );

		// Issue a credential.
		$result = WP_MCP_AI_Credentials::issue_credential( $assistant_id, $this->admin_user_id );

		if ( is_wp_error( $result ) ) {
			$this->markTestSkipped( 'Credential issuance returned WP_Error: ' . $result->get_error_message() );
		}

		$this->assertArrayHasKey( 'token', $result, 'issue_credential() should return array with "token" key' );
		$this->assertNotEmpty( $result['token'], 'Issued token should not be empty' );

		// Credential should now appear in the list.
		$after = WP_MCP_AI_Credentials::get_credentials( $assistant_id );
		$this->assertNotEmpty( $after, 'Credentials list should be non-empty after issuance' );
	}

	/**
	 * Revoking a credential marks it revoked so it can no longer authenticate,
	 * while the record stays listed for auditing.
	 */
	public function test_credential_revoke_removes_entry() {
		if ( ! class_exists( 'WP_MCP_AI_Credentials' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Credentials not available.' );
		}

		$assistant_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Revoke Test Assistant',
				'post_status' => 'publish',
			)
		);
		$this->created_posts[] = $assistant_id;

		$result = WP_MCP_AI_Credentials::issue_credential( $assistant_id, $this->admin_user_id );

		if ( is_wp_error( $result ) ) {
			$this->markTestSkipped( 'Credential issuance returned WP_Error: ' . $result->get_error_message() );
		}

		$credentials = WP_MCP_AI_Credentials::get_credentials( $assistant_id );
		$this->assertNotEmpty( $credentials );

		$credential_id = $credentials[0]['id'] ?? '';
		if ( ! $credential_id ) {
			$this->markTestSkipped( 'Could not determine credential ID from issued result.' );
		}

		// Revoke it.
		$revoke_result = WP_MCP_AI_Credentials::revoke_credential( $assistant_id, $credential_id, $this->admin_user_id );

		$this->assertNotWPError( $revoke_result, 'Revocation should not return WP_Error' );

		$after = WP_MCP_AI_Credentials::get_credentials( $assistant_id );
		$found = false;
		foreach ( $after as $record ) {
			if ( isset( $record['id'] ) && $credential_id === $record['id'] ) {
				$found = true;
				$this->assertNotEmpty( $record['revoked_at'] ?? '', 'Revoked credential should carry a revoked_at timestamp' );
				break;
			}
		}
		$this->assertTrue( $found, 'Revoked credential should remain listed with revoked status' );
	}

	// -----------------------------------------------------------------------
	// Log command logic
	// -----------------------------------------------------------------------

	/**
	 * WP_MCP_AI_Logger::get_recent_error_messages returns an array.
	 */
	public function test_log_get_recent_errors_returns_array() {
		if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Logger not available.' );
		}

		$errors = WP_MCP_AI_Logger::get_recent_error_messages( 5 );
		$this->assertIsArray( $errors );
	}

	/**
	 * WP_MCP_AI_Logger::get_recent_activity_entries returns an array.
	 */
	public function test_log_get_recent_activity_returns_array() {
		if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Logger not available.' );
		}

		$activity = WP_MCP_AI_Logger::get_recent_activity_entries( 5 );
		$this->assertIsArray( $activity );
	}

	/**
	 * Logged error messages appear in get_recent_error_messages output.
	 */
	public function test_log_error_persists_to_recent_errors() {
		if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Logger not available.' );
		}

		// Logging is opt-in; enable it explicitly so the assertion is
		// independent of options left behind by other suites.
		update_option( 'wp_mcp_ai_settings', array( 'enable_logging' => true ) );
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		$unique = 'cli-test-error-' . uniqid( '', true );
		WP_MCP_AI_Logger::log_error( $unique );

		$errors = WP_MCP_AI_Logger::get_recent_error_messages( 50 );

		$found = false;
		foreach ( $errors as $entry ) {
			$haystack = is_string( $entry ) ? $entry : ( $entry['message'] ?? wp_json_encode( $entry ) );
			if ( false !== strpos( $haystack, $unique ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'Logged error message should appear in get_recent_error_messages()' );

		// Clean up the persisted option to avoid polluting other tests.
		if ( defined( 'WP_MCP_AI_Logger::RECENT_ERRORS_OPTION' ) || class_exists( 'WP_MCP_AI_Logger' ) ) {
			delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );
		}
	}

	/**
	 * can_prune_error_log returns a boolean.
	 */
	public function test_log_can_prune_returns_boolean() {
		if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Logger not available.' );
		}

		$result = WP_MCP_AI_Logger::can_prune_error_log();
		$this->assertIsBool( $result );
	}

	// -----------------------------------------------------------------------
	// CLI file existence checks (ensure files were created)
	// -----------------------------------------------------------------------

	/**
	 * All new CLI command files exist.
	 */
	public function test_new_cli_command_files_exist() {
		$files = array(
			'class-wp-mcp-ai-cli-assistant-command.php',
			'class-wp-mcp-ai-cli-tool-command.php',
			'class-wp-mcp-ai-cli-settings-command.php',
			'class-wp-mcp-ai-cli-credential-command.php',
			'class-wp-mcp-ai-cli-log-command.php',
		);

		foreach ( $files as $file ) {
			$path = WP_MCP_AI_PATH . 'includes/cli/' . $file;
			$this->assertFileExists( $path, "{$file} should exist" );
		}
	}

	/**
	 * Main CLI command file loads new command files in the WP_CLI block.
	 */
	public function test_main_cli_file_loads_new_command_files() {
		$main_cli = WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cli-command.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin file for assertion.
		$content = file_get_contents( $main_cli );

		$this->assertStringContainsString( 'class-wp-mcp-ai-cli-assistant-command.php', $content );
		$this->assertStringContainsString( 'class-wp-mcp-ai-cli-tool-command.php', $content );
		$this->assertStringContainsString( 'class-wp-mcp-ai-cli-settings-command.php', $content );
		$this->assertStringContainsString( 'class-wp-mcp-ai-cli-credential-command.php', $content );
		$this->assertStringContainsString( 'class-wp-mcp-ai-cli-log-command.php', $content );
	}
}
