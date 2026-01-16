<?php
/**
 * Tests for Documentation Link Rendering
 *
 * @package WP_MCP_AI
 */

/**
 * Test that documentation links are properly rendered in section UI.
 */
class Test_Documentation_Link_Rendering extends WP_UnitTestCase {

	/**
	 * List of sections that should have documentation links.
	 *
	 * @var array
	 */
	private $sections_with_docs = array(
		'general'               => 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/getting-started/QUICK_START_5_MINUTES.md',
		'authentication'        => 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/reference/api/authentication.md',
		'chat-client'           => 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/guides/user/chat/chat-client-settings.md',
		'advanced'              => 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/guides/admin/settings/new-settings-december-2025.md',
		'providers'             => 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/guides/admin/SETTINGS_DASHBOARD_GUIDE.md#providers-tab',
		'orchestration'         => 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/architecture/orchestration/ORCHESTRATION-LAYER-ARCHITECTURE.md',
		'overview'              => 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/QUICK_REFERENCE.md',
		'tools'                 => 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/reference/tools/tool-reference.md',
		'token-manager'         => 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/features/performance/TOKEN_MANAGEMENT_GUIDE.md',
		'integrations'          => 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/architecture/integrations/oauth-settings-architecture.md',
	);

	/**
	 * Test that all sections with documentation URLs return valid URLs.
	 */
	public function test_sections_have_documentation_urls() {
		foreach ( $this->sections_with_docs as $section_id => $expected_url ) {
			$section = WP_MCP_AI_Settings_Registry::get_section( $section_id );

			$this->assertNotNull( $section, "Section '{$section_id}' should be registered" );

			$doc_url = $section->get_documentation_url();

			$this->assertNotEmpty( $doc_url, "Section '{$section_id}' should have a documentation URL" );
			$this->assertEquals(
				$expected_url,
				$doc_url,
				"Section '{$section_id}' should have the correct documentation URL"
			);
		}
	}

	/**
	 * Test that documentation links are rendered in output for sections with custom render_wrapper.
	 */
	public function test_documentation_link_rendered_in_general_section() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'general' );
		$this->assertNotNull( $section );

		// Capture the output.
		ob_start();
		$section->render_wrapper();
		$output = ob_get_clean();

		// Check for documentation link elements.
		$this->assertStringContainsString( 'section-documentation', $output, 'Should contain documentation section class' );
		$this->assertStringContainsString( 'dashicons-book-alt', $output, 'Should contain book icon' );
		$this->assertStringContainsString( 'View Documentation', $output, 'Should contain "View Documentation" text' );
		$this->assertStringContainsString( 'dashicons-external', $output, 'Should contain external link icon' );
		$this->assertStringContainsString( 'target="_blank"', $output, 'Should open in new tab' );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $output, 'Should have secure rel attribute' );

		// Check for the actual URL.
		$expected_url = 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/getting-started/QUICK_START_5_MINUTES.md';
		$this->assertStringContainsString( esc_url( $expected_url ), $output, 'Should contain the correct documentation URL' );
	}

	/**
	 * Test that documentation links are rendered in authentication section.
	 */
	public function test_documentation_link_rendered_in_authentication_section() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'authentication' );
		$this->assertNotNull( $section );

		// Capture the output.
		ob_start();
		$section->render_wrapper();
		$output = ob_get_clean();

		// Check for documentation link elements.
		$this->assertStringContainsString( 'section-documentation', $output, 'Should contain documentation section class' );
		$this->assertStringContainsString( 'View Documentation', $output, 'Should contain "View Documentation" text' );

		// Check for the actual URL.
		$expected_url = 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/reference/api/authentication.md';
		$this->assertStringContainsString( esc_url( $expected_url ), $output, 'Should contain the correct documentation URL' );
	}

	/**
	 * Test that documentation links are rendered in providers section.
	 */
	public function test_documentation_link_rendered_in_providers_section() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'providers' );
		$this->assertNotNull( $section );

		// Capture the output.
		ob_start();
		$section->render_wrapper();
		$output = ob_get_clean();

		// Check for documentation link elements.
		$this->assertStringContainsString( 'section-documentation', $output, 'Should contain documentation section class' );
		$this->assertStringContainsString( 'View Documentation', $output, 'Should contain "View Documentation" text' );

		// Check for the actual URL.
		$expected_url = 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/guides/admin/SETTINGS_DASHBOARD_GUIDE.md#providers-tab';
		$this->assertStringContainsString( esc_url( $expected_url ), $output, 'Should contain the correct documentation URL' );
	}

	/**
	 * Test that documentation links are rendered in orchestration section.
	 */
	public function test_documentation_link_rendered_in_orchestration_section() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'orchestration' );
		$this->assertNotNull( $section );

		// Capture the output.
		ob_start();
		$section->render_wrapper();
		$output = ob_get_clean();

		// Check for documentation link elements.
		$this->assertStringContainsString( 'section-documentation', $output, 'Should contain documentation section class' );
		$this->assertStringContainsString( 'View Documentation', $output, 'Should contain "View Documentation" text' );

		// Check for the actual URL.
		$expected_url = 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/architecture/orchestration/ORCHESTRATION-LAYER-ARCHITECTURE.md';
		$this->assertStringContainsString( esc_url( $expected_url ), $output, 'Should contain the correct documentation URL' );
	}

	/**
	 * Test that documentation links are rendered in overview section.
	 */
	public function test_documentation_link_rendered_in_overview_section() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'overview' );
		$this->assertNotNull( $section );

		// Capture the output.
		ob_start();
		$section->render_wrapper();
		$output = ob_get_clean();

		// Check for documentation link elements.
		$this->assertStringContainsString( 'section-documentation', $output, 'Should contain documentation section class' );
		$this->assertStringContainsString( 'View Documentation', $output, 'Should contain "View Documentation" text' );

		// Check for the actual URL.
		$expected_url = 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/QUICK_REFERENCE.md';
		$this->assertStringContainsString( esc_url( $expected_url ), $output, 'Should contain the correct documentation URL' );
	}

	/**
	 * Test that documentation links are rendered in tools section.
	 */
	public function test_documentation_link_rendered_in_tools_section() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'tools' );
		$this->assertNotNull( $section );

		// Capture the output.
		ob_start();
		$section->render_wrapper();
		$output = ob_get_clean();

		// Check for documentation link elements.
		$this->assertStringContainsString( 'section-documentation', $output, 'Should contain documentation section class' );
		$this->assertStringContainsString( 'View Documentation', $output, 'Should contain "View Documentation" text' );

		// Check for the actual URL.
		$expected_url = 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/reference/tools/tool-reference.md';
		$this->assertStringContainsString( esc_url( $expected_url ), $output, 'Should contain the correct documentation URL' );
	}

	/**
	 * Test that documentation links are properly escaped.
	 */
	public function test_documentation_links_are_properly_escaped() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'general' );
		$this->assertNotNull( $section );

		// Capture the output.
		ob_start();
		$section->render_wrapper();
		$output = ob_get_clean();

		// Ensure URL is properly escaped (no unescaped quotes or special chars).
		$this->assertStringNotContainsString( '<script', $output, 'Should not contain unescaped script tags' );
		$this->assertStringNotContainsString( 'javascript:', $output, 'Should not contain javascript: protocol' );

		// Check that esc_url was applied (look for properly escaped URL).
		preg_match( '/href="([^"]+)"/', $output, $matches );
		$this->assertNotEmpty( $matches, 'Should find href attribute' );

		if ( ! empty( $matches[1] ) ) {
			$url = $matches[1];
			$this->assertStringStartsWith( 'https://', $url, 'Should be HTTPS URL' );
			$this->assertEquals( $url, esc_url( $url ), 'URL should be properly escaped' );
		}
	}

	/**
	 * Test that sections without documentation URLs don't render documentation links.
	 */
	public function test_sections_without_docs_dont_render_links() {
		// Get a section that typically doesn't override render_wrapper
		// and thus would use the parent's implementation.
		$all_sections = WP_MCP_AI_Settings_Registry::get_sections();

		foreach ( $all_sections as $section ) {
			$doc_url = $section->get_documentation_url();

			// If no documentation URL, the output should not contain documentation elements.
			if ( empty( $doc_url ) ) {
				ob_start();
				$section->render_wrapper();
				$output = ob_get_clean();

				$this->assertStringNotContainsString(
					'section-documentation',
					$output,
					sprintf( 'Section %s without doc URL should not render documentation link', $section->get_id() )
				);
			}
		}
	}

	/**
	 * Test that all documentation URLs use HTTPS.
	 */
	public function test_all_documentation_urls_use_https() {
		foreach ( $this->sections_with_docs as $section_id => $expected_url ) {
			$this->assertStringStartsWith(
				'https://',
				$expected_url,
				"Section '{$section_id}' documentation URL should use HTTPS"
			);
		}
	}
}
