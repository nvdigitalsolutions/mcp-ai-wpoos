<?php
/**
 * PHPUnit tests for the QMS audit log table.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * @group qms
 */
class Test_QMS_Audit_Log extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/addons/pro/includes/qms/class-wp-mcp-ai-qms-audit-log.php';
		WP_MCP_AI_QMS_Audit_Log::install();
	}

	public function test_record_writes_row_and_returns_id() {
		$id = WP_MCP_AI_QMS_Audit_Log::record(
			array(
				'event'    => 'unit_test',
				'post_id'  => 42,
				'doc_id'   => 'TEST-001',
				'revision' => '1.0',
				'meta'     => array( 'k' => 'v' ),
			)
		);
		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		$rows = WP_MCP_AI_QMS_Audit_Log::query( array( 'post_id' => 42 ) );
		$this->assertNotEmpty( $rows );
		$this->assertSame( 'unit_test', $rows[0]['event'] );
		$this->assertSame( 'TEST-001', $rows[0]['doc_id'] );
		$this->assertSame( array( 'k' => 'v' ), $rows[0]['meta'] );
	}

	public function test_query_filters_by_subsystem_and_event() {
		WP_MCP_AI_QMS_Audit_Log::record( array( 'event' => 'foo', 'post_id' => 100, 'subsystem' => 'qms' ) );
		WP_MCP_AI_QMS_Audit_Log::record( array( 'event' => 'bar', 'post_id' => 100, 'subsystem' => 'para' ) );

		$qms = WP_MCP_AI_QMS_Audit_Log::query( array( 'post_id' => 100, 'subsystem' => 'qms' ) );
		$this->assertCount( 1, $qms );
		$this->assertSame( 'foo', $qms[0]['event'] );

		$para = WP_MCP_AI_QMS_Audit_Log::query( array( 'post_id' => 100, 'subsystem' => 'para' ) );
		$this->assertCount( 1, $para );
		$this->assertSame( 'bar', $para[0]['event'] );
	}
}
