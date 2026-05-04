<?php
/**
 * Tests for WP_MCP_AI_Workflow_Trigger_Registry.
 *
 * @package WP_MCP_AI
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Test suite for the workflow trigger registry.
 *
 * @since 1.6.0
 */
class Test_Workflow_Trigger_Registry extends WP_UnitTestCase {

	/**
	 * Registry instance.
	 *
	 * @var WP_MCP_AI_Workflow_Trigger_Registry
	 */
	protected $registry;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WP_MCP_AI_Workflow_Trigger_Registry' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Workflow_Trigger_Registry not loaded.' );
		}
		// Reset singleton so each test starts fresh.
		$ref = new ReflectionProperty( WP_MCP_AI_Workflow_Trigger_Registry::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
		$this->registry = WP_MCP_AI_Workflow_Trigger_Registry::get_instance();
	}

	/**
	 * Test that the singleton is returned.
	 */
	public function test_get_instance_returns_same_object() {
		$a = WP_MCP_AI_Workflow_Trigger_Registry::get_instance();
		$b = WP_MCP_AI_Workflow_Trigger_Registry::get_instance();
		$this->assertSame( $a, $b );
	}

	/**
	 * Test that all built-in trigger types are registered.
	 */
	public function test_builtin_trigger_types_registered() {
		$expected = array(
			'post_status_change',
			'cron_schedule',
			'rest_webhook',
			'a2a_inbound',
			'user_registration',
			'comment_published',
			'file_upload',
		);
		$triggers = $this->registry->get_triggers();
		foreach ( $expected as $type ) {
			$this->assertArrayHasKey( $type, $triggers, "Built-in trigger type '{$type}' should be registered." );
		}
	}

	/**
	 * Test register() and get_trigger() round-trip.
	 */
	public function test_register_and_get_trigger() {
		$this->registry->register(
			'test_custom_trigger',
			array(
				'label'         => 'Custom Test',
				'description'   => 'A custom trigger for testing.',
				'handler_class' => 'stdClass',
				'schema'        => array(),
			)
		);

		$trigger = $this->registry->get_trigger( 'test_custom_trigger' );
		$this->assertIsArray( $trigger );
		$this->assertSame( 'Custom Test', $trigger['label'] );
	}

	/**
	 * Test get_trigger returns false for unknown type.
	 */
	public function test_get_trigger_returns_false_for_unknown() {
		$result = $this->registry->get_trigger( 'nonexistent_type_xyz' );
		$this->assertFalse( $result );
	}

	/**
	 * Test get_triggers returns an array.
	 */
	public function test_get_triggers_returns_array() {
		$triggers = $this->registry->get_triggers();
		$this->assertIsArray( $triggers );
		$this->assertNotEmpty( $triggers );
	}

	/**
	 * Test wp_mcp_ai_register_workflow_triggers action fires.
	 */
	public function test_register_workflow_triggers_action_fires() {
		$ref = new ReflectionProperty( WP_MCP_AI_Workflow_Trigger_Registry::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );

		$fired = false;
		add_action(
			'wp_mcp_ai_register_workflow_triggers',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		WP_MCP_AI_Workflow_Trigger_Registry::get_instance();
		$this->assertTrue( $fired, 'wp_mcp_ai_register_workflow_triggers action should fire on instantiation.' );
	}

	/**
	 * Test each built-in trigger has required config keys.
	 */
	public function test_builtin_triggers_have_required_keys() {
		$required = array( 'label', 'description' );
		foreach ( $this->registry->get_triggers() as $type => $config ) {
			foreach ( $required as $key ) {
				$this->assertArrayHasKey( $key, $config, "Trigger '{$type}' missing key '{$key}'." );
			}
		}
	}
}
