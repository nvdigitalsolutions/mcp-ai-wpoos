<?php
/**
 * Characterization tests for the Wave F2 CRM inbound tool batch — the
 * ported evaluate/classify/extract/detect/score/qualify inbound tools.
 *
 * The tools are LLM-backed through the ported CRM Classifier; these tests
 * pin the byte-identical surfaces and the LLM-free pre-flight contracts
 * (availability, capability, required arguments, lead resolution).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the same contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/crm/inbound/` are asserted in full, including the serving
 *   sources and the ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRM inbound tool batch tests.
 */
class Test_Crm_Tools_Inbound extends WP_UnitTestCase {

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
	 * The seven tool surfaces must be byte-identical.
	 */
	public function test_inbound_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Evaluate_Inbound_Message'  => 'evaluate_inbound_message',
			'WP_MCP_AI_Tool_Classify_Message_Intent'   => 'classify_message_intent',
			'WP_MCP_AI_Tool_Extract_Lead_From_Message' => 'extract_lead_from_message',
			'WP_MCP_AI_Tool_Detect_Buying_Signals'     => 'detect_buying_signals',
			'WP_MCP_AI_Tool_Score_Lead'                => 'score_lead',
			'WP_MCP_AI_Tool_Qualify_Lead_Bant'         => 'qualify_lead_bant',
			'WP_MCP_AI_Tool_Qualify_Lead_Meddic'       => 'qualify_lead_meddic',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}

		$this->assertSame(
			array( 'message_body' ),
			( new WP_MCP_AI_Tool_Evaluate_Inbound_Message() )->get_parameters_schema()['required']
		);
		$this->assertSame(
			array( 'lead_id' ),
			( new WP_MCP_AI_Tool_Qualify_Lead_Bant() )->get_parameters_schema()['required']
		);
	}

	/**
	 * The serving sources must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		foreach (
			array(
				'WP_MCP_AI_Tool_Evaluate_Inbound_Message',
				'WP_MCP_AI_Tool_Classify_Message_Intent',
				'WP_MCP_AI_Tool_Score_Lead',
				'WP_MCP_AI_Tool_Qualify_Lead_Meddic',
			) as $class
		) {
			$reflection = new ReflectionClass( $class );
			$file       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'addons/pro/includes/tools/crm/inbound/', $file, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/tools/crm/inbound/', $file, $class );
			}
		}
	}

	/**
	 * The availability and capability pre-flight gates must reject
	 * disabled-toolkit and subscriber calls.
	 */
	public function test_preflight_gates(): void {
		$this->enable_crm_toolkit();

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Evaluate_Inbound_Message();

		// Byte-identical leniency: the message_body requirement is
		// schema-level only; execute() proceeds and degrades gracefully in
		// the no-API-key test env.
		$lenient = $tool->execute( array(), array( 'user_id' => $user_id ) );
		$this->assertIsArray( $lenient );
		$this->assertArrayHasKey( 'success', $lenient );

		// Subscriber forbidden.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$forbidden = $tool->execute( array( 'message_body' => 'Hi' ), array() );
		$this->assertInstanceOf( 'WP_Error', $forbidden );
		$this->assertSame( 'forbidden', $forbidden->get_error_code() );
	}

	/**
	 * The lead-backed tools must reject unknown lead IDs before any LLM
	 * work.
	 */
	public function test_lead_resolution_gates(): void {
		$this->enable_crm_toolkit();

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		$context = array( 'user_id' => $user_id );

		foreach (
			array(
				'WP_MCP_AI_Tool_Score_Lead',
				'WP_MCP_AI_Tool_Qualify_Lead_Bant',
				'WP_MCP_AI_Tool_Qualify_Lead_Meddic',
			) as $class
		) {
			$ghost = ( new $class() )->execute( array( 'lead_id' => 999999 ), $context );
			$this->assertInstanceOf( 'WP_Error', $ghost, $class );
			$this->assertSame( 'not_found', $ghost->get_error_code(), $class );
		}
	}

	/**
	 * Standalone only: the init's tool filter must carry the inbound batch.
	 */
	public function test_inbound_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the CRM tool map inline.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_crm_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Evaluate_Inbound_Message', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Classify_Message_Intent', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Extract_Lead_From_Message', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Detect_Buying_Signals', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Score_Lead', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Qualify_Lead_Bant', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Qualify_Lead_Meddic', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/inbound/class-wp-mcp-ai-tool-evaluate-inbound-message.php',
			$tools['WP_MCP_AI_Tool_Evaluate_Inbound_Message']
		);
	}

	/**
	 * Standalone only: the ecosystem registration must register the inbound
	 * batch into both registries.
	 */
	public function test_inbound_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		wp_mcp_ai_pro_register_crm_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['evaluate_inbound_message'] ?? null );
		$this->assertNotNull( $parent->all()['qualify_lead_bant'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'evaluate_inbound_message' ) );
		$this->assertTrue( $core_tools->has( 'classify_message_intent' ) );
		$this->assertTrue( $core_tools->has( 'extract_lead_from_message' ) );
		$this->assertTrue( $core_tools->has( 'detect_buying_signals' ) );
		$this->assertTrue( $core_tools->has( 'score_lead' ) );
		$this->assertTrue( $core_tools->has( 'qualify_lead_bant' ) );
		$this->assertTrue( $core_tools->has( 'qualify_lead_meddic' ) );
	}
}
