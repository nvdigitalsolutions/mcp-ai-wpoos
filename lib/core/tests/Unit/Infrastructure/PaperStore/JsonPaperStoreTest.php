<?php
/**
 * Tests for the JSON Paper Store.
 *
 * Pins the three things a flat-file store can get wrong: path containment,
 * atomic replacement, and the record stamps that make two runtimes agree.
 *
 * @package Nvoos\Core\Tests
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Infrastructure\PaperStore;

use Nvoos\Core\Domain\Contract\PaperStoreInterface;
use Nvoos\Core\Infrastructure\PaperStore\JsonPaperStore;
use PHPUnit\Framework\TestCase;

final class JsonPaperStoreTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		parent::setUp();

		$this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'paper-test-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->root, 0775, true );
	}

	protected function tearDown(): void {
		$this->rmtree( $this->root );
		parent::tearDown();
	}

	private function store(): JsonPaperStore {
		return new JsonPaperStore( $this->root );
	}

	public function test_save_stamps_the_record(): void {
		$record = $this->store()->save(
			'knowledge',
			array(
				'id'          => 'dior-sauvage',
				'title'       => 'Dior Sauvage',
				'description' => 'Fragrance note',
				'tags'        => array( 'fragrance' ),
			)
		);

		$this->assertSame( 'dior-sauvage', $record['id'] );
		$this->assertSame( 'knowledge', $record['collection'] );
		$this->assertSame( 'knowledge', $record['type'] );
		$this->assertSame( 'draft', $record['status'] );
		$this->assertSame( PaperStoreInterface::SCHEMA_VERSION, $record['schemaVersion'] );
		$this->assertNotEmpty( $record['created_at'] );
		$this->assertNotEmpty( $record['updated_at'] );
	}

	public function test_save_derives_an_id_from_the_title(): void {
		$record = $this->store()->save( 'prompts', array( 'title' => 'Write a Brand Voice!' ) );

		$this->assertSame( 'write-a-brand-voice', $record['id'] );
		$this->assertTrue( $this->store()->has( 'prompts', 'write-a-brand-voice' ) );
	}

	public function test_save_preserves_created_at_on_replace(): void {
		$store = $this->store();
		$first = $store->save( 'knowledge', array( 'id' => 'a', 'title' => 'A' ) );

		$again = $store->save( 'knowledge', array( 'id' => 'a', 'title' => 'A renamed' ) );

		$this->assertSame( $first['created_at'], $again['created_at'] );
		$this->assertSame( 'A renamed', $again['title'] );
		$this->assertSame( 'a', $again['id'] );
	}

	public function test_the_store_owns_created_at(): void {
		$store = $this->store();
		$store->save( 'knowledge', array( 'id' => 'a', 'title' => 'A' ) );

		$again = $store->save(
			'knowledge',
			array(
				'id'         => 'a',
				'title'      => 'A',
				'created_at' => '2020-01-01T00:00:00+00:00',
			)
		);

		$this->assertNotSame( '2020-01-01T00:00:00+00:00', $again['created_at'] );
	}

	public function test_a_missing_title_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->store()->save( 'knowledge', array( 'id' => 'x' ) );
	}

	public function test_path_traversal_is_rejected_rather_than_rewritten(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->store()->save( '../escape', array( 'title' => 'Nope' ) );
	}

	public function test_an_id_with_a_separator_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->store()->save( 'knowledge', array( 'id' => 'a/b', 'title' => 'Nope' ) );
	}

	public function test_find_returns_null_for_an_unknown_record(): void {
		$this->assertNull( $this->store()->find( 'knowledge', 'missing' ) );
		$this->assertFalse( $this->store()->has( 'knowledge', 'missing' ) );
	}

	public function test_a_written_file_is_human_readable_json(): void {
		$this->store()->save( 'knowledge', array( 'id' => 'a', 'title' => 'A' ) );

		$raw = file_get_contents( $this->root . '/knowledge/a.json' );
		$decoded = json_decode( (string) $raw, true );

		$this->assertIsArray( $decoded );
		$this->assertStringContainsString( "\n", (string) $raw, 'Records should be pretty-printed.' );
		$this->assertSame( 1, $decoded['schemaVersion'] );
	}

	public function test_collections_only_lists_dirs_with_records(): void {
		$store = $this->store();
		$store->save( 'knowledge', array( 'id' => 'a', 'title' => 'A' ) );
		$store->save( 'prompts', array( 'id' => 'b', 'title' => 'B' ) );

		mkdir( $this->root . '/empty-collection', 0775, true );

		$this->assertSame( array( 'knowledge', 'prompts' ), $store->collections() );
	}

	public function test_delete_removes_the_file_and_reports_absence(): void {
		$store = $this->store();
		$store->save( 'knowledge', array( 'id' => 'a', 'title' => 'A' ) );

		$this->assertTrue( $store->delete( 'knowledge', 'a' ) );
		$this->assertFalse( $store->delete( 'knowledge', 'a' ) );
		$this->assertFalse( $store->has( 'knowledge', 'a' ) );
	}

	public function test_query_filters_by_tag_status_and_search(): void {
		$store = $this->store();

		$store->save( 'knowledge', array( 'id' => 'a', 'title' => 'Alpha', 'description' => 'first', 'tags' => array( 'x' ), 'status' => 'published' ) );
		$store->save( 'knowledge', array( 'id' => 'b', 'title' => 'Beta', 'description' => 'second', 'tags' => array( 'y' ), 'status' => 'draft' ) );
		$store->save( 'knowledge', array( 'id' => 'c', 'title' => 'Gamma', 'description' => 'second', 'tags' => array( 'x' ), 'status' => 'draft' ) );

		$this->assertSame( 2, $store->query( 'knowledge', array( 'tags' => 'x' ) )['total'] );
		$this->assertSame( 1, $store->query( 'knowledge', array( 'status' => 'published' ) )['total'] );
		$this->assertSame( 2, $store->query( 'knowledge', array( 'search' => 'SECOND' ) )['total'] );
		$this->assertSame( 1, $store->query( 'knowledge', array( 'tags' => 'x', 'status' => 'draft' ) )['total'] );
	}

	public function test_query_pages_and_reports_the_full_total(): void {
		$store = $this->store();

		foreach ( range( 1, 5 ) as $i ) {
			$store->save( 'knowledge', array( 'id' => 'r' . $i, 'title' => 'R' . $i ) );
		}

		$page = $store->query( 'knowledge', array( 'limit' => 2, 'offset' => 2 ) );

		$this->assertCount( 2, $page['records'] );
		$this->assertSame( 5, $page['total'] );
	}

	public function test_query_orders_by_updated_at_descending_by_default(): void {
		$store = $this->store();

		$store->save( 'knowledge', array( 'id' => 'old', 'title' => 'Old' ) );
		$record = $store->find( 'knowledge', 'old' );
		$record['updated_at'] = '2020-01-01T00:00:00+00:00';
		$store->save( 'knowledge', $record );

		$store->save( 'knowledge', array( 'id' => 'new', 'title' => 'New' ) );

		$ids = array_column( $store->query( 'knowledge' )['records'], 'id' );

		$this->assertSame( 'new', $ids[0] );
	}

	public function test_query_can_order_by_title_ascending(): void {
		$store = $this->store();

		$store->save( 'knowledge', array( 'id' => 'b', 'title' => 'Beta' ) );
		$store->save( 'knowledge', array( 'id' => 'a', 'title' => 'Alpha' ) );

		$titles = array_column(
			$store->query( 'knowledge', array( 'order_by' => 'title', 'direction' => 'asc' ) )['records'],
			'title'
		);

		$this->assertSame( array( 'Alpha', 'Beta' ), $titles );
	}

	public function test_query_rejects_an_unknown_order_field(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->store()->query( 'knowledge', array( 'order_by' => 'rand' ) );
	}

	public function test_query_clamps_the_page_size(): void {
		$store = $this->store();

		foreach ( range( 1, 3 ) as $i ) {
			$store->save( 'knowledge', array( 'id' => 'r' . $i, 'title' => 'R' . $i ) );
		}

		$this->assertCount( 3, $store->query( 'knowledge', array( 'limit' => 9999 ) )['records'] );
		$this->assertCount( 3, $store->query( 'knowledge', array( 'limit' => 0 ) )['records'] );
	}

	public function test_an_unknown_collection_queries_empty(): void {
		$page = $this->store()->query( 'nope' );

		$this->assertSame( array(), $page['records'] );
		$this->assertSame( 0, $page['total'] );
	}

	/**
	 * Recursively delete a directory.
	 */
	private function rmtree( string $path ): void {
		if ( ! is_dir( $path ) ) {
			if ( is_file( $path ) ) {
				@unlink( $path );
			}

			return;
		}

		foreach ( (array) scandir( $path ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$this->rmtree( $path . DIRECTORY_SEPARATOR . (string) $entry );
		}

		@rmdir( $path );
	}
}
