<?php
/**
 * Base+pro regression pins for the CRM toolkit init gate.
 *
 * Regression for the base+pro load-gate fix: with the base version constant
 * true AND the Pro addon active, requiring the CRM init with the toolkit
 * enabled must load the shared engine, wire all eight CRM CPTs, register the
 * inbound listener hooks, and expose the top-level "NV CRM" admin menu — the
 * same surface the complete build exposes. The old gate
 * (`if ( $is_enabled && ! $is_base )`) skipped all of this, so the
 * require-time side-effect pins below fail on a regression.
 *
 * Note: the Pro classmap fallback autoloader can satisfy plain
 * class_exists() checks even when the init gate blocks, so the regression
 * pins assert require-time side effects (file-scope hooks and
 * no-autoload class loads) that only exist when the gate passed.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once __DIR__ . '/helpers/trait-basepro-test-case.php';

/**
 * CRM toolkit base+pro init-gate regression tests.
 */
class Test_BasePro_CRM_Init extends WP_UnitTestCase {
	use WP_MCP_AI_BasePro_Test_Case;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->wp_mcp_ai_basepro_require_matrix();
		$this->wp_mcp_ai_basepro_enable_toolkits( array( 'enable_crm_toolkit' ) );
	}

	/**
	 * The enabled gate must let the CRM init run its boot-time wiring.
	 *
	 * The matrix bootstrap seeds enable_crm_toolkit before the plugin boots,
	 * so the Pro module registry loads this init at boot through the
	 * base+pro gate. The file-scope hooks and no-autoload class loads
	 * below only exist when that gate passed.
	 *
	 * @return void
	 */
	public function test_crm_init_gate_passes_with_pro_active(): void {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/init.php';

		// Shared engine classes (gate-only loads — the autoloader can also
		// serve these, so they are smoke pins, not regression pins).
		$this->assertTrue( class_exists( 'WP_MCP_AI_CRM_Engine' ), 'Shared CRM engine must load in base+pro.' );

		// Regression pins: file-scope side effects that only exist when the
		// gate passed. The inbound listeners are required inside the gate, so
		// a no-autoload class_exists() flips from false to true.
		$this->assertTrue( class_exists( 'WP_MCP_AI_CRM_IMAP_Listener', false ), 'IMAP listener must be required inside the gate.' );
		$this->assertTrue( class_exists( 'WP_MCP_AI_CRM_Web_Form_Listener', false ), 'Web form listener must be required inside the gate.' );

		// The inbound chat-channel pipeline and SMS webhook hooks are
		// registered at file scope inside the gate.
		$this->assertSame(
			10,
			has_action( 'wp_mcp_ai_chat_channel_message_received', 'wp_mcp_ai_crm_handle_chat_channel_message' ),
			'Inbound chat-channel listener must hook at priority 10.'
		);
		$this->assertSame(
			10,
			has_action( 'rest_api_init', array( 'WP_MCP_AI_CRM_SMS_Webhook_Listener', 'register_route' ) ),
			'SMS webhook route must hook on rest_api_init.'
		);

		// The admin surface is wired inside the gate (WP_ADMIN is defined in
		// this matrix, so the is_admin() branch runs at require time).
		$this->assertTrue( class_exists( 'WP_MCP_AI_CRM_Command_Center_Page' ), 'Command Center page must load.' );
		$this->assertTrue( class_exists( 'WP_MCP_AI_CRM_Settings_Page' ), 'CRM settings page must load.' );
		$this->assertTrue( class_exists( 'WP_MCP_AI_CRM_Blueprints_Page' ), 'CRM blueprints page must load.' );
		$this->assertTrue( class_exists( 'WP_MCP_AI_CRM_REST_Controller' ), 'CRM REST controller must load.' );
	}

	/**
	 * The eight CRM CPTs must register with their canonical slugs.
	 *
	 * @return void
	 */
	public function test_crm_cpts_register(): void {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/init.php';

		WP_MCP_AI_Company_CPT::register_post_type();
		WP_MCP_AI_Lead_CPT::register_post_type();
		WP_MCP_AI_Deal_CPT::register_post_type();
		WP_MCP_AI_CRM_Activity_CPT::register_post_type();
		WP_MCP_AI_Support_Ticket_CPT::register_post_type();
		WP_MCP_AI_Customer_CPT::register_post_type();
		WP_MCP_AI_Sequence_CPT::register_post_type();
		WP_MCP_AI_CRM_Workflow_Rule_CPT::register_post_type();

		foreach (
			array(
				'mcp_ai_company',
				'mcp_ai_lead',
				'mcp_ai_deal',
				'mcp_ai_crm_activity',
				'mcp_ai_ticket',
				'mcp_ai_customer',
				'mcp_ai_sequence',
				'mcp_ai_crm_wf_rule',
			) as $slug
		) {
			$this->assertTrue( post_type_exists( $slug ), "CRM CPT {$slug} should be registered in base+pro." );
		}
	}

	/**
	 * The top-level "NV CRM" admin menu must register in base+pro.
	 *
	 * @return void
	 */
	public function test_crm_admin_menu_hooks(): void {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/init.php';
		if ( ! class_exists( 'WP_MCP_AI_CRM_Admin_Menu', false ) ) {
			$this->markTestSkipped( 'CRM admin menu class did not load — the gate test covers that regression.' );
		}
		// Re-arm the menu hooks. Two test-framework behaviours get in the way:
		// the per-test hook snapshot restore may have removed a registration
		// made during an earlier test's first init load, and the class's
		// static first-loader guard would then refuse to re-register. Reset
		// the guard before re-arming.
		$registered = new ReflectionProperty( 'WP_MCP_AI_CRM_Admin_Menu', 'registered' );
		$registered->setValue( null, false );
		WP_MCP_AI_CRM_Admin_Menu::init();

		$this->assertSame( 'nvoos-crm-dashboard', WP_MCP_AI_CRM_Admin_Menu::PARENT_SLUG );
		$this->assertSame( 25, has_action( 'admin_menu', array( 'WP_MCP_AI_CRM_Admin_Menu', 'register_parent_menu' ) ), 'NV CRM parent menu must hook at priority 25.' );
		$this->assertSame( 28, has_action( 'admin_menu', array( 'WP_MCP_AI_CRM_Admin_Menu', 'register_submenus' ) ), 'NV CRM submenus must hook at priority 28.' );
	}
}
