<?php
/**
 * Tests for semantic_content_search tool provider routing, degradation, and
 * dimension hygiene.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test semantic_content_search reliability behaviour.
 */
class Test_Tool_Semantic_Content_Search extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Semantic_Content_Search
	 */
	private $tool;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Original settings snapshot, restored in tearDown.
	 *
	 * @var array
	 */
	private $original_settings = array();

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->tool = new WP_MCP_AI_Tool_Semantic_Content_Search();

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$this->original_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		// Empty settings so no real provider resolves; also forces the
		// provider classes (and interface) to load for the fake providers
		// used by individual tests.
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		WP_MCP_AI_Vector_Context_Service::get_instance()->reset_embedding_provider();
		WP_MCP_AI_Vector_Context_Service::get_instance()->get_embedding_provider();
	}

	/**
	 * Tear down: restore settings, reset provider, remove filters.
	 */
	public function tearDown(): void {
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $this->original_settings );
		WP_MCP_AI_Vector_Context_Service::get_instance()->reset_embedding_provider();
		remove_all_filters( 'wp_mcp_ai_embedding_provider' );
		remove_all_filters( 'wp_mcp_ai_semantic_search_keyword_fallback' );
		parent::tearDown();
	}

	/**
	 * Register a fake embedding provider that always returns a fixed vector.
	 *
	 * @param array $vector Vector the provider should return.
	 * @return void
	 */
	private function register_fake_provider( array $vector ) {
		$fake = new class( $vector ) implements WP_MCP_AI_Embedding_Provider_Interface {
			/**
			 * Fixed vector.
			 *
			 * @var array
			 */
			private $vector;

			/**
			 * Constructor.
			 *
			 * @param array $vector Fixed vector to return.
			 */
			public function __construct( array $vector ) {
				$this->vector = $vector;
			}

			/**
			 * {@inheritdoc}
			 */
			public function get_id() {
				return 'fake';
			}

			/**
			 * {@inheritdoc}
			 */
			public function get_model() {
				return 'fake-model';
			}

			/**
			 * {@inheritdoc}
			 */
			public function is_available() {
				return true;
			}

			/**
			 * Generate the fake embedding vector.
			 *
			 * @param string $text Text to embed (ignored).
			 * @return array Fixed vector.
			 */
			public function embed( $text ) {
				return $this->vector;
			}
		};

		WP_MCP_AI_Vector_Context_Service::get_instance()->reset_embedding_provider();

		add_filter(
			'wp_mcp_ai_embedding_provider',
			function () use ( $fake ) {
				return $fake;
			}
		);
	}

	/**
	 * Store a post embedding with the given vector under the legacy meta key.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $vector  Embedding vector.
	 * @return void
	 */
	private function store_post_embedding( $post_id, array $vector ) {
		update_post_meta(
			$post_id,
			'_wp_mcp_ai_embeddings',
			array(
				'embeddings' => array(
					array( 'embedding' => $vector ),
				),
				'model'      => 'fake-model',
			)
		);
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'semantic_content_search', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
	}

	/**
	 * Unauthenticated call returns forbidden error.
	 */
	public function test_unauthenticated_returns_forbidden() {
		$result = $this->tool->execute(
			array( 'query' => 'gardening' ),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Missing query returns missing_query error.
	 */
	public function test_missing_query_returns_error() {
		$result = $this->tool->execute(
			array(),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_query', $result->get_error_code() );
	}

	/**
	 * With no embedding provider configured the tool degrades to keyword search.
	 */
	public function test_keyword_fallback_when_no_embedding_provider() {
		$this->factory->post->create(
			array(
				'post_title'   => 'Greenhouse winter gardening guide',
				'post_content' => 'Growing vegetables in a greenhouse through winter.',
				'post_status'  => 'publish',
			)
		);

		$result = $this->tool->execute(
			array( 'query' => 'greenhouse winter gardening' ),
			array(
				'user_id'          => $this->admin_id,
				'assistant_config' => array( 'provider' => 'deepseek' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'keyword', $result['fallback_mode'] );
		$this->assertFalse( $result['semantic_search_enabled'] );
		$this->assertNotEmpty( $result['warning'] );
		$this->assertNotEmpty( $result['results'] );
	}

	/**
	 * Disabling the keyword fallback returns the actionable WP_Error.
	 */
	public function test_keyword_fallback_disabled_returns_error() {
		add_filter( 'wp_mcp_ai_semantic_search_keyword_fallback', '__return_false' );

		$result = $this->tool->execute(
			array( 'query' => 'greenhouse' ),
			array(
				'user_id'          => $this->admin_id,
				'assistant_config' => array( 'provider' => 'deepseek' ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_no_embedding_provider', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'actions', $data );
	}

	/**
	 * Stored vectors with mismatched dimensions are skipped and counted.
	 */
	public function test_dimension_mismatch_is_skipped_and_counted() {
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'Stored post',
				'post_status' => 'publish',
			)
		);

		// Fake provider returns 2-dim vectors; stored vector has 3 dims.
		$this->register_fake_provider( array( 0.1, 0.2 ) );
		$this->store_post_embedding( $post_id, array( 1.0, 2.0, 3.0 ) );

		$result = $this->tool->execute(
			array(
				'query'     => 'gardening',
				'threshold' => 0.0,
			),
			array(
				'user_id'          => $this->admin_id,
				'assistant_config' => array( 'provider' => 'openai' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['embeddings_checked'] );
		$this->assertSame( 1, $result['skipped_dimension_mismatch'] );
		$this->assertSame( 'fake-model', $result['query_embedding_model'] );
		$this->assertSame( 'fake', $result['query_embedding_provider'] );
		$this->assertEmpty( $result['results'] );
	}

	/**
	 * Matching-dimension stored vectors are scored and returned.
	 */
	public function test_matching_dimension_vector_is_returned() {
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Garden planning',
				'post_excerpt' => 'Planning a garden for the season.',
				'post_status'  => 'publish',
			)
		);

		// Identical vectors: cosine similarity is exactly 1.0.
		$this->register_fake_provider( array( 0.1, 0.2 ) );
		$this->store_post_embedding( $post_id, array( 0.1, 0.2 ) );

		$result = $this->tool->execute(
			array(
				'query'     => 'garden',
				'threshold' => 0.9,
			),
			array(
				'user_id'          => $this->admin_id,
				'assistant_config' => array( 'provider' => 'openai' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['embeddings_checked'] );
		$this->assertSame( 0, $result['skipped_dimension_mismatch'] );
		$this->assertNotEmpty( $result['results'] );
		$this->assertSame( 'wordpress', $result['results'][0]['source'] ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Value under test, not prose.
		$this->assertSame( $post_id, $result['results'][0]['post_id'] );
	}
}
