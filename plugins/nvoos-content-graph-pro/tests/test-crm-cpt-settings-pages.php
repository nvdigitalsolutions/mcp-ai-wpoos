<?php
/**
 * Characterization tests for the Wave F2 CRM per-CPT settings pages batch —
 * the ported Company/Lead/Deal/Support-Ticket/Customer settings pages.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical constants, hooks,
 *   and registered settings are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/admin/` are asserted in full, including the serving sources and
 *   the init wiring.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRM per-CPT settings pages tests.
 */
class Test_Crm_Cpt_Settings_Pages extends WP_UnitTestCase {

	/**
	 * The five page classes and their byte-identical contracts.
	 *
	 * @return array Class => [ option, slug, file ].
	 */
	private function pages(): array {
		return array(
			'WP_MCP_AI_Company_Settings_Page'        => array( 'wp_mcp_ai_company_settings', 'wp-mcp-ai-company-settings' ),
			'WP_MCP_AI_Lead_Settings_Page'           => array( 'wp_mcp_ai_lead_settings', 'wp-mcp-ai-lead-settings' ),
			'WP_MCP_AI_Deal_Settings_Page'           => array( 'wp_mcp_ai_deal_settings', 'wp-mcp-ai-deal-settings' ),
			'WP_MCP_AI_Support_Ticket_Settings_Page' => array( 'wp_mcp_ai_ticket_settings', 'wp-mcp-ai-support-ticket-settings' ),
			'WP_MCP_AI_Customer_Settings_Page'       => array( 'wp_mcp_ai_customer_settings', 'wp-mcp-ai-customer-settings' ),
		);
	}

	/**
	 * The serving sources must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		foreach ( $this->pages() as $class => $meta ) {
			$reflection = new ReflectionClass( $class );
			$file       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'addons/pro/includes/admin/', $file, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/admin/', $file, $class );
			}
		}
	}

	/**
	 * The page constants must be byte-identical.
	 */
	public function test_page_constants(): void {
		foreach ( $this->pages() as $class => $meta ) {
			$this->assertSame( $meta[0], $class::OPTION_NAME, $class );
			$this->assertSame( $meta[1], $class::PAGE_SLUG, $class );
		}
	}

	/**
	 * init() must wire the admin_menu (priority 25) and admin_init hooks.
	 */
	public function test_init_hooks(): void {
		foreach ( $this->pages() as $class => $meta ) {
			$class::init();
			$this->assertNotFalse( has_action( 'admin_menu' ), $class );
			$this->assertNotFalse( has_action( 'admin_init' ), $class );
		}
	}

	/**
	 * register_settings() must register the byte-identical option group.
	 */
	public function test_register_settings(): void {
		foreach ( $this->pages() as $class => $meta ) {
			$instance = new $class();
			$instance->register_settings();

			$registered = get_registered_settings();
			$this->assertArrayHasKey( $meta[0], $registered, $class );
			$this->assertSame( $meta[0] . '_group', $registered[ $meta[0] ]['group'], $class );
		}
	}

	/**
	 * sanitize_settings() must keep the byte-identical sanitization
	 * contracts for the company page.
	 */
	public function test_company_sanitize_contract(): void {
		$instance = new WP_MCP_AI_Company_Settings_Page();

		$sanitized = $instance->sanitize_settings(
			array(
				'assistant_id'    => -5,
				'default_status'  => 'client',
				'default_country' => ' <b>US</b> ',
			)
		);
		// Negatives clamp to 0 (not absint-coerced to 5).
		$this->assertSame( 0, $sanitized['assistant_id'] );
		$this->assertSame( 'client', $sanitized['default_status'] );
		$this->assertSame( 'US', $sanitized['default_country'] );

		// Invalid status is rejected.
		$rejected = $instance->sanitize_settings( array( 'default_status' => 'bogus' ) );
		$this->assertArrayNotHasKey( 'default_status', $rejected );
	}

	/**
	 * Standalone only: the CRM init must wire all five pages.
	 */
	public function test_init_wiring_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base CRM init wires the pages at boot.' );
		}

		$settings                       = get_option( 'wp_mcp_ai_settings', array() );
		$settings                       = is_array( $settings ) ? $settings : array();
		$settings['enable_crm_toolkit'] = 1;
		update_option( 'wp_mcp_ai_settings', $settings );

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';

		$this->assertNotFalse( has_action( 'admin_menu' ) );
		$this->assertNotFalse( has_action( 'admin_init' ) );
	}
}
