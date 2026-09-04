<?php
/**
 * Tests for Architectural Design Phase E tools.
 *
 * Phase E — Precedent CPT, embedding-based semantic search, and example
 * assistant blueprints.
 *
 * Fixtures cover:
 *   - manage_architectural_precedents create → list → get → update → delete.
 *   - Precedents engine helpers (cosine, keyword_score, build_corpus).
 *   - search_architectural_precedents in keyword-fallback mode (no key).
 *   - search_architectural_precedents in embedding mode via filter stub.
 *   - Country / building-type / area filters narrow the candidate pool.
 *   - Access control: edit_posts required for all actions.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test case for Phase E tools.
 */
class Test_Architectural_Tools_Phase_E extends WP_UnitTestCase {

	/**
	 * Editor user id.
	 *
	 * @var int
	 */
	protected $editor_id = 0;

	/**
	 * Subscriber user id (for access control checks).
	 *
	 * @var int
	 */
	protected $subscriber_id = 0;

	/**
	 * Set up — load Phase E files.
	 */
	public function setUp(): void {
		parent::setUp();

		$pro_path = defined( 'WP_MCP_AI_PRO_PATH' )
			? WP_MCP_AI_PRO_PATH
			: dirname( __DIR__ ) . '/';

		$base = $pro_path . 'includes/tools/architectural-design/';
		if ( ! file_exists( $base ) ) {
			$this->markTestSkipped( 'Architectural Design toolkit not present.' );
		}

		// Precedent CPT.
		$cpt_file = $pro_path . 'includes/class-wp-mcp-ai-architectural-precedent-cpt.php';
		if ( file_exists( $cpt_file ) ) {
			require_once $cpt_file;
			if ( class_exists( 'WP_MCP_AI_Architectural_Precedent_CPT' )
				&& method_exists( 'WP_MCP_AI_Architectural_Precedent_CPT', 'register_post_type' ) ) {
				WP_MCP_AI_Architectural_Precedent_CPT::register_post_type();
				WP_MCP_AI_Architectural_Precedent_CPT::register_taxonomies();
				WP_MCP_AI_Architectural_Precedent_CPT::register_meta();
			}
		}
		if ( ! post_type_exists( 'mcp_arch_precedent' ) ) {
			register_post_type(
				'mcp_arch_precedent',
				array(
					'public'          => false,
					'show_ui'         => false,
					'capability_type' => 'post',
					'map_meta_cap'    => true,
					'supports'        => array( 'title', 'editor', 'excerpt', 'custom-fields' ),
				)
			);
		}

		// Engine + tools.
		require_once $base . 'class-wp-mcp-ai-architectural-precedents-engine.php';
		require_once $base . 'precedents/class-wp-mcp-ai-tool-manage-architectural-precedents.php';
		require_once $base . 'precedents/class-wp-mcp-ai-tool-search-architectural-precedents.php';

		$this->editor_id     = $this->factory->user->create( array( 'role' => 'editor' ) );
		$this->subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->editor_id );
		update_option( 'wp_mcp_ai_settings', array( 'enable_architectural_design_toolkit' => 1 ) );
	}

	/**
	 * Editor execution context.
	 *
	 * @return array
	 */
	protected function ctx() {
		return array( 'user_id' => $this->editor_id );
	}

	/**
	 * Subscriber execution context.
	 *
	 * @return array
	 */
	protected function sub_ctx() {
		return array( 'user_id' => $this->subscriber_id );
	}

	/**
	 * Pseudo-embedding deterministic per text — lets us test embedding mode
	 * without an OpenAI key. Tokens are hashed into a fixed-length vector
	 * and L2-normalised, so cosine similarity reflects shared vocabulary:
	 * a query about tropical courtyards ranks the tropical precedent above
	 * an unrelated one, independently of incidental corpus metadata.
	 *
	 * @param string $text Text.
	 * @return array
	 */
	protected function pseudo_embedding( $text ) {
		$tokens = preg_split( '/[^a-z0-9]+/', strtolower( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
		$vec    = array_fill( 0, 64, 0.0 );
		foreach ( (array) $tokens as $token ) {
			$idx          = hexdec( substr( hash( 'sha256', $token ), 0, 4 ) ) % 64;
			$vec[ $idx ] += 1.0;
		}
		$mag = 0.0;
		foreach ( $vec as $v ) {
			$mag += $v * $v;
		}
		$mag = sqrt( $mag );
		if ( $mag > 0 ) {
			foreach ( $vec as $i => $v ) {
				$vec[ $i ] = $v / $mag;
			}
		}
		return $vec;
	}

	/**
	 * Helper to seed a precedent and return its id.
	 *
	 * @param array $extra Extra args.
	 * @return int
	 */
	protected function create_precedent( array $extra = array() ) {
		$tool   = new WP_MCP_AI_Tool_Manage_Architectural_Precedents();
		$args   = array_merge(
			array(
				'action'         => 'create',
				'title'          => 'Test Precedent',
				'description'    => 'A reference precedent for tests.',
				'country_code'   => 'LK',
				'building_type'  => 'residential',
				'climate_zone'   => 'Af',
				'year_completed' => 2020,
				'area_m2'        => 250.0,
				'key_features'   => array( 'courtyards', 'cross-ventilation' ),
			),
			$extra
		);
		$result = $tool->execute( $args, $this->ctx() );
		$this->assertIsArray( $result );
		$this->assertTrue( ! empty( $result['success'] ) );
		return (int) $result['precedent']['id'];
	}

	/**
	 * Create → get → list → update → delete workflow.
	 */
	public function test_manage_workflow() {
		$id = $this->create_precedent();
		$this->assertGreaterThan( 0, $id );

		$tool = new WP_MCP_AI_Tool_Manage_Architectural_Precedents();
		$got  = $tool->execute(
			array(
				'action'       => 'get',
				'precedent_id' => $id,
			),
			$this->ctx()
		);
		$this->assertSame( 'LK', $got['precedent']['country_code'] );
		$this->assertSame( 'residential', $got['precedent']['building_type'] );
		$this->assertSame( 250.0, $got['precedent']['area_m2'] );
		$this->assertSame( array( 'courtyards', 'cross-ventilation' ), $got['precedent']['key_features'] );

		$list = $tool->execute( array( 'action' => 'list' ), $this->ctx() );
		$this->assertGreaterThanOrEqual( 1, $list['count'] );

		$updated = $tool->execute(
			array(
				'action'                => 'update',
				'precedent_id'          => $id,
				'sustainability_rating' => 'EDGE Certified',
				'area_m2'               => 275.0,
			),
			$this->ctx()
		);
		$this->assertSame( 'EDGE Certified', $updated['precedent']['sustainability_rating'] );
		$this->assertSame( 275.0, $updated['precedent']['area_m2'] );

		$del = $tool->execute(
			array(
				'action'       => 'delete',
				'precedent_id' => $id,
			),
			$this->ctx()
		);
		$this->assertSame( $id, $del['deleted_id'] );
		$this->assertNull( get_post( $id ) );
	}

	/**
	 * Subscribers cannot manage precedents.
	 */
	public function test_access_control_rejects_subscribers() {
		$tool   = new WP_MCP_AI_Tool_Manage_Architectural_Precedents();
		$result = $tool->execute(
			array(
				'action' => 'create',
				'title'  => 'No-go',
			),
			$this->sub_ctx()
		);
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Engine cosine sanity.
	 */
	public function test_cosine_helpers() {
		$this->assertEqualsWithDelta(
			1.0,
			WP_MCP_AI_Architectural_Precedents_Engine::cosine( array( 1.0, 2.0, 3.0 ), array( 1.0, 2.0, 3.0 ) ),
			1e-9
		);
		$this->assertSame(
			0.0,
			WP_MCP_AI_Architectural_Precedents_Engine::cosine( array( 1.0, 0.0 ), array( 0.0, 1.0 ) )
		);
		$this->assertEqualsWithDelta(
			-1.0,
			WP_MCP_AI_Architectural_Precedents_Engine::cosine( array( 1.0, 2.0 ), array( -1.0, -2.0 ) ),
			1e-9
		);
	}

	/**
	 * Engine keyword scoring.
	 */
	public function test_keyword_score() {
		$this->assertSame(
			1.0,
			WP_MCP_AI_Architectural_Precedents_Engine::keyword_score( 'tropical residential', 'A tropical residential proposal' )
		);
		$this->assertSame(
			0.0,
			WP_MCP_AI_Architectural_Precedents_Engine::keyword_score( '', 'something' )
		);
	}

	/**
	 * Search in keyword fallback mode (no embedding service / no key).
	 */
	public function test_search_keyword_fallback() {
		$this->create_precedent(
			array(
				'title'       => 'Tropical Courtyard House',
				'description' => 'A tropical courtyard residence with cross-ventilation.',
			)
		);
		$this->create_precedent(
			array(
				'title'         => 'Hurricane Hardened Office',
				'country_code'  => 'JM',
				'building_type' => 'commercial',
				'description'   => 'Impact-rated curtain wall and continuous load path.',
			)
		);

		$tool   = new WP_MCP_AI_Tool_Search_Architectural_Precedents();
		$result = $tool->execute(
			array(
				'query' => 'tropical courtyard residence',
				'limit' => 2,
			),
			$this->ctx()
		);
		$this->assertIsArray( $result );
		$this->assertSame( 'keyword', $result['mode'] );
		$this->assertGreaterThan( 0, $result['returned'] );
		$this->assertSame( 'Tropical Courtyard House', $result['results'][0]['title'] );
	}

	/**
	 * Search in embedding mode using the engine filter to inject pseudo embeddings.
	 */
	public function test_search_embedding_mode() {
		// Inject pseudo embeddings so embed_text() returns them.
		$test = $this;
		$cb   = static function ( $val, $text ) use ( $test ) {
			return $test->pseudo_embedding( $text );
		};
		add_filter( 'wp_mcp_ai_arch_prec_embedding', $cb, 10, 2 );

		$id1 = $this->create_precedent(
			array(
				'title'       => 'Tropical Courtyard House',
				'description' => 'Tropical courtyard residence cross-ventilation.',
			)
		);
		$id2 = $this->create_precedent(
			array(
				'title'        => 'Snow-Belt Lakeside Cabin',
				'description'  => 'High-snowload mountain cabin in cold climate.',
				'country_code' => 'US',
				'climate_zone' => 'Dfb',
			)
		);
		// Verify embeddings were cached on save.
		$this->assertNotEmpty( get_post_meta( $id1, '_arch_prec_embedding', true ) );
		$this->assertNotEmpty( get_post_meta( $id2, '_arch_prec_embedding', true ) );

		$tool   = new WP_MCP_AI_Tool_Search_Architectural_Precedents();
		$result = $tool->execute(
			array(
				'query' => 'tropical courtyard residence cross-ventilation',
				'limit' => 2,
			),
			$this->ctx()
		);
		remove_filter( 'wp_mcp_ai_arch_prec_embedding', $cb, 10 );

		$this->assertSame( 'embedding', $result['mode'] );
		$this->assertSame( 'Tropical Courtyard House', $result['results'][0]['title'] );
		// Top score should be higher than the second.
		$this->assertGreaterThan( $result['results'][1]['similarity'], $result['results'][0]['similarity'] );
	}

	/**
	 * Filters narrow the candidate set.
	 */
	public function test_search_filters_country_and_btype() {
		$this->create_precedent(
			array(
				'title'         => 'LK House A',
				'country_code'  => 'LK',
				'building_type' => 'residential',
			)
		);
		$this->create_precedent(
			array(
				'title'         => 'JM Office B',
				'country_code'  => 'JM',
				'building_type' => 'commercial',
			)
		);
		$this->create_precedent(
			array(
				'title'         => 'US Tower C',
				'country_code'  => 'US',
				'building_type' => 'commercial',
				'area_m2'       => 5000.0,
			)
		);

		$tool   = new WP_MCP_AI_Tool_Search_Architectural_Precedents();
		$result = $tool->execute(
			array(
				'query'         => 'building project',
				'country_code'  => 'JM',
				'building_type' => 'commercial',
				'limit'         => 5,
			),
			$this->ctx()
		);
		$this->assertSame( 1, $result['candidate_count'] );
		$this->assertSame( 'JM Office B', $result['results'][0]['title'] );

		$big = $tool->execute(
			array(
				'query'       => 'tower',
				'min_area_m2' => 1000.0,
				'limit'       => 5,
			),
			$this->ctx()
		);
		$this->assertSame( 1, $big['candidate_count'] );
		$this->assertSame( 'US Tower C', $big['results'][0]['title'] );
	}

	/**
	 * Search rejects empty queries.
	 */
	public function test_search_rejects_empty_query() {
		$tool   = new WP_MCP_AI_Tool_Search_Architectural_Precedents();
		$result = $tool->execute( array( 'query' => '   ' ), $this->ctx() );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_invalid_arguments', $result->get_error_code() );
	}

	/**
	 * Subscribers cannot search.
	 */
	public function test_search_access_control() {
		$tool   = new WP_MCP_AI_Tool_Search_Architectural_Precedents();
		$result = $tool->execute( array( 'query' => 'anything' ), $this->sub_ctx() );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Three example assistant blueprints exist and reference Phase A–E tools.
	 */
	public function test_example_blueprints_exist() {
		$pro_path = defined( 'WP_MCP_AI_PRO_PATH' )
			? WP_MCP_AI_PRO_PATH
			: dirname( __DIR__ ) . '/';
		$dir      = $pro_path . 'includes/tools/architectural-design/examples/';
		$expected = array( 'LK-residential.md', 'JM-hurricane-resilient.md', 'US-commercial.md' );
		foreach ( $expected as $file ) {
			$this->assertFileExists( $dir . $file, "Blueprint {$file} must exist." );
			$contents = file_get_contents( $dir . $file );
			$this->assertNotFalse( strpos( $contents, 'search_architectural_precedents' ), "Blueprint {$file} should reference search_architectural_precedents." );
		}
	}
}
