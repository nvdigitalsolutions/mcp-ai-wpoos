<?php
/**
 * Paper Store core port tests (Wave E6, sub-cluster 3).
 *
 * Characterization suite for the ported `NvoosContentGraphAi\Engine\PaperStore`
 * core: the JSON driver (required-field enforcement, timestamps,
 * id/type sanitization, error codes, per-mode filesystem seam), the
 * inverted index (structure, indexing paths, the rebuild-only
 * max-tags quirk, the flock save + `wp_mcp_ai_paper_index_rebuilt`
 * payload, find-by lookups, drop/ensure), and the manager (singleton
 * lifecycle, root filter, security files, traversal guard, driver
 * registration, collection listing, reset). Runs in both matrices.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Engine\PaperStore\PaperIndex;
use NvoosContentGraphAi\Engine\PaperStore\PaperJsonDriver;
use NvoosContentGraphAi\Engine\PaperStore\PaperStoreManager;

/**
 * @group paper-store
 */
class Test_Paper_Store_Core extends \WP_UnitTestCase {

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
	 * Create an index bound to a temp collection dir.
	 *
	 * @param string $collection Collection name.
	 * @return array{0: PaperIndex, 1: string} Index + collection dir.
	 */
	private function make_index( string $collection = 'knowledge' ): array {
		$collection_dir = \trailingslashit( $this->paper_root ) . $collection;
		if ( ! \is_dir( $collection_dir ) ) {
			\mkdir( $collection_dir, 0777, true );
		}
		$indexes_dir = \trailingslashit( $this->paper_root ) . '_indexes';
		return array( new PaperIndex( $collection, $collection_dir, $indexes_dir ), $collection_dir );
	}

	// ─── JSON driver ──────────────────────────────────────────────

	public function test_driver_extension_is_json(): void {
		$driver = new PaperJsonDriver();
		$this->assertSame( '.json', $driver->get_extension() );
	}

	public function test_driver_read_nonexistent_returns_wp_error(): void {
		$driver = new PaperJsonDriver();
		$result = $driver->read( $this->paper_root . 'nonexistent.json' );
		$this->assertWPError( $result );
		$this->assertSame( 'paper_file_not_found', $result->get_error_code() );
	}

	public function test_driver_read_malformed_json_returns_wp_error(): void {
		$driver = new PaperJsonDriver();
		$file   = $this->paper_root . 'malformed.json';
		\file_put_contents( $file, 'not valid json!!!' );

		$result = $driver->read( $file );
		$this->assertWPError( $result );
		$this->assertSame( 'paper_invalid_json', $result->get_error_code() );
	}

	public function test_driver_read_missing_required_fields_returns_wp_error(): void {
		$driver = new PaperJsonDriver();
		$file   = $this->paper_root . 'incomplete.json';
		\file_put_contents( $file, \wp_json_encode( array( 'title' => 'No ID' ) ) );

		$result = $driver->read( $file );
		$this->assertWPError( $result );
		$this->assertSame( 'paper_missing_fields', $result->get_error_code() );
	}

	public function test_driver_write_and_read_valid_record(): void {
		$driver = new PaperJsonDriver();
		$file   = $this->paper_root . 'valid.json';

		$this->assertTrue( $driver->write( $file, $this->make_record( 'valid-record', 'Valid Record' ) ) );

		$read = $driver->read( $file );
		$this->assertIsArray( $read );
		$this->assertSame( 'valid-record', $read['id'] );
		$this->assertSame( 'Valid Record', $read['title'] );
		$this->assertArrayHasKey( 'created_at', $read );
		$this->assertArrayHasKey( 'updated_at', $read );
	}

	public function test_driver_write_auto_sets_timestamps(): void {
		$driver = new PaperJsonDriver();
		$file   = $this->paper_root . 'timestamps.json';

		$driver->write(
			$file,
			array(
				'id'    => 'ts-test',
				'type'  => 'test',
				'title' => 'Timestamp Test',
			)
		);

		$read = $driver->read( $file );
		$this->assertNotEmpty( $read['created_at'] );
		$this->assertNotEmpty( $read['updated_at'] );
	}

	public function test_driver_write_preserves_existing_created_at(): void {
		$driver                = new PaperJsonDriver();
		$file                  = $this->paper_root . 'preserve-ts.json';
		$record                = $this->make_record( 'preserve-test' );
		$record['created_at']  = '2020-01-01T00:00:00+00:00';

		$driver->write( $file, $record );

		$read = $driver->read( $file );
		$this->assertSame( '2020-01-01T00:00:00+00:00', $read['created_at'] );
	}

	public function test_driver_write_rejects_missing_fields(): void {
		$driver = new PaperJsonDriver();
		$result = $driver->write( $this->paper_root . 'bad.json', array( 'title' => 'No ID or type' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'paper_missing_fields', $result->get_error_code() );
	}

	public function test_driver_write_sanitizes_id_and_type(): void {
		$driver = new PaperJsonDriver();
		$file   = $this->paper_root . 'sanitized.json';

		$driver->write(
			$file,
			array(
				'id'    => 'Dior Sauvage!',
				'type'  => 'Perfume Profile',
				'title' => 'Sanitize Me',
			)
		);

		$read = $driver->read( $file );
		$this->assertSame( 'diorsauvage', $read['id'] );
		$this->assertSame( 'perfumeprofile', $read['type'] );
	}

	public function test_driver_delete_removes_file(): void {
		$driver = new PaperJsonDriver();
		$file   = $this->paper_root . 'to-delete.json';

		$driver->write( $file, $this->make_record( 'to-delete' ) );
		$this->assertFileExists( $file );

		$this->assertTrue( $driver->delete( $file ) );
		$this->assertFileDoesNotExist( $file );
	}

	public function test_driver_delete_nonexistent_returns_wp_error(): void {
		$driver = new PaperJsonDriver();
		$result = $driver->delete( $this->paper_root . 'ghost.json' );
		$this->assertWPError( $result );
		$this->assertSame( 'paper_file_not_found', $result->get_error_code() );
	}

	public function test_driver_required_fields(): void {
		$driver = new PaperJsonDriver();
		$fields = $driver->get_required_fields();
		$this->assertContains( 'id', $fields );
		$this->assertContains( 'type', $fields );
		$this->assertContains( 'title', $fields );
	}

	public function test_driver_filesystem_seam_resolves_per_install_mode(): void {
		$driver = new PaperJsonDriver();

		$ref = new \ReflectionProperty( PaperJsonDriver::class, 'filesystem' );
		$ref->setAccessible( true );
		$filesystem = $ref->getValue( $driver );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the base Filesystem Service owns atomic writes.
			$this->assertInstanceOf( \WP_MCP_AI\Filesystem\WP_MCP_AI_Filesystem_Service::class, $filesystem );
		} else {
			// Standalone: native-PHP fallback (the base's own fallback path).
			$this->assertNull( $filesystem );
		}
	}

	// ─── Index ────────────────────────────────────────────────────

	public function test_index_empty_when_missing_file(): void {
		list( $index ) = $this->make_index();

		$this->assertSame( 0, $index->get_count() );
		$this->assertSame( array(), $index->get_all_record_ids() );
		$this->assertSame( array(), $index->get_all_tags() );
	}

	public function test_index_record_adds_record_to_all_dimensions(): void {
		list( $index ) = $this->make_index();

		$index->index_record(
			array(
				'id'         => 'dior',
				'title'      => 'Dior Sauvage',
				'tags'       => array( 'perfume', 'dior' ),
				'status'     => 'published',
				'type'       => 'product',
				'author_id'  => 7,
				'created_at' => '2026-05-15T10:00:00+00:00',
			)
		);

		$this->assertSame( 1, $index->get_count() );
		$this->assertSame( array( 'dior' => 'Dior Sauvage' ), $index->get_all_record_ids() );
		$this->assertSame( array( 'dior' ), $index->find_by_tag( 'perfume' ) );
		$this->assertSame( array( 'dior' ), $index->find_by_status( 'published' ) );
		$this->assertSame( array( 'dior' ), $index->find_by_type( 'product' ) );
		$this->assertSame( array( 'dior' ), $index->find_by_author( 7 ) );
		$this->assertSame( array( 'dior' ), $index->find_by_date_bucket( '2026-05' ) );
		$this->assertSame(
			array(
				'perfume' => 1,
				'dior'    => 1,
			),
			$index->get_all_tags()
		);
	}

	public function test_index_record_without_id_returns_false(): void {
		list( $index ) = $this->make_index();
		$this->assertFalse( $index->index_record( array( 'title' => 'No ID' ) ) );
	}

	public function test_index_record_skips_empty_tags(): void {
		list( $index ) = $this->make_index();

		$index->index_record(
			array(
				'id'    => 'a',
				'title' => 'A',
				'tags'  => array( '', 'real' ),
			)
		);

		$this->assertSame( array(), $index->find_by_tag( '' ) );
		$this->assertSame( array( 'a' ), $index->find_by_tag( 'real' ) );
	}

	public function test_index_remove_record_clears_all_dimensions(): void {
		list( $index ) = $this->make_index();

		$index->index_record(
			array(
				'id'         => 'gone',
				'title'      => 'Gone',
				'tags'       => array( 'x' ),
				'status'     => 'draft',
				'type'       => 'note',
				'author_id'  => 3,
				'created_at' => '2026-05-01T00:00:00+00:00',
			)
		);
		$index->remove_record( 'gone' );

		$this->assertSame( 0, $index->get_count() );
		$this->assertSame( array(), $index->get_all_record_ids() );
		$this->assertSame( array(), $index->find_by_tag( 'x' ) );
		$this->assertSame( array(), $index->find_by_status( 'draft' ) );
		$this->assertSame( array(), $index->find_by_type( 'note' ) );
		$this->assertSame( array(), $index->find_by_author( 3 ) );
		$this->assertSame( array(), $index->find_by_date_bucket( '2026-05' ) );
	}

	public function test_index_remove_record_empty_id_returns_false(): void {
		list( $index ) = $this->make_index();
		$this->assertFalse( $index->remove_record( '' ) );
	}

	public function test_index_date_bucket_falls_back_to_updated_at(): void {
		list( $index ) = $this->make_index();

		$index->index_record(
			array(
				'id'         => 'b',
				'title'      => 'B',
				'updated_at' => '2026-04-20T00:00:00+00:00',
			)
		);

		$this->assertSame( array( 'b' ), $index->find_by_date_bucket( '2026-04' ) );
	}

	public function test_index_rebuild_scans_collection_and_skips_index_files(): void {
		list( $index, $collection_dir ) = $this->make_index();
		$driver = new PaperJsonDriver();

		$driver->write( \trailingslashit( $collection_dir ) . 'one.json', $this->make_record( 'one', 'One' ) );
		$driver->write( \trailingslashit( $collection_dir ) . 'two.json', $this->make_record( 'two', 'Two' ) );
		// A stray index-shaped file must be skipped.
		\file_put_contents( \trailingslashit( $collection_dir ) . 'skip.idx.json', '{"_count":0}' );

		$this->assertTrue( $index->rebuild( $driver, '.json' ) );
		$this->assertSame( 2, $index->get_count() );
		$this->assertArrayHasKey( 'one', $index->get_all_record_ids() );
		$this->assertArrayHasKey( 'two', $index->get_all_record_ids() );
	}

	public function test_index_rebuild_respects_max_tags_cap_but_single_record_path_does_not(): void {
		\add_filter( 'wp_mcp_ai_paper_index_max_tags', '__return_zero' );

		list( $index, $collection_dir ) = $this->make_index();
		$driver = new PaperJsonDriver();

		// Single-record path: uncapped (preserved base quirk).
		$index->index_record(
			array(
				'id'    => 'a',
				'title' => 'A',
				'tags'  => array( 't' ),
			)
		);
		$index->index_record(
			array(
				'id'    => 'b',
				'title' => 'B',
				'tags'  => array( 't' ),
			)
		);
		$this->assertSame( array( 'a', 'b' ), $index->find_by_tag( 't' ) );

		// Rebuild path: capped at max_tags (0 → no tag entries).
		$driver->write(
			\trailingslashit( $collection_dir ) . 'c.json',
			array(
				'id'    => 'c',
				'type'  => 'test',
				'title' => 'C',
				'tags'  => array( 't2' ),
			)
		);
		$index->rebuild( $driver, '.json' );
		$this->assertSame( array(), $index->find_by_tag( 't2' ) );

		\remove_filter( 'wp_mcp_ai_paper_index_max_tags', '__return_zero' );
	}

	public function test_index_ensure_exists_builds_missing_index(): void {
		list( $index, $collection_dir ) = $this->make_index();
		$driver = new PaperJsonDriver();

		$driver->write( \trailingslashit( $collection_dir ) . 'seed.json', $this->make_record( 'seed', 'Seed' ) );

		$this->assertTrue( $index->ensure_exists( $driver, '.json' ) );
		$this->assertSame( 1, $index->get_count() );
		// Second call short-circuits on the existing file.
		$this->assertTrue( $index->ensure_exists( $driver, '.json' ) );
	}

	public function test_index_drop_removes_file(): void {
		list( $index ) = $this->make_index();

		$index->index_record(
			array(
				'id'    => 'x',
				'title' => 'X',
			)
		);
		$indexes_dir = \trailingslashit( $this->paper_root ) . '_indexes';
		$index_file  = \trailingslashit( $indexes_dir ) . 'knowledge.idx.json';
		$this->assertFileExists( $index_file );

		$this->assertTrue( $index->drop() );
		$this->assertFileDoesNotExist( $index_file );
	}

	public function test_index_save_fires_rebuilt_action_with_stats(): void {
		$payloads = array();
		\add_action(
			'wp_mcp_ai_paper_index_rebuilt',
			function ( $collection, $stats ) use ( &$payloads ): void {
				$payloads[] = array( $collection, $stats );
			},
			10,
			2
		);

		list( $index ) = $this->make_index();
		$index->index_record(
			array(
				'id'    => 'r',
				'title' => 'R',
				'tags'  => array( 't1', 't2' ),
			)
		);

		$this->assertCount( 1, $payloads );
		$this->assertSame( 'knowledge', $payloads[0][0] );
		$this->assertSame( 1, $payloads[0][1]['record_count'] );
		$this->assertSame( 2, $payloads[0][1]['tag_count'] );
	}

	// ─── Manager ──────────────────────────────────────────────────

	public function test_manager_is_singleton(): void {
		$this->assertSame( PaperStoreManager::get_instance(), PaperStoreManager::get_instance() );
	}

	public function test_manager_root_honors_filter_and_writes_security_files(): void {
		$this->assertSame( \trailingslashit( $this->paper_root ), $this->manager->get_root_path() );

		$this->assertFileExists( \trailingslashit( $this->paper_root ) . '.htaccess' );
		$this->assertFileExists( \trailingslashit( $this->paper_root ) . 'index.php' );
		$this->assertSame( "Deny from all\n", \file_get_contents( \trailingslashit( $this->paper_root ) . '.htaccess' ) );
		$this->assertSame( "<?php\n// Silence is golden.\n", \file_get_contents( \trailingslashit( $this->paper_root ) . 'index.php' ) );
	}

	public function test_manager_initialized_action_fires_once(): void {
		$calls = array();
		\add_action(
			'wp_mcp_ai_paper_store_initialized',
			function ( $root ) use ( &$calls ): void {
				$calls[] = $root;
			}
		);

		$this->manager->get_root_path();
		$this->manager->get_root_path();

		$this->assertCount( 1, $calls );
		$this->assertSame( \trailingslashit( $this->paper_root ), $calls[0] );
	}

	public function test_manager_indexes_path(): void {
		$this->assertSame( \trailingslashit( $this->paper_root ) . '_indexes', $this->manager->get_indexes_path() );
	}

	public function test_manager_repositories_are_cached_per_collection(): void {
		$repo_a = $this->manager->get_repository( 'col-a' );
		$repo_b = $this->manager->get_repository( 'col-b' );

		$this->assertSame( $repo_a, $this->manager->get_repository( 'col-a' ) );
		$this->assertNotSame( $repo_a, $repo_b );
		$this->assertSame( 'col-a', $repo_a->get_collection_name() );
		$this->assertSame( 'col-b', $repo_b->get_collection_name() );
	}

	public function test_manager_repository_sanitizes_collection_name(): void {
		$repo = $this->manager->get_repository( 'My-Collection!' );
		$this->assertSame( 'my-collection', $repo->get_collection_name() );
	}

	public function test_manager_get_driver_defaults_to_json_and_uses_filter(): void {
		$driver = $this->manager->get_driver( '.json' );
		$this->assertInstanceOf( PaperJsonDriver::class, $driver );

		$custom = new PaperJsonDriver();
		\add_filter(
			'wp_mcp_ai_paper_driver',
			function ( $default, $extension ) use ( $custom ) {
				$this->assertInstanceOf( PaperJsonDriver::class, $default );
				$this->assertSame( '.md', $extension );
				return $custom;
			},
			10,
			2
		);

		$this->assertSame( $custom, $this->manager->get_driver( '.md' ) );
	}

	public function test_manager_register_driver(): void {
		$custom = new PaperJsonDriver();
		$this->manager->register_driver( '.custom', $custom );
		$this->assertSame( $custom, $this->manager->get_driver( '.custom' ) );
	}

	public function test_manager_validate_path_accepts_inside_root(): void {
		$inside = \trailingslashit( $this->paper_root ) . 'knowledge';
		\wp_mkdir_p( $inside );

		$this->assertTrue( $this->manager->validate_path( $inside ) );
	}

	public function test_manager_validate_path_rejects_traversal(): void {
		$outside = \sys_get_temp_dir() . '/';
		$result  = $this->manager->validate_path( $outside );
		$this->assertWPError( $result );
		$this->assertSame( 'paper_path_traversal', $result->get_error_code() );
	}

	public function test_manager_validate_path_rejects_invalid_path(): void {
		$result = $this->manager->validate_path( \trailingslashit( $this->paper_root ) . 'does-not-exist' );
		$this->assertWPError( $result );
		$this->assertSame( 'paper_path_error', $result->get_error_code() );
	}

	public function test_manager_list_collections_sorted_excluding_indexes_and_dotfiles(): void {
		\wp_mkdir_p( \trailingslashit( $this->paper_root ) . 'col-b' );
		\wp_mkdir_p( \trailingslashit( $this->paper_root ) . 'col-a' );
		\wp_mkdir_p( \trailingslashit( $this->paper_root ) . '_indexes' );
		\wp_mkdir_p( \trailingslashit( $this->paper_root ) . '.hidden' );

		$this->assertSame( array( 'col-a', 'col-b' ), $this->manager->list_collections() );
	}

	public function test_manager_reset_clears_state(): void {
		$this->manager->get_repository( 'pre-reset' );
		$this->manager->reset();

		$calls = array();
		\add_action(
			'wp_mcp_ai_paper_store_initialized',
			function () use ( &$calls ): void {
				$calls[] = true;
			}
		);

		// After reset the manager re-initializes lazily (action fires again).
		$this->manager->get_root_path();
		$this->assertCount( 1, $calls );
	}
}
