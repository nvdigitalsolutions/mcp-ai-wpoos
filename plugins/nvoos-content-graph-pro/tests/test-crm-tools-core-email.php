<?php
/**
 * Characterization tests for the Wave F2 CRM core + email-search tool batch —
 * the ported `manage-crm-contact`, `crm-email-search-*` (3), and
 * `crm-capture-interaction` tools plus their support symbols (the
 * relevance-search trait, the capture-tool base, and the Gmail client).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the tool
 *   classes (classmap-autoloaded); the byte-identical surface and the
 *   deterministic execute() gates are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/crm|tools/capture|services|traits/` are asserted in full,
 *   including the ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRM core + email-search tool batch tests.
 */
class Test_Crm_Tools_Core_Email extends WP_UnitTestCase {

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
	 * The eight ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Manage_CRM_Contact'             => 'tools/crm/class-wp-mcp-ai-tool-manage-crm-contact.php',
			'WP_MCP_AI_Tool_CRM_Email_Search_Leads'         => 'tools/crm/class-wp-mcp-ai-tool-crm-email-search-leads.php',
			'WP_MCP_AI_Tool_CRM_Email_Search_Correspondence' => 'tools/crm/class-wp-mcp-ai-tool-crm-email-search-correspondence.php',
			'WP_MCP_AI_Tool_CRM_Email_Search_Accounting'    => 'tools/crm/class-wp-mcp-ai-tool-crm-email-search-accounting.php',
			'WP_MCP_AI_Tool_CRM_Capture_Interaction'        => 'tools/crm/class-wp-mcp-ai-tool-crm-capture-interaction.php',
			'WP_MCP_AI_Pro_Capture_Tool_Base'               => 'tools/capture/class-wp-mcp-ai-pro-capture-tool-base.php',
			'WP_MCP_AI_CRM_Gmail_Client'                    => 'services/class-wp-mcp-ai-crm-gmail-client.php',
		);

		foreach ( $symbols as $class => $file ) {
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'addons/pro/includes/' . $file, $path, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/' . $file, $path, $class );
			}
		}

		// The relevance-search trait (served by whichever file the tool
		// top-level require resolved).
		$trait_reflection = new ReflectionClass( 'WP_MCP_AI_CRM_Relevance_Search' );
		$trait_path       = str_replace( '\\', '/', (string) $trait_reflection->getFileName() );
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertStringContainsString( 'addons/pro/includes/traits/trait-wp-mcp-ai-relevance-search.php', $trait_path );
		} else {
			$this->assertStringContainsString( 'nvoos-content-graph-pro/src/traits/trait-wp-mcp-ai-relevance-search.php', $trait_path );
		}
	}

	/**
	 * The five tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Manage_CRM_Contact'          => 'manage_crm_contact',
			'WP_MCP_AI_Tool_CRM_Email_Search_Leads'      => 'crm_email_search_leads',
			'WP_MCP_AI_Tool_CRM_Email_Search_Correspondence' => 'crm_email_search_correspondence',
			'WP_MCP_AI_Tool_CRM_Email_Search_Accounting' => 'crm_email_search_accounting',
			'WP_MCP_AI_Tool_CRM_Capture_Interaction'     => 'crm_capture_interaction',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}

		$manage  = new WP_MCP_AI_Tool_Manage_CRM_Contact();
		$flags_m = $manage->get_capability_flags();
		$this->assertContains( 'pro', $flags_m );
		$this->assertContains( 'write', $flags_m );
		$this->assertContains( 'requires-capability', $flags_m );

		$leads   = new WP_MCP_AI_Tool_CRM_Email_Search_Leads();
		$flags_l = $leads->get_capability_flags();
		$this->assertContains( 'pro', $flags_l );
		$this->assertContains( 'read-only', $flags_l );

		$capture = new WP_MCP_AI_Tool_CRM_Capture_Interaction();
		$flags_c = $capture->get_capability_flags();
		$this->assertContains( 'pro', $flags_c );
		$this->assertContains( 'write', $flags_c );
		$this->assertContains( 'state-changing', $flags_c );
		$this->assertContains( 'pii-data', $flags_c );
	}

	/**
	 * The email-search forbidden gate must fire without a capable user.
	 */
	public function test_email_search_forbidden_gate(): void {
		$this->enable_crm_toolkit();
		$tool = new WP_MCP_AI_Tool_CRM_Email_Search_Leads();

		$result = $tool->execute( array( 'action' => 'search' ), array() );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * manage-crm-contact must reject unknown actions.
	 */
	public function test_manage_crm_contact_invalid_action(): void {
		$tool   = new WP_MCP_AI_Tool_Manage_CRM_Contact();
		$result = $tool->execute( array(), array() );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'tool_error', $result->get_error_code() );
	}

	/**
	 * The capture tool's argument gates must be deterministic.
	 */
	public function test_capture_argument_gates(): void {
		$tool = new WP_MCP_AI_Tool_CRM_Capture_Interaction();

		$result = $tool->execute( array(), array() );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'capture_missing_wing_key', $result->get_error_code() );

		$result = $tool->execute(
			array(
				'account_id' => 'acct-1',
				'room'       => 'not-a-room',
			),
			array()
		);
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'capture_invalid_room', $result->get_error_code() );
	}

	/**
	 * Standalone only: the init's tool filter must carry the core + email
	 * batch.
	 */
	public function test_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the CRM tool map inline.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_crm_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Manage_CRM_Contact', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_CRM_Email_Search_Leads', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_CRM_Email_Search_Correspondence', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_CRM_Email_Search_Accounting', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_CRM_Capture_Interaction', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/class-wp-mcp-ai-tool-crm-email-search-leads.php',
			$tools['WP_MCP_AI_Tool_CRM_Email_Search_Leads']
		);
	}

	/**
	 * Standalone only: the ecosystem registration must register the core +
	 * email batch into both registries.
	 */
	public function test_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		wp_mcp_ai_pro_register_crm_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['manage_crm_contact'] ?? null );
		$this->assertNotNull( $parent->all()['crm_email_search_leads'] ?? null );
		$this->assertNotNull( $parent->all()['crm_capture_interaction'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'manage_crm_contact' ) );
		$this->assertTrue( $core_tools->has( 'crm_email_search_leads' ) );
		$this->assertTrue( $core_tools->has( 'crm_email_search_correspondence' ) );
		$this->assertTrue( $core_tools->has( 'crm_email_search_accounting' ) );
		$this->assertTrue( $core_tools->has( 'crm_capture_interaction' ) );
	}
}
