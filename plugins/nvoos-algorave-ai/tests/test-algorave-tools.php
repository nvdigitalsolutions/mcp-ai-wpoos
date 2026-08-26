<?php
/**
 * Tests for Algorave addon tools.
 *
 * @package NV_oOS_Algorave
 * @since   1.0.0
 */

/**
 * Algorave tool tests.
 */
class Test_Algorave_Tools extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Standalone plugin directory (holds the core + CPT classes).
		if ( ! defined( 'NVOOS_ALGORAVE_PATH' ) ) {
			define( 'NVOOS_ALGORAVE_PATH', dirname( __DIR__, 2 ) . '/nvoos-algorave/' );
		}
		if ( ! defined( 'NVOOS_ALGORAVE_URL' ) ) {
			define( 'NVOOS_ALGORAVE_URL', 'http://example.com/wp-content/plugins/nvoos-algorave/' );
		}
		if ( ! defined( 'NVOOS_ALGORAVE_VERSION' ) ) {
			define( 'NVOOS_ALGORAVE_VERSION', '1.0.0' );
		}
		// AI addon directory (holds the tool classes).
		if ( ! defined( 'NVOOS_ALGORAVE_AI_PATH' ) ) {
			define( 'NVOOS_ALGORAVE_AI_PATH', dirname( __DIR__ ) . '/' );
		}

		// Load the standalone plugin's core classes.
		require_once NVOOS_ALGORAVE_PATH . 'includes/class-nvoos-algorave.php';
		require_once NVOOS_ALGORAVE_PATH . 'includes/class-nvoos-algorave-pattern-cpt.php';
		require_once NVOOS_ALGORAVE_PATH . 'includes/class-nvoos-algorave-sample-library.php';

		// Load tool files from this addon.
		$tools_dir = NVOOS_ALGORAVE_AI_PATH . 'includes/tools/';

		require_once $tools_dir . 'class-nvoos-algorave-tool-generate-pattern.php';
		require_once $tools_dir . 'class-nvoos-algorave-tool-modify-pattern.php';
		require_once $tools_dir . 'class-nvoos-algorave-tool-play-control.php';
		require_once $tools_dir . 'class-nvoos-algorave-tool-export-midi.php';
		require_once $tools_dir . 'class-nvoos-algorave-tool-sample-manager.php';
		require_once $tools_dir . 'class-nvoos-algorave-tool-generate-music-ai.php';
		require_once $tools_dir . 'class-nvoos-algorave-tool-visualizer.php';
		require_once $tools_dir . 'class-nvoos-algorave-tool-strudel-reference.php';
		require_once $tools_dir . 'class-nvoos-algorave-tool-midi-output.php';
	}

	/**
	 * Test that all tools implement the correct interface.
	 */
	public function test_tools_implement_interface() {
		$tools = array(
			new NV_oOS_Algorave_Tool_Generate_Pattern(),
			new NV_oOS_Algorave_Tool_Modify_Pattern(),
			new NV_oOS_Algorave_Tool_Play_Control(),
			new NV_oOS_Algorave_Tool_Export_MIDI(),
			new NV_oOS_Algorave_Tool_Sample_Manager(),
			new NV_oOS_Algorave_Tool_Generate_Music_AI(),
			new NV_oOS_Algorave_Tool_Visualizer(),
			new NV_oOS_Algorave_Tool_Strudel_Reference(),
			new NV_oOS_Algorave_Tool_MIDI_Output(),
		);

		foreach ( $tools as $tool ) {
			$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
			$this->assertInstanceOf( 'WP_MCP_AI_Tool_Capability_Flags_Interface', $tool );
		}
	}

	/**
	 * Test generate pattern tool with Strudel engine.
	 */
	public function test_generate_pattern_strudel() {
		$tool   = new NV_oOS_Algorave_Tool_Generate_Pattern();
		$result = $tool->execute(
			array(
				'description' => 'A techno beat at 130bpm',
				'engine'      => 'strudel',
				'bpm'         => 130,
				'scale'       => 'C minor',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertNotEmpty( $result['code'] );
		$this->assertEquals( 'strudel', $result['engine'] );
		$this->assertEquals( 130, $result['bpm'] );
	}

	/**
	 * Test generate pattern tool with Tone.js engine.
	 */
	public function test_generate_pattern_tonejs() {
		$tool   = new NV_oOS_Algorave_Tool_Generate_Pattern();
		$result = $tool->execute(
			array(
				'description' => 'Ambient pads',
				'engine'      => 'tonejs',
				'bpm'         => 80,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertNotEmpty( $result['code'] );
		$this->assertEquals( 'tonejs', $result['engine'] );
		$this->assertStringContainsString( 'Tone.Transport', $result['code'] );
	}

	/**
	 * Test generate pattern requires description.
	 */
	public function test_generate_pattern_requires_description() {
		$tool   = new NV_oOS_Algorave_Tool_Generate_Pattern();
		$result = $tool->execute( array() );

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
	}

	/**
	 * Test play control tool.
	 */
	public function test_play_control() {
		$tool = new NV_oOS_Algorave_Tool_Play_Control();

		// Test play action.
		$result = $tool->execute(
			array(
				'action' => 'play',
				'code'   => 'test code',
			)
		);
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'play', $result['action'] );
		$this->assertTrue( $result['_browser_command'] );

		// Test stop action.
		$result = $tool->execute( array( 'action' => 'stop' ) );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'stop', $result['action'] );

		// Test invalid action.
		$result = $tool->execute( array( 'action' => 'invalid' ) );
		$this->assertFalse( $result['success'] );
	}

	/**
	 * Test set BPM via play control.
	 */
	public function test_play_control_set_bpm() {
		$tool   = new NV_oOS_Algorave_Tool_Play_Control();
		$result = $tool->execute(
			array(
				'action' => 'set_bpm',
				'bpm'    => 140,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 140, $result['bpm'] );
	}

	/**
	 * Test MIDI export generates valid output.
	 */
	public function test_export_midi() {
		$tool   = new NV_oOS_Algorave_Tool_Export_MIDI();
		$result = $tool->execute(
			array(
				'name'  => 'test-pattern',
				'bpm'   => 120,
				'notes' => array(
					array(
						'note'     => 'C4',
						'time'     => 0,
						'duration' => 1,
						'velocity' => 100,
					),
					array(
						'note'     => 'E4',
						'time'     => 1,
						'duration' => 1,
						'velocity' => 90,
					),
					array(
						'note'     => 'G4',
						'time'     => 2,
						'duration' => 1,
						'velocity' => 80,
					),
				),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'url', $result );
		$this->assertEquals( 3, $result['note_count'] );
	}

	/**
	 * Test MIDI export requires notes.
	 */
	public function test_export_midi_requires_notes() {
		$tool   = new NV_oOS_Algorave_Tool_Export_MIDI();
		$result = $tool->execute( array( 'name' => 'empty' ) );

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
	}

	/**
	 * Test visualizer tool.
	 */
	public function test_visualizer() {
		$tool = new NV_oOS_Algorave_Tool_Visualizer();

		// Test set mode.
		$result = $tool->execute(
			array(
				'action' => 'set_mode',
				'mode'   => 'spectrum',
			)
		);
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'spectrum', $result['mode'] );

		// Test set color.
		$result = $tool->execute(
			array(
				'action' => 'set_color',
				'color'  => '#ff0000',
			)
		);
		$this->assertTrue( $result['success'] );
		$this->assertEquals( '#ff0000', $result['color'] );

		// Test toggle.
		$result = $tool->execute(
			array(
				'action'  => 'toggle',
				'enabled' => true,
			)
		);
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['enabled'] );

		// Test invalid mode.
		$result = $tool->execute(
			array(
				'action' => 'set_mode',
				'mode'   => 'invalid',
			)
		);
		$this->assertFalse( $result['success'] );
	}

	/**
	 * Test modify pattern tool.
	 */
	public function test_modify_pattern() {
		$tool   = new NV_oOS_Algorave_Tool_Modify_Pattern();
		$result = $tool->execute(
			array(
				'code'         => 's("bd*4").gain(0.8)',
				'modification' => 'speed it up to 140bpm',
				'engine'       => 'strudel',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'original_code', $result );
		$this->assertArrayHasKey( 'modification', $result );
	}

	/**
	 * Test modify pattern requires both code and modification.
	 */
	public function test_modify_pattern_requires_inputs() {
		$tool = new NV_oOS_Algorave_Tool_Modify_Pattern();

		// Missing code.
		$result = $tool->execute( array( 'modification' => 'change bpm' ) );
		$this->assertFalse( $result['success'] );

		// Missing modification.
		$result = $tool->execute( array( 'code' => 's("bd*4")' ) );
		$this->assertFalse( $result['success'] );
	}

	/**
	 * Test sample manager browse returns expected format.
	 */
	public function test_sample_manager_browse() {
		$tool   = new NV_oOS_Algorave_Tool_Sample_Manager();
		$result = $tool->execute( array( 'action' => 'browse' ) );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'samples', $result );
		$this->assertArrayHasKey( 'total', $result );
	}

	/**
	 * Test AI music generation requires prompt.
	 *
	 * The tool follows the canonical envelope rules: invalid input returns
	 * a WP_Error, not an array( 'success' => false, ... ) shape.
	 */
	public function test_generate_music_ai_requires_prompt() {
		$tool   = new NV_oOS_Algorave_Tool_Generate_Music_AI();
		$result = $tool->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'tool_error', $result->get_error_code() );
	}

	/**
	 * Test all tools have unique slugs.
	 */
	public function test_tool_slugs_are_unique() {
		$tools = array(
			new NV_oOS_Algorave_Tool_Generate_Pattern(),
			new NV_oOS_Algorave_Tool_Modify_Pattern(),
			new NV_oOS_Algorave_Tool_Play_Control(),
			new NV_oOS_Algorave_Tool_Export_MIDI(),
			new NV_oOS_Algorave_Tool_Sample_Manager(),
			new NV_oOS_Algorave_Tool_Generate_Music_AI(),
			new NV_oOS_Algorave_Tool_Visualizer(),
			new NV_oOS_Algorave_Tool_Strudel_Reference(),
			new NV_oOS_Algorave_Tool_MIDI_Output(),
		);

		$slugs = array_map(
			function ( $tool ) {
				return $tool->get_slug();
			},
			$tools
		);

		$this->assertCount( count( $tools ), array_unique( $slugs ), 'Tool slugs must be unique.' );
	}

	/**
	 * Test all tool parameters schemas are valid.
	 */
	public function test_tool_schemas_are_valid() {
		$tools = array(
			new NV_oOS_Algorave_Tool_Generate_Pattern(),
			new NV_oOS_Algorave_Tool_Modify_Pattern(),
			new NV_oOS_Algorave_Tool_Play_Control(),
			new NV_oOS_Algorave_Tool_Export_MIDI(),
			new NV_oOS_Algorave_Tool_Sample_Manager(),
			new NV_oOS_Algorave_Tool_Generate_Music_AI(),
			new NV_oOS_Algorave_Tool_Visualizer(),
			new NV_oOS_Algorave_Tool_Strudel_Reference(),
			new NV_oOS_Algorave_Tool_MIDI_Output(),
		);

		foreach ( $tools as $tool ) {
			$schema = $tool->get_parameters_schema();
			$this->assertEquals( 'object', $schema['type'], $tool->get_slug() . ' schema must be type "object".' );
			$this->assertArrayHasKey( 'properties', $schema, $tool->get_slug() . ' schema must have "properties".' );
		}
	}

	/**
	 * Test all tools have capability flags.
	 */
	public function test_tools_have_capability_flags() {
		$tools = array(
			new NV_oOS_Algorave_Tool_Generate_Pattern(),
			new NV_oOS_Algorave_Tool_Modify_Pattern(),
			new NV_oOS_Algorave_Tool_Play_Control(),
			new NV_oOS_Algorave_Tool_Export_MIDI(),
			new NV_oOS_Algorave_Tool_Sample_Manager(),
			new NV_oOS_Algorave_Tool_Generate_Music_AI(),
			new NV_oOS_Algorave_Tool_Visualizer(),
			new NV_oOS_Algorave_Tool_Strudel_Reference(),
			new NV_oOS_Algorave_Tool_MIDI_Output(),
		);

		foreach ( $tools as $tool ) {
			$flags = $tool->get_capability_flags();
			$this->assertIsArray( $flags, $tool->get_slug() . ' capability flags must be an array.' );
			$this->assertNotEmpty( $flags, $tool->get_slug() . ' must have at least one capability flag.' );
		}
	}

	/**
	 * Test Strudel reference tool returns mini-notation reference.
	 */
	public function test_strudel_reference_mini_notation() {
		$tool   = new NV_oOS_Algorave_Tool_Strudel_Reference();
		$result = $tool->execute( array( 'topic' => 'mini_notation' ) );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'mini_notation', $result['topic'] );
		$this->assertArrayHasKey( 'mini_notation', $result['reference'] );
		$this->assertArrayHasKey( 'syntax', $result['reference']['mini_notation'] );
		$this->assertNotEmpty( $result['reference']['mini_notation']['syntax'] );
	}

	/**
	 * Test Strudel reference tool returns effects reference.
	 */
	public function test_strudel_reference_effects() {
		$tool   = new NV_oOS_Algorave_Tool_Strudel_Reference();
		$result = $tool->execute( array( 'topic' => 'effects' ) );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'effects', $result['reference'] );
		$this->assertArrayHasKey( 'effects', $result['reference']['effects'] );
	}

	/**
	 * Test Strudel reference tool returns all topics by default.
	 */
	public function test_strudel_reference_all() {
		$tool   = new NV_oOS_Algorave_Tool_Strudel_Reference();
		$result = $tool->execute( array() );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'all', $result['topic'] );
		$this->assertArrayHasKey( 'mini_notation', $result['reference'] );
		$this->assertArrayHasKey( 'effects', $result['reference'] );
		$this->assertArrayHasKey( 'transformations', $result['reference'] );
		$this->assertArrayHasKey( 'sample_banks', $result['reference'] );
		$this->assertArrayHasKey( 'synthesizers', $result['reference'] );
		$this->assertArrayHasKey( 'tempo', $result['reference'] );
		$this->assertArrayHasKey( 'midi', $result['reference'] );
	}

	/**
	 * Test MIDI output tool help action.
	 */
	public function test_midi_output_help() {
		$tool   = new NV_oOS_Algorave_Tool_MIDI_Output();
		$result = $tool->execute( array( 'action' => 'help' ) );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'help', $result['action'] );
		$this->assertArrayHasKey( 'reference', $result );
		$this->assertArrayHasKey( 'functions', $result['reference'] );
	}

	/**
	 * Test MIDI output tool code generation.
	 */
	public function test_midi_output_generate_code() {
		$tool   = new NV_oOS_Algorave_Tool_MIDI_Output();
		$result = $tool->execute(
			array(
				'action'      => 'generate_code',
				'device_name' => 'IAC Driver',
				'channel'     => 1,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'generate_code', $result['action'] );
		$this->assertArrayHasKey( 'code', $result );
		$this->assertStringContainsString( 'IAC Driver', $result['code'] );
		$this->assertStringContainsString( '.midi(', $result['code'] );
	}

	/**
	 * Test play control set_cps action.
	 */
	public function test_play_control_set_cps() {
		$tool   = new NV_oOS_Algorave_Tool_Play_Control();
		$result = $tool->execute(
			array(
				'action' => 'set_cps',
				'cps'    => 0.5,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 0.5, $result['cps'] );
		$this->assertEquals( 120, $result['bpm'] );
	}

	/**
	 * Test play control set_bank action.
	 */
	public function test_play_control_set_bank() {
		$tool   = new NV_oOS_Algorave_Tool_Play_Control();
		$result = $tool->execute(
			array(
				'action' => 'set_bank',
				'bank'   => 'RolandTR909',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'RolandTR909', $result['bank'] );
	}

	/**
	 * Test generate pattern with techno genre creates richer scaffold.
	 */
	public function test_generate_pattern_techno_scaffold() {
		$tool   = new NV_oOS_Algorave_Tool_Generate_Pattern();
		$result = $tool->execute(
			array(
				'description' => 'A driving techno beat',
				'engine'      => 'strudel',
				'bpm'         => 140,
				'genre'       => 'techno',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'Techno', $result['code'] );
		$this->assertStringContainsString( '.bank(', $result['code'] );
		$this->assertStringContainsString( '.room(', $result['code'] );
	}
}
