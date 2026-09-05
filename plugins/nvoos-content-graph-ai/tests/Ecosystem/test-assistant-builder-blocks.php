<?php
/**
 * Assistant builder block-set tests (Wave D-UI-4 close-out).
 *
 * Characterization suite for the ported assistant blocks
 * (`AssistantSelectorBlock`, `ToolsGridBlock`, `KnowledgeBaseBlock`,
 * `AssistantBuilderBlock`): metadata defaults, hub registration +
 * idempotency, capability gates, render markup, the per-install-mode
 * tool-registry seam, the builder's create-config contract, and the
 * Build Assistant Prompt tab embedding. Assertions mirror the base
 * plugin's `assistant-selector` / `tools-grid` / `knowledge-base` /
 * `assistant-builder` block surface.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Blocks\AssistantBuilderBlock;
use NvoosContentGraphAi\Blocks\AssistantSelectorBlock;
use NvoosContentGraphAi\Blocks\Blocks;
use NvoosContentGraphAi\Blocks\KnowledgeBaseBlock;
use NvoosContentGraphAi\Blocks\ToolsGridBlock;
use NvoosContentGraphAi\Frontend\ChatShortcode;

/**
 * @group blocks
 */
class Test_Assistant_Builder_Blocks extends \WP_UnitTestCase {

	/**
	 * Blocks hub under test.
	 *
	 * @var Blocks
	 */
	private $blocks;

	/**
	 * Tool slugs known to the active registry (per install mode).
	 *
	 * @var array<string>
	 */
	private $known_slugs;

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

		// The builder block embeds `[nvoos_content_graph_chat]`; register
		// the real shortcode so do_shortcode() produces widget markup (the
		// plugin bootstrap never runs under the ecosystem test window).
		( new ChatShortcode() )->register();

		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$all = \WP_MCP_AI_Tool_Registry::get_instance()->get_tools();
			foreach ( array_slice( $all, 0, 3 ) as $tool ) {
				if ( is_object( $tool ) && method_exists( $tool, 'get_slug' ) && '' !== (string) $tool->get_slug() ) {
					$this->known_slugs[] = (string) $tool->get_slug();
				}
			}
		} else {
			$this->known_slugs = array( 'ai_analyze_image', 'ai_create_text_embeddings' );
		}

		$this->blocks = new Blocks();
		$this->blocks->register_blocks();

		// The block renders require an admin for the capability-gated
		// surfaces (tools grid, knowledge base, builder). A fresh factory
		// admin is deterministic regardless of what a previous run left
		// behind for user ID 1 in the shared test database.
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $admin );
	}

	public function tearDown(): void {
		\wp_dequeue_script( Blocks::ASSISTANT_SCRIPT_HANDLE );
		\wp_dequeue_style( Blocks::ASSISTANT_STYLE_HANDLE );
		\wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Render a registered block with WordPress block-support context.
	 *
	 * @param string $name  Block name.
	 * @param array  $attrs Block attributes.
	 * @return string Rendered block HTML.
	 */
	private function render_block( string $name, array $attrs = array() ): string {
		return \render_block(
			array(
				'blockName'    => $name,
				'attrs'        => $attrs,
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}

	/**
	 * Create a published assistant post.
	 *
	 * @param string $title Assistant title.
	 * @return int Post ID.
	 */
	private function create_assistant( string $title = 'Support Assistant' ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
	}

	// ─── Hub registration ───────────────────────────────────────────

	public function test_hub_registers_assistant_block_set(): void {
		$registry = \WP_Block_Type_Registry::get_instance();

		$expected = array(
			AssistantSelectorBlock::BLOCK_NAME,
			ToolsGridBlock::BLOCK_NAME,
			KnowledgeBaseBlock::BLOCK_NAME,
			AssistantBuilderBlock::BLOCK_NAME,
		);

		foreach ( $expected as $name ) {
			$this->assertTrue( $registry->is_registered( $name ) );
			$block = $registry->get_registered( $name );
			$this->assertNotNull( $block->render_callback );
		}

		// Shared assistant assets are registered (not yet enqueued).
		$this->assertTrue( \wp_script_is( Blocks::ASSISTANT_SCRIPT_HANDLE, 'registered' ) );
		$this->assertTrue( \wp_style_is( Blocks::ASSISTANT_STYLE_HANDLE, 'registered' ) );
	}

	public function test_hub_registration_idempotent(): void {
		$registry = \WP_Block_Type_Registry::get_instance();
		$this->blocks->register_blocks();
		$count = count( $registry->get_all_registered() );

		$this->blocks->register_blocks();
		$this->assertSame( $count, count( $registry->get_all_registered() ) );
	}

	// ─── Assistant selector ─────────────────────────────────────────

	public function test_selector_metadata_defaults(): void {
		$meta = AssistantSelectorBlock::metadata();

		$this->assertSame( 3, $meta['apiVersion'] );
		$this->assertSame( 'nvoos-content-graph-ai', $meta['category'] );
		$this->assertSame( 0, $meta['attributes']['defaultAssistantId']['default'] );
		$this->assertSame( '', $meta['attributes']['label']['default'] );
		$this->assertTrue( $meta['attributes']['showStartButton']['default'] );
		$this->assertSame( '', $meta['attributes']['startButtonText']['default'] );
	}

	public function test_selector_renders_assistants_and_escaping(): void {
		$clean = $this->create_assistant( 'Support Assistant' );
		$this->create_assistant( '<script>alert(1)</script>Evil' );

		$html = $this->render_block(
			AssistantSelectorBlock::BLOCK_NAME,
			array(
				'label'              => 'Pick one:',
				'startButtonText'    => 'Go',
				'defaultAssistantId' => $clean,
			)
		);

		$this->assertStringContainsString( 'wp-block-nvoos-content-graph-ai-assistant-selector', $html );
		$this->assertStringContainsString( 'nvoos-cg-selector', $html );
		$this->assertStringContainsString( 'Pick one:', $html );
		$this->assertStringContainsString( 'Support Assistant', $html );
		$this->assertStringContainsString( 'value="' . $clean . '" selected', $html );
		$this->assertStringContainsString( 'nvoos-cg-selector__start', $html );
		$this->assertStringContainsString( '>Go</button>', $html );

		// The malicious title never reaches raw markup.
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'Evil', $html );

		// Assets enqueued by the render callback.
		$this->assertTrue( \wp_script_is( Blocks::ASSISTANT_SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertTrue( \wp_style_is( Blocks::ASSISTANT_STYLE_HANDLE, 'enqueued' ) );
	}

	public function test_selector_start_button_toggle(): void {
		$html = $this->render_block(
			AssistantSelectorBlock::BLOCK_NAME,
			array( 'showStartButton' => false )
		);

		$this->assertStringNotContainsString( 'nvoos-cg-selector__start', $html );
	}

	public function test_selector_admin_embed_fallback_markup(): void {
		$this->create_assistant( 'Embed Assistant' );

		$html = AssistantSelectorBlock::render( array(), '', null );

		$this->assertStringContainsString( 'class="wp-block-nvoos-content-graph-ai-assistant-selector nvoos-cg-selector"', $html );
		$this->assertMatchesRegularExpression( '/data-block-id="nvoos-cg-selector-\d+"/', $html );
		$this->assertStringContainsString( 'Embed Assistant', $html );
	}

	// ─── Tools grid ─────────────────────────────────────────────────

	public function test_tools_grid_metadata_defaults(): void {
		$meta = ToolsGridBlock::metadata();

		$this->assertSame( '', $meta['attributes']['title']['default'] );
		$this->assertTrue( $meta['attributes']['showDescriptions']['default'] );
		$this->assertTrue( $meta['attributes']['startCollapsed']['default'] );
		$this->assertTrue( $meta['attributes']['showActions']['default'] );
		$this->assertSame( array(), $meta['attributes']['selectedTools']['default'] );
	}

	public function test_tools_grid_renders_groups_and_actions(): void {
		$html = $this->render_block(
			ToolsGridBlock::BLOCK_NAME,
			array(
				'title'          => 'My Tools',
				'startCollapsed' => false,
			)
		);

		$this->assertStringContainsString( 'wp-block-nvoos-content-graph-ai-tools-grid', $html );
		$this->assertStringContainsString( 'My Tools', $html );
		$this->assertStringContainsString( 'nvoos-cg-tools-grid__search-input', $html );
		$this->assertStringContainsString( 'nvoos-cg-tools-grid__group-select', $html );
		$this->assertStringContainsString( 'nvoos-cg-tools-grid__select-all', $html );
		$this->assertStringContainsString( 'nvoos-cg-tools-grid__deselect-all', $html );
		$this->assertStringContainsString( '<details', $html );
		$this->assertStringContainsString( 'data-group-id=', $html );

		// Known tool slugs render as checkbox values.
		foreach ( $this->known_slugs as $slug ) {
			$this->assertStringContainsString( 'value="' . $slug . '"', $html );
		}
	}

	public function test_tools_grid_selected_state_and_flags(): void {
		$first = $this->known_slugs[0] ?? 'ai_analyze_image';

		$html = $this->render_block(
			ToolsGridBlock::BLOCK_NAME,
			array(
				'selectedTools'    => array( $first ),
				'showActions'      => false,
				'showDescriptions' => false,
			)
		);

		$this->assertStringContainsString( 'value="' . $first . '" checked', $html );
		$this->assertStringContainsString( 'nvoos-cg-tools-grid__item--selected', $html );
		$this->assertStringNotContainsString( 'nvoos-cg-tools-grid__search-input', $html );
		$this->assertStringNotContainsString( 'nvoos-cg-tools-grid__item-description', $html );
	}

	public function test_tools_grid_permission_gate(): void {
		\wp_set_current_user( 0 );

		$html = $this->render_block( ToolsGridBlock::BLOCK_NAME );

		$this->assertStringContainsString( 'nvoos-cg-tools-grid__notice', $html );
		$this->assertStringContainsString( 'You do not have permission to view tools.', $html );
	}

	// ─── Knowledge base ─────────────────────────────────────────────

	public function test_knowledge_base_metadata_defaults(): void {
		$meta = KnowledgeBaseBlock::metadata();

		$this->assertSame( '.pdf,.txt,.md,.doc,.docx,.csv,.json', $meta['attributes']['allowedTypes']['default'] );
		$this->assertSame( 10, $meta['attributes']['maxFiles']['default'] );
		$this->assertSame( 10, $meta['attributes']['maxFileSizeMB']['default'] );
		$this->assertTrue( $meta['attributes']['showPreview']['default'] );
		$this->assertSame( array(), $meta['attributes']['uploadedFileIds']['default'] );
	}

	public function test_knowledge_base_render_data_attributes(): void {
		$html = $this->render_block(
			KnowledgeBaseBlock::BLOCK_NAME,
			array(
				'title'           => 'My KB',
				'allowedTypes'    => '.pdf,.txt',
				'maxFiles'        => 5,
				'maxFileSizeMB'   => 2,
				'uploadedFileIds' => array( 7, 9 ),
			)
		);

		$this->assertStringContainsString( 'wp-block-nvoos-content-graph-ai-knowledge-base', $html );
		$this->assertStringContainsString( 'My KB', $html );
		$this->assertStringContainsString( 'data-allowed-types=".pdf,.txt"', $html );
		$this->assertStringContainsString( 'data-max-files="5"', $html );
		$this->assertStringContainsString( 'data-nonce=', $html );
		$this->assertStringContainsString( 'data-upload-url="', $html );
		$this->assertStringContainsString( 'nvoos-cg-kb__dropzone', $html );

		// Pre-uploaded IDs land in the hidden input + count.
		$this->assertStringContainsString( 'value="7,9"', $html );
		$this->assertStringContainsString( '<strong class="nvoos-cg-kb__count">2</strong>', $html );
	}

	public function test_knowledge_base_permission_gate(): void {
		\wp_set_current_user( 0 );

		$html = $this->render_block( KnowledgeBaseBlock::BLOCK_NAME );

		$this->assertStringContainsString( 'nvoos-cg-kb__notice', $html );
		$this->assertStringContainsString( 'You do not have permission to upload files.', $html );
	}

	// ─── Assistant builder ──────────────────────────────────────────

	public function test_builder_metadata_defaults(): void {
		$meta = AssistantBuilderBlock::metadata();

		$this->assertTrue( $meta['attributes']['showAssistantSelector']['default'] );
		$this->assertTrue( $meta['attributes']['showToolsGrid']['default'] );
		$this->assertTrue( $meta['attributes']['showKnowledgeBase']['default'] );
		$this->assertTrue( $meta['attributes']['showBuildButton']['default'] );
		$this->assertSame( 0, $meta['attributes']['defaultAssistantId']['default'] );
		$this->assertSame( 'stacked', $meta['attributes']['layout']['default'] );
		$this->assertSame( array( 'stacked', 'side-by-side' ), $meta['attributes']['layout']['enum'] );
	}

	public function test_builder_renders_sections_and_config(): void {
		$html = $this->render_block( AssistantBuilderBlock::BLOCK_NAME );

		$this->assertStringContainsString( 'wp-block-nvoos-content-graph-ai-assistant-builder', $html );
		$this->assertStringContainsString( 'nvoos-cg-builder--stacked', $html );
		$this->assertStringContainsString( 'nvoos-cg-builder__selector', $html );
		$this->assertStringContainsString( 'nvoos-cg-builder__tools', $html );
		$this->assertStringContainsString( 'nvoos-cg-builder__knowledge-base', $html );
		$this->assertStringContainsString( 'nvoos-cg-builder__build', $html );
		$this->assertStringContainsString( 'nvoos-cg-builder__chat', $html );
		$this->assertStringContainsString( 'nvoos-cg-builder-config', $html );

		// The chat section embeds the ecosystem widget.
		$this->assertStringContainsString( 'nvoos-content-graph-chat-widget', $html );

		// Create-config contract.
		$expected_action = defined( 'WP_MCP_AI_PATH' ) ? 'wp_mcp_ai_create_assistant' : 'nvoos_cg_ai_create_assistant';
		$this->assertStringContainsString( '"createAction":"' . $expected_action . '"', $html );
		$this->assertMatchesRegularExpression( '/"redirectUrl":"[^"]+edit\.php\?post_type=mcp_ai_assistant/', $html );
		$this->assertStringContainsString( '"sections":{"selector":true,"tools":true,"kb":true,"build":true}', $html );
	}

	public function test_builder_layout_and_section_toggles(): void {
		$html = $this->render_block(
			AssistantBuilderBlock::BLOCK_NAME,
			array(
				'layout'          => 'side-by-side',
				'showToolsGrid'   => false,
				'showBuildButton' => false,
			)
		);

		$this->assertStringContainsString( 'nvoos-cg-builder--side-by-side', $html );
		$this->assertStringNotContainsString( 'nvoos-cg-builder--stacked', $html );
		$this->assertStringNotContainsString( 'nvoos-cg-builder__tools', $html );
		$this->assertStringNotContainsString( 'nvoos-cg-builder__build', $html );
		$this->assertStringContainsString( '"tools":false', $html );
		$this->assertStringContainsString( '"build":false', $html );
	}

	public function test_builder_permission_gate(): void {
		\wp_set_current_user( 0 );

		$html = $this->render_block( AssistantBuilderBlock::BLOCK_NAME );

		$this->assertStringContainsString( 'nvoos-cg-builder__notice', $html );
		$this->assertStringContainsString( 'You do not have permission to use the Assistant Builder.', $html );
	}

	// ─── Build Assistant Prompt tab embedding ───────────────────────

	public function test_prompt_tab_embeds_block_components(): void {
		$_GET['tab'] = 'prompt';

		\ob_start();
		( new \NvoosContentGraphAi\Admin\AssistantPages\BuildAssistantPage() )->render_page();
		$html = \ob_get_clean();

		$this->assertStringContainsString( 'Tools &amp; Knowledge Base', $html );
		$this->assertStringContainsString( 'nvoos-cg-tools-grid', $html );
		$this->assertStringContainsString( 'nvoos-cg-kb', $html );
	}
}
