<?php
/**
 * Tests for the Jev source-filter seam in Pro research tools.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test doubles scoped to this suite.

/**
 * Exposes the protected Jev filter seam of the ECA research tool.
 */
class Test_ECA_With_Jev_Seam extends WP_MCP_AI_Tool_Research_ECA {

	/**
	 * Public passthrough for the protected seam.
	 *
	 * @param array  $search_results Search results.
	 * @param string $query          Research query.
	 * @return array
	 */
	public function filter( $search_results, $query ) {
		return $this->maybe_jev_filter_sources( $search_results, $query );
	}
}

/**
 * Exposes the protected Jev filter seam of the research-report tool.
 */
class Test_Report_With_Jev_Seam extends WP_MCP_AI_Pro_Tool_Generate_Research_Report {

	/**
	 * Public passthrough for the protected seam.
	 *
	 * @param array  $search_results Search results.
	 * @param string $topic          Research topic.
	 * @return array
	 */
	public function filter( $search_results, $topic ) {
		return $this->maybe_jev_filter_sources( $search_results, $topic );
	}
}

/**
 * Test class for the research Jev filter seam.
 */
class Test_Research_Jev_Filter extends WP_UnitTestCase {

	/**
	 * ECA tool seam.
	 *
	 * @var Test_ECA_With_Jev_Seam
	 */
	private $eca_tool;

	/**
	 * Report tool seam.
	 *
	 * @var Test_Report_With_Jev_Seam
	 */
	private $report_tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			define( 'WP_MCP_AI_PRO_PATH', dirname( __DIR__ ) . '/' );
		}

		require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
		require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-decision-client.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-typesafe-client.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openrouter-client.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-pro-jev-classifier.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-research-eca.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-generate-research-report.php';

		$this->eca_tool    = new Test_ECA_With_Jev_Seam();
		$this->report_tool = new Test_Report_With_Jev_Seam();

		wp_cache_flush();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		remove_all_filters( 'pre_http_request' );
		wp_cache_flush();
		parent::tearDown();
	}

	/**
	 * Helper: build a search-results array with $n sources.
	 *
	 * @param int $n Number of sources.
	 * @return array
	 */
	private function build_search_results( $n ) {
		$sources = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$sources[] = array(
				'url'     => 'https://example.com/source-' . $i,
				'title'   => 'Source ' . $i,
				'snippet' => 'Snippet for source ' . $i,
			);
		}

		return array(
			'results' => array(),
			'sources' => $sources,
			'queries' => array( 'chess club' ),
		);
	}

	/**
	 * Test the ECA seam is a no-op when the setting is off.
	 */
	public function test_eca_seam_noop_when_setting_off() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_typesafe'  => true,
				'typesafe_api_key' => 'sk-ts-test',
			)
		);

		$input  = $this->build_search_results( 8 );
		$output = $this->eca_tool->filter( $input, 'chess club' );

		$this->assertSame( $input, $output );
	}

	/**
	 * Test the report seam is a no-op when the setting is off.
	 */
	public function test_report_seam_noop_when_setting_off() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_typesafe'  => true,
				'typesafe_api_key' => 'sk-ts-test',
			)
		);

		$input  = $this->build_search_results( 8 );
		$output = $this->report_tool->filter( $input, 'chess club' );

		$this->assertSame( $input, $output );
	}

	/**
	 * Test the ECA seam fails open when Jev credentials are missing.
	 */
	public function test_eca_seam_fails_open_without_credentials() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_jev_research_filter' => true ) );

		$input  = $this->build_search_results( 8 );
		$output = $this->eca_tool->filter( $input, 'chess club' );

		$this->assertSame( $input, $output );
	}

	/**
	 * Test the report seam filters and records the dropped count when enabled.
	 */
	public function test_report_seam_filters_when_enabled() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_typesafe'            => true,
				'typesafe_api_key'           => 'sk-ts-test',
				'enable_jev_research_filter' => true,
			)
		);

		$score_map = array(
			0 => 0.1,
			1 => 1.2,
			2 => 2.8,
			3 => 0.4,
			4 => 1.1,
			5 => 1.5,
			6 => 1.0,
			7 => 1.3,
		);

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( $score_map ) {
				$body    = json_decode( $args['body'], true );
				$answers = array();

				foreach ( array_keys( $body['questions'] ) as $key ) {
					preg_match( '/relevance_(\d+)/', $key, $matches );
					$index           = (int) $matches[1];
					$answers[ $key ] = array( 'score' => isset( $score_map[ $index ] ) ? $score_map[ $index ] : 1.0 );
				}

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'model'   => 'jev-1.13.0',
							'answers' => $answers,
						)
					),
				);
			},
			10,
			2
		);

		$input  = $this->build_search_results( 8 );
		$output = $this->report_tool->filter( $input, 'chess club' );

		remove_all_filters( 'pre_http_request' );

		$this->assertNotSame( $input, $output );
		$this->assertEquals( 2, $output['jev_dropped'] );
		$this->assertCount( 6, $output['sources'] );
		$this->assertEquals( 'https://example.com/source-2', $output['sources'][0]['url'] );
	}

	/**
	 * Test the ECA seam fails open on a transport error.
	 */
	public function test_eca_seam_fails_open_on_transport_error() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_typesafe'            => true,
				'typesafe_api_key'           => 'sk-ts-test',
				'enable_jev_research_filter' => true,
			)
		);

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 500 ),
					'body'     => wp_json_encode( array( 'error' => array( 'message' => 'Boom' ) ) ),
				);
			},
			10
		);

		$input  = $this->build_search_results( 8 );
		$output = $this->eca_tool->filter( $input, 'chess club' );

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( $input, $output );
	}
}
