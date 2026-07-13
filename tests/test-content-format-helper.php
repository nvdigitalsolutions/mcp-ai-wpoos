<?php
/**
 * Tests for the WP_MCP_AI_Content_Format_Helper class.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test content format helper functionality.
 */
class Test_Content_Format_Helper extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Test post IDs.
	 *
	 * @var array
	 */
	private $post_ids = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Create test posts for various format scenarios.
		$this->post_ids['block_post'] = $this->factory->post->create(
			array(
				'post_title'   => 'Block Editor Post',
				'post_content' => '<!-- wp:paragraph --><p>Block content.</p><!-- /wp:paragraph -->',
				'post_type'    => 'post',
				'post_status'  => 'publish',
			)
		);

		$this->post_ids['classic_post'] = $this->factory->post->create(
			array(
				'post_title'   => 'Classic Editor Post',
				'post_content' => '<p>Plain HTML content without blocks.</p>',
				'post_type'    => 'post',
				'post_status'  => 'publish',
			)
		);

		$this->post_ids['elementor_page'] = $this->factory->post->create(
			array(
				'post_title'   => 'Elementor Page',
				'post_content' => '',
				'post_type'    => 'page',
				'post_status'  => 'publish',
			)
		);

		// Mark one post as Elementor.
		update_post_meta( $this->post_ids['elementor_page'], '_elementor_edit_mode', 'builder' );
		update_post_meta(
			$this->post_ids['elementor_page'],
			'_elementor_data',
			wp_json_encode(
				array(
					'title'         => '',
					'type'          => 'page',
					'version'       => '0.4',
					'page_settings' => array(),
					'content'       => array(
						array(
							'id'       => 'abc12345',
							'elType'   => 'container',
							'isInner'  => false,
							'settings' => array(),
							'elements' => array(
								array(
									'id'         => 'def67890',
									'elType'     => 'widget',
									'widgetType' => 'heading',
									'isInner'    => false,
									'settings'   => array(
										'title' => 'Welcome to Our Site',
									),
									'elements'   => array(),
								),
								array(
									'id'         => 'ghi11111',
									'elType'     => 'widget',
									'widgetType' => 'text-editor',
									'isInner'    => false,
									'settings'   => array(
										'editor' => '<p>This is Elementor content with <strong>rich text</strong>.</p>',
									),
									'elements'   => array(),
								),
							),
						),
					),
				)
			)
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		parent::tearDown();
	}

	// ---------------------------------------------------------------------------
	// detect_post_format
	// ---------------------------------------------------------------------------

	/**
	 * Test detect_post_format returns block-editor for standard posts.
	 */
	public function test_detect_post_format_block_editor() {
		$format = WP_MCP_AI_Content_Format_Helper::detect_post_format( $this->post_ids['block_post'] );
		$this->assertSame( WP_MCP_AI_Content_Format_Helper::FORMAT_BLOCK_EDITOR, $format );
	}

	/**
	 * Test detect_post_format returns elementor for Elementor pages.
	 */
	public function test_detect_post_format_elementor() {
		$format = WP_MCP_AI_Content_Format_Helper::detect_post_format( $this->post_ids['elementor_page'] );
		$this->assertSame( WP_MCP_AI_Content_Format_Helper::FORMAT_ELEMENTOR, $format );
	}

	/**
	 * Test detect_post_format returns block-editor for non-Elementor pages.
	 */
	public function test_detect_post_format_block_for_page_without_elementor() {
		$page_id = $this->factory->post->create(
			array(
				'post_title'   => 'Regular Page',
				'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
				'post_type'    => 'page',
				'post_status'  => 'publish',
			)
		);

		$format = WP_MCP_AI_Content_Format_Helper::detect_post_format( $page_id );
		$this->assertSame( WP_MCP_AI_Content_Format_Helper::FORMAT_BLOCK_EDITOR, $format );

		wp_delete_post( $page_id, true );
	}

	/**
	 * Test detect_post_format returns block-editor for invalid post ID.
	 */
	public function test_detect_post_format_zero_id() {
		$format = WP_MCP_AI_Content_Format_Helper::detect_post_format( 0 );
		$this->assertSame( WP_MCP_AI_Content_Format_Helper::FORMAT_BLOCK_EDITOR, $format );
	}

	// ---------------------------------------------------------------------------
	// is_elementor_post / is_block_editor_post
	// ---------------------------------------------------------------------------

	/**
	 * Test is_elementor_post returns true for Elementor pages.
	 */
	public function test_is_elementor_post_true() {
		$this->assertTrue( WP_MCP_AI_Content_Format_Helper::is_elementor_post( $this->post_ids['elementor_page'] ) );
	}

	/**
	 * Test is_elementor_post returns false for block posts.
	 */
	public function test_is_elementor_post_false() {
		$this->assertFalse( WP_MCP_AI_Content_Format_Helper::is_elementor_post( $this->post_ids['block_post'] ) );
	}

	/**
	 * Test is_block_editor_post returns true for block posts.
	 */
	public function test_is_block_editor_post_true() {
		$this->assertTrue( WP_MCP_AI_Content_Format_Helper::is_block_editor_post( $this->post_ids['block_post'] ) );
	}

	/**
	 * Test is_block_editor_post returns false for Elementor pages.
	 */
	public function test_is_block_editor_post_false() {
		$this->assertFalse( WP_MCP_AI_Content_Format_Helper::is_block_editor_post( $this->post_ids['elementor_page'] ) );
	}

	// ---------------------------------------------------------------------------
	// extract_readable_text
	// ---------------------------------------------------------------------------

	/**
	 * Test extract_readable_text for block editor posts.
	 */
	public function test_extract_readable_text_block_post() {
		$text = WP_MCP_AI_Content_Format_Helper::extract_readable_text( $this->post_ids['block_post'] );
		$this->assertStringContainsString( 'Block content.', $text );
	}

	/**
	 * Test extract_readable_text for classic posts.
	 */
	public function test_extract_readable_text_classic_post() {
		$text = WP_MCP_AI_Content_Format_Helper::extract_readable_text( $this->post_ids['classic_post'] );
		$this->assertStringContainsString( 'Plain HTML content without blocks.', $text );
	}

	/**
	 * Test extract_readable_text for Elementor pages.
	 */
	public function test_extract_readable_text_elementor_page() {
		$text = WP_MCP_AI_Content_Format_Helper::extract_readable_text( $this->post_ids['elementor_page'] );
		$this->assertStringContainsString( 'Welcome to Our Site', $text );
		$this->assertStringContainsString( 'This is Elementor content with rich text.', $text );
	}

	// ---------------------------------------------------------------------------
	// extract_text_from_elementor_json
	// ---------------------------------------------------------------------------

	/**
	 * Test extract_text_from_elementor_json with empty input.
	 */
	public function test_extract_text_from_elementor_json_empty() {
		$text = WP_MCP_AI_Content_Format_Helper::extract_text_from_elementor_json( '' );
		$this->assertSame( '', $text );
	}

	/**
	 * Test extract_text_from_elementor_json with string JSON.
	 */
	public function test_extract_text_from_elementor_json_string() {
		$json = wp_json_encode(
			array(
				'content' => array(
					array(
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => array( 'title' => 'Hello World' ),
						'elements'   => array(),
					),
				),
			)
		);

		$text = WP_MCP_AI_Content_Format_Helper::extract_text_from_elementor_json( $json );
		$this->assertStringContainsString( 'Hello World', $text );
	}

	/**
	 * Test extract_text_from_elementor_json with null/invalid input.
	 */
	public function test_extract_text_from_elementor_json_null() {
		$text = WP_MCP_AI_Content_Format_Helper::extract_text_from_elementor_json( null );
		$this->assertSame( '', $text );
	}

	/**
	 * Test extract_text_from_elementor_json with malformed JSON.
	 */
	public function test_extract_text_from_elementor_json_malformed() {
		$text = WP_MCP_AI_Content_Format_Helper::extract_text_from_elementor_json( '{not valid json' );
		$this->assertSame( '', $text );
	}

	/**
	 * Test extract_text_from_elementor_json with button widget.
	 */
	public function test_extract_text_button_widget() {
		$data = array(
			'content' => array(
				array(
					'elType'     => 'widget',
					'widgetType' => 'button',
					'settings'   => array( 'text' => 'Click Me' ),
					'elements'   => array(),
				),
			),
		);

		$text = WP_MCP_AI_Content_Format_Helper::extract_text_from_elementor_json( $data );
		$this->assertStringContainsString( 'Click Me', $text );
	}

	/**
	 * Test extract_text_from_elementor_json with icon-box widget.
	 */
	public function test_extract_text_icon_box_widget() {
		$data = array(
			'content' => array(
				array(
					'elType'     => 'widget',
					'widgetType' => 'icon-box',
					'settings'   => array(
						'title_text'       => 'Feature Title',
						'description_text' => '<p>Feature description here.</p>',
					),
					'elements'   => array(),
				),
			),
		);

		$text = WP_MCP_AI_Content_Format_Helper::extract_text_from_elementor_json( $data );
		$this->assertStringContainsString( 'Feature Title', $text );
		$this->assertStringContainsString( 'Feature description here.', $text );
	}

	/**
	 * Test extract_text_from_elementor_json with accordion widget.
	 */
	public function test_extract_text_accordion_widget() {
		$data = array(
			'content' => array(
				array(
					'elType'     => 'widget',
					'widgetType' => 'accordion',
					'settings'   => array(
						'tabs' => array(
							array(
								'tab_title'   => 'Question 1',
								'tab_content' => '<p>Answer 1</p>',
							),
							array(
								'tab_title'   => 'Question 2',
								'tab_content' => '<p>Answer 2</p>',
							),
						),
					),
					'elements'   => array(),
				),
			),
		);

		$text = WP_MCP_AI_Content_Format_Helper::extract_text_from_elementor_json( $data );
		$this->assertStringContainsString( 'Question 1', $text );
		$this->assertStringContainsString( 'Answer 1', $text );
		$this->assertStringContainsString( 'Question 2', $text );
		$this->assertStringContainsString( 'Answer 2', $text );
	}

	/**
	 * Test extract_text_from_elementor_json with icon-list widget.
	 */
	public function test_extract_text_icon_list_widget() {
		$data = array(
			'content' => array(
				array(
					'elType'     => 'widget',
					'widgetType' => 'icon-list',
					'settings'   => array(
						'icon_list' => array(
							array( 'text' => 'Item One' ),
							array( 'text' => 'Item Two' ),
						),
					),
					'elements'   => array(),
				),
			),
		);

		$text = WP_MCP_AI_Content_Format_Helper::extract_text_from_elementor_json( $data );
		$this->assertStringContainsString( 'Item One', $text );
		$this->assertStringContainsString( 'Item Two', $text );
	}

	/**
	 * Test extract_text_from_elementor_json with unknown widget (fallback scan).
	 */
	public function test_extract_text_unknown_widget_fallback() {
		$data = array(
			'content' => array(
				array(
					'elType'     => 'widget',
					'widgetType' => 'some_future_widget',
					'settings'   => array(
						'title'       => 'Future Widget Title',
						'description' => '<p>Future description</p>',
					),
					'elements'   => array(),
				),
			),
		);

		$text = WP_MCP_AI_Content_Format_Helper::extract_text_from_elementor_json( $data );
		$this->assertStringContainsString( 'Future Widget Title', $text );
		$this->assertStringContainsString( 'Future description', $text );
	}

	/**
	 * Test extract_text_from_elementor_json with nested containers.
	 */
	public function test_extract_text_nested_containers() {
		$data = array(
			'content' => array(
				array(
					'elType'   => 'container',
					'settings' => array(),
					'elements' => array(
						array(
							'elType'   => 'container',
							'settings' => array(),
							'elements' => array(
								array(
									'elType'     => 'widget',
									'widgetType' => 'heading',
									'settings'   => array( 'title' => 'Deeply Nested Heading' ),
									'elements'   => array(),
								),
							),
						),
					),
				),
			),
		);

		$text = WP_MCP_AI_Content_Format_Helper::extract_text_from_elementor_json( $data );
		$this->assertStringContainsString( 'Deeply Nested Heading', $text );
	}

	// ---------------------------------------------------------------------------
	// detect_seo_plugin
	// ---------------------------------------------------------------------------

	/**
	 * Test detect_seo_plugin returns none when no SEO plugin is active.
	 */
	public function test_detect_seo_plugin_none() {
		// In a test environment, no SEO plugin is active by default.
		// We skip the cache by not having called it yet.
		$result = WP_MCP_AI_Content_Format_Helper::detect_seo_plugin();
		$this->assertSame( WP_MCP_AI_Content_Format_Helper::SEO_NONE, $result );
	}

	// ---------------------------------------------------------------------------
	// get_seo_meta_keys
	// ---------------------------------------------------------------------------

	/**
	 * Test get_seo_meta_keys for Rank Math.
	 */
	public function test_get_seo_meta_keys_rank_math() {
		$keys = WP_MCP_AI_Content_Format_Helper::get_seo_meta_keys( 'rank_math' );
		$this->assertSame( 'rank_math_title', $keys['title'] );
		$this->assertSame( 'rank_math_description', $keys['description'] );
		$this->assertSame( 'rank_math_focus_keyword', $keys['focus_keyword'] );
	}

	/**
	 * Test get_seo_meta_keys for Yoast.
	 */
	public function test_get_seo_meta_keys_yoast() {
		$keys = WP_MCP_AI_Content_Format_Helper::get_seo_meta_keys( 'yoast' );
		$this->assertSame( '_yoast_wpseo_title', $keys['title'] );
		$this->assertSame( '_yoast_wpseo_metadesc', $keys['description'] );
		$this->assertSame( '_yoast_wpseo_focuskw', $keys['focus_keyword'] );
	}

	/**
	 * Test get_seo_meta_keys for SEOPress.
	 */
	public function test_get_seo_meta_keys_seopress() {
		$keys = WP_MCP_AI_Content_Format_Helper::get_seo_meta_keys( 'seopress' );
		$this->assertSame( '_seopress_titles_title', $keys['title'] );
		$this->assertSame( '_seopress_titles_desc', $keys['description'] );
		$this->assertSame( '_seopress_analysis_target_kw', $keys['focus_keyword'] );
	}

	/**
	 * Test get_seo_meta_keys for none (fallback custom keys).
	 */
	public function test_get_seo_meta_keys_none() {
		$keys = WP_MCP_AI_Content_Format_Helper::get_seo_meta_keys( 'none' );
		$this->assertSame( '_wp_mcp_ai_seo_title', $keys['title'] );
		$this->assertSame( '_wp_mcp_ai_meta_description', $keys['description'] );
		$this->assertSame( '_wp_mcp_ai_focus_keyword', $keys['focus_keyword'] );
	}

	/**
	 * Test get_seo_meta_keys defaults to auto-detection when no plugin specified.
	 */
	public function test_get_seo_meta_keys_auto_detect() {
		$keys = WP_MCP_AI_Content_Format_Helper::get_seo_meta_keys();
		$this->assertIsArray( $keys );
		$this->assertArrayHasKey( 'title', $keys );
		$this->assertArrayHasKey( 'description', $keys );
		$this->assertArrayHasKey( 'focus_keyword', $keys );
	}

	// ---------------------------------------------------------------------------
	// get_seo_plugin_name
	// ---------------------------------------------------------------------------

	/**
	 * Test get_seo_plugin_name returns 'None' when no plugin is active.
	 */
	public function test_get_seo_plugin_name_none() {
		$this->assertSame( 'None', WP_MCP_AI_Content_Format_Helper::get_seo_plugin_name() );
	}

	// ---------------------------------------------------------------------------
	// validate_format
	// ---------------------------------------------------------------------------

	/**
	 * Test validate_format returns valid format unchanged.
	 */
	public function test_validate_format_valid() {
		$this->assertSame( 'block-editor', WP_MCP_AI_Content_Format_Helper::validate_format( 'block-editor' ) );
		$this->assertSame( 'classic-editor', WP_MCP_AI_Content_Format_Helper::validate_format( 'classic-editor' ) );
		$this->assertSame( 'elementor', WP_MCP_AI_Content_Format_Helper::validate_format( 'elementor' ) );
		$this->assertSame( 'auto', WP_MCP_AI_Content_Format_Helper::validate_format( 'auto' ) );
	}

	/**
	 * Test validate_format returns block-editor for invalid format.
	 */
	public function test_validate_format_invalid() {
		$this->assertSame( 'block-editor', WP_MCP_AI_Content_Format_Helper::validate_format( 'invalid-format' ) );
		$this->assertSame( 'block-editor', WP_MCP_AI_Content_Format_Helper::validate_format( '' ) );
	}

	// ---------------------------------------------------------------------------
	// resolve_format
	// ---------------------------------------------------------------------------

	/**
	 * Test resolve_format returns explicit format when not auto.
	 */
	public function test_resolve_format_explicit() {
		$format = WP_MCP_AI_Content_Format_Helper::resolve_format( 'classic-editor', $this->post_ids['elementor_page'] );
		$this->assertSame( 'classic-editor', $format );
	}

	/**
	 * Test resolve_format with auto detects Elementor format.
	 */
	public function test_resolve_format_auto_detects_elementor() {
		$format = WP_MCP_AI_Content_Format_Helper::resolve_format( 'auto', $this->post_ids['elementor_page'] );
		$this->assertSame( 'elementor', $format );
	}

	/**
	 * Test resolve_format with auto and no post ID defaults to block-editor.
	 */
	public function test_resolve_format_auto_no_post_id() {
		$format = WP_MCP_AI_Content_Format_Helper::resolve_format( 'auto', null );
		$this->assertSame( 'block-editor', $format );
	}

	/**
	 * Test resolve_format with auto detects block for standard posts.
	 */
	public function test_resolve_format_auto_detects_block() {
		$format = WP_MCP_AI_Content_Format_Helper::resolve_format( 'auto', $this->post_ids['block_post'] );
		$this->assertSame( 'block-editor', $format );
	}

	// ---------------------------------------------------------------------------
	// is_elementor_active
	// ---------------------------------------------------------------------------

	/**
	 * Test is_elementor_active returns false when Elementor is not loaded.
	 */
	public function test_is_elementor_active_false() {
		// In a test environment, Elementor is not active.
		$this->assertFalse( WP_MCP_AI_Content_Format_Helper::is_elementor_active() );
	}
}
