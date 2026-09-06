<?php
/**
 * Security audit logger port tests (Wave D4c).
 *
 * Characterization suite for `SecurityAuditLogger`. Assertions mirror the
 * base plugin's audit logger: constants, real-DDL table creation, event
 * inserts, the `wp_mcp_ai_security_event` action payload, paginated REST
 * reads with decoded details, the 30-day purge, activation/deactivation
 * cron lifecycle, and session-log event mapping. The custom table is
 * created with real DDL — the WP framework's TEMPORARY-table rewrite is
 * suspended in setUp and restored in tearDown (same pattern as the token
 * tracking suite).
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Security\SecurityAuditLogger;

/**
 * @group security
 */
class Test_Security_Audit_Logger extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		// Allow real DDL on the custom audit table.
		\remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		\remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		\remove_all_actions( 'wp_mcp_ai_security_event' );
		\remove_all_actions( 'wp_mcp_ai_purge_security_events' );

		\delete_option( 'wp_mcp_ai_security_log_table_version' );
	}

	public function tearDown(): void {
		global $wpdb;

		// Drop the real table BEFORE re-adding the framework temp-table filter.
		$table = $wpdb->prefix . SecurityAuditLogger::TABLE_NAME;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test teardown for custom table.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		\add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		\add_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		\remove_all_actions( 'wp_mcp_ai_security_event' );
		\remove_all_actions( 'wp_mcp_ai_purge_security_events' );
		\delete_option( 'wp_mcp_ai_security_log_table_version' );

		// Clear any cron events scheduled by activation tests.
		$timestamp = \wp_next_scheduled( 'wp_mcp_ai_purge_security_events' );
		if ( $timestamp ) {
			\wp_unschedule_event( $timestamp, 'wp_mcp_ai_purge_security_events' );
		}

		parent::tearDown();
	}

	public function test_constants_match_base(): void {
		$this->assertSame( 'wp_mcp_ai_security_log', SecurityAuditLogger::TABLE_NAME );
		$this->assertSame( '1.0.0', SecurityAuditLogger::TABLE_VERSION );
		$this->assertSame( 'failed_capability', SecurityAuditLogger::EVENT_FAILED_CAPABILITY );
		$this->assertSame( 'blocked_ssrf', SecurityAuditLogger::EVENT_BLOCKED_SSRF );
		$this->assertSame( 'rate_limit_hit', SecurityAuditLogger::EVENT_RATE_LIMIT_HIT );
		$this->assertSame( 'destructive_op_denied', SecurityAuditLogger::EVENT_DESTRUCTIVE_OP_DENIED );
		$this->assertSame( 'nonce_failure', SecurityAuditLogger::EVENT_NONCE_FAILURE );
		$this->assertSame( 'upload_blocked', SecurityAuditLogger::EVENT_UPLOAD_BLOCKED );
		$this->assertSame( 'tool_execution', SecurityAuditLogger::EVENT_TOOL_EXECUTION );
		$this->assertSame( 'chat_turn', SecurityAuditLogger::EVENT_CHAT_TURN );
	}

	public function test_log_event_inserts_row_and_fires_action(): void {
		$fired = array();
		\add_action(
			'wp_mcp_ai_security_event',
			static function ( $event_type, $user_id, $ip_address, $details ) use ( &$fired ): void {
				$fired = compact( 'event_type', 'user_id', 'ip_address', 'details' );
			},
			10,
			4
		);

		SecurityAuditLogger::log_event( SecurityAuditLogger::EVENT_RATE_LIMIT_HIT, 42, array( 'tool_slug' => 'web_search' ) );

		global $wpdb;
		$table = $wpdb->prefix . SecurityAuditLogger::TABLE_NAME;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );

		$this->assertCount( 1, $rows );
		$this->assertSame( SecurityAuditLogger::EVENT_RATE_LIMIT_HIT, $rows[0]['event_type'] );
		$this->assertSame( 42, (int) $rows[0]['user_id'] );
		$this->assertNotEmpty( $rows[0]['event_time'] );

		$this->assertSame( SecurityAuditLogger::EVENT_RATE_LIMIT_HIT, $fired['event_type'] );
		$this->assertSame( 42, $fired['user_id'] );
		$this->assertSame( array( 'tool_slug' => 'web_search' ), $fired['details'] );

		// Version bookkeeping written after dbDelta.
		$this->assertSame( SecurityAuditLogger::TABLE_VERSION, \get_option( 'wp_mcp_ai_security_log_table_version' ) );
	}

	public function test_get_events_returns_paginated_decoded_rows(): void {
		SecurityAuditLogger::log_event( SecurityAuditLogger::EVENT_FAILED_CAPABILITY, 7, array( 'cap' => 'manage_options' ) );
		SecurityAuditLogger::log_event( SecurityAuditLogger::EVENT_NONCE_FAILURE, 9, array( 'action' => 'x' ) );

		$request = new \WP_REST_Request( 'GET', '/mcp-ai/v1/security/events' );
		$request->set_param( 'per_page', 1 );
		$request->set_param( 'page', 1 );
		$request->set_param( 'event_type', SecurityAuditLogger::EVENT_FAILED_CAPABILITY );

		$response = SecurityAuditLogger::get_events( $request );
		$data     = $response->get_data();

		$this->assertSame( 1, $data['total'] );
		$this->assertSame( 1, $data['total_pages'] );
		$this->assertCount( 1, $data['events'] );
		$this->assertSame( SecurityAuditLogger::EVENT_FAILED_CAPABILITY, $data['events'][0]['event_type'] );
		$this->assertSame( 7, $data['events'][0]['user_id'] );
		$this->assertSame( array( 'cap' => 'manage_options' ), $data['events'][0]['details'] );
	}

	public function test_purge_removes_events_older_than_30_days(): void {
		global $wpdb;
		$table = $wpdb->prefix . SecurityAuditLogger::TABLE_NAME;

		SecurityAuditLogger::log_event( SecurityAuditLogger::EVENT_NONCE_FAILURE, 1 );
		SecurityAuditLogger::log_event( SecurityAuditLogger::EVENT_NONCE_FAILURE, 1 );

		// Age one row beyond the 30-day cutoff.
		$old = gmdate( 'Y-m-d H:i:s', strtotime( '-40 days' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET event_time = %s ORDER BY id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$old
			)
		);

		SecurityAuditLogger::purge_old_events();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$this->assertSame( 1, $remaining );
	}

	public function test_activation_and_deactivation_manage_purge_cron(): void {
		SecurityAuditLogger::on_activation();
		$this->assertNotFalse( \wp_next_scheduled( 'wp_mcp_ai_purge_security_events' ) );

		SecurityAuditLogger::on_deactivation();
		$this->assertFalse( \wp_next_scheduled( 'wp_mcp_ai_purge_security_events' ) );
	}

	public function test_session_log_tool_result_mapping(): void {
		SecurityAuditLogger::on_session_log_event(
			'tool_result',
			array(
				'user_id'      => '3',
				'name'         => 'web_search',
				'outcome'      => 'success',
				'duration_ms'  => 12.5,
				'assistant_id' => 8,
			),
			4,
			1234567890.5
		);

		$request  = new \WP_REST_Request( 'GET', '/mcp-ai/v1/security/events' );
		$request->set_param( 'per_page', 20 );
		$request->set_param( 'page', 1 );
		$response = SecurityAuditLogger::get_events( $request );
		$data     = $response->get_data();

		$this->assertSame( 1, $data['total'] );
		$event = $data['events'][0];
		$this->assertSame( SecurityAuditLogger::EVENT_TOOL_EXECUTION, $event['event_type'] );
		$this->assertSame( 3, $event['user_id'] );
		$this->assertSame( 'web_search', $event['details']['tool_slug'] );
		$this->assertSame( 'session_log', $event['details']['source'] );
		$this->assertSame( 4, $event['details']['seq'] );
	}

	public function test_session_log_turn_mapping(): void {
		SecurityAuditLogger::on_session_log_event( 'turn_started', array( 'user_id' => 5, 'reason' => 'user' ), 1, 1.0 );
		SecurityAuditLogger::on_session_log_event( 'unknown_type', array( 'user_id' => 5 ), 2, 2.0 );

		$request  = new \WP_REST_Request( 'GET', '/mcp-ai/v1/security/events' );
		$request->set_param( 'per_page', 20 );
		$request->set_param( 'page', 1 );
		$response = SecurityAuditLogger::get_events( $request );
		$data     = $response->get_data();

		$this->assertSame( 1, $data['total'] );
		$event = $data['events'][0];
		$this->assertSame( SecurityAuditLogger::EVENT_CHAT_TURN, $event['event_type'] );
		$this->assertSame( 'started', $event['details']['phase'] );
	}
}
