<?php
/**
 * Profession pages ported-class tests (Wave E-UI-3, sub-cluster 1).
 *
 * Verifies the extraction ports of the base plugin's
 * `WP_MCP_AI_Admin_Profession_Research_Page` and
 * `WP_MCP_AI_Admin_Profession_Settings` preserve the public behaviour:
 * the byte-identical page slugs, the per-mode menu registration under
 * the profession CPT menu, init/register idempotence (hook-registry
 * dedup delta), the per-mode post-type and shortcode seams, the
 * research enqueue gate + `wpMcpAiResearchPage` envelope, the render
 * surface (common surface, no-assistant notice, import form, review
 * quality metrics with seeded professions), the settings
 * registrations, the settings tab renders
 * (overview/configuration/tools/help), the capability gate, and the
 * nonce-gated save flow (options + temperature clamp +
 * settings-updated redirect). Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Admin\Integrations\ProfessionResearchPage;
use NvoosContentGraphAiPlatform\Admin\Integrations\ProfessionSettings;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The test-only exposer fixtures share this file with their test cases.

/**
 * Test-only exposer: the research page's protected statics are
 * published as public wrappers.
 */
class ProfessionResearchPageExposer extends ProfessionResearchPage {

	public static function exposed_profession_post_type(): string {
		return self::profession_post_type();
	}

	public static function exposed_shortcode_class() {
		return self::shortcode_class();
	}

	public static function exposed_render(): string {
		\ob_start();
		try {
			self::render_page();
		} finally {
			$output = (string) \ob_get_clean();
		}
		return $output;
	}
}

/**
 * Test-only exposer: the settings page's protected statics and helpers
 * are published as public wrappers.
 */
class ProfessionSettingsExposer extends ProfessionSettings {

	public static function exposed_profession_post_type(): string {
		return self::profession_post_type();
	}

	public static function exposed_available_providers(): array {
		return self::available_providers();
	}

	public function exposed_page_hook() {
		return $this->page_hook;
	}

	public function exposed_render(): string {
		\ob_start();
		try {
			$this->render_page();
		} finally {
			$output = (string) \ob_get_clean();
		}
		return $output;
	}

	public function exposed_get_tools_list(): array {
		return $this->get_tools_list();
	}
}

/**
 * Profession pages characterisation suite (Wave E-UI-3, sub-cluster 1).
 */
#[\PHPUnit\Framework\Attributes\Group( 'integrations' )]
class Test_Profession_Pages extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		\wp_set_current_user( 0 );
		unset( $_GET['tab'], $_GET['settings-updated'], $_POST['wp_mcp_ai_profession_settings_nonce'], $_POST['wp_mcp_ai_profession_default_provider'], $_POST['wp_mcp_ai_profession_default_model'], $_POST['wp_mcp_ai_profession_default_temperature'] );

		\delete_option( 'wp_mcp_ai_profession_settings' );
		\delete_option( 'wp_mcp_ai_profession_default_provider' );
		\delete_option( 'wp_mcp_ai_profession_default_model' );
		\delete_option( 'wp_mcp_ai_profession_default_temperature' );

		// Script/style queue leaks + WP 6.9 all_queued_deps memoization:
		// reset through the public API so the memo invalidates.
		global $wp_scripts;
		foreach ( (array) $wp_scripts->queue as $handle ) {
			\wp_dequeue_script( $handle );
		}
		foreach ( (array) \wp_styles()->queue as $handle ) {
			\wp_dequeue_style( $handle );
		}
	}

	public function tearDown(): void {
		\wp_set_current_user( 0 );
		unset( $_GET['tab'], $_GET['settings-updated'], $_POST['wp_mcp_ai_profession_settings_nonce'], $_POST['wp_mcp_ai_profession_default_provider'], $_POST['wp_mcp_ai_profession_default_model'], $_POST['wp_mcp_ai_profession_default_temperature'] );

		\delete_option( 'wp_mcp_ai_profession_settings' );
		\delete_option( 'wp_mcp_ai_profession_default_provider' );
		\delete_option( 'wp_mcp_ai_profession_default_model' );
		\delete_option( 'wp_mcp_ai_profession_default_temperature' );
		\remove_all_filters( 'wp_redirect' );

		parent::tearDown();
	}

	/**
	 * Create + switch to an administrator user.
	 *
	 * @return int
	 */
	private function admin_user(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Count callbacks on a hook whose method name matches.
	 *
	 * @param string $hook   Hook name.
	 * @param string $method Method name to match.
	 * @return int
	 */
	private function count_method_callbacks( string $hook, string $method ): int {
		global $wp_filter;
		$total = 0;
		foreach ( (array) ( $wp_filter[ $hook ]->callbacks ?? array() ) as $priority_group ) {
			foreach ( (array) $priority_group as $cb ) {
				$fn = $cb['function'] ?? null;
				if ( \is_array( $fn ) && isset( $fn[1] ) && $method === $fn[1] ) {
					++$total;
				}
			}
		}
		return $total;
	}

	/**
	 * Invoke a handler and capture the WPDieException message instead of
	 * the echo buffer (the throwing test die-handler echoes nothing).
	 *
	 * @param callable $callback Handler invocation.
	 * @return string The die message, or the echoed output if no die fired.
	 */
	private function capture_die_message( callable $callback ): string {
		\ob_start();
		try {
			$callback();
		} catch ( \WPDieException $e ) {
			\ob_end_clean();
			return $e->getMessage();
		}
		return (string) \ob_get_clean();
	}

	/**
	 * Intercept wp_redirect() and capture the location, rethrowing so the
	 * handler's bare `exit` cannot terminate the process.
	 *
	 * @param callable $handler Handler invocation.
	 * @return string|null The redirect location, or null if none fired.
	 */
	private function capture_redirect( callable $handler ) {
		$redirected = null;
		\add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$redirected ) {
				$redirected = $location;
				throw new \RuntimeException( 'stop' );
			}
		);

		try {
			$handler();
		} catch ( \RuntimeException $e ) {
			unset( $e ); // Expected — the redirect filter short-circuits exit.
		}

		\remove_all_filters( 'wp_redirect' );
		return $redirected;
	}

	/**
	 * Create a published profession post with optional meta.
	 *
	 * @param array $meta Meta key/value pairs.
	 * @return int
	 */
	private function create_profession( array $meta = array() ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_title'  => 'Seeded Profession',
				'post_status' => 'publish',
			)
		);

		foreach ( $meta as $key => $value ) {
			\update_post_meta( $id, $key, $value );
		}

		return $id;
	}

	// ─── Research page: public surface + seams ──────────────────

	public function test_research_page_slug_byte_identical(): void {
		$this->assertSame( 'research-profession', ProfessionResearchPage::PAGE_SLUG );
	}

	public function test_research_menu_registration_resolves_per_install_mode(): void {
		global $submenu;
		$submenu = array();

		$this->admin_user();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the base class owns the same page.
			\WP_MCP_AI_Admin_Profession_Research_Page::add_menu_page();
		} else {
			ProfessionResearchPage::add_menu_page();
		}

		$parent = 'edit.php?post_type=mcp_ai_profession';
		$this->assertArrayHasKey( $parent, $submenu );
		$slugs = \wp_list_pluck( $submenu[ $parent ], 2 );
		$this->assertContains( 'research-profession', $slugs );
	}

	public function test_research_init_is_idempotent(): void {
		$before = $this->count_method_callbacks( 'admin_menu', 'add_menu_page' );

		ProfessionResearchPage::init();
		ProfessionResearchPage::init();

		$this->assertSame( 1, $this->count_method_callbacks( 'admin_menu', 'add_menu_page' ) - $before );
	}

	public function test_research_post_type_seam(): void {
		$this->assertSame( 'mcp_ai_profession', ProfessionResearchPageExposer::exposed_profession_post_type() );
	}

	public function test_research_shortcode_seam_resolves_per_install_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame( 'WP_MCP_AI_Shortcode', ProfessionResearchPageExposer::exposed_shortcode_class() );
		} else {
			$this->assertNull( ProfessionResearchPageExposer::exposed_shortcode_class() );
		}
	}

	// ─── Research page: assets ──────────────────────────────────

	public function test_research_enqueue_assets_per_install_mode(): void {
		$this->admin_user();

		// Isolate the chat handle (other subsystems may register the same
		// handle name in this process).
		\wp_deregister_script( 'wp-mcp-ai-chat' );
		\wp_deregister_style( 'wp-mcp-ai-chat' );

		ProfessionResearchPage::enqueue_assets( 'mcp_ai_profession_page_research-profession' );

		$this->assertTrue( \wp_style_is( 'wp-mcp-ai-enhanced-research-page', 'enqueued' ) );
		$this->assertTrue( \wp_script_is( 'wp-mcp-ai-enhanced-research-page', 'registered' ) );

		// The localized envelope carries the byte-identical key.
		global $wp_scripts;
		$data = $wp_scripts->registered['wp-mcp-ai-enhanced-research-page']->extra['data'] ?? '';
		$this->assertStringContainsString( 'wpMcpAiResearchPage', $data );
		$this->assertStringContainsString( 'ajaxUrl', $data );
		$this->assertStringContainsString( '"entityType":"profession"', $data );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the base chat shortcode assets ride along.
			$this->assertTrue( \wp_style_is( \WP_MCP_AI_Shortcode::STYLE_HANDLE, 'enqueued' ) );
			$this->assertTrue( \wp_script_is( \WP_MCP_AI_Shortcode::SCRIPT_HANDLE, 'enqueued' ) );
		} else {
			$this->assertFalse( \wp_script_is( 'wp-mcp-ai-chat', 'registered' ) );
		}
	}

	public function test_research_enqueue_skips_other_pages(): void {
		$this->admin_user();
		\wp_deregister_script( 'wp-mcp-ai-enhanced-research-page' );

		ProfessionResearchPage::enqueue_assets( 'toplevel_page_something-else' );

		$this->assertFalse( \wp_script_is( 'wp-mcp-ai-enhanced-research-page', 'registered' ) );
	}

	// ─── Research page: render surface ──────────────────────────

	public function test_research_render_common_surface(): void {
		$this->admin_user();
		$output = ProfessionResearchPageExposer::exposed_render();

		$this->assertStringContainsString( 'Research &amp; Add Profession', $output );
		$this->assertStringContainsString( 'How It Works', $output );
		$this->assertStringContainsString( 'Choose Your Workflow', $output );
		$this->assertStringContainsString( 'Import Profession Data', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-import-form', $output );
		// The import nonce action only exists hashed — assert the field name.
		$this->assertStringContainsString( 'name="import_nonce"', $output );
		$this->assertStringContainsString( 'Profession Data Quality', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-example-query', $output );

		// No assistants configured → byte-identical notice.
		$this->assertStringContainsString( 'No AI assistant found', $output );
		$this->assertStringNotContainsString( 'wp-mcp-ai-research-chat', $output );
	}

	public function test_research_render_with_assistant(): void {
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Research Assistant',
				'post_status' => 'publish',
			)
		);

		$this->admin_user();
		$output = ProfessionResearchPageExposer::exposed_render();

		$this->assertStringContainsString( 'wp-mcp-ai-research-chat', $output );
		$this->assertStringNotContainsString( 'No AI assistant found', $output );

		\wp_delete_post( $assistant_id, true );
	}

	public function test_research_render_review_metrics(): void {
		// One complete + one partial profession → 50% completeness.
		$this->create_profession(
			array(
				'_wp_mcp_ai_profession_expertise'     => 'Backend',
				'_wp_mcp_ai_profession_agent_role'    => 'executor',
				'_wp_mcp_ai_profession_default_tools' => 'web_search',
			)
		);
		$this->create_profession(
			array(
				'_wp_mcp_ai_profession_expertise' => 'Design',
			)
		);

		$this->admin_user();
		$output = ProfessionResearchPageExposer::exposed_render();

		// Compute the expected completeness with the byte-identical rules —
		// the install may carry seeded professions beyond this test's two.
		$counts          = \wp_count_posts( 'mcp_ai_profession' );
		$published_count = (int) ( isset( $counts->publish ) ? $counts->publish : 0 );
		$professions     = \get_posts(
			array(
				'post_type'      => 'mcp_ai_profession',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$complete_count = 0;
		foreach ( $professions as $profession ) {
			$expertise = \get_post_meta( $profession->ID, '_wp_mcp_ai_profession_expertise', true );
			$role      = \get_post_meta( $profession->ID, '_wp_mcp_ai_profession_agent_role', true );
			$tools     = \get_post_meta( $profession->ID, '_wp_mcp_ai_profession_default_tools', true );

			if ( ! empty( $expertise ) && ! empty( $role ) && ! empty( $tools ) ) {
				++$complete_count;
			}
		}

		$expected = $published_count > 0 ? \round( ( $complete_count / $published_count ) * 100 ) : 0;

		$this->assertStringContainsString( 'completeness-percentage">' . $expected . '%', $output );
		$this->assertStringContainsString( 'quality-metric-value">' . $published_count . '<', $output );
		$this->assertStringContainsString( 'quality-metric-value">' . $complete_count . '<', $output );

		// The warning notice only renders below the 80% threshold.
		if ( $expected < 80 ) {
			$this->assertStringContainsString( 'Data completeness is ' . $expected . '%', $output );
		}
	}

	// ─── Settings page: public surface + seams ──────────────────

	public function test_settings_page_slug_byte_identical(): void {
		$this->assertSame( 'wp-mcp-ai-profession-settings', ProfessionSettings::PAGE_SLUG );
	}

	public function test_settings_menu_registration_resolves_per_install_mode(): void {
		global $submenu;
		$submenu = array();

		$this->admin_user();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the base class owns the same page.
			( new \WP_MCP_AI_Admin_Profession_Settings() )->register_submenu_page();
		} else {
			$page = new ProfessionSettings();
			$page->register_submenu_page();
		}

		$parent = 'edit.php?post_type=mcp_ai_profession';
		$this->assertArrayHasKey( $parent, $submenu );
		$slugs = \wp_list_pluck( $submenu[ $parent ], 2 );
		$this->assertContains( 'wp-mcp-ai-profession-settings', $slugs );
	}

	public function test_settings_register_is_idempotent(): void {
		$menu_before = $this->count_method_callbacks( 'admin_menu', 'register_submenu_page' );
		$init_before = $this->count_method_callbacks( 'admin_init', 'register_settings' );

		$page = new ProfessionSettings();
		$page->register();
		$page->register();

		$this->assertSame( 1, $this->count_method_callbacks( 'admin_menu', 'register_submenu_page' ) - $menu_before );
		$this->assertSame( 1, $this->count_method_callbacks( 'admin_init', 'register_settings' ) - $init_before );
	}

	public function test_settings_post_type_seam(): void {
		$this->assertSame( 'mcp_ai_profession', ProfessionSettingsExposer::exposed_profession_post_type() );
	}

	public function test_settings_available_providers_seam_resolves_per_install_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame(
				\WP_MCP_AI_Admin_Settings::get_available_providers(),
				ProfessionSettingsExposer::exposed_available_providers()
			);
		} else {
			$this->assertSame( array(), ProfessionSettingsExposer::exposed_available_providers() );
		}
	}

	// ─── Settings page: registrations + render surface ──────────

	public function test_settings_register_settings(): void {
		$page = new ProfessionSettings();
		$page->register_settings();

		global $wp_registered_settings;
		$this->assertArrayHasKey( 'wp_mcp_ai_profession_default_provider', $wp_registered_settings );
		$this->assertArrayHasKey( 'wp_mcp_ai_profession_default_model', $wp_registered_settings );
		$this->assertArrayHasKey( 'wp_mcp_ai_profession_default_temperature', $wp_registered_settings );
	}

	public function test_settings_render_blocks_non_managers(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );

		$message = $this->capture_die_message(
			static function (): void {
				( new ProfessionSettingsExposer() )->exposed_render();
			}
		);
		$this->assertSame( 'You do not have sufficient permissions to access this page.', $message );
	}

	public function test_settings_render_overview_tab(): void {
		$this->admin_user();
		$output = ( new ProfessionSettingsExposer() )->exposed_render();

		$this->assertStringContainsString( 'Profession Settings', $output );
		$this->assertStringContainsString( 'profession-settings-nav', $output );
		$this->assertStringContainsString( 'AI Professions Overview', $output );
		$this->assertStringContainsString( 'Key Features', $output );
		$this->assertStringContainsString( 'Use Cases', $output );
	}

	public function test_settings_render_configuration_tab(): void {
		$this->admin_user();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only query parameters under test.
		$_GET['tab']               = 'configuration';
		$_GET['settings-updated']  = 'true';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$output = ( new ProfessionSettingsExposer() )->exposed_render();

		$this->assertStringContainsString( 'Default AI Provider', $output );
		$this->assertStringContainsString( '-- Use Global Default --', $output );
		$this->assertStringContainsString( 'Default Model', $output );
		$this->assertStringContainsString( 'Default Temperature', $output );
		$this->assertStringContainsString( 'Settings Hierarchy', $output );
		$this->assertStringContainsString( 'Settings saved successfully.', $output );
		$this->assertStringNotContainsString( 'AI Professions Overview', $output );
	}

	public function test_settings_render_tools_tab(): void {
		$this->admin_user();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only query parameters under test.
		$_GET['tab'] = 'tools';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$output = ( new ProfessionSettingsExposer() )->exposed_render();

		$this->assertStringContainsString( 'Available Tools', $output );
		$this->assertStringContainsString( 'Create Post', $output );
		$this->assertStringContainsString( 'all 16 core tools', $output );
		$this->assertStringContainsString( 'Tool Recommendations', $output );
	}

	public function test_settings_render_help_tab(): void {
		$this->admin_user();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only query parameters under test.
		$_GET['tab'] = 'help';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$output = ( new ProfessionSettingsExposer() )->exposed_render();

		$this->assertStringContainsString( 'Quick Start Guide', $output );
		$this->assertStringContainsString( 'Tool Reference Documentation', $output );
		$this->assertStringContainsString( 'Create New Profession', $output );
	}

	// ─── Settings page: save flow ───────────────────────────────

	public function test_settings_save_flow(): void {
		$this->admin_user();

		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: valid nonce into the superglobals.
		$_POST['wp_mcp_ai_profession_settings_nonce']   = \wp_create_nonce( 'wp_mcp_ai_profession_settings' );
		$_POST['wp_mcp_ai_profession_default_provider'] = 'openai';
		$_POST['wp_mcp_ai_profession_default_model']    = 'gpt-4.1';
		$_POST['wp_mcp_ai_profession_default_temperature'] = '2.5';
		// phpcs:enable WordPress.Security.NonceVerification

		$redirected = $this->capture_redirect(
			static function (): void {
				( new ProfessionSettingsExposer() )->exposed_render();
			}
		);

		$this->assertNotNull( $redirected );
		$this->assertStringContainsString( 'settings-updated=true', $redirected );
		$this->assertStringContainsString( 'page=wp-mcp-ai-profession-settings', $redirected );

		$this->assertSame( 'openai', \get_option( 'wp_mcp_ai_profession_default_provider' ) );
		$this->assertSame( 'gpt-4.1', \get_option( 'wp_mcp_ai_profession_default_model' ) );
		// The temperature is clamped to the 0–1 range (int coercion quirk).
		$this->assertEquals( 1.0, \get_option( 'wp_mcp_ai_profession_default_temperature' ) );
	}

	public function test_settings_render_ignores_invalid_nonce(): void {
		$this->admin_user();

		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: deliberately invalid nonce.
		$_POST['wp_mcp_ai_profession_settings_nonce']   = 'bogus';
		$_POST['wp_mcp_ai_profession_default_provider'] = 'openai';
		// phpcs:enable WordPress.Security.NonceVerification

		$redirected = $this->capture_redirect(
			static function (): void {
				( new ProfessionSettingsExposer() )->exposed_render();
			}
		);

		$this->assertNull( $redirected );
		$this->assertFalse( \get_option( 'wp_mcp_ai_profession_default_provider' ) );
	}

	public function test_settings_tools_list_shape(): void {
		$page  = new ProfessionSettingsExposer();
		$tools = $page->exposed_get_tools_list();

		$this->assertCount( 16, $tools );
		$this->assertSame( 'Create Post', $tools['wp_create_post'] );
		$this->assertSame( 'Web Search', $tools['web_search'] );
	}
}
