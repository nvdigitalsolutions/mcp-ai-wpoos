<?php
/**
 * Conversation import manager port tests (Wave E4, sub-cluster 6).
 *
 * Characterization suite for the ported `ConversationImport\Manager`:
 * the dry-run counting pass, the skip/refresh dedupe policies, the
 * limit cap, resume-token validation, per-batch progress callbacks,
 * the completion action with imported session keys, and the inspect /
 * status helpers — exercised through the per-mode conversation seam
 * (base adapters + base conversations monolith, platform classes
 * standalone). Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\ConversationImport\CctWriter;
use NvoosContentGraphAiPlatform\ConversationImport\Manager;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The stub-writer fixture shares this file with its test case.

/**
 * Stub writer for manager tests: records writes without a database.
 *
 * The write boundary is untyped — monolith installs flow base
 * conversations through the manager (base adapters), standalone installs
 * flow this package's class.
 */
class Conversation_Import_Manager_Stub_Writer extends CctWriter {

	/**
	 * Session keys written during the run.
	 *
	 * @var string[]
	 */
	public $written = array();

	/**
	 * Existing rows returned by the dedupe lookup.
	 *
	 * @var array
	 */
	public $existing = array();

	/**
	 * Look up existing rows for a set of session keys.
	 *
	 * @param string[] $session_keys Session keys to look up.
	 * @return array|\WP_Error
	 */
	public function find_existing_ids( array $session_keys ) {
		return $this->existing;
	}

	/**
	 * Record a write call without touching the database.
	 *
	 * @param object $conversation Canonical conversation (base or platform class, per install mode).
	 * @param int    $user_id      Importing user ID.
	 * @param int    $existing_id  Existing row ID (0 = insert).
	 * @return array
	 */
	public function write( $conversation, $user_id, $existing_id = 0 ) {
		$this->written[] = $conversation->get_session_key();

		return array(
			'id'     => 1,
			'action' => 0 !== $existing_id ? 'updated' : 'imported',
		);
	}
}

/**
 * Conversation import manager test suite.
 */
class Test_Conversation_Import_Manager extends \WP_UnitTestCase {

	/**
	 * Temp files created during tests.
	 *
	 * @var string[]
	 */
	protected $temp_files = array();

	/**
	 * Clean up temp files and checkpoint state after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
		$this->temp_files = array();

		delete_option( Manager::CHECKPOINT_OPTION );

		parent::tearDown();
	}

	/**
	 * Write a temp ChatGPT fixture file.
	 *
	 * @return string Absolute path.
	 */
	protected function write_fixture_file() {
		$fixture = array(
			array(
				'id'                 => 'conv-1',
				'title'              => 'Hello World',
				'create_time'        => 1700000000.0,
				'update_time'        => 1700000100.0,
				'default_model_slug' => 'gpt-4',
				'current_node'       => 'msg-2',
				'mapping'            => array(
					'root'  => array(
						'id'       => 'root',
						'message'  => null,
						'parent'   => null,
						'children' => array( 'msg-1' ),
					),
					'msg-1' => array(
						'id'       => 'msg-1',
						'message'  => array(
							'id'          => 'msg-1',
							'author'      => array( 'role' => 'user' ),
							'create_time' => 1700000001.0,
							'content'     => array(
								'content_type' => 'text',
								'parts'        => array( 'Hello!' ),
							),
							'weight'      => 1.0,
							'metadata'    => array(),
						),
						'parent'   => 'root',
						'children' => array( 'msg-2' ),
					),
					'msg-2' => array(
						'id'       => 'msg-2',
						'message'  => array(
							'id'          => 'msg-2',
							'author'      => array( 'role' => 'assistant' ),
							'create_time' => 1700000002.0,
							'content'     => array(
								'content_type' => 'text',
								'parts'        => array( 'Hi there!' ),
							),
							'weight'      => 1.0,
							'metadata'    => array(),
						),
						'parent'   => 'msg-1',
						'children' => array(),
					),
				),
			),
			array(
				'id'           => 'conv-2',
				'title'        => 'Second conversation',
				'create_time'  => 1700000200.0,
				'update_time'  => 1700000200.0,
				'current_node' => 'b-2',
				'mapping'      => array(
					'b-root' => array(
						'id'       => 'b-root',
						'message'  => null,
						'parent'   => null,
						'children' => array( 'b-1' ),
					),
					'b-1'    => array(
						'id'       => 'b-1',
						'message'  => array(
							'id'          => 'b-1',
							'author'      => array( 'role' => 'user' ),
							'create_time' => 1700000201.0,
							'content'     => array(
								'content_type' => 'text',
								'parts'        => array( 'Another question.' ),
							),
							'weight'      => 1.0,
							'metadata'    => array(),
						),
						'parent'   => 'b-root',
						'children' => array( 'b-2' ),
					),
					'b-2'    => array(
						'id'       => 'b-2',
						'message'  => array(
							'id'          => 'b-2',
							'author'      => array( 'role' => 'assistant' ),
							'create_time' => 1700000202.0,
							'content'     => array(
								'content_type' => 'text',
								'parts'        => array( 'Another answer.' ),
							),
							'weight'      => 1.0,
							'metadata'    => array(),
						),
						'parent'   => 'b-1',
						'children' => array(),
					),
				),
			),
		);

		$file = tempnam( sys_get_temp_dir(), 'wpmcp-port-mgr-' ) . '.json';
		file_put_contents( $file, wp_json_encode( $fixture ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture writing.

		$this->temp_files[] = $file;

		return $file;
	}

	/**
	 * Manager: dry-run counts without writing.
	 *
	 * @return void
	 */
	public function test_manager_dry_run_counts_without_writing() {
		$file = $this->write_fixture_file();

		$stub           = new Conversation_Import_Manager_Stub_Writer();
		$stub->existing = array();

		$manager = new Manager( $stub );
		$report  = $manager->run(
			array(
				'source'  => $file,
				'dry_run' => true,
				'user_id' => 1,
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $report );
		$this->assertSame( 'completed', $report['status'] );
		$this->assertSame( 2, $report['totals']['detected'] );
		$this->assertSame( 2, $report['totals']['imported'] );
		$this->assertSame( 0, $report['totals']['failed'] );
		$this->assertEmpty( $stub->written );
	}

	/**
	 * Manager: skip policy leaves existing rows untouched.
	 *
	 * @return void
	 */
	public function test_manager_skip_policy() {
		$file = $this->write_fixture_file();

		$stub           = new Conversation_Import_Manager_Stub_Writer();
		$stub->existing = array(
			'import-chatgpt-' . substr( sha1( 'conv-1' ), 0, 12 ) => 42,
		);

		$manager = new Manager( $stub );
		$report  = $manager->run(
			array(
				'source'  => $file,
				'policy'  => 'skip',
				'user_id' => 1,
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $report );
		$this->assertSame( 1, $report['totals']['imported'] );
		$this->assertSame( 1, $report['totals']['skipped'] );
		$this->assertCount( 1, $stub->written );
	}

	/**
	 * Manager: refresh policy updates existing rows.
	 *
	 * @return void
	 */
	public function test_manager_refresh_policy() {
		$file = $this->write_fixture_file();

		$stub           = new Conversation_Import_Manager_Stub_Writer();
		$stub->existing = array(
			'import-chatgpt-' . substr( sha1( 'conv-1' ), 0, 12 ) => 42,
		);

		$manager = new Manager( $stub );
		$report  = $manager->run(
			array(
				'source'  => $file,
				'policy'  => 'refresh',
				'user_id' => 1,
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $report );
		$this->assertSame( 1, $report['totals']['updated'] );
		$this->assertSame( 1, $report['totals']['imported'] );
		$this->assertCount( 2, $stub->written );
	}

	/**
	 * Manager: limit caps the number of processed conversations.
	 *
	 * @return void
	 */
	public function test_manager_limit_caps_processing() {
		$file = $this->write_fixture_file();

		$stub    = new Conversation_Import_Manager_Stub_Writer();
		$manager = new Manager( $stub );
		$report  = $manager->run(
			array(
				'source'  => $file,
				'limit'   => 1,
				'user_id' => 1,
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $report );
		$this->assertSame( 1, $report['totals']['processed'] );
		$this->assertCount( 1, $stub->written );
	}

	/**
	 * Manager: unknown resume token yields WP_Error.
	 *
	 * @return void
	 */
	public function test_manager_rejects_unknown_resume_token() {
		$file = $this->write_fixture_file();

		$stub    = new Conversation_Import_Manager_Stub_Writer();
		$manager = new Manager( $stub );
		$result  = $manager->run(
			array(
				'source'       => $file,
				'user_id'      => 1,
				'resume_token' => 'import-00000000000000-deadbeef0000',
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_import_resume_not_found', $result->get_error_code() );
	}

	/**
	 * Manager: progress callback fires after each batch and at completion.
	 *
	 * @return void
	 */
	public function test_manager_progress_callback_fires_per_batch() {
		$file = $this->write_fixture_file();

		$stub    = new Conversation_Import_Manager_Stub_Writer();
		$manager = new Manager( $stub );

		$events = array();
		$manager->set_progress_callback(
			function ( $progress ) use ( &$events ) {
				$events[] = $progress;
			}
		);

		$report = $manager->run(
			array(
				'source'     => $file,
				'batch_size' => 1,
				'user_id'    => 1,
				'estimate'   => 2,
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $report );
		$this->assertNotEmpty( $events );

		$first = $events[0];
		$this->assertSame( 1, $first['processed'] );
		$this->assertSame( 2, $first['estimated_total'] );

		$last = $events[ count( $events ) - 1 ];
		$this->assertSame( 2, $last['processed'] );
		$this->assertSame( 2, $last['totals']['imported'] );
	}

	/**
	 * Manager: fires the completion action with the report and imported keys.
	 *
	 * @return void
	 */
	public function test_manager_fires_completion_action_with_keys() {
		$file = $this->write_fixture_file();

		$stub    = new Conversation_Import_Manager_Stub_Writer();
		$manager = new Manager( $stub );

		$captured = array();
		add_action(
			'wp_mcp_ai_conversation_import_completed',
			function ( $report, $user_id ) use ( &$captured ) {
				$captured = array(
					'report'  => $report,
					'user_id' => $user_id,
				);
			},
			10,
			2
		);

		$report = $manager->run(
			array(
				'source'  => $file,
				'user_id' => 7,
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $report );
		$this->assertNotEmpty( $captured );
		$this->assertSame( 7, $captured['user_id'] );
		$this->assertSame( 'completed', $captured['report']['status'] );
		$this->assertContains( 'import-chatgpt-' . substr( sha1( 'conv-1' ), 0, 12 ), $captured['report']['imported_session_keys'] );
	}

	/**
	 * Manager: inspect reports detection without importing.
	 *
	 * @return void
	 */
	public function test_manager_inspect_reports_detection() {
		$file = $this->write_fixture_file();

		$manager = new Manager();
		$result  = $manager->inspect( $file );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'chatgpt', $result['platform'] );
		$this->assertSame( 2, $result['estimated_count'] );
		$this->assertNotEmpty( $result['adapters'] );
	}

	/**
	 * Manager: unknown checkpoint tokens report not_found status.
	 *
	 * @return void
	 */
	public function test_manager_status_unknown_token() {
		$manager = new Manager();
		$status  = $manager->get_status( 'import-00000000000000-deadbeef0000' );

		$this->assertSame( 'not_found', $status['status'] );
	}
}
