<?php
/**
 * Test ESPN Fantasy Get League tool roster settings handling.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for ESPN Fantasy Get League tool.
 */
class Test_ESPN_Fantasy_Get_League_Roster_Settings extends WP_UnitTestCase {

	/**
	 * ESPN Fantasy Get League tool instance.
	 *
	 * @var WP_MCP_AI_Tool_ESPN_Fantasy_Get_League
	 */
	protected $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Check if the Pro addon is available.
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available.' );
			return;
		}

		// Load the tool class.
		$tool_file = WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-espn-fantasy-get-league.php';
		if ( ! file_exists( $tool_file ) ) {
			$this->markTestSkipped( 'ESPN Fantasy Get League tool not found.' );
			return;
		}

		require_once $tool_file;
		$this->tool = new WP_MCP_AI_Tool_ESPN_Fantasy_Get_League();
	}

	/**
	 * Test that format_league_data handles non-array lineupSlotCounts gracefully.
	 */
	public function test_format_league_data_handles_non_array_lineup_slots() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'format_league_data' );
		$method->setAccessible( true );

		// Test with null lineupSlotCounts.
		$league_data_null = array(
			'id'                    => 12345,
			'seasonId'              => 2024,
			'scoringPeriodId'       => 10,
			'currentMatchupPeriod'  => 10,
			'finalScoringPeriod'    => 17,
			'settings'              => array(
				'name'           => 'Test League',
				'size'           => 12,
				'isPublic'       => true,
				'rosterSettings' => array(
					'lineupSlotCounts' => null,
				),
			),
		);

		$result = $method->invoke( $this->tool, $league_data_null );
		
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'roster_settings', $result );
		$this->assertEquals( 0, $result['roster_settings']['roster_size'] );
		$this->assertIsArray( $result['roster_settings']['positions'] );
		$this->assertEmpty( $result['roster_settings']['positions'] );
	}

	/**
	 * Test that format_league_data handles string lineupSlotCounts.
	 */
	public function test_format_league_data_handles_string_lineup_slots() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'format_league_data' );
		$method->setAccessible( true );

		// Test with string lineupSlotCounts.
		$league_data_string = array(
			'id'                    => 12345,
			'seasonId'              => 2024,
			'scoringPeriodId'       => 10,
			'currentMatchupPeriod'  => 10,
			'finalScoringPeriod'    => 17,
			'settings'              => array(
				'name'           => 'Test League',
				'size'           => 12,
				'isPublic'       => true,
				'rosterSettings' => array(
					'lineupSlotCounts' => 'invalid_string',
				),
			),
		);

		$result = $method->invoke( $this->tool, $league_data_string );
		
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'roster_settings', $result );
		$this->assertEquals( 0, $result['roster_settings']['roster_size'] );
		$this->assertIsArray( $result['roster_settings']['positions'] );
		$this->assertEmpty( $result['roster_settings']['positions'] );
	}

	/**
	 * Test that format_league_data handles valid array lineupSlotCounts.
	 */
	public function test_format_league_data_handles_valid_lineup_slots() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'format_league_data' );
		$method->setAccessible( true );

		// Test with valid array lineupSlotCounts.
		$league_data_valid = array(
			'id'                    => 12345,
			'seasonId'              => 2024,
			'scoringPeriodId'       => 10,
			'currentMatchupPeriod'  => 10,
			'finalScoringPeriod'    => 17,
			'settings'              => array(
				'name'           => 'Test League',
				'size'           => 12,
				'isPublic'       => true,
				'rosterSettings' => array(
					'lineupSlotCounts' => array(
						'QB'  => 1,
						'RB'  => 2,
						'WR'  => 2,
						'TE'  => 1,
						'FLEX' => 1,
						'K'   => 1,
						'DEF' => 1,
					),
				),
			),
		);

		$result = $method->invoke( $this->tool, $league_data_valid );
		
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'roster_settings', $result );
		$this->assertEquals( 9, $result['roster_settings']['roster_size'] ); // Sum of all positions.
		$this->assertIsArray( $result['roster_settings']['positions'] );
		$this->assertCount( 7, $result['roster_settings']['positions'] );
	}

	/**
	 * Test that format_league_data handles missing rosterSettings.
	 */
	public function test_format_league_data_handles_missing_roster_settings() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'format_league_data' );
		$method->setAccessible( true );

		// Test with missing rosterSettings.
		$league_data_missing = array(
			'id'                    => 12345,
			'seasonId'              => 2024,
			'scoringPeriodId'       => 10,
			'currentMatchupPeriod'  => 10,
			'finalScoringPeriod'    => 17,
			'settings'              => array(
				'name'     => 'Test League',
				'size'     => 12,
				'isPublic' => true,
			),
		);

		$result = $method->invoke( $this->tool, $league_data_missing );
		
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'roster_settings', $result );
		$this->assertEquals( 0, $result['roster_settings']['roster_size'] );
		$this->assertIsArray( $result['roster_settings']['positions'] );
		$this->assertEmpty( $result['roster_settings']['positions'] );
	}
}
