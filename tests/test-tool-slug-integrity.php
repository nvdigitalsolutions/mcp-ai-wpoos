<?php
/**
 * Tests to verify that all tools are registered with the correct slugs.
 *
 * @package WP_MCP_AI
 */

/**
 * @group tool-registry
 * @group tool-integrity
 */
class WP_MCP_AI_Tool_Slug_Integrity_Tests extends WP_UnitTestCase {

	/**
	 * Test that all tools return slugs that match their class names.
	 */
	public function test_all_tools_have_correct_slugs() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tools = $registry->get_tools();

		$this->assertNotEmpty( $tools, 'Registry should have tools registered' );

		$mismatches = array();

		foreach ( $tools as $tool ) {
			if ( ! $tool instanceof WP_MCP_AI_Tool_Interface ) {
				continue;
			}

			$class_name = get_class( $tool );
			$slug       = $tool->get_slug();

			// Convert class name to expected slug.
			// WP_MCP_AI_Tool_Send_Group_Email -> send_group_email.
			$expected_slug = $this->class_name_to_slug( $class_name );

			if ( $slug !== $expected_slug ) {
				$mismatches[] = array(
					'class'    => $class_name,
					'expected' => $expected_slug,
					'actual'   => $slug,
				);
			}
		}

		if ( ! empty( $mismatches ) ) {
			$error_message = "Tool slug mismatches found:\n";
			foreach ( $mismatches as $mismatch ) {
				$error_message .= sprintf(
					"  - Class: %s\n    Expected slug: %s\n    Actual slug: %s\n",
					$mismatch['class'],
					$mismatch['expected'],
					$mismatch['actual']
				);
			}
			$this->fail( $error_message );
		}
	}

	/**
	 * Convert a tool class name to its expected slug.
	 *
	 * @param string $class_name Full class name (e.g., 'WP_MCP_AI_Tool_Send_Group_Email').
	 * @return string Expected slug (e.g., 'send_group_email').
	 */
	protected function class_name_to_slug( $class_name ) {
		$slug = str_replace( 'WP_MCP_AI_Tool_', '', $class_name );
		$slug = strtolower( preg_replace( '/(?<!^)[A-Z]/', '_$0', $slug ) );
		$slug = str_replace( '__', '_', $slug );
		return $slug;
	}

	/**
	 * Test that send_group_email tool exists and returns correct slug.
	 */
	public function test_send_group_email_tool_exists() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'send_group_email' );

		$this->assertNotNull( $tool, 'send_group_email tool should be registered' );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
		$this->assertEquals( 'send_group_email', $tool->get_slug() );
		$this->assertEquals( 'WP_MCP_AI_Tool_Send_Group_Email', get_class( $tool ) );
	}

	/**
	 * Test that get_open_meteo_forecast tool exists and returns correct slug.
	 */
	public function test_get_open_meteo_forecast_tool_exists() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'get_open_meteo_forecast' );

		$this->assertNotNull( $tool, 'get_open_meteo_forecast tool should be registered' );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
		$this->assertEquals( 'get_open_meteo_forecast', $tool->get_slug() );
		$this->assertEquals( 'WP_MCP_AI_Tool_Get_Open_Meteo_Forecast', get_class( $tool ) );
	}

	/**
	 * Test that retrieving tools by slug returns the correct class.
	 */
	public function test_tool_retrieval_by_slug_is_correct() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$test_cases = array(
			'send_group_email'        => 'WP_MCP_AI_Tool_Send_Group_Email',
			'get_open_meteo_forecast' => 'WP_MCP_AI_Tool_Get_Open_Meteo_Forecast',
			'search_content'          => 'WP_MCP_AI_Tool_Search_Content',
			'save_post'               => 'WP_MCP_AI_Tool_Save_Post',
			'web_search'              => 'WP_MCP_AI_Tool_Web_Search',
		);

		foreach ( $test_cases as $slug => $expected_class ) {
			$tool = $registry->get_tool( $slug );

			if ( $tool ) {
				$this->assertEquals(
					$expected_class,
					get_class( $tool ),
					"Tool slug '$slug' should return class '$expected_class'"
				);
			}
		}
	}

	/**
	 * Test that no two tools share the same slug.
	 */
	public function test_no_duplicate_tool_slugs() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tools = $registry->get_tools();
		$slugs = array();

		foreach ( $tools as $tool ) {
			if ( ! $tool instanceof WP_MCP_AI_Tool_Interface ) {
				continue;
			}

			$slug = $tool->get_slug();

			if ( isset( $slugs[ $slug ] ) ) {
				$this->fail(
					sprintf(
						"Duplicate tool slug found: '%s' is used by both %s and %s",
						$slug,
						get_class( $slugs[ $slug ] ),
						get_class( $tool )
					)
				);
			}

			$slugs[ $slug ] = $tool;
		}

		$this->assertGreaterThan( 0, count( $slugs ), 'Should have registered tools with slugs' );
	}
}
