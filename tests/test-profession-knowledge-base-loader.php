<?php
/**
 * Test Profession Knowledge Base Loader.
 *
 * Tests for the profession knowledge base loader service.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Profession_Knowledge_Base_Loader.
 */
class Test_Profession_Knowledge_Base_Loader extends WP_UnitTestCase {
	/**
	 * Test that knowledge base loader can be instantiated.
	 */
	public function test_loader_instantiation() {
		$loader = new WP_MCP_AI_Profession_Knowledge_Base_Loader();
		$this->assertInstanceOf( WP_MCP_AI_Profession_Knowledge_Base_Loader::class, $loader );
	}

	/**
	 * Test that JSON files exist in the knowledge base directory.
	 */
	public function test_json_files_exist() {
		$knowledge_base_path = WP_MCP_AI_PATH . 'includes/knowledge-base/professions/';
		$this->assertDirectoryExists( $knowledge_base_path, 'Knowledge base directory should exist' );

		$expected_files = array(
			'healthcare-medicine.json',
			'education.json',
			'science-engineering.json',
			'business-finance.json',
			'law-public-safety.json',
			'art-media-entertainment.json',
			'trades-manual-labor.json',
			'technology.json',
			'service-industry.json',
			'transportation.json',
			'agriculture-natural-resources.json',
			'miscellaneous-professions.json',
		);

		foreach ( $expected_files as $file ) {
			$file_path = $knowledge_base_path . $file;
			$this->assertFileExists( $file_path, "JSON file $file should exist" );
		}
	}

	/**
	 * Test that loader can get list of categories.
	 */
	public function test_get_categories() {
		$loader     = new WP_MCP_AI_Profession_Knowledge_Base_Loader();
		$categories = $loader->get_categories();

		$this->assertIsArray( $categories, 'Categories should be an array' );
		$this->assertNotEmpty( $categories, 'Categories should not be empty' );
		$this->assertCount( 12, $categories, 'Should have 12 categories' );
		$this->assertContains( 'healthcare-medicine', $categories );
		$this->assertContains( 'technology', $categories );
	}

	/**
	 * Test that loader can load a single category file.
	 */
	public function test_load_single_category() {
		$loader      = new WP_MCP_AI_Profession_Knowledge_Base_Loader();
		$professions = $loader->load_category( 'technology' );

		$this->assertIsArray( $professions, 'Loaded professions should be an array' );
		$this->assertNotEmpty( $professions, 'Technology category should have professions' );

		// Check first profession structure.
		$first_profession = $professions[0];
		$this->assertArrayHasKey( 'title', $first_profession );
		$this->assertArrayHasKey( 'slug', $first_profession );
		$this->assertArrayHasKey( 'category', $first_profession );
		$this->assertArrayHasKey( 'description', $first_profession );
		$this->assertArrayHasKey( 'role_description', $first_profession );
		$this->assertArrayHasKey( 'expertise', $first_profession );
		$this->assertArrayHasKey( 'warnings', $first_profession );
		$this->assertArrayHasKey( 'knowledge_base', $first_profession );
		$this->assertArrayHasKey( 'default_tools', $first_profession );
		$this->assertArrayHasKey( 'supported_mime_types', $first_profession );
	}

	/**
	 * Test that loader can load all professions.
	 */
	public function test_load_all_professions() {
		$loader      = new WP_MCP_AI_Profession_Knowledge_Base_Loader();
		$professions = $loader->load_all();

		$this->assertIsArray( $professions, 'Loaded professions should be an array' );
		$this->assertNotEmpty( $professions, 'Should have loaded professions' );
		$this->assertGreaterThan( 100, count( $professions ), 'Should have over 100 professions' );
	}

	/**
	 * Test that loaded professions are properly sanitized.
	 */
	public function test_profession_sanitization() {
		$loader      = new WP_MCP_AI_Profession_Knowledge_Base_Loader();
		$professions = $loader->load_category( 'healthcare-medicine' );

		foreach ( $professions as $profession ) {
			// Title should be sanitized text.
			$this->assertIsString( $profession['title'] );
			$this->assertNotEmpty( $profession['title'] );

			// Slug should be sanitized.
			$this->assertIsString( $profession['slug'] );
			$this->assertEquals( sanitize_title( $profession['slug'] ), $profession['slug'] );

			// Category should be sanitized key.
			$this->assertIsString( $profession['category'] );
			$this->assertEquals( sanitize_key( $profession['category'] ), $profession['category'] );

			// Arrays should be arrays.
			$this->assertIsArray( $profession['expertise'] );
			$this->assertIsArray( $profession['warnings'] );
			$this->assertIsArray( $profession['default_tools'] );
			$this->assertIsArray( $profession['supported_mime_types'], 'supported_mime_types should be an array' );
			$this->assertNotEmpty( $profession['supported_mime_types'], 'supported_mime_types should not be empty' );
		}
	}

	/**
	 * Test that invalid file returns WP_Error.
	 */
	public function test_invalid_file_returns_error() {
		$loader = new WP_MCP_AI_Profession_Knowledge_Base_Loader();
		$result = $loader->load_from_file( '/nonexistent/file.json' );

		$this->assertWPError( $result, 'Should return WP_Error for non-existent file' );
	}

	/**
	 * Test that all JSON files are valid JSON.
	 */
	public function test_json_files_are_valid() {
		$knowledge_base_path = WP_MCP_AI_PATH . 'includes/knowledge-base/professions/';
		$json_files          = glob( $knowledge_base_path . '*.json' );

		foreach ( $json_files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$content = file_get_contents( $file );
			$data    = json_decode( $content, true );

			$this->assertNotNull( $data, basename( $file ) . ' should contain valid JSON' );
			$this->assertArrayHasKey( 'category', $data, basename( $file ) . ' should have category field' );
			$this->assertArrayHasKey( 'professions', $data, basename( $file ) . ' should have professions array' );
			$this->assertIsArray( $data['professions'], basename( $file ) . ' professions should be an array' );
		}
	}

	/**
	 * Test that supported MIME types are correctly assigned based on category.
	 */
	public function test_mime_types_assigned_by_category() {
		$loader      = new WP_MCP_AI_Profession_Knowledge_Base_Loader();
		$professions = $loader->load_all();

		foreach ( $professions as $profession ) {
			$category           = $profession['category'];
			$supported_mimes    = $profession['supported_mime_types'];

			// All professions should have supported_mime_types.
			$this->assertIsArray( $supported_mimes, "Profession {$profession['slug']} should have supported_mime_types array" );
			$this->assertNotEmpty( $supported_mimes, "Profession {$profession['slug']} should have at least one MIME type" );

			// All professions should support text/plain.
			$this->assertContains( 'text/plain', $supported_mimes, "All professions should support text/plain" );

			// Check category-specific MIME types.
			switch ( $category ) {
				case 'creative':
					$this->assertContains( 'image/jpeg', $supported_mimes, "Creative professions should support JPEG images" );
					$this->assertContains( 'image/png', $supported_mimes, "Creative professions should support PNG images" );
					break;
				case 'financial':
				case 'legal':
				case 'advisory':
					$this->assertContains( 'application/pdf', $supported_mimes, "Financial/legal/advisory professions should support PDF" );
					$this->assertContains( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $supported_mimes, "Financial/legal/advisory professions should support DOCX" );
					break;
				case 'technical':
					$this->assertContains( 'application/pdf', $supported_mimes, "Technical professions should support PDF" );
					$this->assertContains( 'text/csv', $supported_mimes, "Technical professions should support CSV" );
					break;
				case 'healthcare':
					$this->assertContains( 'application/pdf', $supported_mimes, "Healthcare professions should support PDF" );
					$this->assertContains( 'image/jpeg', $supported_mimes, "Healthcare professions should support JPEG images" );
					break;
			}
		}
	}

	/**
	 * Test that seeder can use the loader.
	 */
	public function test_seeder_integration() {
		// Clear the seeded option to allow re-seeding.
		delete_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION );

		// Create a repository instance.
		$repository = new WP_MCP_AI_Profession_Repository();

		// Load professions using the loader (same as seeder does).
		$loader      = new WP_MCP_AI_Profession_Knowledge_Base_Loader();
		$professions = $loader->load_all();

		$this->assertIsArray( $professions );
		$this->assertNotEmpty( $professions );

		// Test saving one profession.
		$test_profession = $professions[0];
		$result          = $repository->save( $test_profession );

		$this->assertNotWPError( $result, 'Saving profession should not return error' );
		$this->assertIsInt( $result, 'Saving profession should return post ID' );

		// Clean up.
		wp_delete_post( $result, true );
	}

	/**
	 * Test that professions get enhanced default tools.
	 *
	 * Tests that the loader enhances basic 3-tool professions with
	 * recommended tools from the profession tool recommender.
	 */
	public function test_enhanced_default_tools() {
		$loader      = new WP_MCP_AI_Profession_Knowledge_Base_Loader();
		$professions = $loader->load_category( 'technology' );

		$this->assertIsArray( $professions, 'Loaded professions should be an array' );
		$this->assertNotEmpty( $professions, 'Technology category should have professions' );

		// Find a software developer or similar profession.
		$test_profession = null;
		foreach ( $professions as $profession ) {
			if ( 'software_developer' === $profession['slug'] || 'web_developer' === $profession['slug'] ) {
				$test_profession = $profession;
				break;
			}
		}

		$this->assertNotNull( $test_profession, 'Should find a test profession' );
		$this->assertArrayHasKey( 'default_tools', $test_profession );
		$this->assertIsArray( $test_profession['default_tools'] );

		// Should have more than the basic 3 tools after enhancement.
		$tool_count = count( $test_profession['default_tools'] );
		$this->assertGreaterThan(
			3,
			$tool_count,
			"Default tools should be enhanced to more than 3 tools (found {$tool_count})"
		);

		// Should have the core tools at minimum.
		$core_tools = array( 'web_search', 'search_content', 'save_post' );
		foreach ( $core_tools as $core_tool ) {
			$this->assertContains(
				$core_tool,
				$test_profession['default_tools'],
				"Should contain core tool: {$core_tool}"
			);
		}

		// Verify tools are valid (no empty strings).
		foreach ( $test_profession['default_tools'] as $tool ) {
			$this->assertNotEmpty( $tool, 'Tool slug should not be empty' );
			$this->assertIsString( $tool, 'Tool slug should be a string' );
		}
	}

	/**
	 * Test that custom tool lists from JSON are preserved.
	 *
	 * If a JSON file already has more than 3 tools, they should be preserved
	 * and not overridden by the recommender.
	 */
	public function test_custom_tools_preserved() {
		// Create a test profession with more than 3 custom tools.
		$custom_tools = array(
			'web_search',
			'search_content',
			'save_post',
			'custom_tool_1',
			'custom_tool_2',
		);

		// Mock the validate_profession method's behavior.
		$loader = new WP_MCP_AI_Profession_Knowledge_Base_Loader();
		$result = $this->call_protected_method(
			$loader,
			'enhance_default_tools',
			array( $custom_tools, 'test_profession', 'technical' )
		);

		// Should return the custom tools since count > 3.
		$this->assertEquals( $custom_tools, $result, 'Custom tools should be preserved when count > 3' );
	}

	/**
	 * Test tool enhancement with empty JSON tools.
	 *
	 * If JSON has no tools, should get full recommended set.
	 */
	public function test_empty_tools_get_recommendations() {
		$loader = new WP_MCP_AI_Profession_Knowledge_Base_Loader();
		$result = $this->call_protected_method(
			$loader,
			'enhance_default_tools',
			array( array(), 'software_developer', 'technical' )
		);

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertNotEmpty( $result, 'Should have recommended tools' );
		$this->assertGreaterThan( 3, count( $result ), 'Should have more than 3 tools' );
	}

	/**
	 * Helper method to call protected methods for testing.
	 *
	 * @param object $object     Object instance.
	 * @param string $method     Method name.
	 * @param array  $parameters Method parameters.
	 * @return mixed Method result.
	 */
	protected function call_protected_method( $object, $method, array $parameters = array() ) {
		$reflection = new ReflectionClass( get_class( $object ) );
		$method     = $reflection->getMethod( $method );
		$method->setAccessible( true );

		return $method->invokeArgs( $object, $parameters );
	}
}
