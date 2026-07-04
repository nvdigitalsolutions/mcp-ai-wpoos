<?php
declare(strict_types=1);

namespace NvoosGraphify\Tests\Unit\Graph;

use NvoosGraphify\Graph\Db;
use NvoosGraphify\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Db class.
 *
 * These tests mock $wpdb and verify query construction.
 *
 * @since 1.0.0
 */
class DbTest extends TestCase {

	/** @var \wpdb&\PHPUnit\Framework\MockObject\MockObject */
	private $mockWpdb;

	/** @var \wpdb|null Saved original $wpdb for restoration. */
	private $originalWpdb;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Save the original $wpdb so we can restore it in tearDown.
		$this->originalWpdb = $GLOBALS['wpdb'] ?? null;

		// Mock global $wpdb.
		$this->mockWpdb         = $this->createMock( \wpdb::class );
		$this->mockWpdb->prefix = 'wp_';
		$GLOBALS['wpdb']        = $this->mockWpdb;
	}

	/**
	 * Tear down test fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( null !== $this->originalWpdb ) {
			$GLOBALS['wpdb'] = $this->originalWpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		parent::tearDown();
	}

	/** @test */
	public function nodesTableReturnsPrefixedName(): void {
		$this->assertSame(
			'wp_' . Schema::TABLE_NODES,
			Db::nodesTable()
		);
	}

	/** @test */
	public function edgesTableReturnsPrefixedName(): void {
		$this->assertSame(
			'wp_' . Schema::TABLE_EDGES,
			Db::edgesTable()
		);
	}

	/** @test */
	public function metaTableReturnsPrefixedName(): void {
		$this->assertSame(
			'wp_' . Schema::TABLE_META,
			Db::metaTable()
		);
	}

	/** @test */
	public function remoteSourcesTableReturnsPrefixedName(): void {
		$this->assertSame(
			'wp_' . Schema::TABLE_REMOTE_SOURCES,
			Db::remoteSourcesTable()
		);
	}

	/** @test */
	public function embeddingsTableReturnsPrefixedName(): void {
		$this->assertSame(
			'wp_' . Schema::TABLE_EMBEDDINGS,
			Db::embeddingsTable()
		);
	}

	/** @test */
	public function countNodesReturnsZeroWhenTableIsEmpty(): void {
		$this->mockWpdb->expects( $this->once() )
			->method( 'get_var' )
			->with( $this->stringContains( 'SELECT COUNT(*)' ) )
			->willReturn( '0' );

		$this->assertSame( 0, Db::countNodes() );
	}

	/** @test */
	public function countEdgesReturnsZeroWhenTableIsEmpty(): void {
		$this->mockWpdb->expects( $this->once() )
			->method( 'get_var' )
			->with( $this->stringContains( 'SELECT COUNT(*)' ) )
			->willReturn( '0' );

		$this->assertSame( 0, Db::countEdges() );
	}

	/** @test */
	public function upsertNodeInsertsWhenNotFound(): void {
		$this->mockWpdb->expects( $this->once() )
			->method( 'get_var' )
			->willReturn( null );

		$this->mockWpdb->expects( $this->once() )
			->method( 'insert' )
			->willReturn( true );

		$this->mockWpdb->insert_id = 42;

		$result = Db::upsertNode(
			array(
				'node_id' => 'post_1',
				'label'   => 'Test Post',
				'type'    => 'post',
				'post_id' => 1,
				'url'     => 'https://example.com/test',
			)
		);

		$this->assertSame( 42, $result );
	}

	/** @test */
	public function upsertNodeUpdatesWhenFound(): void {
		$this->mockWpdb->expects( $this->once() )
			->method( 'get_var' )
			->willReturn( '5' );

		$this->mockWpdb->expects( $this->once() )
			->method( 'update' );

		$result = Db::upsertNode(
			array(
				'node_id' => 'post_1',
				'label'   => 'Updated Post',
				'type'    => 'post',
				'post_id' => 1,
			)
		);

		$this->assertSame( 5, $result );
	}

	/** @test */
	public function getNodeReturnsNullWhenNotFound(): void {
		$this->mockWpdb->expects( $this->once() )
			->method( 'get_row' )
			->willReturn( null );

		$this->assertNull( Db::getNode( 'nonexistent' ) );
	}

	/** @test */
	public function upsertEdgeInsertsWhenNotFound(): void {
		$this->mockWpdb->expects( $this->once() )
			->method( 'get_row' )
			->willReturn( null );

		$this->mockWpdb->expects( $this->once() )
			->method( 'insert' )
			->willReturn( true );

		$this->mockWpdb->insert_id = 99;

		$result = Db::upsertEdge(
			array(
				'source_node_id' => 'post_1',
				'target_node_id' => 'term_5_category',
				'relation'       => 'CATEGORIZED_BY',
			)
		);

		$this->assertSame( 99, $result );
	}

	/** @test */
	public function upsertEdgeKeepsHighestConfidenceOnDuplicate(): void {
		$existing             = new \stdClass();
		$existing->id         = 10;
		$existing->confidence = 0.8;

		$this->mockWpdb->expects( $this->once() )
			->method( 'get_row' )
			->willReturn( $existing );

		// Should update with max(0.8, 0.6) = 0.8.
		$this->mockWpdb->expects( $this->once() )
			->method( 'update' )
			->with(
				$this->anything(),
				$this->callback(
					function ( array $data ) {
						return $data['confidence'] === 0.8;
					}
				),
				$this->anything(),
				$this->anything(),
				$this->anything()
			);

		$result = Db::upsertEdge(
			array(
				'source_node_id' => 'post_1',
				'target_node_id' => 'term_5_category',
				'relation'       => 'CATEGORIZED_BY',
				'confidence'     => 0.6,
			)
		);

		$this->assertSame( 10, $result );
	}

	/** @test */
	public function getAllNodesReturnsArrayWhenEmpty(): void {
		$this->mockWpdb->expects( $this->once() )
			->method( 'get_results' )
			->willReturn( array() );

		$this->assertSame( array(), Db::getAllNodes() );
	}

	/** @test */
	public function getEdgesForNodeReturnsArrayWhenEmpty(): void {
		$this->mockWpdb->expects( $this->once() )
			->method( 'get_results' )
			->willReturn( array() );

		$this->assertSame( array(), Db::getEdgesForNode( 'post_1' ) );
	}
}
