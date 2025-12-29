<?php
/**
 * Cron manager utilities for NV oOS.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
	/**
	 * Provides helpers to track and manage cron events scheduled via the plugin tools.
	 */
	class WP_MCP_AI_Cron_Manager {
		const OPTION_NAME = 'wp_mcp_ai_cron_jobs';

		/**
		 * Bootstrap the cron manager hooks.
		 */
		public static function init() {
			add_action( 'init', array( __CLASS__, 'maybe_prune_jobs' ) );
		}

		/**
		 * Normalise cron arguments before interacting with WP-Cron.
		 *
		 * WordPress expects positional arguments to use zero-based numeric keys.
		 * Associative arrays should be wrapped so they are treated as a single
		 * argument when scheduling and clearing events.
		 *
		 * @param mixed $args Raw argument list supplied by a caller.
		 * @return array Sanitised argument list suitable for WP-Cron functions.
		 */
		public static function normalise_args( $args ) {
			if ( ! is_array( $args ) ) {
				return array();
			}

			if ( empty( $args ) ) {
				return array();
			}

			$keys               = array_keys( $args );
			$is_numeric_indexed = true;

			foreach ( $keys as $index => $key ) {
				if ( (string) $key !== (string) $index ) {
					$is_numeric_indexed = false;
					break;
				}
			}

			if ( $is_numeric_indexed ) {
				return array_values( $args );
			}

			return array( $args );
		}

		/**
		 * Record a cron event scheduled through the plugin.
		 *
		 * @param string $hook      Cron hook name.
		 * @param array  $args      Arguments passed to the cron callback.
		 * @param string $schedule  Schedule slug (or "single" for one-off events).
		 * @param int    $timestamp Initial timestamp requested for the event.
		 * @param int    $user_id   User who scheduled the event.
		 *
		 * @return string Identifier of the stored job.
		 */
		public static function record_job( $hook, $args, $schedule, $timestamp, $user_id ) {
			$hook      = (string) $hook;
			$args      = self::normalise_args( $args );
			$schedule  = $schedule ? (string) $schedule : 'single';
			$timestamp = (int) $timestamp;
			$user_id   = (int) $user_id;

			$job_id = self::generate_job_id( $hook, $args );

			$jobs = self::load_jobs();

			$created_at      = isset( $jobs[ $job_id ]['created_at'] ) ? (int) $jobs[ $job_id ]['created_at'] : time();
			$first_timestamp = isset( $jobs[ $job_id ]['first_timestamp'] ) && $jobs[ $job_id ]['first_timestamp'] ? (int) $jobs[ $job_id ]['first_timestamp'] : $timestamp;

			$jobs[ $job_id ] = array(
				'job_id'          => $job_id,
				'hook'            => $hook,
				'args'            => $args,
				'schedule'        => $schedule,
				'first_timestamp' => $first_timestamp,
				'created_at'      => $created_at,
				'created_by'      => $user_id,
			);

			self::save_jobs( $jobs );

			return $job_id;
		}

		/**
		 * Retrieve the stored cron jobs, keyed by job ID.
		 *
		 * @return array
		 */
		public static function get_jobs() {
			return self::load_jobs();
		}

		/**
		 * Retrieve a single stored cron job by ID.
		 *
		 * @param string $job_id Cron identifier.
		 *
		 * @return array|null
		 */
		public static function get_job( $job_id ) {
			$jobs   = self::load_jobs();
			$job_id = (string) $job_id;

			return isset( $jobs[ $job_id ] ) ? $jobs[ $job_id ] : null;
		}

		/**
		 * Remove a stored cron job and unschedule it from WP-Cron.
		 *
		 * @param string $job_id Stored job identifier.
		 *
		 * @return bool Whether a job was removed.
		 */
		public static function remove_job( $job_id ) {
			$job_id = (string) $job_id;

			$jobs = self::load_jobs();

			if ( ! isset( $jobs[ $job_id ] ) ) {
				return false;
			}

			$job   = $jobs[ $job_id ];
			$hook  = isset( $job['hook'] ) ? (string) $job['hook'] : '';
			$args  = isset( $job['args'] ) ? $job['args'] : array();
			$args  = self::normalise_args( $args );
			$event = false;

			if ( '' !== $hook ) {
				$event = wp_get_scheduled_event( $hook, $args );
			}

			if ( $event ) {
				if ( empty( $event->schedule ) ) {
					wp_unschedule_event( $event->timestamp, $hook, $args );
				} else {
					wp_clear_scheduled_hook( $hook, $args );
				}
			}

			unset( $jobs[ $job_id ] );
			self::save_jobs( $jobs );

			return true;
		}

		/**
		 * Remove entries for jobs that are no longer scheduled.
		 *
		 * Jobs are kept for a configurable period after their scheduled time to allow users
		 * to see recently executed one-time jobs and verify test jobs ran successfully.
		 * The retention period can be configured in Settings > Orchestration Layer.
		 */
		public static function maybe_prune_jobs() {
			$jobs    = self::load_jobs();
			$changed = false;

			// Get retention period from settings registry (in hours), default to 24 hours.
			if ( class_exists( 'WP_MCP_AI_Settings_Registry' ) ) {
				$retention_hours = WP_MCP_AI_Settings_Registry::get_setting( 'cron_job_retention_period', 24 );
			} else {
				// Fallback to direct option read if registry not available.
				$settings        = get_option( 'wp_mcp_ai_settings', array() );
				$retention_hours = isset( $settings['cron_job_retention_period'] ) ? $settings['cron_job_retention_period'] : 24;
			}
			$retention_hours = absint( $retention_hours );

			// If retention is 0, jobs are removed immediately when not scheduled.
			$retention_period = $retention_hours > 0 ? $retention_hours * HOUR_IN_SECONDS : 0;

			foreach ( $jobs as $job_id => $job ) {
				$hook = isset( $job['hook'] ) ? (string) $job['hook'] : '';
				$args = isset( $job['args'] ) ? $job['args'] : array();
				$args = self::normalise_args( $args );

				if ( '' === $hook ) {
					unset( $jobs[ $job_id ] );
					$changed = true;
					continue;
				}

				$event = wp_get_scheduled_event( $hook, $args );
				if ( ! $event ) {
					// Check if we should remove the job based on retention period.
					if ( $retention_period === 0 ) {
						// Remove immediately if retention is disabled.
						unset( $jobs[ $job_id ] );
						$changed = true;
					} else {
						// Only remove if it's been longer than the retention period.
						$first_timestamp = isset( $job['first_timestamp'] ) ? (int) $job['first_timestamp'] : 0;

						if ( $first_timestamp > 0 && ( time() - $first_timestamp ) > $retention_period ) {
							unset( $jobs[ $job_id ] );
							$changed = true;
						}
					}
				}
			}

			if ( $changed ) {
				self::save_jobs( $jobs );
			}
		}

		/**
		 * Generate a stable identifier for a cron job.
		 *
		 * @param string $hook Cron hook name.
		 * @param array  $args Cron arguments.
		 *
		 * @return string
		 */
		protected static function generate_job_id( $hook, $args ) {
			$data = array(
				'hook' => (string) $hook,
				'args' => self::normalise_args( $args ),
			);

			return md5( wp_json_encode( $data ) );
		}

		/**
		 * Load the stored jobs from the options table.
		 *
		 * @return array
		 */
		protected static function load_jobs() {
			$raw_jobs = get_option( self::OPTION_NAME, array() );

			if ( ! is_array( $raw_jobs ) ) {
				$raw_jobs = array();
			}

			$jobs    = array();
			$updated = false;

			foreach ( $raw_jobs as $key => $job ) {
				if ( ! is_array( $job ) || empty( $job['hook'] ) ) {
					$updated = true;
					continue;
				}

				$job_id = isset( $job['job_id'] ) ? (string) $job['job_id'] : self::generate_job_id( $job['hook'], isset( $job['args'] ) ? $job['args'] : array() );

				$normalised_args = self::normalise_args( isset( $job['args'] ) ? $job['args'] : array() );

				$jobs[ $job_id ] = array(
					'job_id'          => $job_id,
					'hook'            => (string) $job['hook'],
					'args'            => $normalised_args,
					'schedule'        => isset( $job['schedule'] ) ? (string) $job['schedule'] : 'single',
					'first_timestamp' => isset( $job['first_timestamp'] ) ? (int) $job['first_timestamp'] : 0,
					'created_at'      => isset( $job['created_at'] ) ? (int) $job['created_at'] : 0,
					'created_by'      => isset( $job['created_by'] ) ? (int) $job['created_by'] : 0,
				);

				if ( ! isset( $job['job_id'] ) || $job_id !== $job['job_id'] ) {
					$updated = true;
				}
			}

			if ( $updated ) {
				self::save_jobs( $jobs );
			}

			return $jobs;
		}

		/**
		 * Persist the cron jobs array to the database.
		 *
		 * @param array $jobs Jobs to store.
		 */
		protected static function save_jobs( $jobs ) {
			if ( ! is_array( $jobs ) ) {
				$jobs = array();
			}

			$existing = get_option( self::OPTION_NAME, null );

			if ( null === $existing ) {
				add_option( self::OPTION_NAME, $jobs, '', 'no' );
			} else {
				update_option( self::OPTION_NAME, $jobs );
			}
		}
	}

	WP_MCP_AI_Cron_Manager::init();
}
