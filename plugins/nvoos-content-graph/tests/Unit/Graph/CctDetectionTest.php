<?php
declare(strict_types=1);

namespace NvoosContentGraph\Tests\Unit\Graph;

use NvoosContentGraph\Graph\Detector;
use WP_UnitTestCase;

require_once dirname( __DIR__, 2 ) . '/helpers/jetengine-cct-stubs.php';

/**
 * Unit tests for JetEngine CCT detection and the per-CCT inclusion setting.
 *
 * Uses the shared JetEngine stub (tests/helpers/jetengine-cct-stubs.php)
 * to exercise the enumeration and the `excluded_cct_slugs` allowlist.
 *
 * @since 1.0.7
 */
class CctDetectionTest extends WP_UnitTestCase {

	/**
	 * Stub rows for the transcript CCT.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $transcripts = array(
		array(
			'_ID'           => 10,
			'cct_title'     => 'Transcript 10',
			'cct_author_id' => 1,
		),
		array(
			'_ID'           => 11,
			'cct_title'     => 'Transcript 11',
			'cct_author_id' => 1,
		),
	);

	/**
	 * Stub rows for the agent-memories CCT.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $memories = array(
		array(
			'_ID'           => 20,
			'cct_title'     => 'Memory 20',
			'cct_author_id' => 2,
		),
	);

	/**
	 * Install a two-CCT JetEngine stub before every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		nvoos_cg_test_install_jetengine_cct(
			array(
				array(
					'slug' => 'ai_chat_transcripts',
					'name' => 'Chat Transcripts',
					'rows' => $this->transcripts,
				),
				array(
					'slug' => 'ai_chat_agent_memories',
					'name' => 'Agent Memories',
					'rows' => $this->memories,
				),
			)
		);
	}

	/**
	 * Reset the stub and indexed-slug filters between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		nvoos_cg_test_reset_jetengine();
		remove_all_filters( 'nvoos_content_graph_indexed_cct_slugs' );
		parent::tearDown();
	}

	/**
	 * getCctTypes() enumerates slug, name, and db handler per type.
	 *
	 * @return void
	 */
	public function test_get_cct_types_enumerates_registered_types(): void {
		$types = Detector::getCctTypes();

		$this->assertSame(
			array( 'ai_chat_transcripts', 'ai_chat_agent_memories' ),
			wp_list_pluck( $types, 'slug' )
		);
		$this->assertSame(
			array( 'Chat Transcripts', 'Agent Memories' ),
			wp_list_pluck( $types, 'name' )
		);
		$this->assertNotEmpty( $types[0]['db'] );
		$this->assertSame( '', Detector::getLastCctsSkipReason() );
	}

	/**
	 * Without an installed stub, enumeration fails closed with a skip reason.
	 *
	 * @return void
	 */
	public function test_get_cct_types_skip_reason_when_no_stub(): void {
		nvoos_cg_test_reset_jetengine();

		$this->assertSame( array(), Detector::getCctTypes() );
		$this->assertSame( 'jetengine_modules_unavailable', Detector::getLastCctsSkipReason() );
	}

	/**
	 * Every registered CCT is indexed by default.
	 *
	 * @return void
	 */
	public function test_detect_ccts_indexes_all_by_default(): void {
		$rows = Detector::detectCcts();

		$this->assertCount( 3, $rows );
		$slugs = array_values( array_unique( wp_list_pluck( $rows, 'type' ) ) );
		sort( $slugs );
		$this->assertSame( array( 'ai_chat_agent_memories', 'ai_chat_transcripts' ), $slugs );
		$this->assertSame( '', Detector::getLastCctsSkipReason() );
	}

	/**
	 * The excluded_cct_slugs setting removes excluded CCTs from indexing.
	 *
	 * @return void
	 */
	public function test_detect_ccts_honors_excluded_cct_slugs_setting(): void {
		update_option(
			'nvoos_content_graph_settings',
			array( 'excluded_cct_slugs' => array( 'ai_chat_agent_memories' ) )
		);

		$rows = Detector::detectCcts();

		$this->assertCount( 2, $rows );
		foreach ( $rows as $row ) {
			$this->assertSame( 'ai_chat_transcripts', $row['type'] );
		}
		$this->assertSame( '', Detector::getLastCctsSkipReason() );
	}

	/**
	 * Excluding every CCT yields an empty set with a descriptive skip reason.
	 *
	 * @return void
	 */
	public function test_detect_ccts_excluding_everything_reports_skip_reason(): void {
		update_option(
			'nvoos_content_graph_settings',
			array(
				'excluded_cct_slugs' => array( 'ai_chat_transcripts', 'ai_chat_agent_memories' ),
			)
		);

		$this->assertSame( array(), Detector::detectCcts() );
		$this->assertSame( 'all_content_types_empty_or_unindexed', Detector::getLastCctsSkipReason() );
	}

	/**
	 * The indexed-slug filter still runs after the setting, so it can
	 * re-include an excluded CCT (backwards-compatible override).
	 *
	 * @return void
	 */
	public function test_indexed_slugs_filter_can_reinclude_excluded(): void {
		update_option(
			'nvoos_content_graph_settings',
			array( 'excluded_cct_slugs' => array( 'ai_chat_agent_memories' ) )
		);
		add_filter(
			'nvoos_content_graph_indexed_cct_slugs',
			static function ( $slugs ) {
				$slugs[] = 'ai_chat_agent_memories';
				return $slugs;
			}
		);

		$this->assertCount( 3, Detector::detectCcts() );
	}

	/**
	 * detectCcts() records a per-slug report of what happened to each type.
	 *
	 * @return void
	 */
	public function test_detect_ccts_reports_per_type_status(): void {
		Detector::detectCcts();

		$report = Detector::getCctTypeReport();

		$this->assertSame(
			array(
				'status' => Detector::CCT_STATUS_INDEXED,
				'items'  => 2,
			),
			$report['ai_chat_transcripts']
		);
		$this->assertSame(
			array(
				'status' => Detector::CCT_STATUS_INDEXED,
				'items'  => 1,
			),
			$report['ai_chat_agent_memories']
		);
	}

	/**
	 * A type with no table is reported (not queried), and an empty type is
	 * reported as empty — both are silently invisible in the graph otherwise.
	 *
	 * @return void
	 */
	public function test_detect_ccts_reports_empty_and_missing_table_types(): void {
		nvoos_cg_test_install_jetengine_cct(
			array(
				array(
					'slug' => 'ai_chat_transcripts',
					'name' => 'Chat Transcripts',
					'rows' => $this->transcripts,
				),
				array(
					'slug' => 'ai_chat_agent_memories',
					'name' => 'Agent Memories',
					'rows' => $this->memories,
				),
				array(
					'slug' => 'no_rows_yet',
					'name' => 'No Rows Yet',
				),
				array(
					'slug'         => 'ghost_type',
					'name'         => 'Ghost Type',
					'table_exists' => false,
				),
			)
		);

		$rows   = Detector::detectCcts();
		$report = Detector::getCctTypeReport();

		$this->assertCount( 3, $rows );
		$this->assertSame( Detector::CCT_STATUS_EMPTY, $report['no_rows_yet']['status'] );
		$this->assertSame( 0, $report['no_rows_yet']['items'] );
		$this->assertSame( Detector::CCT_STATUS_TABLE_MISSING, $report['ghost_type']['status'] );
		$this->assertSame( 0, $report['ghost_type']['items'] );
	}

	/**
	 * Excluded types are reported as excluded rather than silently dropped.
	 *
	 * @return void
	 */
	public function test_detect_ccts_reports_excluded_types(): void {
		update_option(
			'nvoos_content_graph_settings',
			array( 'excluded_cct_slugs' => array( 'ai_chat_agent_memories' ) )
		);

		Detector::detectCcts();
		$report = Detector::getCctTypeReport();

		$this->assertSame( Detector::CCT_STATUS_EXCLUDED, $report['ai_chat_agent_memories']['status'] );
		$this->assertSame( Detector::CCT_STATUS_INDEXED, $report['ai_chat_transcripts']['status'] );
	}

	/**
	 * inspectCctTypes() returns a lightweight snapshot without pulling rows.
	 *
	 * @return void
	 */
	public function test_inspect_cct_types_returns_status_snapshot(): void {
		nvoos_cg_test_install_jetengine_cct(
			array(
				array(
					'slug' => 'ai_chat_transcripts',
					'name' => 'Chat Transcripts',
					'rows' => $this->transcripts,
				),
				array(
					'slug' => 'ai_chat_agent_memories',
					'name' => 'Agent Memories',
					'rows' => $this->memories,
				),
				array(
					'slug'         => 'ghost_type',
					'name'         => 'Ghost Type',
					'table_exists' => false,
				),
			)
		);

		// Reset the detection report so earlier detectCcts() tests cannot leak
		// state into the "inspectCctTypes() must not populate it" assertion.
		$report_prop = new \ReflectionProperty( Detector::class, 'cctTypeReport' );
		$report_prop->setValue( null, array() );

		$report = Detector::inspectCctTypes();

		$this->assertSame(
			array(
				'status' => Detector::CCT_STATUS_INDEXED,
				'items'  => 2,
			),
			$report['ai_chat_transcripts']
		);
		$this->assertSame(
			array(
				'status' => Detector::CCT_STATUS_INDEXED,
				'items'  => 1,
			),
			$report['ai_chat_agent_memories']
		);
		$this->assertSame( Detector::CCT_STATUS_TABLE_MISSING, $report['ghost_type']['status'] );

		// The snapshot must not have populated the detection report.
		$this->assertSame( array(), Detector::getCctTypeReport() );
	}
}
