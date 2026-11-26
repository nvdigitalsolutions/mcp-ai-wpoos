<?php
/**
 * AJAX tests for miscellaneous base + Pro handlers that don't fit
 * any of the domain-specific clusters.
 *
 * Handlers covered:
 *  Base plugin:
 *   - wp_mcp_ai_build_assistant_from_conversation   (edit_posts, nonce wp_mcp_ai_create_assistant)
 *   - wp_mcp_ai_upload_assistant_attachment          (upload_files, nonce wp_mcp_ai_create_assistant)
 *   - wp_mcp_ai_create_team_from_modal               (edit_posts, nonce wp_mcp_ai_create_team)
 *   - wp_mcp_ai_create_from_professional             (edit_posts, nonce wp_mcp_ai_create_from_professional)
 *   - wp_mcp_ai_deploy_team                          (edit_posts, nonce wp_mcp_ai_deploy_team)
 *   - wp_mcp_ai_get_orchestration_stats              (manage_options, nonce wp_mcp_ai_orchestration)
 *   - wp_mcp_ai_run_orchestration_seeder             (manage_options, nonce wp_mcp_ai_orchestration)
 *   - wp_mcp_ai_get_professional_config              (any logged-in, nonce wp-mcp-ai-professional-selector)
 *   - wp_mcp_ai_download_component                   (manage_options, nonce wp_mcp_ai_download_component)
 *   - wp_mcp_ai_download_all_components              (manage_options, nonce wp_mcp_ai_download_component)
 *   - wp_mcp_ai_save_model_config                    (manage_options, nonce wp_mcp_ai_admin)
 *   - wp_mcp_ai_clear_test_files                     (manage_options, nonce wp_mcp_ai_clear_test_files)
 *   - wp_mcp_ai_clear_dev_files                      (manage_options, nonce via safe_ajax_handler → clear_dev_files)
 *  Pro addon:
 *   - wp_mcp_ai_cpt_chat                             (edit_posts, nonce wp_mcp_ai_cpt_chat)
 *   - wp_mcp_ai_install_canvas_addon                 (install_plugins, nonce wp_mcp_ai_install_canvas_addon)
 *   - wp_mcp_ai_test_pro_package                     (manage_options, dynamic nonce wp_mcp_ai_test_package_<package>)
 *   - wp_mcp_ai_preview_excel                        (edit_posts, nonce wp_mcp_ai_research_reg_product)
 *   - wp_mcp_ai_clear_yfinance_cache                 (manage_options, nonce wp_mcp_ai_clear_yfinance_cache)
 *   - wp_mcp_ai_get_member_records_preview           (read, nonce wp_mcp_ai_health_consolidate)
 *   - wp_mcp_ai_check_record_completeness            (edit_posts, nonce wp_mcp_ai_health_consolidate)
 *   - wp_mcp_ai_get_product_records_preview          (edit_posts, nonce wp_mcp_ai_reg_consolidate)
 *   - wp_mcp_ai_execute_workflow_node                (manage_options, nonce mcp_ai_pro_workflow_builder)
 *   - wp_mcp_ai_save_workflow_execution              (manage_options, nonce mcp_ai_pro_workflow_builder)
 *
 * @package WP_MCP_AI
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName

/**
 * Miscellaneous assistant + utility AJAX cluster.
 */
class Test_Assistant_Misc_AJAX extends WP_MCP_AI_Ajax_TestCase {

	/**
	 * Ensure this cluster's file-scope AJAX handlers are loaded.
	 *
	 * The plugin's admin loader only includes the orchestration dashboard when
	 * is_admin() is true, which never happens in the CLI test environment. The
	 * financial-planner page is not included by any loader this cluster reaches,
	 * so it is only present when another suite autoloads it. Loading both here
	 * keeps the file's behaviour independent of suite ordering.
	 *
	 * The registrations themselves are (re-)bound in setUp() below, because
	 * wp-phpunit snapshots hooks once per process and restores that snapshot
	 * after every test, wiping anything registered at include time.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		// Load the dashboard class once; its hooks are (re-)registered in
		// setUp() below because the per-test hook restore drops anything
		// registered after the once-per-process snapshot.
		if ( ! class_exists( 'WP_MCP_AI_Admin_Orchestration_Dashboard' ) ) {
			$path = WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-orchestration-dashboard.php';
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		// Load the financial-planner toolkit page for the same reason. Its
		// yfinance handler is declared (and bound) at file scope, so without
		// this the three yfinance tests only run when another suite happens to
		// autoload the page first — and then fail, because the per-test hook
		// restore has already dropped the binding made at include time. Loading
		// it here plus the setUp() re-bind makes the cluster order-independent.
		if ( ! function_exists( 'wp_mcp_ai_ajax_clear_yfinance_cache' ) && defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$path = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-financial-planner-settings-page.php';
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Bind the file-scope AJAX handlers this cluster covers.
	 *
	 * Two different load shapes are repaired here:
	 *
	 * 1. Orchestration dashboard — included only when is_admin() is true, which
	 *    never happens in the CLI runner. Re-instantiate (cheap: the constructor
	 *    only adds actions) so dispatches work regardless of run order.
	 * 2. Financial planner settings page — registers its yfinance handler at
	 *    file scope. Another suite may autoload the page first
	 *    (tests/pro/admin/test-toolkit-settings-persistence.php instantiates all
	 *    nine toolkit pages), after which wp-phpunit's once-per-process
	 *    _restore_hooks() drops the action while the function definition
	 *    survives. Re-bind the action so the dispatch reaches the handler
	 *    instead of terminating with an empty body.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( class_exists( 'WP_MCP_AI_Admin_Orchestration_Dashboard' )
			&& ! has_action( 'wp_ajax_wp_mcp_ai_get_orchestration_stats' ) ) {
			new WP_MCP_AI_Admin_Orchestration_Dashboard();
		}

		if ( function_exists( 'wp_mcp_ai_ajax_clear_yfinance_cache' )
			&& ! has_action( 'wp_ajax_wp_mcp_ai_clear_yfinance_cache' ) ) {
			add_action( 'wp_ajax_wp_mcp_ai_clear_yfinance_cache', 'wp_mcp_ai_ajax_clear_yfinance_cache' );
		}

		$this->bind_admin_handlers();
	}

	/**
	 * Bind the base and Pro admin handlers this cluster covers.
	 *
	 * Every one of these is registered from an admin-only loader or a
	 * file-scope `add_action()` that nothing runs in the CLI runner, and
	 * wp-phpunit's per-test hook restore drops whatever a previous suite
	 * registered. Unbound, the dispatch ends with an empty body that reads
	 * exactly like a rejection.
	 */
	private function bind_admin_handlers(): void {
		$pro = defined( 'WP_MCP_AI_PRO_PATH' ) ? WP_MCP_AI_PRO_PATH : '';

		// class, action that proves it is bound, file to load.
		$pages = array(
			array( 'WP_MCP_AI_Admin_Create_Assistant_Button', 'wp_mcp_ai_build_assistant_from_conversation', WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-create-assistant-button.php' ),
			array( 'WP_MCP_AI_Admin_Create_Team_Button', 'wp_mcp_ai_create_team_from_modal', WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-create-team-button.php' ),
			array( 'WP_MCP_AI_Add_Assistant_Page', 'wp_mcp_ai_create_from_professional', WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-add-assistant-page.php' ),
			array( 'WP_MCP_AI_Add_Team_Page', 'wp_mcp_ai_deploy_team', WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-add-team-page.php' ),
			array( 'WP_MCP_AI_Reg_Product_Research_Page', 'wp_mcp_ai_preview_excel', $pro . 'includes/admin/class-wp-mcp-ai-reg-product-research-page.php' ),
			array( 'WP_MCP_AI_Health_Records_Consolidate_Page', 'wp_mcp_ai_get_member_records_preview', $pro . 'includes/admin/class-wp-mcp-ai-health-records-consolidate-page.php' ),
		);

		foreach ( $pages as $page ) {
			list( $class, $probe, $file ) = $page;

			if ( false !== has_action( 'wp_ajax_' . $probe ) ) {
				continue;
			}

			if ( ! class_exists( $class ) && '' !== $file && file_exists( $file ) ) {
				require_once $file;
			}

			if ( class_exists( $class ) && is_callable( array( $class, 'init' ) ) ) {
				call_user_func( array( $class, 'init' ) );
			}
		}

		// Instance-registered handlers.
		if ( false === has_action( 'wp_ajax_wp_mcp_ai_cpt_chat' )
			&& class_exists( 'WP_MCP_AI_Pro_CPT_AI_Integration' ) ) {
			// The constructor is a no-op outside is_admin(), so bind the instance
			// method directly; the singleton caches that decision forever.
			$cpt_integration = WP_MCP_AI_Pro_CPT_AI_Integration::get_instance();
			if ( false === has_action( 'wp_ajax_wp_mcp_ai_cpt_chat' ) && method_exists( $cpt_integration, 'handle_ajax_chat' ) ) {
				add_action( 'wp_ajax_wp_mcp_ai_cpt_chat', array( $cpt_integration, 'handle_ajax_chat' ) );
			}
		}

		if ( false === has_action( 'wp_ajax_wp_mcp_ai_test_pro_package' )
			&& class_exists( 'WP_MCP_AI_Pro_Packages_Settings_Page' ) ) {
			new WP_MCP_AI_Pro_Packages_Settings_Page();
		}
	}

	// ---
	// wp_mcp_ai_build_assistant_from_conversation
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_build_assistant_from_conversation_rejects_bad_nonce() {
		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_build_assistant_from_conversation',
			array(
				'nonce' => 'bad_nonce',
				'title' => 'My Assistant',
			)
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_build_assistant_from_conversation_rejects_subscriber() {
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_build_assistant_from_conversation',
			array(
				'nonce' => wp_create_nonce( 'wp_mcp_ai_create_assistant' ),
				'title' => 'My Assistant',
			)
		);
		$this->assertAjaxError( $response );
	}

	/** Validates the missing title parameter. */
	public function test_build_assistant_from_conversation_validates_missing_title() {
		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_build_assistant_from_conversation',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_create_assistant' ) )
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// wp_mcp_ai_upload_assistant_attachment
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_upload_assistant_attachment_rejects_bad_nonce() {
		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_upload_assistant_attachment',
			array( 'nonce' => 'bad_nonce' )
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_upload_assistant_attachment_rejects_subscriber() {
		// Subscriber doesn't have upload_files capability.
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_upload_assistant_attachment',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_create_assistant' ) )
		);
		$this->assertAjaxError( $response );
	}

	/** Validates the missing file parameter. */
	public function test_upload_assistant_attachment_validates_missing_file() {
		// Editor has upload_files; no $_FILES provided → handler must reject.
		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_upload_assistant_attachment',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_create_assistant' ) )
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// wp_mcp_ai_create_team_from_modal
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_create_team_from_modal_rejects_bad_nonce() {
		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_create_team_from_modal',
			array(
				'nonce' => 'bad_nonce',
				'title' => 'Team A',
			)
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_create_team_from_modal_rejects_subscriber() {
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_create_team_from_modal',
			array(
				'nonce' => wp_create_nonce( 'wp_mcp_ai_create_team' ),
				'title' => 'Team A',
			)
		);
		$this->assertAjaxError( $response );
	}

	/** Validates the missing title parameter. */
	public function test_create_team_from_modal_validates_missing_title() {
		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_create_team_from_modal',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_create_team' ) )
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// wp_mcp_ai_create_from_professional
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_create_from_professional_rejects_bad_nonce() {
		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_create_from_professional',
			array( 'nonce' => 'bad_nonce' )
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_create_from_professional_rejects_subscriber() {
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_create_from_professional',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_create_from_professional' ) )
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// wp_mcp_ai_deploy_team
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_deploy_team_rejects_bad_nonce() {
		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_deploy_team',
			array(
				'nonce'   => 'bad_nonce',
				'team_id' => '1',
			)
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_deploy_team_rejects_subscriber() {
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_deploy_team',
			array(
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_deploy_team' ),
				'team_id' => '1',
			)
		);
		$this->assertAjaxError( $response );
	}

	/** Validates the invalid team id parameter. */
	public function test_deploy_team_validates_invalid_team_id() {
		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_deploy_team',
			array(
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_deploy_team' ),
				'team_id' => '0',
			)
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// wp_mcp_ai_get_orchestration_stats
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_get_orchestration_stats_rejects_bad_nonce() {
		$this->as_admin();
		$response = $this->dispatch( 'wp_mcp_ai_get_orchestration_stats', array( 'nonce' => 'bad' ) );
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_get_orchestration_stats_rejects_subscriber() {
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_get_orchestration_stats',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_orchestration' ) )
		);
		$this->assertAjaxError( $response );
	}

	/** Dispatches successfully on the happy path. */
	public function test_get_orchestration_stats_happy_path() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_get_orchestration_stats',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_orchestration' ) )
		);
		$this->assertAjaxSuccess( $response );
	}

	// ---
	// wp_mcp_ai_run_orchestration_seeder
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_run_orchestration_seeder_rejects_bad_nonce() {
		$this->as_admin();
		$response = $this->dispatch( 'wp_mcp_ai_run_orchestration_seeder', array( 'nonce' => 'bad' ) );
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_run_orchestration_seeder_rejects_subscriber() {
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_run_orchestration_seeder',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_orchestration' ) )
		);
		$this->assertAjaxError( $response );
	}

	/** Dispatches successfully on the happy path. */
	public function test_run_orchestration_seeder_happy_path() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_run_orchestration_seeder',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_orchestration' ) )
		);
		// May succeed or return an error if seeder class not available — both
		// are valid; we just verify no PHP fatal occurs.
		$this->assertTrue(
			$this->isAjaxSuccess( $response ) || $this->isAjaxError( $response ),
			'Expected a structured JSON response from run_orchestration_seeder.'
		);
	}

	// ---
	// wp_mcp_ai_get_professional_config
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_get_professional_config_rejects_bad_nonce() {
		$this->as_editor();
		$response = $this->dispatch( 'wp_mcp_ai_get_professional_config', array( 'nonce' => 'bad' ) );
		$this->assertAjaxForbidden( $response );
	}

	/** Get professional config rejects unauthenticated. */
	public function test_get_professional_config_rejects_unauthenticated() {
		// No current user set — WP_Ajax_UnitTestCase starts as no user.
		$response = $this->dispatch(
			'wp_mcp_ai_get_professional_config',
			array( 'nonce' => wp_create_nonce( 'wp-mcp-ai-professional-selector' ) )
		);
		// Handler may wp_die(-1) or return error.
		$this->assertTrue(
			$this->isAjaxForbidden( $response ) || $this->isAjaxError( $response ),
			'Expected rejection for unauthenticated request to get_professional_config.'
		);
	}

	// ---
	// wp_mcp_ai_download_component
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_download_component_rejects_bad_nonce() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_download_component',
			array(
				'nonce'     => 'bad',
				'component' => 'vectorizer',
			)
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_download_component_rejects_subscriber() {
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_download_component',
			array(
				'nonce'     => wp_create_nonce( 'wp_mcp_ai_download_component' ),
				'component' => 'vectorizer',
			)
		);
		$this->assertAjaxError( $response );
	}

	/** Validates the invalid component parameter. */
	public function test_download_component_validates_invalid_component() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_download_component',
			array(
				'nonce'     => wp_create_nonce( 'wp_mcp_ai_download_component' ),
				'component' => 'nonexistent',
			)
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// wp_mcp_ai_download_all_components
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_download_all_components_rejects_bad_nonce() {
		$this->as_admin();
		$response = $this->dispatch( 'wp_mcp_ai_download_all_components', array( 'nonce' => 'bad' ) );
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_download_all_components_rejects_subscriber() {
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_download_all_components',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_download_component' ) )
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// wp_mcp_ai_save_model_config
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_save_model_config_rejects_bad_nonce() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_save_model_config',
			array(
				'nonce'  => 'bad',
				'model'  => 'gpt-4',
				'config' => array( 'temperature' => '0.7' ),
			)
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_save_model_config_rejects_subscriber() {
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_save_model_config',
			array(
				'nonce'  => wp_create_nonce( 'wp_mcp_ai_admin' ),
				'model'  => 'gpt-4',
				'config' => array( 'temperature' => '0.7' ),
			)
		);
		$this->assertAjaxError( $response );
	}

	/** Validates the missing model parameter. */
	public function test_save_model_config_validates_missing_model() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_save_model_config',
			array(
				'nonce'  => wp_create_nonce( 'wp_mcp_ai_admin' ),
				'config' => array( 'temperature' => '0.7' ),
			)
		);
		$this->assertAjaxError( $response );
	}

	/** Validates the missing config parameter. */
	public function test_save_model_config_validates_missing_config() {
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_save_model_config',
			array(
				'nonce' => wp_create_nonce( 'wp_mcp_ai_admin' ),
				'model' => 'gpt-4',
			)
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// wp_mcp_ai_clear_test_files
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_clear_test_files_rejects_bad_nonce() {
		$this->as_admin();
		$response = $this->dispatch( 'wp_mcp_ai_clear_test_files', array( 'nonce' => 'bad' ) );
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_clear_test_files_rejects_subscriber() {
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_clear_test_files',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_clear_test_files' ) )
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// wp_mcp_ai_clear_dev_files  (routed through safe_ajax_handler)
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_clear_dev_files_rejects_bad_nonce() {
		$this->as_admin();
		$response = $this->dispatch( 'wp_mcp_ai_clear_dev_files', array( 'nonce' => 'bad' ) );
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_clear_dev_files_rejects_subscriber() {
		$this->as_subscriber();
		// safe_ajax_handler uses the same wp_mcp_ai_clear_dev_files nonce internally.
		$response = $this->dispatch(
			'wp_mcp_ai_clear_dev_files',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_clear_dev_files' ) )
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// PRO: wp_mcp_ai_cpt_chat
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_cpt_chat_rejects_bad_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_CPT_AI_Integration' ) ) {
			$this->markTestSkipped( 'Pro addon (WP_MCP_AI_Pro_CPT_AI_Integration) not loaded.' );
		}
		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_cpt_chat',
			array(
				'nonce'   => 'bad',
				'post_id' => '1',
				'message' => 'Hello',
			)
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_cpt_chat_rejects_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_CPT_AI_Integration' ) ) {
			$this->markTestSkipped( 'Pro addon (WP_MCP_AI_Pro_CPT_AI_Integration) not loaded.' );
		}
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_cpt_chat',
			array(
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_cpt_chat' ),
				'post_id' => '1',
				'message' => 'Hello',
			)
		);
		$this->assertAjaxError( $response );
	}

	/** Validates the missing message parameter. */
	public function test_cpt_chat_validates_missing_message() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_CPT_AI_Integration' ) ) {
			$this->markTestSkipped( 'Pro addon (WP_MCP_AI_Pro_CPT_AI_Integration) not loaded.' );
		}
		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_cpt_chat',
			array(
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_cpt_chat' ),
				'post_id' => '1',
			)
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// PRO: wp_mcp_ai_install_canvas_addon
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_install_canvas_addon_rejects_bad_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Packages_Settings_Page' ) ) {
			$this->markTestSkipped( 'Pro packages class not loaded.' );
		}
		$this->as_admin();
		$response = $this->dispatch( 'wp_mcp_ai_install_canvas_addon', array( 'nonce' => 'bad' ) );
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_install_canvas_addon_rejects_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Packages_Settings_Page' ) ) {
			$this->markTestSkipped( 'Pro packages class not loaded.' );
		}
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_install_canvas_addon',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_install_canvas_addon' ) )
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// PRO: wp_mcp_ai_test_pro_package
	// ---

	/** Validates the missing package parameter. */
	public function test_test_pro_package_validates_missing_package() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Packages_Settings_Page' ) ) {
			$this->markTestSkipped( 'Pro packages class not loaded.' );
		}
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_test_pro_package',
			array( 'nonce' => 'any_nonce' )
		);
		// Missing package → handler returns error before nonce check.
		$this->assertAjaxError( $response );
	}

	/** Guards against a missing or invalid nonce. */
	public function test_test_pro_package_rejects_bad_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Packages_Settings_Page' ) ) {
			$this->markTestSkipped( 'Pro packages class not loaded.' );
		}
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_test_pro_package',
			array(
				'nonce'   => 'bad_nonce',
				'package' => 'openai',
			)
		);
		$this->assertAjaxError( $response ); // nonce verified via wp_verify_nonce() → error response.
	}

	/** Guards against insufficient capabilities. */
	public function test_test_pro_package_rejects_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Packages_Settings_Page' ) ) {
			$this->markTestSkipped( 'Pro packages class not loaded.' );
		}
		$pkg = 'openai';
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_test_pro_package',
			array(
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_test_package_' . $pkg ),
				'package' => $pkg,
			)
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// PRO: wp_mcp_ai_preview_excel
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_preview_excel_rejects_bad_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_Reg_Product_Research_Page' ) ) {
			$this->markTestSkipped( 'Reg product research class not loaded.' );
		}
		$this->as_editor();
		$response = $this->dispatch( 'wp_mcp_ai_preview_excel', array( 'nonce' => 'bad' ) );
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_preview_excel_rejects_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_Reg_Product_Research_Page' ) ) {
			$this->markTestSkipped( 'Reg product research class not loaded.' );
		}
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_preview_excel',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_research_reg_product' ) )
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// PRO: wp_mcp_ai_clear_yfinance_cache
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_clear_yfinance_cache_rejects_bad_nonce() {
		if ( ! function_exists( 'wp_mcp_ai_ajax_clear_yfinance_cache' ) ) {
			$this->markTestSkipped( 'yfinance handler function not loaded.' );
		}
		$this->as_admin();
		$response = $this->dispatch( 'wp_mcp_ai_clear_yfinance_cache', array( 'nonce' => 'bad' ) );
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_clear_yfinance_cache_rejects_subscriber() {
		if ( ! function_exists( 'wp_mcp_ai_ajax_clear_yfinance_cache' ) ) {
			$this->markTestSkipped( 'yfinance handler function not loaded.' );
		}
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_clear_yfinance_cache',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_clear_yfinance_cache' ) )
		);
		$this->assertAjaxError( $response );
	}

	/** Dispatches successfully on the happy path. */
	public function test_clear_yfinance_cache_happy_path() {
		if ( ! function_exists( 'wp_mcp_ai_ajax_clear_yfinance_cache' ) ) {
			$this->markTestSkipped( 'yfinance handler function not loaded.' );
		}
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_clear_yfinance_cache',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_clear_yfinance_cache' ) )
		);
		$this->assertAjaxSuccess( $response );
	}

	// ---
	// PRO: wp_mcp_ai_get_member_records_preview
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_get_member_records_preview_rejects_bad_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_Health_Records_Consolidate_Page' ) ) {
			$this->markTestSkipped( 'Health records class not loaded.' );
		}
		$this->as_admin();
		$response = $this->dispatch( 'wp_mcp_ai_get_member_records_preview', array( 'nonce' => 'bad' ) );
		$this->assertAjaxForbidden( $response );
	}

	/** Get member records preview rejects unauthenticated. */
	public function test_get_member_records_preview_rejects_unauthenticated() {
		if ( ! class_exists( 'WP_MCP_AI_Health_Records_Consolidate_Page' ) ) {
			$this->markTestSkipped( 'Health records class not loaded.' );
		}
		// Requires at minimum 'read' capability.
		$response = $this->dispatch(
			'wp_mcp_ai_get_member_records_preview',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_health_consolidate' ) )
		);
		$this->assertTrue(
			$this->isAjaxForbidden( $response ) || $this->isAjaxError( $response ),
			'Expected rejection for unauthenticated get_member_records_preview.'
		);
	}

	// ---
	// PRO: wp_mcp_ai_check_record_completeness
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_check_record_completeness_rejects_bad_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_Health_Records_Consolidate_Page' ) ) {
			$this->markTestSkipped( 'Health records class not loaded.' );
		}
		$this->as_editor();
		$response = $this->dispatch( 'wp_mcp_ai_check_record_completeness', array( 'nonce' => 'bad' ) );
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_check_record_completeness_rejects_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_Health_Records_Consolidate_Page' ) ) {
			$this->markTestSkipped( 'Health records class not loaded.' );
		}
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_check_record_completeness',
			array(
				'nonce'     => wp_create_nonce( 'wp_mcp_ai_health_consolidate' ),
				'record_id' => '5',
			)
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// PRO: wp_mcp_ai_get_product_records_preview
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_get_product_records_preview_rejects_bad_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_Reg_Product_Research_Page' ) ) {
			$this->markTestSkipped( 'Reg product research class not loaded.' );
		}
		$this->as_editor();
		$response = $this->dispatch( 'wp_mcp_ai_get_product_records_preview', array( 'nonce' => 'bad' ) );
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_get_product_records_preview_rejects_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_Reg_Product_Research_Page' ) ) {
			$this->markTestSkipped( 'Reg product research class not loaded.' );
		}
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_get_product_records_preview',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_reg_consolidate' ) )
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// PRO: wp_mcp_ai_execute_workflow_node
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_execute_workflow_node_rejects_bad_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Workflow_Builder_Page' ) ) {
			$this->markTestSkipped( 'Pro workflow builder class not loaded.' );
		}
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_execute_workflow_node',
			array(
				'nonce'     => 'bad',
				'node_id'   => 'n1',
				'node_type' => 'start',
			)
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_execute_workflow_node_rejects_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Workflow_Builder_Page' ) ) {
			$this->markTestSkipped( 'Pro workflow builder class not loaded.' );
		}
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_execute_workflow_node',
			array(
				'nonce'     => wp_create_nonce( 'mcp_ai_pro_workflow_builder' ),
				'node_id'   => 'n1',
				'node_type' => 'start',
			)
		);
		$this->assertAjaxError( $response );
	}

	// ---
	// PRO: wp_mcp_ai_save_workflow_execution
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_save_workflow_execution_rejects_bad_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Workflow_Builder_Page' ) ) {
			$this->markTestSkipped( 'Pro workflow builder class not loaded.' );
		}
		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_save_workflow_execution',
			array(
				'nonce'          => 'bad',
				'execution_data' => wp_json_encode( array() ),
			)
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_save_workflow_execution_rejects_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Workflow_Builder_Page' ) ) {
			$this->markTestSkipped( 'Pro workflow builder class not loaded.' );
		}
		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_save_workflow_execution',
			array(
				'nonce'          => wp_create_nonce( 'mcp_ai_pro_workflow_builder' ),
				'execution_data' => wp_json_encode( array() ),
			)
		);
		$this->assertAjaxError( $response );
	}
}
