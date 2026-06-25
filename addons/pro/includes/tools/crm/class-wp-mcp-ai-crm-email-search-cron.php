<?php
/**
 * CRM Cron Scheduler
 *
 * Centralized WP Cron registration for CRM background jobs:
 * email search cache refresh, automatic lead pruning,
 * message log compaction, and scheduled multi-source lead auto-import.
 *
 * Hooks wired:
 *  - wp_mcp_ai_crm_email_search_leads_refresh          (hourly)
 *  - wp_mcp_ai_crm_email_search_correspondence_refresh  (hourly)
 *  - wp_mcp_ai_crm_email_search_accounting_refresh      (twicedaily)
 *  - wp_mcp_ai_crm_auto_prune                           (daily)
 *  - wp_mcp_ai_crm_auto_import_sources                  (custom interval)
 *
 * @package WP_MCP_AI_Pro
 * @since  2.9.0
 * @since  2.11.0 Added wp_mcp_ai_crm_auto_import_sources hook for Upwork/LinkedIn scheduled import.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRM Cron scheduler.
 *
 * @since 2.9.0
 */
class WP_MCP_AI_CRM_Email_Search_Cron {

	/**
	 * Cron hook for automatic lead pruning.
	 *
	 * @since 2.9.0
	 * @var string
	 */
	const AUTO_PRUNE_HOOK = 'wp_mcp_ai_crm_auto_prune';

	/**
	 * Cron hook for scheduled multi-source lead auto-import.
	 *
	 * @since 2.11.0
	 * @var string
	 */
	const AUTO_IMPORT_HOOK = 'wp_mcp_ai_crm_auto_import_sources';

	/**
	 * Hooks to schedule: hook_name => recurrence.
	 *
	 * @var array<string, string>
	 */
	const SCHEDULES = array(
		'wp_mcp_ai_crm_email_search_leads_refresh'      => 'hourly',
		'wp_mcp_ai_crm_email_search_correspondence_refresh' => 'hourly',
		'wp_mcp_ai_crm_email_search_accounting_refresh' => 'twicedaily',
	);

	/**
	 * Initialize cron scheduling.
	 *
	 * @since 2.9.0
	 */
	public static function init() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return;
		}

		add_action( 'init', array( __CLASS__, 'maybe_schedule_all' ), 30 );

		// Register the auto-prune handler.
		add_action( self::AUTO_PRUNE_HOOK, array( __CLASS__, 'run_auto_prune' ) );

		// Register the multi-source auto-import handler.
		add_action( self::AUTO_IMPORT_HOOK, array( __CLASS__, 'run_auto_import_sources' ) );
	}

	/**
	 * Schedule all email search cron hooks and auto-prune if not already scheduled.
	 *
	 * @since 2.9.0
	 */
	public static function maybe_schedule_all() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return;
		}

		foreach ( self::SCHEDULES as $hook => $recurrence ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time(), $recurrence, $hook );
			}
		}

		// Schedule auto-prune if hygiene pruning is enabled.
		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			$hygiene       = WP_MCP_AI_CRM_Engine::get_hygiene_settings();
			$prune_enabled = ! empty( $hygiene['auto_prune_spam'] )
				|| ! empty( $hygiene['auto_prune_excluded'] )
				|| ( ! empty( $hygiene['auto_prune_stale_days'] ) && $hygiene['auto_prune_stale_days'] > 0 );

			if ( $prune_enabled && ! wp_next_scheduled( self::AUTO_PRUNE_HOOK ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::AUTO_PRUNE_HOOK );
			} elseif ( ! $prune_enabled ) {
				$ts = wp_next_scheduled( self::AUTO_PRUNE_HOOK );
				if ( $ts ) {
					wp_unschedule_event( $ts, self::AUTO_PRUNE_HOOK );
				}
			}

			// Schedule multi-source auto-import if any external sourcing platform has auto-import enabled.
			$crm_settings          = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
			$upwork_auto_enabled   = ! empty( $crm_settings['external_sourcing']['upwork']['auto_import_enabled'] );
			$linkedin_auto_enabled = ! empty( $crm_settings['external_sourcing']['linkedin']['auto_import_enabled'] );
			$auto_import_enabled   = $upwork_auto_enabled || $linkedin_auto_enabled;
			$interval_hours        = isset( $crm_settings['external_sourcing']['auto_import_interval_hours'] )
				? max( 1, min( 24, absint( $crm_settings['external_sourcing']['auto_import_interval_hours'] ) ) )
				: 6;

			if ( $auto_import_enabled ) {
				// Register a custom cron schedule for the configured interval.
				add_filter(
					// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Dynamic interval from user-configurable hours.
					'cron_schedules',
					function ( $schedules ) use ( $interval_hours ) {
						$interval_seconds             = $interval_hours * HOUR_IN_SECONDS;
						$schedules['crm_auto_import'] = array(
							'interval' => $interval_seconds,
							/* translators: %d: number of hours */
							'display'  => sprintf( __( 'Every %d hours (CRM Auto-Import)', 'mcp-ai-wpoos-pro' ), $interval_hours ),
						);
						return $schedules;
					}
				);

				if ( ! wp_next_scheduled( self::AUTO_IMPORT_HOOK ) ) {
					wp_schedule_event( time() + ( 5 * MINUTE_IN_SECONDS ), 'crm_auto_import', self::AUTO_IMPORT_HOOK );
				}
			} else {
				$ts = wp_next_scheduled( self::AUTO_IMPORT_HOOK );
				if ( $ts ) {
					wp_unschedule_event( $ts, self::AUTO_IMPORT_HOOK );
				}
			}
		}
	}

	/**
	 * Run automatic lead pruning based on hygiene settings.
	 *
	 * Called daily via WP Cron. Respects the hygiene configuration:
	 * auto_prune_spam, auto_prune_excluded, auto_prune_stale_days.
	 * Safety-capped at 100 leads per run to prevent mass deletion.
	 *
	 * @since 2.9.0
	 */
	public static function run_auto_prune() {
		if ( ! class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			return;
		}

		$hygiene = WP_MCP_AI_CRM_Engine::get_hygiene_settings();

		$prune_spam     = ! empty( $hygiene['auto_prune_spam'] );
		$prune_excluded = ! empty( $hygiene['auto_prune_excluded'] );
		$prune_stale    = ! empty( $hygiene['auto_prune_stale_days'] )
			? absint( $hygiene['auto_prune_stale_days'] )
			: 0;

		// Skip if nothing is configured to auto-prune.
		if ( ! $prune_spam && ! $prune_excluded && $prune_stale <= 0 ) {
			return;
		}

		// Load the prune tool.
		$_prune_file = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/compliance/class-wp-mcp-ai-tool-prune-crm-messages.php';
		if ( ! file_exists( $_prune_file ) ) {
			return;
		}
		require_once $_prune_file;
		if ( ! class_exists( 'WP_MCP_AI_Tool_Prune_CRM_Messages' ) ) {
			return;
		}

		$pruner = new WP_MCP_AI_Tool_Prune_CRM_Messages();
		$result = $pruner->execute(
			array(
				'dry_run'             => false,
				'prune_spam'          => $prune_spam,
				'prune_excluded'      => $prune_excluded,
				'prune_stale_days'    => $prune_stale,
				'prune_never_engaged' => false, // Opt-in separately.
				'max_prune'           => 100,     // Safety cap per run.
			),
			array( 'user_id' => 0 )
		);

		if ( is_wp_error( $result ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'CRM auto-prune error: ' . $result->get_error_message() );
		}
	}

	/**
	 * Run multi-source lead auto-import from Upwork and LinkedIn.
	 *
	 * Called on the wp_mcp_ai_crm_auto_import_sources cron hook.
	 * Searches enabled platforms for new jobs, scores them, and imports
	 * high-scoring ones into the CRM pipeline using the configured
	 * external_sourcing settings.
	 *
	 * Each source fails independently — a failing platform does not
	 * prevent the other from running.
	 *
	 * @since 2.11.0
	 */
	public static function run_auto_import_sources() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) || ! class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			return;
		}

		$all_connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
		if ( empty( $all_connections ) ) {
			return;
		}

		$crm_settings = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
		$user_context = array( 'user_id' => 0 ); // Cron context: no specific user.
		$log_prefix   = 'CRM auto-import';

		// ── Upwork pipeline ──
		$upwork_config = isset( $crm_settings['external_sourcing']['upwork'] ) ? $crm_settings['external_sourcing']['upwork'] : array();
		if ( ! empty( $upwork_config['auto_import_enabled'] ) ) {
			self::run_auto_import_upwork( $all_connections, $crm_settings, $upwork_config, $user_context, $log_prefix );
		}

		// ── LinkedIn pipeline ──
		$linkedin_config = isset( $crm_settings['external_sourcing']['linkedin'] ) ? $crm_settings['external_sourcing']['linkedin'] : array();
		if ( ! empty( $linkedin_config['auto_import_enabled'] ) ) {
			self::run_auto_import_linkedin( $all_connections, $crm_settings, $linkedin_config, $user_context, $log_prefix );
		}

		// Save last-auto-import timestamp.
		update_option( 'wp_mcp_ai_crm_cc_last_source_refresh', time(), false );
	}

	/**
	 * Run Upwork auto-import for all enabled connections.
	 *
	 * @since 2.11.0
	 *
	 * @param array  $all_connections All remote connections.
	 * @param array  $crm_settings    CRM toolkit settings.
	 * @param array  $upwork_config   Upwork-specific config.
	 * @param array  $user_context    Execution context.
	 * @param string $log_prefix     Log prefix for error messages.
	 */
	private static function run_auto_import_upwork( $all_connections, $crm_settings, $upwork_config, $user_context, $log_prefix ) {
		$_search_file = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/upwork/class-wp-mcp-ai-tool-search-upwork-jobs.php';
		$_score_file  = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/upwork/class-wp-mcp-ai-tool-score-upwork-job.php';
		$_import_file = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/upwork/class-wp-mcp-ai-tool-import-upwork-project.php';

		if ( ! file_exists( $_search_file ) || ! file_exists( $_score_file ) || ! file_exists( $_import_file ) ) {
			return;
		}

		require_once $_search_file;
		require_once $_score_file;
		require_once $_import_file;

		$min_score      = isset( $upwork_config['auto_import_min_score'] ) ? (int) $upwork_config['auto_import_min_score'] : 60;
		$save_as        = isset( $upwork_config['auto_import_as'] ) ? sanitize_key( $upwork_config['auto_import_as'] ) : 'deal';
		$excluded_words = isset( $crm_settings['external_sourcing']['excluded_keywords'] ) ? $crm_settings['external_sourcing']['excluded_keywords'] : '';

		foreach ( $all_connections as $conn_id => $connection ) {
			$conn_type = isset( $connection['connection_type'] ) ? sanitize_key( $connection['connection_type'] ) : '';
			if ( 'upwork' !== $conn_type || empty( $connection['enabled'] ) ) {
				continue;
			}

			try {
				$searcher = new WP_MCP_AI_Tool_Search_Upwork_Jobs();
				$results  = $searcher->execute(
					array(
						'connection_id' => $conn_id,
						'limit'         => 10,
					),
					$user_context
				);

				if ( is_wp_error( $results ) || empty( $results['success'] ) ) {
					continue;
				}

				$jobs = isset( $results['data']['jobs'] ) ? $results['data']['jobs'] : array();
				if ( empty( $jobs ) ) {
					continue;
				}

				foreach ( $jobs as $job ) {
					$job_id    = isset( $job['id'] ) ? sanitize_text_field( $job['id'] ) : '';
					$job_title = isset( $job['title'] ) ? sanitize_text_field( $job['title'] ) : '';

					if ( ! $job_id ) {
						continue;
					}

					// Skip excluded keywords.
					if ( $excluded_words && $job_title ) {
						$_excluded = array_filter( array_map( 'trim', explode( "\n", $excluded_words ) ) );
						$_skip     = false;
						foreach ( $_excluded as $_kw ) {
							if ( $_kw && false !== stripos( $job_title, $_kw ) ) {
								$_skip = true;
								break;
							}
						}
						if ( $_skip ) {
							continue;
						}
					}

					$scorer    = new WP_MCP_AI_Tool_Score_Upwork_Job();
					$score     = $scorer->execute( array( 'job_id' => $job_id ), $user_context );
					$score_val = 0;
					if ( ! is_wp_error( $score ) && ! empty( $score['success'] ) ) {
						$score_val = isset( $score['total_score'] ) ? (int) $score['total_score'] : 0;
					}

					if ( $score_val >= $min_score ) {
						$importer = new WP_MCP_AI_Tool_Import_Upwork_Project();
						$importer->execute(
							array(
								'job_id'        => $job_id,
								'save_as'       => $save_as,
								'connection_id' => $conn_id,
							),
							$user_context
						);
					}
				}
			} catch ( \Exception $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( $log_prefix . ' Upwork error: ' . $e->getMessage() );
				}
			}
		}
	}

	/**
	 * Run LinkedIn auto-import for all enabled connections.
	 *
	 * @since 2.11.0
	 *
	 * @param array  $all_connections  All remote connections.
	 * @param array  $crm_settings     CRM toolkit settings.
	 * @param array  $linkedin_config  LinkedIn-specific config.
	 * @param array  $user_context     Execution context.
	 * @param string $log_prefix      Log prefix for error messages.
	 */
	private static function run_auto_import_linkedin( $all_connections, $crm_settings, $linkedin_config, $user_context, $log_prefix ) {
		$_search_file = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/linkedin/class-wp-mcp-ai-tool-search-linkedin-jobs.php';
		$_score_file  = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/linkedin/class-wp-mcp-ai-tool-score-linkedin-job.php';
		$_save_file   = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/linkedin/class-wp-mcp-ai-tool-save-linkedin-job.php';

		if ( ! file_exists( $_search_file ) || ! file_exists( $_score_file ) || ! file_exists( $_save_file ) ) {
			return;
		}

		require_once $_search_file;
		require_once $_score_file;
		require_once $_save_file;

		$li_min_score   = isset( $linkedin_config['auto_import_min_score'] ) ? (int) $linkedin_config['auto_import_min_score'] : 60;
		$li_save_as     = isset( $linkedin_config['auto_import_as'] ) ? sanitize_key( $linkedin_config['auto_import_as'] ) : 'deal';
		$li_keywords    = isset( $linkedin_config['default_search_keywords'] ) ? $linkedin_config['default_search_keywords'] : '';
		$li_location    = isset( $linkedin_config['default_location'] ) ? $linkedin_config['default_location'] : '';
		$excluded_words = isset( $crm_settings['external_sourcing']['excluded_keywords'] ) ? $crm_settings['external_sourcing']['excluded_keywords'] : '';

		foreach ( $all_connections as $conn_id => $connection ) {
			$conn_type = isset( $connection['connection_type'] ) ? sanitize_key( $connection['connection_type'] ) : '';
			if ( 'linkedin' !== $conn_type || empty( $connection['enabled'] ) ) {
				continue;
			}

			try {
				$li_searcher = new WP_MCP_AI_Tool_Search_LinkedIn_Jobs();
				$li_args     = array(
					'connection_id' => $conn_id,
					'limit'         => 10,
				);
				if ( $li_keywords ) {
					$li_args['query'] = $li_keywords;
				}
				if ( $li_location ) {
					$li_args['location'] = $li_location;
				}
				$li_results = $li_searcher->execute( $li_args, $user_context );

				if ( is_wp_error( $li_results ) || empty( $li_results['success'] ) ) {
					continue;
				}

				$li_jobs = isset( $li_results['data']['jobs'] ) ? $li_results['data']['jobs'] : array();
				if ( empty( $li_jobs ) ) {
					continue;
				}

				foreach ( $li_jobs as $li_job ) {
					$li_job_id    = isset( $li_job['id'] ) ? sanitize_text_field( $li_job['id'] ) : '';
					$li_job_title = isset( $li_job['title'] ) ? sanitize_text_field( $li_job['title'] ) : '';

					if ( ! $li_job_id ) {
						continue;
					}

					// Skip excluded keywords.
					if ( $excluded_words && $li_job_title ) {
						$_excluded = array_filter( array_map( 'trim', explode( "\n", $excluded_words ) ) );
						$_skip     = false;
						foreach ( $_excluded as $_kw ) {
							if ( $_kw && false !== stripos( $li_job_title, $_kw ) ) {
								$_skip = true;
								break;
							}
						}
						if ( $_skip ) {
							continue;
						}
					}

					$li_scorer    = new WP_MCP_AI_Tool_Score_LinkedIn_Job();
					$li_score     = $li_scorer->execute( array( 'job_id' => $li_job_id ), $user_context );
					$li_score_val = 0;
					if ( ! is_wp_error( $li_score ) && ! empty( $li_score['success'] ) ) {
						$li_score_val = isset( $li_score['total_score'] ) ? (int) $li_score['total_score'] : 0;
					}

					if ( $li_score_val >= $li_min_score ) {
						$li_saver = new WP_MCP_AI_Tool_Save_LinkedIn_Job();
						$li_saver->execute(
							array(
								'job_id'        => $li_job_id,
								'save_as'       => $li_save_as,
								'connection_id' => $conn_id,
							),
							$user_context
						);
					}
				}
			} catch ( \Exception $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( $log_prefix . ' LinkedIn error: ' . $e->getMessage() );
				}
			}
		}
	}

	/**
	 * Clear all email search cron hooks (called on deactivation).
	 *
	 * @since 2.9.0
	 */
	public static function unschedule_all() {
		foreach ( array_keys( self::SCHEDULES ) as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}

		$ts = wp_next_scheduled( self::AUTO_PRUNE_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::AUTO_PRUNE_HOOK );
		}

		$ts = wp_next_scheduled( self::AUTO_IMPORT_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::AUTO_IMPORT_HOOK );
		}
	}
}

// Initialize.
add_action( 'plugins_loaded', array( 'WP_MCP_AI_CRM_Email_Search_Cron', 'init' ), 30 );
