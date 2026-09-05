<?php
/**
 * Cron manager port tests (Wave E2).
 *
 * Characterization suite for the ported `CronManager`: byte-identical
 * option key, `init()` prune-hook wiring, argument normalisation
 * (positional vs wrapped associative), record/get/remove lifecycle with
 * idempotent re-record semantics, WP-Cron unschedule behavior for single
 * and recurring events, pruning rules (scheduled jobs kept, retention
 * window, zero-retention immediate removal, zero/missing first
 * timestamp), stable job-ID generation (protected seam + public static
 * wrapper), legacy-entry normalisation on load, and corrupted-option
 * reset.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Queues\CronManager;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The seam fixture shares this file with its test case.

/**
 * Seam subclass exposing protected members for contract testing.
 */
class CronManagerSeam extends CronManager {

	/**
	 * Expose generate_job_id().
	 *
	 * @param string $hook Cron hook name.
	 * @param array  $args Cron arguments.
	 * @return string
	 */
	public static function seam_generate_job_id( $hook, $args ) {
		return self::generate_job_id( $hook, $args );
	}

	/**
	 * Expose load_jobs().
	 *
	 * @return array
	 */
	public static function seam_load_jobs() {
		return self::load_jobs();
	}

	/**
	 * Expose save_jobs().
	 *
	 * @param array $jobs Jobs to store.
	 * @return void
	 */
	public static function seam_save_jobs( $jobs ): void {
		self::save_jobs( $jobs );
	}
}

/**
 * @group queues
 */
class Test_Cron_Manager extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		delete_option( CronManager::OPTION_NAME );
		delete_option( 'wp_mcp_ai_settings' );
	}

	public function tearDown(): void {
		remove_action( 'init', array( CronManager::class, 'maybe_prune_jobs' ) );

		delete_option( CronManager::OPTION_NAME );
		delete_option( 'wp_mcp_ai_settings' );

		parent::tearDown();
	}

	// ─── Constants + wiring ─────────────────────────────────────────

	public function test_constants_are_byte_identical(): void {
		$this->assertSame( 'wp_mcp_ai_cron_jobs', CronManager::OPTION_NAME );
	}

	public function test_init_registers_prune_hook(): void {
		CronManager::init();

		$this->assertSame(
			10,
			has_action( 'init', array( CronManager::class, 'maybe_prune_jobs' ) )
		);
	}

	// ─── Argument normalisation ─────────────────────────────────────

	public function test_normalise_args_non_array_and_empty(): void {
		$this->assertSame( array(), CronManager::normalise_args( 'not-an-array' ) );
		$this->assertSame( array(), CronManager::normalise_args( null ) );
		$this->assertSame( array(), CronManager::normalise_args( array() ) );
	}

	public function test_normalise_args_numeric_indexed(): void {
		$this->assertSame(
			array( 'a', 'b', 'c' ),
			CronManager::normalise_args( array( 'a', 'b', 'c' ) )
		);
	}

	public function test_normalise_args_associative_is_wrapped(): void {
		$args = CronManager::normalise_args( array( 'key' => 'value' ) );

		$this->assertSame( array( array( 'key' => 'value' ) ), $args );
	}

	public function test_normalise_args_sparse_numeric_is_wrapped(): void {
		// Sparse keys ([2 => 'a']) are not zero-based positional — wrapped.
		$this->assertSame(
			array( array( 2 => 'a' ) ),
			CronManager::normalise_args( array( 2 => 'a' ) )
		);
	}

	// ─── Record / get lifecycle ─────────────────────────────────────

	public function test_record_and_get_job(): void {
		$job_id = CronManager::record_job( 'my_cron_hook', array( 'a', 'b' ), 'daily', 123456, 7 );

		$job = CronManager::get_job( $job_id );
		$this->assertNotNull( $job );
		$this->assertSame( $job_id, $job['job_id'] );
		$this->assertSame( 'my_cron_hook', $job['hook'] );
		$this->assertSame( array( 'a', 'b' ), $job['args'] );
		$this->assertSame( 'daily', $job['schedule'] );
		$this->assertSame( 123456, $job['first_timestamp'] );
		$this->assertSame( 7, $job['created_by'] );
		$this->assertGreaterThan( 0, $job['created_at'] );

		$jobs = CronManager::get_jobs();
		$this->assertSame( array( $job_id ), array_keys( $jobs ) );
	}

	public function test_record_job_defaults_schedule_to_single(): void {
		$job_id = CronManager::record_job( 'one_off_hook', array(), '', time(), 1 );

		$this->assertSame( 'single', CronManager::get_job( $job_id )['schedule'] );
	}

	public function test_record_job_idempotent_re_record(): void {
		$first_id = CronManager::record_job( 'stable_hook', array( 'x' ), 'daily', 1000, 1 );
		$first    = CronManager::get_job( $first_id );

		// Re-record the same hook+args with a different timestamp/user: the
		// identity, created_at, and first_timestamp are preserved.
		$second_id = CronManager::record_job( 'stable_hook', array( 'x' ), 'daily', 2000, 2 );
		$second    = CronManager::get_job( $second_id );

		$this->assertSame( $first_id, $second_id );
		$this->assertSame( $first['created_at'], $second['created_at'] );
		$this->assertSame( 1000, $second['first_timestamp'] );
		$this->assertSame( 2, $second['created_by'] );
	}

	public function test_get_job_unknown_returns_null(): void {
		$this->assertNull( CronManager::get_job( 'missing-job' ) );
	}

	public function test_generate_job_id_stable_and_static_wrapper(): void {
		$generated = CronManagerSeam::seam_generate_job_id( 'gen_hook', array( 'p', 'q' ) );

		$this->assertSame(
			$generated,
			CronManager::generate_job_id_static( 'gen_hook', array( 'p', 'q' ) )
		);
		$this->assertSame(
			$generated,
			CronManager::generate_job_id_static(
				'gen_hook',
				array(
					0 => 'p',
					1 => 'q',
				)
			)
		);
		$this->assertNotSame(
			$generated,
			CronManager::generate_job_id_static( 'other_hook', array( 'p', 'q' ) )
		);
	}

	// ─── Remove + unschedule ────────────────────────────────────────

	public function test_remove_job_unknown_returns_false(): void {
		$this->assertFalse( CronManager::remove_job( 'missing-job' ) );
	}

	public function test_remove_job_unschedules_single_event(): void {
		$timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_single_event( $timestamp, 'cron_remove_single', array( 'x' ) );

		$job_id = CronManager::record_job( 'cron_remove_single', array( 'x' ), 'single', $timestamp, 1 );

		$this->assertTrue( CronManager::remove_job( $job_id ) );
		$this->assertFalse( wp_next_scheduled( 'cron_remove_single', array( 'x' ) ) );
		$this->assertNull( CronManager::get_job( $job_id ) );
	}

	public function test_remove_job_clears_recurring_event(): void {
		$timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_event( $timestamp, 'daily', 'cron_remove_recurring', array( 'y' ) );

		$job_id = CronManager::record_job( 'cron_remove_recurring', array( 'y' ), 'daily', $timestamp, 1 );

		$this->assertTrue( CronManager::remove_job( $job_id ) );
		$this->assertFalse( wp_get_scheduled_event( 'cron_remove_recurring', array( 'y' ) ) );
	}

	// ─── Pruning ────────────────────────────────────────────────────

	public function test_prune_keeps_scheduled_jobs(): void {
		$timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_single_event( $timestamp, 'cron_prune_keep', array( 'k' ) );

		$job_id = CronManager::record_job( 'cron_prune_keep', array( 'k' ), 'single', $timestamp, 1 );

		CronManager::maybe_prune_jobs();

		$this->assertNotNull( CronManager::get_job( $job_id ) );
	}

	public function test_prune_removes_unscheduled_job_past_retention(): void {
		$old    = time() - 25 * HOUR_IN_SECONDS;
		$job_id = CronManager::record_job( 'cron_prune_old', array( 'o' ), 'single', $old, 1 );

		CronManager::maybe_prune_jobs();

		$this->assertNull( CronManager::get_job( $job_id ) );
	}

	public function test_prune_keeps_unscheduled_job_within_retention(): void {
		$job_id = CronManager::record_job( 'cron_prune_recent', array( 'r' ), 'single', time(), 1 );

		CronManager::maybe_prune_jobs();

		$this->assertNotNull( CronManager::get_job( $job_id ) );
	}

	public function test_prune_zero_retention_removes_immediately(): void {
		update_option( 'wp_mcp_ai_settings', array( 'cron_job_retention_period' => 0 ) );

		$job_id = CronManager::record_job( 'cron_prune_zero', array( 'z' ), 'single', time(), 1 );

		CronManager::maybe_prune_jobs();

		$this->assertNull( CronManager::get_job( $job_id ) );
	}

	public function test_prune_removes_jobs_with_zero_first_timestamp(): void {
		CronManagerSeam::seam_save_jobs(
			array(
				'zero-ts-job' => array(
					'job_id'          => 'zero-ts-job',
					'hook'            => 'cron_zero_ts',
					'args'            => array(),
					'schedule'        => 'single',
					'first_timestamp' => 0,
					'created_at'      => time(),
					'created_by'      => 1,
				),
			)
		);

		CronManager::maybe_prune_jobs();

		$this->assertNull( CronManager::get_job( 'zero-ts-job' ) );
	}

	// ─── Load normalisation + corruption ────────────────────────────

	public function test_load_jobs_normalises_legacy_entries(): void {
		CronManagerSeam::seam_save_jobs(
			array(
				'legacy-1' => array(
					'hook' => 'legacy_hook',
					'args' => array( 'v' ),
				),
				'legacy-2' => 'not-an-array',
			)
		);

		$jobs = CronManagerSeam::seam_load_jobs();

		$this->assertCount( 1, $jobs );

		$job = reset( $jobs );
		$this->assertSame( 'legacy_hook', $job['hook'] );
		$this->assertSame( array( 'v' ), $job['args'] );
		$this->assertSame( 'single', $job['schedule'] );
		$this->assertSame( 0, $job['first_timestamp'] );
		$this->assertSame( 0, $job['created_at'] );
		$this->assertSame( 0, $job['created_by'] );
		// The generated ID is stable and the option was re-saved in normalised form.
		$this->assertSame(
			$job['job_id'],
			CronManager::generate_job_id_static( 'legacy_hook', array( 'v' ) )
		);
		$this->assertSame( array( $job['job_id'] ), array_keys( CronManager::get_jobs() ) );
	}

	public function test_load_jobs_corrupted_option_resets_to_empty(): void {
		update_option( CronManager::OPTION_NAME, 'corrupted-string' );

		$this->assertSame( array(), CronManager::get_jobs() );
	}

	public function test_save_jobs_uses_add_option_for_fresh_install(): void {
		CronManagerSeam::seam_save_jobs(
			array(
				'fresh-job' => array(
					'job_id'          => 'fresh-job',
					'hook'            => 'fresh_hook',
					'args'            => array(),
					'schedule'        => 'single',
					'first_timestamp' => time(),
					'created_at'      => time(),
					'created_by'      => 1,
				),
			)
		);

		$this->assertSame( 'fresh-job', array_keys( CronManager::get_jobs() )[0] );
	}
}
