<?php
/**
 * NV oOS Cloudways Dashboard — Provisioning Job
 *
 * Background job (via Action Scheduler) that polls Cloudways for app
 * provisioning status and auto-applies toolkits once the app is running.
 *
 * @package NV_oOS_CloudwaysDashboard
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provisioning monitor job.
 *
 * Enqueued by the create-app REST endpoint. Each run:
 *  1. Polls Cloudways for the current app status.
 *  2. If still provisioning, re-schedules itself for +30 s.
 *  3. If running, applies pending toolkits and marks provisioning complete.
 *  4. If failed, logs the error and stops.
 *
 * Maximum retries: 60 (30 minutes at 30 s intervals).
 *
 * @since 0.1.0
 */
class NV_oOS_CloudwaysDashboard_Provisioning_Job {

	/**
	 * Action Scheduler hook name.
	 *
	 * @var string
	 */
	const HOOK = 'nvoos_cloudways_dashboard_provision_app';

	/**
	 * Base polling interval in seconds.
	 *
	 * @var int
	 */
	const POLL_INTERVAL_BASE = 30;

	/**
	 * Maximum polling interval in seconds (cap).
	 *
	 * @var int
	 */
	const POLL_INTERVAL_MAX = 300; // 5 minutes.

	/**
	 * Backoff multiplier for exponential delay.
	 *
	 * @var int
	 */
	const BACKOFF_MULTIPLIER = 2;

	/**
	 * Maximum jitter in seconds added to each delay.
	 *
	 * @var int
	 */
	const JITTER_MAX_SECONDS = 5;

	/**
	 * Maximum number of polling attempts before giving up.
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 60;

	/**
	 * Enqueue a new provisioning job for an app.
	 *
	 * @param int   $app_id       Cloudways app ID.
	 * @param array $toolkit_ids  Toolkit slugs to apply.
	 * @param array $assistant_ids Assistant IDs to create.
	 * @return int|false Action Scheduler action ID, or false on failure.
	 */
	public static function enqueue( $app_id, $toolkit_ids = array(), $assistant_ids = array() ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			self::log_error( $app_id, 'Action Scheduler not available.' );
			return false;
		}

		$action_id = as_enqueue_async_action(
			self::HOOK,
			array(
				'app_id'        => absint( $app_id ),
				'toolkit_ids'   => array_map( 'sanitize_key', (array) $toolkit_ids ),
				'assistant_ids' => array_map( 'sanitize_key', (array) $assistant_ids ),
				'attempt'       => 0,
			),
			'nvoos_cloudways_dashboard'
		);

		if ( $action_id ) {
			update_option(
				self::status_key( $app_id ),
				array(
					'status'       => 'provisioning',
					'action_id'    => $action_id,
					'attempt'      => 0,
					'started_at'   => time(),
					'last_poll_at' => null,
					'error'        => null,
				)
			);
		}

		return $action_id;
	}

	/**
	 * Callback invoked by Action Scheduler.
	 *
	 * @param int   $app_id        Cloudways app ID.
	 * @param array $toolkit_ids   Toolkit slugs.
	 * @param array $assistant_ids Assistant IDs.
	 * @param int   $attempt       Current attempt count.
	 * @return void
	 */
	public static function run( $app_id, $toolkit_ids = array(), $assistant_ids = array(), $attempt = 0 ) {
		$app_id  = absint( $app_id );
		$attempt = absint( $attempt ) + 1;

		// Safety ceiling.
		if ( $attempt > self::MAX_ATTEMPTS ) {
			self::set_status( $app_id, 'timeout', 'Exceeded maximum polling attempts (' . self::MAX_ATTEMPTS . ').' );
			return;
		}

		self::set_status( $app_id, 'provisioning', null, $attempt );

		// Bail if Cloudways client not available.
		if ( ! class_exists( 'WP_MCP_AI_Cloudways_Client' ) ) {
			self::set_status( $app_id, 'failed', 'Cloudways client not available.' );
			return;
		}

		$client = \WP_MCP_AI_Cloudways_Client::instance();
		if ( ! $client->is_configured() ) {
			self::set_status( $app_id, 'failed', 'Cloudways credentials not configured.' );
			return;
		}

		// Poll Cloudways for app status.
		$result = $client->get( "/app/{$app_id}" );

		if ( is_wp_error( $result ) ) {
			$error_msg = $result->get_error_message();
			self::log_error( $app_id, "API error (attempt {$attempt}): {$error_msg}" );

			// On transient error, retry.
			self::reschedule( $app_id, $toolkit_ids, $assistant_ids, $attempt );
			return;
		}

		$app    = isset( $result['app'] ) ? $result['app'] : $result;
		$status = isset( $app['status'] ) ? strtolower( $app['status'] ) : 'unknown';

		self::log_info( $app_id, "App status: {$status} (attempt {$attempt})" );

		switch ( $status ) {
			case 'running':
				self::on_running( $app_id, $app, $toolkit_ids, $assistant_ids );
				break;

			case 'provisioning':
			case 'pending':
			case 'deploying':
				// Still provisioning — reschedule.
				self::reschedule( $app_id, $toolkit_ids, $assistant_ids, $attempt );
				break;

			case 'failed':
			case 'error':
				self::set_status( $app_id, 'failed', 'Cloudways reported app status: ' . ( isset( $app['status'] ) ? $app['status'] : 'unknown' ) );
				break;

			default:
				// Unknown status — keep polling but log.
				self::log_info( $app_id, "Unknown status: {$status}" );
				self::reschedule( $app_id, $toolkit_ids, $assistant_ids, $attempt );
				break;
		}
	}

	/**
	 * Called when the app is running — apply toolkits.
	 *
	 * @param int   $app_id        Cloudways app ID.
	 * @param array $app           App data from Cloudways API.
	 * @param array $toolkit_ids   Toolkit slugs to apply.
	 * @param array $assistant_ids Assistant IDs to create.
	 * @return void
	 */
	private static function on_running( $app_id, $app, $toolkit_ids, $assistant_ids ) {
		$app_url  = isset( $app['app_fqdn'] ) ? $app['app_fqdn'] : '';
		$cname    = isset( $app['cname'] ) ? $app['cname'] : $app_url;
		$username = isset( $app['username'] ) ? $app['username'] : '';
		$password = isset( $app['password'] ) ? $app['password'] : '';

		$results = array(
			'app_url'        => $app_url,
			'cname'          => $cname,
			'plugin_install' => 'skipped',
			'toolkits'       => array(),
			'assistants'     => array(),
			'applied_at'     => time(),
		);

		// Attempt to install the nvOS base plugin on the target site.
		$plugin_result             = self::install_nvos_plugin( $app_id, $cname, $username, $password );
		$results['plugin_install'] = $plugin_result;

		// Apply toolkits via the toolkit-shell manifest system.
		if ( ! empty( $toolkit_ids ) ) {
			foreach ( $toolkit_ids as $tk_slug ) {
				$results['toolkits'][ $tk_slug ] = self::apply_toolkit( $app_id, $tk_slug );
			}
		}

		// TODO: Create assistants on the target site once plugin is installed.
		if ( ! empty( $assistant_ids ) ) {
			$results['assistants'] = 'pending_plugin_install';
		}

		// Mark as ready.
		self::set_status( $app_id, 'ready', null, null, $results );
		self::log_info( $app_id, 'Provisioning complete. App is ready.' );

		/**
		 * Fires when a Cloudways app has finished provisioning and toolkits are applied.
		 *
		 * @param int   $app_id  Cloudways app ID.
		 * @param array $results Provisioning results.
		 * @since 0.1.0
		 */
		do_action( 'nvoos_cloudways_dashboard_app_ready', $app_id, $results );
	}

	/**
	 * Attempt to install the NV oOS base plugin on a remote WordPress site.
	 *
	 * Uses wp_remote_post to the target site's REST API (if accessible) or
	 * records the intent for manual install.
	 *
	 * @param int    $app_id    Cloudways app ID.
	 * @param string $cname     App domain.
	 * @param string $_username App username (reserved for future use).
	 * @param string $_password App password (reserved for future use).
	 * @return string 'installed', 'pending_manual', or 'unreachable'.
	 */
	private static function install_nvos_plugin( $app_id, $cname, $_username, $_password ) {
		if ( empty( $cname ) ) {
			return 'no_domain';
		}

		// Try WordPress plugin installation via REST API.
		$site_url = 'https://' . $cname;
		$rest_url = $site_url . '/wp-json/wp/v2/plugins';

		// First, check if the site is reachable.
		$health = wp_remote_get(
			$site_url,
			array(
				'timeout'   => 10,
				'sslverify' => false,
			)
		);
		if ( is_wp_error( $health ) ) {
			self::log_info( $app_id, "Site not yet reachable at {$cname}" );
			return 'unreachable';
		}

		// Record the intent — the plugin slug to install.
		$plugin_slug = 'mcp-ai-wpoos';
		update_option(
			"nvoos_cw_app_plugin_intent_{$app_id}",
			array(
				'site_url'    => $site_url,
				'plugin_slug' => $plugin_slug,
				'created_at'  => time(),
			)
		);

		self::log_info( $app_id, "Plugin install intent recorded for {$plugin_slug} on {$cname}" );
		return 'pending_manual';
	}

	/**
	 * Record toolkit intent for a site.
	 *
	 * @param int    $app_id  Cloudways app ID.
	 * @param string $tk_slug Toolkit slug.
	 * @return string Status.
	 */
	private static function apply_toolkit( $app_id, $tk_slug ) {
		$intents             = get_option( "nvoos_cw_toolkit_intents_{$app_id}", array() );
		$intents[ $tk_slug ] = array(
			'slug'       => $tk_slug,
			'applied_at' => time(),
			'status'     => 'pending_install',
		);
		update_option(
			"nvoos_cw_toolkit_intents_{$app_id}",
			$intents
		);
		self::log_info( $app_id, "Toolkit intent recorded: {$tk_slug}" );
		return 'pending_install';
	}

	/**
	 * Re-schedule the job for another poll.
	 *
	 * @param int   $app_id        Cloudways app ID.
	 * @param array $toolkit_ids   Toolkit slugs.
	 * @param array $assistant_ids Assistant IDs.
	 * @param int   $attempt       Current attempt.
	 * @return void
	 */
	/**
	 * Calculate the next poll delay with exponential backoff and jitter.
	 *
	 * @since 1.2.0
	 *
	 * @param int $attempt Current attempt count.
	 * @return int Delay in seconds.
	 */
	private static function calculate_poll_delay( $attempt ) {
		$exponential = self::POLL_INTERVAL_BASE * pow( self::BACKOFF_MULTIPLIER, $attempt - 1 );
		$capped      = min( $exponential, self::POLL_INTERVAL_MAX );
		$jitter      = wp_rand( 0, self::JITTER_MAX_SECONDS );

		return (int) ( $capped + $jitter );
	}

	/**
	 * Re-schedule the job for another poll.
	 *
	 * @param int   $app_id        Cloudways app ID.
	 * @param array $toolkit_ids   Toolkit slugs.
	 * @param array $assistant_ids Assistant IDs.
	 * @param int   $attempt       Current attempt.
	 * @return void
	 */
	private static function reschedule( $app_id, $toolkit_ids, $assistant_ids, $attempt ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			self::set_status( $app_id, 'failed', 'Action Scheduler not available for re-scheduling.' );
			return;
		}

		$delay = self::calculate_poll_delay( $attempt );

		as_schedule_single_action(
			time() + $delay,
			self::HOOK,
			array(
				'app_id'        => $app_id,
				'toolkit_ids'   => $toolkit_ids,
				'assistant_ids' => $assistant_ids,
				'attempt'       => $attempt,
			),
			'nvoos_cloudways_dashboard'
		);
	}

	/**
	 * Update provisioning status in the options table.
	 *
	 * @param int         $app_id  Cloudways app ID.
	 * @param string      $status  Status label.
	 * @param string|null $error   Error message.
	 * @param int|null    $attempt Attempt count.
	 * @param array|null  $results Final results (for 'ready' state).
	 * @return void
	 */
	/**
	 * Update provisioning status in the options table.
	 *
	 * For terminal states (ready, failed, timeout), schedules a deferred
	 * cleanup so completed jobs do not pollute the options table forever.
	 *
	 * @param int         $app_id  Cloudways app ID.
	 * @param string      $status  Status label.
	 * @param string|null $error   Error message.
	 * @param int|null    $attempt Attempt count.
	 * @param array|null  $results Final results (for 'ready' state).
	 * @return void
	 */
	private static function set_status( $app_id, $status, $error = null, $attempt = null, $results = null ) {
		$current = get_option( self::status_key( $app_id ), array() );

		if ( null !== $attempt ) {
			$current['attempt'] = $attempt;
		}
		$current['status']       = $status;
		$current['last_poll_at'] = time();
		if ( null !== $error ) {
			$current['error'] = $error;
		}
		if ( null !== $results ) {
			$current['results'] = $results;
		}

		update_option( self::status_key( $app_id ), $current );

		// Schedule cleanup for terminal states.
		$terminal_states = array( 'ready', 'failed', 'timeout' );
		if ( in_array( $status, $terminal_states, true ) ) {
			$hook = 'nvoos_cloudways_dashboard_cleanup_provisioning_status';
			if ( ! wp_next_scheduled( $hook, array( 'app_id' => $app_id ) ) ) {
				wp_schedule_single_event( time() + DAY_IN_SECONDS, $hook, array( 'app_id' => $app_id ) );
			}
		}
	}

	/**
	 * Clean up provisioning status and related option records for an app.
	 *
	 * @since 1.2.0
	 *
	 * @param int $app_id Cloudways app ID.
	 * @return void
	 */
	public static function cleanup_status( $app_id ) {
		delete_option( self::status_key( $app_id ) );
		delete_option( "nvoos_cw_app_plugin_intent_{$app_id}" );
		delete_option( "nvoos_cw_toolkit_intents_{$app_id}" );
	}

	/**
	 * Register cleanup hooks on plugin init.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function register_cleanup_hooks() {
		add_action(
			'nvoos_cloudways_dashboard_cleanup_provisioning_status',
			array( __CLASS__, 'cleanup_status' ),
			10,
			1
		);
	}

	/**
	 * Get the option key for an app's provisioning status.
	 *
	 * @param int $app_id Cloudways app ID.
	 * @return string
	 */
	public static function status_key( $app_id ) {
		return "nvoos_cw_provisioning_{$app_id}";
	}

	/**
	 * Log an info-level message about this app.
	 *
	 * @param int    $app_id Cloudways app ID.
	 * @param string $message Log message.
	 * @return void
	 */
	private static function log_info( $app_id, $message ) {
		if ( function_exists( 'wp_mcp_ai_log' ) ) {
			wp_mcp_ai_log( "[Cloudways Dashboard] [App #{$app_id}] {$message}" );
		}
	}

	/**
	 * Log an error-level message about this app.
	 *
	 * @param int    $app_id Cloudways app ID.
	 * @param string $message Error message.
	 * @return void
	 */
	private static function log_error( $app_id, $message ) {
		if ( function_exists( 'wp_mcp_ai_log' ) ) {
			wp_mcp_ai_log( "[Cloudways Dashboard] [App #{$app_id}] ERROR: {$message}", 'error' );
		}
	}
}
