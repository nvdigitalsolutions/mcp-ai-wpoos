<?php
/**
 * Test suite for the media_model_capabilities tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for WP_MCP_AI_Tool_Media_Model_Capabilities.
 */
class Test_Tool_Media_Model_Capabilities extends WP_UnitTestCase {

	/**
	 * Tool under test.
	 *
	 * @var WP_MCP_AI_Tool_Media_Model_Capabilities
	 */
	protected $tool;

	/**
	 * Capable user id.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Fresh catalog cache and tool instance per test.
	 */
	public function set_up() {
		parent::set_up();

		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-model-catalog.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-media-model-capabilities.php';

		WP_MCP_AI_Media_Model_Catalog::reset_cache();

		$this->tool    = new WP_MCP_AI_Tool_Media_Model_Capabilities();
		$this->user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );
	}

	/**
	 * Reset cache.
	 */
	public function tear_down() {
		WP_MCP_AI_Media_Model_Catalog::reset_cache();
		parent::tear_down();
	}

	/**
	 * Tool identity.
	 */
	public function test_slug_and_name() {
		$this->assertSame( 'media_model_capabilities', $this->tool->get_slug() );
		$this->assertNotSame( '', $this->tool->get_name() );
		$this->assertNotSame( '', $this->tool->get_description() );
	}

	/**
	 * The schema advertises every action and the required field.
	 */
	public function test_parameters_schema() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertArrayHasKey( 'action', $schema['properties'] );
		$this->assertSame( array( 'list', 'describe', 'recommend', 'validate' ), $schema['properties']['action']['enum'] );
		$this->assertSame( array( 'action' ), $schema['required'] );
		$this->assertFalse( $schema['additionalProperties'] );

		// The style enum is derived from the prompt-craft vocabulary.
		$this->assertContains( 'cinematic', $schema['properties']['style']['enum'] );
		$this->assertContains( 'any', $schema['properties']['style']['enum'] );
	}

	/**
	 * Lists compact rows plus registry metadata.
	 */
	public function test_list_action() {
		$result = $this->tool->execute( array( 'action' => 'list' ), array( 'user_id' => $this->user_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'list', $result['data']['action'] );
		$this->assertNotEmpty( $result['data']['models'] );
		$this->assertArrayHasKey( 'version', $result['data']['catalog'] );

		$row = $result['data']['models'][0];
		foreach ( array( 'id', 'label', 'provider', 'kind', 'tool', 'tier', 'styles', 'resolutions' ) as $key ) {
			$this->assertArrayHasKey( $key, $row );
		}
	}

	/**
	 * List honours the kind filter and the limit.
	 */
	public function test_list_filters_and_limit() {
		$result = $this->tool->execute(
			array(
				'action' => 'list',
				'kind'   => 'image',
			),
			array( 'user_id' => $this->user_id )
		);

		foreach ( $result['data']['models'] as $row ) {
			$this->assertSame( 'image', $row['kind'] );
		}

		$limited = $this->tool->execute(
			array(
				'action' => 'list',
				'limit'  => 2,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertCount( 2, $limited['data']['models'] );
		$this->assertTrue( $limited['data']['truncated'] );
	}

	/**
	 * Describe returns the full record.
	 */
	public function test_describe_action() {
		$result = $this->tool->execute(
			array(
				'action' => 'describe',
				'model'  => 'seedance-2.0',
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'seedance-2.0', $result['data']['model']['id'] );
		$this->assertArrayHasKey( 'duration', $result['data']['model'] );
	}

	/**
	 * Describe rejects missing and unknown models.
	 */
	public function test_describe_errors() {
		$missing = $this->tool->execute( array( 'action' => 'describe' ), array( 'user_id' => $this->user_id ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'wp_mcp_ai_media_model_missing', $missing->get_error_code() );

		$unknown = $this->tool->execute(
			array(
				'action' => 'describe',
				'model'  => 'nope',
			),
			array( 'user_id' => $this->user_id )
		);
		$this->assertWPError( $unknown );
		$this->assertSame( 'wp_mcp_ai_media_model_unknown', $unknown->get_error_code() );
	}

	/**
	 * Recommend returns ranked candidates with reasons.
	 */
	public function test_recommend_action() {
		$result = $this->tool->execute(
			array(
				'action'           => 'recommend',
				'kind'             => 'video',
				'style'            => 'realistic',
				'reference_images' => 12,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['data']['advisory'] );
		$this->assertNotEmpty( $result['data']['candidates'] );
		$this->assertSame( $result['data']['candidates'][0]['id'], $result['data']['best'] );

		foreach ( $result['data']['candidates'] as $candidate ) {
			$this->assertArrayHasKey( 'reasons', $candidate );
			$this->assertNotEmpty( $candidate['reasons'] );
		}
	}

	/**
	 * Recommend returns an empty set with an explanatory message when nothing fits.
	 */
	public function test_recommend_empty() {
		$result = $this->tool->execute(
			array(
				'action'   => 'recommend',
				'kind'     => 'video',
				'audio'    => 'required',
				'provider' => 'openai',
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['data']['count'] );
		$this->assertNull( $result['data']['best'] );
		$this->assertNotSame( '', $result['message'] );
	}

	/**
	 * Validate pre-flights an argument set and reports drops.
	 */
	public function test_validate_action_reports_drops() {
		$result = $this->tool->execute(
			array(
				'action'    => 'validate',
				'model'     => 'kling-3.0',
				'arguments' => array(
					'resolution' => '1080p',
					'duration'   => 10,
					'prompt'     => 'A cat',
				),
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['data']['clean'] );
		$this->assertArrayNotHasKey( 'resolution', $result['data']['params'] );
		$this->assertSame( 'resolution', $result['data']['dropped'][0]['field'] );
		$this->assertSame( 10, $result['data']['params']['duration'] );
	}

	/**
	 * Validate reports a clean pass.
	 */
	public function test_validate_action_clean() {
		$result = $this->tool->execute(
			array(
				'action'    => 'validate',
				'model'     => 'soul-2',
				'arguments' => array(
					'aspect_ratio' => '16:9',
					'resolution'   => '1080p',
					'batch_size'   => 4,
				),
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertTrue( $result['data']['clean'] );
		$this->assertEmpty( $result['data']['dropped'] );
		$this->assertEmpty( $result['data']['errors'] );
	}

	/**
	 * Validate requires a model id.
	 */
	public function test_validate_requires_model() {
		$result = $this->tool->execute(
			array(
				'action'    => 'validate',
				'arguments' => array( 'duration' => 5 ),
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_media_model_missing', $result->get_error_code() );
	}

	/**
	 * An unregistered action falls back to list rather than erroring.
	 */
	public function test_unknown_action_falls_back_to_list() {
		$result = $this->tool->execute( array( 'action' => 'explode' ), array( 'user_id' => $this->user_id ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'list', $result['data']['action'] );
	}

	/**
	 * Capability flags: offline and read-only, no credentials.
	 */
	public function test_capability_flags() {
		$flags = $this->tool->get_capability_flags();

		$this->assertContains( 'read-only', $flags );
		$this->assertNotContains( 'external-api', $flags );
		$this->assertNotContains( 'requires-credentials', $flags );
		$this->assertNotContains( 'consumes-tokens', $flags );
	}

	/**
	 * Usage guidance is complete.
	 */
	public function test_usage_guidance() {
		$guidance = $this->tool->get_usage_guidance();

		foreach ( array( 'when_to_use', 'when_not_to_use', 'related_tools', 'notes' ) as $key ) {
			$this->assertArrayHasKey( $key, $guidance );
		}
		$this->assertContains( 'enhance_media_prompt', $guidance['related_tools'] );
	}

	/**
	 * The declared capability is a real WordPress capability.
	 */
	public function test_required_capability() {
		$this->assertSame( 'edit_posts', $this->tool->get_required_capability() );
	}
}
