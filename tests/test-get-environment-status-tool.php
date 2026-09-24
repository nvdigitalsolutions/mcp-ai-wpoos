<?php
/**
 * Environment status tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-environment-status.php';

/**
 * Tests for the get_environment_status tool.
 */
class WP_MCP_AI_Get_Environment_Status_Tool_Test extends WP_UnitTestCase {

	/**
	 * Load the assistant CPT class (the tool summarises assistant posts)
	 * and wipe any assistant posts left behind by the bootstrap backfill so
	 * the assistant counts are deterministic.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/assistants/class-wp-mcp-ai-assistant-cpt.php';
		}

		$existing = get_posts(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);

		foreach ( $existing as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		WP_MCP_AI_Admin_Settings::reset_settings_cache();
	}

	/**
	 * Reset settings state between tests.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Admin_Settings::reset_settings_cache();
		parent::tearDown();
	}

	/**
	 * Seed plugin settings and flush the settings cache.
	 *
	 * @param array $overrides Settings to save.
	 */
	private function set_settings( array $overrides ) {
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $overrides );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();
	}

	/**
	 * Create a published assistant post.
	 *
	 * @param array $meta Meta to attach.
	 * @return int Post ID.
	 */
	private function create_assistant( array $meta = array() ) {
		$post_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant',
			)
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}

	/**
	 * Assert that a warning element contains the given substring.
	 *
	 * @param array  $warnings Warning messages.
	 * @param string $needle   Substring to find.
	 */
	private function assert_warning_contains( array $warnings, string $needle ) {
		foreach ( $warnings as $warning ) {
			if ( is_string( $warning ) && false !== strpos( $warning, $needle ) ) {
				$this->addToAssertionCount( 1 );
				return;
			}
		}

		$this->fail( sprintf( 'Expected a warning containing "%s"; got: %s', $needle, wp_json_encode( $warnings ) ) );
	}

	/**
	 * Assert that no warning element contains the given substring.
	 *
	 * @param array  $warnings Warning messages.
	 * @param string $needle   Substring to look for.
	 */
	private function assert_no_warning_contains( array $warnings, string $needle ) {
		foreach ( $warnings as $warning ) {
			if ( is_string( $warning ) && false !== strpos( $warning, $needle ) ) {
				$this->fail( sprintf( 'Did not expect a warning containing "%s"; got: %s', $needle, $warning ) );
			}
		}

		$this->addToAssertionCount( 1 );
	}

	/**
	 * The tool must reject users without manage_options.
	 */
	public function test_execute_requires_manage_options() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$tool   = new WP_MCP_AI_Tool_Get_Environment_Status();
		$result = $tool->execute( array(), array( 'user_id' => $subscriber_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Zero published assistants must produce the no-assistants warning.
	 */
	public function test_warns_when_no_assistants_are_published() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->set_settings( array( 'default_assistant' => 0 ) );

		$tool   = new WP_MCP_AI_Tool_Get_Environment_Status();
		$result = $tool->execute( array(), array( 'user_id' => $admin_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['assistants']['environment']['total_assistants'] );
		$this->assert_warning_contains( $result['warnings'], 'No assistants are published yet' );
	}

	/**
	 * Published assistants must suppress the no-assistants warning.
	 *
	 * Regression: build_warnings() used to read flat keys from the
	 * 'environment'-wrapped assistant summary, so the warning fired even
	 * when assistants existed.
	 */
	public function test_published_assistants_suppress_no_assistants_warning() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->create_assistant();
		$this->create_assistant();

		$this->set_settings( array( 'default_assistant' => 0 ) );

		$tool   = new WP_MCP_AI_Tool_Get_Environment_Status();
		$result = $tool->execute( array(), array( 'user_id' => $admin_id ) );

		$this->assertSame( 2, $result['assistants']['environment']['total_assistants'] );
		$this->assert_no_warning_contains( $result['warnings'], 'No assistants are published yet' );
	}

	/**
	 * The default assistant entry must expose its provider/model meta.
	 */
	public function test_default_assistant_exposes_provider_and_model_meta() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$assistant_id = $this->create_assistant(
			array(
				'_wp_mcp_ai_provider' => 'deepseek',
				'_wp_mcp_ai_model'    => 'deepseek-flash',
			)
		);

		$this->set_settings( array( 'default_assistant' => $assistant_id ) );

		$tool   = new WP_MCP_AI_Tool_Get_Environment_Status();
		$result = $tool->execute( array(), array( 'user_id' => $admin_id ) );

		$default = $result['assistants']['environment']['default_assistant'];

		$this->assertNotNull( $default );
		$this->assertSame( $assistant_id, $default['id'] );
		$this->assertSame( 'publish', $default['status'] );
		$this->assertSame( 'deepseek', $default['provider'] );
		$this->assertSame( 'deepseek-flash', $default['model'] );
	}

	/**
	 * An unloadable default assistant must produce the misconfiguration warning.
	 *
	 * Regression: this branch read the same wrapped keys and could never fire.
	 */
	public function test_unloadable_default_assistant_warns() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->set_settings( array( 'default_assistant' => 999999 ) );

		$tool   = new WP_MCP_AI_Tool_Get_Environment_Status();
		$result = $tool->execute( array(), array( 'user_id' => $admin_id ) );

		$this->assert_warning_contains( $result['warnings'], 'The configured default assistant could not be loaded' );
	}

	/**
	 * The effective model for a DeepSeek default provider comes from
	 * deepseek_model, not the OpenAI-only default_model.
	 */
	public function test_default_provider_model_resolves_deepseek_key() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->set_settings(
			array(
				'default_provider' => 'deepseek',
				'deepseek_model'   => 'deepseek-flash',
			)
		);

		$tool   = new WP_MCP_AI_Tool_Get_Environment_Status();
		$result = $tool->execute( array(), array( 'user_id' => $admin_id ) );

		$this->assertSame( 'deepseek', $result['plugin']['default_provider'] );
		$this->assertSame( 'deepseek-flash', $result['plugin']['default_provider_model'] );
		// default_model remains the OpenAI default and must not be mistaken
		// for the model DeepSeek chats use.
		$this->assertSame( 'gpt-4.1', $result['plugin']['default_model'] );
	}

	/**
	 * Gemini uses its own default_gemini_model key.
	 */
	public function test_default_provider_model_resolves_gemini_key() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->set_settings(
			array(
				'default_provider'     => 'gemini',
				'default_gemini_model' => 'gemini-2.5-flash',
			)
		);

		$tool   = new WP_MCP_AI_Tool_Get_Environment_Status();
		$result = $tool->execute( array(), array( 'user_id' => $admin_id ) );

		$this->assertSame( 'gemini-2.5-flash', $result['plugin']['default_provider_model'] );
	}

	/**
	 * Providers without a mapped model key report an empty model.
	 */
	public function test_default_provider_model_empty_for_unmapped_provider() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->set_settings( array( 'default_provider' => 'embedded' ) );

		$tool   = new WP_MCP_AI_Tool_Get_Environment_Status();
		$result = $tool->execute( array(), array( 'user_id' => $admin_id ) );

		$this->assertSame( '', $result['plugin']['default_provider_model'] );
	}

	/**
	 * A keyless DeepSeek default provider must warn.
	 */
	public function test_deepseek_default_provider_without_key_warns() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->set_settings( array( 'default_provider' => 'deepseek' ) );

		$tool   = new WP_MCP_AI_Tool_Get_Environment_Status();
		$result = $tool->execute( array(), array( 'user_id' => $admin_id ) );

		$this->assert_warning_contains( $result['warnings'], 'DeepSeek is the default provider but no API key is configured' );
	}
}
