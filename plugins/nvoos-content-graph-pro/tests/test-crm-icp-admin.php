<?php
/**
 * Characterization tests for the Wave F2 CRM ICP + admin slice — the
 * ported ICP profile store, ICP scorer, the two ICP tools, the CRM admin
 * menu, and the ICP admin page.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical contracts are
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/crm/icp/` + `src/admin/` are asserted in full, including
 *   the serving sources and the ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRM ICP + admin slice tests.
 */
class Test_Crm_Icp_Admin extends WP_UnitTestCase {

	/**
	 * Snapshot of the shared settings option before mutation.
	 *
	 * @var mixed
	 */
	private $settings_snapshot = null;

	/**
	 * Snapshot the shared settings option.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->settings_snapshot = get_option( 'wp_mcp_ai_settings', null );
	}

	/**
	 * Restore the shared settings option.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		if ( null === $this->settings_snapshot ) {
			delete_option( 'wp_mcp_ai_settings' );
		} else {
			update_option( 'wp_mcp_ai_settings', $this->settings_snapshot );
		}
		delete_option( WP_MCP_AI_ICP_Profile::OPTION_KEY );
		parent::tearDown();
	}

	/**
	 * Enable the CRM toolkit flag in the shared settings option.
	 *
	 * @return void
	 */
	private function enable_crm_toolkit(): void {
		$settings                       = get_option( 'wp_mcp_ai_settings', array() );
		$settings                       = is_array( $settings ) ? $settings : array();
		$settings['enable_crm_toolkit'] = 1;
		update_option( 'wp_mcp_ai_settings', $settings );
	}

	/**
	 * The serving sources must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$files = array(
			'WP_MCP_AI_ICP_Profile'             => 'tools/crm/icp/class-wp-mcp-ai-icp-profile.php',
			'WP_MCP_AI_ICP_Scorer'              => 'tools/crm/icp/class-wp-mcp-ai-icp-scorer.php',
			'WP_MCP_AI_Tool_Compute_ICP_Score'  => 'tools/crm/icp/class-wp-mcp-ai-tool-compute-icp-score.php',
			'WP_MCP_AI_Tool_Manage_ICP_Profile' => 'tools/crm/icp/class-wp-mcp-ai-tool-manage-icp-profile.php',
			'WP_MCP_AI_CRM_Admin_Menu'          => 'admin/class-wp-mcp-ai-crm-admin-menu.php',
			'WP_MCP_AI_ICP_Admin_Page'          => 'admin/class-wp-mcp-ai-icp-admin-page.php',
		);

		foreach ( $files as $class => $suffix ) {
			$reflection = new ReflectionClass( $class );
			$file       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'addons/pro/includes/' . $suffix, $file, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/' . $suffix, $file, $class );
			}
		}
	}

	/**
	 * The ICP profile store must keep the byte-identical option-backed CRUD
	 * contract.
	 */
	public function test_icp_profile_crud(): void {
		$this->assertSame( 'wp_mcp_ai_icp_profiles', WP_MCP_AI_ICP_Profile::OPTION_KEY );

		$profile         = WP_MCP_AI_ICP_Profile::get_example_profile();
		$profile['id']   = 'b2b-saas';
		$profile['name'] = 'B2B SaaS';

		$saved = WP_MCP_AI_ICP_Profile::save( $profile );
		$this->assertTrue( $saved );

		$loaded = WP_MCP_AI_ICP_Profile::get( 'b2b-saas' );
		$this->assertSame( 'B2B SaaS', $loaded['name'] );

		$this->assertArrayHasKey( 'b2b-saas', WP_MCP_AI_ICP_Profile::get_all() );

		WP_MCP_AI_ICP_Profile::set_default( 'b2b-saas' );
		$default = WP_MCP_AI_ICP_Profile::get_default();
		$this->assertSame( 'b2b-saas', $default['id'] );

		// Validation contract.
		$this->assertIsArray( WP_MCP_AI_ICP_Profile::validate_profile( $profile ) );

		// The store refuses to delete the last remaining profile — seed a
		// second one first (byte-identical guard).
		$second         = WP_MCP_AI_ICP_Profile::get_example_profile();
		$second['id']   = 'enterprise-retail';
		$second['name'] = 'Enterprise Retail';
		WP_MCP_AI_ICP_Profile::save( $second );

		$deleted = WP_MCP_AI_ICP_Profile::delete( 'b2b-saas' );
		$this->assertTrue( $deleted );
		$this->assertNull( WP_MCP_AI_ICP_Profile::get( 'b2b-saas' ) );
	}

	/**
	 * The ICP scorer must compute byte-identical 0-100 scores with tiers.
	 */
	public function test_icp_scorer_compute_score(): void {
		$profile       = WP_MCP_AI_ICP_Profile::get_example_profile();
		$profile['id'] = 'b2b-saas';
		WP_MCP_AI_ICP_Profile::save( $profile );

		$company = array(
			'company_name' => 'Acme Cloud',
			'industry'     => 'Technology',
			'company_size' => '51-200',
		);

		$result = WP_MCP_AI_ICP_Scorer::compute_score( $company, $profile );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'total_score', $result );
		$this->assertArrayHasKey( 'tier', $result );
		$this->assertGreaterThanOrEqual( 0, $result['total_score'] );
		$this->assertLessThanOrEqual( 100, $result['total_score'] );

		$all = WP_MCP_AI_ICP_Scorer::score_against_all_profiles( $company );
		$this->assertIsArray( $all );
	}

	/**
	 * The Compute ICP Score tool must keep the byte-identical pre-flight
	 * contracts. Note (upstream latent bug, byte-identical): the tool's
	 * resolve_profile() casts the profile id to int and passes it to
	 * compute_score() (which type-hints an array), so the compute step
	 * itself TypeError-crashes once data completeness passes — the paths
	 * before that point are pinned here.
	 */
	public function test_compute_icp_score_tool(): void {
		$this->enable_crm_toolkit();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/class-wp-mcp-ai-company-cpt.php';
		} else {
			require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-company-cpt.php';
		}
		WP_MCP_AI_Company_CPT::register_post_type();

		$tool = new WP_MCP_AI_Tool_Compute_ICP_Score();
		$this->assertSame( 'compute_icp_score', $tool->get_slug() );
		$this->assertSame( 'edit_posts', $tool->get_required_capability() );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		$context = array( 'user_id' => $user_id );

		// No default profile configured.
		delete_option( WP_MCP_AI_ICP_Profile::OPTION_KEY );
		$no_profile = $tool->execute(
			array(
				'company_data' => array(
					'company_name' => 'Acme Cloud',
				),
			),
			$context
		);
		$this->assertInstanceOf( 'WP_Error', $no_profile );
		$this->assertSame( 'wp_mcp_ai_no_default_profile', $no_profile->get_error_code() );

		// Profile configured but too few data points.
		WP_MCP_AI_ICP_Profile::save( WP_MCP_AI_ICP_Profile::get_example_profile() );
		$sparse = $tool->execute(
			array(
				'company_data' => array(
					'company_name' => 'Acme Cloud',
					'industry'     => 'Technology',
				),
			),
			$context
		);
		$this->assertInstanceOf( 'WP_Error', $sparse );
		$this->assertSame( 'wp_mcp_ai_insufficient_data', $sparse->get_error_code() );
	}

	/**
	 * The Manage ICP Profile tool must keep the action contract.
	 */
	public function test_manage_icp_profile_tool(): void {
		$this->enable_crm_toolkit();

		$tool = new WP_MCP_AI_Tool_Manage_ICP_Profile();
		$this->assertSame( 'manage_icp_profile', $tool->get_slug() );

		// The manage tool is gated on manage_options (byte-identical map).
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$context = array( 'user_id' => $user_id );

		$missing = $tool->execute( array(), $context );
		$this->assertInstanceOf( 'WP_Error', $missing );
		$this->assertSame( 'icp_missing_action', $missing->get_error_code() );

		$invalid = $tool->execute( array( 'action' => 'bogus' ), $context );
		$this->assertInstanceOf( 'WP_Error', $invalid );
		$this->assertSame( 'icp_invalid_action', $invalid->get_error_code() );

		$listed = $tool->execute( array( 'action' => 'list' ), $context );
		$this->assertIsArray( $listed );
		$this->assertArrayHasKey( 'count', $listed );
		$this->assertArrayHasKey( 'profiles', $listed );

		// Note (upstream latent bug, byte-identical): handle_create() never
		// stamps the generated slug into the profile data's `id` field, so
		// validate_profile() rejects every create with
		// icp_validation_missing_id. The contract is pinned as-is.
		$created = $tool->execute(
			array(
				'action'       => 'create',
				'profile_data' => array(
					'name'        => 'Created Via Tool',
					'description' => 'From the characterization test',
				),
			),
			$context
		);
		$this->assertInstanceOf( 'WP_Error', $created );
		$this->assertSame( 'icp_validation_missing_id', $created->get_error_code() );
	}

	/**
	 * The CRM admin menu must keep the byte-identical parent slug and hooks.
	 */
	public function test_crm_admin_menu(): void {
		$this->assertSame( 'nvoos-crm-dashboard', WP_MCP_AI_CRM_Admin_Menu::PARENT_SLUG );
		$this->assertSame( 'nvoos-crm-dashboard', WP_MCP_AI_CRM_Admin_Menu::get_parent_slug() );

		WP_MCP_AI_CRM_Admin_Menu::init();
		$this->assertSame( 25, has_action( 'admin_menu', array( 'WP_MCP_AI_CRM_Admin_Menu', 'register_parent_menu' ) ) );
		$this->assertSame( 28, has_action( 'admin_menu', array( 'WP_MCP_AI_CRM_Admin_Menu', 'register_submenus' ) ) );
	}

	/**
	 * The ICP admin page must keep the byte-identical slug and hooks.
	 */
	public function test_icp_admin_page(): void {
		$this->assertSame( 'nvoos-crm-icp-profiles', WP_MCP_AI_ICP_Admin_Page::PAGE_SLUG );
		$this->assertSame( 'wp_mcp_ai_icp_profiles', WP_MCP_AI_ICP_Admin_Page::OPTION_NAME );
		$this->assertSame( 'wp_mcp_ai_icp_admin_action', WP_MCP_AI_ICP_Admin_Page::NONCE_ACTION );

		WP_MCP_AI_ICP_Admin_Page::init();
		$this->assertSame( 30, has_action( 'admin_menu', array( 'WP_MCP_AI_ICP_Admin_Page', 'register_page' ) ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( 'WP_MCP_AI_ICP_Admin_Page', 'enqueue_assets' ) ) );
	}

	/**
	 * Standalone only: the init's tool filter must carry the ICP tools and
	 * the ecosystem registration must register them.
	 */
	public function test_icp_filter_and_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the CRM tool map inline.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_crm_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Compute_ICP_Score', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Manage_ICP_Profile', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/icp/class-wp-mcp-ai-tool-compute-icp-score.php',
			$tools['WP_MCP_AI_Tool_Compute_ICP_Score']
		);

		wp_mcp_ai_pro_register_crm_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['compute_icp_score'] ?? null );
		$this->assertNotNull( $parent->all()['manage_icp_profile'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'compute_icp_score' ) );
		$this->assertTrue( $core_tools->has( 'manage_icp_profile' ) );
	}
}
