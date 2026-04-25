<?php
/**
 * Tests for the Schema Verifier.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test Schema Verifier.
 */
class Test_WP_MCP_AI_Schema_Verifier extends WP_UnitTestCase {

	/**
	 * No schema => trivial pass.
	 */
	public function test_empty_schema_passes() {
		$v = new WP_MCP_AI_Schema_Verifier();
		$this->assertTrue( $v->verify( array( 'value' => array() ) )['passed'] );
	}

	/**
	 * Type string.
	 */
	public function test_type_string() {
		$v = new WP_MCP_AI_Schema_Verifier(
			'schema_verifier',
			array( 'type' => 'string', 'minLength' => 2 )
		);
		$this->assertTrue( $v->verify( array( 'value' => 'hi' ) )['passed'] );
		$this->assertFalse( $v->verify( array( 'value' => 'x' ) )['passed'] );
		$this->assertFalse( $v->verify( array( 'value' => 42 ) )['passed'] );
	}

	/**
	 * Object with required properties.
	 */
	public function test_object_required() {
		$v = new WP_MCP_AI_Schema_Verifier(
			'schema_verifier',
			array(
				'type'       => 'object',
				'required'   => array( 'name', 'age' ),
				'properties' => array(
					'name' => array( 'type' => 'string' ),
					'age'  => array( 'type' => 'integer', 'minimum' => 0 ),
				),
			)
		);
		$this->assertTrue( $v->verify( array( 'value' => array( 'name' => 'a', 'age' => 1 ) ) )['passed'] );
		$this->assertFalse( $v->verify( array( 'value' => array( 'name' => 'a' ) ) )['passed'] );
		$this->assertFalse( $v->verify( array( 'value' => array( 'name' => 'a', 'age' => -1 ) ) )['passed'] );
	}

	/**
	 * Enum.
	 */
	public function test_enum() {
		$v = new WP_MCP_AI_Schema_Verifier(
			'schema_verifier',
			array( 'enum' => array( 'a', 'b' ) )
		);
		$this->assertTrue( $v->verify( array( 'value' => 'a' ) )['passed'] );
		$this->assertFalse( $v->verify( array( 'value' => 'c' ) )['passed'] );
	}

	/**
	 * Array with items schema.
	 */
	public function test_array_items() {
		$v = new WP_MCP_AI_Schema_Verifier(
			'schema_verifier',
			array(
				'type'  => 'array',
				'items' => array( 'type' => 'integer', 'minimum' => 1 ),
			)
		);
		$this->assertTrue( $v->verify( array( 'value' => array( 1, 2, 3 ) ) )['passed'] );
		$this->assertFalse( $v->verify( array( 'value' => array( 1, 0, 3 ) ) )['passed'] );
		$this->assertFalse( $v->verify( array( 'value' => array( 1, 'x' ) ) )['passed'] );
	}

	/**
	 * Pattern + maxLength.
	 */
	public function test_string_pattern_and_max_length() {
		$v = new WP_MCP_AI_Schema_Verifier(
			'schema_verifier',
			array( 'type' => 'string', 'pattern' => '/^[a-z]+$/', 'maxLength' => 5 )
		);
		$this->assertTrue( $v->verify( array( 'value' => 'hello' ) )['passed'] );
		$this->assertFalse( $v->verify( array( 'value' => 'Hello' ) )['passed'] );
		$this->assertFalse( $v->verify( array( 'value' => 'hellos' ) )['passed'] );
	}

	/**
	 * When subject has no `value` key, the entire subject is validated.
	 */
	public function test_value_fallback() {
		$v = new WP_MCP_AI_Schema_Verifier(
			'schema_verifier',
			array( 'type' => 'object', 'required' => array( 'x' ) )
		);
		$this->assertTrue( $v->verify( array( 'x' => 1 ) )['passed'] );
		$this->assertFalse( $v->verify( array( 'y' => 1 ) )['passed'] );
	}
}
