<?php
/**
 * Tests for Phase B1 per-toolkit MemPalace capture tools.
 *
 * Covers:
 *  - `WP_MCP_AI_Pro_Capture_Tool_Base` argument validation:
 *      - missing wing key returns WP_Error
 *      - room outside the toolkit's enum returns WP_Error
 *      - wing slug is auto-built from `<prefix>/<wing_key>`
 *  - `WP_MCP_AI_Tool_PM_Capture_Decision`: born tier=core, importance=0.85
 *  - `WP_MCP_AI_Tool_Health_Capture_Encounter`: sensitivity=phi, consent=consent, tier=core
 *  - `WP_MCP_AI_Tool_DocGen_Capture_Style_Memory`: summarisation discipline
 *      (verbatim original archived + recall summary referencing it)
 *  - Verbatim discipline guard: toolkits that do NOT opt in cannot flip
 *      verbatim=false (the base class forces it back to true).
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

/**
 * Phase B1 test suite.
 */
class WP_MCP_AI_MemPalace_Capture_Tools_Phase_B1_Test extends WP_UnitTestCase {

	/**
	 * Captured `wp_mcp_ai_memory_stored` events for the active test.
	 *
	 * @var array
	 */
	protected $events = array();

	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon path not defined.' );
		}
		// Make capture writes header-less so we can inspect the canonical
		// event payload without depending on the transient store.
		add_filter( 'wp_mcp_ai_memory_capture_skip_transient', '__return_true' );

		// Load the capture tool classes — the addon registers them lazily
		// from the toolkit-conditional blocks; the test loads them directly.
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/capture/class-wp-mcp-ai-pro-capture-tool-base.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-tool-crm-capture-interaction.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/class-wp-mcp-ai-tool-pm-capture-decision.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-docgen-capture-style-memory.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-tool-social-capture-post-performance.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/class-wp-mcp-ai-tool-health-capture-encounter.php';

		$this->events = array();
		add_action(
			'wp_mcp_ai_memory_stored',
			function ( $event ) {
				$this->events[] = $event;
			}
		);
	}

	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_memory_capture_skip_transient' );
		remove_all_actions( 'wp_mcp_ai_memory_stored' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------
	// Base — argument validation
	// -------------------------------------------------------------------

	public function test_capture_tool_requires_wing_key() {
		$tool   = new WP_MCP_AI_Tool_CRM_Capture_Interaction();
		$result = $tool->execute(
			array(
				'room'    => 'interactions',
				'content' => 'Spoke with Acme; budget approved.',
			),
			array( 'assistant_id' => 42 )
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'capture_missing_wing_key', $result->get_error_code() );
	}

	public function test_capture_tool_rejects_room_outside_enum() {
		$tool   = new WP_MCP_AI_Tool_CRM_Capture_Interaction();
		$result = $tool->execute(
			array(
				'account_id' => 'acme',
				'room'       => 'gossip', // Not in CRM room enum.
				'content'    => 'Some content',
			),
			array( 'assistant_id' => 42 )
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'capture_invalid_room', $result->get_error_code() );
	}

	public function test_capture_tool_builds_wing_from_prefix_and_key() {
		$tool   = new WP_MCP_AI_Tool_CRM_Capture_Interaction();
		$result = $tool->execute(
			array(
				'account_id' => 'acme',
				'room'       => 'interactions',
				'content'    => 'Spoke with Acme; budget approved.',
			),
			array( 'assistant_id' => 42 )
		);
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'account/acme', $result['wing'] );
		$this->assertSame( 'interactions', $result['room'] );
	}

	// -------------------------------------------------------------------
	// PM capture — decision-grade defaults
	// -------------------------------------------------------------------

	public function test_pm_capture_decision_is_born_tier_core() {
		$tool   = new WP_MCP_AI_Tool_PM_Capture_Decision();
		$result = $tool->execute(
			array(
				'project_id' => 'site-redesign',
				'room'       => 'decisions',
				'content'    => 'Adopting Tailwind for the design system.',
			),
			array( 'assistant_id' => 42 )
		);
		$this->assertTrue( $result['success'] );
		$this->assertSame( WP_MCP_AI_Memory_Capture_Service::TIER_CORE, $result['tier'] );
		$this->assertSame( 'project/site-redesign', $result['wing'] );

		// Importance must be at the toolkit-specific 0.85 default.
		$this->assertNotEmpty( $this->events );
		$last = end( $this->events );
		$this->assertSame( 0.85, (float) $last['importance'] );
	}

	// -------------------------------------------------------------------
	// Healthcare capture — PHI + consent + tier=core
	// -------------------------------------------------------------------

	public function test_health_capture_encounter_classifies_as_phi_with_consent() {
		$tool   = new WP_MCP_AI_Tool_Health_Capture_Encounter();
		$result = $tool->execute(
			array(
				'member_id' => 'jane-doe',
				'room'      => 'vitals',
				'content'   => 'BP 120/80 mmHg, pulse 72.',
			),
			array( 'assistant_id' => 42 )
		);
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'patient/jane-doe', $result['wing'] );
		$this->assertSame( WP_MCP_AI_Memory_Capture_Service::TIER_CORE, $result['tier'] );

		$last = end( $this->events );
		$this->assertSame( 'phi', $last['sensitivity'] );
		$this->assertSame( 'consent', $last['consent_basis'] );
		$this->assertTrue( (bool) $last['verbatim'] );
	}

	// -------------------------------------------------------------------
	// Verbatim discipline — non-summarising toolkits cannot flip it off
	// -------------------------------------------------------------------

	public function test_non_summarising_toolkit_cannot_disable_verbatim() {
		$tool   = new WP_MCP_AI_Tool_CRM_Capture_Interaction();
		$result = $tool->execute(
			array(
				'account_id' => 'acme',
				'room'       => 'interactions',
				'content'    => 'Verbatim transcript here.',
				'verbatim'   => false, // Caller asks to disable; toolkit must refuse.
			),
			array( 'assistant_id' => 42 )
		);
		$this->assertTrue( $result['success'] );

		$last = end( $this->events );
		$this->assertTrue( (bool) $last['verbatim'], 'CRM (non-summarising) must keep verbatim=true regardless of caller.' );
	}

	// -------------------------------------------------------------------
	// Doc Gen — summarisation discipline (two-record, archival + recall)
	// -------------------------------------------------------------------

	public function test_docgen_summary_writes_two_records_archival_plus_recall() {
		$tool   = new WP_MCP_AI_Tool_DocGen_Capture_Style_Memory();
		$result = $tool->execute(
			array(
				'user_id'  => '7',
				'room'     => 'style',
				'content'  => 'The user prefers concise, active-voice paragraphs of 2–3 sentences with no marketing fluff. Lists are used for steps only, never for emphasis. Tone is warm but expert.',
				'summary'  => 'Active-voice, ≤3-sentence paragraphs; lists only for steps; warm-expert tone.',
				'verbatim' => false,
			),
			array( 'assistant_id' => 42 )
		);
		$this->assertTrue( $result['success'] );
		$this->assertNotEmpty( $result['verbatim_context_id'] );
		$this->assertNotEmpty( $result['summary_context_id'] );
		$this->assertNotSame( $result['verbatim_context_id'], $result['summary_context_id'] );

		// Inspect the two events emitted.
		$this->assertCount( 2, $this->events );

		$archival = $this->events[0];
		$summary  = $this->events[1];

		$this->assertSame( WP_MCP_AI_Memory_Capture_Service::TIER_ARCHIVAL, $archival['tier'] );
		$this->assertTrue( (bool) $archival['verbatim'] );
		$this->assertStringContainsString( 'no marketing fluff', $archival['content'] );

		$this->assertSame( WP_MCP_AI_Memory_Capture_Service::TIER_RECALL, $summary['tier'] );
		$this->assertFalse( (bool) $summary['verbatim'] );
		$this->assertStringContainsString( 'Active-voice', $summary['content'] );

		// The summary's subject_refs must point back at the verbatim record.
		$found_pointer = false;
		foreach ( $summary['subject_refs'] as $ref ) {
			if ( 0 === strpos( $ref, 'verbatim:' ) ) {
				$found_pointer = true;
				break;
			}
		}
		$this->assertTrue( $found_pointer, 'Summary must reference its verbatim source via subject_refs.' );
	}

	// -------------------------------------------------------------------
	// Schema — required fields are correctly declared
	// -------------------------------------------------------------------

	public function test_schemas_declare_correct_required_fields() {
		$cases = array(
			array( new WP_MCP_AI_Tool_CRM_Capture_Interaction(), 'account_id', array( 'interactions', 'objections', 'next-actions' ) ),
			array( new WP_MCP_AI_Tool_PM_Capture_Decision(), 'project_id', array( 'decisions', 'status', 'adr' ) ),
			array( new WP_MCP_AI_Tool_DocGen_Capture_Style_Memory(), 'user_id', array( 'style', 'drafts' ) ),
			array( new WP_MCP_AI_Tool_Social_Capture_Post_Performance(), 'brand_id', array( 'voice', 'performance' ) ),
			array( new WP_MCP_AI_Tool_Health_Capture_Encounter(), 'member_id', array( 'vitals', 'allergies', 'prescriptions', 'imaging', 'notes' ) ),
		);

		foreach ( $cases as $row ) {
			list( $tool, $wing_key, $expected_rooms ) = $row;
			$schema = $tool->get_parameters_schema();
			$this->assertSame( 'object', $schema['type'] );
			$this->assertEqualSets( array( $wing_key, 'room', 'content' ), $schema['required'], 'Required fields differ for ' . $tool->get_slug() );
			$this->assertSame( $expected_rooms, $schema['properties']['room']['enum'], 'Room enum differs for ' . $tool->get_slug() );
		}
	}
}
