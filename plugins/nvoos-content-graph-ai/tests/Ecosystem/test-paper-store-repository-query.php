<?php
/**
 * Paper Store repository/query/bootstrap port tests (Wave E6, sub-cluster 3).
 *
 * Characterization suite for the ported `NvoosContentGraphAi\Engine\PaperStore`
 * repository (save defaults, pre-save filter, immutable update fields,
 * delete/truncate lifecycle, actions), the fluent query builder
 * (index-resolved vs post-filtered clauses, the tags/status IN quirks,
 * date-bucket resolution, loose comparisons, ordering, limit/offset,
 * immutability), and the bootstrap hook surface (priority-30 tool
 * registration listener, per-mode no-op resolution). Runs in both
 * matrices.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Engine\PaperStore\PaperStoreBootstrap;
use NvoosContentGraphAi\Engine\PaperStore\PaperStoreManager;

/**
 * @group paper-store
 */
class Test_Paper_Store_Repository_Query extends \WP_UnitTestCase {

	/**
	 * Temp paper-store root path.
	 *
	 * @var string
	 */
	private $paper_root = '';

	/**
	 * Manager instance.
	 *
	 * @var PaperStoreManager
	 */
	private $manager;

	public function setUp(): void {
		parent::setUp();

		$this->paper_root = \sys_get_temp_dir() . '/nvoos-cg-paper-test-' . \wp_rand( 100000, 999999 ) . '/';
		if ( ! \is_dir( $this->paper_root ) ) {
			\mkdir( $this->paper_root, 0777, true );
		}

		\add_filter(
			'wp_mcp_ai_paper_store_root',
			function () {
				return $this->paper_root;
			},
			999
		);

		$this->manager = PaperStoreManager::get_instance();
		$this->manager->reset();

		// The ecosystem composition roots never run in the test process
		// (plugins_loaded has passed when the test bootstrap loads them),
		// and the framework restores the hook registry between tests —
		// reset the bootstrap's static gate so each test can wire it
		// independently. Standalone: register directly so the hook
		// surface is exercisable. Monolith: the base loader owns the same
		// registration — leave it alone.
		$ref = new \ReflectionProperty( PaperStoreBootstrap::class, 'registered' );
		$ref->setAccessible( true );
		$ref->setValue( null, false );

		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			PaperStoreBootstrap::register();
		}
	}

	public function tearDown(): void {
		$this->manager->reset();
		\remove_all_filters( 'wp_mcp_ai_paper_store_root', 999 );

		if ( '' !== $this->paper_root && \is_dir( $this->paper_root ) ) {
			$this->delete_directory( $this->paper_root );
		}

		parent::tearDown();
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function delete_directory( string $dir ): void {
		if ( ! \is_dir( $dir ) ) {
			return;
		}
		$items = \scandir( $dir );
		if ( ! \is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( \is_dir( $path ) ) {
				$this->delete_directory( $path );
			} else {
				\unlink( $path );
			}
		}
		\rmdir( $dir );
	}

	/**
	 * Create a sample record array for testing.
	 *
	 * @param string $id    Record ID.
	 * @param string $title Record title.
	 * @return array
	 */
	private function make_record( string $id = 'test-record', string $title = 'Test Record' ): array {
		return array(
			'id'          => $id,
			'type'        => 'test',
			'title'       => $title,
			'description' => 'A test record for unit testing.',
			'tags'        => array( 'test', 'unit' ),
			'status'      => 'published',
			'body'        => array( 'content' => 'Hello, world!' ),
			'meta'        => array( 'key' => 'value' ),
		);
	}

	/**
	 * Create + switch to an administrator user.
	 *
	 * @return int
	 */
	private function admin_user(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Get the repository fixture.
	 *
	 * @param string $collection Collection name.
	 * @return \NvoosContentGraphAi\Engine\PaperStore\PaperRepository
	 */
	private function repository( string $collection = 'knowledge' ) {
		return $this->manager->get_repository( $collection );
	}

	// ─── Repository ───────────────────────────────────────────────

	public function test_save_returns_normalized_record_with_defaults(): void {
		$repo = $this->repository( 'products' );

		$saved = $repo->save(
			array(
				'id'    => 'dior',
				'title' => 'Dior Sauvage',
			)
		);

		$this->assertIsArray( $saved );
		$this->assertSame( 'products', $saved['type'] ); // type default = collection.
		$this->assertSame( 'published', $saved['status'] ); // status default.
		$this->assertNotEmpty( $saved['created_at'] );
		$this->assertNotEmpty( $saved['updated_at'] );
	}

	public function test_save_stamps_author_id_when_logged_in(): void {
		$user_id = $this->admin_user();
		$repo    = $this->repository();

		$saved = $repo->save(
			array(
				'id'    => 'authored',
				'title' => 'Authored',
			)
		);

		$this->assertSame( $user_id, $saved['author_id'] );

		\wp_set_current_user( 0 );
	}

	public function test_save_without_id_returns_wp_error(): void {
		$repo = $this->repository();
		$result = $repo->save( array( 'title' => 'No ID' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'paper_missing_id', $result->get_error_code() );
	}

	public function test_save_applies_before_save_filter_and_fires_saved_action(): void {
		$saved_events = array();
		\add_action(
			'wp_mcp_ai_paper_record_saved',
			function ( $collection, $record_id, $record ) use ( &$saved_events ): void {
				$saved_events[] = array( $collection, $record_id, $record );
			},
			10,
			3
		);
		\add_filter(
			'wp_mcp_ai_paper_record_before_save',
			function ( $record, $collection ) {
				$this->assertSame( 'knowledge', $collection );
				$record['injected'] = 'yes';
				return $record;
			},
			10,
			2
		);

		$repo = $this->repository();
		$saved = $repo->save( $this->make_record( 'filtered' ) );

		$this->assertSame( 'yes', $saved['injected'] );
		$this->assertCount( 1, $saved_events );
		$this->assertSame( 'knowledge', $saved_events[0][0] );
		$this->assertSame( 'filtered', $saved_events[0][1] );
		$this->assertSame( $saved, $saved_events[0][2] );
	}

	public function test_find_returns_record_or_null(): void {
		$repo = $this->repository();
		$repo->save( $this->make_record( 'found' ) );

		$this->assertIsArray( $repo->find( 'found' ) );
		$this->assertNull( $repo->find( 'missing' ) );
	}

	public function test_all_returns_all_records(): void {
		$repo = $this->repository();
		$repo->save( $this->make_record( 'a', 'A' ) );
		$repo->save( $this->make_record( 'b', 'B' ) );

		$all = $repo->all();
		$this->assertCount( 2, $all );
	}

	public function test_update_merges_and_protects_immutable_fields(): void {
		$repo = $this->repository();
		$repo->save( $this->make_record( 'upd' ) );

		$updated = $repo->update(
			'upd',
			array(
				'id'         => 'evil-override',
				'created_at' => '1999-01-01T00:00:00+00:00',
				'title'      => 'Updated Title',
			)
		);

		$this->assertIsArray( $updated );
		$this->assertSame( 'upd', $updated['id'] );
		$this->assertNotSame( '1999-01-01T00:00:00+00:00', $updated['created_at'] );
		$this->assertSame( 'Updated Title', $updated['title'] );
	}

	public function test_update_missing_returns_wp_error(): void {
		$repo = $this->repository();
		$result = $repo->update( 'ghost', array( 'title' => 'X' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'paper_not_found', $result->get_error_code() );
	}

	public function test_delete_removes_record_and_fires_action(): void {
		$deleted_events = array();
		\add_action(
			'wp_mcp_ai_paper_record_deleted',
			function ( $collection, $record_id ) use ( &$deleted_events ): void {
				$deleted_events[] = array( $collection, $record_id );
			},
			10,
			2
		);

		$repo = $this->repository();
		$repo->save( $this->make_record( 'gone' ) );

		$this->assertTrue( $repo->delete( 'gone' ) );
		$this->assertFalse( $repo->exists( 'gone' ) );
		$this->assertNull( $repo->find( 'gone' ) );
		$this->assertCount( 1, $deleted_events );
		$this->assertSame( array( 'knowledge', 'gone' ), $deleted_events[0] );
	}

	public function test_delete_missing_returns_wp_error(): void {
		$repo = $this->repository();
		$result = $repo->delete( 'ghost' );
		$this->assertWPError( $result );
		$this->assertSame( 'paper_not_found', $result->get_error_code() );
	}

	public function test_truncate_deletes_all_and_returns_count(): void {
		$repo = $this->repository();
		$repo->save( $this->make_record( 'a', 'A' ) );
		$repo->save( $this->make_record( 'b', 'B' ) );
		$repo->save( $this->make_record( 'c', 'C' ) );

		$this->assertSame( 3, $repo->truncate() );
		$this->assertSame( 0, $repo->count() );
		$this->assertSame( array(), $repo->all() );
	}

	public function test_exists_and_count(): void {
		$repo = $this->repository();
		$this->assertFalse( $repo->exists( 'x' ) );
		$this->assertSame( 0, $repo->count() );

		$repo->save( $this->make_record( 'x' ) );
		$this->assertTrue( $repo->exists( 'x' ) );
		$this->assertSame( 1, $repo->count() );
	}

	public function test_repository_accessors(): void {
		$repo = $this->repository();

		$this->assertSame( 'knowledge', $repo->get_collection_name() );
		$this->assertInstanceOf( \NvoosContentGraphAi\Engine\PaperStore\PaperIndex::class, $repo->get_index() );
		$this->assertInstanceOf( \NvoosContentGraphAi\Engine\PaperStore\PaperDriverInterface::class, $repo->get_driver() );
	}

	// ─── Query builder ────────────────────────────────────────────

	/**
	 * Seed three heterogeneous records.
	 *
	 * @return \NvoosContentGraphAi\Engine\PaperStore\PaperRepository
	 */
	private function seed_query_fixture() {
		$repo = $this->repository();
		$repo->save(
			array(
				'id'          => 'one',
				'type'        => 'article',
				'title'       => 'Alpha Post',
				'tags'        => array( 'seo', 'guide' ),
				'status'      => 'published',
				'author_id'   => 1,
				'rank'        => 3,
				'created_at'  => '2026-05-01T00:00:00+00:00',
				'updated_at'  => '2026-05-10T00:00:00+00:00',
			)
		);
		$repo->save(
			array(
				'id'          => 'two',
				'type'        => 'article',
				'title'       => 'Beta Guide',
				'tags'        => array( 'guide' ),
				'status'      => 'draft',
				'author_id'   => 2,
				'rank'        => 1,
				'created_at'  => '2026-05-02T00:00:00+00:00',
				'updated_at'  => '2026-05-12T00:00:00+00:00',
			)
		);
		$repo->save(
			array(
				'id'          => 'three',
				'type'        => 'video',
				'title'       => 'Gamma Clip',
				'tags'        => array( 'seo' ),
				'status'      => 'published',
				'author_id'   => 1,
				'rank'        => 2,
				'created_at'  => '2026-04-30T00:00:00+00:00',
				'updated_at'  => '2026-05-11T00:00:00+00:00',
			)
		);
		return $repo;
	}

	public function test_query_tags_equals_resolves_from_index(): void {
		$repo = $this->seed_query_fixture();

		$results = $repo->where( 'tags', '=', 'seo' )->get();
		$ids     = \wp_list_pluck( $results, 'id' );
		\sort( $ids );
		$this->assertSame( array( 'one', 'three' ), $ids );
	}

	public function test_query_tags_in_uses_first_value_quirk(): void {
		$repo = $this->seed_query_fixture();

		// The base resolves array values to the first element for tags.
		$results = $repo->where( 'tags', 'IN', array( 'seo', 'guide' ) )->get();
		$ids     = \wp_list_pluck( $results, 'id' );
		\sort( $ids );
		$this->assertSame( array( 'one', 'three' ), $ids );
	}

	public function test_query_status_in_merges_buckets(): void {
		$repo = $this->seed_query_fixture();

		$results = $repo->where( 'status', 'IN', array( 'published', 'draft' ) )->get();
		$this->assertCount( 3, $results );
	}

	public function test_query_type_and_author_resolve_from_index(): void {
		$repo = $this->seed_query_fixture();

		$articles = $repo->where( 'type', '=', 'article' )->get();
		$this->assertCount( 2, $articles );

		$authored = $repo->where( 'author_id', '=', 1 )->get();
		$this->assertCount( 2, $authored );
	}

	public function test_query_date_field_resolves_bucket_then_post_filters(): void {
		$repo = $this->seed_query_fixture();

		// created_at = resolves the 2026-05 bucket (one + two), then the
		// post-filter keeps exact matches only.
		$results = $repo->where( 'created_at', '=', '2026-05-01T00:00:00+00:00' )->get();
		$ids     = \wp_list_pluck( $results, 'id' );
		$this->assertSame( array( 'one' ), $ids );

		// updated_at >= resolves the same bucket and post-filters by value.
		$gte = $repo->where( 'updated_at', '>=', '2026-05-11T00:00:00+00:00' )->get();
		$this->assertCount( 2, $gte );
	}

	public function test_query_non_index_field_post_filters(): void {
		$repo = $this->seed_query_fixture();

		$results = $repo->where( 'title', 'LIKE', 'guide' )->get();
		$ids     = \wp_list_pluck( $results, 'id' );
		$this->assertSame( array( 'two' ), $ids );
	}

	public function test_query_not_equals_and_not_in_post_filters(): void {
		$repo = $this->seed_query_fixture();

		$not_draft = $repo->where( 'status', '!=', 'draft' )->get();
		$this->assertCount( 2, $not_draft );

		$not_in = $repo->where( 'status', 'NOT IN', array( 'draft' ) )->get();
		$this->assertCount( 2, $not_in );
	}

	public function test_query_comparison_operators(): void {
		$repo = $this->seed_query_fixture();

		$this->assertCount( 1, $repo->where( 'rank', '>', 2 )->get() );
		$this->assertCount( 2, $repo->where( 'rank', '>=', 2 )->get() );
		$this->assertCount( 1, $repo->where( 'rank', '<', 2 )->get() );
		$this->assertCount( 2, $repo->where( 'rank', '<=', 2 )->get() );
	}

	public function test_query_order_by_asc_and_desc(): void {
		$repo = $this->seed_query_fixture();

		$asc  = \wp_list_pluck( $repo->query()->order_by( 'rank', 'asc' )->get(), 'id' );
		$desc = \wp_list_pluck( $repo->query()->order_by( 'rank', 'desc' )->get(), 'id' );

		$this->assertSame( array( 'two', 'three', 'one' ), $asc );
		$this->assertSame( array( 'one', 'three', 'two' ), $desc );
	}

	public function test_query_limit_and_offset(): void {
		$repo = $this->seed_query_fixture();

		$this->assertCount( 2, $repo->query()->limit( 2 )->get() );

		$paged = $repo->query()->order_by( 'rank', 'asc' )->limit( 1 )->offset( 1 )->get();
		$this->assertSame( array( 'three' ), \wp_list_pluck( $paged, 'id' ) );
	}

	public function test_query_first_returns_first_or_null(): void {
		$repo = $this->seed_query_fixture();

		$first = $repo->query()->order_by( 'rank', 'asc' )->first();
		$this->assertSame( 'two', $first['id'] );

		$this->assertNull( $repo->where( 'id', '=', 'ghost' )->first() );
	}

	public function test_query_count_counts_post_filtered(): void {
		$repo = $this->seed_query_fixture();

		$this->assertSame( 3, $repo->query()->count() );
		$this->assertSame( 2, $repo->where( 'type', '=', 'article' )->count() );
		$this->assertSame( 1, $repo->where( 'title', 'LIKE', 'gamma' )->count() );
	}

	public function test_query_builder_is_immutable(): void {
		$repo = $this->seed_query_fixture();

		$base = $repo->query();
		$base->where( 'status', '=', 'draft' );
		$base->limit( 1 );
		$base->order_by( 'rank', 'desc' );

		$this->assertCount( 3, $base->get() );
	}

	// ─── Bootstrap ────────────────────────────────────────────────

	public function test_bootstrap_register_is_idempotent(): void {
		PaperStoreBootstrap::register();
		PaperStoreBootstrap::register();

		$this->assertSame( 1, $this->count_closure_hooks( 'wp_mcp_ai_bootstrapped', 'PaperStoreBootstrap.php' ) );
	}

	public function test_bootstrap_hooks_tool_registration_at_priority_30(): void {
		global $wp_filter;

		PaperStoreBootstrap::register();

		$this->assertArrayHasKey( 'wp_mcp_ai_bootstrapped', $wp_filter );
		$this->assertArrayHasKey( 30, $wp_filter['wp_mcp_ai_bootstrapped']->callbacks );

		$listener_count = 0;
		foreach ( $wp_filter['wp_mcp_ai_bootstrapped']->callbacks[30] as $cb ) {
			if ( $cb['function'] instanceof \Closure && false !== \strpos( (string) ( new \ReflectionFunction( $cb['function'] ) )->getFileName(), 'PaperStoreBootstrap.php' ) ) {
				++$listener_count;
			}
		}
		$this->assertSame( 1, $listener_count );
	}

	public function test_bootstrap_register_tools_is_mode_resolved_noop(): void {
		// Both branches are documented no-ops today; the call must not
		// fatal in either matrix.
		PaperStoreBootstrap::register_tools();
		$this->addToAssertionCount( 1 );
	}

	public function test_bootstrapped_fire_is_safe_and_coexists_with_base(): void {
		PaperStoreBootstrap::register();
		\do_action( 'wp_mcp_ai_bootstrapped' );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the base loader owns the six registrations.
			$registry = \WP_MCP_AI_Tool_Registry::get_instance();
			$this->assertTrue( $registry->is_tool_registered( 'paper_store_list' ) );
			$this->assertTrue( $registry->is_tool_registered( 'paper_store_delete' ) );
		}

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Count closures on a hook whose defining file matches a suffix.
	 *
	 * @param string $tag      Hook tag.
	 * @param string $filename File-name fragment to match.
	 * @return int
	 */
	private function count_closure_hooks( string $tag, string $filename ): int {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $tag ] ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $wp_filter[ $tag ]->callbacks as $priority_callbacks ) {
			foreach ( $priority_callbacks as $cb ) {
				if ( ! isset( $cb['function'] ) || ! ( $cb['function'] instanceof \Closure ) ) {
					continue;
				}
				$file = ( new \ReflectionFunction( $cb['function'] ) )->getFileName();
				if ( false !== $file && false !== \strpos( $file, $filename ) ) {
					++$count;
				}
			}
		}
		return $count;
	}
}
