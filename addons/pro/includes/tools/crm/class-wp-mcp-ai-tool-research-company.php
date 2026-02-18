<?php
/**
 * Tool for researching companies using AI and web search.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides functionality to research companies using web search and AI.
 */
class WP_MCP_AI_Tool_Research_Company implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Determine whether tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// Requires both Company CPT and web search tool.
		return post_type_exists( 'mcp_ai_company' ) && class_exists( 'WP_MCP_AI_Tool_Web_Search' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		if ( ! post_type_exists( 'mcp_ai_company' ) ) {
			return __( 'The Research Company tool is disabled because the CRM Toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
		}
		return __( 'The Research Company tool requires the Web Search tool to be available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'research_company';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Research Company', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Research a company using AI-powered web search. Gets company information, industry insights, market position, and identifies if they are a good target for your services. Returns structured data ready for CRM import.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'company_name'    => array(
					'type'        => 'string',
					'description' => __( 'Name of the company to research.', 'mcp-ai-wpoos-pro' ),
				),
				'industry'        => array(
					'type'        => 'string',
					'description' => __( 'Industry sector (helps narrow search results).', 'mcp-ai-wpoos-pro' ),
				),
				'location'        => array(
					'type'        => 'string',
					'description' => __( 'Company location (city, state, or country) to help identify the right company.', 'mcp-ai-wpoos-pro' ),
				),
				'research_focus'  => array(
					'type'        => 'string',
					'description' => __( 'What to focus the research on: general (company overview), target_fit (evaluate as prospect), industry_analysis (industry trends), or competition (competitive positioning).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'general', 'target_fit', 'industry_analysis', 'competition' ),
					'default'     => 'general',
				),
				'service_context' => array(
					'type'        => 'string',
					'description' => __( 'Description of your services to evaluate target fit (e.g., "WordPress development agency", "B2B SaaS consulting").', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'company_name' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_tool_unavailable', self::get_unavailable_reason() );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to research companies.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['company_name'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_company_name', __( 'Company name is required.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
		}

		$company_name    = sanitize_text_field( $arguments['company_name'] );
		$industry        = isset( $arguments['industry'] ) ? sanitize_text_field( $arguments['industry'] ) : '';
		$location        = isset( $arguments['location'] ) ? sanitize_text_field( $arguments['location'] ) : '';
		$research_focus  = isset( $arguments['research_focus'] ) ? sanitize_text_field( $arguments['research_focus'] ) : 'general';
		$service_context = isset( $arguments['service_context'] ) ? sanitize_text_field( $arguments['service_context'] ) : '';

		// Build search query based on research focus.
		$search_query = $this->build_search_query( $company_name, $industry, $location, $research_focus, $service_context );

		// Execute web search using the web_search tool.
		$web_search_tool = new WP_MCP_AI_Tool_Web_Search();
		$search_result   = $web_search_tool->execute(
			array(
				'query'       => $search_query,
				'num_results' => 5,
			),
			$context
		);

		if ( is_wp_error( $search_result ) ) {
			return new WP_Error(
				'wp_mcp_ai_search_failed',
				sprintf(
					/* translators: %s: Error message */
					__( 'Web search failed: %s', 'mcp-ai-wpoos-pro' ),
					$search_result->get_error_message()
				)
			);
		}

		// Extract and structure the research data.
		$research_data = $this->structure_research_data( $search_result, $company_name, $research_focus );

		// Add guidance based on research focus.
		$research_data['guidance'] = $this->get_research_guidance( $research_focus, $service_context );

		// Trigger action for other plugins/automations.
		do_action( 'wp_mcp_ai_company_researched', $company_name, $research_data, $arguments, $context );

		return array(
			'success'        => true,
			'company_name'   => $company_name,
			'research_focus' => $research_focus,
			'research_data'  => $research_data,
			'search_query'   => $search_query,
			'message'        => sprintf(
				/* translators: %s: Company name */
				__( 'Research completed for "%s". Review the data and use create_company tool to add to CRM.', 'mcp-ai-wpoos-pro' ),
				$company_name
			),
		);
	}

	/**
	 * Build search query based on research focus.
	 *
	 * @param string $company_name    Company name.
	 * @param string $industry        Industry.
	 * @param string $location        Location.
	 * @param string $research_focus  Research focus.
	 * @param string $service_context Service context.
	 * @return string Search query.
	 */
	private function build_search_query( $company_name, $industry, $location, $research_focus, $service_context ) {
		$query_parts = array( $company_name );

		if ( $industry ) {
			$query_parts[] = $industry;
		}

		if ( $location ) {
			$query_parts[] = $location;
		}

		switch ( $research_focus ) {
			case 'target_fit':
				$query_parts[] = 'company size employees revenue business model';
				if ( $service_context ) {
					$query_parts[] = 'uses ' . $service_context;
				}
				break;

			case 'industry_analysis':
				$query_parts[] = 'industry trends market analysis best practices';
				break;

			case 'competition':
				$query_parts[] = 'competitors market position competitive landscape';
				break;

			case 'general':
			default:
				$query_parts[] = 'company information overview website contact';
				break;
		}

		return implode( ' ', $query_parts );
	}

	/**
	 * Structure research data from search results.
	 *
	 * @param array  $search_result  Search results.
	 * @param string $company_name   Company name.
	 * @param string $research_focus Research focus.
	 * @return array Structured data.
	 */
	private function structure_research_data( $search_result, $company_name, $research_focus ) {
		$data = array(
			'search_results' => isset( $search_result['results'] ) ? $search_result['results'] : array(),
			'summary'        => isset( $search_result['summary'] ) ? $search_result['summary'] : '',
			'sources'        => isset( $search_result['sources'] ) ? $search_result['sources'] : array(),
		);

		// Add context about what was found.
		$result_count = count( $data['search_results'] );

		$data['findings_summary'] = sprintf(
			/* translators: 1: Company name, 2: Number of results */
			__( 'Found %2$d sources of information about %1$s.', 'mcp-ai-wpoos-pro' ),
			$company_name,
			$result_count
		);

		return $data;
	}

	/**
	 * Get research guidance based on focus.
	 *
	 * @param string $research_focus  Research focus.
	 * @param string $service_context Service context.
	 * @return string Guidance text.
	 */
	private function get_research_guidance( $research_focus, $service_context ) {
		switch ( $research_focus ) {
			case 'target_fit':
				return __( 'Review company size, revenue, and business model to determine if they match your ideal customer profile. Look for indicators that they might need your services.', 'mcp-ai-wpoos-pro' );

			case 'industry_analysis':
				return __( 'Analyze industry trends, challenges, and best practices. Use this information to position your services effectively and identify pain points.', 'mcp-ai-wpoos-pro' );

			case 'competition':
				return __( 'Understand competitive landscape to identify differentiation opportunities and potential partnership possibilities.', 'mcp-ai-wpoos-pro' );

			case 'general':
			default:
				return __( 'Review the company overview, website, and contact information. Extract key details to populate the CRM record.', 'mcp-ai-wpoos-pro' );
		}
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'crm',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'sales_manager', 'business_development', 'marketing_manager', 'market_researcher' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-capability',  // Requires user capabilities.
			'read-only',            // Does not modify data.
			'external-api',         // Uses web search API.
		);
	}
}
