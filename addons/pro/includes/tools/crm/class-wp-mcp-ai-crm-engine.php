<?php
/**
 * CRM Toolkit Shared Engine
 *
 * Cross-cutting helpers shared by all CRM sub-modules:
 *
 *  - Toolkit settings (wp_mcp_ai_crm_toolkit_settings) resolution.
 *  - Lead scoring (0–100 cold/warm/hot with factor decomposition).
 *  - Lifecycle stage progression (Subscriber → Lead → MQL → SAL → SQL → Opportunity → Customer).
 *  - Pipeline probability lookups (stage → win probability).
 *  - Routing strategy resolution (round_robin, weighted, territory, skill).
 *  - Currency formatting helpers.
 *  - DNC / suppression helper.
 *  - Search algorithm configuration (keyword_tfidf, fulltext).
 *
 * Mirrors WP_MCP_AI_Healthcare_Engine in the healthcare toolkit.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared CRM engine.
 *
 * @since 2.3.0
 * @since 2.4.0 Added 'search' settings block for configurable relevance algorithm.
 */
class WP_MCP_AI_CRM_Engine {

	/**
	 * Settings option key.
	 *
	 * @var string
	 */
	const SETTINGS_OPTION = 'wp_mcp_ai_crm_toolkit_settings';

	/**
	 * Cached toolkit settings.
	 *
	 * @var array|null
	 */
	private static $settings_cache = null;

	/*
	---------------------------------------------------------------------
	* Toolkit settings
	* ------------------------------------------------------------------
	*/

	/**
	 * Get resolved CRM toolkit settings.
	 *
	 * @return array
	 */
	public static function get_toolkit_settings() {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}

		$defaults = array(
			'default_currency'        => 'USD',
			'default_lifecycle_stage' => 'lead',
			'qualification_framework' => 'bant',
			'hot_score_threshold'     => 70,
			'warm_score_threshold'    => 40,
			'audit_retention_days'    => 365,
			'consent'                 => array(
				'require_double_opt_in'   => true,
				'default_legal_basis'     => 'legitimate_interest',
				'unsubscribe_footer_text' => '',
				'physical_address'        => '',
			),
			'routing'                 => array(
				'strategy'    => 'round_robin',
				'pool'        => array(),
				'territories' => array(),
			),
			'sequences'               => array(
				'send_hours_local'        => array( 9, 18 ),
				'send_days'               => array( 1, 2, 3, 4, 5 ),
				'pause_on_reply'          => true,
				'pause_on_meeting_booked' => true,
			),
			'pipeline'                => array(
				'stages' => array(
					'qualification' => array(
						'label'       => 'Qualification',
						'probability' => 0.10,
					),
					'discovery'     => array(
						'label'       => 'Discovery',
						'probability' => 0.25,
					),
					'proposal'      => array(
						'label'       => 'Proposal',
						'probability' => 0.50,
					),
					'negotiation'   => array(
						'label'       => 'Negotiation',
						'probability' => 0.75,
					),
					'closed_won'    => array(
						'label'       => 'Closed-Won',
						'probability' => 1.00,
						'is_won'      => true,
					),
					'closed_lost'   => array(
						'label'       => 'Closed-Lost',
						'probability' => 0.00,
						'is_lost'     => true,
					),
				),
			),
			'integrations'            => array(
				// Twilio (SMS outbound + inbound webhook).
				'twilio_account_sid_secret' => '',
				'twilio_auth_token_secret'  => '',
				'twilio_from_number'        => '', // E.164 format, e.g. +1234567890
				// WhatsApp (Meta Cloud API outbound + inbound webhook).
				'whatsapp_access_token'     => '',
				'whatsapp_phone_number_id'  => '',
				'whatsapp_app_secret'       => '', // For webhook signature validation
				// notify.lk (Sri Lanka SMS gateway).
				'notifylk_user_id'          => '',
				'notifylk_api_key'          => '',
				'notifylk_sender_id'        => '',
				// OAuth handles for IMAP/Gmail/Outlook.
				'gmail_oauth_handle'        => '',
				'outlook_oauth_handle'      => '',
				// Default SMS provider: 'twilio' | 'notifylk'.
				'sms_provider'              => 'twilio',
				// Default Gmail import query (Gmail search syntax).
				'gmail_default_query'       => 'newer_than:7d is:unread',
				// Gmail scheduled import settings (since 2.9.0).
				'gmail_poll_interval'       => 300, // Seconds (60–3600). Default 5 minutes.
				'gmail_max_per_poll'        => 10,  // Max messages per poll cycle (1–25).
				'gmail_use_history_sync'    => true, // Use incremental historyId sync.
				// Email-to-Case routing rules (since 2.9.0).
				'email_to_case_rules'       => array(),
			),

			'search'                  => array(
				'algorithm'       => 'keyword_tfidf',
				'default_orderby' => 'relevance',
				'min_relevance'   => 0,
				'field_weights'   => array(
					'name'    => 3.0,
					'company' => 2.0,
					'email'   => 1.5,
				),
			),
			'research_assistant'      => 'default',
			// Storage backend preference: 'auto', 'cct', or 'cpt'.
			'storage_backend'         => 'auto',
			// SLA targets for support tickets (P1–P4, minutes).
			'sla'                     => array(
				'p1_first_response_minutes'  => 15,
				'p1_resolution_minutes'      => 240,
				'p2_first_response_minutes'  => 60,
				'p2_resolution_minutes'      => 480,
				'p3_first_response_minutes'  => 240,
				'p3_resolution_minutes'      => 1440,
				'p4_first_response_minutes'  => 480,
				'p4_resolution_minutes'      => 4320,
				'business_hours_start'       => '09:00',
				'business_hours_end'         => '17:00',
				'business_days'              => array( 1, 2, 3, 4, 5 ), // Mon–Fri.
				'auto_close_resolved_days'   => 3,
				'auto_escalate_waiting_days' => 3,
			),
			// Support module settings.
			'support'                 => array(
				'default_assignee_id' => 0,
				'ticket_categories'   => array( 'Bug', 'Question', 'Feature Request', 'Account', 'Billing', 'Other' ),
				'resolution_types'    => array( 'Solved', 'Not Reproducible', "Won't Fix", 'Duplicate', 'Third Party' ),
			),
			// Email-to-Case routing (since 2.9.0).
			'email_to_case'           => array(
				'enabled'              => true,
				'default_priority'     => 'p2_high',
				'default_category'     => 'question',
				// Subject-based routing: keyword → (category, priority).
				'subject_rules'        => array(),
				// Sender-based routing: email/domain → (category, priority, assignee).
				'sender_rules'         => array(),
			),
			// Performance optimization (since 2.9.0).
			'optimization'            => array(
				'message_retention_days' => 90,  // Auto-prune CRM messages older than N days (0 = keep forever).
				'audit_max_entries'     => 5000, // Max audit log entries before compaction.
			),
		);

		$stored = get_option( self::SETTINGS_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = array_replace_recursive( $defaults, $stored );

		/**
		 * Filter the resolved CRM toolkit settings.
		 *
		 * @param array $settings Merged settings (defaults + stored).
		 */
		$settings = apply_filters( 'wp_mcp_ai_crm_toolkit_settings', $settings );
		if ( ! is_array( $settings ) ) {
			$settings = $defaults;
		}

		self::$settings_cache = $settings;
		return self::$settings_cache;
	}

	/**
	 * Flush the settings cache (used after saving settings in admin).
	 *
	 * @return void
	 */
	public static function flush_settings_cache() {
		self::$settings_cache = null;
		self::$hygiene_cache  = null;
	}

	/*
	---------------------------------------------------------------------
	* Email Hygiene Settings
	* ------------------------------------------------------------------
	*/

	/**
	 * Hygiene settings option key.
	 *
	 * @var string
	 */
	const HYGIENE_OPTION = 'wp_mcp_ai_crm_hygiene_settings';

	/**
	 * Cached hygiene settings.
	 *
	 * @var array|null
	 */
	private static $hygiene_cache = null;

	/**
	 * Get resolved email hygiene settings.
	 *
	 * @since 2.8.0
	 * @return array
	 */
	public static function get_hygiene_settings() {
		if ( null !== self::$hygiene_cache ) {
			return self::$hygiene_cache;
		}

		$defaults = array(
			'exclude_list'          => array(),
			'priority_list'         => array(),
			'spam_domains'          => array(),
			'promotional_domains'   => array(),
			'priority_domains'      => array(),
			'promotional_keywords'  => array(),
			'auto_prune_spam'       => true,
			'auto_prune_stale_days' => 0, // 0 = disabled.
			'auto_prune_excluded'   => true,
		);

		$stored = get_option( self::HYGIENE_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = array_merge( $defaults, $stored );

		/**
		 * Filter: override resolved email hygiene settings.
		 *
		 * @since 2.8.0
		 * @param array $settings Merged hygiene settings.
		 */
		$settings = apply_filters( 'wp_mcp_ai_crm_hygiene_settings', $settings );
		if ( ! is_array( $settings ) ) {
			$settings = $defaults;
		}

		self::$hygiene_cache = $settings;
		return self::$hygiene_cache;
	}

	/*
	---------------------------------------------------------------------
	* Lifecycle stages
	* ------------------------------------------------------------------
	*/

	/**
	 * Canonical lifecycle stages (HubSpot-aligned).
	 *
	 * @var string[]
	 */
	const LIFECYCLE_STAGES = array(
		'subscriber',
		'lead',
		'mql',     // Marketing Qualified Lead.
		'sal',     // Sales Accepted Lead.
		'sql',     // Sales Qualified Lead.
		'opportunity',
		'customer',
		'evangelist',
		'other',
	);

	/**
	 * Check whether a lifecycle stage is valid.
	 *
	 * @param string $stage Stage slug.
	 * @return bool
	 */
	public static function is_valid_lifecycle_stage( $stage ) {
		return in_array( sanitize_key( $stage ), self::LIFECYCLE_STAGES, true );
	}

	/**
	 * Return the next stage in the lifecycle progression, or null if terminal.
	 *
	 * @param string $stage Current stage slug.
	 * @return string|null
	 */
	public static function next_lifecycle_stage( $stage ) {
		$stage = sanitize_key( $stage );
		$pos   = array_search( $stage, self::LIFECYCLE_STAGES, true );
		if ( false === $pos || $pos >= count( self::LIFECYCLE_STAGES ) - 1 ) {
			return null;
		}
		return self::LIFECYCLE_STAGES[ $pos + 1 ];
	}

	/*
	---------------------------------------------------------------------
	* Lead scoring
	* ------------------------------------------------------------------
	*/

	/**
	 * Score label by numeric score bucket.
	 *
	 * Industry convention: 0-39 = Cold, 40-69 = Warm, 70-100 = Hot.
	 *
	 * @param int|null $score Numeric score or null.
	 * @return string 'cold' | 'warm' | 'hot' | 'unscored'
	 */
	public static function score_label( $score ) {
		if ( null === $score || ! is_numeric( $score ) ) {
			return 'unscored';
		}
		$score    = (int) $score;
		$settings = self::get_toolkit_settings();
		$hot      = (int) $settings['hot_score_threshold'];
		$warm     = (int) $settings['warm_score_threshold'];

		if ( $score >= $hot ) {
			return 'hot';
		}
		if ( $score >= $warm ) {
			return 'warm';
		}
		return 'cold';
	}

	/**
	 * Calculate a composite lead score from factor weights.
	 *
	 * @param array $factors Map of factor_key => value (0–100).
	 * @return int Composite score (0–100), clamped.
	 */
	public static function calculate_lead_score( array $factors ) {
		$default_weights = array(
			'fit'        => 0.35,   // company size, industry fit.
			'intent'     => 0.30,   // signals: pricing-page visit, demo request.
			'engagement' => 0.20,   // email opens, click-throughs, replies.
			'recency'    => 0.15,   // recent activity decay.
		);

		/**
		 * Filter the factor weights used for lead scoring.
		 *
		 * @param array $weights Map of factor_key => weight (0–1, all sum to 1).
		 */
		$weights = apply_filters( 'wp_mcp_ai_crm_score_factors', $default_weights );
		if ( ! is_array( $weights ) ) {
			$weights = $default_weights;
		}

		$score = 0;
		foreach ( $weights as $key => $weight ) {
			if ( isset( $factors[ $key ] ) ) {
				$score += (float) $weight * min( 100, max( 0, (int) $factors[ $key ] ) );
			}
		}

		/**
		 * Fires after a lead score has been calculated.
		 *
		 * @param int   $score  Composite score (0–100).
		 * @param array $factors Raw factors.
		 */
		do_action( 'wp_mcp_ai_crm_lead_score_calculated', $score, $factors );

		return min( 100, max( 0, (int) round( $score ) ) );
	}

	/*
	---------------------------------------------------------------------
	* Pipeline helpers
	* ------------------------------------------------------------------
	*/

	/**
	 * Get pipeline stages definition.
	 *
	 * @return array Map of stage_id => array( label, probability, is_won?, is_lost? ).
	 */
	public static function get_pipeline_stages() {
		$settings = self::get_toolkit_settings();
		$stages   = isset( $settings['pipeline']['stages'] ) ? $settings['pipeline']['stages'] : array();

		/**
		 * Filter the pipeline stage definitions.
		 *
		 * @param array $stages Stage map.
		 */
		$stages = apply_filters( 'wp_mcp_ai_crm_pipeline_stages', $stages );
		return is_array( $stages ) ? $stages : array();
	}

	/**
	 * Get the win probability for a given pipeline stage.
	 *
	 * @param string $stage_id Stage slug.
	 * @return float Probability (0–1), default 0.
	 */
	public static function stage_probability( $stage_id ) {
		$stages = self::get_pipeline_stages();
		if ( isset( $stages[ $stage_id ]['probability'] ) ) {
			return (float) $stages[ $stage_id ]['probability'];
		}
		return 0.0;
	}

	/*
	---------------------------------------------------------------------
	* Routing
	* ------------------------------------------------------------------
	*/

	/**
	 * Resolve the next owner for a lead based on the active routing strategy.
	 *
	 * @return int WordPress user ID, or 0 if no pool is configured.
	 */
	public static function get_next_owner() {
		$settings = self::get_toolkit_settings();
		$strategy = sanitize_key( $settings['routing']['strategy'] );
		$pool     = isset( $settings['routing']['pool'] ) ? (array) $settings['routing']['pool'] : array();
		$pool     = array_filter( $pool, 'absint' );

		if ( empty( $pool ) ) {
			return 0;
		}

		/**
		 * Filter: override routing strategy at runtime.
		 *
		 * Return a WP user ID > 0 to short-circuit the strategy below.
		 *
		 * @param int|false $user_id User ID or false to use the default strategy.
		 * @param string   $strategy Current strategy slug.
		 * @param array    $pool     Current pool of user IDs.
		 */
		$override = apply_filters( 'wp_mcp_ai_crm_routing_strategy', false, $strategy, $pool );

		// --- Strategy: manual / filtered override ---
		if ( filter_var( $override, FILTER_VALIDATE_INT ) && (int) $override > 0 ) {
			// Verify the user is still in the pool.
			$override_id = (int) $override;
			if ( in_array( $override_id, $pool, true ) ) {
				return $override_id;
			}
		}

		// --- Strategy: weighted (stub — falls through to round_robin) ---
		if ( 'weighted' === $strategy ) {
			// Weighted: prefer owner with fewest active leads.
			$workloads = array();
			foreach ( $pool as $uid ) {
				$count             = self::count_active_leads( $uid );
				$workloads[ $uid ] = $count;
			}
			asort( $workloads );
			return (int) key( $workloads );
		}

		// --- Strategy: round_robin ---
		// Track the last assigned owner via a simple option counter.
		$counter_key = 'wp_mcp_ai_crm_routing_counter';
		$counter     = (int) get_option( $counter_key, 0 );
		$index       = $counter % count( $pool );
		$next_owner  = (int) $pool[ $index ];

		update_option( $counter_key, $counter + 1, false );

		return $next_owner;
	}

	/**
	 * Count active leads assigned to a user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int
	 */
	public static function count_active_leads( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return 0;
		}

		// Post-type agnostic: query by meta `contact_owner`.
		$query = new WP_Query(
			array(
				'post_type'      => array( 'mcp_crm_contacts', 'mcp_ai_lead' ),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => 'contact_owner',
					'value' => (string) $user_id,
				),
				),
				'no_found_rows'  => true,
			)
		);

		return $query->found_posts;
	}

	/*
	---------------------------------------------------------------------
	* Suppression / DNC
	* ------------------------------------------------------------------
	*/

	/**
	 * Internal DNC / suppression list.
	 *
	 * Stored as a simple option mapping identifier → channels.
	 *
	 * @param string $identifier Email address or phone number (E.164).
	 * @param string $channel    'email' | 'sms' | 'whatsapp' | 'all'.
	 * @return bool True if the identifier is suppressed for the given channel.
	 */
	public static function check_dnc( $identifier, $channel = 'all' ) {
		$dnc_list = get_option( 'wp_mcp_ai_crm_dnc_list', array() );
		if ( ! is_array( $dnc_list ) ) {
			return false;
		}

		$identifier = strtolower( trim( $identifier ) );
		if ( ! isset( $dnc_list[ $identifier ] ) ) {
			return false;
		}

		$suppressed_channels = (array) $dnc_list[ $identifier ];
		if ( in_array( 'all', $suppressed_channels, true ) || in_array( $channel, $suppressed_channels, true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Add an identifier to the internal DNC list.
	 *
	 * @param string $identifier Email or phone (E.164).
	 * @param string $channel    Channel slug or 'all'.
	 * @return void
	 */
	public static function add_to_dnc( $identifier, $channel = 'all' ) {
		$dnc_list = get_option( 'wp_mcp_ai_crm_dnc_list', array() );
		if ( ! is_array( $dnc_list ) ) {
			$dnc_list = array();
		}

		$identifier = strtolower( trim( $identifier ) );
		if ( ! isset( $dnc_list[ $identifier ] ) ) {
			$dnc_list[ $identifier ] = array();
		}
		$dnc_list[ $identifier ][] = sanitize_key( $channel );
		$dnc_list[ $identifier ]   = array_unique( $dnc_list[ $identifier ] );

		update_option( 'wp_mcp_ai_crm_dnc_list', $dnc_list, false );
	}

	/*
	---------------------------------------------------------------------
	* Currency helpers
	* ------------------------------------------------------------------
	*/

	/**
	 * Format an amount in the default currency.
	 *
	 * @param float  $amount   Monetary amount.
	 * @param string $currency ISO 4217 code (defaults to toolkit setting).
	 * @return string
	 */
	public static function format_currency( $amount, $currency = '' ) {
		if ( empty( $currency ) ) {
			$settings = self::get_toolkit_settings();
			$currency = $settings['default_currency'];
		}

		$symbols = array(
			'USD' => '$',
			'EUR' => '€',
			'GBP' => '£',
			'CAD' => 'C$',
			'AUD' => 'A$',
			'INR' => '₹',
		);

		$symbol = isset( $symbols[ $currency ] ) ? $symbols[ $currency ] : $currency . ' ';

		// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
		return $symbol . number_format_i18n( (float) $amount, 2 );
	}
}
