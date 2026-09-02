<?php
/**
 * Thread Manager port tests (Wave D1d).
 *
 * Characterization suite for the ported
 * `NvoosContentGraphAi\Chat\ThreadManager`. Assertions pin behaviour
 * against the base plugin's `WP_MCP_AI_Thread_Manager` (ecosystem port
 * plan, principle: behaviour-preserving).
 *
 * The WP test framework rewrites CREATE/DROP TABLE to TEMPORARY variants
 * during test methods; these tests suspend that rewrite so the real schema
 * is exercised, and clean up in tearDown (same pattern as the platform
 * addon's measurement tests).
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Chat\ThreadManager;

/**
 * @group chat
 */
class Test_Thread_Manager extends \WP_UnitTestCase {

	private $manager;

	public function setUp(): void {
		parent::setUp();

		// Suspend the framework's TEMPORARY-table rewrite for this file's
		// DDL + CRUD (see class docblock).
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		global $wpdb;
		foreach ( array( ThreadManager::TABLE_CHECKPOINTS, ThreadManager::TABLE_MESSAGES, ThreadManager::TABLE_THREADS ) as $table_base ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-harness shadow cleanup on plugin-owned tables; names from class constants.
			$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$wpdb->prefix}{$table_base}" );
		}

		ThreadManager::drop_tables();
		ThreadManager::create_tables();
		ThreadManager::reset_instance();

		$this->manager = ThreadManager::get_instance();
	}

	public function tearDown(): void {
		// Drop the real tables BEFORE re-arming the rewrite so cleanup is
		// not redirected to TEMPORARY variants.
		ThreadManager::drop_tables();
		ThreadManager::reset_instance();

		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		parent::tearDown();
	}

	private function table_exists( $base_name ): bool {
		global $wpdb;
		$table = $wpdb->prefix . $base_name;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion on plugin-owned table; name from class constant.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}

	public function test_create_tables_creates_all_three_tables(): void {
		$this->assertTrue( $this->table_exists( ThreadManager::TABLE_THREADS ) );
		$this->assertTrue( $this->table_exists( ThreadManager::TABLE_MESSAGES ) );
		$this->assertTrue( $this->table_exists( ThreadManager::TABLE_CHECKPOINTS ) );
	}

	public function test_create_thread_envelope_and_defaults(): void {
		$result = $this->manager->create_thread(
			5,
			0,
			array( 'provider' => 'openai', 'model' => 'gpt-4o' ),
			'write',
			array( 'type' => 'Page', 'id' => 9 )
		);

		$this->assertTrue( $result['success'] );
		$thread = $result['data'];
		$this->assertIsInt( $thread['id'] );
		$this->assertGreaterThan( 0, $thread['id'] );
		$this->assertStringStartsWith( 'Write — ', $thread['title'] );
		$this->assertSame( 'active', $thread['status'] );
		$this->assertSame( 'openai', $thread['model_provider'] );
		$this->assertSame( 'gpt-4o', $thread['model_name'] );
		$this->assertSame( 'write', $thread['profile'] );
		$this->assertSame( 'Page', $thread['scope_type'] );
		$this->assertSame( 9, $thread['scope_id'] );
		$this->assertSame( 0, $thread['message_count'] );
		$this->assertSame( 5, $thread['user_id'] );
	}

	public function test_create_thread_without_model_formats_default_name(): void {
		$result = $this->manager->create_thread( 7 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Default', $result['data']['model_name'] );
		$this->assertSame( '', $result['data']['model_provider'] );
		$this->assertSame( 'General', $result['data']['scope_type'] );
	}

	public function test_get_thread_missing_returns_null(): void {
		$this->assertNull( $this->manager->get_thread( 999999 ) );
	}

	public function test_list_threads_pagination_and_status_filter(): void {
		$this->manager->create_thread( 11, 0, array(), 'write' );
		$this->manager->create_thread( 11, 0, array(), 'write' );
		$this->manager->create_thread( 11, 0, array(), 'write' );
		$this->manager->create_thread( 22, 0, array(), 'write' );

		$threads = $this->manager->list_threads( 11, 'active' )['data']['threads'];
		$this->assertSame( 3, $this->manager->list_threads( 11, 'active' )['data']['total'] );
		$this->assertCount( 3, $threads );

		// Archive one and re-check filters.
		$this->manager->archive_thread( $threads[0]['id'] );
		$this->assertSame( 2, $this->manager->list_threads( 11, 'active' )['data']['total'] );
		$this->assertSame( 1, $this->manager->list_threads( 11, 'archived' )['data']['total'] );
		$this->assertSame( 3, $this->manager->list_threads( 11, 'all' )['data']['total'] );

		// Pagination: per_page 1, page 2 returns exactly one thread.
		$page_two = $this->manager->list_threads( 11, 'all', 2, 1 )['data'];
		$this->assertSame( 3, $page_two['total'] );
		$this->assertCount( 1, $page_two['threads'] );

		// Other users are isolated.
		$this->assertSame( 1, $this->manager->list_threads( 22, 'all' )['data']['total'] );
	}

	public function test_archive_and_restore_thread(): void {
		$created = $this->manager->create_thread( 3 );
		$id      = $created['data']['id'];

		$this->assertTrue( $this->manager->archive_thread( $id )['success'] );
		$this->assertSame( 'archived', $this->manager->get_thread( $id )['status'] );

		$this->assertTrue( $this->manager->restore_thread( $id )['success'] );
		$this->assertSame( 'active', $this->manager->get_thread( $id )['status'] );

		// Base semantics: updating a missing row returns 0 (not false) → success.
		$this->assertTrue( $this->manager->archive_thread( 999999 )['success'] );
	}

	public function test_summarize_thread_archives_and_continues(): void {
		$created = $this->manager->create_thread( 3 );
		$id      = $created['data']['id'];

		$result = $this->manager->summarize_thread( $id );

		$this->assertTrue( $result['success'] );
		$new_id = $result['data']['new_thread_id'];
		$this->assertNotSame( $id, $new_id );

		$old = $this->manager->get_thread( $id );
		$new = $this->manager->get_thread( $new_id );
		$this->assertSame( 'archived', $old['status'] );
		$this->assertSame( 'active', $new['status'] );
		$this->assertStringEndsWith( ' (continued)', $new['title'] );
	}

	public function test_summarize_missing_thread_errors(): void {
		$result = $this->manager->summarize_thread( 999999 );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_thread_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_add_message_requires_thread_and_increments_count(): void {
		$missing = $this->manager->add_message( 999999, 'user', 'Hi' );
		$this->assertWPError( $missing );
		$this->assertSame( 'wp_mcp_ai_thread_not_found', $missing->get_error_code() );

		$created = $this->manager->create_thread( 9 );
		$id      = $created['data']['id'];

		$first  = $this->manager->add_message( $id, 'user', 'Hello' );
		$second = $this->manager->add_message( $id, 'assistant', 'Hi there', 0 );

		$this->assertTrue( $first['success'] );
		$this->assertIsInt( $first['data']['message_id'] );
		$this->assertTrue( $second['success'] );
		$this->assertSame( 2, $this->manager->get_thread( $id )['message_count'] );
	}

	public function test_get_messages_pagination_and_missing_thread(): void {
		$missing = $this->manager->get_messages( 999999 );
		$this->assertWPError( $missing );

		$created = $this->manager->create_thread( 9 );
		$id      = $created['data']['id'];
		$this->manager->add_message( $id, 'user', 'one' );
		$this->manager->add_message( $id, 'assistant', 'two' );
		$this->manager->add_message( $id, 'user', 'three' );

		$page_one = $this->manager->get_messages( $id, 1, 2 )['data'];
		$this->assertSame( 3, $page_one['total'] );
		$this->assertCount( 2, $page_one['messages'] );
		$this->assertSame( 'one', $page_one['messages'][0]['content'] );

		$page_two = $this->manager->get_messages( $id, 2, 2 )['data'];
		$this->assertCount( 1, $page_two['messages'] );
		$this->assertSame( 'three', $page_two['messages'][0]['content'] );
	}

	public function test_get_thread_context_returns_recent_in_order(): void {
		$created = $this->manager->create_thread( 9 );
		$id      = $created['data']['id'];

		for ( $i = 1; $i <= 5; $i++ ) {
			$this->manager->add_message( $id, 'user', "msg{$i}" );
		}

		// created_at is second-precision — identical timestamps tie-break
		// arbitrarily in MySQL. Pin deterministic timestamps per message id
		// (fresh table → ids 1..5 in insertion order) so the context query
		// is ordered unambiguously.
		global $wpdb;
		$table = $this->manager->get_messages_table();
		for ( $i = 1; $i <= 5; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture ordering on plugin-owned table; name from class constant.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET created_at = DATE_ADD('2026-01-01 00:00:00', INTERVAL %d SECOND) WHERE id = %d",
					$i,
					$i
				)
			);
		}

		$context = $this->manager->get_thread_context( $id, 2 );

		$this->assertCount( 2, $context );
		$this->assertSame( 'msg4', $context[0]['content'] );
		$this->assertSame( 'msg5', $context[1]['content'] );
	}

	public function test_checkpoint_roundtrip_and_diff(): void {
		$created = $this->manager->create_thread( 9 );
		$id      = $created['data']['id'];

		$checkpoint = $this->manager->create_checkpoint( $id, 'Milestone', array( 1, 2 ) );
		$this->assertTrue( $checkpoint['success'] );
		$checkpoint_id = $checkpoint['data']['checkpoint_id'];

		$list = $this->manager->get_checkpoints( $id )['data']['checkpoints'];
		$this->assertCount( 1, $list );
		$this->assertSame( 'Milestone', $list[0]['label'] );
		$this->assertSame( array( 1, 2 ), $list[0]['diff_data']['affected_ids'] );

		$diff = $this->manager->get_checkpoint_diff( $id, $checkpoint_id );
		$this->assertTrue( $diff['success'] );
		$this->assertSame( array( 1, 2 ), $diff['data']['changes'] );

		$missing = $this->manager->get_checkpoint_diff( $id, 999999 );
		$this->assertWPError( $missing );
		$this->assertSame( 'wp_mcp_ai_checkpoint_not_found', $missing->get_error_code() );
	}

	public function test_restore_checkpoint_deletes_later_messages_and_recounts(): void {
		$created = $this->manager->create_thread( 9 );
		$id      = $created['data']['id'];

		$this->manager->add_message( $id, 'user', 'before-1', 0 );
		$this->manager->add_message( $id, 'user', 'before-2', 0 );
		$this->manager->add_message( $id, 'user', 'after-1', 2 );
		$this->manager->add_message( $id, 'user', 'after-2', 2 );

		$this->assertSame( 4, $this->manager->get_thread( $id )['message_count'] );

		$result = $this->manager->restore_checkpoint( $id, 0 );
		$this->assertTrue( $result['success'] );

		$this->assertSame( 2, $this->manager->get_thread( $id )['message_count'] );
		$this->assertSame( 2, $this->manager->get_messages( $id )['data']['total'] );
	}
}
