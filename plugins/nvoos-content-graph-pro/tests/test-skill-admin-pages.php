<?php
/**
 * Characterization tests for the ported skill admin pages + skills-manager
 * init (Wave F1, sub-cluster 3b-3).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the page
 *   classes (classmap-autoloaded even though the CLI test env never boots
 *   them via the admin-gated init); the same public surface is asserted.
 * - Standalone matrix (base plugin absent): the ported pages in
 *   `src/admin/` + `src/skills-manager-init.php` are asserted in full.
 *
 * Skills write into the test environment's uploads dir — install/save flows
 * are safe in both matrices.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Skill admin pages tests.
 */
class Test_Skill_Admin_Pages extends WP_UnitTestCase {

	/**
	 * Snapshot of the three settings options before mutation.
	 *
	 * @var array<string,mixed>
	 */
	private $option_snapshot = array();

	/**
	 * Names of skills installed by this suite (cleaned up in tearDown).
	 *
	 * @var string[]
	 */
	private $installed_skills = array();

	/**
	 * Sample valid SKILL.md content for the save flow.
	 *
	 * @var string
	 */
	private const SAVE_CONTENT = "---\nname: test-admin-skill\ndescription: Installed via the admin save flow.\n---\n\n# Test Admin Skill\n";

	/**
	 * Snapshot the settings options.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->option_snapshot = array(
			'wp_mcp_ai_skills_enabled'          => get_option( 'wp_mcp_ai_skills_enabled', null ),
			'wp_mcp_ai_skill_auto_inject'       => get_option( 'wp_mcp_ai_skill_auto_inject', null ),
			'wp_mcp_ai_skill_max_per_assistant' => get_option( 'wp_mcp_ai_skill_max_per_assistant', null ),
		);
	}

	/**
	 * Restore options and remove any skills installed by this suite.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		foreach ( $this->option_snapshot as $option => $value ) {
			if ( null === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value );
			}
		}

		$registry = defined( 'WP_MCP_AI_PATH' )
			? WP_MCP_AI_Skill_Registry::instance()
			: \NvoosContentGraphAiPlatform\Skills\SkillRegistry::instance();

		foreach ( $this->installed_skills as $skill_name ) {
			$skill_dir = trailingslashit( $registry->get_skills_dir() ) . $skill_name;
			if ( is_dir( $skill_dir ) ) {
				$items = scandir( $skill_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup only.
				if ( is_array( $items ) ) {
					foreach ( $items as $item ) {
						if ( '.' !== $item && '..' !== $item ) {
							unlink( trailingslashit( $skill_dir ) . $item ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Cleanup only.
						}
					}
				}
				rmdir( $skill_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Cleanup only.
			}
		}
		$this->installed_skills = array();

		parent::tearDown();
	}

	/**
	 * Create + switch to an administrator user.
	 *
	 * @return int
	 */
	private function admin_user(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Capture echoed output of a callback (swallowing WPDieException).
	 *
	 * @param callable $callback Handler invocation.
	 * @return string
	 */
	private function capture_output( callable $callback ): string {
		ob_start();
		try {
			$callback();
		} catch ( \WPDieException $e ) {
			unset( $e ); // Expected — payload echoed before the die.
		}
		return (string) ob_get_clean();
	}

	/**
	 * Invoke a handler and capture the WPDieException message instead of
	 * the echo buffer (nonce failures echo nothing).
	 *
	 * @param callable $callback Handler invocation.
	 * @return string
	 */
	private function capture_die_message( callable $callback ): string {
		ob_start();
		try {
			$callback();
		} catch ( \WPDieException $e ) {
			ob_end_clean();
			return $e->getMessage();
		}
		return (string) ob_get_clean();
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
		add_filter(
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

		remove_all_filters( 'wp_redirect' );
		return $redirected;
	}

	/**
	 * Craft the manager AJAX nonce into the superglobals.
	 *
	 * @return void
	 */
	private function craft_manager_nonce(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Test fixture crafting the nonce explicitly.
		$_POST['nonce']    = wp_create_nonce( 'wp_mcp_ai_skill_manager' );
		$_REQUEST['nonce'] = $_POST['nonce'];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * init() must wire the admin-menu and AJAX hooks.
	 */
	public function test_init_wires_hooks(): void {
		WP_MCP_AI_Skill_Manager_Admin_Page::init();
		WP_MCP_AI_Skill_Research_Admin_Page::init();
		WP_MCP_AI_Skill_Settings_Admin_Page::init();

		foreach (
			array(
				'wp_ajax_wp_mcp_ai_skill_manager_upload',
				'wp_ajax_wp_mcp_ai_skill_manager_install_url',
				'wp_ajax_wp_mcp_ai_skill_manager_save',
				'wp_ajax_wp_mcp_ai_skill_manager_delete',
				'wp_ajax_wp_mcp_ai_skill_manager_generate_skill',
			) as $action
		) {
			$this->assertNotFalse( has_action( $action ) );
		}
	}

	/**
	 * All three pages must land under the per-mode resolved menu parent.
	 */
	public function test_pages_register_under_resolved_menus(): void {
		$this->admin_user();
		WP_MCP_AI_Skill_Manager_Admin_Page::init();
		WP_MCP_AI_Skill_Research_Admin_Page::init();
		WP_MCP_AI_Skill_Settings_Admin_Page::init();

		// Standalone: the Platform addon's admin pages are CLI-skipped, so
		// register its top-level menu parent for this test (prior-wave
		// pattern — without a registered parent the submenu still lands,
		// but the hook fallback is not in the allowlists).
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			add_menu_page(
				'NV Platform',
				'NV Platform',
				'manage_options',
				\NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG,
				static function (): void {},
				'',
				3
			);
		}

		do_action( 'admin_menu' );

		global $submenu;
		$found = array();
		foreach ( $submenu as $items ) {
			foreach ( $items as $item ) {
				if ( isset( $item[2] ) ) {
					$found[] = $item[2];
				}
			}
		}

		$this->assertContains( 'wp-mcp-ai-skill-manager', $found );
		$this->assertContains( 'wp-mcp-ai-skill-settings', $found );
		$this->assertContains( 'research-skill', $found );
	}

	/**
	 * The manager page must render its tabbed surface.
	 */
	public function test_manager_page_renders(): void {
		$this->admin_user();
		$output = $this->capture_output( array( 'WP_MCP_AI_Skill_Manager_Admin_Page', 'render_page' ) );
		$this->assertStringContainsString( 'wp-mcp-ai-skill-manager', $output );
		$this->assertStringContainsString( 'Skill Manager', $output );
	}

	/**
	 * The settings page must render the option fields and nonce.
	 */
	public function test_settings_page_renders(): void {
		$this->admin_user();
		// The option fields live on the configuration tab.
		$_GET['tab'] = 'configuration';
		$output      = $this->capture_output(
			static function (): void {
				( new WP_MCP_AI_Skill_Settings_Admin_Page() )->render_page();
			}
		);
		unset( $_GET['tab'] );
		$this->assertStringContainsString( 'name="wp_mcp_ai_skill_max_per_assistant"', $output );
		$this->assertStringContainsString( 'name="wp_mcp_ai_skill_settings_nonce"', $output );
	}

	/**
	 * The settings save flow must persist the three options (clamped) and
	 * redirect with the byte-identical settings-updated query arg.
	 */
	public function test_settings_save_flow(): void {
		$this->admin_user();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Test fixture; the nonce value is crafted explicitly below.
		$_POST['wp_mcp_ai_skill_settings_nonce']    = wp_create_nonce( 'wp_mcp_ai_skill_settings' );
		$_POST['wp_mcp_ai_skills_enabled']          = '1';
		$_POST['wp_mcp_ai_skill_auto_inject']       = '1';
		$_POST['wp_mcp_ai_skill_max_per_assistant'] = '250';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$redirect = $this->capture_redirect(
			static function (): void {
				( new WP_MCP_AI_Skill_Settings_Admin_Page() )->render_page();
			}
		);

		$this->assertNotNull( $redirect );
		$this->assertStringContainsString( 'settings-updated=true', $redirect );

		$this->assertSame( 1, (int) get_option( 'wp_mcp_ai_skills_enabled' ) );
		$this->assertSame( 1, (int) get_option( 'wp_mcp_ai_skill_auto_inject' ) );
		// 250 clamps to the byte-identical 1..100 band.
		$this->assertSame( 100, (int) get_option( 'wp_mcp_ai_skill_max_per_assistant' ) );
	}

	/**
	 * The research page must render the import form (nonce field present).
	 */
	public function test_research_page_renders(): void {
		$this->admin_user();
		$output = $this->capture_output( array( 'WP_MCP_AI_Skill_Research_Admin_Page', 'render_page' ) );
		$this->assertStringContainsString( 'name="import_nonce"', $output );
	}

	/**
	 * The save AJAX flow must install the skill and echo the success payload.
	 */
	public function test_ajax_save_flow(): void {
		$this->admin_user();
		$this->craft_manager_nonce();
		$_POST['content'] = self::SAVE_CONTENT;

		$output = $this->capture_output( array( 'WP_MCP_AI_Skill_Manager_Admin_Page', 'handle_ajax_save' ) );

		$this->assertStringContainsString( 'saved successfully', $output );

		$registry = defined( 'WP_MCP_AI_PATH' )
			? WP_MCP_AI_Skill_Registry::instance()
			: \NvoosContentGraphAiPlatform\Skills\SkillRegistry::instance();
		$this->assertNotNull( $registry->get_skill( 'test-admin-skill' ) );
		$this->installed_skills[] = 'test-admin-skill';
	}

	/**
	 * The delete AJAX flow must uninstall the skill.
	 */
	public function test_ajax_delete_flow(): void {
		$this->admin_user();
		$this->craft_manager_nonce();
		$_POST['content'] = self::SAVE_CONTENT;
		$this->capture_output( array( 'WP_MCP_AI_Skill_Manager_Admin_Page', 'handle_ajax_save' ) );

		$this->craft_manager_nonce();
		$_POST['skill'] = 'test-admin-skill';
		$output         = $this->capture_output( array( 'WP_MCP_AI_Skill_Manager_Admin_Page', 'handle_ajax_delete' ) );

		$this->assertStringContainsString( 'deleted successfully', $output );

		$registry = defined( 'WP_MCP_AI_PATH' )
			? WP_MCP_AI_Skill_Registry::instance()
			: \NvoosContentGraphAiPlatform\Skills\SkillRegistry::instance();
		$this->assertNull( $registry->get_skill( 'test-admin-skill' ) );
	}

	/**
	 * The generate AJAX flow must assemble the YAML frontmatter and install.
	 */
	public function test_ajax_generate_flow(): void {
		$this->admin_user();
		$this->craft_manager_nonce();
		$_POST['name']        = 'test-generated-skill';
		$_POST['description'] = 'Generated by the admin flow test.';
		$_POST['license']     = 'MIT';

		$output = $this->capture_output( array( 'WP_MCP_AI_Skill_Manager_Admin_Page', 'handle_ajax_generate_skill' ) );

		$this->assertStringContainsString( '"success":true', $output );
		$this->assertStringContainsString( 'test-generated-skill', $output );
		$this->assertStringContainsString( 'license: MIT', $output );
		$this->assertStringContainsString( 'metadata:', $output );

		// The generate flow returns assembled content for the editor
		// preview — installation happens through the save flow.
	}

	/**
	 * A missing nonce must be rejected with the -1 die message.
	 */
	public function test_ajax_nonce_rejection(): void {
		$this->admin_user();
		$_POST['content'] = self::SAVE_CONTENT;

		$output = $this->capture_die_message( array( 'WP_MCP_AI_Skill_Manager_Admin_Page', 'handle_ajax_save' ) );
		$this->assertStringContainsString( '-1', $output );
	}

	/**
	 * Standalone only: the registry's pro_skills_manager module must boot
	 * once the init file exists.
	 */
	public function test_skills_manager_module_boots_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base registry boots the module.' );
		}

		$registry = WP_MCP_AI_Pro_Module_Registry::get_instance();
		$registry->boot();
		$this->assertTrue( $registry->is_loaded( 'pro_skills_manager' ) );
	}
}
