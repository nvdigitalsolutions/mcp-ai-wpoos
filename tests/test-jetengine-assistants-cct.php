<?php
/**
 * Tests for JetEngine assistants Custom Content Type registration.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for JetEngine assistants CCT.
 */
class WP_MCP_AI_JetEngine_Assistants_CCT_Test extends WP_UnitTestCase {

	/**
	 * Test that the CCT slug is returned correctly.
	 */
	public function test_get_slug_returns_correct_value() {
		$this->assertSame( 'assistants', WP_MCP_AI_JetEngine_Assistants_CCT::get_slug() );
	}

	/**
	 * Test that registration request is properly structured.
	 */
	public function test_registration_request_structure() {
		$reflection = new ReflectionMethod( WP_MCP_AI_JetEngine_Assistants_CCT::class, 'get_registration_request' );
		$reflection->setAccessible( true );
		$request = $reflection->invoke( null );

		$this->assertIsArray( $request );
		$this->assertArrayHasKey( 'name', $request );
		$this->assertArrayHasKey( 'slug', $request );
		$this->assertArrayHasKey( 'args', $request );
		$this->assertArrayHasKey( 'meta_fields', $request );
		$this->assertSame( 'assistants', $request['slug'] );
	}

	/**
	 * Test that CCT args are properly configured.
	 */
	public function test_cct_args_configuration() {
		$reflection = new ReflectionMethod( WP_MCP_AI_JetEngine_Assistants_CCT::class, 'get_cct_args' );
		$reflection->setAccessible( true );
		$args = $reflection->invoke( null, 'AI Assistants' );

		$this->assertIsArray( $args );
		$this->assertArrayHasKey( 'slug', $args );
		$this->assertArrayHasKey( 'icon', $args );
		$this->assertArrayHasKey( 'capability', $args );
		$this->assertArrayHasKey( 'rest_get_enabled', $args );
		$this->assertArrayHasKey( 'rest_post_enabled', $args );
		$this->assertArrayHasKey( 'rest_put_enabled', $args );
		$this->assertArrayHasKey( 'rest_delete_enabled', $args );

		$this->assertSame( 'assistants', $args['slug'] );
		$this->assertSame( 'dashicons-lightbulb', $args['icon'] );
		$this->assertSame( 'manage_options', $args['capability'] );
		$this->assertTrue( $args['rest_get_enabled'] );
		$this->assertTrue( $args['rest_post_enabled'] );
		$this->assertTrue( $args['rest_put_enabled'] );
		$this->assertTrue( $args['rest_delete_enabled'] );
	}

	/**
	 * Test that meta fields are properly defined.
	 */
	public function test_meta_fields_configuration() {
		$reflection = new ReflectionMethod( WP_MCP_AI_JetEngine_Assistants_CCT::class, 'get_meta_fields' );
		$reflection->setAccessible( true );
		$fields = $reflection->invoke( null );

		$this->assertIsArray( $fields );
		$this->assertNotEmpty( $fields );

		// Check that essential fields are present.
		$field_names = array_column( $fields, 'name' );
		$this->assertContains( 'title', $field_names );
		$this->assertContains( 'description', $field_names );
		$this->assertContains( 'provider', $field_names );
		$this->assertContains( 'model', $field_names );
		$this->assertContains( 'system_prompt', $field_names );
		$this->assertContains( 'temperature', $field_names );
		$this->assertContains( 'tools', $field_names );

		// Verify all fields have show_in_rest enabled.
		foreach ( $fields as $field ) {
			$this->assertArrayHasKey( 'show_in_rest', $field );
			$this->assertTrue( $field['show_in_rest'], "Field {$field['name']} should have show_in_rest enabled" );
		}
	}

	/**
	 * Test that title field is marked as required.
	 */
	public function test_title_field_is_required() {
		$reflection = new ReflectionMethod( WP_MCP_AI_JetEngine_Assistants_CCT::class, 'get_meta_fields' );
		$reflection->setAccessible( true );
		$fields = $reflection->invoke( null );

		$title_field = null;
		foreach ( $fields as $field ) {
			if ( 'title' === $field['name'] ) {
				$title_field = $field;
				break;
			}
		}

		$this->assertNotNull( $title_field, 'Title field should exist' );
		$this->assertArrayHasKey( 'is_required', $title_field );
		$this->assertTrue( $title_field['is_required'], 'Title field should be required' );
	}

	/**
	 * Test that temperature field has proper numeric constraints.
	 */
	public function test_temperature_field_has_numeric_constraints() {
		$reflection = new ReflectionMethod( WP_MCP_AI_JetEngine_Assistants_CCT::class, 'get_meta_fields' );
		$reflection->setAccessible( true );
		$fields = $reflection->invoke( null );

		$temperature_field = null;
		foreach ( $fields as $field ) {
			if ( 'temperature' === $field['name'] ) {
				$temperature_field = $field;
				break;
			}
		}

		$this->assertNotNull( $temperature_field, 'Temperature field should exist' );
		$this->assertSame( 'number', $temperature_field['type'] );
		$this->assertArrayHasKey( 'min', $temperature_field );
		$this->assertArrayHasKey( 'max', $temperature_field );
		$this->assertArrayHasKey( 'step', $temperature_field );
		$this->assertSame( 0, $temperature_field['min'] );
		$this->assertSame( 2, $temperature_field['max'] );
		$this->assertSame( 0.1, $temperature_field['step'] );
	}

	/**
	 * Test that field builder creates properly structured fields.
	 */
	public function test_build_field_creates_valid_structure() {
		$reflection = new ReflectionMethod( WP_MCP_AI_JetEngine_Assistants_CCT::class, 'build_field' );
		$reflection->setAccessible( true );

		$field = $reflection->invoke( null, 12345, 'test_field', 'Test Field', 'text' );

		$this->assertIsArray( $field );
		$this->assertArrayHasKey( 'id', $field );
		$this->assertArrayHasKey( 'name', $field );
		$this->assertArrayHasKey( 'title', $field );
		$this->assertArrayHasKey( 'type', $field );
		$this->assertArrayHasKey( 'object_type', $field );
		$this->assertArrayHasKey( 'width', $field );

		$this->assertSame( 12345, $field['id'] );
		$this->assertSame( 'test_field', $field['name'] );
		$this->assertSame( 'Test Field', $field['title'] );
		$this->assertSame( 'text', $field['type'] );
		$this->assertSame( 'field', $field['object_type'] );
		$this->assertSame( '100%', $field['width'] );
	}

	/**
	 * Test that field builder accepts and applies overrides.
	 */
	public function test_build_field_applies_overrides() {
		$reflection = new ReflectionMethod( WP_MCP_AI_JetEngine_Assistants_CCT::class, 'build_field' );
		$reflection->setAccessible( true );

		$overrides = array(
			'is_required' => true,
			'description' => 'Custom description',
			'width'       => '50%',
		);

		$field = $reflection->invoke( null, 12345, 'test_field', 'Test Field', 'text', $overrides );

		$this->assertTrue( $field['is_required'] );
		$this->assertSame( 'Custom description', $field['description'] );
		$this->assertSame( '50%', $field['width'] );
	}

	/**
	 * Test that get_item_handler returns null when JetEngine is not available.
	 */
	public function test_get_item_handler_returns_null_when_jetengine_unavailable() {
		// This test assumes JetEngine is not loaded in the test environment.
		// In a real WordPress installation with JetEngine, this would return an object.
		$handler = WP_MCP_AI_JetEngine_Assistants_CCT::get_item_handler();

		$this->assertNull( $handler, 'Item handler should be null when JetEngine is not available' );
	}

	/**
	 * Test that bootstrap registers the init hooks.
	 */
	public function test_bootstrap_registers_init_hooks() {
		// Remove any existing hooks to ensure clean state.
		remove_all_actions( 'init' );

		// Call bootstrap.
		WP_MCP_AI_JetEngine_Assistants_CCT::bootstrap();

		// Verify hooks are registered.
		$this->assertTrue( has_action( 'init' ) > 0, 'Init action should be registered' );
	}
}
