<?php
/**
 * Content Assistant port tests (Wave E4, sub-cluster 4).
 *
 * Characterization suite for the ported `ContentAssistantBootstrap` +
 * `ContentAssistantMetabox`: the feature gate (settings default-on,
 * option off, filter override), the `admin_init` registration wiring,
 * the metabox ID, the settings-driven + filterable post-type set, the
 * metabox registration, the permission / disabled / no-assistants
 * render gates, the full render (selector + quick actions + modal),
 * the quick-action registry with its filter, the `mcp_ai_assistant`
 * enumeration, and the per-mode asset enqueue (chat bundle
 * monolith-only, own assets both modes). Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\ContentAssistant\ContentAssistantBootstrap;
use NvoosContentGraphAiPlatform\ContentAssistant\ContentAssistantMetabox;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The seam fixture shares this file with its test case.

/**
 * Seam exposing protected members for contract testing.
 */
class ContentAssistantMetaboxSeam extends ContentAssistantMetabox {

	/**
	 * Expose get_enabled_post_types().
	 *
	 * @return array
	 */
	public function seam_get_enabled_post_types() {
		return $this->get_enabled_post_types();
	}

	/**
	 * Expose get_quick_actions().
	 *
	 * @return array
	 */
	public function seam_get_quick_actions() {
		return $this->get_quick_actions();
	}

	/**
	 * Expose get_available_assistants().
	 *
	 * @return array
	 */
	public function seam_get_available_assistants() {
		return $this->get_available_assistants();
	}

	/**
	 * Expose get_context_data().
	 *
	 * @param \WP_Post $post Post object.
	 * @return array
	 */
	public function seam_get_context_data( $post ) {
		return $this->get_context_data( $post );
	}

	/**
	 * Expose get_assistant_title().
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	public function seam_get_assistant_title( $post_type ) {
		return $this->get_assistant_title( $post_type );
	}

	/**
	 * Expose get_placeholder_text().
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	public function seam_get_placeholder_text( $post_type ) {
		return $this->get_placeholder_text( $post_type );
	}
}

/**
 * Content Assistant characterization.
 */
class Test_Content_Assistant extends \WP_UnitTestCase {

	/**
	 * Metabox instance under test.
	 *
	 * @var ContentAssistantMetaboxSeam
	 */
	private $metabox;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'wp_mcp_ai_settings' );

		$this->metabox = new ContentAssistantMetaboxSeam();
	}

	// Bootstrap gate.

	/**
	 * The feature is enabled by default.
	 */
	public function test_feature_is_enabled_by_default() {
		$this->assertTrue( ContentAssistantBootstrap::is_enabled() );
	}

	/**
	 * The settings toggle disables the feature.
	 */
	public function test_settings_toggle_disables_the_feature() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_content_assistant_metabox' => false ) );

		$this->assertFalse( ContentAssistantBootstrap::is_enabled() );
	}

	/**
	 * The enabled-state filter overrides the setting.
	 */
	public function test_enabled_filter_overrides_the_setting() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_content_assistant_metabox' => true ) );
		add_filter( 'wp_mcp_ai_content_assistant_enabled', '__return_false' );

		$this->assertFalse( ContentAssistantBootstrap::is_enabled() );
	}

	/**
	 * A disabled feature registers no metabox hooks.
	 */
	public function test_disabled_register_wires_no_metabox() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_content_assistant_metabox' => false ) );

		$before = has_action( 'add_meta_boxes' );

		ContentAssistantBootstrap::register();

		$this->assertSame( $before, has_action( 'add_meta_boxes' ) );
	}

	/**
	 * An enabled feature registers the metabox add_meta_boxes and
	 * admin_enqueue_scripts hooks.
	 */
	public function test_enabled_register_wires_the_metabox() {
		ContentAssistantBootstrap::register();

		$this->assertNotFalse( has_action( 'add_meta_boxes' ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts' ) );
	}

	// Metabox identity + post types.

	/**
	 * The metabox ID is byte-identical to the base plugin's.
	 */
	public function test_metabox_id_matches_the_base() {
		$this->assertSame( 'wp_mcp_ai_content_assistant', ContentAssistantMetabox::METABOX_ID );
	}

	/**
	 * The enabled post-type set defaults to post + page.
	 */
	public function test_post_types_default_to_post_and_page() {
		$this->assertSame( array( 'post', 'page' ), $this->metabox->seam_get_enabled_post_types() );
	}

	/**
	 * The settings post-type list overrides the default.
	 */
	public function test_post_types_follow_settings() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'content_assistant_post_types' => array( 'post', 'product' ) )
		);

		$this->assertSame( array( 'post', 'product' ), $this->metabox->seam_get_enabled_post_types() );
	}

	/**
	 * A non-array settings value falls back to the default list.
	 */
	public function test_post_types_fall_back_on_non_array_setting() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'content_assistant_post_types' => 'post' )
		);

		$this->assertSame( array( 'post', 'page' ), $this->metabox->seam_get_enabled_post_types() );
	}

	/**
	 * The post-types filter wins over both settings and defaults.
	 */
	public function test_post_types_filter_wins() {
		add_filter(
			'wp_mcp_ai_content_assistant_post_types',
			function () {
				return array( 'attachment' );
			}
		);

		$this->assertSame( array( 'attachment' ), $this->metabox->seam_get_enabled_post_types() );
	}

	// Metabox registration.

	/**
	 * The metabox registers on every enabled post type.
	 */
	public function test_metabox_registers_on_enabled_post_types() {
		global $wp_meta_boxes;

		$this->metabox->register_metabox();

		$this->assertIsArray( $wp_meta_boxes );
		$this->assertArrayHasKey( ContentAssistantMetabox::METABOX_ID, $wp_meta_boxes['post']['side']['high'] );
		$this->assertArrayHasKey( ContentAssistantMetabox::METABOX_ID, $wp_meta_boxes['page']['side']['high'] );
	}

	// Render gates.

	/**
	 * Users without edit_post permission see the permission message.
	 */
	public function test_render_gates_on_edit_post_capability() {
		$editor = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post   = self::factory()->post->create_and_get( array( 'post_author' => $editor ) );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		ob_start();
		$this->metabox->render( $post );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'You do not have permission to use this feature.', $output );
	}

	/**
	 * A disabled feature renders the not-enabled message.
	 */
	public function test_render_gates_on_the_settings_toggle() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_content_assistant_metabox' => false ) );

		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post = self::factory()->post->create_and_get( array( 'post_author' => $user ) );
		wp_set_current_user( $user );

		ob_start();
		$this->metabox->render( $post );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'AI Content Assistant is not enabled.', $output );
	}

	/**
	 * With no published assistants the render shows the create-first message.
	 */
	public function test_render_shows_no_assistants_message() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post = self::factory()->post->create_and_get( array( 'post_author' => $user ) );
		wp_set_current_user( $user );

		ob_start();
		$this->metabox->render( $post );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'No AI assistants available. Please create an assistant first.', $output );
	}

	/**
	 * The full render carries the assistant selector, the quick actions, the
	 * open button, and the modal markup.
	 */
	public function test_full_render_carries_the_assistant_ui() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post = self::factory()->post->create_and_get( array( 'post_author' => $user ) );

		self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Content Writer',
			)
		);

		wp_set_current_user( $user );

		ob_start();
		$this->metabox->render( $post );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Select Assistant:', $output );
		$this->assertStringContainsString( 'Content Writer', $output );
		$this->assertStringContainsString( 'Quick AI Actions:', $output );
		$this->assertStringContainsString( 'Improve Content', $output );
		$this->assertStringContainsString( 'Open AI Assistant', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-content-assistant-modal', $output );
		$this->assertStringContainsString( 'wp_mcp_ai_content_assistant_nonce', $output );
	}

	// Quick actions.

	/**
	 * The quick-action registry ships the six documented actions.
	 */
	public function test_quick_actions_ship_six_defaults() {
		$actions = $this->metabox->seam_get_quick_actions();

		$this->assertCount( 6, $actions );

		$slugs = wp_list_pluck( $actions, 'slug' );

		$this->assertSame(
			array( 'improve_content', 'generate_outline', 'seo_optimize', 'rewrite', 'expand', 'summarize' ),
			$slugs
		);
	}

	/**
	 * The quick-actions filter replaces the registry.
	 */
	public function test_quick_actions_filter_wins() {
		add_filter(
			'wp_mcp_ai_content_assistant_quick_actions',
			function () {
				return array(
					array(
						'slug'  => 'custom_action',
						'label' => 'Custom Action',
						'icon'  => 'star-filled',
					),
				);
			}
		);

		$actions = $this->metabox->seam_get_quick_actions();

		$this->assertCount( 1, $actions );
		$this->assertSame( 'custom_action', $actions[0]['slug'] );
	}

	// Assistant enumeration.

	/**
	 * Only published assistants are enumerated, newest-title order aside.
	 */
	public function test_assistant_enumeration_returns_published_only() {
		self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Published Assistant',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'draft',
				'post_title'  => 'Draft Assistant',
			)
		);

		$assistants = $this->metabox->seam_get_available_assistants();

		$this->assertCount( 1, $assistants );
		$this->assertSame( 'Published Assistant', $assistants[0]['title'] );
	}

	// Context helpers.

	/**
	 * The context-data envelope carries every documented field.
	 */
	public function test_context_data_carries_the_post_fields() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Context Title',
				'post_content' => 'Context Body',
				'post_excerpt' => 'Context Excerpt',
			)
		);

		$context = $this->metabox->seam_get_context_data( $post );

		$this->assertSame( $post->ID, $context['post_id'] );
		$this->assertSame( 'post', $context['post_type'] );
		$this->assertSame( 'Context Title', $context['post_title'] );
		$this->assertSame( 'Context Body', $context['post_content'] );
		$this->assertSame( 'Context Excerpt', $context['post_excerpt'] );
	}

	/**
	 * Titles and placeholders derive from the post-type labels.
	 */
	public function test_titles_and_placeholders_follow_post_type_labels() {
		$this->assertSame( 'Post AI Assistant', $this->metabox->seam_get_assistant_title( 'post' ) );
		$this->assertSame( 'Ask me about this post...', $this->metabox->seam_get_placeholder_text( 'post' ) );
		$this->assertSame( 'Content AI Assistant', $this->metabox->seam_get_assistant_title( 'unknown_type' ) );
	}

	// Asset enqueue.

	/**
	 * Non-edit-screen hooks never enqueue assets.
	 */
	public function test_enqueue_skips_non_edit_hooks() {
		$this->metabox->enqueue_assets( 'index.php' );

		$this->assertFalse( wp_style_is( 'wp-mcp-ai-content-assistant', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'wp-mcp-ai-content-assistant', 'enqueued' ) );
	}

	/**
	 * The metabox's own assets and localization enqueue on the post edit
	 * screen in both modes. The chat bundle itself is monolith-only.
	 */
	public function test_enqueue_serves_own_assets_in_both_modes() {
		global $post;

		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post = self::factory()->post->create_and_get( array( 'post_author' => $user ) );

		$this->metabox->enqueue_assets( 'post.php' );

		$this->assertTrue( wp_style_is( 'wp-mcp-ai-content-assistant', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-content-assistant', 'enqueued' ) );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the base chat bundle is registered and enqueued.
			$this->assertTrue( wp_style_is( 'wp-mcp-ai-chat', 'enqueued' ) );
			$this->assertTrue( wp_script_is( 'wp-mcp-ai-chat', 'enqueued' ) );

			$styles  = wp_styles();
			$content = $styles->registered['wp-mcp-ai-content-assistant'];
			$this->assertContains( 'wp-mcp-ai-chat', $content->deps );
		} else {
			// Standalone: no base shortcode, so the chat bundle is skipped.
			$this->assertFalse( wp_style_is( 'wp-mcp-ai-chat', 'enqueued' ) );
			$this->assertFalse( wp_script_is( 'wp-mcp-ai-chat', 'enqueued' ) );

			$content = wp_styles()->registered['wp-mcp-ai-content-assistant'];
			$this->assertSame( array(), $content->deps );
		}

		// The localization envelope is attached to the own-script handle.
		$scripts = wp_scripts();
		$this->assertIsObject( $scripts->registered['wp-mcp-ai-content-assistant'] );
		$this->assertStringContainsString( 'wpMcpAiContentAssistant', (string) $scripts->get_data( 'wp-mcp-ai-content-assistant', 'data' ) );
	}
}
