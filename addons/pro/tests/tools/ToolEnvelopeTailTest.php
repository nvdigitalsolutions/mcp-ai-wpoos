<?php
/**
 * Canonical-envelope checks for the one-file tail of the #7212 sweep.
 *
 * The tail spans seven toolkits, so this file pins the three decisions that a
 * future sweep could undo without noticing:
 *
 *  - two tool `execute()` paths now fail with WP_Error;
 *  - one *engine* deliberately keeps its status DTO, because the ingest REST
 *    route publishes `success` / `reason` verbatim. Converting it would change
 *    that response contract, which an envelope sweep must not do.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group tools
 * @group pro
 */
class ToolEnvelopeTailTest extends WP_UnitTestCase {

	/**
	 * The administrator this test runs as.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Give the test an administrator, so capability-gated paths run.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped();
		}
		$this->user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );
	}

	/**
	 * Drop the current user so no capability leaks between tests.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * A shell tool must fail as an error, not as a successful call saying false.
	 */
	public function test_git_operations_returns_a_wp_error(): void {
		$this->load_tool( 'WP_MCP_AI_Tool_Git_Operations', 'architect-agent/class-wp-mcp-ai-tool-git-operations.php' );

		$tool   = new WP_MCP_AI_Tool_Git_Operations();
		$result = $tool->execute( array(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_command_failed', $result->get_error_code() );
	}

	/**
	 * Missing arguments name themselves rather than returning prose.
	 */
	public function test_appointment_followup_requires_appointment_ids(): void {
		$this->load_tool(
			'WP_MCP_AI_Tool_Send_Appointment_Followup',
			'healthcare/wellness/class-wp-mcp-ai-tool-send-appointment-followup.php'
		);

		$tool   = new WP_MCP_AI_Tool_Send_Appointment_Followup();
		$result = $tool->execute( array(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_param', $result->get_error_code() );
	}

	/**
	 * The engine's status DTO is a decision, not an oversight.
	 *
	 * `ingest_reply()` is a public method on an engine, not a tool's `execute()`,
	 * and the ingest REST route returns this array as JSON. The two sites are
	 * annotated rather than converted; this test is what makes that annotation
	 * load-bearing — a future sweep that "fixes" them fails here first.
	 */
	public function test_the_ingest_status_dto_keeps_its_documented_shape(): void {
		$this->load_tool( 'WP_MCP_AI_OA_Engine', 'outbound-booking/class-wp-mcp-ai-oa-engine.php' );

		$result = WP_MCP_AI_OA_Engine::ingest_reply( 999999, 'email', 'hello' );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'not_found', $result['reason'] );
	}

	/**
	 * Require a toolkit file by path when the Pro autoloader has not loaded it.
	 *
	 * Without this the assertions could skip themselves silently, passing while
	 * proving nothing.
	 *
	 * @param string $class_name Class name to ensure is loaded.
	 * @param string $relative   Path under the Pro plugin root.
	 */
	protected function load_tool( $class_name, $relative ) {
		if ( class_exists( $class_name ) ) {
			return;
		}

		$file = WP_MCP_AI_PRO_PATH . 'includes/tools/' . $relative;

		if ( is_readable( $file ) ) {
			require_once $file;
		}

		if ( ! class_exists( $class_name ) ) {
			$this->markTestSkipped( $class_name . ' is not available in this environment.' );
		}
	}
}
