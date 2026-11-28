<?php
/**
 * Test: Email Generator — provider seam.
 *
 * @package NV_oOS_Design_System
 */

/**
 * Tests for the AI provider seam.
 *
 * The plugin never talks to a provider directly: generation goes through the
 * WordPress AI Client when it is available, or through the
 * `nvoos_nds_email_generation_provider` filter supplied by a site.
 *
 * @covers NV_oOS_Design_System_Email_Generator
 */
class Test_Email_Generator extends WP_UnitTestCase {

	/**
	 * Generator instance.
	 *
	 * @var NV_oOS_Design_System_Email_Generator
	 */
	private $generator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->generator = new NV_oOS_Design_System_Email_Generator();
	}

	/**
	 * Clean up filters between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		remove_all_filters( 'nvoos_nds_email_generation_provider' );
		parent::tearDown();
	}

	/**
	 * An empty prompt is rejected before any provider is consulted.
	 *
	 * @return void
	 */
	public function test_empty_prompt_is_rejected() {
		$result = $this->generator->generate( '   ' );

		$this->assertWPError( $result );
		$this->assertSame( 'nds_email_generate_empty_prompt', $result->get_error_code() );
	}

	/**
	 * Both prompt halves reach the filter callback.
	 *
	 * @return void
	 */
	public function test_provider_filter_receives_both_prompts() {
		$captured = array();

		add_filter(
			'nvoos_nds_email_generation_provider',
			static function () use ( &$captured ) {
				return static function ( $system, $user ) use ( &$captured ) {
					$captured['system'] = $system;
					$captured['user']   = $user;
					return new WP_Error( 'test_stop', 'stop' );
				};
			}
		);

		$result = $this->generator->generate( 'A welcome email for new subscribers' );

		$this->assertWPError( $result );
		$this->assertSame( 'test_stop', $result->get_error_code() );
		$this->assertArrayHasKey( 'system', $captured );
		$this->assertArrayHasKey( 'user', $captured );
		$this->assertStringContainsString( 'HTML email template', $captured['system'] );
		$this->assertStringContainsString( 'A welcome email for new subscribers', $captured['user'] );
	}

	/**
	 * A provider WP_Error is returned to the caller unchanged.
	 *
	 * @return void
	 */
	public function test_provider_error_is_propagated() {
		add_filter(
			'nvoos_nds_email_generation_provider',
			static function () {
				return static function () {
					return new WP_Error( 'provider_down', 'The provider is unavailable.' );
				};
			}
		);

		$result = $this->generator->generate( 'A renewal reminder' );

		$this->assertWPError( $result );
		$this->assertSame( 'provider_down', $result->get_error_code() );
		$this->assertSame( 'The provider is unavailable.', $result->get_error_message() );
	}

	/**
	 * Without the AI Client and without a filter, generation fails with an
	 * actionable error rather than making an outbound request.
	 *
	 * @return void
	 */
	public function test_missing_provider_returns_actionable_error() {
		if ( function_exists( 'wp_ai_client_prompt' ) && function_exists( 'wp_supports_ai' ) && wp_supports_ai() ) {
			$this->markTestSkipped( 'This suite has a working WordPress AI Client; the no-provider path cannot be exercised.' );
		}

		$result = $this->generator->generate( 'A shipping confirmation' );

		$this->assertWPError( $result );
		$this->assertSame( 'nds_email_generate_no_provider', $result->get_error_code() );
		$this->assertStringContainsString( 'nvoos_nds_email_generation_provider', $result->get_error_message() );
	}

	/**
	 * A non-callable filter value falls through to the AI Client path.
	 *
	 * @return void
	 */
	public function test_non_callable_filter_value_is_ignored() {
		add_filter( 'nvoos_nds_email_generation_provider', '__return_false' );

		if ( function_exists( 'wp_ai_client_prompt' ) && function_exists( 'wp_supports_ai' ) && wp_supports_ai() ) {
			$this->markTestSkipped( 'A working AI Client would be used after the non-callable filter falls through.' );
		}

		$result = $this->generator->generate( 'A password reset' );

		$this->assertWPError( $result );
		$this->assertSame( 'nds_email_generate_no_provider', $result->get_error_code() );
	}
}
