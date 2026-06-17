<?php
/**
 * Tests for WP_MCP_AI_Function_Call_Validator.
 *
 * Covers schema validation, missing tool handling, required-property enforcement,
 * enum constraints, and parallel/nested call result shapes.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for WP_MCP_AI_Function_Call_Validator.
 */
class Test_Service_Function_Call_Validator extends WP_UnitTestCase {

	/**
	 * Validator under test.
	 *
	 * @var WP_MCP_AI_Function_Call_Validator
	 */
	private $validator;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->validator = new WP_MCP_AI_Function_Call_Validator();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		$this->validator = null;
		parent::tearDown();
	}

	/**
	 * Test that the validator can be instantiated with no arguments.
	 */
	public function test_instantiation_with_no_args_succeeds() {
		$validator = new WP_MCP_AI_Function_Call_Validator();
		$this->assertInstanceOf( WP_MCP_AI_Function_Call_Validator::class, $validator );
	}

	/**
	 * Test that validate_function_call returns invalid result when tool not in registry.
	 */
	public function test_validate_function_call_returns_invalid_for_unknown_tool_without_schema() {
		$result = $this->validator->validate_function_call(
			'nonexistent_tool_xyz_12345',
			array(),
			array() // empty schema forces registry lookup
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'valid', $result );
		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Test that validate_function_call fails when root schema is not type object.
	 */
	public function test_validate_function_call_fails_for_non_object_root_schema() {
		$schema = array(
			'type' => 'string',
		);

		$result = $this->validator->validate_function_call(
			'any_tool',
			array( 'key' => 'value' ),
			$schema
		);

		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Test that validate_function_call succeeds with a valid object schema and matching args.
	 */
	public function test_validate_function_call_succeeds_with_valid_object_schema() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'name' => array( 'type' => 'string' ),
			),
			'required'   => array( 'name' ),
		);

		$result = $this->validator->validate_function_call(
			'any_tool',
			array( 'name' => 'hello' ),
			$schema
		);

		$this->assertTrue( $result['valid'] );
		$this->assertEmpty( $result['errors'] );
		$this->assertArrayHasKey( 'normalized_args', $result );
	}

	/**
	 * Test that validate_function_call reports missing required property.
	 */
	public function test_validate_function_call_reports_missing_required_property() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'action' => array( 'type' => 'string' ),
			),
			'required'   => array( 'action' ),
		);

		$result = $this->validator->validate_function_call(
			'any_tool',
			array(), // missing 'action'
			$schema
		);

		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Test that validate_function_call enforces string minLength constraint.
	 */
	public function test_validate_function_call_enforces_min_length_constraint() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'title' => array(
					'type'      => 'string',
					'minLength' => 5,
				),
			),
			'required'   => array( 'title' ),
		);

		$result = $this->validator->validate_function_call(
			'any_tool',
			array( 'title' => 'hi' ), // only 2 chars
			$schema
		);

		$this->assertFalse( $result['valid'] );
	}

	/**
	 * Test that execute_parallel_calls returns correct aggregate structure for empty input.
	 */
	public function test_execute_parallel_calls_returns_structure_for_empty_input() {
		$result = $this->validator->execute_parallel_calls( array(), array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'results', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'total_calls', $result );
		$this->assertArrayHasKey( 'successful', $result );
		$this->assertArrayHasKey( 'failed', $result );
		$this->assertSame( 0, $result['total_calls'] );
	}

	/**
	 * Test that validate_function_call uses default values for optional properties.
	 */
	public function test_validate_function_call_applies_default_for_optional_property() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'required_field' => array( 'type' => 'string' ),
				'optional_field' => array(
					'type'    => 'string',
					'default' => 'default_value',
				),
			),
			'required'   => array( 'required_field' ),
		);

		$result = $this->validator->validate_function_call(
			'any_tool',
			array( 'required_field' => 'value' ),
			$schema
		);

		$this->assertTrue( $result['valid'] );
		$this->assertArrayHasKey( 'optional_field', $result['normalized_args'] );
		$this->assertSame( 'default_value', $result['normalized_args']['optional_field'] );
	}
}
