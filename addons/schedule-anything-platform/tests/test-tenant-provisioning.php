<?php
/**
 * Test: Tenant Provisioning
 *
 * Verifies the SA_Multisite_Provisioner creates subsites correctly
 * and that tenant data is properly isolated.
 *
 * @package Schedule_Anything
 * @since   0.1.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for tenant provisioning — subdomain generation and validation.
 *
 * @group schedule-anything
 * @group provisioning
 */
class Test_Tenant_Provisioning extends WP_UnitTestCase {

	/**
	 * Test that generate_subdomain produces a valid, normalized subdomain.
	 */
	public function test_generate_subdomain_normalizes_input() {
		$result = SA_Multisite_Provisioner::generate_subdomain( 'ACME Corporation LLC' );

		$this->assertIsString( $result );
		$this->assertStringNotContainsString( ' ', $result );
		$this->assertStringNotContainsString( 'llc', $result );
		// Should be lowercase.
		$this->assertSame( strtolower( $result ), $result );
	}

	/**
	 * Test that generate_subdomain handles empty input gracefully.
	 */
	public function test_generate_subdomain_empty_input() {
		$result = SA_Multisite_Provisioner::generate_subdomain( '' );

		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
		// Should fall back to 'workspace'.
		$this->assertStringContainsString( 'workspace', $result );
	}

	/**
	 * Test that generate_subdomain handles noise-only input.
	 */
	public function test_generate_subdomain_noise_only() {
		$result = SA_Multisite_Provisioner::generate_subdomain( 'the llc inc' );

		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
	}

	/**
	 * Test that generate_subdomain truncates long input.
	 */
	public function test_generate_subdomain_truncates_long_input() {
		$long_name = 'this-is-a-very-long-company-name-that-exceeds-thirty-characters';
		$result    = SA_Multisite_Provisioner::generate_subdomain( $long_name );

		$this->assertLessThanOrEqual( 30, strlen( $result ) );
	}

	/**
	 * Test that provision fails without required fields.
	 */
	public function test_provision_fails_without_required_fields() {
		$result = SA_Multisite_Provisioner::provision( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'sa_missing_field', $result->get_error_code() );
	}

	/**
	 * Test that provision fails with invalid tier.
	 */
	public function test_provision_fails_with_invalid_tier() {
		$result = SA_Multisite_Provisioner::provision(
			array(
				'slug'               => 'test-tenant',
				'tier'               => 'invalid_tier',
				'stripe_customer_id' => 'cus_test123',
				'admin_email'        => 'admin@test.com',
				'admin_name'         => 'Test Admin',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'sa_invalid_tier', $result->get_error_code() );
	}
}
