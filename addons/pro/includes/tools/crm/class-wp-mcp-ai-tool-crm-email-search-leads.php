<?php
/**
 * Tool for searching new leads via email-based criteria in the CRM.
 *
 * Implements industry-standard lead scoring, email categorization, and scheduling.
 * Supports cached results, WP Cron-driven refresh, and multi-remote connection
 * search for throughout-the-day querying across Gmail accounts and external CRMs.
 *
 * Industry references:
 * - HubSpot Lead Scoring & Lifecycle Stages (Subscriber → Lead → MQL → SQL → Opportunity)
 * - Salesforce Lead Management (Lead Object, Web-to-Lead, Lead Conversion)
 * - Monday.com Lead Qualification (high-intent: demo, trial, pricing, consultation)
 * - Nimble CRM Contact Form Integration (inquiry type capture best practices)
 * - Zoho CRM & Freshsales lead qualification workflows
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRM Email Search – New Leads Tool.
 *
 * Searches CRM contacts by email-based lead criteria following industry standards:
 * - Lead scoring (demographic, firmographic, behavioral signals)
 * - Email categorisation: new_inquiry, demo_request, pricing_inquiry, partnership,
 *   trial_request, support_request, referral, consultation_request, event_registration,
 *   content_download, general
 * - Multi-remote connection search across Gmail accounts via Remote Sites connections
 * - Filtered by email domain, source channel, lead status, priority, date range, or minimum lead score
 * - Results are cached (WP_MCP_AI_Cache_Helper) and optionally auto-refreshed via WP Cron
 *
 * Industry references: HubSpot Lead Scoring, Salesforce Lead Management,
 * Freshsales & Zoho CRM lead qualification best practices.
 *
 * @since 2.1.0
 */
class WP_MCP_AI_Tool_CRM_Email_Search_Leads implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * WP Cron hook for scheduled cache refresh.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'wp_mcp_ai_crm_email_search_leads_refresh';

	/**
	 * Cache key prefix for lead search results.
	 *
	 * @var string
	 */
	const CACHE_KEY_PREFIX = 'crm_leads_search_';

	/**
	 * Default cache TTL in seconds (1 hour).
	 *
	 * @var int
	 */
	const DEFAULT_CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Allowed lead status values (industry-standard pipeline stages).
	 *
	 * @var string[]
	 */
	const LEAD_STATUSES = array( 'new', 'contacted', 'qualified', 'unqualified', 'nurturing', 'converted', 'all' );

	/**
	 * Allowed inquiry type values (email categorisation).
	 *
	 * Industry-standard lead inquiry categories from HubSpot, Salesforce,
	 * Monday.com, and Nimble CRM best practices.
	 *
	 * High-intent (ready to buy): demo_request, trial_request, pricing_inquiry, consultation_request.
	 * MQL signals (marketing engaged): event_registration, content_download, newsletter_signup.
	 * Relationship: partnership, referral.
	 * Service: support_request, account_management.
	 *
	 * @var string[]
	 */
	const INQUIRY_TYPES = array(
		'new_inquiry',
		'demo_request',
		'pricing_inquiry',
		'partnership',
		'trial_request',
		'support_request',
		'referral',
		'consultation_request',
		'event_registration',
		'content_download',
		'newsletter_signup',
		'account_management',
		'general',
		'all',
	);

	/**
	 * Allowed lead source channels (industry-standard lead origin classification).
	 *
	 * Aligned with HubSpot Original Source / Salesforce Lead Source picklist values.
	 *
	 * @var string[]
	 */
	const SOURCE_CHANNELS = array(
		'web_form',
		'email_inbound',
		'phone_call',
		'chat',
		'social_media',
		'referral',
		'event',
		'campaign',
		'import',
		'api',
		'manual',
		'partner',
		'organic_search',
		'paid_search',
		'all',
	);

	/**
	 * Allowed lead priority/urgency values.
	 *
	 * Industry standard: high = immediate action, medium = this week,
	 * low = nurture queue.
	 *
	 * @var string[]
	 */
	const LEAD_PRIORITY = array( 'high', 'medium', 'low', 'all' );

	/**
	 * Allowed MQL/SQL pipeline stage values (industry-standard lead qualification).
	 *
	 * MQL = Marketing Qualified Lead (engaged with marketing content).
	 * SQL = Sales Qualified Lead (accepted by sales for active pursuit).
	 *
	 * @var string[]
	 */
	const MQL_STAGES = array( 'mql', 'sql', 'all' );

	/**
	 * Allowed cron schedule recurrences.
	 *
	 * @var string[]
	 */
	const CRON_SCHEDULES = array( 'hourly', 'twicedaily', 'daily' );

	/**
	 * Constructor – registers WP Cron callback.
	 */
	public function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_search' ) );
	}

	/**
	 * Determine whether this tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The CRM Email Search (Leads) tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
 * {@inheritdoc}
 */
	public function get_slug() {
		return 'crm_email_search_leads';
	}

	/**
 * {@inheritdoc}
 */
	public function get_name() {
		return __( 'CRM Email Search: New Leads', 'mcp-ai-wpoos-pro' );
	}

	/**
 * {@inheritdoc}
 */
	public function get_description() {
		return __( 'Search CRM contacts for new leads by email-based criteria including lead score, email domain, inquiry type, source channel, priority, and date range. Supports multi-remote Gmail connection search via Remote Sites connection IDs for cross-account lead discovery. Results are cached for efficient throughout-the-day querying and can be auto-refreshed on a WP Cron schedule. Implements industry-standard lead scoring (HubSpot/Salesforce), 14 inquiry type categories, and pipeline-stage filtering (MQL/SQL).', 'mcp-ai-wpoos-pro' );
	}

	/**
 * {@inheritdoc}
 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'          => array(
					'type'        => 'string',
					'enum'        => array( 'search', 'get_cached', 'clear_cache', 'schedule', 'unschedule' ),
					'description' => __( 'Action to perform: search (execute and cache), get_cached (return cached results), clear_cache (invalidate), schedule (register WP Cron refresh), unschedule (remove schedule).', 'mcp-ai-wpoos-pro' ),
					'default'     => 'search',
				),
				'lead_status'     => array(
					'type'        => 'string',
					'enum'        => self::LEAD_STATUSES,
					'description' => __( 'Pipeline stage filter. "new" = freshly added, "nurturing" = in drip sequence, "converted" = won. Defaults to "new".', 'mcp-ai-wpoos-pro' ),
					'default'     => 'new',
				),
				'inquiry_type'    => array(
					'type'        => 'string',
					'enum'        => self::INQUIRY_TYPES,
					'description' => __( 'Email category/inquiry type. Industry-standard categories: new_inquiry, demo_request, pricing_inquiry, partnership, trial_request, support_request, referral, consultation_request, event_registration, content_download, newsletter_signup, account_management, general. High-intent: demo_request, trial_request, pricing_inquiry, consultation_request. MQL signals: event_registration, content_download.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'all',
				),
				'email_domain'    => array(
					'type'        => 'string',
					'description' => __( 'Filter leads by email domain (e.g. "acmecorp.com"). Supports partial match.', 'mcp-ai-wpoos-pro' ),
				),
				'source'          => array(
						'type'        => 'string',
						'enum'        => self::SOURCE_CHANNELS,
						'description' => __( 'Lead source channel. Industry-standard HubSpot/Salesforce categories: web_form, email_inbound, phone_call, chat, social_media, referral, event, campaign, import, api, manual, partner, organic_search, paid_search.', 'mcp-ai-wpoos-pro' ),
						'default'     => 'all',
					),
				'lead_score_min'  => array(
					'type'        => 'integer',
					'description' => __( 'Minimum lead score (0-100). Industry standard: 0-39 cold, 40-69 warm, 70-100 hot.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 100,
				),
				'lead_score_max'  => array(
					'type'        => 'integer',
					'description' => __( 'Maximum lead score (0-100).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 100,
				),
				'date_from'       => array(
					'type'        => 'string',
					'description' => __( 'Include contacts added on or after this date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'date_to'         => array(
					'type'        => 'string',
					'description' => __( 'Include contacts added on or before this date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'per_page'        => array(
					'type'        => 'integer',
					'description' => __( 'Results per page (1–100).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				),
				'page'            => array(
					'type'        => 'integer',
					'description' => __( 'Page number.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'default'     => 1,
				),
				'cache_ttl'       => array(
					'type'        => 'integer',
					'description' => __( 'Cache lifetime in seconds (minimum 60, default 3600).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 60,
					'default'     => HOUR_IN_SECONDS,
				),
				'schedule'        => array(
					'type'        => 'string',
					'enum'        => self::CRON_SCHEDULES,
					'description' => __( 'WP Cron recurrence for automatic cache refresh (hourly, twicedaily, daily). Used with action=schedule.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'hourly',
				),
				'force_refresh'   => array(
					'type'        => 'boolean',
					'description' => __( 'When true, bypass any cached results and execute a fresh database query. Useful for on-demand data refreshes during the day.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'contact_owner'   => array(
					'type'        => 'string',
					'description' => __( 'Filter leads by assigned contact owner (WordPress username or email of the sales rep). Industry standard: HubSpot Contact Owner / Salesforce Lead Owner field.', 'mcp-ai-wpoos-pro' ),
				),
				'mql_stage'       => array(
					'type'        => 'string',
					'enum'        => self::MQL_STAGES,
					'description' => __( 'Lead qualification stage filter. "mql" = Marketing Qualified Lead (engaged with marketing content); "sql" = Sales Qualified Lead (accepted by sales for active pursuit); "all" = no stage filter. Industry standard: HubSpot lifecycle stages, Salesforce lead qualification workflow.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'all',
				),
				'priority'        => array(
					'type'        => 'string',
					'enum'        => self::LEAD_PRIORITY,
					'description' => __( 'Lead priority/urgency filter. "high" = immediate action required (hot leads), "medium" = this week, "low" = nurture queue. Industry standard across HubSpot, Salesforce, and Zoho CRM.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'all',
				),
				'connection_ids'  => array(
					'type'        => 'array',
					'description' => __( 'Optional array of Remote Sites Gmail connection IDs to search for leads across multiple email accounts. When provided, the tool queries each Gmail account for matching inbound emails and merges the results with local CRM contacts. Each connection must be of type "gmail".', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'include_external' => array(
					'type'        => 'boolean',
					'description' => __( 'When true, searches external Gmail connections (using connection_ids or all configured Gmail connections) in addition to local CRM contacts. When false, only searches the local CRM database.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
 * {@inheritdoc}
 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
 * {@inheritdoc}
 */
	public function requires_base_pro() {
		return true;
	}

	/**
 * {@inheritdoc}
 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
			'requires-capability',
		);
	}

	/**
	 * Get extended tool definition (toolkit metadata).
	 *
	 * @since 2.1.0
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'crm',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'sales_manager', 'business_development', 'marketing_manager' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_tool_unavailable', self::get_unavailable_reason() );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to search CRM leads.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'search';

		switch ( $action ) {
			case 'search':
				return $this->run_search( $arguments );
			case 'get_cached':
				return $this->get_cached_results( $arguments );
			case 'clear_cache':
				return $this->clear_cached_results( $arguments );
			case 'schedule':
				return $this->schedule_search( $arguments );
			case 'unschedule':
				return $this->unschedule_search();
			default:
				return new WP_Error(
					'wp_mcp_ai_invalid_action',
					__( 'Invalid action. Must be one of: search, get_cached, clear_cache, schedule, unschedule.', 'mcp-ai-wpoos-pro' )
				);
		}
	}

	// -------------------------------------------------------------------------
	// Action handlers.
	// -------------------------------------------------------------------------

	/**
	 * Execute search using cache-aside pattern (industry standard for throughout-the-day querying).
	 *
	 * Returns cached results when available unless force_refresh is requested.
	 * Always re-caches the results after a fresh database query.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	private function run_search( array $arguments ) {
		$filters       = $this->extract_filters( $arguments );
		$cache_key     = $this->build_cache_key( $filters );
		$cache_ttl     = isset( $arguments['cache_ttl'] ) ? max( 60, absint( $arguments['cache_ttl'] ) ) : self::DEFAULT_CACHE_TTL;
		$force_refresh = ! empty( $arguments['force_refresh'] );
		$include_external = ! empty( $arguments['include_external'] );

		// External search is never cached (always live).
		if ( ! $include_external && ! $force_refresh ) {
			$cached = $this->cache_get( $cache_key );
			if ( false !== $cached ) {
				$cached['from_cache']    = true;
				$cached['force_refresh'] = false;
				return $cached;
			}
		}

		$results = $this->query_leads( $filters );

		// Merge external Gmail search results when requested.
		if ( $include_external ) {
			$external_results = $this->search_external_gmail( $arguments );
			if ( is_array( $external_results ) && ! empty( $external_results['leads'] ) ) {
				$results['leads'] = array_merge( $results['leads'], $external_results['leads'] );
				$results['total'] = count( $results['leads'] );
				$results['external'] = $external_results['external'] ?? array();
			}
		}

		$this->cache_set( $cache_key, $results, $cache_ttl );

		$results['cached']        = true;
		$results['cache_ttl']     = $cache_ttl;
		$results['cached_at']     = current_time( 'mysql' );
		$results['cache_key']     = $cache_key;
		$results['force_refresh'] = $force_refresh;

		return $results;
	}

	/**
	 * Return previously cached results without re-querying.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	private function get_cached_results( array $arguments ) {
		$filters   = $this->extract_filters( $arguments );
		$cache_key = $this->build_cache_key( $filters );
		$cached    = $this->cache_get( $cache_key );

		if ( false === $cached ) {
			return new WP_Error(
				'wp_mcp_ai_crm_leads_no_cache',
				__( 'No cached results found. Run action=search first to populate the cache.', 'mcp-ai-wpoos-pro' )
			);
		}

		$cached['from_cache'] = true;
		return $cached;
	}

	/**
	 * Invalidate the cached results for the given filter set.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	private function clear_cached_results( array $arguments ) {
		$filters   = $this->extract_filters( $arguments );
		$cache_key = $this->build_cache_key( $filters );
		$this->cache_delete( $cache_key );

		return array(
			'success' => true,
			'message' => __( 'Lead search cache cleared successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Register a WP Cron event to auto-refresh the cached lead search.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	private function schedule_search( array $arguments ) {
		$recurrence = isset( $arguments['schedule'] ) ? sanitize_key( $arguments['schedule'] ) : 'hourly';

		if ( ! in_array( $recurrence, self::CRON_SCHEDULES, true ) ) {
			$recurrence = 'hourly';
		}

		$filters = $this->extract_filters( $arguments );
		update_option( 'wp_mcp_ai_crm_leads_search_params', $filters, false );

		// Remove any existing schedule before registering a new one.
		$existing = wp_next_scheduled( self::CRON_HOOK );
		if ( $existing ) {
			wp_unschedule_event( $existing, self::CRON_HOOK );
		}

		$result = wp_schedule_event( time(), $recurrence, self::CRON_HOOK );

		if ( false === $result ) {
			return new WP_Error(
				'wp_mcp_ai_crm_leads_schedule_failed',
				__( 'Failed to schedule the lead search cron event.', 'mcp-ai-wpoos-pro' )
			);
		}

		return array(
			'success'    => true,
			/* translators: %s: cron recurrence label (hourly / twicedaily / daily) */
			'message'    => sprintf( __( 'Lead search scheduled to auto-refresh %s.', 'mcp-ai-wpoos-pro' ), $recurrence ),
			'recurrence' => $recurrence,
			'hook'       => self::CRON_HOOK,
			'next_run'   => wp_next_scheduled( self::CRON_HOOK ),
		);
	}

	/**
	 * Remove the scheduled WP Cron refresh.
	 *
	 * @return array
	 */
	private function unschedule_search() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}

		delete_option( 'wp_mcp_ai_crm_leads_search_params' );

		return array(
			'success' => true,
			'message' => __( 'Lead search schedule removed.', 'mcp-ai-wpoos-pro' ),
		);
	}

	// -------------------------------------------------------------------------
	// Cron callback.
	// -------------------------------------------------------------------------

	/**
	 * WP Cron callback – refresh cached lead search results.
	 *
	 * @return void
	 */
	public function run_scheduled_search() {
		$filters = get_option( 'wp_mcp_ai_crm_leads_search_params', array() );
		if ( empty( $filters ) ) {
			return;
		}

		$cache_key = $this->build_cache_key( $filters );
		$results   = $this->query_leads( $filters );

		$results['cached']    = true;
		$results['cached_at'] = current_time( 'mysql' );
		$results['cache_key'] = $cache_key;

		$this->cache_set( $cache_key, $results, self::DEFAULT_CACHE_TTL );

		/**
		 * Fires after a scheduled CRM lead search cache refresh completes.
		 *
		 * @since 2.1.0
		 *
		 * @param array $results Refreshed results.
		 * @param array $filters Filters used for the query.
		 */
		do_action( 'wp_mcp_ai_crm_leads_search_refreshed', $results, $filters );
	}

	// -------------------------------------------------------------------------
	// Core query.
	// -------------------------------------------------------------------------

	/**
	 * Query CRM contact records for new leads.
	 *
	 * Applies lead-score range, inquiry type, status, source, domain, and date filters
	 * in line with industry-standard lead management practices.
	 *
	 * @param array $filters Sanitised filter parameters.
	 * @return array
	 */
	private function query_leads( array $filters ) {
		$per_page    = min( max( absint( $filters['per_page'] ), 1 ), 100 );
		$page        = max( absint( $filters['page'] ), 1 );
		$lead_status = $filters['lead_status'];

		$query_args = array(
			'post_type'      => 'mcp_crm_contacts',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$meta_query = array( 'relation' => 'AND' );

		// Lead status / pipeline stage.
		if ( 'all' !== $lead_status ) {
			$meta_query[] = array(
				'key'     => 'lead_status',
				'value'   => sanitize_key( $lead_status ),
				'compare' => '=',
			);
		}

		// Inquiry type / email category.
		if ( ! empty( $filters['inquiry_type'] ) && 'all' !== $filters['inquiry_type'] ) {
			$meta_query[] = array(
				'key'     => 'inquiry_type',
				'value'   => sanitize_key( $filters['inquiry_type'] ),
				'compare' => '=',
			);
		}

		// Lead source channel.
		if ( ! empty( $filters['source'] ) && 'all' !== $filters['source'] ) {
			$meta_query[] = array(
				'key'     => 'source',
				'value'   => sanitize_key( $filters['source'] ),
				'compare' => '=',
			);
		}

		// Priority/urgency filter.
		if ( ! empty( $filters['priority'] ) && 'all' !== $filters['priority'] ) {
			$meta_query[] = array(
				'key'     => 'priority',
				'value'   => sanitize_key( $filters['priority'] ),
				'compare' => '=',
			);
		}

		// MQL/SQL stage (industry-standard: HubSpot lifecycle stage / Salesforce lead qualification).
		if ( ! empty( $filters['mql_stage'] ) && 'all' !== $filters['mql_stage'] ) {
			$meta_query[] = array(
				'key'     => 'mql_stage',
				'value'   => sanitize_key( $filters['mql_stage'] ),
				'compare' => '=',
			);
		}

		// Contact owner / assigned sales rep (HubSpot Contact Owner / Salesforce Lead Owner).
		if ( ! empty( $filters['contact_owner'] ) ) {
			$meta_query[] = array(
				'key'     => 'contact_owner',
				'value'   => sanitize_text_field( $filters['contact_owner'] ),
				'compare' => '=',
			);
		}

		// Lead score range (industry-standard: 0-39 cold, 40-69 warm, 70-100 hot).
		if ( isset( $filters['lead_score_min'] ) || isset( $filters['lead_score_max'] ) ) {
			$score_clause = array(
				'key'  => 'lead_score',
				'type' => 'NUMERIC',
			);
			if ( isset( $filters['lead_score_min'] ) && isset( $filters['lead_score_max'] ) ) {
				$score_clause['value']   = array( absint( $filters['lead_score_min'] ), absint( $filters['lead_score_max'] ) );
				$score_clause['compare'] = 'BETWEEN';
			} elseif ( isset( $filters['lead_score_min'] ) ) {
				$score_clause['value']   = absint( $filters['lead_score_min'] );
				$score_clause['compare'] = '>=';
			} else {
				$score_clause['value']   = absint( $filters['lead_score_max'] );
				$score_clause['compare'] = '<=';
			}
			$meta_query[] = $score_clause;
		}

		if ( count( $meta_query ) > 1 ) {
			$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required for CRM lead filtering on indexed meta fields.
		}

		// Date range filter.
		$date_query = array();
		if ( ! empty( $filters['date_from'] ) ) {
			$date_query['after'] = sanitize_text_field( $filters['date_from'] );
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$date_query['before']    = sanitize_text_field( $filters['date_to'] );
			$date_query['inclusive'] = true;
		}
		if ( ! empty( $date_query ) ) {
			$query_args['date_query'] = array( $date_query );
		}

		$query = new WP_Query( $query_args );
		$leads = array();

		foreach ( $query->posts as $post ) {
			$email = (string) get_post_meta( $post->ID, 'email', true );

			// Email domain filter (partial match on the domain part).
			if ( ! empty( $filters['email_domain'] ) ) {
				$domain = sanitize_text_field( $filters['email_domain'] );
				// Ensure the '@' boundary is respected.
				if ( false === strpos( $email, '@' . $domain ) ) {
					continue;
				}
			}

			$raw_score   = get_post_meta( $post->ID, 'lead_score', true );
			$lead_score  = is_numeric( $raw_score ) ? (int) $raw_score : null;
			$score_label = $this->score_label( $lead_score );

			$leads[] = array(
				'id'            => $post->ID,
				'name'          => $post->post_title,
				'email'         => sanitize_email( $email ),
				'first_name'    => sanitize_text_field( (string) get_post_meta( $post->ID, 'first_name', true ) ),
				'last_name'     => sanitize_text_field( (string) get_post_meta( $post->ID, 'last_name', true ) ),
				'company'       => sanitize_text_field( (string) get_post_meta( $post->ID, 'company', true ) ),
				'lead_status'   => sanitize_key( (string) get_post_meta( $post->ID, 'lead_status', true ) ),
				'inquiry_type'  => sanitize_key( (string) get_post_meta( $post->ID, 'inquiry_type', true ) ),
				'mql_stage'     => sanitize_key( (string) get_post_meta( $post->ID, 'mql_stage', true ) ),
				'priority'      => sanitize_key( (string) get_post_meta( $post->ID, 'priority', true ) ),
				'contact_owner' => sanitize_text_field( (string) get_post_meta( $post->ID, 'contact_owner', true ) ),
				'source'        => sanitize_key( (string) get_post_meta( $post->ID, 'source', true ) ),
				'lead_score'    => $lead_score,
				'score_label'   => $score_label,
				'added_date'    => $post->post_date,
				'edit_url'      => get_edit_post_link( $post->ID, 'raw' ),
				'origin'        => 'local',
			);
		}

		return array(
			'success'  => true,
			'leads'    => $leads,
			'total'    => $query->found_posts,
			'per_page' => $per_page,
			'page'     => $page,
			'pages'    => max( 1, $query->max_num_pages ),
			'filters'  => $filters,
			'scoring'  => array(
				'cold' => '0–39',
				'warm' => '40–69',
				'hot'  => '70–100',
			),
		);
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Return a human-readable label for a numeric lead score.
	 *
	 * Industry convention: 0-39 = Cold, 40-69 = Warm, 70-100 = Hot.
	 *
	 * @param int|null $score Numeric lead score or null when not set.
	 * @return string
	 */
	private function score_label( $score ) {
		if ( null === $score ) {
			return __( 'unscored', 'mcp-ai-wpoos-pro' );
		}
		if ( $score >= 70 ) {
			return __( 'hot', 'mcp-ai-wpoos-pro' );
		}
		if ( $score >= 40 ) {
			return __( 'warm', 'mcp-ai-wpoos-pro' );
		}
		return __( 'cold', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Extract and sanitise filter parameters from raw tool arguments.
	 *
	 * @param array $arguments Raw arguments passed to execute().
	 * @return array Sanitised filter set.
	 */
	private function extract_filters( array $arguments ) {
		$lead_status = isset( $arguments['lead_status'] ) ? sanitize_key( $arguments['lead_status'] ) : 'new';
		if ( ! in_array( $lead_status, self::LEAD_STATUSES, true ) ) {
			$lead_status = 'new';
		}

		$inquiry_type = isset( $arguments['inquiry_type'] ) ? sanitize_key( $arguments['inquiry_type'] ) : 'all';
		if ( ! in_array( $inquiry_type, self::INQUIRY_TYPES, true ) ) {
			$inquiry_type = 'all';
		}

		$mql_stage = isset( $arguments['mql_stage'] ) ? sanitize_key( $arguments['mql_stage'] ) : 'all';
		if ( ! in_array( $mql_stage, self::MQL_STAGES, true ) ) {
			$mql_stage = 'all';
		}

		// Source channel with enum validation (HubSpot/Salesforce standard).
		$source = isset( $arguments['source'] ) ? sanitize_key( $arguments['source'] ) : 'all';
		if ( ! in_array( $source, self::SOURCE_CHANNELS, true ) ) {
			$source = 'all';
		}

		// Lead priority with enum validation.
		$priority = isset( $arguments['priority'] ) ? sanitize_key( $arguments['priority'] ) : 'all';
		if ( ! in_array( $priority, self::LEAD_PRIORITY, true ) ) {
			$priority = 'all';
		}

		$filters = array(
			'lead_status'   => $lead_status,
			'inquiry_type'  => $inquiry_type,
			'mql_stage'     => $mql_stage,
			'contact_owner' => isset( $arguments['contact_owner'] ) ? sanitize_text_field( $arguments['contact_owner'] ) : '',
			'email_domain'  => isset( $arguments['email_domain'] ) ? sanitize_text_field( $arguments['email_domain'] ) : '',
			'source'        => $source,
			'priority'      => $priority,
			'date_from'     => isset( $arguments['date_from'] ) ? sanitize_text_field( $arguments['date_from'] ) : '',
			'date_to'       => isset( $arguments['date_to'] ) ? sanitize_text_field( $arguments['date_to'] ) : '',
			'per_page'      => isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20,
			'page'          => isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1,
		);

		if ( isset( $arguments['lead_score_min'] ) ) {
			$filters['lead_score_min'] = min( 100, absint( $arguments['lead_score_min'] ) );
		}
		if ( isset( $arguments['lead_score_max'] ) ) {
			$filters['lead_score_max'] = min( 100, absint( $arguments['lead_score_max'] ) );
		}

		return $filters;
	}

	/**
	 * Build a stable, deterministic cache key from a filter set.
	 *
	 * @param array $filters Sanitised filters.
	 * @return string Cache key (without WP_MCP_AI_Cache_Helper prefix).
	 */
	private function build_cache_key( array $filters ) {
		ksort( $filters );
		return self::CACHE_KEY_PREFIX . md5( (string) wp_json_encode( $filters ) );
	}

	/**
	 * Store a value in the plugin cache (Cache_Helper → transient fallback).
	 *
	 * @param string $key        Cache key.
	 * @param mixed  $value      Data to cache.
	 * @param int    $expiration TTL in seconds.
	 * @return void
	 */
	private function cache_set( $key, $value, $expiration ) {
		if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
			WP_MCP_AI_Cache_Helper::set( $key, $value, $expiration );
		} else {
			set_transient( 'wp_mcp_ai_' . $key, $value, $expiration );
		}
	}

	/**
	 * Retrieve a cached value (Cache_Helper → transient fallback).
	 *
	 * @param string $key Cache key.
	 * @return mixed|false
	 */
	private function cache_get( $key ) {
		if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
			return WP_MCP_AI_Cache_Helper::get( $key );
		}
		return get_transient( 'wp_mcp_ai_' . $key );
	}

	/**
	 * Delete a cached value (Cache_Helper → transient fallback).
	 *
	 * @param string $key Cache key.
	 * @return void
	 */
	private function cache_delete( $key ) {
		if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
			WP_MCP_AI_Cache_Helper::delete( $key );
		} else {
			delete_transient( 'wp_mcp_ai_' . $key );
		}
	}

	// -------------------------------------------------------------------------
	// Multi-remote external Gmail search.
	// -------------------------------------------------------------------------

	/**
	 * Search external Gmail accounts for lead emails via Remote Sites connections.
	 *
	 * Uses the Gmail API to search across one or more configured Gmail connections.
	 * Results are normalised to the same lead format as local CRM contacts.
	 *
	 * @since 2.2.0
	 *
	 * @param array $arguments Tool arguments including connection_ids and search params.
	 * @return array|WP_Error External leads or error.
	 */
	private function search_external_gmail( array $arguments ) {
		$connection_ids = $this->resolve_connection_ids( $arguments );

		if ( empty( $connection_ids ) ) {
			return array( 'leads' => array(), 'external' => array() );
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return new WP_Error(
				'wp_mcp_ai_crm_remote_manager_missing',
				__( 'Remote Site Manager is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$all_leads   = array();
		$external_meta = array();

		foreach ( $connection_ids as $connection_id ) {
			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
			if ( empty( $connection ) || empty( $connection['connection_type'] ) || 'gmail' !== $connection['connection_type'] ) {
				$external_meta[ $connection_id ] = array(
					'status'  => 'skipped',
					'reason'  => 'not_gmail_connection',
				);
				continue;
			}

			$client_id     = isset( $connection['client_id'] ) ? trim( (string) $connection['client_id'] ) : '';
			$client_secret = isset( $connection['client_secret'] ) ? trim( (string) $connection['client_secret'] ) : '';
			$refresh_token = isset( $connection['refresh_token'] ) ? trim( (string) $connection['refresh_token'] ) : '';
			$user_email    = isset( $connection['user_email'] ) ? trim( (string) $connection['user_email'] ) : '';

			// Decrypt encrypted fields.
			if ( ! empty( $client_secret ) ) {
				$client_secret = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $client_secret );
			}
			if ( ! empty( $refresh_token ) ) {
				$refresh_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $refresh_token );
			}

			if ( '' === $client_id || '' === $client_secret || '' === $refresh_token ) {
				$external_meta[ $connection_id ] = array(
					'status'  => 'skipped',
					'reason'  => 'missing_credentials',
					'label'   => isset( $connection['name'] ) ? $connection['name'] : $connection_id,
				);
				continue;
			}

			// Build Gmail search query from lead filter arguments.
			$gmail_query = $this->build_gmail_search_query( $arguments );

			// Obtain access token.
			$access_token = $this->request_gmail_access_token( $client_id, $client_secret, $refresh_token );
			if ( is_wp_error( $access_token ) ) {
				$external_meta[ $connection_id ] = array(
					'status' => 'error',
					'error'  => $access_token->get_error_message(),
					'label'  => isset( $connection['name'] ) ? $connection['name'] : $connection_id,
				);
				continue;
			}

			$gmail_user = '' !== $user_email ? $user_email : 'me';
			$max_results = isset( $arguments['per_page'] ) ? min( 50, absint( $arguments['per_page'] ) ) : 20;

			$list_url = 'https://gmail.googleapis.com/gmail/v1/users/' . rawurlencode( $gmail_user ) . '/messages'
				. '?q=' . rawurlencode( $gmail_query )
				. '&maxResults=' . $max_results;

			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$timeout  = isset( $settings['request_timeout'] ) ? max( 5, absint( $settings['request_timeout'] ) ) : 30;

			$response = wp_remote_get(
				$list_url,
				array(
					'timeout' => $timeout,
					'headers' => array(
						'Accept'        => 'application/json',
						'Authorization' => 'Bearer ' . $access_token,
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				$external_meta[ $connection_id ] = array(
					'status' => 'error',
					'error'  => $response->get_error_message(),
					'label'  => isset( $connection['name'] ) ? $connection['name'] : $connection_id,
				);
				continue;
			}

			$status_code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $status_code ) {
				$external_meta[ $connection_id ] = array(
					'status' => 'error',
					'error'  => sprintf( __( 'HTTP %d from Gmail API.', 'mcp-ai-wpoos-pro' ), $status_code ),
					'label'  => isset( $connection['name'] ) ? $connection['name'] : $connection_id,
				);
				continue;
			}

			$body         = wp_remote_retrieve_body( $response );
			$list_payload = json_decode( $body, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $list_payload ) ) {
				$external_meta[ $connection_id ] = array(
					'status' => 'error',
					'error'  => __( 'Invalid JSON from Gmail API.', 'mcp-ai-wpoos-pro' ),
					'label'  => isset( $connection['name'] ) ? $connection['name'] : $connection_id,
				);
				continue;
			}

			$messages_found = 0;
			if ( ! empty( $list_payload['messages'] ) && is_array( $list_payload['messages'] ) ) {
				foreach ( $list_payload['messages'] as $message_ref ) {
					if ( empty( $message_ref['id'] ) ) {
						continue;
					}

					// Fetch minimal details (headers only) for performance.
					$detail_url = 'https://gmail.googleapis.com/gmail/v1/users/' . rawurlencode( $gmail_user )
						. '/messages/' . rawurlencode( $message_ref['id'] )
						. '?format=metadata&metadataHeaders=From&metadataHeaders=Subject&metadataHeaders=Date';

					$detail_response = wp_remote_get(
						$detail_url,
						array(
							'timeout' => $timeout,
							'headers' => array(
								'Accept'        => 'application/json',
								'Authorization' => 'Bearer ' . $access_token,
							),
						)
					);

					if ( is_wp_error( $detail_response ) || 200 !== (int) wp_remote_retrieve_response_code( $detail_response ) ) {
						continue;
					}

					$detail_body = wp_remote_retrieve_body( $detail_response );
					$msg_data    = json_decode( $detail_body, true );
					if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $msg_data ) ) {
						continue;
					}

					$from    = '';
					$subject = '';
					$date    = '';
					$snippet = isset( $msg_data['snippet'] ) ? sanitize_text_field( $msg_data['snippet'] ) : '';

					if ( ! empty( $msg_data['payload']['headers'] ) && is_array( $msg_data['payload']['headers'] ) ) {
						foreach ( $msg_data['payload']['headers'] as $header ) {
							$name  = isset( $header['name'] ) ? strtolower( $header['name'] ) : '';
							$value = isset( $header['value'] ) ? $header['value'] : '';
							if ( 'from' === $name ) {
								$from = $value;
							} elseif ( 'subject' === $name ) {
								$subject = $value;
							} elseif ( 'date' === $name ) {
								$date = $value;
							}
						}
					}

					// Extract name and email from "From" header.
					$from_name  = '';
					$from_email = '';
					if ( preg_match( '/^([^<]+)<([^>]+)>/', $from, $matches ) ) {
						$from_name  = trim( $matches[1] );
						$from_email = trim( $matches[2] );
					} elseif ( '' !== $from ) {
						$from_email = trim( $from );
					}

					$all_leads[] = array(
						'id'           => 'gmail:' . $message_ref['id'],
						'name'         => sanitize_text_field( $from_name ),
						'email'        => sanitize_email( $from_email ),
						'first_name'   => '',
						'last_name'    => '',
						'company'      => '',
						'lead_status'  => 'new',
						'inquiry_type' => 'new_inquiry',
						'mql_stage'    => '',
						'priority'     => '',
						'contact_owner' => '',
						'source'       => 'email_inbound',
						'lead_score'   => null,
						'score_label'  => __( 'unscored', 'mcp-ai-wpoos-pro' ),
						'added_date'   => sanitize_text_field( $date ),
						'edit_url'     => '',
						'origin'       => 'external',
						'origin_label' => isset( $connection['name'] ) ? $connection['name'] : $connection_id,
						'gmail_subject' => sanitize_text_field( $subject ),
						'gmail_snippet' => $snippet,
					);
					$messages_found++;
				}
			}

			$external_meta[ $connection_id ] = array(
				'status'        => 'success',
				'label'         => isset( $connection['name'] ) ? $connection['name'] : $connection_id,
				'messages_found' => $messages_found,
				'query'         => $gmail_query,
			);
		}

		return array(
			'leads'    => $all_leads,
			'external' => $external_meta,
		);
	}

	/**
	 * Resolve which Gmail connection IDs to query.
	 *
	 * If explicit connection_ids are provided, use those (validated).
	 * If include_external is true but no specific IDs, use all configured Gmail connections.
	 *
	 * @since 2.2.0
	 *
	 * @param array $arguments Tool arguments.
	 * @return string[] Array of connection IDs.
	 */
	private function resolve_connection_ids( array $arguments ) {
		if ( ! empty( $arguments['connection_ids'] ) && is_array( $arguments['connection_ids'] ) ) {
			return array_map( 'sanitize_key', $arguments['connection_ids'] );
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return array();
		}

		$all_connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
		$gmail_ids       = array();

		foreach ( $all_connections as $cid => $conn ) {
			if ( ! empty( $conn['connection_type'] ) && 'gmail' === $conn['connection_type'] ) {
				$gmail_ids[] = $cid;
			}
		}

		return $gmail_ids;
	}

	/**
	 * Build a Gmail search query string from lead filter arguments.
	 *
	 * Translates CRM lead filter parameters into Gmail search operators.
	 *
	 * @since 2.2.0
	 *
	 * @param array $arguments Tool arguments.
	 * @return string Gmail-compatible search query.
	 */
	private function build_gmail_search_query( array $arguments ) {
		$parts = array( 'is:unread' );

		// Filter by email domain.
		if ( ! empty( $arguments['email_domain'] ) ) {
			$parts[] = 'from:' . sanitize_text_field( $arguments['email_domain'] );
		}

		// Date range.
		if ( ! empty( $arguments['date_from'] ) ) {
			$parts[] = 'after:' . sanitize_text_field( $arguments['date_from'] );
		}
		if ( ! empty( $arguments['date_to'] ) ) {
			$parts[] = 'before:' . sanitize_text_field( $arguments['date_to'] );
		}

		// Inquiry type → Gmail label/keyword mapping.
		$inquiry_type = isset( $arguments['inquiry_type'] ) ? sanitize_key( $arguments['inquiry_type'] ) : 'all';
		if ( 'all' !== $inquiry_type ) {
			$keyword_map = array(
				'demo_request'          => 'demo OR demonstration OR "book a demo"',
				'pricing_inquiry'       => 'pricing OR price OR quote OR cost OR estimate',
				'trial_request'         => 'trial OR "free trial" OR "try it"',
				'support_request'       => 'support OR help OR issue OR problem OR "not working"',
				'partnership'           => 'partnership OR partner OR collaboration OR affiliate',
				'referral'              => 'referral OR referred OR "recommended by"',
				'consultation_request'  => 'consultation OR consulting OR "book a call" OR assessment',
				'event_registration'    => 'webinar OR event OR register OR registration OR rsvp',
				'content_download'      => 'download OR whitepaper OR ebook OR guide OR pdf',
				'newsletter_signup'     => 'newsletter OR subscribe OR subscription',
				'account_management'    => 'account OR upgrade OR renew OR billing OR invoice',
			);

			if ( isset( $keyword_map[ $inquiry_type ] ) ) {
				$parts[] = '{' . $keyword_map[ $inquiry_type ] . '}';
			}
		}

		// Negative: exclude common non-lead emails.
		$parts[] = '-{spam OR "out of office" OR "delivery failure" OR noreply OR no-reply OR "mailer daemon"}';

		return implode( ' ', $parts );
	}

	/**
	 * Request an OAuth 2.0 access token for a Gmail connection.
	 *
	 * @since 2.2.0
	 *
	 * @param string $client_id     Gmail API client ID.
	 * @param string $client_secret Gmail API client secret.
	 * @param string $refresh_token Gmail API refresh token.
	 * @return string|WP_Error Access token or error.
	 */
	private function request_gmail_access_token( $client_id, $client_secret, $refresh_token ) {
		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 30,
				'body'    => array(
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'refresh_token' => $refresh_token,
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( 200 !== $status || JSON_ERROR_NONE !== json_last_error() || empty( $data['access_token'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_crm_gmail_token_failed',
				__( 'Failed to obtain Gmail access token.', 'mcp-ai-wpoos-pro' )
			);
		}

		return (string) $data['access_token'];
	}
}
