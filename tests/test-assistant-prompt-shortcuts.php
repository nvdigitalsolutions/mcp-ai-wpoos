<?php
/**
 * Tests for assistant prompt shortcut aggregation.
 *
 * @package WP_MCP_AI\Tests
 */
class Test_Assistant_Prompt_Shortcuts extends WP_UnitTestCase {
	/**
	 * Registered stub tool instance.
	 *
	 * @var WP_MCP_AI_Test_Prompt_Shortcut_Tool
	 */
	protected $stub_tool;

	/**
	 * Tool registry reference.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $registry;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( function_exists( 'wp_mcp_ai_bootstrap' ) ) {
			wp_mcp_ai_bootstrap();
		}

		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();

		if ( method_exists( $this->registry, 'init' ) ) {
			$this->registry->init();
		}

		$this->stub_tool = new WP_MCP_AI_Test_Prompt_Shortcut_Tool();
		$this->registry->register_tool( $this->stub_tool );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		if ( $this->registry instanceof WP_MCP_AI_Tool_Registry ) {
			$this->registry->unregister_tool( $this->stub_tool->get_slug() );
		}

		parent::tearDown();
	}

	/**
	 * Ensure the default prompt shortcuts from a tool are merged with the global fallback entry.
	 */
	public function test_tool_default_shortcuts_are_included_with_global_fallback() {
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Prompt Shortcut Assistant',
			)
		);

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, array( $this->stub_tool->get_slug() ) );

		$shortcuts = WP_MCP_AI_Shortcode::get_assistant_tool_shortcuts( $assistant_id );

		$this->assertNotEmpty( $shortcuts, 'Expected shortcut entries to be returned.' );

		$tool_shortcuts = array_filter(
			$shortcuts,
			function ( $shortcut ) {
				return is_array( $shortcut ) && isset( $shortcut['tool'] ) && $this->stub_tool->get_slug() === $shortcut['tool'];
			}
		);

		$this->assertCount( 1, $tool_shortcuts, 'Default tool shortcut should be present exactly once.' );

		$tool_shortcut = array_values( $tool_shortcuts )[0];
		$this->assertSame( 'Default summary', $tool_shortcut['label'] );
		$this->assertSame( 'summarize the latest updates', $tool_shortcut['payload'] );
		$this->assertSame( 'Provide a quick site summary.', $tool_shortcut['description'] );

		$fallback_entries = array_filter(
			$shortcuts,
			static function ( $shortcut ) {
				return is_array( $shortcut ) && isset( $shortcut['tool'] ) && 'default' === $shortcut['tool'];
			}
		);

		$this->assertCount( 1, $fallback_entries, 'Global fallback shortcut should be appended once.' );

		$fallback_shortcut = array_values( $fallback_entries )[0];
		$this->assertSame( 'What can you do?', $fallback_shortcut['label'] );
		$this->assertSame( 'what are some things you can do', $fallback_shortcut['payload'] );
	}

	/**
	 * Ensure custom pre-built shortcuts override the default tool entries.
	 */
	public function test_custom_prebuilt_shortcuts_override_default_tool_entries() {
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Custom Prompt Shortcut Assistant',
			)
		);

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, array( $this->stub_tool->get_slug() ) );

		update_post_meta(
			$assistant_id,
			WP_MCP_AI_Assistant_CPT::META_TOOL_PREBUILT_SHORTCUTS,
			array(
				$this->stub_tool->get_slug() => array(
					'mode'      => 'custom',
					'shortcuts' => array(
						array(
							'label'       => 'Review open support tickets',
							'payload'     => 'list unresolved support tickets',
							'description' => 'Surface outstanding issues that need attention.',
						),
					),
				),
			)
		);

		$shortcuts = WP_MCP_AI_Shortcode::get_assistant_tool_shortcuts( $assistant_id );

		$tool_shortcuts = array_filter(
			$shortcuts,
			function ( $shortcut ) {
				return is_array( $shortcut ) && isset( $shortcut['tool'] ) && $this->stub_tool->get_slug() === $shortcut['tool'];
			}
		);

		$this->assertCount( 1, $tool_shortcuts, 'Only the custom tool shortcut should be included.' );

		$tool_shortcut = array_values( $tool_shortcuts )[0];
		$this->assertSame( 'Review open support tickets', $tool_shortcut['label'] );
		$this->assertSame( 'list unresolved support tickets', $tool_shortcut['payload'] );
		$this->assertSame( 'Surface outstanding issues that need attention.', $tool_shortcut['description'] );

		$fallback_entries = array_filter(
			$shortcuts,
			static function ( $shortcut ) {
				return is_array( $shortcut ) && isset( $shortcut['tool'] ) && 'default' === $shortcut['tool'];
			}
		);

		$this->assertCount( 1, $fallback_entries, 'Global fallback shortcut should remain appended.' );
	}

	/**
	 * Ensure pre-built shortcuts stored with explicit tool metadata are applied after sanitization.
	 */
	public function test_prebuilt_shortcuts_with_tool_metadata_are_used() {
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Configured Pre-built Shortcuts Assistant',
			)
		);

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, array( $this->stub_tool->get_slug() ) );

		update_post_meta(
			$assistant_id,
			WP_MCP_AI_Assistant_CPT::META_TOOL_PREBUILT_SHORTCUTS,
			array(
				array(
					'tool'      => $this->stub_tool->get_slug(),
					'mode'      => 'custom',
					'shortcuts' => array(
						array(
							'label'       => 'Compile engagement metrics',
							'payload'     => 'gather the latest engagement analytics',
							'description' => 'Review the newest audience and traffic metrics.',
						),
					),
				),
			)
		);

		$shortcuts = WP_MCP_AI_Shortcode::get_assistant_tool_shortcuts( $assistant_id );

		$tool_shortcuts = array_filter(
			$shortcuts,
			function ( $shortcut ) {
				return is_array( $shortcut ) && isset( $shortcut['tool'] ) && $this->stub_tool->get_slug() === $shortcut['tool'];
			}
		);

		$this->assertCount( 1, $tool_shortcuts, 'Configured pre-built shortcut should be surfaced exactly once.' );

		$tool_shortcut = array_values( $tool_shortcuts )[0];
		$this->assertSame( 'Compile engagement metrics', $tool_shortcut['label'] );
		$this->assertSame( 'gather the latest engagement analytics', $tool_shortcut['payload'] );
		$this->assertSame( 'Review the newest audience and traffic metrics.', $tool_shortcut['description'] );

		$fallback_entries = array_filter(
			$shortcuts,
			static function ( $shortcut ) {
				return is_array( $shortcut ) && isset( $shortcut['tool'] ) && 'default' === $shortcut['tool'];
			}
		);

		$this->assertCount( 1, $fallback_entries, 'Global fallback shortcut should remain appended.' );
	}

	/**
	 * Ensure core tools honour customised pre-built shortcuts.
	 */
	public function test_core_tool_prebuilt_shortcuts_can_be_customised() {
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Core Tool Custom Prompt Assistant',
			)
		);

		$tool_slug = 'get_recent_posts';

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, array( $tool_slug ) );

		update_post_meta(
			$assistant_id,
			WP_MCP_AI_Assistant_CPT::META_TOOL_PREBUILT_SHORTCUTS,
			array(
				$tool_slug => array(
					'mode'      => 'custom',
					'shortcuts' => array(
						array(
							'label'       => 'Review latest articles',
							'payload'     => 'list the five most recent blog posts',
							'description' => 'Summarise the newest publications for editorial review.',
						),
					),
				),
			)
		);

		$shortcuts = WP_MCP_AI_Shortcode::get_assistant_tool_shortcuts( $assistant_id );

		$tool_shortcuts = array_filter(
			$shortcuts,
			static function ( $shortcut ) use ( $tool_slug ) {
				return is_array( $shortcut ) && isset( $shortcut['tool'] ) && $tool_slug === $shortcut['tool'];
			}
		);

		$this->assertCount( 1, $tool_shortcuts, 'Only the customised shortcut should be surfaced for the tool.' );

		$tool_shortcut = array_values( $tool_shortcuts )[0];
		$this->assertSame( 'Review latest articles', $tool_shortcut['label'] );
		$this->assertSame( 'list the five most recent blog posts', $tool_shortcut['payload'] );
		$this->assertSame( 'Summarise the newest publications for editorial review.', $tool_shortcut['description'] );
	}

	/**
	 * Ensure multiple core tool overrides are respected together.
	 */
	public function test_multiple_core_tool_prebuilt_shortcuts_overrides() {
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Multiple Core Tool Overrides Assistant',
			)
		);

		$tools = array( 'get_recent_posts', 'search_content' );

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, $tools );

		update_post_meta(
			$assistant_id,
			WP_MCP_AI_Assistant_CPT::META_TOOL_PREBUILT_SHORTCUTS,
			array(
				'get_recent_posts' => array(
					'mode'      => 'custom',
					'shortcuts' => array(
						array(
							'label'   => 'Summarise new posts',
							'payload' => 'summarise the five most recent posts',
						),
					),
				),
				'search_content'   => array(
					'mode'      => 'custom',
					'shortcuts' => array(
						array(
							'label'       => 'Locate policy docs',
							'payload'     => 'search for published policies about onboarding',
							'description' => 'Focus on handbooks and procedural documentation.',
						),
					),
				),
			)
		);

		$shortcuts = WP_MCP_AI_Shortcode::get_assistant_tool_shortcuts( $assistant_id );

		$this->assertNotEmpty( $shortcuts, 'Expected customised tool shortcuts to be returned.' );

		$grouped = array();
		foreach ( $shortcuts as $shortcut ) {
			if ( ! is_array( $shortcut ) || empty( $shortcut['tool'] ) ) {
				continue;
			}

			$grouped[ $shortcut['tool'] ][] = $shortcut;
		}

		$this->assertArrayHasKey( 'get_recent_posts', $grouped );
		$this->assertArrayHasKey( 'search_content', $grouped );

		$recent = $grouped['get_recent_posts'][0];
		$search = $grouped['search_content'][0];

		$this->assertSame( 'Summarise new posts', $recent['label'] );
		$this->assertSame( 'summarise the five most recent posts', $recent['payload'] );

		$this->assertSame( 'Locate policy docs', $search['label'] );
		$this->assertSame( 'search for published policies about onboarding', $search['payload'] );
		$this->assertSame( 'Focus on handbooks and procedural documentation.', $search['description'] );
	}

	/**
	 * Ensure multiple custom rows for a single tool are preserved.
	 */
	public function test_multiple_custom_rows_for_single_tool_are_saved() {
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Multiple Rows Assistant',
			)
		);

		$tool_slug = 'search_content';

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, array( $tool_slug ) );

		update_post_meta(
			$assistant_id,
			WP_MCP_AI_Assistant_CPT::META_TOOL_PREBUILT_SHORTCUTS,
			array(
				$tool_slug => array(
					'mode'      => 'custom',
					'shortcuts' => array(
						array(
							'label'   => 'Find support tickets',
							'payload' => 'search for support tickets mentioning billing issues',
						),
						array(
							'label'       => 'Gather product reviews',
							'payload'     => 'search for product review posts from the last 30 days',
							'description' => 'Limit results to testimonials and review categories.',
						),
					),
				),
			)
		);

		$shortcuts = WP_MCP_AI_Shortcode::get_assistant_tool_shortcuts( $assistant_id );

		$tool_shortcuts = array_values(
			array_filter(
				$shortcuts,
				static function ( $shortcut ) use ( $tool_slug ) {
					return is_array( $shortcut ) && isset( $shortcut['tool'] ) && $tool_slug === $shortcut['tool'];
				}
			)
		);

		$this->assertCount( 2, $tool_shortcuts, 'Expected both custom rows to be returned.' );
		$this->assertSame( 'Find support tickets', $tool_shortcuts[0]['label'] );
		$this->assertSame( 'Gather product reviews', $tool_shortcuts[1]['label'] );
		$this->assertSame( 'Limit results to testimonials and review categories.', $tool_shortcuts[1]['description'] );
	}

	/**
	 * Ensure the meta box submission pipeline persists customised pre-built shortcuts.
	 */
	public function test_prebuilt_shortcut_customisation_persists_via_save_post() {
		global $wp_mcp_ai_assistant_cpt;

		$this->assertInstanceOf( WP_MCP_AI_Assistant_CPT::class, $wp_mcp_ai_assistant_cpt );

		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Meta Box Customisation Assistant',
			)
		);

		$tool_slug = 'get_recent_posts';

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$_POST['wp_mcp_ai_tools_meta_nonce']   = wp_create_nonce( 'wp_mcp_ai_tools_meta' );
		$_POST['wp_mcp_ai_tools']              = array( $tool_slug );
		$_POST['wp_mcp_ai_prebuilt_shortcuts'] = array(
			$tool_slug => array(
				'mode'      => 'custom',
				'shortcuts' => array(
					array(
						'label'       => 'Editorial digest',
						'payload'     => 'compile a digest of the three latest posts',
						'description' => 'Highlight the main takeaway from each post.',
					),
				),
			),
		);

		$wp_mcp_ai_assistant_cpt->save_post( $assistant_id, get_post( $assistant_id ) );

		unset( $_POST['wp_mcp_ai_tools_meta_nonce'], $_POST['wp_mcp_ai_tools'], $_POST['wp_mcp_ai_prebuilt_shortcuts'] );

		$saved_prebuilt = get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOL_PREBUILT_SHORTCUTS, true );
		$this->assertIsArray( $saved_prebuilt, 'Expected pre-built shortcut metadata to be stored.' );
		$this->assertArrayHasKey( $tool_slug, $saved_prebuilt );

		$tool_entry = $saved_prebuilt[ $tool_slug ];
		$this->assertSame( 'custom', $tool_entry['mode'] );
		$this->assertNotEmpty( $tool_entry['shortcuts'] );

		$shortcut = $tool_entry['shortcuts'][0];
		$this->assertSame( 'Editorial digest', $shortcut['label'] );
		$this->assertSame( 'compile a digest of the three latest posts', $shortcut['payload'] );
		$this->assertSame( 'Highlight the main takeaway from each post.', $shortcut['description'] );

		wp_set_current_user( 0 );

		$tool_shortcuts = WP_MCP_AI_Shortcode::get_assistant_tool_shortcuts( $assistant_id );

		$tool_shortcuts = array_values(
			array_filter(
				$tool_shortcuts,
				static function ( $entry ) use ( $tool_slug ) {
					return is_array( $entry ) && isset( $entry['tool'] ) && $tool_slug === $entry['tool'];
				}
			)
		);

		$this->assertCount( 1, $tool_shortcuts, 'Customised shortcut should be returned through the shortcode helper.' );
		$this->assertSame( 'Editorial digest', $tool_shortcuts[0]['label'] );
	}

	/**
	 * Ensure empty customised entries fall back to defaults.
	 */
	public function test_empty_customised_prebuilt_shortcuts_are_ignored() {
		$raw = array(
			'get_recent_posts' => array(
				'mode'      => 'custom',
				'shortcuts' => array(
					array(
						'label'   => '',
						'payload' => '',
					),
				),
			),
		);

		$sanitised = WP_MCP_AI_Assistant_CPT::sanitize_prebuilt_tool_shortcuts_meta( $raw );

		$this->assertSame( array(), $sanitised, 'Expected empty custom rows to be discarded.' );
	}
}

class WP_MCP_AI_Test_Prompt_Shortcut_Tool implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Shortcuts_Interface {
	/**
	 * Get the tool slug.
	 *
	 * @return string Tool slug.
	 */
	public function get_slug() {
		return 'test_prompt_shortcut_tool';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string Tool name.
	 */
	public function get_name() {
		return 'Test Prompt Shortcut Tool';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string Tool description.
	 */
	public function get_description() {
		return 'Stub tool for exercising prompt shortcut logic.';
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array Parameters schema.
	 */
	public function get_parameters_schema() {
		return array();
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context Execution context.
	 * @return array|WP_Error Tool result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		return array();
	}

	public function get_shortcut_tasks() {
		return array(
			array(
				'label'       => 'Default summary',
				'payload'     => 'summarize the latest updates',
				'description' => 'Provide a quick site summary.',
			),
		);
	}
}
