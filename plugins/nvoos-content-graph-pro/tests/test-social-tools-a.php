<?php
/**
 * Characterization tests for the Wave F2 social-media tools batch A — the
 * eleven always-on Pro download/post/insights tools.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the tool
 *   classes (classmap-autoloaded); the byte-identical surfaces and
 *   fail-closed gates are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/social-media/` are asserted in full, including the filter
 *   and ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Social tools batch A tests.
 */
class Test_Social_Tools_A extends WP_UnitTestCase {

	/**
	 * The ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Pro_Tool_Download_Google_Maps_Images' => 'tools/social-media/class-wp-mcp-ai-pro-tool-download-google-maps-images.php',
			'WP_MCP_AI_Pro_Tool_Download_Facebook_Page_Images' => 'tools/social-media/class-wp-mcp-ai-pro-tool-download-facebook-page-images.php',
			'WP_MCP_AI_Pro_Tool_Download_Instagram_Page_Images' => 'tools/social-media/class-wp-mcp-ai-pro-tool-download-instagram-page-images.php',
			'WP_MCP_AI_Pro_Tool_Post_Facebook_Instagram' => 'tools/social-media/class-wp-mcp-ai-pro-tool-post-facebook-instagram.php',
			'WP_MCP_AI_Pro_Tool_Post_Tiktok_Video'       => 'tools/social-media/class-wp-mcp-ai-pro-tool-post-tiktok-video.php',
			'WP_MCP_AI_Pro_Tool_Post_Linkedin_Update'    => 'tools/social-media/class-wp-mcp-ai-pro-tool-post-linkedin-update.php',
			'WP_MCP_AI_Pro_Tool_Post_Google_Business_Update' => 'tools/social-media/class-wp-mcp-ai-pro-tool-post-google-business-update.php',
			'WP_MCP_AI_Pro_Tool_Get_Facebook_Instagram_Insights' => 'tools/social-media/class-wp-mcp-ai-pro-tool-get-facebook-instagram-insights.php',
			'WP_MCP_AI_Pro_Tool_Get_Tiktok_Insights'     => 'tools/social-media/class-wp-mcp-ai-pro-tool-get-tiktok-insights.php',
			'WP_MCP_AI_Pro_Tool_Get_Linkedin_Insights'   => 'tools/social-media/class-wp-mcp-ai-pro-tool-get-linkedin-insights.php',
			'WP_MCP_AI_Pro_Tool_Get_Google_Business_Insights' => 'tools/social-media/class-wp-mcp-ai-pro-tool-get-google-business-insights.php',
		);

		foreach ( $symbols as $class => $file ) {
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'addons/pro/includes/' . $file, $path, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/' . $file, $path, $class );
			}
		}
	}

	/**
	 * The eleven tool surfaces must be byte-identical (all edit_posts).
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Pro_Tool_Download_Google_Maps_Images' => 'download_google_maps_images',
			'WP_MCP_AI_Pro_Tool_Download_Facebook_Page_Images' => 'download_facebook_page_images',
			'WP_MCP_AI_Pro_Tool_Download_Instagram_Page_Images' => 'download_instagram_page_images',
			'WP_MCP_AI_Pro_Tool_Post_Facebook_Instagram' => 'post_facebook_instagram',
			'WP_MCP_AI_Pro_Tool_Post_Tiktok_Video'       => 'post_tiktok_video',
			'WP_MCP_AI_Pro_Tool_Post_Linkedin_Update'    => 'post_linkedin_update',
			'WP_MCP_AI_Pro_Tool_Post_Google_Business_Update' => 'post_google_business_update',
			'WP_MCP_AI_Pro_Tool_Get_Facebook_Instagram_Insights' => 'get_facebook_instagram_insights',
			'WP_MCP_AI_Pro_Tool_Get_Tiktok_Insights'     => 'get_tiktok_insights',
			'WP_MCP_AI_Pro_Tool_Get_Linkedin_Insights'   => 'get_linkedin_insights',
			'WP_MCP_AI_Pro_Tool_Get_Google_Business_Insights' => 'get_google_business_insights',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}
	}

	/**
	 * The eleven tools must fail closed without a user — the insights tools
	 * carry their byte-identical platform-specific forbidden codes.
	 */
	public function test_permission_gates(): void {
		$expectations = array(
			'WP_MCP_AI_Pro_Tool_Download_Google_Maps_Images' => 'wp_mcp_ai_forbidden',
			'WP_MCP_AI_Pro_Tool_Download_Facebook_Page_Images' => 'wp_mcp_ai_forbidden',
			'WP_MCP_AI_Pro_Tool_Download_Instagram_Page_Images' => 'wp_mcp_ai_forbidden',
			'WP_MCP_AI_Pro_Tool_Post_Facebook_Instagram' => 'wp_mcp_ai_forbidden',
			'WP_MCP_AI_Pro_Tool_Post_Tiktok_Video'       => 'wp_mcp_ai_forbidden',
			'WP_MCP_AI_Pro_Tool_Post_Linkedin_Update'    => 'wp_mcp_ai_forbidden',
			'WP_MCP_AI_Pro_Tool_Post_Google_Business_Update' => 'wp_mcp_ai_forbidden',
			'WP_MCP_AI_Pro_Tool_Get_Facebook_Instagram_Insights' => 'wp_mcp_ai_meta_insights_forbidden',
			'WP_MCP_AI_Pro_Tool_Get_Tiktok_Insights'     => 'wp_mcp_ai_tiktok_insights_forbidden',
			'WP_MCP_AI_Pro_Tool_Get_Linkedin_Insights'   => 'wp_mcp_ai_linkedin_insights_forbidden',
			'WP_MCP_AI_Pro_Tool_Get_Google_Business_Insights' => 'wp_mcp_ai_google_business_insights_forbidden',
		);

		foreach ( $expectations as $class => $error_code ) {
			$tool   = new $class();
			$result = $tool->execute( array(), array() );
			$this->assertWPError( $result, $class );
			$this->assertSame( $error_code, $result->get_error_code(), $class );
		}
	}

	/**
	 * As an editor, the tools must reach their argument gates.
	 */
	public function test_argument_gates(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$args   = array( 'user_id' => $editor );

		$download = new WP_MCP_AI_Pro_Tool_Download_Facebook_Page_Images();
		$this->assertSame( 'wp_mcp_ai_missing_params', $download->execute( array(), $args )->get_error_code() );

		// The post + insights tools gate on manage_options (filterable) — an
		// editor is forbidden; an administrator reaches the argument gates.
		$admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$admin_args = array( 'user_id' => $admin );

		$tiktok = new WP_MCP_AI_Pro_Tool_Get_Tiktok_Insights();
		$this->assertSame( 'wp_mcp_ai_tiktok_insights_forbidden', $tiktok->execute( array(), $args )->get_error_code() );
		$this->assertSame( 'wp_mcp_ai_tiktok_insights_missing_token', $tiktok->execute( array(), $admin_args )->get_error_code() );

		$post = new WP_MCP_AI_Pro_Tool_Post_Facebook_Instagram();
		$this->assertSame( 'wp_mcp_ai_forbidden', $post->execute( array(), $args )->get_error_code() );
		$this->assertSame( 'wp_mcp_ai_invalid_platform', $post->execute( array(), $admin_args )->get_error_code() );

		$google = new WP_MCP_AI_Pro_Tool_Post_Google_Business_Update();
		$this->assertSame( 'wp_mcp_ai_missing_google_token', $google->execute( array(), $admin_args )->get_error_code() );
	}

	/**
	 * Standalone only: the init's tool filter must carry the batch.
	 */
	public function test_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the social tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/social-media/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_social_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Pro_Tool_Download_Google_Maps_Images', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Pro_Tool_Get_Google_Business_Insights', $tools );
	}

	/**
	 * Standalone only: the ecosystem registration must register the batch
	 * into both registries.
	 */
	public function test_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/social-media/init.php';
		wp_mcp_ai_pro_register_social_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['download_facebook_page_images'] ?? null );
		$this->assertNotNull( $parent->all()['post_tiktok_video'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'post_facebook_instagram' ) );
		$this->assertTrue( $core_tools->has( 'get_linkedin_insights' ) );
	}
}
