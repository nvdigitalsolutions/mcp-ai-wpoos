<?php
/**
 * Tests for Cloud Vision API tools.
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-cloud-vision-product-search.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-cloud-vision-object-localization.php';

/**
 * Test suite for Cloud Vision tools registration and basic functionality.
 */
class WP_MCP_AI_Cloud_Vision_Tools_Test extends WP_UnitTestCase {

	/**
	 * Clean up global state after each test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Test that Product Search tool is registered with correct metadata.
	 */
	public function test_product_search_tool_registration() {
		$tool = new WP_MCP_AI_Tool_Cloud_Vision_Product_Search();

		$this->assertSame( 'cloud_vision_product_search', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );

		$schema = $tool->get_parameters_schema();
		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );

		// Verify required parameters.
		$this->assertContains( 'project_id', $schema['required'] );
		$this->assertContains( 'location', $schema['required'] );
		$this->assertContains( 'product_set_id', $schema['required'] );
	}

	/**
	 * Test that Object Localization tool is registered with correct metadata.
	 */
	public function test_object_localization_tool_registration() {
		$tool = new WP_MCP_AI_Tool_Cloud_Vision_Object_Localization();

		$this->assertSame( 'cloud_vision_object_localization', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );

		$schema = $tool->get_parameters_schema();
		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertArrayHasKey( 'properties', $schema );

		// Verify image input options.
		$this->assertArrayHasKey( 'image_url', $schema['properties'] );
		$this->assertArrayHasKey( 'attachment_id', $schema['properties'] );
		$this->assertArrayHasKey( 'max_results', $schema['properties'] );
	}

	/**
	 * Test that Product Search requires admin capability.
	 */
	public function test_product_search_requires_admin_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Cloud_Vision_Product_Search();
		$result = $tool->execute(
			array(
				'project_id'     => 'test-project',
				'location'       => 'us-east1',
				'product_set_id' => 'test-set',
				'image_url'      => 'https://example.com/image.jpg',
			),
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'permission_denied', $result->get_error_code() );
	}

	/**
	 * Test that Object Localization requires edit_posts capability.
	 */
	public function test_object_localization_requires_edit_posts_capability() {
		wp_set_current_user( 0 );

		$tool   = new WP_MCP_AI_Tool_Cloud_Vision_Object_Localization();
		$result = $tool->execute(
			array(
				'image_url' => 'https://example.com/image.jpg',
			),
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'permission_denied', $result->get_error_code() );
	}

	/**
	 * Test that Product Search requires API credentials.
	 */
	public function test_product_search_requires_credentials() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		$tool   = new WP_MCP_AI_Tool_Cloud_Vision_Product_Search();
		$result = $tool->execute(
			array(
				'project_id'     => 'test-project',
				'location'       => 'us-east1',
				'product_set_id' => 'test-set',
				'image_url'      => 'https://example.com/image.jpg',
			),
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'missing_credentials', $result->get_error_code() );
	}

	/**
	 * Test that Object Localization requires API credentials.
	 */
	public function test_object_localization_requires_credentials() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		$tool   = new WP_MCP_AI_Tool_Cloud_Vision_Object_Localization();
		$result = $tool->execute(
			array(
				'image_url' => 'https://example.com/image.jpg',
			),
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'missing_credentials', $result->get_error_code() );
	}

	/**
	 * Test that Product Search validates required parameters.
	 */
	public function test_product_search_validates_required_parameters() {
		$settings                             = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['google_vision_api_key']    = 'test-api-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Tool_Cloud_Vision_Product_Search();
		$result = $tool->execute(
			array(
				'image_url' => 'https://example.com/image.jpg',
			),
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'missing_parameters', $result->get_error_code() );
	}

	/**
	 * Test that tools are registered in the tool registry.
	 */
	public function test_tools_registered_in_registry() {
		WP_MCP_AI_Tool_Registry::get_instance()->init();

		$product_search_tool = WP_MCP_AI_Tool_Registry::get_instance()->get_tool( 'cloud_vision_product_search' );
		$this->assertInstanceOf( WP_MCP_AI_Tool_Interface::class, $product_search_tool );

		$object_localization_tool = WP_MCP_AI_Tool_Registry::get_instance()->get_tool( 'cloud_vision_object_localization' );
		$this->assertInstanceOf( WP_MCP_AI_Tool_Interface::class, $object_localization_tool );
	}
}
