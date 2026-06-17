<?php
/**
 * Tests for the PR-E job-source adapters.
 *
 * Covers:
 *  - WP_MCP_AI_Job_Source_Transcript_Mining
 *  - WP_MCP_AI_Job_Source_Crawl4AI
 *  - WP_MCP_AI_Job_Source_Hitl_Approvals
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Class Test_Job_Source_Adapters
 */
class Test_Job_Source_Adapters extends WP_UnitTestCase {

	/**
	 * Regular (non-admin) user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure the interface and all adapter classes are available.
		require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-cron-status-job-source.php';
		require_once WP_MCP_AI_PATH . 'includes/services/job-sources/class-wp-mcp-ai-job-source-transcript-mining.php';
		require_once WP_MCP_AI_PATH . 'includes/services/job-sources/class-wp-mcp-ai-job-source-crawl4ai.php';
		require_once WP_MCP_AI_PATH . 'includes/services/job-sources/class-wp-mcp-ai-job-source-hitl-approvals.php';

		// Ensure the Approval Queue CPT class and registration are available.
		if ( ! class_exists( 'WP_MCP_AI_Approval_Queue' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-approval-queue.php';
		}
		WP_MCP_AI_Approval_Queue::register_cpt();

		// Ensure the Transcript Mining Job class is available.
		if ( ! class_exists( 'WP_MCP_AI_Transcript_Mining_Job' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-transcript-mining-job.php';
		}

		// Ensure the Crawler class is available.
		if ( ! class_exists( 'WP_MCP_AI_Crawler' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/crawler/class-wp-mcp-ai-crawler.php';
		}

		$this->user_id  = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	// ── Interface contracts ──────────────────────────────────────────────────

	/**
	 * All adapters must implement the interface.
	 */
	public function test_adapters_implement_interface() {
		$tm  = new WP_MCP_AI_Job_Source_Transcript_Mining();
		$c4a = new WP_MCP_AI_Job_Source_Crawl4AI();
		$hqa = new WP_MCP_AI_Job_Source_Hitl_Approvals();

		$this->assertInstanceOf( 'Interface_WP_MCP_AI_Cron_Status_Job_Source', $tm );
		$this->assertInstanceOf( 'Interface_WP_MCP_AI_Cron_Status_Job_Source', $c4a );
		$this->assertInstanceOf( 'Interface_WP_MCP_AI_Cron_Status_Job_Source', $hqa );
	}

	/**
	 * Each adapter must return a unique, non-empty slug.
	 */
	public function test_adapter_slugs_are_unique() {
		$adapters = array(
			new WP_MCP_AI_Job_Source_Transcript_Mining(),
			new WP_MCP_AI_Job_Source_Crawl4AI(),
			new WP_MCP_AI_Job_Source_Hitl_Approvals(),
		);
		$slugs = array_map( static function ( $a ) { return $a->get_slug(); }, $adapters );

		foreach ( $slugs as $slug ) {
			$this->assertIsString( $slug );
			$this->assertNotEmpty( $slug );
		}
		$this->assertCount( count( $slugs ), array_unique( $slugs ), 'Adapter slugs must be unique.' );
	}

	// ── Transcript Mining adapter ────────────────────────────────────────────

	/**
	 * When no transcript-mining transients exist, get_jobs() returns empty.
	 */
	public function test_transcript_mining_returns_empty_when_no_jobs() {
		wp_set_current_user( $this->user_id );
		$adapter = new WP_MCP_AI_Job_Source_Transcript_Mining();
		$jobs    = $adapter->get_jobs( $this->user_id );

		$this->assertIsArray( $jobs );
		$this->assertEmpty( $jobs );
	}

	/**
	 * A transcript-mining transient for the requesting user is surfaced.
	 */
	public function test_transcript_mining_returns_own_job() {
		$job_id = wp_generate_uuid4();
		$state  = array(
			'id'           => $job_id,
			'status'       => 'running',
			'user_id'      => $this->user_id,
			'agent_id'     => '42',
			'created_at'   => time() - 10,
			'updated_at'   => time(),
			'total'        => 20,
			'processed'    => 5,
			'last_message' => 'Mining…',
		);
		set_transient( WP_MCP_AI_Transcript_Mining_Job::STATE_PREFIX . $job_id, $state, 600 );

		wp_set_current_user( $this->user_id );
		$adapter = new WP_MCP_AI_Job_Source_Transcript_Mining();
		$jobs    = $adapter->get_jobs( $this->user_id );

		$this->assertArrayHasKey( $job_id, $jobs );
		$record = $jobs[ $job_id ];
		$this->assertSame( $job_id, $record['job_id'] );
		$this->assertSame( 'running', $record['status'] );
		$this->assertSame( 25, $record['progress'] ); // 5/20 * 100 = 25.
		$this->assertSame( '42', $record['assistant_id'] );

		delete_transient( WP_MCP_AI_Transcript_Mining_Job::STATE_PREFIX . $job_id );
	}

	/**
	 * A non-admin user does not see another user's transcript-mining job.
	 */
	public function test_transcript_mining_hides_other_users_jobs() {
		$other_user = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$job_id     = wp_generate_uuid4();
		$state      = array(
			'id'         => $job_id,
			'status'     => 'queued',
			'user_id'    => $other_user,
			'created_at' => time(),
			'updated_at' => time(),
			'total'      => 10,
			'processed'  => 0,
		);
		set_transient( WP_MCP_AI_Transcript_Mining_Job::STATE_PREFIX . $job_id, $state, 600 );

		$adapter = new WP_MCP_AI_Job_Source_Transcript_Mining();
		$jobs    = $adapter->get_jobs( $this->user_id );

		$this->assertArrayNotHasKey( $job_id, $jobs );

		delete_transient( WP_MCP_AI_Transcript_Mining_Job::STATE_PREFIX . $job_id );
	}

	/**
	 * An admin user sees all transcript-mining jobs regardless of owner.
	 */
	public function test_transcript_mining_admin_sees_all_jobs() {
		$job_id = wp_generate_uuid4();
		$state  = array(
			'id'         => $job_id,
			'status'     => 'queued',
			'user_id'    => $this->user_id,
			'created_at' => time(),
			'updated_at' => time(),
			'total'      => 10,
			'processed'  => 0,
		);
		set_transient( WP_MCP_AI_Transcript_Mining_Job::STATE_PREFIX . $job_id, $state, 600 );

		wp_set_current_user( $this->admin_id );
		$adapter = new WP_MCP_AI_Job_Source_Transcript_Mining();
		$jobs    = $adapter->get_jobs( $this->admin_id );

		$this->assertArrayHasKey( $job_id, $jobs );

		delete_transient( WP_MCP_AI_Transcript_Mining_Job::STATE_PREFIX . $job_id );
	}

	/**
	 * A transient with no 'id' field is silently dropped.
	 */
	public function test_transcript_mining_drops_malformed_transient() {
		$key = WP_MCP_AI_Transcript_Mining_Job::STATE_PREFIX . 'bad_record';
		set_transient( $key, array( 'status' => 'running' ), 600 ); // Missing 'id'.

		$adapter = new WP_MCP_AI_Job_Source_Transcript_Mining();
		$jobs    = $adapter->get_jobs( $this->admin_id );

		// The bad record should not appear (keyed by empty string or missing key).
		$this->assertArrayNotHasKey( '', $jobs );

		delete_transient( $key );
	}

	/**
	 * Cancellable flag is true for queued/running jobs only.
	 */
	public function test_transcript_mining_cancellable_flag() {
		$cases = array(
			'queued'    => true,
			'running'   => true,
			'completed' => false,
			'failed'    => false,
			'cancelled' => false,
		);

		$adapter = new WP_MCP_AI_Job_Source_Transcript_Mining();

		foreach ( $cases as $status => $expected_cancellable ) {
			$job_id = wp_generate_uuid4();
			$state  = array(
				'id'         => $job_id,
				'status'     => $status,
				'user_id'    => $this->admin_id,
				'created_at' => time(),
				'updated_at' => time(),
				'total'      => 0,
				'processed'  => 0,
			);
			set_transient( WP_MCP_AI_Transcript_Mining_Job::STATE_PREFIX . $job_id, $state, 600 );

			$jobs = $adapter->get_jobs( $this->admin_id );

			$this->assertArrayHasKey( $job_id, $jobs );
			$this->assertSame(
				$expected_cancellable,
				$jobs[ $job_id ]['cancellable'],
				"Expected cancellable={$expected_cancellable} for status={$status}"
			);

			delete_transient( WP_MCP_AI_Transcript_Mining_Job::STATE_PREFIX . $job_id );
		}
	}

	// ── Crawl4AI adapter ─────────────────────────────────────────────────────

	/**
	 * When no Crawl4AI transients exist, get_jobs() returns empty.
	 */
	public function test_crawl4ai_returns_empty_when_no_jobs() {
		$adapter = new WP_MCP_AI_Job_Source_Crawl4AI();
		$jobs    = $adapter->get_jobs( $this->user_id );

		$this->assertIsArray( $jobs );
		$this->assertEmpty( $jobs );
	}

	/**
	 * A Crawl4AI transient for the requesting user is surfaced.
	 */
	public function test_crawl4ai_returns_own_job() {
		$task_id = 'task_' . wp_generate_uuid4();
		$key     = WP_MCP_AI_Crawler::JOB_STORAGE_PREFIX . md5( $task_id );
		$job     = array(
			'task_id'    => $task_id,
			'status'     => 'polling',
			'base_url'   => 'https://example.com/',
			'created_at' => time() - 5,
			'updated_at' => time(),
			'context'    => array(
				'user_id'      => $this->user_id,
				'assistant_id' => '99',
			),
		);
		set_transient( $key, $job, 600 );

		$adapter = new WP_MCP_AI_Job_Source_Crawl4AI();
		$jobs    = $adapter->get_jobs( $this->user_id );

		$this->assertArrayHasKey( $task_id, $jobs );
		$record = $jobs[ $task_id ];
		$this->assertSame( $task_id, $record['job_id'] );
		$this->assertSame( 'polling', $record['status'] );
		$this->assertSame( '99', $record['assistant_id'] );
		$this->assertSame( 'crawl4ai', $record['kind'] );

		delete_transient( $key );
	}

	/**
	 * A non-admin user does not see another user's Crawl4AI job.
	 */
	public function test_crawl4ai_hides_other_users_jobs() {
		$other_user = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$task_id    = 'task_' . wp_generate_uuid4();
		$key        = WP_MCP_AI_Crawler::JOB_STORAGE_PREFIX . md5( $task_id );
		$job        = array(
			'task_id'    => $task_id,
			'status'     => 'pending',
			'base_url'   => 'https://other.example.com/',
			'created_at' => time(),
			'updated_at' => time(),
			'context'    => array( 'user_id' => $other_user ),
		);
		set_transient( $key, $job, 600 );

		$adapter = new WP_MCP_AI_Job_Source_Crawl4AI();
		$jobs    = $adapter->get_jobs( $this->user_id );

		$this->assertArrayNotHasKey( $task_id, $jobs );

		delete_transient( $key );
	}

	/**
	 * An admin sees all Crawl4AI jobs.
	 */
	public function test_crawl4ai_admin_sees_all_jobs() {
		$task_id = 'task_' . wp_generate_uuid4();
		$key     = WP_MCP_AI_Crawler::JOB_STORAGE_PREFIX . md5( $task_id );
		$job     = array(
			'task_id'    => $task_id,
			'status'     => 'completed',
			'base_url'   => 'https://example.com/',
			'created_at' => time(),
			'updated_at' => time(),
			'context'    => array( 'user_id' => $this->user_id ),
		);
		set_transient( $key, $job, 600 );

		wp_set_current_user( $this->admin_id );
		$adapter = new WP_MCP_AI_Job_Source_Crawl4AI();
		$jobs    = $adapter->get_jobs( $this->admin_id );

		$this->assertArrayHasKey( $task_id, $jobs );

		delete_transient( $key );
	}

	// ── HITL Approvals adapter ───────────────────────────────────────────────

	/**
	 * When no pending approvals exist, get_jobs() returns empty.
	 */
	public function test_hitl_returns_empty_when_no_pending_approvals() {
		$adapter = new WP_MCP_AI_Job_Source_Hitl_Approvals();
		$jobs    = $adapter->get_jobs( $this->user_id );

		$this->assertIsArray( $jobs );
		$this->assertEmpty( $jobs );
	}

	/**
	 * A pending approval for the requesting user is surfaced.
	 */
	public function test_hitl_returns_own_pending_approval() {
		$queue = WP_MCP_AI_Approval_Queue::get_instance();
		wp_set_current_user( $this->user_id );

		$post_id = $queue->enqueue( array(
			'tool'         => 'delete_post',
			'arguments'    => array( 'id' => 1 ),
			'assistant_id' => 77,
			'requester_id' => $this->user_id,
			'session_id'   => 'test_session',
			'reason'       => 'Needs human review',
		) );

		$this->assertIsInt( $post_id );

		$adapter = new WP_MCP_AI_Job_Source_Hitl_Approvals();
		$jobs    = $adapter->get_jobs( $this->user_id );

		$expected_key = 'approval_' . $post_id;
		$this->assertArrayHasKey( $expected_key, $jobs );

		$record = $jobs[ $expected_key ];
		$this->assertSame( $expected_key, $record['job_id'] );
		$this->assertSame( 'pending', $record['status'] );
		$this->assertSame( 'hitl_approval', $record['kind'] );
		$this->assertSame( $this->user_id, $record['created_by'] );
		$this->assertSame( '77', $record['assistant_id'] );
		$this->assertFalse( $record['cancellable'] );
		$this->assertFalse( $record['retryable'] );
	}

	/**
	 * A non-admin user cannot see another user's pending approval.
	 */
	public function test_hitl_hides_other_users_pending_approvals() {
		$other_user = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$queue      = WP_MCP_AI_Approval_Queue::get_instance();
		wp_set_current_user( $other_user );

		$post_id = $queue->enqueue( array(
			'tool'         => 'publish_post',
			'arguments'    => array(),
			'assistant_id' => 0,
			'requester_id' => $other_user,
			'session_id'   => 'other_session',
		) );

		$this->assertIsInt( $post_id );

		$adapter = new WP_MCP_AI_Job_Source_Hitl_Approvals();
		$jobs    = $adapter->get_jobs( $this->user_id );

		$this->assertArrayNotHasKey( 'approval_' . $post_id, $jobs );
	}

	/**
	 * An admin sees all pending approval requests.
	 */
	public function test_hitl_admin_sees_all_pending_approvals() {
		$queue = WP_MCP_AI_Approval_Queue::get_instance();
		wp_set_current_user( $this->user_id );

		$post_id = $queue->enqueue( array(
			'tool'         => 'send_email',
			'arguments'    => array(),
			'assistant_id' => 0,
			'requester_id' => $this->user_id,
			'session_id'   => 'admin_test_session',
		) );

		$this->assertIsInt( $post_id );

		wp_set_current_user( $this->admin_id );
		$adapter = new WP_MCP_AI_Job_Source_Hitl_Approvals();
		$jobs    = $adapter->get_jobs( $this->admin_id );

		$this->assertArrayHasKey( 'approval_' . $post_id, $jobs );
	}

	/**
	 * An approved approval (post_status = 'publish') is not returned.
	 */
	public function test_hitl_excludes_resolved_approvals() {
		$queue = WP_MCP_AI_Approval_Queue::get_instance();
		wp_set_current_user( $this->admin_id );

		$post_id = $queue->enqueue( array(
			'tool'         => 'delete_user',
			'arguments'    => array(),
			'assistant_id' => 0,
			'requester_id' => $this->admin_id,
			'session_id'   => 'resolved_test',
		) );

		$this->assertIsInt( $post_id );

		// Approve the request.
		$queue->approve( $post_id, $this->admin_id );

		$adapter = new WP_MCP_AI_Job_Source_Hitl_Approvals();
		$jobs    = $adapter->get_jobs( $this->admin_id );

		$this->assertArrayNotHasKey( 'approval_' . $post_id, $jobs );
	}

	// ── Filter integration ────────────────────────────────────────────────────

	/**
	 * The job-sources-init.php registers all three base adapters on the filter.
	 */
	public function test_base_adapters_are_registered_via_filter() {
		require_once WP_MCP_AI_PATH . 'includes/services/job-sources/job-sources-init.php';

		$sources = apply_filters( 'wp_mcp_ai_cron_status_job_sources', array() );

		$this->assertArrayHasKey( 'transcript_mining', $sources );
		$this->assertArrayHasKey( 'crawl4ai', $sources );
		$this->assertArrayHasKey( 'hitl_approvals', $sources );

		$this->assertInstanceOf( 'Interface_WP_MCP_AI_Cron_Status_Job_Source', $sources['transcript_mining'] );
		$this->assertInstanceOf( 'Interface_WP_MCP_AI_Cron_Status_Job_Source', $sources['crawl4ai'] );
		$this->assertInstanceOf( 'Interface_WP_MCP_AI_Cron_Status_Job_Source', $sources['hitl_approvals'] );
	}
}
