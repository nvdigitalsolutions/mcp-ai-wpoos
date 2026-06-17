<?php
/**
 * Tool for searching customer correspondence via email-based criteria in the CRM.
 *
 * Implements industry-standard email categorisation, response-time analytics, and
 * routing suggestions. Supports cached results and WP Cron-driven refresh.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 2.1.0
 * @since 2.4.0  Added configurable orderby, order, search with TF-IDF relevance scoring.
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/traits/trait-wp-mcp-ai-relevance-search.php';

/**
 * CRM Email Search – Customer Correspondence Tool.
 *
 * Searches CRM contacts for customer correspondence activity following enterprise
 * CRM standards (Salesforce, HubSpot, Dynamics 365, Zoho):
 * - Email categorisation: support, general, sales, escalated
 * - Correspondence status: needs_followup, recently_contacted, never_contacted, overdue
 * - Response-time analytics and routing suggestions per contact
 * - Tag-based and domain-based filtering
 * - Results cached and auto-refreshed via WP Cron
 *
 * @since 2.1.0
 */
class WP_MCP_AI_Tool_CRM_Email_Search_Correspondence implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_CRM_Relevance_Search;

	/**
	 * WP Cron hook for scheduled cache refresh.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'wp_mcp_ai_crm_email_search_correspondence_refresh';

	/**
	 * Cache key prefix for correspondence search results.
	 *
	 * @var string
	 */
	const CACHE_KEY_PREFIX = 'crm_correspondence_search_';

	/**
	 * Default cache TTL in seconds (1 hour).
	 *
	 * @var int
	 */
	const DEFAULT_CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Allowed correspondence category values.
	 *
	 * @var string[]
	 */
	const CATEGORIES = array( 'support', 'general', 'sales', 'escalated', 'all' );

	/**
	 * Allowed correspondence status / search mode values.
	 *
	 * @var string[]
	 */
	const CORRESPONDENCE_TYPES = array( 'needs_followup', 'recently_contacted', 'never_contacted', 'overdue', 'all' );

	/**
	 * Allowed cron schedule recurrences.
	 *
	 * @var string[]
	 */
	const CRON_SCHEDULES = array( 'hourly', 'twicedaily', 'daily' );

	/**
	 * Allowed communication channel values (industry-standard omnichannel CRM tracking).
	 *
	 * Modern CRMs (Salesforce, HubSpot, Zoho) track interactions across all channels.
	 *
	 * @var string[]
	 */
	const CHANNELS = array( 'email', 'phone', 'chat', 'social', 'all' );

	/**
	 * Allowed orderby values for correspondence search.
	 *
	 * @var string[]
	 */
	const ORDERBY_OPTIONS = array( 'relevance', 'last_contacted', 'date', 'name', 'company' );

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
		return __( 'The CRM Email Search (Correspondence) tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
 * {@inheritdoc}
 */
	public function get_slug() {
		return 'crm_email_search_correspondence';
	}

	/**
 * {@inheritdoc}
 */
	public function get_name() {
		return __( 'CRM Email Search: Customer Correspondence', 'mcp-ai-wpoos-pro' );
	}

	/**
 * {@inheritdoc}
 */
	public function get_description() {
		return __( 'Search CRM contacts for customer correspondence activity. Supports industry-standard email categories (support, general, sales, escalated), response-time analytics, routing suggestions, follow-up status filtering, and free-text TF-IDF relevance search across name, company, and email. Configurable orderby (relevance, last_contacted, date, name, company) and order (ASC/DESC). Results are cached and can be auto-refreshed on a WP Cron schedule.', 'mcp-ai-wpoos-pro' );
	}

	/**
 * {@inheritdoc}
 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'              => array(
					'type'        => 'string',
					'enum'        => array( 'search', 'get_cached', 'clear_cache', 'schedule', 'unschedule' ),
					'description' => __( 'Action: search (execute and cache), get_cached (return cached results), clear_cache (invalidate cache), schedule (register WP Cron refresh), unschedule (remove schedule).', 'mcp-ai-wpoos-pro' ),
					'default'     => 'search',
				),
				'correspondence_type' => array(
					'type'        => 'string',
					'enum'        => self::CORRESPONDENCE_TYPES,
					'description' => __( 'Correspondence status filter. "needs_followup" = not contacted within days_since_contact. "overdue" = past follow_up_date. "recently_contacted" = contacted within days_since_contact. "never_contacted" = no last_contacted record.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'needs_followup',
				),
				'category'            => array(
					'type'        => 'string',
					'enum'        => self::CATEGORIES,
					'description' => __( 'Email/correspondence category (industry standard). support = help requests; sales = sales touchpoints; escalated = high-priority issues.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'all',
				),
				'days_since_contact'  => array(
					'type'        => 'integer',
					'description' => __( 'Threshold in days. For "needs_followup": min days since last contact. For "recently_contacted": max days since last contact.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'default'     => 7,
				),
				'contact_status'      => array(
					'type'        => 'string',
					'description' => __( 'Filter by CRM contact status (e.g. "active", "inactive", "prospect", "customer").', 'mcp-ai-wpoos-pro' ),
				),
				'tags'                => array(
					'type'        => 'array',
					'description' => __( 'Return only contacts that possess ALL of these tags.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'email_domain'        => array(
					'type'        => 'string',
					'description' => __( 'Filter by contact email domain (e.g. "example.com").', 'mcp-ai-wpoos-pro' ),
				),
				'include_analytics'   => array(
					'type'        => 'boolean',
					'description' => __( 'When true, include per-contact response-time analytics and routing suggestions.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'per_page'            => array(
					'type'        => 'integer',
					'description' => __( 'Results per page (1–100).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				),
				'page'                => array(
					'type'        => 'integer',
					'description' => __( 'Page number.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'default'     => 1,
				),
				'cache_ttl'           => array(
					'type'        => 'integer',
					'description' => __( 'Cache lifetime in seconds (minimum 60, default 3600).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 60,
					'default'     => HOUR_IN_SECONDS,
				),
				'schedule'            => array(
					'type'        => 'string',
					'enum'        => self::CRON_SCHEDULES,
					'description' => __( 'WP Cron recurrence for automatic cache refresh (hourly, twicedaily, daily). Used with action=schedule.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'hourly',
				),
				'force_refresh'       => array(
					'type'        => 'boolean',
					'description' => __( 'When true, bypass any cached results and execute a fresh database query. Useful for on-demand refreshes during the day.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'channel'             => array(
					'type'        => 'string',
					'enum'        => self::CHANNELS,
					'description' => __( 'Filter by communication channel. Industry-standard omnichannel CRM tracking: "email" = email correspondence; "phone" = phone call logs; "chat" = live chat / messaging; "social" = social media interactions; "all" = no channel filter.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'all',
				),
				'sla_breach_only'     => array(
					'type'        => 'boolean',
					'description' => __( 'When true, return only contacts that have breached SLA (Service Level Agreement) response time thresholds. Uses days_since_contact as the SLA threshold. Industry standard in enterprise CRM / helpdesk operations.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'search'              => array(
					'type'        => 'string',
					'description' => __( 'Free-text search across contact name, company, and email fields. When combined with orderby=relevance, results are scored and ranked using TF-IDF relevance. Useful for finding contacts by partial name, domain, or company keyword.', 'mcp-ai-wpoos-pro' ),
				),
				'orderby'             => array(
					'type'        => 'string',
					'enum'        => self::ORDERBY_OPTIONS,
					'description' => __( 'Sort order for results. "relevance" requires the search parameter and ranks by TF-IDF score. "last_contacted" sorts by last contact date. "date" sorts by contact creation date. "name" sorts alphabetically by contact name. "company" sorts by company name.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'last_contacted',
				),
				'order'               => array(
					'type'        => 'string',
					'enum'        => array( 'ASC', 'DESC' ),
					'description' => __( 'Sort direction: ASC (ascending) or DESC (descending).', 'mcp-ai-wpoos-pro' ),
					'default'     => 'ASC',
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
			'profession_tags'       => array( 'sales_manager', 'account_manager', 'customer_success' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to search CRM correspondence.', 'mcp-ai-wpoos-pro' ) );
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

		// Cache-aside: return cached results unless a forced refresh is requested.
		if ( ! $force_refresh ) {
			$cached = $this->cache_get( $cache_key );
			if ( false !== $cached ) {
				$cached['from_cache']    = true;
				$cached['force_refresh'] = false;
				return $cached;
			}
		}

		$results = $this->query_correspondence( $filters );

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
			return array(
				'success'  => false,
				'message'  => __( 'No cached results found. Run action=search first to populate the cache.', 'mcp-ai-wpoos-pro' ),
				'contacts' => array(),
				'total'    => 0,
			);
		}

		$cached['from_cache'] = true;
		return $cached;
	}

	/**
	 * Invalidate the cache for the given filter set.
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
			'message' => __( 'Correspondence search cache cleared successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Register a WP Cron event to auto-refresh the cached correspondence search.
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
		update_option( 'wp_mcp_ai_crm_correspondence_search_params', $filters, false );

		$existing = wp_next_scheduled( self::CRON_HOOK );
		if ( $existing ) {
			wp_unschedule_event( $existing, self::CRON_HOOK );
		}

		$result = wp_schedule_event( time(), $recurrence, self::CRON_HOOK );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'    => true,
			/* translators: %s: cron recurrence label */
			'message'    => sprintf( __( 'Correspondence search scheduled to auto-refresh %s.', 'mcp-ai-wpoos-pro' ), $recurrence ),
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

		delete_option( 'wp_mcp_ai_crm_correspondence_search_params' );

		return array(
			'success' => true,
			'message' => __( 'Correspondence search schedule removed.', 'mcp-ai-wpoos-pro' ),
		);
	}

	// -------------------------------------------------------------------------
	// Cron callback.
	// -------------------------------------------------------------------------

	/**
	 * WP Cron callback – refresh cached correspondence search results.
	 *
	 * @return void
	 */
	public function run_scheduled_search() {
		$filters = get_option( 'wp_mcp_ai_crm_correspondence_search_params', array() );
		if ( empty( $filters ) ) {
			return;
		}

		$cache_key = $this->build_cache_key( $filters );
		$results   = $this->query_correspondence( $filters );

		$results['cached']    = true;
		$results['cached_at'] = current_time( 'mysql' );
		$results['cache_key'] = $cache_key;

		$this->cache_set( $cache_key, $results, self::DEFAULT_CACHE_TTL );

		/**
		 * Fires after a scheduled CRM correspondence search cache refresh completes.
		 *
		 * @since 2.1.0
		 *
		 * @param array $results Refreshed results.
		 * @param array $filters Filters used for the query.
		 */
		do_action( 'wp_mcp_ai_crm_correspondence_search_refreshed', $results, $filters );
	}

	// -------------------------------------------------------------------------
	// Core query.
	// -------------------------------------------------------------------------

	/**
	 * Query CRM contacts for correspondence activity.
	 *
	 * Applies correspondence type, email category, days-since-contact, contact status,
	 * tag, and email-domain filters. Optionally enriches each result with response-time
	 * analytics and a routing suggestion.
	 *
	 * @param array $filters Sanitised filter parameters.
	 * @return array
	 */
	private function query_correspondence( array $filters ) {
		global $wpdb;

		$per_page            = min( max( absint( $filters['per_page'] ), 1 ), 100 );
		$page                = max( absint( $filters['page'] ), 1 );
		$correspondence_type = $filters['correspondence_type'];
		$days_since          = max( 1, absint( $filters['days_since_contact'] ) );
		$include_analytics   = ! empty( $filters['include_analytics'] );
		$orderby             = isset( $filters['orderby'] ) ? $filters['orderby'] : 'last_contacted';
		$order               = isset( $filters['order'] ) ? $filters['order'] : 'ASC';
		$search              = isset( $filters['search'] ) ? trim( sanitize_text_field( $filters['search'] ) ) : '';
		$is_relevance        = ( 'relevance' === $orderby && '' !== $search );

		$query_args = array(
			'post_type'      => 'mcp_crm_contacts',
			'post_status'    => 'publish',
			'posts_per_page' => $is_relevance ? 500 : $per_page,
			'paged'          => $is_relevance ? 1 : $page,
		);

		$meta_query  = array( 'relation' => 'AND' );
		$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days_since} days" ) );

		// Correspondence status / search-mode clauses (meta_query only — ordering is applied separately).
		switch ( $correspondence_type ) {
			case 'needs_followup':
				$meta_query[] = array(
					'relation' => 'OR',
					array(
						'key'     => 'last_contacted',
						'value'   => $cutoff_date,
						'compare' => '<',
						'type'    => 'DATETIME',
					),
					array(
						'key'     => 'last_contacted',
						'compare' => 'NOT EXISTS',
					),
				);
				break;

			case 'recently_contacted':
				$meta_query[] = array(
					'key'     => 'last_contacted',
					'value'   => $cutoff_date,
					'compare' => '>=',
					'type'    => 'DATETIME',
				);
				break;

			case 'never_contacted':
				$meta_query[] = array(
					'key'     => 'last_contacted',
					'compare' => 'NOT EXISTS',
				);
				break;

			case 'overdue':
				$meta_query[] = array(
					'key'     => 'follow_up_date',
					'value'   => current_time( 'mysql' ),
					'compare' => '<',
					'type'    => 'DATETIME',
				);
				break;

			case 'all':
			default:
				break;
		}

		// Dynamic orderby – decoupled from correspondence_type filtering.
		if ( ! $is_relevance ) {
			switch ( $orderby ) {
				case 'last_contacted':
					$query_args['meta_key'] = 'last_contacted'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required for correspondence ordering on indexed meta.
					$query_args['orderby']  = 'meta_value';
					break;
				case 'name':
					$query_args['orderby'] = 'title';
					break;
				case 'company':
					$query_args['meta_key'] = 'company'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required for company-name ordering on indexed meta.
					$query_args['orderby']  = 'meta_value';
					break;
				case 'date':
				default:
					$query_args['orderby'] = 'date';
					break;
			}
			$query_args['order'] = $order;
		} else {
			// Relevance mode queries broadly; scoring and pagination happen after the loop.
			$query_args['orderby'] = 'date';
			$query_args['order']   = 'DESC';
		}

		// Free-text search across contact name, company, and email.
		if ( '' !== $search ) {
			$search_like  = '%' . $wpdb->esc_like( $search ) . '%';
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => 'company',
					'value'   => $search_like,
					'compare' => 'LIKE',
				),
				array(
					'key'     => 'email',
					'value'   => $search_like,
					'compare' => 'LIKE',
				),
			);
			// Also search post title (contact name) via WordPress core search.
			$query_args['s'] = $search;
		}

		// Email category (industry-standard: support, general, sales, escalated).
		if ( ! empty( $filters['category'] ) && 'all' !== $filters['category'] ) {
			$meta_query[] = array(
				'key'     => 'correspondence_category',
				'value'   => sanitize_key( $filters['category'] ),
				'compare' => '=',
			);
		}

		// Contact status.
		if ( ! empty( $filters['contact_status'] ) ) {
			$meta_query[] = array(
				'key'     => 'contact_status',
				'value'   => sanitize_text_field( $filters['contact_status'] ),
				'compare' => '=',
			);
		}

		// Communication channel (industry-standard omnichannel CRM tracking).
		if ( ! empty( $filters['channel'] ) && 'all' !== $filters['channel'] ) {
			$meta_query[] = array(
				'key'     => 'channel',
				'value'   => sanitize_key( $filters['channel'] ),
				'compare' => '=',
			);
		}

		if ( count( $meta_query ) > 1 ) {
			$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required for CRM correspondence filtering on indexed meta fields.
		}

		$query    = new WP_Query( $query_args );
		$contacts = array();

		$filter_tags = isset( $filters['tags'] ) && is_array( $filters['tags'] )
			? array_map( 'sanitize_text_field', $filters['tags'] )
			: array();

		foreach ( $query->posts as $post ) {
			$email = (string) get_post_meta( $post->ID, 'email', true );

			// Email domain filter.
			if ( ! empty( $filters['email_domain'] ) ) {
				$domain = sanitize_text_field( $filters['email_domain'] );
				if ( false === strpos( $email, '@' . $domain ) ) {
					continue;
				}
			}

			// Tag filter – contact must have ALL requested tags.
			if ( ! empty( $filter_tags ) ) {
				$contact_tags = (array) get_post_meta( $post->ID, 'tags', true );
				if ( count( array_intersect( $filter_tags, $contact_tags ) ) < count( $filter_tags ) ) {
					continue;
				}
			}

			$last_contacted  = (string) get_post_meta( $post->ID, 'last_contacted', true );
			$follow_up_date  = (string) get_post_meta( $post->ID, 'follow_up_date', true );
			$contact_count   = absint( get_post_meta( $post->ID, 'contact_count', true ) );
			$category        = sanitize_key( (string) get_post_meta( $post->ID, 'correspondence_category', true ) );
			$channel_value   = sanitize_key( (string) get_post_meta( $post->ID, 'channel', true ) );

			// SLA breach filter: skip contacts within SLA threshold when sla_breach_only is active.
			if ( ! empty( $filters['sla_breach_only'] ) && $last_contacted ) {
				$diff_seconds = current_time( 'timestamp', true ) - strtotime( $last_contacted );
				if ( $diff_seconds < ( $days_since * DAY_IN_SECONDS ) ) {
					continue;
				}
			}

			$record = array(
				'id'             => $post->ID,
				'name'           => $post->post_title,
				'email'          => sanitize_email( $email ),
				'first_name'     => sanitize_text_field( (string) get_post_meta( $post->ID, 'first_name', true ) ),
				'last_name'      => sanitize_text_field( (string) get_post_meta( $post->ID, 'last_name', true ) ),
				'company'        => sanitize_text_field( (string) get_post_meta( $post->ID, 'company', true ) ),
				'contact_status' => sanitize_text_field( (string) get_post_meta( $post->ID, 'contact_status', true ) ),
				'category'       => $category,
				// Default to 'email' when no channel is stored — email is the primary.
			// correspondence channel in CRM systems that don't yet track omnichannel.
			'channel'        => $channel_value  ? $channel_value : 'email',
				'last_contacted' => sanitize_text_field( $last_contacted ),
				'follow_up_date' => sanitize_text_field( $follow_up_date ),
				'contact_count'  => $contact_count,
				'tags'           => array_map( 'sanitize_text_field', (array) get_post_meta( $post->ID, 'tags', true ) ),
				'added_date'     => $post->post_date,
				'edit_url'       => get_edit_post_link( $post->ID, 'raw' ),
			);

			// Optional per-contact analytics and routing suggestion.
			if ( $include_analytics ) {
				$record['analytics'] = $this->build_analytics( $last_contacted, $follow_up_date, $contact_count, $category );
			}

			$contacts[] = $record;
		}

		// Relevance scoring: rank all results with TF-IDF, then paginate.
		if ( $is_relevance ) {
			$contacts = $this->rank_by_relevance( $contacts, $search );
			$total    = count( $contacts );
			$pages    = max( 1, (int) ceil( $total / $per_page ) );
			$offset   = ( $page - 1 ) * $per_page;
			$contacts = array_slice( $contacts, $offset, $per_page );

			return array(
				'success'  => true,
				'contacts' => $contacts,
				'total'    => $total,
				'per_page' => $per_page,
				'page'     => $page,
				'pages'    => $pages,
				'filters'  => $filters,
			);
		}

		return array(
			'success'  => true,
			'contacts' => $contacts,
			'total'    => $query->found_posts,
			'per_page' => $per_page,
			'page'     => $page,
			'pages'    => max( 1, $query->max_num_pages ),
			'filters'  => $filters,
		);
	}

	// -------------------------------------------------------------------------
	// Analytics helpers.
	// -------------------------------------------------------------------------

	/**
	 * Build per-contact response-time analytics and routing suggestion.
	 *
	 * Follows industry practice of surfacing days-since-contact, overdue flag,
	 * engagement frequency, and an actionable routing recommendation.
	 *
	 * @param string $last_contacted  MySQL datetime of last contact (may be empty).
	 * @param string $follow_up_date  MySQL datetime of next follow-up (may be empty).
	 * @param int    $contact_count   Total number of email touchpoints logged.
	 * @param string $category        Correspondence category slug.
	 * @return array
	 */
	private function build_analytics( $last_contacted, $follow_up_date, $contact_count, $category ) {
		$analytics = array(
			'days_since_last_contact' => null,
			'is_overdue'              => false,
			'days_overdue'            => null,
			'engagement_level'        => $this->engagement_level( $contact_count ),
			'routing_suggestion'      => $this->routing_suggestion( $category ),
		);

		if ( $last_contacted ) {
			$diff = ( current_time( 'timestamp', true ) - strtotime( $last_contacted ) );
			$analytics['days_since_last_contact'] = (int) floor( $diff / DAY_IN_SECONDS );
		}

		if ( $follow_up_date ) {
			$overdue_seconds = current_time( 'timestamp', true ) - strtotime( $follow_up_date );
			if ( $overdue_seconds > 0 ) {
				$analytics['is_overdue']   = true;
				$analytics['days_overdue'] = (int) floor( $overdue_seconds / DAY_IN_SECONDS );
			}
		}

		return $analytics;
	}

	/**
	 * Return a human-readable engagement level based on total contact count.
	 *
	 * Industry convention: 0 = dormant, 1-3 = low, 4-10 = moderate, 11+ = high.
	 *
	 * @param int $count Total contact count.
	 * @return string
	 */
	private function engagement_level( $count ) {
		if ( 0 === $count ) {
			return __( 'dormant', 'mcp-ai-wpoos-pro' );
		}
		if ( $count <= 3 ) {
			return __( 'low', 'mcp-ai-wpoos-pro' );
		}
		if ( $count <= 10 ) {
			return __( 'moderate', 'mcp-ai-wpoos-pro' );
		}
		return __( 'high', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Return a routing suggestion based on the correspondence category.
	 *
	 * Industry standard: route to the appropriate team/queue by category.
	 *
	 * @param string $category Correspondence category slug.
	 * @return string
	 */
	private function routing_suggestion( $category ) {
		$routing = array(
			'support'   => __( 'Route to Customer Support queue', 'mcp-ai-wpoos-pro' ),
			'escalated' => __( 'Escalate to Senior Support / Account Manager immediately', 'mcp-ai-wpoos-pro' ),
			'sales'     => __( 'Route to Sales team for follow-up', 'mcp-ai-wpoos-pro' ),
			'general'   => __( 'Assign to account owner for response', 'mcp-ai-wpoos-pro' ),
		);

		return isset( $routing[ $category ] ) ? $routing[ $category ] : __( 'Review and assign manually', 'mcp-ai-wpoos-pro' );
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Extract and sanitise filter parameters from raw tool arguments.
	 *
	 * @param array $arguments Raw arguments.
	 * @return array Sanitised filter set.
	 */
	private function extract_filters( array $arguments ) {
		$correspondence_type = isset( $arguments['correspondence_type'] )
			? sanitize_key( $arguments['correspondence_type'] )
			: 'needs_followup';
		if ( ! in_array( $correspondence_type, self::CORRESPONDENCE_TYPES, true ) ) {
			$correspondence_type = 'needs_followup';
		}

		$category = isset( $arguments['category'] ) ? sanitize_key( $arguments['category'] ) : 'all';
		if ( ! in_array( $category, self::CATEGORIES, true ) ) {
			$category = 'all';
		}

		$tags = array();
		if ( ! empty( $arguments['tags'] ) && is_array( $arguments['tags'] ) ) {
			$tags = array_map( 'sanitize_text_field', $arguments['tags'] );
		}

		$channel = isset( $arguments['channel'] ) ? sanitize_key( $arguments['channel'] ) : 'all';
		if ( ! in_array( $channel, self::CHANNELS, true ) ) {
			$channel = 'all';
		}

		$orderby = $this->sanitise_orderby(
			isset( $arguments['orderby'] ) ? $arguments['orderby'] : '',
			'last_contacted',
			self::ORDERBY_OPTIONS
		);

		$order = isset( $arguments['order'] ) ? strtoupper( sanitize_key( $arguments['order'] ) ) : 'ASC';
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'ASC';
		}

		return array(
			'correspondence_type' => $correspondence_type,
			'category'            => $category,
			'channel'             => $channel,
			'sla_breach_only'     => isset( $arguments['sla_breach_only'] ) ? (bool) $arguments['sla_breach_only'] : false,
			'days_since_contact'  => isset( $arguments['days_since_contact'] ) ? max( 1, absint( $arguments['days_since_contact'] ) ) : 7,
			'contact_status'      => isset( $arguments['contact_status'] ) ? sanitize_text_field( $arguments['contact_status'] ) : '',
			'tags'                => $tags,
			'email_domain'        => isset( $arguments['email_domain'] ) ? sanitize_text_field( $arguments['email_domain'] ) : '',
			'include_analytics'   => isset( $arguments['include_analytics'] ) ? (bool) $arguments['include_analytics'] : true,
			'per_page'            => isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20,
			'page'                => isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1,
			'orderby'             => $orderby,
			'order'               => $order,
			'search'              => isset( $arguments['search'] ) ? sanitize_text_field( $arguments['search'] ) : '',
		);
	}

	/**
	 * Build a stable, deterministic cache key from a filter set.
	 *
	 * @param array $filters Sanitised filters.
	 * @return string
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
}
