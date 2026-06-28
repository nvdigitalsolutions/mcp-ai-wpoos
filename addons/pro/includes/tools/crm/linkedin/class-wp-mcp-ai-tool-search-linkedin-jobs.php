<?php
/**
 * Tool for searching LinkedIn job postings via the LinkedIn REST API.
 *
 * When a valid LinkedIn OAuth connection is configured through Remote Sites,
 * the tool queries the LinkedIn Jobs API.  Otherwise it falls back to an
 * AI-powered web search that returns structured job results.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 2.10.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Searches LinkedIn for job postings matching specified criteria.
 *
 * @since 2.10.0
 */
class WP_MCP_AI_Tool_Search_LinkedIn_Jobs implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Determine whether CRM toolkit is enabled.
	 *
	 * @since 2.10.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] ) && class_exists( 'WP_MCP_AI_LinkedIn_Client' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.10.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return __( 'The Search LinkedIn Jobs tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
		}
		return __( 'The Search LinkedIn Jobs tool requires the LinkedIn client integration to be configured.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'search_linkedin_jobs';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Search LinkedIn Jobs', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Search LinkedIn for job postings matching specified criteria.  Supports keyword, location, and experience-level filters.  Falls back to AI-powered web search when no LinkedIn connection is configured.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'query'            => array(
					'type'        => 'string',
					'description' => __( 'Keywords or job title to search for.', 'mcp-ai-wpoos-pro' ),
				),
				'location'         => array(
					'type'        => 'string',
					'description' => __( 'Location filter (city, state, country, or "Remote").', 'mcp-ai-wpoos-pro' ),
				),
				'keywords'         => array(
					'type'        => 'string',
					'description' => __( 'Additional comma-separated keywords to filter results.', 'mcp-ai-wpoos-pro' ),
				),
				'experience_level' => array(
					'type'        => 'string',
					'description' => __( 'Experience level filter.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'entry', 'mid_level', 'senior', 'executive' ),
				),
				'job_type'         => array(
					'type'        => 'string',
					'description' => __( 'Type of employment.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'full_time', 'part_time', 'contract', 'temporary', 'volunteer', 'internship' ),
				),
				'remote'           => array(
					'type'        => 'boolean',
					'description' => __( 'Filter to remote-only positions.', 'mcp-ai-wpoos-pro' ),
				),
				'connection_id'    => array(
					'type'        => 'string',
					'description' => __( 'Optional Remote Sites LinkedIn connection ID.  If omitted, the default LinkedIn connection from CRM settings is used.', 'mcp-ai-wpoos-pro' ),
				),
				'limit'            => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of results to return (1–50).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
			),
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
			'external-api',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to search LinkedIn jobs.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Determine whether the LinkedIn API is available.
		$use_api = $this->has_valid_connection( $arguments );

		// Fall back to web search when the LinkedIn connection is not configured.
		if ( ! $use_api ) {
			return $this->execute_fallback( $arguments, $context );
		}

		$connection_id = sanitize_text_field( $arguments['connection_id'] );

		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-linkedin-client.php';
		$client = new WP_MCP_AI_LinkedIn_Client( $connection_id );

		$filters = array();

		if ( ! empty( $arguments['query'] ) ) {
			$filters['keywords'] = sanitize_text_field( $arguments['query'] );
		}

		if ( ! empty( $arguments['location'] ) ) {
			$filters['location'] = sanitize_text_field( $arguments['location'] );
		}

		$limit            = isset( $arguments['limit'] ) ? min( 50, max( 1, absint( $arguments['limit'] ) ) ) : 10;
		$filters['count'] = $limit;

		$result = $client->search_jobs( $filters );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$elements = isset( $result['elements'] ) ? $result['elements'] : array();
		$jobs     = array();

		foreach ( $elements as $element ) {
			$jobs[] = array(
				'id'          => isset( $element['entityUrn'] ) ? $element['entityUrn'] : '',
				'title'       => isset( $element['title'] ) ? $element['title'] : '',
				'company'     => isset( $element['companyDetails']['com.linkedin.common.CompanyAttribution']['company'] )
					? $element['companyDetails']['com.linkedin.common.CompanyAttribution']['company'] : '',
				'location'    => isset( $element['formattedLocation'] ) ? $element['formattedLocation'] : '',
				'posted'      => isset( $element['listedAt'] ) ? $element['listedAt'] : '',
				'description' => isset( $element['description']['text'] ) ? wp_trim_words( $element['description']['text'], 60 ) : '',
				'url'         => isset( $element['applyMethod']['com.linkedin.vjobs.CommonExternalJobPosting']['url'] )
					? $element['applyMethod']['com.linkedin.vjobs.CommonExternalJobPosting']['url'] : '',
			);
		}

		return array(
			'success' => true,
			'mode'    => 'api',
			'count'   => count( $jobs ),
			'jobs'    => $jobs,
			'total'   => isset( $result['paging']['total'] ) ? (int) $result['paging']['total'] : count( $jobs ),
		);
	}

	/**
	 * Check whether the arguments include a valid, enabled LinkedIn connection.
	 *
	 * @param array $arguments Tool arguments.
	 * @return bool True when the LinkedIn API can be used.
	 */
	protected function has_valid_connection( $arguments ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return false;
		}

		// Use explicit connection_id from arguments, or the CRM toolkit default.
		$connection_id = '';
		if ( ! empty( $arguments['connection_id'] ) ) {
			$connection_id = sanitize_text_field( $arguments['connection_id'] );
		} elseif ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			$settings      = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
			$connection_id = isset( $settings['external_sourcing']['linkedin']['default_connection_id'] )
				? $settings['external_sourcing']['linkedin']['default_connection_id']
				: '';
		}

		if ( empty( $connection_id ) ) {
			return false;
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( empty( $connection ) ) {
			return false;
		}

		// If explicitly set to web_search mode, never use the API.
		$mode = isset( $connection['linkedin_mode'] ) ? $connection['linkedin_mode'] : 'api';
		if ( 'web_search' === $mode ) {
			return false;
		}

		// API mode: require OAuth credentials and a refresh token.
		return ! empty( $connection['refresh_token'] );
	}

	/**
	 * Fallback: AI-powered web search for LinkedIn job postings.
	 *
	 * Used when no LinkedIn API connection is configured.  Constructs a
	 * web-search query that targets LinkedIn job listings.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Structured job results.
	 */
	protected function execute_fallback( array $arguments, array $context ) {
		// Build a search query targeting LinkedIn job listings.
		$query_parts = array( 'site:linkedin.com/jobs' );

		// Load per-connection defaults when a connection_id is provided.
		$conn_defaults = array();
		if ( ! empty( $arguments['connection_id'] ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$conn = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection(
				sanitize_text_field( $arguments['connection_id'] )
			);
			if ( $conn && 'linkedin' === ( isset( $conn['connection_type'] ) ? $conn['connection_type'] : '' ) ) {
				if ( ! empty( $conn['linkedin_search_query'] ) ) {
					$conn_defaults['query'] = $conn['linkedin_search_query'];
				}
				if ( ! empty( $conn['linkedin_search_location'] ) ) {
					$conn_defaults['location'] = $conn['linkedin_search_location'];
				}
			}
		}

		// Argument values take precedence; connection defaults fill gaps.
		$arg_query    = ! empty( $arguments['query'] ) ? $arguments['query'] : ( $conn_defaults['query'] ?? '' );
		$arg_location = ! empty( $arguments['location'] ) ? $arguments['location'] : ( $conn_defaults['location'] ?? '' );

		if ( ! empty( $arg_query ) ) {
			$query_parts[] = sanitize_text_field( $arg_query );
		}
		if ( ! empty( $arguments['keywords'] ) ) {
			$query_parts[] = sanitize_text_field( $arguments['keywords'] );
		}
		if ( ! empty( $arg_location ) ) {
			$query_parts[] = sanitize_text_field( $arg_location );
		}
		if ( ! empty( $arguments['remote'] ) ) {
			$query_parts[] = 'remote';
		}
		if ( ! empty( $arguments['job_type'] ) ) {
			$query_parts[] = sanitize_text_field( $arguments['job_type'] );
		}

		$search_query = implode( ' ', $query_parts );

		// Use the plugin's web search tool if available.
		if ( class_exists( 'WP_MCP_AI_Tool_Web_Search' ) ) {
			$web_search = new WP_MCP_AI_Tool_Web_Search();
			$result     = $web_search->execute(
				array(
					'query' => $search_query,
					'limit' => isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10,
				),
				$context
			);

			if ( ! is_wp_error( $result ) && ! empty( $result['results'] ) ) {
				return array(
					'success' => true,
					'mode'    => 'fallback',
					'query'   => $search_query,
					'count'   => count( $result['results'] ),
					'jobs'    => $result['results'],
					'message' => __( 'Results obtained via web search. Connect a LinkedIn account for richer API results.', 'mcp-ai-wpoos-pro' ),
				);
			}
		}

		return array(
			'success' => true,
			'mode'    => 'fallback',
			'query'   => $search_query,
			'count'   => 0,
			'jobs'    => array(),
			'message' => sprintf(
				/* translators: %s: search query */
				__( 'No results found for "%s". Try adjusting your search terms or connect a LinkedIn account for direct API access.', 'mcp-ai-wpoos-pro' ),
				$search_query
			),
		);
	}
}
