<?php
/**
 * Chat-family block tests (Wave D-UI-2).
 *
 * Characterization suite for the ecosystem blocks hub
 * (`NvoosContentGraphAi\Blocks`): hub registration, idempotency, the
 * block category, the chat block's attribute→shortcode mapping, the
 * bubble block's markup/CSS-variable/asset surface, and input
 * sanitisation. Both blocks are server-rendered wrappers around the
 * `[nvoos_content_graph_chat]` widget (D-UI-1b), so the shortcode is
 * registered here as the render target.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Blocks\Blocks;
use NvoosContentGraphAi\Blocks\ChatBlock;
use NvoosContentGraphAi\Blocks\ChatBubbleBlock;
use NvoosContentGraphAi\Frontend\ChatShortcode;

/**
 * @group blocks
 */
class Test_Blocks extends \WP_UnitTestCase {

	/**
	 * Blocks hub under test.
	 *
	 * @var Blocks
	 */
	private $blocks;

	/**
	 * Chat widget shortcode (render target for both blocks).
	 *
	 * @var ChatShortcode
	 */
	private $shortcode;

	public function setUp(): void {
		parent::setUp();

		// Isolate the script/style registries — wp_scripts()/wp_styles()
		// are process-global singletons and inline-script data from other
		// tests would bleed in.
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;

		if ( ! \post_type_exists( 'mcp_ai_assistant' ) ) {
			\register_post_type( 'mcp_ai_assistant', array( 'public' => true ) );
		}

		// The block render callbacks embed `[nvoos_content_graph_chat]`;
		// register the real shortcode so do_shortcode() produces the
		// widget markup (the plugin bootstrap never runs under the
		// ecosystem test window).
		$this->shortcode = new ChatShortcode();
		$this->shortcode->register();

		$this->blocks = new Blocks();
	}

	public function tearDown(): void {
		\wp_dequeue_script( Blocks::BUBBLE_SCRIPT_HANDLE );
		\wp_dequeue_style( Blocks::BUBBLE_STYLE_HANDLE );
		\wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Extract the injected frontend config from the registered inline
	 * script of the chat widget (the block render target).
	 *
	 * @return array|null Decoded config or null when absent.
	 */
	private function injected_config(): ?array {
		$scripts = \wp_scripts();

		$registered = isset( $scripts->registered[ ChatShortcode::SCRIPT_HANDLE ] ) ? $scripts->registered[ ChatShortcode::SCRIPT_HANDLE ] : null;
		if ( ! $registered ) {
			return null;
		}

		$data = $registered->extra['before'] ?? array();
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		foreach ( $data as $chunk ) {
			if ( ! is_string( $chunk ) ) {
				continue; // WP may park sentinel values in the extra stack.
			}
			if ( false === strpos( $chunk, 'window.NvoosContentGraphChat.push(' ) ) {
				continue;
			}
			if ( 1 === preg_match( '/push\(\s*(\{.*\})\s*\)\s*;/s', $chunk, $matches ) ) {
				$decoded = json_decode( $matches[1], true );
				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
		}

		return null;
	}

	/**
	 * Build a WP_Block instance for the render callbacks.
	 *
	 * @param string $name  Block name.
	 * @param array  $attrs Block attributes.
	 * @return \WP_Block
	 */
	private function block_instance( string $name, array $attrs = array() ): \WP_Block {
		return new \WP_Block(
			array(
				'blockName' => $name,
				'attrs'     => $attrs,
				'innerHTML' => '',
			)
		);
	}

	// ─── Hub registration + idempotency ─────────────────────────────

	public function test_blocks_hub_constants_and_registration(): void {
		$this->assertSame( 'nvoos-content-graph-ai', Blocks::CATEGORY );
		$this->assertSame( 'nvoos-content-graph-ai-chat-bubble', Blocks::BUBBLE_SCRIPT_HANDLE );
		$this->assertSame( 'nvoos-content-graph-ai-chat-bubble-style', Blocks::BUBBLE_STYLE_HANDLE );
		$this->assertSame( 'nvoos-content-graph-ai-blocks', Blocks::EDITOR_SCRIPT_HANDLE );

		$this->blocks->register();
		$this->blocks->register_blocks();

		$registry = \WP_Block_Type_Registry::get_instance();

		$this->assertTrue( $registry->is_registered( ChatBlock::BLOCK_NAME ) );
		$this->assertTrue( $registry->is_registered( ChatBubbleBlock::BLOCK_NAME ) );

		$chat = $registry->get_registered( ChatBlock::BLOCK_NAME );
		$this->assertSame( array( ChatBlock::class, 'render' ), $chat->render_callback );

		$bubble = $registry->get_registered( ChatBubbleBlock::BLOCK_NAME );
		$this->assertSame( array( ChatBubbleBlock::class, 'render' ), $bubble->render_callback );

		// Bubble assets are registered (not yet enqueued) by the hub.
		$this->assertTrue( \wp_script_is( Blocks::BUBBLE_SCRIPT_HANDLE, 'registered' ) );
		$this->assertTrue( \wp_style_is( Blocks::BUBBLE_STYLE_HANDLE, 'registered' ) );

		// Editor script enqueues with the Gutenberg dependency set.
		$this->blocks->enqueue_editor_assets();
		$this->assertTrue( \wp_script_is( Blocks::EDITOR_SCRIPT_HANDLE, 'enqueued' ) );

		$editor = \wp_scripts()->registered[ Blocks::EDITOR_SCRIPT_HANDLE ];
		$this->assertContains( 'wp-blocks', $editor->deps );
		$this->assertContains( 'wp-block-editor', $editor->deps );
		$this->assertContains( 'wp-i18n', $editor->deps );
	}

	public function test_blocks_registration_idempotent(): void {
		$registry = \WP_Block_Type_Registry::get_instance();

		$this->blocks->register_blocks();
		$count = count( $registry->get_all_registered() );

		// A second pass must not fatal or duplicate registrations.
		$this->blocks->register_blocks();
		$this->assertSame( $count, count( $registry->get_all_registered() ) );
	}

	public function test_block_category_filter_adds_category_once(): void {
		$this->blocks->register();

		$categories = \apply_filters( 'block_categories_all', array(), null );

		$slugs = wp_list_pluck( $categories, 'slug' );
		$this->assertContains( Blocks::CATEGORY, $slugs );

		// Re-applying with the result must not duplicate the category.
		$again   = \apply_filters( 'block_categories_all', $categories, null );
		$slugs2  = wp_list_pluck( $again, 'slug' );
		$this->assertSame( count( $slugs ), count( $slugs2 ) );
	}

	// ─── Chat block ─────────────────────────────────────────────────

	public function test_chat_block_metadata_defaults(): void {
		$meta = ChatBlock::metadata();

		$this->assertSame( 3, $meta['apiVersion'] );
		$this->assertSame( 'nvoos-content-graph-ai', $meta['category'] );
		$this->assertSame( 0, $meta['attributes']['assistantId']['default'] );
		$this->assertFalse( $meta['attributes']['allowGuests']['default'] );
		$this->assertSame( '500px', $meta['attributes']['height']['default'] );
		$this->assertTrue( $meta['attributes']['showCost']['default'] );
		$this->assertSame( '', $meta['attributes']['placeholder']['default'] );
		$this->assertFalse( $meta['supports']['html'] );
		$this->assertTrue( $meta['supports']['anchor'] );
	}

	public function test_chat_block_render_embeds_widget(): void {
		$html = ChatBlock::render(
			array( 'height' => '320px' ),
			'',
			$this->block_instance( ChatBlock::BLOCK_NAME )
		);

		$this->assertStringContainsString( 'wp-block-nvoos-content-graph-ai-chat', $html );
		// The real widget markup is embedded via do_shortcode().
		$this->assertStringContainsString( 'nvoos-content-graph-chat-widget', $html );
		$this->assertStringContainsString( 'height:320px', $html );
	}

	public function test_chat_block_render_attribute_mapping(): void {
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);

		ChatBlock::render(
			array(
				'assistantId' => $assistant_id,
				'allowGuests' => true,
				'provider'    => 'gemini',
				'model'       => 'gemini-1.5-pro',
				'height'      => '400px',
				'showCost'    => false,
				'placeholder' => 'Ask me anything',
			),
			'',
			$this->block_instance( ChatBlock::BLOCK_NAME )
		);

		$config = $this->injected_config();

		$this->assertNotNull( $config );
		$this->assertSame( 'gemini', $config['provider'] );
		$this->assertSame( 'gemini-1.5-pro', $config['model'] );
		$this->assertFalse( $config['showCost'] );
		$this->assertSame( 'Ask me anything', $config['placeholder'] );

		// allowGuests + a resolvable assistantId issues a guest token.
		$this->assertNotSame( '', $config['guestToken'] );
		$this->assertSame(
			$assistant_id,
			\NvoosContentGraphAi\Chat\GuestToken::validate_guest_token( $config['guestToken'] )
		);
	}

	// ─── Chat bubble block ──────────────────────────────────────────

	public function test_bubble_block_render_markup_and_assets(): void {
		$html = ChatBubbleBlock::render(
			array(
				'bubblePosition'   => 'top-left',
				'bubbleSize'       => 'large',
				'bubbleAnimation'  => 'none',
				'bubbleTooltip'    => 'Need help?',
				'panelTitle'       => 'Support',
				'panelWidth'       => 600,
				'panelHeight'      => 700,
				'bubbleColor'      => '#123456',
				'headerBackground' => '#abcdef',
			),
			'',
			$this->block_instance( ChatBubbleBlock::BLOCK_NAME )
		);

		$this->assertStringContainsString( 'wp-block-nvoos-content-graph-ai-chat-bubble', $html );

		// Position/size classes; animation class suppressed when "none".
		$this->assertStringContainsString( 'nvoos-cg-bubble--top-left', $html );
		$this->assertStringContainsString( 'nvoos-cg-bubble--large', $html );
		$this->assertStringNotContainsString( 'nvoos-cg-bubble--bounce', $html );

		// CSS custom properties only for non-default values.
		$this->assertStringContainsString( '--nvoos-cg-bubble-color:#123456', $html );
		$this->assertStringContainsString( '--nvoos-cg-bubble-header-background:#abcdef', $html );
		$this->assertStringContainsString( '--nvoos-cg-bubble-panel-width:600px', $html );
		$this->assertStringContainsString( '--nvoos-cg-bubble-panel-height:700px', $html );

		// Data attributes + tooltip + panel title.
		$this->assertStringContainsString( 'data-remember-state="false"', $html );
		$this->assertStringContainsString( 'Need help?', $html );
		$this->assertStringContainsString( 'Support', $html );
		$this->assertMatchesRegularExpression( '/id="nvoos-cg-bubble-\d+"/', $html );

		// Embedded widget + bubble assets enqueued.
		$this->assertStringContainsString( 'nvoos-content-graph-chat-widget', $html );
		$this->assertTrue( \wp_script_is( Blocks::BUBBLE_SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertTrue( \wp_style_is( Blocks::BUBBLE_STYLE_HANDLE, 'enqueued' ) );
	}

	public function test_bubble_block_render_sanitizes_input(): void {
		$html = ChatBubbleBlock::render(
			array(
				'bubbleColor'   => 'red;background:url(evil)',
				'bubbleTooltip' => '<script>alert(1)</script>Hello',
				'panelTitle'    => '<b>Hi</b>',
			),
			'',
			$this->block_instance( ChatBubbleBlock::BLOCK_NAME )
		);

		// Invalid hex colours never reach the style attribute.
		$this->assertStringNotContainsString( 'red;background', $html );
		// Tags are stripped from the tooltip; markup is escaped in the title.
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'Hello', $html );
		$this->assertStringNotContainsString( '<b>Hi</b>', $html );
		$this->assertStringContainsString( '&lt;b&gt;Hi&lt;/b&gt;', $html );
	}
}
