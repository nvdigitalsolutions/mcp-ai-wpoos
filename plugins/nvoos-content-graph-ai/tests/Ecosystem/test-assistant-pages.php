<?php
/**
 * Assistant admin pages tests (Wave D-UI-4).
 *
 * Characterization suite for the ecosystem assistant admin surface:
 * the `mcp_ai_assistant` post type registration (`AssistantPostType`),
 * its REST-visible meta + sanitizers + capability gate, the
 * `AssistantPages` hub wiring, the Build Assistant page (tabs, option
 * lists, markup, form creation flow), and the Add Assistant page
 * (template grid, modal, create-from-profession flow). Assertions
 * mirror the base plugin's behaviour: byte-identical post-type args,
 * meta keys, and creation meta vocabulary.
 *
 * Matrix note: the pages register standalone-only (the base plugin owns
 * the same menus in monolith installs), so the tests exercise the page
 * classes directly in both matrices.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Admin\AssistantPostType;
use NvoosContentGraphAi\Admin\AssistantPages;
use NvoosContentGraphAi\Admin\AssistantPages\AddAssistantPage;
use NvoosContentGraphAi\Admin\AssistantPages\BuildAssistantPage;

/**
 * @group admin
 */
class Test_Assistant_Pages extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! \post_type_exists( 'mcp_ai_assistant' ) ) {
			\register_post_type( 'mcp_ai_assistant', array( 'public' => true ) );
		}

		$_GET  = array();
		$_POST = array();
		\wp_set_current_user( 1 );
	}

	public function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		\wp_set_current_user( 1 );

		parent::tearDown();
	}

	// ─── Post type registration ────────────────────────────────────

	public function test_post_type_constants_match_base(): void {
		$this->assertSame( 'mcp_ai_assistant', AssistantPostType::POST_TYPE );
		$this->assertSame( '_wp_mcp_ai_tools', AssistantPostType::META_TOOLS );
		$this->assertSame( '_wp_mcp_ai_provider', AssistantPostType::META_PROVIDER );
		$this->assertSame( '_wp_mcp_ai_model', AssistantPostType::META_MODEL );
		$this->assertSame( '_wp_mcp_ai_temperature', AssistantPostType::META_TEMPERATURE );
		$this->assertSame( '_wp_mcp_ai_system_prompt', AssistantPostType::META_SYSTEM_PROMPT );
		$this->assertSame( '_wp_mcp_ai_memory_files', AssistantPostType::META_MEMORY_FILES );
		$this->assertSame( '_wp_mcp_ai_primary_roles', AssistantPostType::META_PRIMARY_ROLES );
		$this->assertSame( '_wp_mcp_ai_source_profession', AssistantPostType::META_SOURCE_PROFESSION );
	}

	public function test_register_post_type_registers_expected_args(): void {
		if ( \post_type_exists( AssistantPostType::POST_TYPE ) ) {
			\unregister_post_type( AssistantPostType::POST_TYPE );
		}

		AssistantPostType::register_post_type();

		$object = \get_post_type_object( AssistantPostType::POST_TYPE );
		$this->assertNotNull( $object );
		$this->assertSame( 'AI Assistants', $object->labels->name );
		$this->assertSame( array( 'title', 'editor' ), $object->supports );
		$this->assertTrue( $object->show_in_rest );
		$this->assertSame( 'mcp-ai-assistants', $object->rest_base );
		$this->assertSame( 'dashicons-lightbulb', $object->menu_icon );
		$this->assertFalse( $object->public );
		$this->assertFalse( $object->rewrite );

		// Idempotent second call — never re-registers or fatals.
		AssistantPostType::register_post_type();
		$this->assertSame( $object, \get_post_type_object( AssistantPostType::POST_TYPE ) );
	}

	public function test_block_editor_disabled_for_assistant_only(): void {
		$this->assertFalse( AssistantPostType::disable_block_editor_for_post_type( true, 'mcp_ai_assistant' ) );
		$this->assertTrue( AssistantPostType::disable_block_editor_for_post_type( true, 'post' ) );
		$this->assertFalse( AssistantPostType::disable_block_editor_for_post_type( false, 'page' ) );
	}

	// ─── Meta registration + sanitizers ─────────────────────────────

	public function test_register_meta_exposes_expected_keys(): void {
		AssistantPostType::register_meta();

		$keys = \get_registered_meta_keys( 'post', 'mcp_ai_assistant' );
		$this->assertIsArray( $keys );

		foreach ( array( '_wp_mcp_ai_tools', '_wp_mcp_ai_provider', '_wp_mcp_ai_model', '_wp_mcp_ai_temperature', '_wp_mcp_ai_system_prompt' ) as $key ) {
			$this->assertArrayHasKey( $key, $keys, "Meta key $key should be registered." );
		}

		$this->assertSame(
			array( AssistantPostType::class, 'sanitize_tools_meta' ),
			$keys['_wp_mcp_ai_tools']['sanitize_callback']
		);
		$this->assertSame(
			array( AssistantPostType::class, 'sanitize_provider_meta' ),
			$keys['_wp_mcp_ai_provider']['sanitize_callback']
		);
	}

	public function test_sanitize_tools_meta_dedupes_and_slugs(): void {
		$this->assertSame(
			array( 'tool_a' ),
			AssistantPostType::sanitize_tools_meta( array( 'tool_a', 'tool_a', 42, '' ) )
		);
		$this->assertSame(
			array( 'tool-a', 'tool_b' ),
			AssistantPostType::sanitize_tools_meta( array( 'TOOL-A', 'tool_b' ) )
		);
		$this->assertSame( array(), AssistantPostType::sanitize_tools_meta( 'not-an-array' ) );
	}

	public function test_sanitize_provider_rejects_unknown(): void {
		$this->assertSame( 'openai', AssistantPostType::sanitize_provider_meta( 'openai' ) );
		$this->assertSame( 'gemini', AssistantPostType::sanitize_provider_meta( 'gemini' ) );
		$this->assertSame( '', AssistantPostType::sanitize_provider_meta( 'evil' ) );
		$this->assertSame( '', AssistantPostType::sanitize_provider_meta( '<script>evil</script>' ) );
	}

	public function test_sanitize_temperature_clamps(): void {
		$this->assertSame( 0.0, AssistantPostType::sanitize_temperature_meta( -3 ) );
		$this->assertSame( 2.0, AssistantPostType::sanitize_temperature_meta( 9 ) );
		$this->assertSame( 0.7, AssistantPostType::sanitize_temperature_meta( '0.7' ) );
	}

	public function test_meta_auth_respects_edit_post(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $admin );
		$this->assertTrue( AssistantPostType::meta_auth_callback( false, '_wp_mcp_ai_provider', $post_id, $admin ) );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );
		$result = AssistantPostType::meta_auth_callback( false, '_wp_mcp_ai_provider', $post_id, $subscriber );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	// ─── Hub wiring ─────────────────────────────────────────────────

	public function test_hub_constants_and_hooks(): void {
		$this->assertSame( 'nvoos-cg-build-assistant', BuildAssistantPage::PAGE_SLUG );
		$this->assertSame( 'nvoos-cg-add-assistant', AddAssistantPage::PAGE_SLUG );
		$this->assertSame( 'nvoos_cg_ai_create_assistant', BuildAssistantPage::CREATE_ACTION );
		$this->assertSame( 'nvoos_cg_ai_create_from_professional', AddAssistantPage::CREATE_ACTION );

		$hub = new AssistantPages();
		$hub->register();

		$this->assertSame( 10, \has_action( 'admin_menu', array( $hub, 'register_menus' ) ) );
		$this->assertSame( 10, \has_action( 'admin_enqueue_scripts', array( $hub, 'enqueue_scripts' ) ) );
		$this->assertSame( 10, \has_action( 'wp_ajax_nvoos_cg_ai_create_assistant', array( $hub, 'handle_ajax_create_assistant' ) ) );
		$this->assertSame( 10, \has_action( 'wp_ajax_nvoos_cg_ai_create_from_professional', array( $hub, 'handle_ajax_create_from_professional' ) ) );
	}

	public function test_hub_registers_menus_under_assistant_cpt(): void {
		if ( ! function_exists( 'add_submenu_page' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! function_exists( 'add_submenu_page' ) ) {
			$this->markTestSkipped( 'admin menu functions unavailable in this window.' );
		}

		$hub = new AssistantPages();
		$hub->register_menus();

		$this->assertIsArray( $GLOBALS['submenu'] );
		$this->assertArrayHasKey( 'edit.php?post_type=mcp_ai_assistant', $GLOBALS['submenu'] );

		$slugs = array();
		foreach ( $GLOBALS['submenu']['edit.php?post_type=mcp_ai_assistant'] as $item ) {
			$slugs[] = $item[2];
		}

		$this->assertContains( BuildAssistantPage::PAGE_SLUG, $slugs );
		$this->assertContains( AddAssistantPage::PAGE_SLUG, $slugs );
	}

	// ─── Build Assistant page ───────────────────────────────────────

	public function test_active_tab_validates_input(): void {
		$page = new BuildAssistantPage();

		$_GET['tab'] = 'manual';
		$this->assertSame( 'manual', $page->get_active_tab() );

		$_GET['tab'] = 'prompt';
		$this->assertSame( 'prompt', $page->get_active_tab() );

		$_GET['tab'] = '<script>evil</script>';
		$this->assertSame( 'manual', $page->get_active_tab() );

		unset( $_GET['tab'] );
		$this->assertSame( 'manual', $page->get_active_tab() );
	}

	public function test_tabs_shape(): void {
		$tabs = ( new BuildAssistantPage() )->get_tabs();

		$this->assertSame( array( 'manual', 'prompt', 'configuration', 'advanced' ), array_keys( $tabs ) );
		foreach ( $tabs as $tab ) {
			$this->assertArrayHasKey( 'title', $tab );
			$this->assertArrayHasKey( 'icon', $tab );
		}
	}

	public function test_professions_and_regions_lists(): void {
		$page = new BuildAssistantPage();

		$professions = $page->get_professions();
		$this->assertNotEmpty( $professions );
		$this->assertArrayHasKey( 'tax_advisor', $professions );

		$regions = $page->get_regions();
		$this->assertArrayHasKey( 'united_states', $regions );
		$this->assertArrayHasKey( 'jamaica', $regions );
		$this->assertArrayHasKey( 'global', $regions );
		$this->assertSame( 22, count( $regions ) );
	}

	public function test_render_page_manual_tab(): void {
		$_GET['tab'] = 'manual';

		\ob_start();
		( new BuildAssistantPage() )->render_page();
		$html = \ob_get_clean();

		$this->assertStringContainsString( 'nvoos-cg-create-assistant-form', $html );
		$this->assertStringContainsString( 'nav-tab-wrapper', $html );
		$this->assertStringContainsString( 'nav-tab-active', $html );
		$this->assertStringContainsString( 'Jamaica Tax Assistant', $html );
		$this->assertStringContainsString( 'assistant-provider', $html );
		$this->assertStringContainsString( 'assistant-temperature', $html );
	}

	public function test_render_prompt_tab_without_builder(): void {
		$_GET['tab'] = 'prompt';

		\ob_start();
		( new BuildAssistantPage() )->render_page();
		$html = \ob_get_clean();

		$this->assertStringContainsString( 'nvoos-cg-no-builder', $html );
		$this->assertStringContainsString( 'nvoos-cg-build-assistant-modal', $html );
	}

	public function test_render_configuration_tab_shows_stats(): void {
		$_GET['tab'] = 'configuration';

		\ob_start();
		( new BuildAssistantPage() )->render_page();
		$html = \ob_get_clean();

		$this->assertStringContainsString( 'nvoos-cg-stats-grid', $html );
		$this->assertStringContainsString( 'Active Assistants', $html );
		$this->assertStringContainsString( AddAssistantPage::PAGE_SLUG, $html );
	}

	public function test_create_from_form_requires_title(): void {
		$result = BuildAssistantPage::create_from_form( array() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_missing_title', $result->get_error_code() );
	}

	public function test_create_from_form_rejects_unknown_provider(): void {
		$result = BuildAssistantPage::create_from_form(
			array(
				'title'    => 'Test Assistant',
				'provider' => 'evil-provider',
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_invalid_provider', $result->get_error_code() );
	}

	public function test_create_from_form_async_unavailable(): void {
		$result = BuildAssistantPage::create_from_form(
			array(
				'title' => 'Test Assistant',
				'async' => true,
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_async_unavailable', $result->get_error_code() );
	}

	public function test_create_from_form_creates_assistant(): void {
		$result = BuildAssistantPage::create_from_form(
			array(
				'title'       => 'Jamaica Tax Assistant',
				'professions' => array( 'tax_advisor', 'accountant', 'lawyer', 'bookkeeper' ),
				'regions'     => array( 'jamaica', 'united_states', 'canada' ),
				'industry'    => 'perfumes',
				'provider'    => 'openai',
				'model'       => 'gpt-4',
				'temperature' => '0.9',
			)
		);

		$this->assertIsArray( $result );

		$post = \get_post( $result['assistant_id'] );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 'openai', \get_post_meta( $post->ID, '_wp_mcp_ai_provider', true ) );
		$this->assertSame( 'gpt-4', \get_post_meta( $post->ID, '_wp_mcp_ai_model', true ) );
		$this->assertEquals( 0.9, \get_post_meta( $post->ID, '_wp_mcp_ai_temperature', true ) );
		$this->assertSame( array(), \get_post_meta( $post->ID, '_wp_mcp_ai_tools', true ) );

		$prompt = \get_post_meta( $post->ID, '_wp_mcp_ai_system_prompt', true );
		$this->assertStringContainsString( 'Tax Advisor', $prompt );
		$this->assertStringContainsString( 'Accountant', $prompt );
		$this->assertStringContainsString( 'Lawyer', $prompt );
		// Professions capped at 3.
		$this->assertStringNotContainsString( 'Bookkeeper', $prompt );
		$this->assertStringContainsString( 'Jamaica', $prompt );
		$this->assertStringContainsString( 'perfumes', $prompt );
	}

	public function test_builder_assistant_resolution(): void {
		// No builder configured → 0.
		$this->assertSame( 0, ( new BuildAssistantPage() )->get_builder_assistant_id() );

		// A published assistant with the builder slug resolves.
		$builder_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_name'   => 'assistant-builder',
				'post_title'  => 'Assistant Builder',
			)
		);

		$this->assertSame( $builder_id, ( new BuildAssistantPage() )->get_builder_assistant_id() );
	}

	// ─── Add Assistant page ─────────────────────────────────────────

	public function test_add_page_render_without_professions(): void {
		\ob_start();
		( new AddAssistantPage() )->render_page();
		$html = \ob_get_clean();

		$this->assertStringContainsString( 'No professional templates found', $html );
		$this->assertStringContainsString( 'nvoos-cg-create-modal', $html );
		$this->assertStringContainsString( 'profession_id', $html );
	}

	public function test_add_page_render_with_profession(): void {
		$profession_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
				'post_title'  => 'Tax Advisor Template',
			)
		);
		\update_post_meta( $profession_id, '_wp_mcp_ai_profession_default_tools', array( 'get_posts_tool' ) );
		\update_post_meta( $profession_id, '_wp_mcp_ai_profession_category', 'tax' );

		\ob_start();
		( new AddAssistantPage() )->render_page();
		$html = \ob_get_clean();

		$this->assertStringContainsString( 'Tax Advisor Template', $html );
		$this->assertStringContainsString( 'data-profession-id="' . $profession_id . '"', $html );
		$this->assertStringContainsString( '1 tool', $html );
	}

	public function test_create_from_professional_validation(): void {
		$this->assertInstanceOf(
			\WP_Error::class,
			AddAssistantPage::create_from_professional( 0, 'Title', 'openai' )
		);

		$profession_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
			)
		);

		$missing_title = AddAssistantPage::create_from_professional( $profession_id, '', 'openai' );
		$this->assertInstanceOf( \WP_Error::class, $missing_title );
		$this->assertSame( 'wp_mcp_ai_missing_title', $missing_title->get_error_code() );
	}

	public function test_create_from_professional_creates_assistant(): void {
		$profession_id = self::factory()->post->create(
			array(
				'post_type'    => 'mcp_ai_profession',
				'post_status'  => 'publish',
				'post_title'   => 'Customs Broker',
				'post_content' => 'Template content',
			)
		);
		\update_post_meta( $profession_id, '_wp_mcp_ai_profession_role_description', 'You are a customs broker.' );
		\update_post_meta( $profession_id, '_wp_mcp_ai_profession_knowledge_base', 'Perfume import rules.' );
		\update_post_meta( $profession_id, '_wp_mcp_ai_profession_default_tools', array( 'get_post', 'create_post' ) );
		\update_post_meta( $profession_id, '_wp_mcp_ai_profession_default_provider', 'gemini' );
		\update_post_meta( $profession_id, '_wp_mcp_ai_profession_default_model', 'gemini-1.5-pro' );
		\update_post_meta( $profession_id, '_wp_mcp_ai_profession_default_temperature', '0.5' );
		\update_post_meta( $profession_id, '_wp_mcp_ai_profession_memory_files', array( 7 ) );

		$result = AddAssistantPage::create_from_professional( $profession_id, 'Perfume Broker', '' );

		$this->assertIsArray( $result );

		$post = \get_post( $result['assistant_id'] );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 'Template content', $post->post_content );

		// Template defaults (provider override empty → template provider).
		$this->assertSame( 'gemini', \get_post_meta( $post->ID, '_wp_mcp_ai_provider', true ) );
		$this->assertSame( 'gemini-1.5-pro', \get_post_meta( $post->ID, '_wp_mcp_ai_model', true ) );
		$this->assertEquals( 0.5, \get_post_meta( $post->ID, '_wp_mcp_ai_temperature', true ) );
		$this->assertSame(
			array( 'get_post', 'create_post' ),
			\get_post_meta( $post->ID, '_wp_mcp_ai_tools', true )
		);
		$this->assertSame(
			array( $profession_id ),
			\get_post_meta( $post->ID, '_wp_mcp_ai_primary_roles', true )
		);
		$this->assertSame( array( 7 ), \get_post_meta( $post->ID, '_wp_mcp_ai_memory_files', true ) );
		$this->assertSame(
			(string) $profession_id,
			\get_post_meta( $post->ID, '_wp_mcp_ai_source_profession', true )
		);

		$prompt = \get_post_meta( $post->ID, '_wp_mcp_ai_system_prompt', true );
		$this->assertStringContainsString( 'customs broker', $prompt );
		$this->assertStringContainsString( 'Perfume import rules', $prompt );
	}

	public function test_create_from_professional_provider_override(): void {
		$profession_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
			)
		);
		\update_post_meta( $profession_id, '_wp_mcp_ai_profession_default_provider', 'openai' );

		$result = AddAssistantPage::create_from_professional( $profession_id, 'Override Assistant', 'anthropic' );

		$this->assertIsArray( $result );
		$this->assertSame(
			'anthropic',
			\get_post_meta( $result['assistant_id'], '_wp_mcp_ai_provider', true )
		);
	}
}
