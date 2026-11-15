<?php
/**
 * Tests for the Auto-Select tool recommendation logic.
 *
 * Covers the deterministic keyword-matching fallback and the model-assisted
 * selection path in WP_MCP_AI_Tool_Presets_Helper.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Tests for the Auto-Select tool recommendation logic.
 */
class WP_MCP_AI_Tool_Presets_Auto_Select_Test extends WP_UnitTestCase {

	/**
	 * A set of base tool slugs known to exist in the registry.
	 *
	 * @var string[]
	 */
	private $known_tools = array(
		'web_search',
		'create_post',
		'get_recent_posts',
		'create_chart',
		'get_site_health',
	);

	/**
	 * Test that the deterministic path scores tools from the system prompt.
	 */
	public function test_compute_auto_select_data_matches_prompt_keywords() {
		$result = WP_MCP_AI_Tool_Presets_Helper::compute_auto_select_data(
			'Search the web and create blog posts.',
			array(),
			$this->known_tools
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'tools', $result );
		$this->assertArrayHasKey( 'reason', $result );
		$this->assertContains( 'web_search', $result['tools'] );
		$this->assertContains( 'create_post', $result['tools'] );
	}

	/**
	 * Test that the deterministic path uses profession default tools as signals.
	 */
	public function test_compute_auto_select_data_uses_profession_default_tools() {
		if ( ! post_type_exists( 'mcp_ai_profession' ) ) {
			$this->markTestSkipped( 'The mcp_ai_profession post type is not registered.' );
		}

		$profession_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_title'  => 'Reporting Specialist',
				'post_status' => 'publish',
			)
		);
		$this->assertIsInt( $profession_id );

		update_post_meta( $profession_id, '_wp_mcp_ai_profession_default_tools', array( 'create_chart' ) );

		$result = WP_MCP_AI_Tool_Presets_Helper::compute_auto_select_data(
			'',
			array( $profession_id ),
			$this->known_tools
		);

		$this->assertIsArray( $result );
		$this->assertContains( 'create_chart', $result['tools'] );

		wp_delete_post( $profession_id, true );
	}

	/**
	 * Test that the deterministic path returns an empty result with no context.
	 */
	public function test_compute_auto_select_data_returns_empty_without_context() {
		$result = WP_MCP_AI_Tool_Presets_Helper::compute_auto_select_data( '', array(), $this->known_tools );

		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['tools'] );
		$this->assertNotEmpty( $result['reason'] );
	}

	/**
	 * Test that the deterministic path honours the max-tools filter.
	 */
	public function test_compute_auto_select_data_honours_max_tools_filter() {
		add_filter( 'wp_mcp_ai_auto_select_max_tools', '__return_zero' );

		$result = WP_MCP_AI_Tool_Presets_Helper::compute_auto_select_data(
			'search the web create posts and charts',
			array(),
			$this->known_tools
		);

		remove_filter( 'wp_mcp_ai_auto_select_max_tools', '__return_zero' );

		$this->assertSame( array(), $result['tools'] );
	}

	/**
	 * Test that a narrow catalog call does not poison the cached tool index for
	 * later callers that need a wider catalog.
	 */
	public function test_tool_index_cache_is_order_independent() {
		// Reset the helper's cached indexes so this test controls the first build.
		$reset = Closure::bind(
			static function () {
				self::$tool_index = null;
			},
			null,
			'WP_MCP_AI_Tool_Presets_Helper'
		);
		$reset();

		// Narrow caller, as the admin-ajax endpoint does for a small selection.
		WP_MCP_AI_Tool_Presets_Helper::compute_auto_select_data(
			'search the web',
			array(),
			array( 'web_search' )
		);

		// Wider caller: create_chart must still be scored from its own index.
		$result = WP_MCP_AI_Tool_Presets_Helper::compute_auto_select_data(
			'create a chart',
			array(),
			$this->known_tools
		);

		$this->assertContains( 'create_chart', $result['tools'] );
	}

	/**
	 * Test that model output parses through prose and markdown fences.
	 */
	public function test_extract_slug_array_from_text_parses_fenced_json() {
		$text  = "Here you go:\n```json\n[\"web_search\", \"create_post\", \"bogus_tool\"]\n```";
		$slugs = WP_MCP_AI_Tool_Presets_Helper::extract_slug_array_from_text( $text, $this->known_tools );

		$this->assertSame( array( 'web_search', 'create_post' ), $slugs );
	}

	/**
	 * Test that model output is case-insensitive and deduplicated.
	 */
	public function test_extract_slug_array_from_text_normalises_case_and_dedupes() {
		$text  = '["Web_Search", "web_search", "CREATE_POST"]';
		$slugs = WP_MCP_AI_Tool_Presets_Helper::extract_slug_array_from_text( $text, $this->known_tools );

		$this->assertSame( array( 'web_search', 'create_post' ), $slugs );
	}

	/**
	 * Test that unparseable model output yields no slugs.
	 */
	public function test_extract_slug_array_from_text_rejects_garbage() {
		$slugs = WP_MCP_AI_Tool_Presets_Helper::extract_slug_array_from_text( 'I cannot comply.', $this->known_tools );

		$this->assertSame( array(), $slugs );
	}

	/**
	 * Test that the model-assisted path returns WP_Error without context.
	 */
	public function test_compute_model_auto_select_errors_without_context() {
		$result = WP_MCP_AI_Tool_Presets_Helper::compute_model_auto_select( '', array(), $this->known_tools, 'openai' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_auto_select_no_context', $result->get_error_code() );
	}

	/**
	 * Test that the model-assisted path validates the model's slug selection.
	 */
	public function test_compute_model_auto_select_validates_model_output() {
		$mock_response = array(
			'choices' => array(
				array(
					'message' => array(
						'content' => 'Sure! ["web_search", "bogus_tool", "create_post"]',
					),
				),
			),
		);

		add_filter(
			'wp_mcp_ai_auto_select_model_response',
			function () use ( $mock_response ) {
				return $mock_response;
			}
		);

		$result = WP_MCP_AI_Tool_Presets_Helper::compute_model_auto_select(
			'Search the web and publish posts.',
			array(),
			$this->known_tools,
			'openai',
			'gpt-test'
		);

		remove_all_filters( 'wp_mcp_ai_auto_select_model_response' );

		$this->assertIsArray( $result );
		$this->assertSame( 'model', $result['method'] );
		$this->assertSame( array( 'web_search', 'create_post' ), $result['tools'] );
		$this->assertNotEmpty( $result['reason'] );
	}

	/**
	 * Test that the model-assisted path honours its max-tools filter.
	 */
	public function test_compute_model_auto_select_honours_max_tools_filter() {
		$mock_response = array(
			'content' => '["web_search", "create_post", "get_recent_posts", "create_chart", "get_site_health"]',
		);

		add_filter(
			'wp_mcp_ai_auto_select_model_response',
			function () use ( $mock_response ) {
				return $mock_response;
			}
		);
		add_filter(
			'wp_mcp_ai_auto_select_model_max_tools',
			function () {
				return 2;
			}
		);

		$result = WP_MCP_AI_Tool_Presets_Helper::compute_model_auto_select(
			'Search, post, chart, health.',
			array(),
			$this->known_tools,
			'openai'
		);

		remove_all_filters( 'wp_mcp_ai_auto_select_model_response' );
		remove_all_filters( 'wp_mcp_ai_auto_select_model_max_tools' );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result['tools'] );
	}

	/**
	 * Test that a WP_Error from the provider surfaces unchanged.
	 */
	public function test_compute_model_auto_select_surfaces_provider_errors() {
		add_filter(
			'wp_mcp_ai_auto_select_model_response',
			function () {
				return new WP_Error( 'provider_down', 'The provider is offline.' );
			}
		);

		$result = WP_MCP_AI_Tool_Presets_Helper::compute_model_auto_select(
			'Search the web.',
			array(),
			$this->known_tools,
			'openai'
		);

		remove_all_filters( 'wp_mcp_ai_auto_select_model_response' );

		$this->assertWPError( $result );
		$this->assertSame( 'provider_down', $result->get_error_code() );
	}

	/**
	 * Test that the model-assisted path rejects a model answer with no valid slugs.
	 */
	public function test_compute_model_auto_select_errors_on_empty_selection() {
		add_filter(
			'wp_mcp_ai_auto_select_model_response',
			function () {
				return array(
					'choices' => array(
						array(
							'message' => array(
								'content' => '["not_a_real_tool"]',
							),
						),
					),
				);
			}
		);

		$result = WP_MCP_AI_Tool_Presets_Helper::compute_model_auto_select(
			'Search the web.',
			array(),
			$this->known_tools,
			'openai'
		);

		remove_all_filters( 'wp_mcp_ai_auto_select_model_response' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_auto_select_parse_failed', $result->get_error_code() );
	}
}
