<?php
/**
 * Test image URL enrichment from messages.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that image URL enrichment handles incorrect URLs correctly.
 */
class Test_Image_URL_Enrichment extends WP_UnitTestCase {

	/**
	 * Test enrichment with exact URL match.
	 */
	public function test_enrichment_with_exact_url_match() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'vectorize_image' );

		$this->assertNotNull( $tool, 'Vectorize image tool should be registered' );

		// Use reflection to test the protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'enrich_arguments_from_messages' );
		$method->setAccessible( true );

		$correct_url = 'https://bots.nvdigital.solutions/wp-content/uploads/2026/01/Natural-Ingredients-3.png';

		$arguments = array(
			'image_url' => $correct_url,
		);

		$context = array(
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'          => 'input_image',
							'url'           => $correct_url,
							'attachment_id' => 123,
							'file_name'     => 'Natural-Ingredients-3.png',
							'mime_type'     => 'image/png',
							'bytes'         => 50000,
						),
					),
				),
			),
		);

		$enriched = $method->invoke( $tool, $arguments, $context );

		// Should find exact match and enrich with metadata.
		$this->assertEquals( 123, $enriched['attachment_id'], 'Should extract attachment_id from matching image' );
		$this->assertEquals( 'Natural-Ingredients-3.png', $enriched['file_name'], 'Should extract file_name' );
		$this->assertEquals( 'image/png', $enriched['source_mime_type'], 'Should extract mime_type' );
		$this->assertEquals( 50000, $enriched['bytes'], 'Should extract bytes' );
	}

	/**
	 * Test enrichment with incorrect URL (domain mismatch).
	 */
	public function test_enrichment_with_incorrect_url_domain_mismatch() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'vectorize_image' );

		$this->assertNotNull( $tool, 'Vectorize image tool should be registered' );

		// Use reflection to test the protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'enrich_arguments_from_messages' );
		$method->setAccessible( true );

		$correct_url   = 'https://bots.nvdigital.solutions/wp-content/uploads/2026/01/Natural-Ingredients-3.png';
		$incorrect_url = 'https://theparfumerie.lk/wp-content/uploads/2025/01/7.png';

		$arguments = array(
			'image_url' => $incorrect_url,
		);

		$context = array(
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'          => 'input_image',
							'url'           => $correct_url,
							'attachment_id' => 123,
							'file_name'     => 'Natural-Ingredients-3.png',
							'mime_type'     => 'image/png',
							'bytes'         => 50000,
						),
					),
				),
			),
		);

		$enriched = $method->invoke( $tool, $arguments, $context );

		// Should detect domain mismatch and use the correct URL from messages.
		$this->assertEquals( $correct_url, $enriched['image_url'], 'Should replace incorrect URL with correct one from messages' );
		$this->assertEquals( 123, $enriched['attachment_id'], 'Should extract attachment_id from fallback image' );
		$this->assertEquals( 'Natural-Ingredients-3.png', $enriched['file_name'], 'Should extract file_name from fallback' );
		$this->assertEquals( 'image/png', $enriched['source_mime_type'], 'Should extract mime_type from fallback' );
		$this->assertEquals( 50000, $enriched['bytes'], 'Should extract bytes from fallback' );
	}

	/**
	 * Test enrichment with multiple images - should use most recent.
	 */
	public function test_enrichment_with_multiple_images_uses_most_recent() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'vectorize_image' );

		$this->assertNotNull( $tool, 'Vectorize image tool should be registered' );

		// Use reflection to test the protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'enrich_arguments_from_messages' );
		$method->setAccessible( true );

		$older_url     = 'https://bots.nvdigital.solutions/wp-content/uploads/2026/01/old-image.png';
		$recent_url    = 'https://bots.nvdigital.solutions/wp-content/uploads/2026/01/recent-image.png';
		$incorrect_url = 'https://otherdomain.com/wrong.png';

		$arguments = array(
			'image_url' => $incorrect_url,
		);

		$context = array(
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'          => 'input_image',
							'url'           => $older_url,
							'attachment_id' => 100,
							'file_name'     => 'old-image.png',
						),
					),
				),
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'          => 'input_image',
							'url'           => $recent_url,
							'attachment_id' => 200,
							'file_name'     => 'recent-image.png',
						),
					),
				),
			),
		);

		$enriched = $method->invoke( $tool, $arguments, $context );

		// Should use the first found image (most recent in iteration order).
		$this->assertEquals( $older_url, $enriched['image_url'], 'Should use first found image from messages' );
		$this->assertEquals( 100, $enriched['attachment_id'], 'Should use first image attachment_id' );
	}

	/**
	 * Test enrichment when attachment_id is already provided.
	 */
	public function test_enrichment_skips_when_attachment_id_provided() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'vectorize_image' );

		$this->assertNotNull( $tool, 'Vectorize image tool should be registered' );

		// Use reflection to test the protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'enrich_arguments_from_messages' );
		$method->setAccessible( true );

		$arguments = array(
			'attachment_id' => 999,
			'image_url'     => 'https://example.com/wrong.png',
		);

		$context = array(
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'          => 'input_image',
							'url'           => 'https://example.com/correct.png',
							'attachment_id' => 123,
						),
					),
				),
			),
		);

		$enriched = $method->invoke( $tool, $arguments, $context );

		// Should return arguments unchanged when attachment_id is provided.
		$this->assertEquals( 999, $enriched['attachment_id'], 'Should not override existing attachment_id' );
		$this->assertEquals( 'https://example.com/wrong.png', $enriched['image_url'], 'Should not override URL when attachment_id is present' );
	}

	/**
	 * Test enrichment with URL in 'url' parameter instead of 'image_url'.
	 */
	public function test_enrichment_with_url_parameter() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'vectorize_image' );

		$this->assertNotNull( $tool, 'Vectorize image tool should be registered' );

		// Use reflection to test the protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'enrich_arguments_from_messages' );
		$method->setAccessible( true );

		$correct_url   = 'https://bots.nvdigital.solutions/wp-content/uploads/2026/01/test.png';
		$incorrect_url = 'https://wrongdomain.com/image.png';

		$arguments = array(
			'url' => $incorrect_url,
		);

		$context = array(
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'          => 'input_image',
							'url'           => $correct_url,
							'attachment_id' => 456,
						),
					),
				),
			),
		);

		$enriched = $method->invoke( $tool, $arguments, $context );

		// Should correct the 'url' parameter.
		$this->assertEquals( $correct_url, $enriched['url'], 'Should replace incorrect url parameter' );
		$this->assertEquals( 456, $enriched['attachment_id'], 'Should extract attachment_id' );
	}

	/**
	 * Test enrichment with same domain but different path (should not be corrected).
	 */
	public function test_enrichment_with_same_domain_different_path() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'vectorize_image' );

		$this->assertNotNull( $tool, 'Vectorize image tool should be registered' );

		// Use reflection to test the protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'enrich_arguments_from_messages' );
		$method->setAccessible( true );

		$image_in_messages = 'https://bots.nvdigital.solutions/wp-content/uploads/2026/01/image1.png';
		$different_image   = 'https://bots.nvdigital.solutions/wp-content/uploads/2026/01/image2.png';

		$arguments = array(
			'image_url' => $different_image,
		);

		$context = array(
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'          => 'input_image',
							'url'           => $image_in_messages,
							'attachment_id' => 123,
						),
					),
				),
			),
		);

		$enriched = $method->invoke( $tool, $arguments, $context );

		// Should NOT correct URL because domain matches (even though exact URL doesn't).
		$this->assertEquals( $different_image, $enriched['image_url'], 'Should not replace URL when domain matches' );
		$this->assertArrayNotHasKey( 'attachment_id', $enriched, 'Should not add attachment_id when domain matches but URL differs' );
	}

	/**
	 * Test extract_domain_from_url helper method.
	 */
	public function test_extract_domain_from_url() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'vectorize_image' );

		$this->assertNotNull( $tool, 'Vectorize image tool should be registered' );

		// Use reflection to test the protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'extract_domain_from_url' );
		$method->setAccessible( true );

		// Test various URL formats.
		$this->assertEquals( 'example.com', $method->invoke( $tool, 'https://example.com/path/to/file.png' ) );
		$this->assertEquals( 'example.com', $method->invoke( $tool, 'http://www.example.com/path' ) );
		$this->assertEquals( 'subdomain.example.com', $method->invoke( $tool, 'https://subdomain.example.com/' ) );
		$this->assertEquals( '', $method->invoke( $tool, '' ) );
		$this->assertEquals( '', $method->invoke( $tool, 'not-a-url' ) );
	}

	/**
	 * Test enrichment with image_url format variations.
	 */
	public function test_enrichment_with_various_image_url_formats() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'vectorize_image' );

		$this->assertNotNull( $tool, 'Vectorize image tool should be registered' );

		// Use reflection to test the protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'enrich_arguments_from_messages' );
		$method->setAccessible( true );

		$correct_url = 'https://bots.nvdigital.solutions/wp-content/uploads/2026/01/test.png';

		$arguments = array(
			'image_url' => 'https://wrong.com/image.png',
		);

		// Test with nested image_url structure.
		$context = array(
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'      => 'image_url',
							'image_url' => array(
								'url' => $correct_url,
							),
							'attachment_id' => 789,
						),
					),
				),
			),
		);

		$enriched = $method->invoke( $tool, $arguments, $context );

		$this->assertEquals( $correct_url, $enriched['image_url'], 'Should handle nested image_url structure' );
		$this->assertEquals( 789, $enriched['attachment_id'], 'Should extract attachment_id from nested structure' );
	}
}
