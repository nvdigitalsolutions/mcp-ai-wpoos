<?php
/**
 * F&B Purchase-Request Workflow Tests — Appendix A of Proposal 062 (A8 plan).
 *
 * Covers the full lifecycle on the QMS engine: transition matrix,
 * submit/approve/release preconditions, segregation of duties, freeze
 * semantics, co-sign threshold, and audit completeness.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case: F&B purchase-request approval lifecycle.
 */
class Test_Fnb_Pr_Workflow extends WP_UnitTestCase {

	/**
	 * Toolkit directory.
	 *
	 * @var string
	 */
	private $toolkit_dir;

	/**
	 * QMS directory.
	 *
	 * @var string
	 */
	private $qms_dir;

	/**
	 * Set up the QMS engine + F&B PR layer.
	 */
	public function setUp(): void {
		parent::setUp();

		// Force the CSV fixture adapter (no network) for the primary backend.
		if ( ! defined( 'WP_MCP_AI_FNB_USE_FIXTURES' ) ) {
			define( 'WP_MCP_AI_FNB_USE_FIXTURES', true );
		}

		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_fnb_toolkit'                 => true,
				'enable_document_generation_toolkit' => true,
				'enable_qms_compliance'              => true,
			)
		);

		$repo_root         = dirname( __DIR__, 4 );
		$this->toolkit_dir = $repo_root . '/addons/pro/includes/tools/food-beverage';
		$this->qms_dir     = $repo_root . '/addons/pro/includes/qms';

		// QMS engine.
		require_once $this->qms_dir . '/class-wp-mcp-ai-qms-capabilities.php';
		require_once $this->qms_dir . '/class-wp-mcp-ai-qms-audit-log.php';
		require_once $this->qms_dir . '/class-wp-mcp-ai-qms-taxonomy.php';
		require_once $this->qms_dir . '/class-wp-mcp-ai-qms-doc-record-cpt.php';
		require_once $this->qms_dir . '/class-wp-mcp-ai-qms-workflow.php';

		WP_MCP_AI_QMS_Capabilities::init();
		WP_MCP_AI_QMS_Doc_Record_CPT::register();
		WP_MCP_AI_QMS_Taxonomy::register();
		WP_MCP_AI_QMS_Audit_Log::install();

		// F&B PR layer.
		$base = $this->toolkit_dir . '/';
		require_once $base . 'class-wp-mcp-ai-fnb-settings.php';
		require_once $base . 'class-wp-mcp-ai-fnb-table-reader.php';
		require_once $base . 'class-wp-mcp-ai-fnb-data-source.php';
		require_once $base . 'class-wp-mcp-ai-fnb-fixture-reader.php';
		require_once $base . 'pr/class-wp-mcp-ai-fnb-pr-service.php';
		require_once $base . 'pr/class-wp-mcp-ai-fnb-pr-gates.php';

		WP_MCP_AI_Fnb_Pr_Gates::init();
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		delete_option( 'wp_mcp_ai_fnb_settings' );
		parent::tearDown();
	}

	/**
	 * Create a user with a given role and return the user ID.
	 *
	 * @param string $role Role slug.
	 * @return int
	 */
	private function make_user( $role = 'administrator' ) {
		return (int) $this->factory->user->create( array( 'role' => $role ) );
	}

	/**
	 * Create a draft PR via the service.
	 *
	 * @param int   $creator     Creator user ID.
	 * @param array $line_args   Line overrides.
	 * @param array $extra_args  Extra create args.
	 * @return array PR payload.
	 */
	private function create_pr( $creator, $line_args = array(), $extra_args = array() ) {
		$args = array_merge(
			array(
				'supplier_id' => 'SUP-02',
				'lines'       => array(
					array_merge(
						array(
							'ingredient_id' => 'IG-01',
							'ingredient'    => 'Chicken breast',
							'pack'          => '5 kg',
							'qty'           => 2,
							'unit_price'    => 2500,
						),
						$line_args
					),
				),
				'order_by'    => gmdate( 'Y-m-d', time() + 2 * DAY_IN_SECONDS ),
				'needed_by'   => gmdate( 'Y-m-d', time() + 5 * DAY_IN_SECONDS ),
				'budget_line' => 'Food purchases',
			),
			$extra_args
		);

		$pr = WP_MCP_AI_Fnb_Pr_Service::create_draft( $args, $creator );
		$this->assertNotInstanceOf( 'WP_Error', $pr, 'PR creation must succeed' );

		return $pr;
	}

	/**
	 * Run the full lifecycle: create → submit → approve → release.
	 *
	 * @return array{creator:int,approver:int,pr:array}
	 */
	private function run_to_released() {
		$creator  = $this->make_user();
		$approver = $this->make_user();

		wp_set_current_user( $creator );
		$pr = $this->create_pr( $creator );

		update_post_meta( $pr['id'], '_fnb_pr_approver', $approver );
		update_post_meta( $pr['id'], '_qms_reviewer_ids', array( $approver ) );
		update_post_meta( $pr['id'], '_qms_approver_ids', array( $approver ) );

		wp_set_current_user( $creator );
		$submitted = WP_MCP_AI_QMS_Workflow::transition(
			$pr['id'],
			WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_IN_REVIEW,
			array( 'actor_id' => $creator )
		);
		$this->assertTrue( $submitted, 'Submit must succeed' );

		wp_set_current_user( $approver );
		$approved = WP_MCP_AI_QMS_Workflow::transition(
			$pr['id'],
			WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_APPROVED,
			array( 'actor_id' => $approver )
		);
		$this->assertTrue( $approved, 'Approve must succeed' );

		wp_set_current_user( $approver );
		$released = WP_MCP_AI_QMS_Workflow::transition(
			$pr['id'],
			WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_RELEASED,
			array( 'actor_id' => $approver )
		);
		$this->assertTrue( $released, 'Release must succeed' );

		return array(
			'creator'  => $creator,
			'approver' => $approver,
			'pr'       => WP_MCP_AI_Fnb_Pr_Service::get_pr( $pr['id'] ),
		);
	}

	/**
	 * Test: the generic QMS approver-list gate still applies (submit needs an approver).
	 */
	public function test_submit_requires_approver() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Pr_Service' ) ) {
			$this->markTestSkipped( 'F&B PR layer not available' );
		}

		$creator = $this->make_user();
		wp_set_current_user( $creator );
		$pr = $this->create_pr( $creator );

		$result = WP_MCP_AI_QMS_Workflow::transition(
			$pr['id'],
			WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_IN_REVIEW,
			array( 'actor_id' => $creator )
		);
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_qms_no_reviewers', $result->get_error_code(), 'The QMS reviewer gate must run first.' );
	}

	/**
	 * Test: full lifecycle transitions + audit completeness.
	 */
	public function test_full_lifecycle_audits_every_transition() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Pr_Service' ) ) {
			$this->markTestSkipped( 'F&B PR layer not available' );
		}

		$creator  = $this->make_user();
		$approver = $this->make_user();

		wp_set_current_user( $creator );
		$pr = $this->create_pr( $creator );
		$this->assertSame( WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_DRAFT, $pr['status'] );
		$this->assertMatchesRegularExpression( '/^PR-\d{6}-\d{3}$/', $pr['document_id'] );
		$this->assertSame( 5000.0, $pr['total'], 'Total is recomputed server-side (2 × 2500).' );

		// Submit.
		wp_set_current_user( $creator );
		update_post_meta( $pr['id'], '_fnb_pr_approver', $approver );
		update_post_meta( $pr['id'], '_qms_reviewer_ids', array( $approver ) );
		update_post_meta( $pr['id'], '_qms_approver_ids', array( $approver ) );
		$submitted = WP_MCP_AI_QMS_Workflow::transition(
			$pr['id'],
			WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_IN_REVIEW,
			array( 'actor_id' => $creator )
		);
		$this->assertTrue( $submitted, 'Submit must succeed.' );

		// Approve by a different actor.
		wp_set_current_user( $approver );
		$approved = WP_MCP_AI_QMS_Workflow::transition(
			$pr['id'],
			WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_APPROVED,
			array( 'actor_id' => $approver )
		);
		$this->assertTrue( $approved, 'Approve must succeed.' );

		// Release (no e-signature needed for purchase requests).
		$released = WP_MCP_AI_QMS_Workflow::transition(
			$pr['id'],
			WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_RELEASED,
			array( 'actor_id' => $approver )
		);
		$this->assertTrue( $released, 'Release must succeed without a password signature for PRs.' );

		$after = WP_MCP_AI_Fnb_Pr_Service::get_pr( $pr['id'] );
		$this->assertSame( WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_RELEASED, $after['status'] );

		// Audit completeness: creation + 3 state transitions.
		$rows   = WP_MCP_AI_QMS_Audit_Log::query( array( 'post_id' => $pr['id'] ) );
		$events = wp_list_pluck( $rows, 'event' );
		$this->assertContains( 'fnb_pr_created', $events );
		$transitions = array_values(
			array_filter(
				$events,
				static function ( $event ) {
					return 'state_transition' === $event;
				}
			)
		);
		$this->assertCount( 3, $transitions );
	}

	/**
	 * Test: the creator cannot approve their own request.
	 */
	public function test_creator_cannot_approve_own_request() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Pr_Service' ) ) {
			$this->markTestSkipped( 'F&B PR layer not available' );
		}

		$creator = $this->make_user();
		wp_set_current_user( $creator );
		$pr = $this->create_pr( $creator );

		update_post_meta( $pr['id'], '_fnb_pr_approver', $creator );
		update_post_meta( $pr['id'], '_qms_reviewer_ids', array( $creator ) );
		update_post_meta( $pr['id'], '_qms_approver_ids', array( $creator ) );

		$this->assertTrue(
			WP_MCP_AI_QMS_Workflow::transition(
				$pr['id'],
				WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_IN_REVIEW,
				array( 'actor_id' => $creator )
			)
		);

		$result = WP_MCP_AI_QMS_Workflow::transition(
			$pr['id'],
			WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_APPROVED,
			array( 'actor_id' => $creator )
		);
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_fnb_pr_self_approval', $result->get_error_code() );
	}

	/**
	 * Test: approval is blocked when no budget line is set.
	 */
	public function test_approve_blocked_without_budget_line() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Pr_Service' ) ) {
			$this->markTestSkipped( 'F&B PR layer not available' );
		}

		$creator  = $this->make_user();
		$approver = $this->make_user();
		wp_set_current_user( $creator );
		$pr = $this->create_pr( $creator, array(), array( 'budget_line' => '' ) );

		update_post_meta( $pr['id'], '_fnb_pr_approver', $approver );
		update_post_meta( $pr['id'], '_qms_reviewer_ids', array( $approver ) );
		update_post_meta( $pr['id'], '_qms_approver_ids', array( $approver ) );

		$this->assertTrue(
			WP_MCP_AI_QMS_Workflow::transition(
				$pr['id'],
				WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_IN_REVIEW,
				array( 'actor_id' => $creator )
			)
		);

		wp_set_current_user( $approver );
		$result = WP_MCP_AI_QMS_Workflow::transition(
			$pr['id'],
			WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_APPROVED,
			array( 'actor_id' => $approver )
		);
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_fnb_pr_missing_budget_line', $result->get_error_code() );
	}

	/**
	 * Test: submitting a duplicate open request is blocked.
	 */
	public function test_duplicate_open_request_blocked_at_submit() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Pr_Service' ) ) {
			$this->markTestSkipped( 'F&B PR layer not available' );
		}

		$creator  = $this->make_user();
		$approver = $this->make_user();

		wp_set_current_user( $creator );
		$first = $this->create_pr( $creator );

		update_post_meta( $first['id'], '_fnb_pr_approver', $approver );
		update_post_meta( $first['id'], '_qms_reviewer_ids', array( $approver ) );
		update_post_meta( $first['id'], '_qms_approver_ids', array( $approver ) );
		$this->assertTrue(
			WP_MCP_AI_QMS_Workflow::transition(
				$first['id'],
				WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_IN_REVIEW,
				array( 'actor_id' => $creator )
			)
		);

		$second = $this->create_pr( $creator );
		update_post_meta( $second['id'], '_fnb_pr_approver', $approver );
		update_post_meta( $second['id'], '_qms_reviewer_ids', array( $approver ) );
		update_post_meta( $second['id'], '_qms_approver_ids', array( $approver ) );

		$result = WP_MCP_AI_QMS_Workflow::transition(
			$second['id'],
			WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_IN_REVIEW,
			array( 'actor_id' => $creator )
		);
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_fnb_pr_duplicate_open', $result->get_error_code() );
	}

	/**
	 * Test: supplier delivery-days cut-off violation is blocked at submit.
	 */
	public function test_cutoff_violation_blocked_at_submit() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Pr_Service' ) ) {
			$this->markTestSkipped( 'F&B PR layer not available' );
		}

		$creator  = $this->make_user();
		$approver = $this->make_user();

		wp_set_current_user( $creator );
		// SUP-02 needs 2 days lead time; allow only 1 day between order and need.
		$pr = $this->create_pr(
			$creator,
			array(),
			array(
				'order_by'  => gmdate( 'Y-m-d', time() + 4 * DAY_IN_SECONDS ),
				'needed_by' => gmdate( 'Y-m-d', time() + 5 * DAY_IN_SECONDS ),
			)
		);

		update_post_meta( $pr['id'], '_fnb_pr_approver', $approver );
		update_post_meta( $pr['id'], '_qms_reviewer_ids', array( $approver ) );
		update_post_meta( $pr['id'], '_qms_approver_ids', array( $approver ) );

		$result = WP_MCP_AI_QMS_Workflow::transition(
			$pr['id'],
			WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_IN_REVIEW,
			array( 'actor_id' => $creator )
		);
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_fnb_pr_cutoff_violation', $result->get_error_code() );
	}

	/**
	 * Test: freeze semantics — editing after approval blocks release.
	 */
	public function test_release_blocked_after_post_approval_edit() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Pr_Service' ) ) {
			$this->markTestSkipped( 'F&B PR layer not available' );
		}

		$state = $this->run_to_released();
		$this->assertSame( WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_RELEASED, $state['pr']['status'] );

		// A second PR edited after approval must not release.
		$creator  = $this->make_user();
		$approver = $this->make_user();
		wp_set_current_user( $creator );
		$pr = $this->create_pr( $creator );

		update_post_meta( $pr['id'], '_fnb_pr_approver', $approver );
		update_post_meta( $pr['id'], '_qms_reviewer_ids', array( $approver ) );
		update_post_meta( $pr['id'], '_qms_approver_ids', array( $approver ) );
		$this->assertTrue(
			WP_MCP_AI_QMS_Workflow::transition( $pr['id'], WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_IN_REVIEW, array( 'actor_id' => $creator ) )
		);
		wp_set_current_user( $approver );
		$this->assertTrue(
			WP_MCP_AI_QMS_Workflow::transition( $pr['id'], WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_APPROVED, array( 'actor_id' => $approver ) )
		);

		wp_update_post(
			array(
				'ID'           => $pr['id'],
				'post_content' => 'Edited after approval.',
			)
		);

		$result = WP_MCP_AI_QMS_Workflow::transition( $pr['id'], WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_RELEASED, array( 'actor_id' => $approver ) );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_fnb_pr_hash_mismatch', $result->get_error_code() );
	}

	/**
	 * Test: totals above the auto-approval threshold need a finance co-sign.
	 */
	public function test_release_requires_cosign_above_threshold() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Pr_Service' ) ) {
			$this->markTestSkipped( 'F&B PR layer not available' );
		}

		update_option( 'wp_mcp_ai_fnb_settings', array( 'pr_auto_approval_threshold' => 1000 ) );

		$creator  = $this->make_user();
		$approver = $this->make_user();
		wp_set_current_user( $creator );
		$pr = $this->create_pr( $creator ); // Total 5000 > 1000.

		update_post_meta( $pr['id'], '_fnb_pr_approver', $approver );
		update_post_meta( $pr['id'], '_qms_reviewer_ids', array( $approver ) );
		update_post_meta( $pr['id'], '_qms_approver_ids', array( $approver ) );
		$this->assertTrue(
			WP_MCP_AI_QMS_Workflow::transition( $pr['id'], WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_IN_REVIEW, array( 'actor_id' => $creator ) )
		);
		wp_set_current_user( $approver );
		$this->assertTrue(
			WP_MCP_AI_QMS_Workflow::transition( $pr['id'], WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_APPROVED, array( 'actor_id' => $approver ) )
		);

		$blocked = WP_MCP_AI_QMS_Workflow::transition( $pr['id'], WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_RELEASED, array( 'actor_id' => $approver ) );
		$this->assertWPError( $blocked );
		$this->assertSame( 'wp_mcp_ai_fnb_pr_needs_cosign', $blocked->get_error_code() );

		// Finance co-sign (a second actor) unblocks release.
		$finance = $this->make_user();
		wp_set_current_user( $finance );
		update_post_meta( $pr['id'], WP_MCP_AI_Fnb_Pr_Service::META_FINANCE, $finance );

		$released = WP_MCP_AI_QMS_Workflow::transition( $pr['id'], WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_RELEASED, array( 'actor_id' => $finance ) );
		$this->assertTrue( $released, 'Release must succeed after the finance co-sign.' );
	}

	/**
	 * Test: supersession creates a new draft revision and retires the old one.
	 */
	public function test_supersede_creates_new_revision() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Pr_Service' ) ) {
			$this->markTestSkipped( 'F&B PR layer not available' );
		}

		$state = $this->run_to_released();
		$old   = $state['pr'];

		$tool_file = $this->toolkit_dir . '/pr/class-wp-mcp-ai-tool-fnb-pr-supersede.php';
		require_once $tool_file;
		if ( ! class_exists( 'WP_MCP_AI_Tool_Fnb_Pr_Supersede' ) ) {
			$this->markTestSkipped( 'fnb_pr_supersede tool not available' );
		}

		wp_set_current_user( $state['approver'] );
		$tool   = new WP_MCP_AI_Tool_Fnb_Pr_Supersede();
		$result = $tool->execute( array( 'post_id' => $old['id'] ), array( 'user_id' => $state['approver'] ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_SUPERSEDED, $result['status'] );
		$this->assertSame( '1.1', $result['revision'] );

		$successor = WP_MCP_AI_Fnb_Pr_Service::get_pr( $result['successor_post_id'] );
		$this->assertSame( WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_DRAFT, $successor['status'] );
		$this->assertSame( $old['document_id'], $successor['document_id'] );
		$this->assertSame( $old['id'], (int) get_post_meta( $result['successor_post_id'], '_qms_supersedes', true ) );
	}

	/**
	 * Test: cancellation requires an audited reason.
	 */
	public function test_cancel_requires_reason() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Pr_Service' ) ) {
			$this->markTestSkipped( 'F&B PR layer not available' );
		}

		$tool_file = $this->toolkit_dir . '/pr/class-wp-mcp-ai-tool-fnb-pr-cancel.php';
		require_once $tool_file;
		if ( ! class_exists( 'WP_MCP_AI_Tool_Fnb_Pr_Cancel' ) ) {
			$this->markTestSkipped( 'fnb_pr_cancel tool not available' );
		}

		$creator = $this->make_user();
		wp_set_current_user( $creator );
		$pr = $this->create_pr( $creator );

		$tool = new WP_MCP_AI_Tool_Fnb_Pr_Cancel();

		$missing = $tool->execute( array( 'post_id' => $pr['id'] ), array( 'user_id' => $creator ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'wp_mcp_ai_fnb_pr_missing_reason', $missing->get_error_code() );

		$cancelled = $tool->execute(
			array(
				'post_id' => $pr['id'],
				'reason'  => 'Duplicate of an earlier request.',
			),
			array( 'user_id' => $creator )
		);
		$this->assertTrue( $cancelled['success'] );
		$this->assertSame( WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_OBSOLETE, $cancelled['status'] );
	}

	/**
	 * Test: read surfaces (list + audit) and the tool capability gate.
	 */
	public function test_read_surfaces_and_capability_gate() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Pr_Service' ) ) {
			$this->markTestSkipped( 'F&B PR layer not available' );
		}

		$create_file = $this->toolkit_dir . '/pr/class-wp-mcp-ai-tool-fnb-pr-create.php';
		$list_file   = $this->toolkit_dir . '/pr/class-wp-mcp-ai-tool-fnb-pr-list.php';
		require_once $create_file;
		require_once $list_file;

		$creator = $this->make_user();
		wp_set_current_user( $creator );
		$pr = $this->create_pr( $creator );

		// List surface returns the register.
		$list_tool = new WP_MCP_AI_Tool_Fnb_Pr_List();
		$listed    = $list_tool->execute( array(), array( 'user_id' => $creator ) );
		$this->assertTrue( $listed['success'] );
		$this->assertNotEmpty( $listed['records'] );

		// Audit surface returns the creation row.
		$audit_file = $this->toolkit_dir . '/pr/class-wp-mcp-ai-tool-fnb-pr-audit.php';
		require_once $audit_file;
		$audit_tool = new WP_MCP_AI_Tool_Fnb_Pr_Audit();
		$audited    = $audit_tool->execute( array( 'post_id' => $pr['id'] ), array( 'user_id' => $creator ) );
		$this->assertTrue( $audited['success'] );
		$this->assertNotEmpty( $audited['entries'] );

		// Create tool is ACT-tier: a subscriber is refused.
		$subscriber = $this->make_user( 'subscriber' );
		wp_set_current_user( $subscriber );
		$create_tool = new WP_MCP_AI_Tool_Fnb_Pr_Create();
		$denied      = $create_tool->execute(
			array(
				'supplier_id' => 'SUP-02',
				'lines'       => wp_json_encode(
					array(
						array(
							'ingredient_id' => 'IG-01',
							'pack'          => '5 kg',
							'qty'           => 1,
							'unit_price'    => 100,
						),
					)
				),
			),
			array( 'user_id' => $subscriber )
		);
		$this->assertWPError( $denied, 'A subscriber cannot create purchase requests.' );
	}
}
