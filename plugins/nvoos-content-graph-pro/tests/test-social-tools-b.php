<?php
/**
 * Characterization tests for the Wave F2 social-media tools batch B — the
 * twenty-one gated scheduling/engagement/analytics/content tools.
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
 * Social tools batch B tests.
 */
class Test_Social_Tools_B extends WP_UnitTestCase {

	/**
	 * Enable the social media toolkit for availability gates.
	 */
	public function setUp(): void {
		parent::setUp();
		$settings                                = get_option( 'wp_mcp_ai_settings', array() );
		$settings                                = is_array( $settings ) ? $settings : array();
		$settings['enable_social_media_toolkit'] = 1;
		update_option( 'wp_mcp_ai_settings', $settings );
	}

	/**
	 * The ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Post_To_Multiple_Platforms'   => 'tools/social-media/class-wp-mcp-ai-tool-post-to-multiple-platforms.php',
			'WP_MCP_AI_Tool_Schedule_Social_Post'         => 'tools/social-media/class-wp-mcp-ai-tool-schedule-social-post.php',
			'WP_MCP_AI_Tool_Bulk_Schedule_Posts'          => 'tools/social-media/class-wp-mcp-ai-tool-bulk-schedule-posts.php',
			'WP_MCP_AI_Tool_Auto_Optimize_Images'         => 'tools/social-media/class-wp-mcp-ai-tool-auto-optimize-images.php',
			'WP_MCP_AI_Tool_Create_Social_Video'          => 'tools/social-media/class-wp-mcp-ai-tool-create-social-video.php',
			'WP_MCP_AI_Tool_Monitor_Mentions_Replies'     => 'tools/social-media/class-wp-mcp-ai-tool-monitor-mentions-replies.php',
			'WP_MCP_AI_Tool_Auto_Respond_Messages'        => 'tools/social-media/class-wp-mcp-ai-tool-auto-respond-messages.php',
			'WP_MCP_AI_Tool_Moderate_Comments'            => 'tools/social-media/class-wp-mcp-ai-tool-moderate-comments.php',
			'WP_MCP_AI_Tool_Get_Cross_Platform_Analytics' => 'tools/social-media/class-wp-mcp-ai-tool-get-cross-platform-analytics.php',
			'WP_MCP_AI_Tool_Track_Hashtag_Performance'    => 'tools/social-media/class-wp-mcp-ai-tool-track-hashtag-performance.php',
			'WP_MCP_AI_Tool_Competitor_Analysis'          => 'tools/social-media/class-wp-mcp-ai-tool-competitor-analysis.php',
			'WP_MCP_AI_Tool_Influencer_Identification'    => 'tools/social-media/class-wp-mcp-ai-tool-influencer-identification.php',
			'WP_MCP_AI_Tool_Get_Social_Analytics'         => 'tools/social-media/class-wp-mcp-ai-tool-get-social-analytics.php',
			'WP_MCP_AI_Tool_Create_Content_Calendar'      => 'tools/social-media/class-wp-mcp-ai-tool-create-content-calendar.php',
			'WP_MCP_AI_Tool_Generate_Post_Ideas'          => 'tools/social-media/class-wp-mcp-ai-tool-generate-post-ideas.php',
			'WP_MCP_AI_Tool_Social_Listening_Trends'      => 'tools/social-media/class-wp-mcp-ai-tool-social-listening-trends.php',
			'WP_MCP_AI_Tool_Social_Capture_Post_Performance' => 'tools/social-media/class-wp-mcp-ai-tool-social-capture-post-performance.php',
			'WP_MCP_AI_Tool_Get_Content_Calendar'         => 'tools/social-media/class-wp-mcp-ai-tool-get-content-calendar.php',
			'WP_MCP_AI_Tool_Generate_Social_Captions'     => 'tools/social-media/class-wp-mcp-ai-tool-generate-social-captions.php',
			'WP_MCP_AI_Tool_Schedule_Social_Posts'        => 'tools/social-media/class-wp-mcp-ai-tool-schedule-social-posts.php',
			'WP_MCP_AI_Tool_Publish_To_Social'            => 'tools/social-media/class-wp-mcp-ai-tool-publish-to-social.php',
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
	 * The twenty-one tool surfaces must be byte-identical (two cap
	 * variances: read + publish_posts).
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Post_To_Multiple_Platforms'   => 'post_to_multiple_platforms',
			'WP_MCP_AI_Tool_Schedule_Social_Post'         => 'schedule_social_post',
			'WP_MCP_AI_Tool_Bulk_Schedule_Posts'          => 'bulk_schedule_posts',
			'WP_MCP_AI_Tool_Auto_Optimize_Images'         => 'auto_optimize_images',
			'WP_MCP_AI_Tool_Create_Social_Video'          => 'create_social_video',
			'WP_MCP_AI_Tool_Monitor_Mentions_Replies'     => 'monitor_mentions_replies',
			'WP_MCP_AI_Tool_Auto_Respond_Messages'        => 'auto_respond_messages',
			'WP_MCP_AI_Tool_Moderate_Comments'            => 'moderate_comments',
			'WP_MCP_AI_Tool_Get_Cross_Platform_Analytics' => 'get_cross_platform_analytics',
			'WP_MCP_AI_Tool_Track_Hashtag_Performance'    => 'track_hashtag_performance',
			'WP_MCP_AI_Tool_Competitor_Analysis'          => 'competitor_analysis',
			'WP_MCP_AI_Tool_Influencer_Identification'    => 'influencer_identification',
			'WP_MCP_AI_Tool_Get_Social_Analytics'         => 'get_social_analytics',
			'WP_MCP_AI_Tool_Create_Content_Calendar'      => 'create_content_calendar',
			'WP_MCP_AI_Tool_Generate_Post_Ideas'          => 'generate_post_ideas',
			'WP_MCP_AI_Tool_Social_Listening_Trends'      => 'social_listening_trends',
			'WP_MCP_AI_Tool_Social_Capture_Post_Performance' => 'social_capture_post_performance',
			'WP_MCP_AI_Tool_Get_Content_Calendar'         => 'get_content_calendar',
			'WP_MCP_AI_Tool_Generate_Social_Captions'     => 'generate_social_captions',
			'WP_MCP_AI_Tool_Schedule_Social_Posts'        => 'schedule_social_posts',
			'WP_MCP_AI_Tool_Publish_To_Social'            => 'publish_to_social',
		);

		$cap_map = array(
			'WP_MCP_AI_Tool_Get_Content_Calendar' => 'read',
			'WP_MCP_AI_Tool_Publish_To_Social'    => 'publish_posts',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$expected_cap = $cap_map[ $class ] ?? 'edit_posts';
			$this->assertSame( $expected_cap, $tool->get_required_capability(), $class );
		}
	}

	/**
	 * All twenty-one tools must fail closed without a user.
	 */
	public function test_permission_gates(): void {
		$classes = array(
			'WP_MCP_AI_Tool_Post_To_Multiple_Platforms',
			'WP_MCP_AI_Tool_Schedule_Social_Post',
			'WP_MCP_AI_Tool_Bulk_Schedule_Posts',
			'WP_MCP_AI_Tool_Auto_Optimize_Images',
			'WP_MCP_AI_Tool_Create_Social_Video',
			'WP_MCP_AI_Tool_Monitor_Mentions_Replies',
			'WP_MCP_AI_Tool_Auto_Respond_Messages',
			'WP_MCP_AI_Tool_Moderate_Comments',
			'WP_MCP_AI_Tool_Get_Cross_Platform_Analytics',
			'WP_MCP_AI_Tool_Track_Hashtag_Performance',
			'WP_MCP_AI_Tool_Competitor_Analysis',
			'WP_MCP_AI_Tool_Influencer_Identification',
			'WP_MCP_AI_Tool_Get_Social_Analytics',
			'WP_MCP_AI_Tool_Create_Content_Calendar',
			'WP_MCP_AI_Tool_Generate_Post_Ideas',
			'WP_MCP_AI_Tool_Social_Listening_Trends',
			'WP_MCP_AI_Tool_Get_Content_Calendar',
			'WP_MCP_AI_Tool_Generate_Social_Captions',
			'WP_MCP_AI_Tool_Schedule_Social_Posts',
			'WP_MCP_AI_Tool_Publish_To_Social',
		);

		foreach ( $classes as $class ) {
			$tool   = new $class();
			$result = $tool->execute( array(), array() );
			$this->assertWPError( $result, $class );
			$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code(), $class );
		}
	}

	/**
	 * The capture tool has no user gate — it must delegate to the capture
	 * base and fail closed on the missing wing key.
	 */
	public function test_capture_wing_key_gate(): void {
		$tool   = new WP_MCP_AI_Tool_Social_Capture_Post_Performance();
		$result = $tool->execute( array(), array() );
		$this->assertWPError( $result );
		$this->assertSame( 'capture_missing_wing_key', $result->get_error_code() );
	}

	/**
	 * As an editor, the argument-gated tools must reach their gates.
	 */
	public function test_argument_gates(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$args   = array( 'user_id' => $editor );

		$calendar = new WP_MCP_AI_Tool_Create_Content_Calendar();
		$this->assertSame( 'invalid_start_date', $calendar->execute( array(), $args )->get_error_code() );

		$schedule = new WP_MCP_AI_Tool_Schedule_Social_Post();
		$this->assertSame( 'missing_content', $schedule->execute( array(), $args )->get_error_code() );
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
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Post_To_Multiple_Platforms', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Publish_To_Social', $tools );
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
		$this->assertNotNull( $parent->all()['schedule_social_post'] ?? null );
		$this->assertNotNull( $parent->all()['publish_to_social'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'moderate_comments' ) );
		$this->assertTrue( $core_tools->has( 'get_social_analytics' ) );
	}
}
