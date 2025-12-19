<?php
/**
 * Tests for Symfony Validator integration
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Validator_Service
 *
 * Tests for the Symfony Validator service integration.
 */
class Test_WP_MCP_AI_Validator_Service extends WP_UnitTestCase {

	/**
	 * Validator service instance.
	 *
	 * @var WP_MCP_AI\Validators\WP_MCP_AI_Validator_Service
	 */
	private $validator_service;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load validator service.
		require_once dirname( __DIR__ ) . '/includes/validators/class-wp-mcp-ai-validator-service.php';
		$this->validator_service = \WP_MCP_AI\Validators\WP_MCP_AI_Validator_Service::get_instance();
	}

	/**
	 * Test that validator service is a singleton.
	 */
	public function test_validator_service_is_singleton() {
		$instance1 = \WP_MCP_AI\Validators\WP_MCP_AI_Validator_Service::get_instance();
		$instance2 = \WP_MCP_AI\Validators\WP_MCP_AI_Validator_Service::get_instance();

		$this->assertSame( $instance1, $instance2, 'Validator service should be a singleton' );
	}

	/**
	 * Test basic validation with NotBlank constraint.
	 */
	public function test_not_blank_validation() {
		$test_object = new class() {
			#[\Symfony\Component\Validator\Constraints\NotBlank]
			public $name = '';
		};

		$violations = $this->validator_service->validate( $test_object );
		$this->assertFalse( $this->validator_service->is_valid( $violations ), 'Empty string should violate NotBlank' );
		$this->assertCount( 1, $violations, 'Should have one violation' );
	}

	/**
	 * Test validation passes with valid data.
	 */
	public function test_validation_passes_with_valid_data() {
		$test_object = new class() {
			#[\Symfony\Component\Validator\Constraints\NotBlank]
			#[\Symfony\Component\Validator\Constraints\Length( min: 3 )]
			public $name = 'John Doe';
		};

		$violations = $this->validator_service->validate( $test_object );
		$this->assertTrue( $this->validator_service->is_valid( $violations ), 'Valid data should pass validation' );
		$this->assertCount( 0, $violations, 'Should have no violations' );
	}

	/**
	 * Test email validation constraint.
	 */
	public function test_email_validation() {
		$test_object = new class() {
			#[\Symfony\Component\Validator\Constraints\Email]
			public $email = 'invalid-email';
		};

		$violations = $this->validator_service->validate( $test_object );
		$this->assertFalse( $this->validator_service->is_valid( $violations ), 'Invalid email should fail validation' );

		$test_object->email = 'valid@example.com';
		$violations         = $this->validator_service->validate( $test_object );
		$this->assertTrue( $this->validator_service->is_valid( $violations ), 'Valid email should pass validation' );
	}

	/**
	 * Test format_errors returns WP_Error.
	 */
	public function test_format_errors_returns_wp_error() {
		$test_object = new class() {
			#[\Symfony\Component\Validator\Constraints\NotBlank( message: 'Name is required' )]
			public $name = '';
		};

		$violations = $this->validator_service->validate( $test_object );
		$error      = $this->validator_service->format_errors( $violations );

		$this->assertInstanceOf( 'WP_Error', $error, 'Should return WP_Error instance' );
		$this->assertEquals( 'validation_failed', $error->get_error_code(), 'Should have validation_failed code' );

		$error_data = $error->get_error_data();
		$this->assertIsArray( $error_data, 'Error data should be an array' );
		$this->assertArrayHasKey( 'errors', $error_data, 'Error data should have errors key' );
		$this->assertCount( 1, $error_data['errors'], 'Should have one error' );
		$this->assertEquals( 'name', $error_data['errors'][0]['field'], 'Error should be for name field' );
	}

	/**
	 * Test multiple validation constraints.
	 */
	public function test_multiple_validation_constraints() {
		$test_object = new class() {
			#[\Symfony\Component\Validator\Constraints\NotBlank]
			#[\Symfony\Component\Validator\Constraints\Length( min: 5, max: 10 )]
			public $username = 'ab';
		};

		$violations = $this->validator_service->validate( $test_object );
		$this->assertFalse( $this->validator_service->is_valid( $violations ), 'Should fail length validation' );
		$this->assertCount( 1, $violations, 'Should have one violation for length' );

		$test_object->username = 'valid_user';
		$violations            = $this->validator_service->validate( $test_object );
		$this->assertTrue( $this->validator_service->is_valid( $violations ), 'Should pass all validations' );
	}

	/**
	 * Test Choice constraint.
	 */
	public function test_choice_constraint() {
		$test_object = new class() {
			#[\Symfony\Component\Validator\Constraints\Choice( choices: array( 'draft', 'publish', 'pending' ) )]
			public $status = 'invalid';
		};

		$violations = $this->validator_service->validate( $test_object );
		$this->assertFalse( $this->validator_service->is_valid( $violations ), 'Invalid choice should fail' );

		$test_object->status = 'publish';
		$violations          = $this->validator_service->validate( $test_object );
		$this->assertTrue( $this->validator_service->is_valid( $violations ), 'Valid choice should pass' );
	}

	/**
	 * Test Type constraint.
	 */
	public function test_type_constraint() {
		$test_object = new class() {
			#[\Symfony\Component\Validator\Constraints\Type( type: 'integer' )]
			public $count = 'not-an-integer';
		};

		$violations = $this->validator_service->validate( $test_object );
		$this->assertFalse( $this->validator_service->is_valid( $violations ), 'Wrong type should fail' );

		$test_object->count = 42;
		$violations         = $this->validator_service->validate( $test_object );
		$this->assertTrue( $this->validator_service->is_valid( $violations ), 'Correct type should pass' );
	}
}
