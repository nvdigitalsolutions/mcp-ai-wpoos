<?php
/**
 * Characterization tests for the Wave F2 Pro services slice — the ported
 * `WP_MCP_AI_Validator_Service`.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the class
 *   (classmap-autoloaded); the byte-identical behavior is asserted.
 * - Standalone matrix (base plugin absent): the ported copy in
 *   `src/services/` is asserted in full, including the serving source.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Validator service tests.
 */
class Test_Validator_Service extends WP_UnitTestCase {

	/**
	 * The serving source must follow the ownership boundary.
	 */
	public function test_validator_serving_source(): void {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Validator_Service' );
		$file       = str_replace( '\\', '/', (string) $reflection->getFileName() );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertStringContainsString( 'addons/pro/includes/services/class-wp-mcp-ai-validator-service.php', $file );
		} else {
			$this->assertStringContainsString( 'nvoos-content-graph-pro/src/services/class-wp-mcp-ai-validator-service.php', $file );
		}
	}

	/**
	 * Email validation must keep the byte-identical contract: WP_Error for
	 * invalid, true for valid.
	 */
	public function test_is_email(): void {
		$validator = new WP_MCP_AI_Validator_Service();

		$this->assertTrue( $validator->is_email( 'user@example.com' ) );

		$invalid = $validator->is_email( 'not-an-email' );
		$this->assertInstanceOf( 'WP_Error', $invalid );
		$this->assertSame( 'invalid_email', $invalid->get_error_code() );
	}

	/**
	 * Phone validation must keep the byte-identical 10-15 digit contract.
	 */
	public function test_is_phone_number(): void {
		$validator = new WP_MCP_AI_Validator_Service();

		$this->assertTrue( $validator->is_phone_number( '+1 (555) 123-4567' ) );
		$this->assertTrue( $validator->is_phone_number( '1234567890' ) );

		$short = $validator->is_phone_number( '12345' );
		$this->assertInstanceOf( 'WP_Error', $short );
		$this->assertSame( 'invalid_phone', $short->get_error_code() );

		$empty = $validator->is_phone_number( '' );
		$this->assertInstanceOf( 'WP_Error', $empty );
		$this->assertSame( 'empty_phone', $empty->get_error_code() );
	}

	/**
	 * URL validation must keep the byte-identical filter_var contract.
	 */
	public function test_is_url(): void {
		$validator = new WP_MCP_AI_Validator_Service();

		$this->assertTrue( $validator->is_url( 'https://example.com/path' ) );

		$invalid = $validator->is_url( 'not a url' );
		$this->assertInstanceOf( 'WP_Error', $invalid );
		$this->assertSame( 'invalid_url', $invalid->get_error_code() );
	}

	/**
	 * Credit-card validation must keep the Luhn algorithm behavior.
	 */
	public function test_is_credit_card(): void {
		$validator = new WP_MCP_AI_Validator_Service();

		$this->assertTrue( $validator->is_credit_card( '4242 4242 4242 4242' ) );
		$this->assertFalse( $validator->is_credit_card( '4242 4242 4242 4241' ) );
	}

	/**
	 * Sanitization must keep the byte-identical per-type behavior.
	 */
	public function test_sanitize_input(): void {
		$validator = new WP_MCP_AI_Validator_Service();

		$this->assertSame( 'hello', $validator->sanitize_input( '  hello  ', 'text' ) );
		$this->assertSame( '+15551234567', $validator->sanitize_input( '+1 (555) 123-4567', 'phone' ) );
		$this->assertSame( 5, $validator->sanitize_input( '5', 'int' ) );
		$this->assertSame( 2.5, $validator->sanitize_input( '2.5', 'float' ) );
		$this->assertSame( 'user@example.com', $validator->sanitize_input( 'user@example.com', 'email' ) );
		$this->assertTrue( $validator->sanitize_input( 'on', 'bool' ) );
	}

	/**
	 * validate_fields must keep the required/type/sanitize envelope.
	 */
	public function test_validate_fields(): void {
		$validator = new WP_MCP_AI_Validator_Service();

		$missing = $validator->validate_fields(
			array(),
			array(
				'email' => array(
					'required' => true,
					'type'     => 'email',
					'sanitize' => 'email',
				),
			)
		);
		$this->assertInstanceOf( 'WP_Error', $missing );
		$this->assertSame( 'validation_failed', $missing->get_error_code() );
		$this->assertArrayHasKey( 'email', $missing->get_error_data() );

		$bad_email = $validator->validate_fields(
			array( 'email' => 'nope' ),
			array(
				'email' => array(
					'required' => true,
					'type'     => 'email',
					'sanitize' => 'email',
				),
			)
		);
		$this->assertInstanceOf( 'WP_Error', $bad_email );

		$valid = $validator->validate_fields(
			array( 'email' => 'user@example.com' ),
			array(
				'email' => array(
					'required' => true,
					'type'     => 'email',
					'sanitize' => 'email',
				),
			)
		);
		$this->assertSame( array( 'email' => 'user@example.com' ), $valid );
	}

	/**
	 * Disposable-domain detection must keep the byte-identical allowlist.
	 */
	public function test_is_disposable_email(): void {
		$validator = new WP_MCP_AI_Validator_Service();

		$this->assertTrue( $validator->is_disposable_email( 'user@mailinator.com' ) );
		$this->assertFalse( $validator->is_disposable_email( 'user@example.com' ) );
	}

	/**
	 * has_mx_records must degrade for malformed input without network I/O.
	 */
	public function test_has_mx_records_malformed(): void {
		$validator = new WP_MCP_AI_Validator_Service();
		$this->assertFalse( $validator->has_mx_records( 'no-at-sign' ) );
	}

	/**
	 * is_available must return a boolean in both matrices (the node_modules
	 * probe resolves against the addon's own path standalone).
	 */
	public function test_is_available_returns_bool(): void {
		$validator = new WP_MCP_AI_Validator_Service();
		$this->assertIsBool( $validator->is_available() );
	}
}
