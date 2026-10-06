<?php
/**
 * Tests for the research-tools content normalization trait.
 *
 * Providers such as Gemini (and some OpenAI-compatible gateways) return
 * chat message content as an array of parts/blocks. The research tools
 * feed that content to string functions (preg_match, json_decode), which
 * fatal on array input. These tests pin the shared
 * WP_MCP_AI_Tool_Research_Content_Normalization trait and the per-tool
 * guards that flatten array content before parsing.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test research tools content normalization.
 */
class Test_Research_Tools_Content_Normalization extends WP_UnitTestCase {

	/**
	 * Build a research result envelope with Gemini-style array content.
	 *
	 * @param string $json JSON payload to wrap in a fenced part.
	 * @return array Research result envelope.
	 */
	private function envelope_for_json( $json ) {
		return array(
			'content'  => array(
				array(
					'type' => 'text',
					'text' => '```json' . "\n" . $json . "\n" . '```',
				),
			),
			'provider' => 'gemini',
			'model'    => 'gemini-2.5-flash',
		);
	}

	/**
	 * Ensure a tool class is loaded, skipping the test when unavailable.
	 *
	 * @param string $class_name Class name.
	 */
	private function assert_tool_class( $class_name ) {
		if ( ! class_exists( $class_name ) ) {
			$this->markTestSkipped( $class_name . ' class not loaded.' );
		}
	}

	/**
	 * Invoke a protected method on a tool instance.
	 *
	 * @param object $tool   Tool instance.
	 * @param string $method Method name.
	 * @param array  $args   Method arguments.
	 * @return mixed Method result.
	 */
	private function invoke( $tool, $method, array $args ) {
		$reflection = new ReflectionMethod( $tool, $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( $tool, $args );
	}

	/**
	 * Test research_post parses Gemini-style array content.
	 */
	public function test_research_post_parses_array_content() {
		$this->assert_tool_class( 'WP_MCP_AI_Tool_Research_Post' );
		$tool = new WP_MCP_AI_Tool_Research_Post();

		$result = $this->invoke(
			$tool,
			'parse_research_results',
			array(
				$this->envelope_for_json(
					wp_json_encode(
						array(
							'title'   => 'Test Post',
							'content' => '<h2>Section</h2><p>Body text.</p>',
						)
					)
				),
				'Test topic',
				'block-editor',
				'',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'Test Post', $result['title'] );
		$this->assertStringContainsString( '<h2>Section</h2>', $result['content'] );
	}

	/**
	 * Test research_post flattens array content inside the JSON body.
	 */
	public function test_research_post_flattens_array_post_content() {
		$this->assert_tool_class( 'WP_MCP_AI_Tool_Research_Post' );
		$tool = new WP_MCP_AI_Tool_Research_Post();

		$result = $this->invoke(
			$tool,
			'parse_research_results',
			array(
				array(
					'content'  => wp_json_encode(
						array(
							'title'   => 'Test Post',
							'content' => array( '<h2>Section</h2>', '<p>Body text.</p>' ),
						)
					),
					'provider' => 'openai',
					'model'    => 'gpt-4.1',
				),
				'Test topic',
				'block-editor',
				'',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'Test Post', $result['title'] );
		$this->assertStringContainsString( '<h2>Section</h2>', $result['content'] );
		$this->assertStringContainsString( '<p>Body text.</p>', $result['content'] );
	}

	/**
	 * Test research_page parses Gemini-style array content.
	 */
	public function test_research_page_parses_array_content() {
		$this->assert_tool_class( 'WP_MCP_AI_Tool_Research_Page' );
		$tool = new WP_MCP_AI_Tool_Research_Page();

		$result = $this->invoke(
			$tool,
			'parse_research_results',
			array(
				$this->envelope_for_json(
					wp_json_encode(
						array(
							'title'   => 'Test Page',
							'content' => '<h2>Section</h2><p>Body text.</p>',
						)
					)
				),
				'Test topic',
				'about',
				'block-editor',
				'',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'Test Page', $result['title'] );
		$this->assertStringContainsString( '<h2>Section</h2>', $result['content'] );
	}

	/**
	 * Test research_policy parses Gemini-style array content.
	 */
	public function test_research_policy_parses_array_content() {
		$this->assert_tool_class( 'WP_MCP_AI_Tool_Research_Policy' );
		$tool = new WP_MCP_AI_Tool_Research_Policy();

		$result = $this->invoke(
			$tool,
			'parse_research_results',
			array(
				$this->envelope_for_json(
					wp_json_encode(
						array(
							'policy_name' => 'Travel Insurance',
							'description' => '<p>Covers trips.</p>',
						)
					)
				),
				'Travel insurance',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'Travel Insurance', $result['policy_name'] );
		$this->assertStringContainsString( 'Covers trips.', $result['description'] );
	}

	/**
	 * Test research_product parses Gemini-style array content.
	 */
	public function test_research_product_parses_array_content() {
		$this->assert_tool_class( 'WP_MCP_AI_Tool_Research_Product' );
		$tool = new WP_MCP_AI_Tool_Research_Product();

		$result = $this->invoke(
			$tool,
			'parse_research_results',
			array(
				$this->envelope_for_json(
					wp_json_encode(
						array(
							'title'       => 'Test Widget',
							'description' => '<p>A great widget.</p>',
						)
					)
				),
				'Test widget',
				'REF-1',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'Test Widget', $result['product_data']['title'] );
		$this->assertStringContainsString( 'A great widget.', $result['product_data']['description'] );
	}

	/**
	 * Test research_project parses Gemini-style array content.
	 */
	public function test_research_project_parses_array_content() {
		$this->assert_tool_class( 'WP_MCP_AI_Tool_Research_Project' );
		$tool = new WP_MCP_AI_Tool_Research_Project();

		$result = $this->invoke(
			$tool,
			'parse_research_results',
			array(
				$this->envelope_for_json(
					wp_json_encode(
						array(
							'title'       => 'Test Project',
							'description' => 'Project description text.',
						)
					)
				),
				'Test project',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'Test Project', $result['title'] );
		$this->assertSame( 'Project description text.', $result['description'] );
	}

	/**
	 * Test research_project parses plain (unfenced) JSON content.
	 */
	public function test_research_project_parses_plain_json_content() {
		$this->assert_tool_class( 'WP_MCP_AI_Tool_Research_Project' );
		$tool = new WP_MCP_AI_Tool_Research_Project();

		$result = $this->invoke(
			$tool,
			'parse_research_results',
			array(
				array(
					'content'  => wp_json_encode(
						array(
							'title'       => 'Test Project',
							'description' => 'Project description text.',
						)
					),
					'provider' => 'openai',
					'model'    => 'gpt-4.1',
				),
				'Test project',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'Test Project', $result['title'] );
		$this->assertSame( 'Project description text.', $result['description'] );
	}
}
