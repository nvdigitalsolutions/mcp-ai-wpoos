<?php
/**
 * Signals, cohorts and evidence tests.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group crm
 * @group pro
 */

/**
 * Signals engine.
 */
class Test_CRM_Signals extends WP_UnitTestCase {

	/**
	 * Lead IDs.
	 *
	 * @var int[]
	 */
	private $leads = array();

	/**
	 * Create the signal table before the per-test transaction opens.
	 *
	 * MySQL 8 implements atomic DDL, so a `CREATE TABLE` issued inside
	 * WP_UnitTestCase's transaction is rolled back with it and the table
	 * path would be untestable. Creating it here (outside any transaction)
	 * lets the suite exercise the indexed path; the meta path is covered
	 * separately by test_meta_fallback.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			return;
		}
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-crm-signals-db.php';
		WP_MCP_AI_CRM_Signals_DB::install();
	}

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon is not loaded.' );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-crm-signals.php';

		update_option(
			WP_MCP_AI_CRM_Signals::OPTION,
			array(
				'enabled'   => true,
				'confirmed' => array( 'running_google_ads', 'no_online_booking', 'blog_stale' ),
			)
		);
		WP_MCP_AI_CRM_Signals::flush_cache();

		$this->leads = array(
			$this->factory->post->create(
				array(
					'post_type'   => 'mcp_ai_lead',
					'post_status' => 'publish',
					'post_title'  => 'A',
				)
			),
			$this->factory->post->create(
				array(
					'post_type'   => 'mcp_ai_lead',
					'post_status' => 'publish',
					'post_title'  => 'B',
				)
			),
			$this->factory->post->create(
				array(
					'post_type'   => 'mcp_ai_lead',
					'post_status' => 'publish',
					'post_title'  => 'C',
				)
			),
		);

		WP_MCP_AI_CRM_Signals::observe( $this->leads[0], 'running_google_ads', array( 'value_num' => 1 ) );
		WP_MCP_AI_CRM_Signals::observe( $this->leads[0], 'no_online_booking', array( 'value_num' => 1 ) );
		WP_MCP_AI_CRM_Signals::observe( $this->leads[0], 'blog_stale', array( 'value_num' => 120 ) );

		WP_MCP_AI_CRM_Signals::observe( $this->leads[1], 'running_google_ads', array( 'value_num' => 1 ) );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_CRM_Signals::OPTION );
		WP_MCP_AI_CRM_Signals::flush_cache();
		parent::tearDown();
	}

	/**
	 * Observation round-trip.
	 */
	public function test_observation_round_trip() {
		$observations = WP_MCP_AI_CRM_Signals::get_observations( $this->leads[0] );
		$this->assertArrayHasKey( 'running_google_ads', $observations );
		$this->assertArrayHasKey( 'blog_stale', $observations );
		$this->assertEquals( 120, $observations['blog_stale']['value_num'] );
	}

	/**
	 * A boolean false clears the observation.
	 */
	public function test_boolean_false_clears() {
		WP_MCP_AI_CRM_Signals::observe( $this->leads[0], 'running_google_ads', array( 'value_num' => 0 ) );
		$observations = WP_MCP_AI_CRM_Signals::get_observations( $this->leads[0] );
		$this->assertArrayNotHasKey( 'running_google_ads', $observations );
	}

	/**
	 * Must / must-not / range cohort.
	 */
	public function test_cohort() {
		$cohort = WP_MCP_AI_CRM_Signals::cohort(
			array(
				'must'     => array( 'running_google_ads', 'no_online_booking' ),
				'must_not' => array( 'blog_stale' ),
				'limit'    => 50,
			)
		);
		$this->assertSame( 0, $cohort['count'] );

		$cohort = WP_MCP_AI_CRM_Signals::cohort(
			array(
				'must'  => array( 'running_google_ads', 'no_online_booking' ),
				'limit' => 50,
			)
		);
		$this->assertSame( 1, $cohort['count'] );
		$this->assertSame( $this->leads[0], $cohort['ids'][0] );

		$cohort = WP_MCP_AI_CRM_Signals::cohort(
			array(
				'ranges' => array( 'blog_stale' => array( 'min' => 90 ) ),
				'limit'  => 50,
			)
		);
		$this->assertSame( 1, $cohort['count'] );
	}

	/**
	 * Evidence card summarises the signals.
	 */
	public function test_evidence_card() {
		$card = WP_MCP_AI_CRM_Signals::evidence_card( $this->leads[0] );
		$this->assertNotEmpty( $card['signals'] );
		$this->assertStringContainsString( 'Running Google Ads', $card['summary'] );
	}

	/**
	 * Coverage reports per-signal leads.
	 */
	public function test_coverage() {
		$coverage = WP_MCP_AI_CRM_Signals::coverage();
		$this->assertArrayHasKey( 'running_google_ads', $coverage );
		$this->assertSame( 2, $coverage['running_google_ads']['leads'] );
	}

	/**
	 * Meta fallback produces the same query semantics.
	 */
	public function test_meta_fallback() {
		add_filter( 'wp_mcp_ai_crm_signals_use_table', '__return_false' );
		WP_MCP_AI_CRM_Signals::observe( $this->leads[2], 'running_google_ads', array( 'value_num' => 1 ) );

		$cohort = WP_MCP_AI_CRM_Signals::cohort(
			array(
				'must'  => array( 'running_google_ads' ),
				'limit' => 50,
			)
		);
		$this->assertGreaterThanOrEqual( 1, $cohort['count'] );
		remove_filter( 'wp_mcp_ai_crm_signals_use_table', '__return_false' );
	}

	/**
	 * Expired observations are excluded.
	 */
	public function test_ttl_excludes_expired() {
		WP_MCP_AI_CRM_Signals::observe(
			$this->leads[2],
			'blog_stale',
			array(
				'value_num'   => 200,
				'observed_at' => gmdate( 'c', time() - 100 * DAY_IN_SECONDS ),
			)
		);
		$observations = WP_MCP_AI_CRM_Signals::get_observations( $this->leads[2] );
		$this->assertArrayNotHasKey( 'blog_stale', $observations );
	}
}
