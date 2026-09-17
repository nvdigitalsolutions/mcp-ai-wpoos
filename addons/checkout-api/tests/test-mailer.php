<?php
/**
 * Tests for the license email mailer.
 *
 * @package NV_oOS_Checkout_API
 * @since   0.1.2
 */

/**
 * Mailer tests (wp_mail short-circuited via pre_wp_mail).
 */
class Test_Checkout_Api_Mailer extends WP_UnitTestCase {

	/**
	 * Set up the table + default settings.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		NVOOS_Checkout_API_License_Store::install_table();
		delete_option( NVOOS_Checkout_API_Settings::OPTION );
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_wp_mail' );
		delete_option( NVOOS_Checkout_API_Settings::OPTION );
		parent::tearDown();
	}

	/**
	 * Create a stored license row.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 * @return array<string,mixed>
	 */
	private function create_license( array $overrides = array() ): array {
		$data = array_merge(
			array(
				'license_key'           => 'mail-key-1',
				'product'               => 'nvoos-oos-complete',
				'site_url'              => 'https://customer.example',
				'stripe_payment_intent' => 'pi_mail_123456',
				'amount'                => 4900,
				'currency'              => 'usd',
				'buyer_email'           => 'buyer@example.com',
			),
			$overrides
		);

		$created = NVOOS_Checkout_API_License_Store::create( $data );
		$this->assertNotWPError( $created );
		return $created;
	}

	/**
	 * Capture pre_wp_mail attempts and let the mailer believe sends succeed.
	 *
	 * @param array<int,array<string,mixed>> $captured Capture bucket (by ref).
	 * @return void
	 */
	private function stub_mail_ok( array &$captured ): void {
		add_filter(
			'pre_wp_mail',
			static function ( $short_circuit, $atts ) use ( &$captured ) {
				$captured[] = $atts;
				return true;
			},
			10,
			2
		);
	}

	/**
	 * Email is skipped entirely when the feature is disabled.
	 *
	 * @return void
	 */
	public function test_maybe_send_skips_when_disabled(): void {
		update_option( NVOOS_Checkout_API_Settings::OPTION, array( 'license_email_enabled' => 0 ) );
		$license = $this->create_license();

		$captured = array();
		$this->stub_mail_ok( $captured );

		$this->assertFalse( NVOOS_Checkout_API_Mailer::maybe_send( $license ) );
		$this->assertCount( 0, $captured );

		$stored = NVOOS_Checkout_API_License_Store::get_by_key( 'mail-key-1' );
		$this->assertNotEmpty( $stored );
		$this->assertEmpty( $stored['email_sent_at'] );
	}

	/**
	 * Email is skipped when the license has no buyer address.
	 *
	 * @return void
	 */
	public function test_maybe_send_skips_without_buyer_email(): void {
		$license = $this->create_license( array( 'buyer_email' => '' ) );

		$captured = array();
		$this->stub_mail_ok( $captured );

		$this->assertFalse( NVOOS_Checkout_API_Mailer::maybe_send( $license ) );
		$this->assertCount( 0, $captured );
	}

	/**
	 * The email is sent exactly once, even across repeated calls.
	 *
	 * @return void
	 */
	public function test_maybe_send_sends_once(): void {
		$license = $this->create_license();

		$captured = array();
		$this->stub_mail_ok( $captured );

		$this->assertTrue( NVOOS_Checkout_API_Mailer::maybe_send( $license ) );
		$this->assertCount( 1, $captured );

		// The row is marked — a second call (even with the stale array the
		// caller still holds) must not send again.
		$this->assertTrue( NVOOS_Checkout_API_Mailer::maybe_send( $license ) );
		$this->assertCount( 1, $captured );

		$stored = NVOOS_Checkout_API_License_Store::get_by_key( 'mail-key-1' );
		$this->assertNotEmpty( $stored['email_sent_at'] );
	}

	/**
	 * A failed send is not marked, so a later retry can try again.
	 *
	 * @return void
	 */
	public function test_maybe_send_does_not_mark_on_failure(): void {
		$license = $this->create_license();

		add_filter( 'pre_wp_mail', '__return_false' );

		$this->assertFalse( NVOOS_Checkout_API_Mailer::maybe_send( $license ) );

		$stored = NVOOS_Checkout_API_License_Store::get_by_key( 'mail-key-1' );
		$this->assertNotEmpty( $stored );
		$this->assertEmpty( $stored['email_sent_at'] );

		// A subsequent successful send completes the job.
		remove_all_filters( 'pre_wp_mail' );
		$this->assertTrue( NVOOS_Checkout_API_Mailer::maybe_send( $license ) );

		$stored = NVOOS_Checkout_API_License_Store::get_by_key( 'mail-key-1' );
		$this->assertNotEmpty( $stored['email_sent_at'] );
	}

	/**
	 * Custom subject and From settings are honoured.
	 *
	 * @return void
	 */
	public function test_maybe_send_uses_custom_subject_and_from(): void {
		update_option(
			NVOOS_Checkout_API_Settings::OPTION,
			array(
				'license_email_subject'   => 'Custom subject',
				'license_email_from_name' => 'NV Store',
				'license_email_from'      => 'store@example.com',
			)
		);
		$license = $this->create_license();

		$captured = array();
		$this->stub_mail_ok( $captured );

		$this->assertTrue( NVOOS_Checkout_API_Mailer::maybe_send( $license ) );

		$this->assertSame( 'buyer@example.com', $captured[0]['to'] );
		$this->assertSame( 'Custom subject', $captured[0]['subject'] );
		$this->assertContains( 'From: NV Store <store@example.com>', $captured[0]['headers'] );
		$this->assertContains( 'Reply-To: store@example.com', $captured[0]['headers'] );
	}

	/**
	 * The default email carries the license key, product, site, and amount.
	 *
	 * @return void
	 */
	public function test_maybe_send_body_contains_license_details(): void {
		$license = $this->create_license();

		$captured = array();
		$this->stub_mail_ok( $captured );

		$this->assertTrue( NVOOS_Checkout_API_Mailer::maybe_send( $license ) );

		$this->assertSame( 'Your NV oOS Complete license', $captured[0]['subject'] );
		$this->assertStringContainsString( 'mail-key-1', $captured[0]['message'] );
		$this->assertStringContainsString( 'NV oOS Complete', $captured[0]['message'] );
		$this->assertStringContainsString( 'https://customer.example', $captured[0]['message'] );
		$this->assertStringContainsString( '49.00 USD', $captured[0]['message'] );
	}
}
