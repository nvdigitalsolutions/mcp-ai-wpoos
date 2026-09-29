<?php
/**
 * Test: Email Tools (MCP tool contracts).
 *
 * @package NV_oOS_Design_System
 */

/**
 * Tests for the AI email-template tools: slugs, schemas, capabilities, and
 * canonical return envelopes.
 */
class Test_Email_Tools extends WP_UnitTestCase {

	/**
	 * Tool class list.
	 *
	 * @var array<int, class-string>
	 */
	private $tool_classes = array(
		'NV_oOS_Design_System_Tool_List_Email_Templates',
		'NV_oOS_Design_System_Tool_Preview_Email_Template',
		'NV_oOS_Design_System_Tool_Audit_Email_Template',
		'NV_oOS_Design_System_Tool_Set_Active_Email_Template',
		'NV_oOS_Design_System_Tool_Test_Send_Email',
		'NV_oOS_Design_System_Tool_Generate_Email_Template',
		'NV_oOS_Design_System_Tool_Export_Email_Template',
		'NV_oOS_Design_System_Tool_Import_Email_Template',
	);

	/**
	 * Every tool implements the NV oOS tool interface (when the base plugin
	 * is loaded) and exposes the admin capability.
	 *
	 * @return void
	 */
	public function test_tools_implement_interface_and_capability() {
		foreach ( $this->tool_classes as $class ) {
			$this->assertTrue( class_exists( $class ), $class . ' should exist.' );

			$tool = new $class();

			$this->assertNotEmpty( $tool->get_slug(), $class . ' should expose a slug.' );
			$this->assertStringStartsWith( 'nds_', $tool->get_slug(), 'Tool slugs should share the nds_ prefix.' );
			$this->assertSame( 'manage_options', $tool->get_required_capability(), $class . ' should be admin-gated.' );

			$schema = $tool->get_parameters_schema();
			$this->assertSame( 'object', $schema['type'] );
			$this->assertArrayHasKey( 'properties', $schema );
		}
	}

	/**
	 * The list tool returns a canonical success envelope.
	 *
	 * @return void
	 */
	public function test_list_tool_returns_success_envelope() {
		$tool   = new NV_oOS_Design_System_Tool_List_Email_Templates();
		$result = $tool->execute( array(), array() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'templates', $result );
		$this->assertIsArray( $result['templates'] );
	}

	/**
	 * The audit tool accepts raw HTML and returns a scorecard.
	 *
	 * @return void
	 */
	public function test_audit_tool_accepts_raw_html() {
		$tool = new NV_oOS_Design_System_Tool_Audit_Email_Template();

		$html   = '<!-- nds-email-wrapper:test --><!DOCTYPE html><html lang="en"><body><h1>Hi</h1></body></html>';
		$result = $tool->execute( array( 'html' => $html ), array() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'audit', $result );
		$this->assertIsInt( $result['audit']['score'] );
	}

	/**
	 * The set-active tool rejects unknown slugs with a WP_Error (canonical
	 * envelope rule: failures are WP_Error, never a success-false array).
	 *
	 * @return void
	 */
	public function test_set_active_rejects_unknown_slug() {
		$tool   = new NV_oOS_Design_System_Tool_Set_Active_Email_Template();
		$result = $tool->execute( array( 'slug' => 'does-not-exist-xyz' ), array() );

		$this->assertWPError( $result, 'Unknown slugs must return a WP_Error.' );
	}

	/**
	 * The preview tool rejects unknown slugs with a WP_Error.
	 *
	 * @return void
	 */
	public function test_preview_rejects_unknown_slug() {
		$tool   = new NV_oOS_Design_System_Tool_Preview_Email_Template();
		$result = $tool->execute( array( 'slug' => 'does-not-exist-xyz' ), array() );

		$this->assertWPError( $result );
	}

	/**
	 * The generate tool rejects empty prompts without touching the network.
	 *
	 * @return void
	 */
	public function test_generate_rejects_empty_prompt() {
		$tool   = new NV_oOS_Design_System_Tool_Generate_Email_Template();
		$result = $tool->execute( array( 'prompt' => '' ), array() );

		$this->assertWPError( $result );
	}

	/**
	 * The export tool rejects unknown slugs with a WP_Error.
	 *
	 * @return void
	 */
	public function test_export_rejects_unknown_slug() {
		$tool   = new NV_oOS_Design_System_Tool_Export_Email_Template();
		$result = $tool->execute( array( 'slug' => 'does-not-exist-xyz' ), array() );

		$this->assertWPError( $result );
	}

	/**
	 * The import tool rejects invalid payloads.
	 *
	 * @return void
	 */
	public function test_import_rejects_invalid_payload() {
		$tool   = new NV_oOS_Design_System_Tool_Import_Email_Template();
		$result = $tool->execute( array( 'json' => '{"not":"a template"}' ), array() );

		$this->assertWPError( $result );
	}
}
