<?php
/**
 * Test suite for the enhance_media_prompt tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for WP_MCP_AI_Tool_Enhance_Media_Prompt.
 */
class Test_Tool_Enhance_Media_Prompt extends WP_UnitTestCase {

	/**
	 * Tool under test.
	 *
	 * @var WP_MCP_AI_Tool_Enhance_Media_Prompt
	 */
	protected $tool;

	/**
	 * Capable user id.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Load the tool.
	 */
	public function set_up() {
		parent::set_up();

		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-prompt-craft.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-enhance-media-prompt.php';

		$this->tool    = new WP_MCP_AI_Tool_Enhance_Media_Prompt();
		$this->user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );
	}

	/**
	 * Tool identity.
	 */
	public function test_slug_and_name() {
		$this->assertSame( 'enhance_media_prompt', $this->tool->get_slug() );
		$this->assertNotSame( '', $this->tool->get_name() );
		$this->assertNotSame( '', $this->tool->get_description() );
	}

	/**
	 * The schema enums come from the prompt-craft vocabulary, so they cannot drift.
	 */
	public function test_schema_enums_match_vocabulary() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertSame( WP_MCP_AI_Prompt_Craft::get_styles(), $schema['properties']['style']['enum'] );
		$this->assertSame( WP_MCP_AI_Prompt_Craft::get_cameras(), $schema['properties']['camera']['enum'] );
		$this->assertSame( WP_MCP_AI_Prompt_Craft::get_lenses(), $schema['properties']['lens']['enum'] );
		$this->assertSame( WP_MCP_AI_Prompt_Craft::get_focal_lengths(), $schema['properties']['focal_length']['enum'] );
		$this->assertSame( WP_MCP_AI_Prompt_Craft::get_apertures(), $schema['properties']['aperture']['enum'] );

		$this->assertSame( array( 'prompt' ), $schema['required'] );
	}

	/**
	 * An empty prompt is a hard error.
	 */
	public function test_empty_prompt_is_error() {
		$result = $this->tool->execute( array( 'prompt' => '   ' ), array( 'user_id' => $this->user_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_prompt', $result->get_error_code() );
	}

	/**
	 * Photographic options are composed and reported.
	 */
	public function test_composes_photographic_options() {
		$result = $this->tool->execute(
			array(
				'prompt'       => 'A woman in a linen suit',
				'style'        => 'cinematic',
				'camera'       => 'grand-format-70mm-film',
				'lens'         => 'classic-anamorphic',
				'focal_length' => 35,
				'aperture'     => '1.4',
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		$prompt = $result['data']['enhanced_prompt'];

		$this->assertStringContainsString( 'A woman in a linen suit', $prompt );
		$this->assertStringContainsString( 'cinematic lighting', $prompt );
		$this->assertStringContainsString( 'shot on a grand format 70mm film camera', $prompt );
		$this->assertStringContainsString( '35mm (natural cinematic perspective)', $prompt );
		$this->assertStringContainsString( 'aperture f/1.4', $prompt );

		$this->assertNotEmpty( $result['data']['applied'] );
		$this->assertTrue( $result['data']['deterministic'] );
	}

	/**
	 * Quality tags are on by default and can be turned off.
	 */
	public function test_quality_tags_default_on_and_toggleable() {
		$on = $this->tool->execute(
			array( 'prompt' => 'A cat' ),
			array( 'user_id' => $this->user_id )
		);
		$this->assertStringContainsString( 'professional photography', $on['data']['enhanced_prompt'] );

		$off = $this->tool->execute(
			array(
				'prompt'               => 'A cat',
				'include_quality_tags' => false,
			),
			array( 'user_id' => $this->user_id )
		);
		$this->assertStringNotContainsString( 'professional photography', $off['data']['enhanced_prompt'] );
	}

	/**
	 * Identity instructions are returned separately and describe every slot.
	 */
	public function test_identity_lock_is_returned_separately() {
		$result = $this->tool->execute(
			array(
				'prompt'     => 'Two people walking through a market',
				'characters' => array(
					array(
						'name'  => 'Sarah',
						'count' => 2,
					),
				),
				'items'      => array(
					array(
						'name'  => 'the canvas tote',
						'count' => 1,
					),
				),
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertSame( 3, $result['data']['reference_slot_count'] );
		$this->assertStringContainsString( 'Reference images 1-2 show Sarah;', $result['data']['reference_instructions'] );
		$this->assertStringContainsString( 'Reference image 3 shows the canvas tote;', $result['data']['reference_instructions'] );

		// The composed prompt contains the lock as well.
		$this->assertStringContainsString( 'Reference images 1-2 show Sarah;', $result['data']['enhanced_prompt'] );
	}

	/**
	 * The identity lock can be suppressed while keeping the rest.
	 */
	public function test_identity_lock_can_be_disabled() {
		$result = $this->tool->execute(
			array(
				'prompt'                => 'A portrait',
				'include_identity_lock' => false,
				'characters'            => array( array( 'count' => 2 ) ),
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertSame( '', $result['data']['reference_instructions'] );
		$this->assertSame( 0, $result['data']['reference_slot_count'] );
		$this->assertStringNotContainsString( 'Reference image', $result['data']['enhanced_prompt'] );
	}

	/**
	 * Zero-count subjects are ignored.
	 */
	public function test_zero_count_subjects_are_ignored() {
		$result = $this->tool->execute(
			array(
				'prompt'     => 'A portrait',
				'characters' => array(
					array(
						'name'  => 'Ghost',
						'count' => 0,
					),
				),
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertSame( '', $result['data']['reference_instructions'] );
		$this->assertSame( 0, $result['data']['reference_slot_count'] );
	}

	/**
	 * Extra tags are appended and deduplicated.
	 */
	public function test_extra_tags() {
		$result = $this->tool->execute(
			array(
				'prompt'     => 'A cat',
				'extra_tags' => array( 'neon glow', 'neon glow', '' ),
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertSame( 1, substr_count( $result['data']['enhanced_prompt'], 'neon glow' ) );
	}

	/**
	 * Unknown options are ignored rather than echoed into the prompt.
	 */
	public function test_unknown_options_are_ignored() {
		$result = $this->tool->execute(
			array(
				'prompt' => 'A cat',
				'camera' => 'not-a-camera',
				'style'  => 'not-a-style',
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertStringNotContainsString( 'not-a-camera', $result['data']['enhanced_prompt'] );
		$this->assertStringNotContainsString( 'not-a-style', $result['data']['enhanced_prompt'] );
	}

	/**
	 * Identical inputs produce identical output.
	 */
	public function test_deterministic_output() {
		$args = array(
			'prompt'   => 'A serum bottle on marble',
			'style'    => 'product',
			'camera'   => 'studio-digital-s35',
			'aperture' => '4',
		);

		$first  = $this->tool->execute( $args, array( 'user_id' => $this->user_id ) );
		$second = $this->tool->execute( $args, array( 'user_id' => $this->user_id ) );

		$this->assertSame( $first['data']['enhanced_prompt'], $second['data']['enhanced_prompt'] );
	}

	/**
	 * Capability flags: text only, no provider dependency.
	 */
	public function test_capability_flags() {
		$flags = $this->tool->get_capability_flags();

		$this->assertContains( 'read-only', $flags );
		$this->assertNotContains( 'external-api', $flags );
		$this->assertNotContains( 'requires-credentials', $flags );
		$this->assertNotContains( 'consumes-tokens', $flags );
	}
}
