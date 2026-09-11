<?php
declare(strict_types=1);

namespace NvoosContentGraph\Tests\Unit\Memory;

use NvoosContentGraph\Graph\Db;
use NvoosContentGraph\Memory\Bridge;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the schema-absent degradation of {@see Bridge}.
 *
 * Mirrors the `Graph\DbTest` mocked-`$wpdb` pattern: the schema probe is
 * stubbed to report "tables missing", and the bridge must silently no-op
 * instead of throwing or issuing writes (the bridge is advisory — memory
 * writes must never break on a missing graph schema).
 *
 * @since 1.0.5
 */
class BridgeTest extends TestCase {

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

		$this->originalWpdb = $GLOBALS['wpdb'] ?? null;

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
	public function projectionNoOpsWhenSchemaIsMissing(): void {
		$this->mockWpdb->expects( $this->once() )
			->method( 'get_var' )
			->willReturn( null ); // SHOW TABLES LIKE → no table.

		$this->mockWpdb->expects( $this->never() )->method( 'insert' );
		$this->mockWpdb->expects( $this->never() )->method( 'update' );

		Bridge::onMemoryStored(
			array(
				'context_id' => 'ctx_no_schema',
				'agent_id'   => '1',
				'title'      => 'Should not be projected',
			)
		);
	}

	/** @test */
	public function retrievalReturnsEmptyWhenSchemaIsMissing(): void {
		$this->mockWpdb->expects( $this->once() )
			->method( 'get_var' )
			->willReturn( null );

		$this->assertSame( array(), Bridge::retrieveGraph( array( 'agent_id' => '1' ) ) );
	}

	/** @test */
	public function wakeUpRetrieverDegradesToEmptyListWhenSchemaIsMissing(): void {
		$this->mockWpdb->expects( $this->once() )
			->method( 'get_var' )
			->willReturn( null );

		$this->assertSame(
			array(),
			Bridge::onWakeUpGraphRetriever( null, array( 'agent_id' => '1' ) )
		);
	}

	/** @test */
	public function tablesInstalledReportsTrueWhenProbeMatches(): void {
		$this->mockWpdb->expects( $this->once() )
			->method( 'get_var' )
			->willReturn( 'wp_' . \NvoosContentGraph\Schema::TABLE_NODES );

		$this->assertTrue( Db::tablesInstalled() );
	}
}
