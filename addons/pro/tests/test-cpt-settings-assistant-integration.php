<?php
/**
 * Tests for CPT Settings and Research Page Assistant Integration.
 *
 * @package WP_MCP_AI
 */

/**
 * Test CPT settings pages correctly configure and use assistants.
 */
class Test_CPT_Settings_Assistant_Integration extends WP_UnitTestCase {
	/**
	 * Test assistant ID.
	 *
	 * @var int
	 */
	private $assistant_id;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create a test assistant.
		$this->assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		// Clean up settings.
		delete_option( 'wp_mcp_ai_quiz_settings' );
		delete_option( 'wp_mcp_ai_place_settings' );
		delete_option( 'wp_mcp_ai_eca_settings' );
		delete_option( 'wp_mcp_ai_policy_settings' );
		delete_option( 'wp_mcp_ai_project_settings' );

		// Delete test assistant.
		if ( $this->assistant_id ) {
			wp_delete_post( $this->assistant_id, true );
		}

		parent::tearDown();
	}

	/**
	 * Test Quiz Settings Page sanitizes assistant_id correctly.
	 */
	public function test_quiz_settings_sanitize_assistant_id() {
		if ( ! class_exists( 'WP_MCP_AI_Quiz_Settings_Page' ) ) {
			require_once dirname( __DIR__ ) . '/includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';
			require_once dirname( __DIR__ ) . '/includes/admin/class-wp-mcp-ai-quiz-settings-page.php';
		}

		$settings_page = new WP_MCP_AI_Quiz_Settings_Page();

		$input = array(
			'assistant_id'          => $this->assistant_id,
			'default_time_limit'    => '30',
			'default_passing_score' => '75',
			'enable_research'       => '1',
		);

		$sanitized = $settings_page->sanitize_settings( $input );

		$this->assertEquals( $this->assistant_id, $sanitized['assistant_id'] );
		$this->assertEquals( 30, $sanitized['default_time_limit'] );
		$this->assertEquals( 75, $sanitized['default_passing_score'] );
		$this->assertTrue( $sanitized['enable_research'] );
	}

	/**
	 * Test Quiz Settings Page sanitizes passing score within bounds.
	 */
	public function test_quiz_settings_sanitize_passing_score_bounds() {
		if ( ! class_exists( 'WP_MCP_AI_Quiz_Settings_Page' ) ) {
			require_once dirname( __DIR__ ) . '/includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';
			require_once dirname( __DIR__ ) . '/includes/admin/class-wp-mcp-ai-quiz-settings-page.php';
		}

		$settings_page = new WP_MCP_AI_Quiz_Settings_Page();

		// Test upper bound.
		$input     = array( 'default_passing_score' => '150' );
		$sanitized = $settings_page->sanitize_settings( $input );
		$this->assertEquals( 100, $sanitized['default_passing_score'] );

		// Test lower bound.
		$input     = array( 'default_passing_score' => '-10' );
		$sanitized = $settings_page->sanitize_settings( $input );
		$this->assertEquals( 0, $sanitized['default_passing_score'] );
	}

	/**
	 * Test Place Settings Page sanitizes assistant_id correctly.
	 */
	public function test_place_settings_sanitize_assistant_id() {
		if ( ! class_exists( 'WP_MCP_AI_Place_Settings_Page' ) ) {
			require_once dirname( __DIR__ ) . '/includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';
			require_once dirname( __DIR__ ) . '/includes/admin/class-wp-mcp-ai-place-settings-page.php';
		}

		$settings_page = new WP_MCP_AI_Place_Settings_Page();

		$input = array(
			'assistant_id'    => $this->assistant_id,
			'enable_research' => '1',
		);

		$sanitized = $settings_page->sanitize_settings( $input );

		$this->assertEquals( $this->assistant_id, $sanitized['assistant_id'] );
		$this->assertTrue( $sanitized['enable_research'] );
	}

	/**
	 * Test ECA Settings Page sanitizes assistant_id correctly.
	 */
	public function test_eca_settings_sanitize_assistant_id() {
		if ( ! class_exists( 'WP_MCP_AI_ECA_Settings_Page' ) ) {
			require_once dirname( __DIR__ ) . '/includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';
			require_once dirname( __DIR__ ) . '/includes/admin/class-wp-mcp-ai-eca-settings-page.php';
		}

		$settings_page = new WP_MCP_AI_ECA_Settings_Page();

		$input = array(
			'assistant_id'    => $this->assistant_id,
			'enable_research' => '1',
		);

		$sanitized = $settings_page->sanitize_settings( $input );

		$this->assertEquals( $this->assistant_id, $sanitized['assistant_id'] );
		$this->assertTrue( $sanitized['enable_research'] );
	}

	/**
	 * Test Policy Settings Page sanitizes assistant_id correctly.
	 */
	public function test_policy_settings_sanitize_assistant_id() {
		if ( ! class_exists( 'WP_MCP_AI_Policy_Settings_Page' ) ) {
			require_once dirname( __DIR__ ) . '/includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';
			require_once dirname( __DIR__ ) . '/includes/admin/class-wp-mcp-ai-policy-settings-page.php';
		}

		$settings_page = new WP_MCP_AI_Policy_Settings_Page();

		$input = array(
			'assistant_id'    => $this->assistant_id,
			'enable_research' => '1',
		);

		$sanitized = $settings_page->sanitize_settings( $input );

		$this->assertEquals( $this->assistant_id, $sanitized['assistant_id'] );
		$this->assertTrue( $sanitized['enable_research'] );
	}

	/**
	 * Test Project Settings Page sanitizes assistant_id correctly.
	 */
	public function test_project_settings_sanitize_assistant_id() {
		if ( ! class_exists( 'WP_MCP_AI_Project_Settings_Page' ) ) {
			require_once dirname( __DIR__ ) . '/includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';
			require_once dirname( __DIR__ ) . '/includes/admin/class-wp-mcp-ai-project-settings-page.php';
		}

		$settings_page = new WP_MCP_AI_Project_Settings_Page();

		$input = array( 'assistant_id' => $this->assistant_id );

		$sanitized = $settings_page->sanitize_settings( $input );

		$this->assertEquals( $this->assistant_id, $sanitized['assistant_id'] );
	}

	/**
	 * Test that enable_research checkbox defaults to false when not checked.
	 */
	public function test_enable_research_defaults_to_false() {
		if ( ! class_exists( 'WP_MCP_AI_Quiz_Settings_Page' ) ) {
			require_once dirname( __DIR__ ) . '/includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';
			require_once dirname( __DIR__ ) . '/includes/admin/class-wp-mcp-ai-quiz-settings-page.php';
		}

		$settings_page = new WP_MCP_AI_Quiz_Settings_Page();

		// Submit without enable_research checkbox (unchecked).
		$input = array(
			'assistant_id'          => $this->assistant_id,
			'default_time_limit'    => '30',
			'default_passing_score' => '75',
		);

		$sanitized = $settings_page->sanitize_settings( $input );

		$this->assertFalse( $sanitized['enable_research'] );
	}

	/**
	 * Test that invalid assistant IDs are sanitized to 0.
	 */
	public function test_invalid_assistant_id_sanitized() {
		if ( ! class_exists( 'WP_MCP_AI_Quiz_Settings_Page' ) ) {
			require_once dirname( __DIR__ ) . '/includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';
			require_once dirname( __DIR__ ) . '/includes/admin/class-wp-mcp-ai-quiz-settings-page.php';
		}

		$settings_page = new WP_MCP_AI_Quiz_Settings_Page();

		// Test with invalid string.
		$input     = array( 'assistant_id' => 'invalid' );
		$sanitized = $settings_page->sanitize_settings( $input );
		$this->assertEquals( 0, $sanitized['assistant_id'] );

		// Test with negative number.
		$input     = array( 'assistant_id' => '-5' );
		$sanitized = $settings_page->sanitize_settings( $input );
		$this->assertEquals( 0, $sanitized['assistant_id'] );
	}
}
