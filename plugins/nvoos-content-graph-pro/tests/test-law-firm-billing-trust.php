<?php
/**
 * Characterization tests for the Wave F4 law-firm billing-trust batch — the
 * ten ported billing & trust-accounting tools.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes; the byte-identical surfaces and the graceful execute()
 *   contracts are asserted (the gated map files are required
 *   deterministically in setUp).
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/law-firm/billing-trust/` are asserted in full.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Law-firm billing-trust tests.
 */
class Test_Law_Firm_Billing_Trust extends WP_UnitTestCase {

	/**
	 * Monolith matrix: require the ten gated base files deterministically.
	 * Standalone: the entry autoloader serves them on demand.
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$files = array(
				'tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-accounts-receivable-tracker.php',
				'tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-billing-compliance-checker.php',
				'tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-expense-reimbursement-tracker.php',
				'tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-fee-calculator.php',
				'tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-invoice-generator.php',
				'tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-profitability-analyzer.php',
				'tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-retainer-balance-monitor.php',
				'tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-time-entry-recorder.php',
				'tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-trust-account-manager.php',
				'tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-trust-reconciliation-tool.php',
			);
			foreach ( $files as $file ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/' . $file;
			}
		}
	}

	/**
	 * The ten ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_LF_Accounts_Receivable_Tracker' => 'tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-accounts-receivable-tracker.php',
			'WP_MCP_AI_Tool_LF_Billing_Compliance_Checker' => 'tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-billing-compliance-checker.php',
			'WP_MCP_AI_Tool_LF_Expense_Reimbursement_Tracker' => 'tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-expense-reimbursement-tracker.php',
			'WP_MCP_AI_Tool_LF_Fee_Calculator'             => 'tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-fee-calculator.php',
			'WP_MCP_AI_Tool_LF_Invoice_Generator'          => 'tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-invoice-generator.php',
			'WP_MCP_AI_Tool_LF_Profitability_Analyzer'     => 'tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-profitability-analyzer.php',
			'WP_MCP_AI_Tool_LF_Retainer_Balance_Monitor'   => 'tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-retainer-balance-monitor.php',
			'WP_MCP_AI_Tool_LF_Time_Entry_Recorder'        => 'tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-time-entry-recorder.php',
			'WP_MCP_AI_Tool_LF_Trust_Account_Manager'      => 'tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-trust-account-manager.php',
			'WP_MCP_AI_Tool_LF_Trust_Reconciliation_Tool'  => 'tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-trust-reconciliation-tool.php',
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
	}

	/**
	 * The tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_LF_Accounts_Receivable_Tracker' => 'lf_accounts_receivable_tracker',
			'WP_MCP_AI_Tool_LF_Billing_Compliance_Checker' => 'lf_billing_compliance_checker',
			'WP_MCP_AI_Tool_LF_Expense_Reimbursement_Tracker' => 'lf_expense_reimbursement_tracker',
			'WP_MCP_AI_Tool_LF_Fee_Calculator'             => 'lf_fee_calculator',
			'WP_MCP_AI_Tool_LF_Invoice_Generator'          => 'lf_invoice_generator',
			'WP_MCP_AI_Tool_LF_Profitability_Analyzer'     => 'lf_profitability_analyzer',
			'WP_MCP_AI_Tool_LF_Retainer_Balance_Monitor'   => 'lf_retainer_balance_monitor',
			'WP_MCP_AI_Tool_LF_Time_Entry_Recorder'        => 'lf_time_entry_recorder',
			'WP_MCP_AI_Tool_LF_Trust_Account_Manager'      => 'lf_trust_account_manager',
			'WP_MCP_AI_Tool_LF_Trust_Reconciliation_Tool'  => 'lf_trust_reconciliation_tool',
		);

		$caps = array(
			'WP_MCP_AI_Tool_LF_Accounts_Receivable_Tracker' => 'edit_posts',
			'WP_MCP_AI_Tool_LF_Billing_Compliance_Checker' => 'edit_posts',
			'WP_MCP_AI_Tool_LF_Expense_Reimbursement_Tracker' => 'manage_options',
			'WP_MCP_AI_Tool_LF_Fee_Calculator'             => 'edit_posts',
			'WP_MCP_AI_Tool_LF_Invoice_Generator'          => 'edit_posts',
			'WP_MCP_AI_Tool_LF_Profitability_Analyzer'     => 'edit_posts',
			'WP_MCP_AI_Tool_LF_Retainer_Balance_Monitor'   => 'edit_posts',
			'WP_MCP_AI_Tool_LF_Time_Entry_Recorder'        => 'manage_options',
			'WP_MCP_AI_Tool_LF_Trust_Account_Manager'      => 'manage_options',
			'WP_MCP_AI_Tool_LF_Trust_Reconciliation_Tool'  => 'edit_posts',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( $caps[ $class ], $tool->get_required_capability(), $class );
		}
	}

	/**
	 * The settings-gated availability + missing-required first gate must be
	 * byte-identical.
	 */
	public function test_billing_compliance_gates(): void {
		update_option( 'wp_mcp_ai_settings', array( 'enable_law_firm_toolkit' => true ) );

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$tool   = new WP_MCP_AI_Tool_LF_Billing_Compliance_Checker();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'missing_required', $result->get_error_code() );

		delete_option( 'wp_mcp_ai_settings' );
	}
}
