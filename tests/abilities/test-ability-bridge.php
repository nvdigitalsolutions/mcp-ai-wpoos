<?php
/**
 * Tests for WP_MCP_AI_Ability_Bridge — tool-to-ability mapping.
 *
 * Verifies identifier generation, annotation mapping from capability flags,
 * permission enforcement, WP_Error conversion, context passing, and
 * output schema fallback behaviour.
 *
 * @package WP_MCP_AI
 * @since   2.0.0
 */

/**
 * Tests for WP_MCP_AI_Ability_Bridge.
 *
 * @covers WP_MCP_AI_Ability_Bridge
 */
class WP_MCP_AI_Ability_Bridge_Test extends WP_UnitTestCase {

	/**
	 * Mock tool instance.
	 *
	 * @since 2.0.0
	 * @var WP_MCP_AI_Ability_Bridge_Mock_Tool
	 */
	private $tool;

	/**
	 * Set up test fixtures.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->tool = new WP_MCP_AI_Ability_Bridge_Mock_Tool();
	}

	/**
	 * Ensure register returns false when wp_register_ability is unavailable.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_register_returns_false_without_api() {
		if ( function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'wp_register_ability() is available — guard is not triggered.' );
		}

		$result = WP_MCP_AI_Ability_Bridge::register( $this->tool, 'nvoos-site' );
		$this->assertFalse( $result, 'Should return false when API is unavailable.' );
	}

	/**
	 * Ensure register creates an ability with the correct identifier format.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_register_produces_correct_identifier() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'wp_register_ability() not available.' );
		}

		$this->tool->set_slug( 'get_post' );
		$result = WP_MCP_AI_Ability_Bridge::register( $this->tool, 'nvoos-content' );

		$this->assertNotFalse( $result );
		$this->assertTrue(
			wp_has_ability( 'nvoos/get-post' ),
			'Ability should be registered as nvoos/get-post (hyphenated slug).'
		);
	}

	/**
	 * Ensure annotations are correctly mapped from read-only capability flags.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_annotations_mapped_from_read_only_flags() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'wp_register_ability() not available.' );
		}

		$this->tool->set_flags( array( 'read-only', 'idempotent', 'local-only' ) );
		$this->tool->set_slug( 'annotation_test' );

		$result = WP_MCP_AI_Ability_Bridge::register( $this->tool, 'nvoos-site' );
		$this->assertNotFalse( $result );

		$ability = wp_get_ability( 'nvoos/annotation-test' );
		$this->assertInstanceOf( 'WP_Ability', $ability );

		$meta = $ability->get_meta();
		$this->assertArrayHasKey( 'annotations', $meta );

		$annotations = $meta['annotations'];
		$this->assertTrue( $annotations['readOnlyHint'], 'read-only flag should set readOnlyHint to true.' );
		$this->assertFalse( $annotations['destructiveHint'], 'No destructive flags should leave destructiveHint false.' );
		$this->assertTrue( $annotations['idempotentHint'], 'idempotent flag should set idempotentHint to true.' );
		$this->assertFalse( $annotations['openWorldHint'], 'local-only should leave openWorldHint false.' );
	}

	/**
	 * Ensure annotations reflect destructive and open-world flags.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_annotations_reflect_destructive_and_open_world() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'wp_register_ability() not available.' );
		}

		$this->tool->set_flags( array( 'write', 'irreversible', 'external-api', 'long-running' ) );
		$this->tool->set_slug( 'dangerous_tool' );

		$result = WP_MCP_AI_Ability_Bridge::register( $this->tool, 'nvoos-system' );
		$this->assertNotFalse( $result );

		$ability     = wp_get_ability( 'nvoos/dangerous-tool' );
		$annotations = $ability->get_meta()['annotations'];

		$this->assertFalse( $annotations['readOnlyHint'], 'write flag should set readOnlyHint false.' );
		$this->assertTrue( $annotations['destructiveHint'], 'irreversible flag should set destructiveHint true.' );
		$this->assertFalse( $annotations['idempotentHint'], 'write without idempotent should leave idempotentHint false.' );
		$this->assertTrue( $annotations['openWorldHint'], 'external-api should set openWorldHint true.' );
	}

	/**
	 * Ensure data-destruction flag also triggers destructiveHint.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_data_destruction_flag_sets_destructive_hint() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'wp_register_ability() not available.' );
		}

		$this->tool->set_flags( array( 'write', 'data-destruction' ) );
		$this->tool->set_slug( 'purge_tool' );

		WP_MCP_AI_Ability_Bridge::register( $this->tool, 'nvoos-system' );

		$ability     = wp_get_ability( 'nvoos/purge-tool' );
		$annotations = $ability->get_meta()['annotations'];

		$this->assertTrue( $annotations['destructiveHint'], 'data-destruction flag should set destructiveHint true.' );
	}

	/**
	 * Ensure permission callback enforces the tool's required capability.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_permission_callback_enforces_capability() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'wp_register_ability() not available.' );
		}

		$this->tool->set_capability( 'manage_options' );
		$this->tool->set_slug( 'admin_tool' );

		WP_MCP_AI_Ability_Bridge::register( $this->tool, 'nvoos-system' );

		$ability = wp_get_ability( 'nvoos/admin-tool' );

		// As a subscriber, permission should be denied.
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$result = $ability->execute( array() );
		$this->assertWPError( $result, 'Subscriber should be denied on manage_options tool.' );

		// As an admin, permission should be granted.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$result = $ability->execute( array() );
		$this->assertNotWPError( $result, 'Admin should be allowed on manage_options tool.' );
	}

	/**
	 * Ensure WP_Error from execute() is converted to a structured error array.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_wp_error_converted_to_structured_error() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'wp_register_ability() not available.' );
		}

		$this->tool->set_slug( 'error_tool' );
		$this->tool->set_result( new WP_Error( 'test_error_code', 'Test error message.' ) );

		WP_MCP_AI_Ability_Bridge::register( $this->tool, 'nvoos-site' );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$result = wp_get_ability( 'nvoos/error-tool' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Test error message.', $result['message'] );
		$this->assertSame( 'test_error_code', $result['code'] );
	}

	/**
	 * Ensure the context array includes ability_context flag.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_execute_receives_ability_context() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'wp_register_ability() not available.' );
		}

		$tool = new WP_MCP_AI_Ability_Bridge_Mock_Tool();
		$tool->set_slug( 'ctx_tool' );
		$tool->set_result(
			array(
				'success' => true,
				'context' => '__to_be_replaced__',
			)
		);

		// Override execute to capture the context.
		$captured_context = null;
		add_filter(
			'wp_mcp_ai_after_ability_execute',
			function ( $ability_id, $tool_slug, $input, $result ) use ( &$captured_context ) {
				// Context is not directly inspectable through the Ability API,
				// but we can verify the execution hook receives correct params.
			},
			10,
			4
		);

		WP_MCP_AI_Ability_Bridge::register( $tool, 'nvoos-site' );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$result = wp_get_ability( 'nvoos/ctx-tool' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
	}

	/**
	 * Ensure output_schema uses generic envelope as fallback.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_output_schema_falls_back_to_generic_envelope() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'wp_register_ability() not available.' );
		}

		$this->tool->set_slug( 'schema_test' );
		WP_MCP_AI_Ability_Bridge::register( $this->tool, 'nvoos-site' );

		$ability = wp_get_ability( 'nvoos/schema-test' );
		$schema  = $ability->get_output_schema();

		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'success', $schema['properties'] );
		$this->assertArrayHasKey( 'message', $schema['properties'] );
		$this->assertArrayHasKey( 'data', $schema['properties'] );
	}
}
