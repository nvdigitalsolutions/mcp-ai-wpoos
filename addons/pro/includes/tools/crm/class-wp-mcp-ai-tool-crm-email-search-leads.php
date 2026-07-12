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

require_once WP_MCP_AI_PRO_PATH . 'includes/traits/trait-wp-mcp-ai-relevance-search.php';

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
 * @since 2.4.0 Added free-text TF-IDF relevance search with configurable orderby/order parameters.
 */
class WP_MCP_AI_Tool_CRM_Email_Search_Leads implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_CRM_Relevance_Search;

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
	 * Allowed orderby values for lead sorting.
	 *
	 * @since 2.4.0
	 *
	 * @var string[]
	 */
	const ORDERBY_OPTIONS = array( 'relevance', 'lead_score', 'date', 'name', 'company' );

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
		return __( 'Search CRM contacts for new leads by free-text search with TF-IDF relevance scoring, or by email-based criteria including lead score, email domain, inquiry type, source channel, priority, and date range. Sort results by relevance, lead_score, date, name, or company in ASC or DESC order. Supports multi-remote Gmail connection search via Remote Sites connection IDs for cross-account lead discovery. Results are cached for efficient throughout-the-day querying and can be auto-refreshed on a WP Cron schedule. Implements industry-standard lead scoring (HubSpot/Salesforce), 14 inquiry type categories, and pipeline-stage filtering (MQL/SQL).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'           => array(
					'type'        => 'string',
					'enum'        => array( 'search', 'get_cached', 'clear_cache', 'schedule', 'unschedule', 'import_from_gmail' ),
					'description' => __( 'Action to perform. Use "search" as the primary action — it caches results for later retrieval. "get_cached" returns previously cached results, auto-falling back to a fresh search when no cache exists. "clear_cache" invalidates cached data. "schedule" registers a WP Cron job for periodic refresh. "unschedule" removes it. "import_from_gmail" pulls leads from Gmail into the CRM pipeline.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'search',
				),
				'lead_status'      => array(
					'type'        => 'string',
					'enum'        => self::LEAD_STATUSES,
					'description' => __( 'Pipeline stage filter. "new" = freshly added, "nurturing" = in drip sequence, "converted" = won. Defaults to "new".', 'mcp-ai-wpoos-pro' ),
					'default'     => 'new',
				),
				'inquiry_type'     => array(
					'type'        => 'string',
					'enum'        => self::INQUIRY_TYPES,
					'description' => __( 'Email category/inquiry type. Industry-standard categories: new_inquiry, demo_request, pricing_inquiry, partnership, trial_request, support_request, referral, consultation_request, event_registration, content_download, newsletter_signup, account_management, general. High-intent: demo_request, trial_request, pricing_inquiry, consultation_request. MQL signals: event_registration, content_download.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'all',
				),
				'email_domain'     => array(
					'type'        => 'string',
					'description' => __( 'Filter leads by email domain (e.g. "acmecorp.com"). Supports partial match.', 'mcp-ai-wpoos-pro' ),
				),
				'source'           => array(
					'type'        => 'string',
					'enum'        => self::SOURCE_CHANNELS,
					'description' => __( 'Lead source channel. Industry-standard HubSpot/Salesforce categories: web_form, email_inbound, phone_call, chat, social_media, referral, event, campaign, import, api, manual, partner, organic_search, paid_search.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'all',
				),
				'lead_score_min'   => array(
					'type'        => 'integer',
					'description' => __( 'Minimum lead score (0-100). Industry standard: 0-39 cold, 40-69 warm, 70-100 hot.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 100,
				),
				'lead_score_max'   => array(
					'type'        => 'integer',
					'description' => __( 'Maximum lead score (0-100).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 100,
				),
				'date_from'        => array(
					'type'        => 'string',
					'description' => __( 'Include contacts added on or after this date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'date_to'          => array(
					'type'        => 'string',
					'description' => __( 'Include contacts added on or before this date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'per_page'         => array(
					'type'        => 'integer',
					'description' => __( 'Results per page (1–100).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				),
				'page'             => array(
					'type'        => 'integer',
					'description' => __( 'Page number.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'default'     => 1,
				),
				'cache_ttl'        => array(
					'type'        => 'integer',
					'description' => __( 'Cache lifetime in seconds (minimum 60, default 3600).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 60,
					'default'     => HOUR_IN_SECONDS,
				),
				'schedule'         => array(
					'type'        => 'string',
					'enum'        => self::CRON_SCHEDULES,
					'description' => __( 'WP Cron recurrence for automatic cache refresh (hourly, twicedaily, daily). Used with action=schedule.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'hourly',
				),
				'force_refresh'    => array(
					'type'        => 'boolean',
					'description' => __( 'When true, bypass any cached results and execute a fresh database query. Useful for on-demand data refreshes during the day.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'contact_owner'    => array(
					'type'        => 'string',
					'description' => __( 'Filter leads by assigned contact owner (WordPress username or email of the sales rep). Industry standard: HubSpot Contact Owner / Salesforce Lead Owner field.', 'mcp-ai-wpoos-pro' ),
				),
				'mql_stage'        => array(
					'type'        => 'string',
					'enum'        => self::MQL_STAGES,
					'description' => __( 'Lead qualification stage filter. "mql" = Marketing Qualified Lead (engaged with marketing content); "sql" = Sales Qualified Lead (accepted by sales for active pursuit); "all" = no stage filter. Industry standard: HubSpot lifecycle stages, Salesforce lead qualification workflow.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'all',
				),
				'priority'         => array(
					'type'        => 'string',
					'enum'        => self::LEAD_PRIORITY,
					'description' => __( 'Lead priority/urgency filter. "high" = immediate action required (hot leads), "medium" = this week, "low" = nurture queue. Industry standard across HubSpot, Salesforce, and Zoho CRM.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'all',
				),
				'connection_ids'   => array(
					'type'        => 'array',
					'description' => __( 'Optional array of Remote Sites Gmail connection IDs to search for leads across multiple email accounts. When provided, the tool queries each Gmail account for matching inbound emails and merges the results with local CRM contacts. Each connection must be of type "gmail".', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'search'           => array(
					'type'        => 'string',
					'description' => __( 'Free-text search across lead name, company, and email. Supports partial matching with TF-IDF relevance scoring when orderby is set to relevance.', 'mcp-ai-wpoos-pro' ),
				),
				'orderby'          => array(
					'type'        => 'string',
					'enum'        => self::ORDERBY_OPTIONS,
					'description' => __( 'Sort results by this field. Use "relevance" with the search parameter for TF-IDF scored results.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'lead_score',
				),
				'order'            => array(
					'type'        => 'string',
					'enum'        => array( 'ASC', 'DESC' ),
					'description' => __( 'Sort direction: ASC (ascending) or DESC (descending).', 'mcp-ai-wpoos-pro' ),
					'default'     => 'DESC',
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

		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

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
			case 'import_from_gmail':
				return $this->import_from_gmail( $arguments, $context );
			default:
				return new WP_Error(
					'wp_mcp_ai_invalid_action',
					__( 'Invalid action. Must be one of: search, get_cached, clear_cache, schedule, unschedule, import_from_gmail.', 'mcp-ai-wpoos-pro' )
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
		$filters          = $this->extract_filters( $arguments );
		$cache_key        = $this->build_cache_key( $filters );
		$cache_ttl        = isset( $arguments['cache_ttl'] ) ? max( 60, absint( $arguments['cache_ttl'] ) ) : self::DEFAULT_CACHE_TTL;
		$force_refresh    = ! empty( $arguments['force_refresh'] );
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
			require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-crm-gmail-client.php';
			$gmail_client     = new WP_MCP_AI_CRM_Gmail_Client();
			$external_results = $gmail_client->search_leads( $arguments );
			if ( is_array( $external_results ) && ! empty( $external_results['leads'] ) ) {
				$results['leads']    = array_merge( $results['leads'], $external_results['leads'] );
				$results['total']    = count( $results['leads'] );
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
	 * Return cached results, auto-falling back to a fresh search when no cache exists.
	 *
	 * Previously returned a WP_Error on cache miss, which caused agentic loops
	 * when assistants called get_cached before search. Now transparently delegates
	 * to run_search() so the caller always receives results.
	 *
	 * @since 2.12.0
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	private function get_cached_results( array $arguments ) {
		$filters   = $this->extract_filters( $arguments );
		$cache_key = $this->build_cache_key( $filters );
		$cached    = $this->cache_get( $cache_key );

		if ( false === $cached ) {
			// Auto-fallback: run a fresh search so the caller never receives an
			// error, preventing agentic loops when assistants request cached
			// results before a cache has been populated.
			$results = $this->run_search( $arguments );
			$results['auto_fallback'] = true;
			$results['auto_fallback_reason'] = __( 'No prior cache existed; ran a fresh search instead.', 'mcp-ai-wpoos-pro' );
			return $results;
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

	/**
	 * Bridge: search Gmail inbox and import matching emails into the CRM pipeline.
	 *
	 * Uses the import_gmail_to_crm tool to fetch emails from Gmail, classify
	 * intent, detect buying signals, extract/upsert leads, score, and qualify.
	 * Spam and newsletters are automatically filtered out by the SDR pipeline.
	 *
	 * @since 2.4.0
	 *
	 * @param array $arguments Tool arguments (query, max_results, auto_reply).
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	private function import_from_gmail( array $arguments, array $context ) {
		$_tool_file = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/inbound/class-wp-mcp-ai-tool-import-gmail-to-crm.php';
		if ( ! file_exists( $_tool_file ) ) {
			return new WP_Error(
				'wp_mcp_ai_crm_import_unavailable',
				__( 'Gmail import bridge is not available.', 'mcp-ai-wpoos-pro' )
			);
		}
		require_once $_tool_file;

		if ( ! class_exists( 'WP_MCP_AI_Tool_Import_Gmail_To_CRM' ) ) {
			return new WP_Error(
				'wp_mcp_ai_crm_import_unavailable',
				__( 'Gmail import bridge class not loaded.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Build arguments for the import tool.
		$default_query = '';
		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			$crm_settings  = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
			$default_query = $crm_settings['integrations']['gmail_default_query'] ?? 'newer_than:7d is:unread';
		}
		$import_args = array(
			'query'       => $arguments['search'] ?? $arguments['email_domain'] ?? $default_query,
			'max_results' => $arguments['max_results'] ?? $arguments['per_page'] ?? 10,
			'auto_reply'  => ! empty( $arguments['auto_reply'] ),
		);

		$tool = new WP_MCP_AI_Tool_Import_Gmail_To_CRM();
		return $tool->execute( $import_args, $context );
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
		$orderby     = isset( $filters['orderby'] ) ? $filters['orderby'] : 'lead_score';
		$order       = isset( $filters['order'] ) ? $filters['order'] : 'DESC';
		$search      = isset( $filters['search'] ) ? $filters['search'] : '';

		$is_relevance = 'relevance' === $orderby && '' !== $search;

		$query_args = array(
			'post_type'      => 'mcp_crm_contacts',
			'post_status'    => 'publish',
			'posts_per_page' => $is_relevance ? 500 : $per_page,
			'paged'          => $is_relevance ? 1 : $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		// Apply sort for non-relevance orderby values.
		if ( ! $is_relevance ) {
			switch ( $orderby ) {
				case 'lead_score':
					$query_args['meta_key'] = 'lead_score'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required for sorting CRM leads by lead score.
					$query_args['orderby']  = 'meta_value_num';
					$query_args['order']    = $order;
					break;
				case 'name':
					$query_args['orderby'] = 'title';
					$query_args['order']   = $order;
					break;
				case 'company':
					$query_args['meta_key'] = 'company'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required for sorting CRM leads by company.
					$query_args['orderby']  = 'meta_value';
					$query_args['order']    = $order;
					break;
				default:
					$query_args['orderby'] = 'date';
					$query_args['order']   = $order;
			}
		}

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

		// Apply TF-IDF relevance ranking post-query when using relevance sort with a search query.
		if ( $is_relevance ) {
			$leads  = $this->rank_by_relevance( $leads, $search );
			$total  = count( $leads );
			$pages  = max( 1, (int) ceil( $total / $per_page ) );
			$offset = ( $page - 1 ) * $per_page;
			$leads  = array_slice( $leads, $offset, $per_page );
		}

		return array(
			'success'  => true,
			'leads'    => $leads,
			'total'    => $is_relevance ? $total : $query->found_posts,
			'per_page' => $per_page,
			'page'     => $page,
			'pages'    => $is_relevance ? $pages : max( 1, $query->max_num_pages ),
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
			'orderby'       => $this->sanitise_orderby(
				isset( $arguments['orderby'] ) ? $arguments['orderby'] : 'lead_score',
				'lead_score',
				self::ORDERBY_OPTIONS
			),
			'order'         => isset( $arguments['order'] ) && 'ASC' === strtoupper( $arguments['order'] ) ? 'ASC' : 'DESC',
			'search'        => isset( $arguments['search'] ) ? sanitize_text_field( $arguments['search'] ) : '',
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
	// Cache helpers.
	// -------------------------------------------------------------------------
}
