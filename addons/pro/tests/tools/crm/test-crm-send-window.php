<?php
/**
 * Send-window tests.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group crm
 * @group pro
 */

/**
 * Send window.
 */
class Test_CRM_Send_Window extends WP_UnitTestCase {

	/**
	 * Lead ID.
	 *
	 * @var int
	 */
	private $lead_id;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon is not loaded.' );
		}
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-crm-send-window.php';

		$this->lead_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_lead' ) );
		update_option( WP_MCP_AI_CRM_Send_Window::OPTION, array( 'enabled' => true ) );
		WP_MCP_AI_CRM_Send_Window::flush_cache();
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_CRM_Send_Window::OPTION );
		WP_MCP_AI_CRM_Send_Window::flush_cache();
		parent::tearDown();
	}

	/**
	 * The window is opt-in; with no configuration every hour is open.
	 */
	public function test_disabled_by_default() {
		delete_option( WP_MCP_AI_CRM_Send_Window::OPTION );
		WP_MCP_AI_CRM_Send_Window::flush_cache();
		update_post_meta( $this->lead_id, '_crm_timezone', 'UTC' );

		// Saturday 02:00 local: outside any business-hours window.
		$weekend = new DateTimeImmutable( '2026-01-17 02:00:00', new DateTimeZone( 'UTC' ) );
		$this->assertTrue( WP_MCP_AI_CRM_Send_Window::is_open( $this->lead_id, 'email', 'sequence', $weekend ) );
	}

	/**
	 * Explicit timezone wins.
	 */
	public function test_explicit_timezone() {
		update_post_meta( $this->lead_id, '_crm_timezone', 'America/New_York' );
		$resolved = WP_MCP_AI_CRM_Send_Window::resolve_timezone( $this->lead_id );
		$this->assertSame( 'America/New_York', $resolved['zone'] );
		$this->assertSame( 'explicit', $resolved['source'] );
	}

	/**
	 * US state maps to the right zone.
	 */
	public function test_country_state_timezone() {
		update_post_meta( $this->lead_id, '_crm_country', 'US' );
		update_post_meta( $this->lead_id, '_crm_state', 'CA' );
		$resolved = WP_MCP_AI_CRM_Send_Window::resolve_timezone( $this->lead_id );
		$this->assertSame( 'America/Los_Angeles', $resolved['zone'] );
		$this->assertSame( 'country', $resolved['source'] );
	}

	/**
	 * Phone prefix fallback.
	 */
	public function test_phone_timezone() {
		update_post_meta( $this->lead_id, 'phone', '+94771234567' );
		$resolved = WP_MCP_AI_CRM_Send_Window::resolve_timezone( $this->lead_id );
		$this->assertSame( 'Asia/Colombo', $resolved['zone'] );
		$this->assertSame( 'phone', $resolved['source'] );
	}

	/**
	 * Window opens in the recipient's local time, DST-safe.
	 */
	public function test_window_is_local_and_dst_safe() {
		update_post_meta( $this->lead_id, '_crm_timezone', 'America/New_York' );

		// January: 13:00 UTC = 08:00 EST.
		$jan = new DateTimeImmutable( '2026-01-15 13:00:00', new DateTimeZone( 'UTC' ) );
		$this->assertTrue( WP_MCP_AI_CRM_Send_Window::is_open( $this->lead_id, 'email', 'sequence', $jan ) );

		// July: 12:00 UTC = 08:00 EDT.
		$jul = new DateTimeImmutable( '2026-07-15 12:00:00', new DateTimeZone( 'UTC' ) );
		$this->assertTrue( WP_MCP_AI_CRM_Send_Window::is_open( $this->lead_id, 'email', 'sequence', $jul ) );

		// 01:00 UTC is 20:00 the previous evening in New York.
		$night = new DateTimeImmutable( '2026-01-15 01:00:00', new DateTimeZone( 'UTC' ) );
		$this->assertFalse( WP_MCP_AI_CRM_Send_Window::is_open( $this->lead_id, 'email', 'sequence', $night ) );
	}

	/**
	 * Replies are never blocked.
	 */
	public function test_reply_not_blocked() {
		update_post_meta( $this->lead_id, '_crm_timezone', 'America/New_York' );
		$night = new DateTimeImmutable( '2026-01-15 02:00:00', new DateTimeZone( 'America/New_York' ) );
		$this->assertTrue( WP_MCP_AI_CRM_Send_Window::is_open( $this->lead_id, 'email', 'reply', $night ) );
	}

	/**
	 * Overnight windows work.
	 */
	public function test_overnight_window() {
		update_option(
			WP_MCP_AI_CRM_Send_Window::OPTION,
			array(
				'enabled' => true,
				'start'   => 20,
				'end'     => 6,
			)
		);
		WP_MCP_AI_CRM_Send_Window::flush_cache();
		update_post_meta( $this->lead_id, '_crm_timezone', 'UTC' );

		$this->assertTrue(
			WP_MCP_AI_CRM_Send_Window::is_open(
				$this->lead_id,
				'email',
				'sequence',
				new DateTimeImmutable( '2026-01-15 23:00:00', new DateTimeZone( 'UTC' ) )
			)
		);
		$this->assertFalse(
			WP_MCP_AI_CRM_Send_Window::is_open(
				$this->lead_id,
				'email',
				'sequence',
				new DateTimeImmutable( '2026-01-15 12:00:00', new DateTimeZone( 'UTC' ) )
			)
		);
	}

	/**
	 * Next open lands on the configured start hour.
	 */
	public function test_next_open() {
		update_post_meta( $this->lead_id, '_crm_timezone', 'UTC' );
		$now  = new DateTimeImmutable( '2026-01-15 22:00:00', new DateTimeZone( 'UTC' ) );
		$next = WP_MCP_AI_CRM_Send_Window::next_open( $this->lead_id, 'email', 'sequence', $now );
		$this->assertGreaterThan( $now->getTimestamp(), $next->getTimestamp() );
		$this->assertSame( '08', $next->format( 'H' ) );
	}
}
