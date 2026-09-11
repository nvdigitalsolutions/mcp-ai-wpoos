<?php
/**
 * Characterization tests for the Wave F2 CRM upwork tool batch — the
 * ported `search-upwork-jobs`, `score-upwork-job`, and
 * `draft-upwork-proposal` tools plus the `WP_MCP_AI_Upwork_Client`
 * GraphQL API client.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   tool classes (classmap-autoloaded); the byte-identical surface and
 *   client constants are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/crm/upwork/` + `src/` are asserted in full, including the
 *   ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRM upwork tool batch tests.
 */
class Test_Crm_Tools_Upwork extends WP_UnitTestCase {

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
	 * The four ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Search_Upwork_Jobs'    => 'tools/crm/upwork/class-wp-mcp-ai-tool-search-upwork-jobs.php',
			'WP_MCP_AI_Tool_Score_Upwork_Job'      => 'tools/crm/upwork/class-wp-mcp-ai-tool-score-upwork-job.php',
			'WP_MCP_AI_Tool_Draft_Upwork_Proposal' => 'tools/crm/upwork/class-wp-mcp-ai-tool-draft-upwork-proposal.php',
			'WP_MCP_AI_Upwork_Client'              => 'class-wp-mcp-ai-upwork-client.php',
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
	 * The three tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Search_Upwork_Jobs'    => 'search_upwork_jobs',
			'WP_MCP_AI_Tool_Score_Upwork_Job'      => 'score_upwork_job',
			'WP_MCP_AI_Tool_Draft_Upwork_Proposal' => 'draft_upwork_proposal',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );

			$flags = $tool->get_capability_flags();
			$this->assertContains( 'pro', $flags, $class );
			$this->assertContains( 'requires-capability', $flags, $class );
			$this->assertContains( 'external-api', $flags, $class );
			$this->assertContains( 'rate-limited', $flags, $class );
		}
	}

	/**
	 * The Upwork GraphQL client constants must be byte-identical.
	 */
	public function test_upwork_client_constants(): void {
		$this->assertSame( 'https://api.upwork.com/graphql', WP_MCP_AI_Upwork_Client::GRAPHQL_ENDPOINT );
		$this->assertSame( 'https://www.upwork.com/api/v3/oauth2/token', WP_MCP_AI_Upwork_Client::TOKEN_ENDPOINT );
		$this->assertSame( 30, WP_MCP_AI_Upwork_Client::DEFAULT_TIMEOUT );
		$this->assertSame( 5242880, WP_MCP_AI_Upwork_Client::MAX_RESPONSE_SIZE );
		$this->assertSame( 30, WP_MCP_AI_Upwork_Client::RATE_LIMIT_PER_MINUTE );
		$this->assertSame( 5, WP_MCP_AI_Upwork_Client::MIN_BACKOFF_SECONDS );
	}

	/**
	 * Standalone only: the init's tool filter must carry the upwork batch.
	 */
	public function test_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the CRM tool map inline.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_crm_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Search_Upwork_Jobs', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Score_Upwork_Job', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Draft_Upwork_Proposal', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/upwork/class-wp-mcp-ai-tool-search-upwork-jobs.php',
			$tools['WP_MCP_AI_Tool_Search_Upwork_Jobs']
		);
	}

	/**
	 * Standalone only: the ecosystem registration must register the upwork
	 * batch into both registries.
	 */
	public function test_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		wp_mcp_ai_pro_register_crm_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['search_upwork_jobs'] ?? null );
		$this->assertNotNull( $parent->all()['draft_upwork_proposal'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'search_upwork_jobs' ) );
		$this->assertTrue( $core_tools->has( 'score_upwork_job' ) );
		$this->assertTrue( $core_tools->has( 'draft_upwork_proposal' ) );
	}
}
