<?php
/**
 * Tests for WP_MCP_AI_Tool_Create_Chart_Fence class.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the create_chart_fence tool.
 *
 * @group tools
 * @group create-chart-fence
 */
class WP_MCP_AI_Tool_Create_Chart_Fence_Tests extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Create_Chart_Fence
	 */
	protected $tool;

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);

		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-chart-fence.php';

		$this->tool = new WP_MCP_AI_Tool_Create_Chart_Fence();
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'create_chart_fence', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
		$this->assertSame( 'edit_posts', $this->tool->get_required_capability() );
	}

	/**
	 * Test the parameter schema declares the v2 keys.
	 */
	public function test_parameter_schema_declares_v2_keys() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertSame( 'object', $schema['type'] );
		foreach ( array( 'type', 'title', 'labels', 'values', 'series', 'mode', 'sort', 'unit', 'animate', 'table', 'data' ) as $key ) {
			$this->assertArrayHasKey( $key, $schema['properties'], $key . ' missing from schema' );
		}
		$this->assertContains( 'violin', $schema['properties']['type']['enum'] );
		$this->assertContains( 'waterfall', $schema['properties']['type']['enum'] );
	}

	/**
	 * Test a simple values-shorthand fence.
	 */
	public function test_execute_creates_values_fence() {
		wp_set_current_user( $this->user_id );

		$result = $this->tool->execute(
			array(
				'type'   => 'bar',
				'title'  => 'Sales',
				'labels' => array( 'A', 'B' ),
				'values' => array( 1, 4 ),
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'bar', $result['chart_type'] );
		$this->assertStringContainsString( '```nvoos-chart', $result['fence'] );
		$this->assertStringContainsString( '"type": "bar"', $result['fence'] );

		$decoded = json_decode( $this->extract_fence_json( $result['fence'] ), true );
		$this->assertIsArray( $decoded );
		$this->assertSame( array( 1, 4 ), $decoded['series'][0]['values'] );
		$this->assertSame( array( 'A', 'B' ), $decoded['labels'] );
		$this->assertStringContainsString( 'Sales', $result['fallback_table'] );
	}

	/**
	 * Test multi-series input with colour whitelisting.
	 */
	public function test_execute_sanitises_series_and_colors() {
		wp_set_current_user( $this->user_id );

		$result = $this->tool->execute(
			array(
				'type'   => 'column',
				'mode'   => 'grouped',
				'series' => array(
					array(
						'name'   => 'One',
						'values' => array( '1', 2, 'NaN' ),
						'color'  => '#ff0000',
					),
					array(
						'name'   => 'Two',
						'values' => array( 3, 4 ),
						'color'  => 'javascript:alert(1)',
					),
				),
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$decoded = json_decode( $this->extract_fence_json( $result['fence'] ), true );
		$this->assertCount( 2, $decoded['series'] );
		// Numeric strings coerced, non-finite entries dropped.
		$this->assertSame( array( 1, 2 ), $decoded['series'][0]['values'] );
		$this->assertSame( '#ff0000', $decoded['series'][0]['color'] );
		// Non-colour strings are stripped, not escaped into the fence.
		$this->assertArrayNotHasKey( 'color', $decoded['series'][1] );
		$this->assertSame( 'grouped', $decoded['mode'] );
		$this->assertStringNotContainsString( 'javascript:', $result['fence'] );
	}

	/**
	 * Test the data.items alternate input.
	 */
	public function test_execute_accepts_data_items() {
		wp_set_current_user( $this->user_id );

		$result = $this->tool->execute(
			array(
				'type' => 'donut',
				'data' => array(
					'items' => array(
						array(
							'label' => 'X',
							'value' => 30,
						),
						array(
							'label' => 'Y',
							'value' => 70,
						),
					),
				),
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$decoded = json_decode( $this->extract_fence_json( $result['fence'] ), true );
		$this->assertSame( array( 'X', 'Y' ), $decoded['labels'] );
		$this->assertSame( array( 30, 70 ), $decoded['series'][0]['values'] );
	}

	/**
	 * Test invalid type rejection.
	 */
	public function test_execute_rejects_invalid_type() {
		wp_set_current_user( $this->user_id );

		$result = $this->tool->execute(
			array(
				'type'   => 'funnel',
				'values' => array( 1 ),
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_chart_type', $result->get_error_code() );
	}

	/**
	 * Test missing data rejection.
	 */
	public function test_execute_rejects_missing_data() {
		wp_set_current_user( $this->user_id );

		$result = $this->tool->execute(
			array( 'type' => 'line' ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_chart_data', $result->get_error_code() );
	}

	/**
	 * Test unauthenticated rejection.
	 */
	public function test_execute_requires_authentication() {
		$result = $this->tool->execute(
			array(
				'type'   => 'bar',
				'values' => array( 1 ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test v2 optional keys are whitelisted.
	 */
	public function test_execute_whitelists_v2_keys() {
		wp_set_current_user( $this->user_id );

		$result = $this->tool->execute(
			array(
				'type'      => 'bullet',
				'values'    => array( 40, 60, 80 ),
				'labels'    => array( 'L1', 'L2', 'L3' ),
				'target'    => 70,
				'sort'      => 'desc',
				'unit'      => '%',
				'animate'   => true,
				'table'     => false,
				'direction' => 'nonsense',
				'mode'      => 'nonsense',
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$decoded = json_decode( $this->extract_fence_json( $result['fence'] ), true );
		$this->assertSame( 70, $decoded['target'] );
		$this->assertSame( 'desc', $decoded['sort'] );
		$this->assertSame( '%', $decoded['unit'] );
		$this->assertTrue( $decoded['animate'] );
		$this->assertFalse( $decoded['table'] );
		// Invalid enum values are dropped, not passed through.
		$this->assertArrayNotHasKey( 'direction', $decoded );
		$this->assertArrayNotHasKey( 'mode', $decoded );
	}

	/**
	 * Extract the JSON payload from a returned fence string.
	 *
	 * @param string $fence Fenced code block.
	 * @return string
	 */
	private function extract_fence_json( $fence ) {
		$fence = preg_replace( '/^```nvoos-chart\s*/', '', $fence );
		$fence = preg_replace( '/\s*```$/', '', $fence );

		return trim( $fence );
	}
}
