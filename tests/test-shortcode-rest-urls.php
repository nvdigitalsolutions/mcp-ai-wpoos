<?php
/**
 * Test coverage for shortcode REST URL generation.
 *
 * @package WP_MCP_AI\Tests
 */

class Test_Shortcode_Rest_URLs extends WP_UnitTestCase {
	/**
	 * Shortcode instance for testing.
	 *
	 * @var WP_MCP_AI_Shortcode
	 */
	protected $shortcode;

	/**
	 * Reflection method to access protected get_rest_url_path method.
	 *
	 * @var ReflectionMethod
	 */
	protected $get_rest_url_path_method;

	public function setUp(): void {
		parent::setUp();

		if ( function_exists( 'wp_mcp_ai_bootstrap' ) ) {
			wp_mcp_ai_bootstrap();
		}

		// Get the shortcode instance.
		$this->shortcode = new WP_MCP_AI_Shortcode();

		// Use reflection to access the protected method.
		$reflection                      = new ReflectionClass( $this->shortcode );
		$this->get_rest_url_path_method  = $reflection->getMethod( 'get_rest_url_path' );
		$this->get_rest_url_path_method->setAccessible( true );
	}

	/**
	 * Test that get_rest_url_path returns absolute URLs.
	 */
	public function test_get_rest_url_path_returns_absolute_url() {
		$path   = 'mcp-ai/v1/tools';
		$result = $this->get_rest_url_path_method->invoke( $this->shortcode, $path );

		// Assert that the result is an absolute URL (starts with http:// or https://).
		$this->assertMatchesRegularExpression( '/^https?:\/\//', $result, 'REST URL should be absolute' );

		// Assert that the result contains the path.
		$this->assertStringContainsString( 'mcp-ai/v1/tools', $result, 'REST URL should contain the path' );

		// Assert that the result contains the REST prefix (usually wp-json).
		$this->assertStringContainsString( 'wp-json', $result, 'REST URL should contain the wp-json prefix' );
	}

	/**
	 * Test that get_rest_url_path returns the expected URL structure.
	 */
	public function test_get_rest_url_path_structure() {
		$path           = 'mcp-ai/v1/chat';
		$result         = $this->get_rest_url_path_method->invoke( $this->shortcode, $path );
		$expected_url   = rest_url( $path );

		// Assert that the result matches WordPress's rest_url() output.
		$this->assertSame( $expected_url, $result, 'REST URL should match WordPress rest_url() output' );
	}

	/**
	 * Test that toolsEndpoint configuration uses absolute URLs.
	 */
	public function test_shortcode_config_has_absolute_tools_endpoint() {
		// Create a test assistant.
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant',
			)
		);

		// Render the shortcode.
		$output = do_shortcode( sprintf( '[%s assistant="%d" allow_guests="1"]', WP_MCP_AI_Shortcode::SHORTCODE, $assistant_id ) );

		// Get the inline script data.
		$inline_scripts = wp_scripts()->get_data( WP_MCP_AI_Shortcode::SCRIPT_HANDLE, 'before' );

		if ( ! empty( $inline_scripts ) ) {
			// Ensure $inline_scripts is an array before using implode.
			if ( ! is_array( $inline_scripts ) ) {
				$inline_scripts = array( $inline_scripts );
			}
			$script_content = implode( "\n", $inline_scripts );

			// Extract toolsEndpoint from the inline script.
			if ( preg_match( '/"toolsEndpoint"\s*:\s*"([^"]+)"/', $script_content, $matches ) ) {
				$tools_endpoint = $matches[1];

				// Assert that toolsEndpoint is an absolute URL.
				$this->assertMatchesRegularExpression( '/^https?:\/\//', $tools_endpoint, 'toolsEndpoint should be an absolute URL' );
				$this->assertStringContainsString( '/wp-json/mcp-ai/v1/tools', $tools_endpoint, 'toolsEndpoint should contain the correct path' );
			} else {
				$this->fail( 'Could not find toolsEndpoint in inline script' );
			}
		} else {
			$this->fail( 'No inline script found for shortcode' );
		}
	}

	/**
	 * Test that all REST endpoint configurations use absolute URLs.
	 */
	public function test_all_rest_endpoints_are_absolute() {
		// Create a test assistant.
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant',
			)
		);

		// Render the shortcode.
		$output = do_shortcode( sprintf( '[%s assistant="%d" allow_guests="1"]', WP_MCP_AI_Shortcode::SHORTCODE, $assistant_id ) );

		// Get the inline script data.
		$inline_scripts = wp_scripts()->get_data( WP_MCP_AI_Shortcode::SCRIPT_HANDLE, 'before' );

		if ( ! empty( $inline_scripts ) ) {
			// Ensure $inline_scripts is an array before using implode.
			if ( ! is_array( $inline_scripts ) ) {
				$inline_scripts = array( $inline_scripts );
			}
			$script_content = implode( "\n", $inline_scripts );

			// List of endpoints that should be absolute URLs.
			$endpoints = array(
				'restUrl',
				'messagesEndpoint',
				'toolsEndpoint',
				'filesEndpoint',
				'transcriptsEndpoint',
				'crawl4aiTaskEndpoint',
			);

			foreach ( $endpoints as $endpoint ) {
				if ( preg_match( '/"' . preg_quote( $endpoint, '/' ) . '"\s*:\s*"([^"]+)"/', $script_content, $matches ) ) {
					$endpoint_url = $matches[1];

					// Assert that the endpoint is an absolute URL (starts with http:// or https://).
					$this->assertMatchesRegularExpression(
						'/^https?:\/\//',
						$endpoint_url,
						sprintf( '%s should be an absolute URL', $endpoint )
					);
				}
			}
		}
	}
}
