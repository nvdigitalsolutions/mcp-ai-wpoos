<?php
declare(strict_types=1);

namespace NvoosGraphify\Tests\Integration;

use NvoosGraphify\Graph\Db;
use NvoosGraphify\Remote\Enricher;
use NvoosGraphify\Schema;
use WP_UnitTestCase;

/**
 * Integration tests for {@see Enricher::syncSource()} — specifically
 * the async parameter added in the June 26 release-readiness review.
 *
 * @since 1.0.0
 */
class EnricherTest extends WP_UnitTestCase {

	/**
	 * Set up — seed a remote source row so syncSource() passes the
	 * existence check and reaches the async branch.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! did_action( 'plugins_loaded' ) ) {
			do_action( 'plugins_loaded' );
		}

		Db::saveRemoteSource(
			array(
				'slug'   => 'enricher_test_source',
				'driver' => 'generic_rest',
				'label'  => 'Enricher Test Source',
				'config' => array( 'endpoint' => 'https://example.com/api' ),
			)
		);
	}

	/**
	 * Tear down — clean up the test source and any scheduled events.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Db::deleteRemoteSource( 'enricher_test_source' );
		\wp_clear_scheduled_hook( Schema::CRON_ENRICH );
		parent::tearDown();
	}

	/**
	 * Calling syncSource() with async=true schedules CRON_ENRICH and
	 * returns immediately with a scheduled-status array — it does NOT
	 * attempt to reach a driver or perform any sync work.
	 *
	 * @return void
	 */
	public function testAsyncSchedulesCronAndReturnsImmediately(): void {
		\wp_clear_scheduled_hook( Schema::CRON_ENRICH );

		$result = Enricher::syncSource( 'enricher_test_source', true );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'async', $result );
		$this->assertTrue( $result['async'] );
		$this->assertSame( 'scheduled', $result['status'] );
		$this->assertSame( 'enricher_test_source', $result['slug'] );
		$this->assertNotFalse( \wp_next_scheduled( Schema::CRON_ENRICH ) );
	}
}
