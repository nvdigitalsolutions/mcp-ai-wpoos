<?php
/**
 * Deliverability engine tests.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group crm
 * @group pro
 */

/**
 * Deliverability tests.
 */
class Test_CRM_Deliverability extends WP_UnitTestCase {

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

		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-crm-codes.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-crm-engine.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-crm-audit.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-crm-deliverability.php';

		$this->lead_id = $this->factory->post->create(
			array(
				'post_type'  => 'mcp_ai_lead',
				'post_title' => 'Jane Smith',
			)
		);
		update_post_meta( $this->lead_id, 'email', 'jane.smith@acme.test' );
		update_post_meta( $this->lead_id, 'first_name', 'Jane' );
		update_post_meta( $this->lead_id, 'last_name', 'Smith' );

		delete_option( WP_MCP_AI_CRM_Deliverability::OPTION );
		delete_option( WP_MCP_AI_CRM_Deliverability::STATS_OPTION );
		WP_MCP_AI_CRM_Deliverability::flush_cache();
		delete_transient( 'wp_mcp_ai_crm_dns_' . md5( 'acme.test|default' ) );
	}

	/**
	 * Infer first.last from a named published address.
	 */
	public function test_infer_pattern_first_last() {
		$result = WP_MCP_AI_CRM_Deliverability::infer_pattern(
			'acme.test',
			array(
				array(
					'email' => 'jane.smith@acme.test',
					'first' => 'Jane',
					'last'  => 'Smith',
				),
			)
		);
		$this->assertSame( '{first}.{last}', $result['pattern'] );
		$this->assertGreaterThanOrEqual( 90, $result['confidence'] );
	}

	/**
	 * Infer f.last from a named published address.
	 */
	public function test_infer_pattern_initial_last() {
		$result = WP_MCP_AI_CRM_Deliverability::infer_pattern(
			'acme.test',
			array(
				array(
					'email' => 'j.smith@acme.test',
					'first' => 'Jane',
					'last'  => 'Smith',
				),
			)
		);
		$this->assertSame( '{f}.{last}', $result['pattern'] );
	}

	/**
	 * A dotless address without a named owner teaches nothing.
	 */
	public function test_infer_pattern_ambiguous_teaches_nothing() {
		$result = WP_MCP_AI_CRM_Deliverability::infer_pattern(
			'acme.test',
			array( array( 'email' => 'jsmith@acme.test' ) )
		);
		$this->assertSame( '', $result['pattern'] );
		$this->assertSame( 0, $result['confidence'] );
	}

	/**
	 * Build an address from a pattern.
	 */
	public function test_build_address() {
		$this->assertSame(
			'jane.smith@acme.test',
			WP_MCP_AI_CRM_Deliverability::build_address( '{first}.{last}', 'Jane', 'Smith', 'acme.test' )
		);
		$this->assertSame(
			'jsmith@acme.test',
			WP_MCP_AI_CRM_Deliverability::build_address( '{f}{last}', 'Jane', 'Smith', 'acme.test' )
		);
	}

	/**
	 * Status round-trip and deliverability gate.
	 */
	public function test_status_round_trip() {
		$this->assertSame( 'unknown', WP_MCP_AI_CRM_Deliverability::get_status( $this->lead_id )['status'] );
		WP_MCP_AI_CRM_Deliverability::set_status(
			$this->lead_id,
			'verified',
			array(
				'confidence' => 95,
				'source'     => 'test',
			)
		);
		$status = WP_MCP_AI_CRM_Deliverability::get_status( $this->lead_id );
		$this->assertSame( 'verified', $status['status'] );
		$this->assertSame( 95, $status['confidence'] );
		$this->assertTrue( WP_MCP_AI_CRM_Deliverability::is_deliverable( $this->lead_id ) );
	}

	/**
	 * Risky is only deliverable when explicitly allowed.
	 */
	public function test_risky_requires_opt_in() {
		WP_MCP_AI_CRM_Deliverability::set_status( $this->lead_id, 'risky' );
		$this->assertFalse( WP_MCP_AI_CRM_Deliverability::is_deliverable( $this->lead_id, false ) );
		$this->assertTrue( WP_MCP_AI_CRM_Deliverability::is_deliverable( $this->lead_id, true ) );
	}

	/**
	 * A published address verifies with full confidence.
	 */
	public function test_verify_published_address() {
		$result = WP_MCP_AI_CRM_Deliverability::verify(
			$this->lead_id,
			array(
				'force'     => true,
				'published' => array(
					array(
						'email' => 'jane.smith@acme.test',
						'first' => 'Jane',
						'last'  => 'Smith',
					),
				),
			)
		);
		$this->assertIsArray( $result );
		$this->assertSame( 'verified', $result['status'] );
		$this->assertSame( 100, $result['confidence'] );
	}

	/**
	 * Domain checks read SPF, DKIM and DMARC through the DNS seam.
	 */
	public function test_check_domain() {
		add_filter(
			'wp_mcp_ai_crm_dns_records',
			static function ( $records, $host, $type ) {
				if ( 'TXT' !== $type ) {
					return array();
				}
				if ( 'acme.test' === $host ) {
					return array( array( 'txt' => 'v=spf1 include:_spf.acme.test ~all' ) );
				}
				if ( '_dmarc.acme.test' === $host ) {
					return array( array( 'txt' => 'v=DMARC1; p=quarantine; rua=mailto:dmarc@acme.test' ) );
				}
				if ( 'default._domainkey.acme.test' === $host ) {
					return array( array( 'txt' => 'v=DKIM1; k=rsa; p=abc123' ) );
				}
				return array();
			},
			10,
			3
		);

		delete_transient( 'wp_mcp_ai_crm_dns_' . md5( 'acme.test|default' ) );
		$result = WP_MCP_AI_CRM_Deliverability::check_domain( 'acme.test', 'default' );

		$this->assertTrue( $result['ready'] );
		$this->assertTrue( $result['checks']['spf'] );
		$this->assertTrue( $result['checks']['dkim'] );
		$this->assertTrue( $result['checks']['dmarc'] );
		$this->assertSame( 'quarantine', $result['checks']['dmarc_policy'] );
	}

	/**
	 * Readiness blocks on a missing domain and passes when DNS/warmup are set.
	 */
	public function test_readiness() {
		$not_ready = WP_MCP_AI_CRM_Deliverability::readiness();
		$this->assertFalse( $not_ready['ready'] );
		$this->assertNotEmpty( $not_ready['blockers'] );

		update_option(
			WP_MCP_AI_CRM_Deliverability::OPTION,
			array(
				'sending_domain'    => 'acme.test',
				'warmup_start_date' => gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ),
				'warmup_min_days'   => 14,
			)
		);
		WP_MCP_AI_CRM_Deliverability::flush_cache();

		add_filter(
			'wp_mcp_ai_crm_dns_records',
			static function ( $records, $host, $type ) {
				if ( 'TXT' !== $type ) {
					return array();
				}
				if ( 'acme.test' === $host ) {
					return array( array( 'txt' => 'v=spf1 -all' ) );
				}
				if ( '_dmarc.acme.test' === $host ) {
					return array( array( 'txt' => 'v=DMARC1; p=reject' ) );
				}
				if ( 'default._domainkey.acme.test' === $host ) {
					return array( array( 'txt' => 'v=DKIM1; p=abc' ) );
				}
				return array();
			},
			10,
			3
		);
		delete_transient( 'wp_mcp_ai_crm_dns_' . md5( 'acme.test|default' ) );

		$ready = WP_MCP_AI_CRM_Deliverability::readiness();
		$this->assertTrue( $ready['ready'], implode( ', ', $ready['blockers'] ) );
	}

	/**
	 * Warmup ramp returns zero before the minimum and grows after.
	 */
	public function test_warmup_ramp() {
		update_option(
			WP_MCP_AI_CRM_Deliverability::OPTION,
			array(
				'warmup_start_date'       => gmdate( 'Y-m-d' ),
				'warmup_min_days'         => 14,
				'warmup_start_per_day'    => 10,
				'warmup_daily_increase'   => 5,
				'max_per_mailbox_per_day' => 50,
			)
		);
		WP_MCP_AI_CRM_Deliverability::flush_cache();
		$this->assertSame( 0, WP_MCP_AI_CRM_Deliverability::daily_cap_for_mailbox() );

		update_option(
			WP_MCP_AI_CRM_Deliverability::OPTION,
			array(
				'warmup_start_date'       => gmdate( 'Y-m-d', time() - 14 * DAY_IN_SECONDS ),
				'warmup_min_days'         => 14,
				'warmup_start_per_day'    => 10,
				'warmup_daily_increase'   => 5,
				'max_per_mailbox_per_day' => 50,
			)
		);
		WP_MCP_AI_CRM_Deliverability::flush_cache();
		$this->assertSame( 10, WP_MCP_AI_CRM_Deliverability::daily_cap_for_mailbox() );
	}

	/**
	 * A hard bounce invalidates and pauses.
	 */
	public function test_hard_bounce_invalidates() {
		WP_MCP_AI_CRM_Deliverability::record_sent();
		WP_MCP_AI_CRM_Deliverability::record_bounce( $this->lead_id, 'hard', '550 mailbox unavailable' );
		$this->assertSame( 'invalid', WP_MCP_AI_CRM_Deliverability::get_status( $this->lead_id )['status'] );
		$this->assertSame( '1', get_post_meta( $this->lead_id, '_sequence_paused', true ) );
		$this->assertGreaterThan( 0, WP_MCP_AI_CRM_Deliverability::bounce_rate() );
	}
}
