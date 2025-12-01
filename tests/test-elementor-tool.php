<?php
/**
 * Tests for the Elementor templates tool.
 *
 * @package WP_MCP_AI
 */

class WP_MCP_AI_Elementor_Tool_Availability_Test extends WP_UnitTestCase {
	/**
	 * Ensure the tool reports missing dependencies when Elementor is inactive.
	 */
	public function test_tool_requires_elementor() {
		if (
			defined( 'ELEMENTOR_VERSION' ) ||
			class_exists( '\\Elementor\\Plugin', false ) ||
			defined( 'ELEMENTOR_PRO_VERSION' ) ||
			class_exists( '\\ElementorPro\\Plugin', false )
		) {
			$this->markTestSkipped( 'Elementor is already loaded for execution tests.' );
		}

		if ( function_exists( 'wp_mcp_ai_bootstrap' ) ) {
			wp_mcp_ai_bootstrap();
		}

		$tool   = new WP_MCP_AI_Tool_Get_Elementor_Templates();
		$result = $tool->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_elementor_missing', $result->get_error_code() );
	}
}

class WP_MCP_AI_Elementor_Tool_Execution_Test extends WP_UnitTestCase {
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		require_once __DIR__ . '/helpers/elementor-stubs.php';

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '999.0-test' );
		}

		if ( function_exists( 'wp_mcp_ai_bootstrap' ) ) {
			wp_mcp_ai_bootstrap();
		}
	}

	public function setUp(): void {
		parent::setUp();

		wp_mcp_ai_register_elementor_library_post_type();
		wp_set_current_user( 0 );
	}

	/**
	 * Ensure unauthenticated requests are rejected.
	 */
	public function test_requires_login() {
		$tool   = new WP_MCP_AI_Tool_Get_Elementor_Templates();
		$result = $tool->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Ensure capability checks are enforced.
	 */
	public function test_requires_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Get_Elementor_Templates();
		$result = $tool->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Ensure Elementor templates are returned with metadata when permissions allow it.
	 */
	public function test_returns_filtered_templates() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$header_id = self::factory()->post->create(
			array(
				'post_type'   => 'elementor_library',
				'post_status' => 'publish',
				'post_title'  => 'Header Template',
			)
		);

		add_post_meta( $header_id, '_elementor_template_type', 'header' );

		$footer_id = self::factory()->post->create(
			array(
				'post_type'   => 'elementor_library',
				'post_status' => 'draft',
				'post_title'  => 'Footer Template',
			)
		);

		add_post_meta( $footer_id, '_elementor_template_type', 'footer' );

		$tool   = new WP_MCP_AI_Tool_Get_Elementor_Templates();
		$result = $tool->execute(
			array(
				'limit'         => 5,
				'template_type' => 'HEADER',
				'status'        => array( 'Publish', 'draft', 'invalid' ),
				'search'        => 'Header',
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );

		$template = $result[0];

		$this->assertSame( $header_id, $template['id'] );
		$this->assertSame( 'Header Template', $template['title'] );
		$this->assertSame( 'publish', $template['status'] );
		$this->assertSame( 'header', $template['template_type'] );
		$this->assertNotEmpty( $template['edit_link'] );
		$this->assertNotEmpty( $template['date_created'] );
		$this->assertNotEmpty( $template['date_modified'] );
		$this->assertIsArray( $template['author'] );
		$this->assertSame( $admin_id, $template['author']['id'] );
	}

	/**
	 * Ensure Elementor Pro still passes availability checks when active.
	 */
	public function test_supports_elementor_pro() {
		if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			define( 'ELEMENTOR_PRO_VERSION', '999.0-pro-test' );
		}

		$this->assertTrue( WP_MCP_AI_Tool_Get_Elementor_Templates::is_available() );
	}
}
