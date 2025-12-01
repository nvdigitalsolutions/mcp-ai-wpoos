<?php
/**
 * Tests for Integrations Section - Meta Label
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test Integrations Section Meta label.
 */
class Test_Section_Integrations_Meta_Label extends WP_UnitTestCase {

	/**
	 * Test that integrations section is registered.
	 */
	public function test_integrations_section_is_registered() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'integrations_gmail_crawl4ai' );

		$this->assertInstanceOf( 'WP_MCP_AI_Section_Integrations', $section );
		$this->assertEquals( 'integrations_gmail_crawl4ai', $section->get_id() );
	}

	/**
	 * Test that Meta subtab has the correct label.
	 */
	public function test_meta_subtab_has_correct_label() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'integrations_gmail_crawl4ai' );

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );

		$subtabs = $method->invoke( $section );

		$this->assertIsArray( $subtabs );
		$this->assertArrayHasKey( 'meta', $subtabs );
		$this->assertEquals( 'Meta', $subtabs['meta']['label'] );
	}

	/**
	 * Test that Meta subtab label does not contain the long version.
	 */
	public function test_meta_subtab_label_is_not_too_long() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'integrations_gmail_crawl4ai' );

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );

		$subtabs = $method->invoke( $section );

		// The label should not contain the old long version.
		$this->assertArrayHasKey( 'meta', $subtabs );
		$this->assertNotEquals( 'Meta (Facebook/Instagram/WhatsApp)', $subtabs['meta']['label'] );
		$this->assertStringNotContainsString( 'Facebook/Instagram/WhatsApp', $subtabs['meta']['label'] );
	}

	/**
	 * Test that Meta subtab has the correct fields.
	 */
	public function test_meta_subtab_has_correct_fields() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'integrations_gmail_crawl4ai' );

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );

		$subtabs = $method->invoke( $section );

		$this->assertArrayHasKey( 'meta', $subtabs );
		$this->assertArrayHasKey( 'fields', $subtabs['meta'] );

		$expected_fields = array( 'meta_access_token', 'meta_app_id', 'meta_app_secret', 'meta_business_account_id' );
		$this->assertEquals( $expected_fields, $subtabs['meta']['fields'] );
	}
}
