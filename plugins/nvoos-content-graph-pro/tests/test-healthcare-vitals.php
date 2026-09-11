<?php
/**
 * Characterization tests for the Wave F4 healthcare vitals batch — the
 * eight ported vitals tools.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes; the byte-identical surfaces and the deterministic execute()
 *   contracts are asserted (the gated map files are required
 *   deterministically in setUp).
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/healthcare/vitals/` are asserted in full.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Healthcare vitals tests.
 */
class Test_Healthcare_Vitals extends WP_UnitTestCase {

	/**
	 * Monolith matrix: require the eight gated base files deterministically.
	 * Standalone: the entry autoloader serves them on demand.
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$files = array(
				'tools/healthcare/vitals/class-wp-mcp-ai-tool-analyze-vital-trends.php',
				'tools/healthcare/vitals/class-wp-mcp-ai-tool-compute-bmi-and-growth-percentile.php',
				'tools/healthcare/vitals/class-wp-mcp-ai-tool-flag-abnormal-vitals.php',
				'tools/healthcare/vitals/class-wp-mcp-ai-tool-get-vaccination-schedule.php',
				'tools/healthcare/vitals/class-wp-mcp-ai-tool-import-vitals.php',
				'tools/healthcare/vitals/class-wp-mcp-ai-tool-log-health-metrics.php',
				'tools/healthcare/vitals/class-wp-mcp-ai-tool-log-vital-signs.php',
				'tools/healthcare/vitals/class-wp-mcp-ai-tool-track-vaccinations.php',
			);
			foreach ( $files as $file ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/' . $file;
			}
		}
	}

	/**
	 * The eight ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Analyze_Vital_Trends'     => 'tools/healthcare/vitals/class-wp-mcp-ai-tool-analyze-vital-trends.php',
			'WP_MCP_AI_Tool_Compute_BMI_And_Growth_Percentile' => 'tools/healthcare/vitals/class-wp-mcp-ai-tool-compute-bmi-and-growth-percentile.php',
			'WP_MCP_AI_Tool_Flag_Abnormal_Vitals'     => 'tools/healthcare/vitals/class-wp-mcp-ai-tool-flag-abnormal-vitals.php',
			'WP_MCP_AI_Tool_Get_Vaccination_Schedule' => 'tools/healthcare/vitals/class-wp-mcp-ai-tool-get-vaccination-schedule.php',
			'WP_MCP_AI_Tool_Import_Vitals'            => 'tools/healthcare/vitals/class-wp-mcp-ai-tool-import-vitals.php',
			'WP_MCP_AI_Tool_Log_Health_Metrics'       => 'tools/healthcare/vitals/class-wp-mcp-ai-tool-log-health-metrics.php',
			'WP_MCP_AI_Tool_Log_Vital_Signs'          => 'tools/healthcare/vitals/class-wp-mcp-ai-tool-log-vital-signs.php',
			'WP_MCP_AI_Tool_Track_Vaccinations'       => 'tools/healthcare/vitals/class-wp-mcp-ai-tool-track-vaccinations.php',
		);

		foreach ( $symbols as $class => $file ) {
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'addons/pro/includes/' . $file, $path, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/' . $file, $path, $class );
			}
		}
	}

	/**
	 * The tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Analyze_Vital_Trends'     => 'analyze_vital_trends',
			'WP_MCP_AI_Tool_Compute_BMI_And_Growth_Percentile' => 'compute_bmi_and_growth_percentile',
			'WP_MCP_AI_Tool_Flag_Abnormal_Vitals'     => 'flag_abnormal_vitals',
			'WP_MCP_AI_Tool_Get_Vaccination_Schedule' => 'get_vaccination_schedule',
			'WP_MCP_AI_Tool_Import_Vitals'            => 'import_vitals',
			'WP_MCP_AI_Tool_Log_Health_Metrics'       => 'log_health_metrics',
			'WP_MCP_AI_Tool_Log_Vital_Signs'          => 'log_vital_signs',
			'WP_MCP_AI_Tool_Track_Vaccinations'       => 'track_vaccinations',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
		}
	}

	/**
	 * The deterministic BMI contract: 90 kg / 190 cm → BMI 24.93 with the
	 * normal band (just under the 25.0 threshold), and the invalid-input
	 * gate degrades gracefully.
	 */
	public function test_bmi_contract(): void {
		$tool = new WP_MCP_AI_Tool_Compute_BMI_And_Growth_Percentile();

		$invalid = $tool->execute(
			array(
				'weight' => 0,
				'height' => 0,
			)
		);
		$this->assertInstanceOf( 'WP_Error', $invalid );
		$this->assertSame( 'wp_mcp_ai_invalid_input', $invalid->get_error_code() );

		$result = $tool->execute(
			array(
				'weight'      => 90,
				'height'      => 190,
				'weight_unit' => 'kg',
				'height_unit' => 'cm',
			)
		);
		$this->assertTrue( $result['success'] );
		$this->assertSame( 24.93, $result['bmi'] );
		$this->assertSame( 'normal', $result['band'] );
	}
}
