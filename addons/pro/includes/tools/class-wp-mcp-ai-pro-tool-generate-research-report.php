<?php
/**
 * Generate Research Report Tool
 *
 * Generate professional research reports with citations, TOC, and formatting.
 * Performs comprehensive multi-step research using web search and AI analysis.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Generate Research Report Tool Class
 */
class WP_MCP_AI_Pro_Tool_Generate_Research_Report {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Maximum number of search queries to perform.
	 *
	 * @var int
	 */
	const MAX_SEARCH_QUERIES = 3;

	/**
	 * Maximum results per search query.
	 *
	 * @var int
	 */
	const MAX_RESULTS_PER_QUERY = 5;

	/**
	 * Number of queries for basic depth research.
	 *
	 * @var int
	 */
	const QUERIES_BASIC = 1;

	/**
	 * Number of queries for standard depth research.
	 *
	 * @var int
	 */
	const QUERIES_STANDARD = 2;

	/**
	 * Number of queries for comprehensive depth research.
	 *
	 * @var int
	 */
	const QUERIES_COMPREHENSIVE = 3;

	public function get_slug() {
		return 'generate_research_report';
	}

	public function get_definition() {
		return array(
			'name'                => 'generate_research_report',
			'description'         => 'Generate professional research reports with multi-step research, web search, citations, and industry-standard formatting. Supports AIA project reports, NCS drawing documentation, and CSI MasterFormat specifications. Can also format pre-written content.',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					// Multi-step research mode parameters.
					'topic'             => array(
						'type'        => 'string',
						'description' => 'Research topic (e.g., "residential project feasibility" or "concrete specifications"). Use this for automatic research.',
					),
					'report_type'       => array(
						'type'        => 'string',
						'description' => 'Type of report to generate',
						'enum'        => array( 'aia_project', 'ncs_drawing', 'csi_specification', 'general' ),
					),
					'depth'             => array(
						'type'        => 'string',
						'description' => 'Research depth level',
						'enum'        => array( 'basic', 'standard', 'comprehensive' ),
						'default'     => 'standard',
					),
					'focus_areas'       => array(
						'type'        => 'array',
						'description' => 'Specific areas to focus research on (e.g., ["sustainability", "cost estimating"])',
						'items'       => array( 'type' => 'string' ),
					),
					// Legacy formatting mode parameters (backward compatibility).
					'title'             => array(
						'type'        => 'string',
						'description' => 'Report title (for formatting pre-written content)',
					),
					'sections'          => array(
						'type'        => 'array',
						'description' => 'Pre-written report sections (for formatting mode)',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'heading' => array( 'type' => 'string' ),
								'content' => array( 'type' => 'string' ),
							),
						),
					),
					'include_toc'       => array(
						'type'        => 'boolean',
						'description' => 'Include table of contents (default: true)',
						'default'     => true,
					),
					'include_citations' => array(
						'type'        => 'boolean',
						'description' => 'Include citations section (default: true)',
						'default'     => true,
					),
					'citations'         => array(
						'type'        => 'array',
						'description' => 'Pre-provided citations (for formatting mode)',
						'items'       => array( 'type' => 'string' ),
					),
				),
				'required'   => array(),
			),
			'required_capability' => 'edit_posts',
			'category'            => array( 'research', 'orchestration', 'content' ),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		// Determine execution mode: research mode vs formatting mode.
		$is_research_mode = ! empty( $arguments['topic'] );
		$is_formatting_mode = ! empty( $arguments['title'] ) && ! empty( $arguments['sections'] );

		if ( $is_research_mode ) {
			// Multi-step research mode.
			return $this->execute_research_mode( $arguments, $context );
		} elseif ( $is_formatting_mode ) {
			// Legacy formatting mode (backward compatibility).
			return $this->execute_formatting_mode( $arguments, $context );
		} else {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'Either provide "topic" for research mode or "title" and "sections" for formatting mode.', 'mcp-ai-wpoos-pro' )
			);
		}
	}

	/**
	 * Execute multi-step research mode.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Research results or error.
	 */
	protected function execute_research_mode( $arguments, $context ) {
		// Extract and validate arguments.
		$topic       = sanitize_text_field( $arguments['topic'] );
		$report_type = isset( $arguments['report_type'] ) ? sanitize_key( $arguments['report_type'] ) : 'general';
		$depth       = isset( $arguments['depth'] ) ? sanitize_text_field( $arguments['depth'] ) : 'standard';
		$focus_areas = isset( $arguments['focus_areas'] ) && is_array( $arguments['focus_areas'] )
			? array_map( 'sanitize_text_field', $arguments['focus_areas'] )
			: array();

		// Validate depth.
		if ( ! in_array( $depth, array( 'basic', 'standard', 'comprehensive' ), true ) ) {
			$depth = 'standard';
		}

		// Validate report_type.
		$valid_types = array( 'aia_project', 'ncs_drawing', 'csi_specification', 'general' );
		if ( ! in_array( $report_type, $valid_types, true ) ) {
			$report_type = 'general';
		}

		// Log research start.
		WP_MCP_AI_Logger::log_event(
			'research_report_started',
			'Starting research report generation',
			array(
				'topic'       => $topic,
				'report_type' => $report_type,
				'depth'       => $depth,
				'focus_areas' => $focus_areas,
			)
		);

		// Step 1: Gather information through web searches.
		$search_results = $this->gather_research_information( $topic, $report_type, $depth, $focus_areas, $context );

		if ( is_wp_error( $search_results ) ) {
			WP_MCP_AI_Logger::log_error(
				'Research report web search failed: ' . $search_results->get_error_message(),
				array(
					'topic' => $topic,
					'error' => $search_results->get_error_code(),
				)
			);
			// Fall back to AI-only research if web search fails.
			$search_results = array(
				'results' => array(),
				'sources' => array(),
				'queries' => array( $topic ),
			);
		}

		// Step 2: Build research prompt with gathered information.
		$prompt = $this->build_research_prompt( $topic, $report_type, $depth, $focus_areas, $search_results );

		// Step 3: Use AI to synthesize the research.
		$research_result = $this->perform_ai_research( $prompt, $context );

		if ( is_wp_error( $research_result ) ) {
			WP_MCP_AI_Logger::log_error(
				'Research report AI synthesis failed: ' . $research_result->get_error_message(),
				array(
					'topic' => $topic,
					'error' => $research_result->get_error_code(),
				)
			);
			return $research_result;
		}

		// Step 4: Parse and format the research results.
		$report_data = $this->parse_and_format_research( $research_result, $topic, $report_type, $search_results );

		if ( is_wp_error( $report_data ) ) {
			WP_MCP_AI_Logger::log_error(
				'Failed to parse research results: ' . $report_data->get_error_message(),
				array( 'topic' => $topic )
			);
			return $report_data;
		}

		// Log success.
		WP_MCP_AI_Logger::log_event(
			'research_report_completed',
			'Research report completed successfully',
			array(
				'topic'         => $topic,
				'report_type'   => $report_type,
				'depth'         => $depth,
				'sources_count' => count( $search_results['sources'] ?? array() ),
			)
		);

		return $report_data;
	}

	/**
	 * Execute legacy formatting mode (backward compatibility).
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Formatted report.
	 */
	protected function execute_formatting_mode( $arguments, $context ) {
		$title       = sanitize_text_field( $arguments['title'] );
		$sections    = $arguments['sections'];
		$include_toc = isset( $arguments['include_toc'] ) ? (bool) $arguments['include_toc'] : true;
		$include_cit = isset( $arguments['include_citations'] ) ? (bool) $arguments['include_citations'] : true;
		$citations   = isset( $arguments['citations'] ) ? $arguments['citations'] : array();

		$report  = "# {$title}\n\n";
		$report .= '_Generated on ' . gmdate( 'F j, Y' ) . "_\n\n";

		if ( $include_toc && count( $sections ) > 1 ) {
			$report .= "## Table of Contents\n\n";
			foreach ( $sections as $i => $section ) {
				$report .= ( $i + 1 ) . '. ' . $section['heading'] . "\n";
			}
			$report .= "\n---\n\n";
		}

		foreach ( $sections as $section ) {
			$report .= '## ' . $section['heading'] . "\n\n";
			$report .= $section['content'] . "\n\n";
		}

		if ( $include_cit && ! empty( $citations ) ) {
			$report .= "## References\n\n";
			foreach ( $citations as $i => $citation ) {
				$report .= ( $i + 1 ) . '. ' . $citation . "\n";
			}
		}

		// Return structured response matching research_product tool pattern.
		return array(
			'success'       => true,
			'title'         => $title,
			'report'        => $report,
			'word_count'    => str_word_count( $report ),
			'section_count' => count( $sections ),
		);
	}

/**
 * Gather research information through web searches.
 *
 * @param string $topic       Research topic.
 * @param string $report_type Report type.
 * @param string $depth       Research depth.
 * @param array  $focus_areas Focus areas.
 * @param array  $context     Execution context.
 * @return array|WP_Error Search results or error.
 */
protected function gather_research_information( $topic, $report_type, $depth, $focus_areas, $context ) {
// Check if web search tool is available.
$registry        = WP_MCP_AI_Tool_Registry::get_instance();
$web_search_tool = $registry->get_tool( 'web_search' );

if ( ! $web_search_tool ) {
// Return empty results if web search is not available.
WP_MCP_AI_Logger::log_event(
'research_report_no_web_search',
'Web search tool not available, using AI-only mode',
array( 'topic' => $topic )
);
return array(
'results' => array(),
'sources' => array(),
'queries' => array( $topic ),
);
}

// Generate search queries based on report type, depth, and focus areas.
$search_queries = $this->generate_research_queries( $topic, $report_type, $depth, $focus_areas );

$all_results = array();
$all_sources = array();

foreach ( $search_queries as $search_query ) {
// Execute web search.
$search_result = $web_search_tool->execute(
array(
'query'       => $search_query,
'max_results' => self::MAX_RESULTS_PER_QUERY,
),
$context
);

if ( is_wp_error( $search_result ) ) {
// Log the error but continue with other searches.
WP_MCP_AI_Logger::log_error(
'Research report web search failed: ' . $search_result->get_error_message(),
array(
'query'       => $search_query,
'topic'       => $topic,
'error_code'  => $search_result->get_error_code(),
)
);
continue;
}

// Collect results.
if ( ! empty( $search_result['results'] ) && is_array( $search_result['results'] ) ) {
foreach ( $search_result['results'] as $result ) {
$all_results[] = $result;
if ( ! empty( $result['url'] ) ) {
$all_sources[] = array(
'url'     => $result['url'],
'title'   => isset( $result['title'] ) ? $result['title'] : '',
'snippet' => isset( $result['snippet'] ) ? $result['snippet'] : '',
);
}
}
}
}

// Deduplicate sources by URL.
$all_sources = $this->deduplicate_sources( $all_sources );

WP_MCP_AI_Logger::log_event(
'research_report_web_search_complete',
'Web search completed for research report',
array(
'topic'         => $topic,
'queries_count' => count( $search_queries ),
'results_count' => count( $all_results ),
'sources_count' => count( $all_sources ),
)
);

return array(
'results' => $all_results,
'sources' => $all_sources,
'queries' => $search_queries,
);
}

/**
 * Generate search queries for research.
 *
 * @param string $topic       Research topic.
 * @param string $report_type Report type.
 * @param string $depth       Research depth.
 * @param array  $focus_areas Focus areas.
 * @return array Search queries.
 */
protected function generate_research_queries( $topic, $report_type, $depth, $focus_areas ) {
$queries = array();

// Main query - always included.
$queries[] = $topic;

// Determine total number of queries based on depth.
if ( 'basic' === $depth ) {
$num_queries = self::QUERIES_BASIC;
} elseif ( 'comprehensive' === $depth ) {
$num_queries = self::QUERIES_COMPREHENSIVE;
} else {
$num_queries = self::QUERIES_STANDARD;
}

// Add focus area queries.
if ( ! empty( $focus_areas ) ) {
foreach ( $focus_areas as $area ) {
if ( count( $queries ) >= $num_queries ) {
break;
}
$queries[] = $topic . ' ' . $area;
}
}

// Add report type-specific queries.
if ( count( $queries ) < $num_queries ) {
$type_specific_queries = $this->get_report_type_queries( $topic, $report_type, $depth );
foreach ( $type_specific_queries as $type_query ) {
if ( count( $queries ) >= $num_queries ) {
break;
}
$queries[] = $type_query;
}
}

// Limit to the calculated number of queries.
return array_slice( $queries, 0, $num_queries );
}

/**
 * Get report type-specific search queries.
 *
 * @param string $topic       Research topic.
 * @param string $report_type Report type.
 * @param string $depth       Research depth.
 * @return array Type-specific queries.
 */
protected function get_report_type_queries( $topic, $report_type, $depth ) {
$queries = array();

switch ( $report_type ) {
case 'aia_project':
$queries[] = $topic . ' AIA standards requirements';
if ( 'comprehensive' === $depth ) {
$queries[] = $topic . ' building codes sustainability LEED';
$queries[] = $topic . ' cost estimate schedule feasibility';
} elseif ( 'standard' === $depth ) {
$queries[] = $topic . ' building codes compliance';
}
break;

case 'ncs_drawing':
$queries[] = $topic . ' NCS CAD layer standards';
if ( 'comprehensive' === $depth ) {
$queries[] = $topic . ' AIA CAD guidelines sheet organization';
$queries[] = $topic . ' drawing standards dimension annotation';
} elseif ( 'standard' === $depth ) {
$queries[] = $topic . ' drawing organization standards';
}
break;

case 'csi_specification':
$queries[] = $topic . ' CSI MasterFormat three-part specification';
if ( 'comprehensive' === $depth ) {
$queries[] = $topic . ' ASTM standards specifications';
$queries[] = $topic . ' product manufacturers installation requirements';
} elseif ( 'standard' === $depth ) {
$queries[] = $topic . ' specifications standards';
}
break;

case 'general':
default:
$queries[] = $topic . ' industry standards best practices';
if ( 'comprehensive' === $depth ) {
$queries[] = $topic . ' research analysis';
$queries[] = $topic . ' recommendations guidelines';
} elseif ( 'standard' === $depth ) {
$queries[] = $topic . ' overview summary';
}
break;
}

return $queries;
}

/**
 * Deduplicate sources by URL.
 *
 * @param array $sources Sources array.
 * @return array Deduplicated sources.
 */
protected function deduplicate_sources( $sources ) {
$unique_sources = array();
$seen_urls      = array();

foreach ( $sources as $source ) {
if ( empty( $source['url'] ) ) {
continue;
}

$url = $source['url'];

if ( ! in_array( $url, $seen_urls, true ) ) {
$unique_sources[] = $source;
$seen_urls[]      = $url;
}
}

return $unique_sources;
}

/**
 * Build the research prompt for AI.
 *
 * @param string $topic          Research topic.
 * @param string $report_type    Report type.
 * @param string $depth          Research depth.
 * @param array  $focus_areas    Focus areas.
 * @param array  $search_results Search results from web search.
 * @return string Research prompt.
 */
protected function build_research_prompt( $topic, $report_type, $depth, $focus_areas, $search_results ) {
$prompt = sprintf(
"Research the following topic and generate a comprehensive, professional report:\n\n**Topic:** %s\n",
$topic
);

$prompt .= "**Report Type:** " . $this->get_report_type_label( $report_type ) . "\n";
$prompt .= "**Depth Level:** " . ucfirst( $depth ) . "\n\n";

// Add context from web search if available.
if ( ! empty( $search_results['sources'] ) ) {
$prompt      .= "**Available Research Sources:**\n";
$source_count = min( 5, count( $search_results['sources'] ) );
for ( $i = 0; $i < $source_count; $i++ ) {
$source  = $search_results['sources'][ $i ];
$prompt .= sprintf(
"[%d] %s - %s\n",
$i + 1,
! empty( $source['title'] ) ? $source['title'] : 'Source',
$source['url']
);
if ( ! empty( $source['snippet'] ) ) {
$prompt .= "    " . substr( $source['snippet'], 0, 150 ) . "...\n";
}
}
$prompt .= "\n";
}

// Add focus areas if provided.
if ( ! empty( $focus_areas ) ) {
$prompt .= "**Focus Areas:** " . implode( ', ', $focus_areas ) . "\n\n";
}

// Add report type-specific instructions.
$prompt .= $this->get_report_type_instructions( $report_type );

$prompt .= "\n**Output Format:**\n";
$prompt .= "Respond with a JSON object containing:\n";
$prompt .= "{\n";
$prompt .= '  "title": "Report title",\n';
$prompt .= '  "sections": [\n';
$prompt .= '    {"heading": "Section Name", "content": "Detailed content..."},\n';
$prompt .= '    ...\n';
$prompt .= '  ],\n';
$prompt .= '  "citations": ["Source 1", "Source 2", ...]\n';
$prompt .= "}\n\n";

$prompt .= "**Important Guidelines:**\n";
$prompt .= "- Use the provided research sources when available\n";
$prompt .= "- Include citations for all claims and data\n";
$prompt .= "- Follow industry-standard structure for the report type\n";
$prompt .= "- Write in professional, clear language\n";
$prompt .= "- Provide actionable insights and recommendations\n";
$prompt .= "- Ensure accuracy and cite all sources\n";

return $prompt;
}

/**
 * Get report type label.
 *
 * @param string $report_type Report type.
 * @return string Label.
 */
protected function get_report_type_label( $report_type ) {
$labels = array(
'aia_project'       => 'AIA Architectural Project Report',
'ncs_drawing'       => 'NCS Drawing Documentation Report',
'csi_specification' => 'CSI MasterFormat Specification Report',
'general'           => 'General Research Report',
);

return isset( $labels[ $report_type ] ) ? $labels[ $report_type ] : 'Research Report';
}

/**
 * Get report type-specific instructions.
 *
 * @param string $report_type Report type.
 * @return string Instructions.
 */
protected function get_report_type_instructions( $report_type ) {
switch ( $report_type ) {
case 'aia_project':
return "**Required Sections (AIA Standard):**\n"
. "1. Executive Summary - Project overview and key points\n"
. "2. Design Intent & Architectural Concepts - Design philosophy and approach\n"
. "3. Technical Design Solutions - Structural, mechanical, electrical systems\n"
. "4. Accessibility & Code Compliance - Building codes, ADA, life safety\n"
. "5. Sustainability Assessment - LEED, energy efficiency, green building\n"
. "6. Cost Estimating - Budget breakdown and value engineering\n"
. "7. Project Schedule & Phasing - Timeline, milestones, critical path\n\n";

case 'ncs_drawing':
return "**Required Sections (NCS Standard):**\n"
. "1. Drawing Set Organization - Sheet types, numbering, organization per UDS\n"
. "2. CAD Layer Structure - Discipline-Major-Minor-Status format (e.g., A-WALL-FULL-N)\n"
. "3. Drawing Types & Standards - Floor plans, elevations, sections, details\n"
. "4. Scale & Dimensioning - Standard scales, annotation conventions\n"
. "5. Quality Control Checklist - Layer naming compliance, coordination, accuracy\n\n";

case 'csi_specification':
return "**Required Sections (CSI MasterFormat Three-Part Format):**\n"
. "1. Part 1 - General - Scope, submittals, quality assurance, warranties\n"
. "2. Part 2 - Products - Materials, manufacturers, standards (ASTM, ANSI, UL)\n"
. "3. Part 3 - Execution - Installation methods, quality control, testing\n"
. "4. Division Organization - Appropriate CSI MasterFormat division (00-35)\n\n";

case 'general':
default:
return "**Suggested Report Structure:**\n"
. "1. Executive Summary - Key findings and recommendations\n"
. "2. Background & Context - Overview of topic and significance\n"
. "3. Research Findings - Detailed analysis and data\n"
. "4. Best Practices - Industry standards and recommendations\n"
. "5. Conclusions - Summary and action items\n\n";
}
}

/**
 * Perform AI research using the plugin's AI capabilities.
 *
 * @param string $prompt  Research prompt.
 * @param array  $context Execution context.
 * @return array|WP_Error Research results or error.
 */
protected function perform_ai_research( $prompt, $context ) {
// Get a suitable AI model for research.
$settings = get_option( 'wp_mcp_ai_settings', array() );
$provider = $this->get_research_provider( $settings );
$model    = $this->get_research_model( $provider, $settings );

if ( is_wp_error( $provider ) ) {
return $provider;
}

if ( is_wp_error( $model ) ) {
return $model;
}

// Build messages array.
$messages = array(
array(
'role'    => 'system',
'content' => 'You are a professional researcher and technical writer. You create comprehensive, well-structured research reports following industry standards. Always respond with valid JSON matching the requested format. Use provided sources when available to ensure accuracy.',
),
array(
'role'    => 'user',
'content' => $prompt,
),
);

// Call the appropriate AI client.
$client = $this->get_ai_client( $provider, $settings );

if ( is_wp_error( $client ) ) {
return $client;
}

// Make the API call.
$result = $client->create_chat_completion(
$messages,
array(
'model'       => $model,
'temperature' => 0.3, // Low temperature for factual, accurate content.
'max_tokens'  => 4000, // Allow for detailed reports.
)
);

if ( is_wp_error( $result ) ) {
return $result;
}

// Extract the content from the response.
if ( ! isset( $result['choices'][0]['message']['content'] ) ) {
return new WP_Error(
'wp_mcp_ai_invalid_response',
__( 'Invalid response from AI provider.', 'mcp-ai-wpoos-pro' )
);
}

return array(
'content'  => $result['choices'][0]['message']['content'],
'provider' => $provider,
'model'    => $model,
);
}

/**
 * Get the best available provider for research.
 *
 * @param array $settings Plugin settings.
 * @return string|WP_Error Provider name or error.
 */
protected function get_research_provider( $settings ) {
// Prefer OpenAI or Gemini for research tasks.
if ( ! empty( $settings['openai_api_key'] ) ) {
return 'openai';
}

if ( ! empty( $settings['gemini_api_key'] ) ) {
return 'gemini';
}

if ( ! empty( $settings['anthropic_api_key'] ) ) {
return 'anthropic';
}

return new WP_Error(
'wp_mcp_ai_no_provider',
__( 'No AI provider configured. Please configure OpenAI, Gemini, or Anthropic API keys in plugin settings.', 'mcp-ai-wpoos-pro' )
);
}

/**
 * Get the best model for the provider.
 *
 * @param string $provider Provider name.
 * @param array  $settings Plugin settings.
 * @return string|WP_Error Model name or error.
 */
protected function get_research_model( $provider, $settings ) {
switch ( $provider ) {
case 'openai':
// Prefer GPT-4 for research if available.
return isset( $settings['openai_model'] ) ? $settings['openai_model'] : 'gpt-4o';

case 'gemini':
return isset( $settings['gemini_model'] ) ? $settings['gemini_model'] : 'gemini-1.5-pro';

case 'anthropic':
return isset( $settings['anthropic_model'] ) ? $settings['anthropic_model'] : 'claude-3-5-sonnet-20241022';

default:
return new WP_Error(
'wp_mcp_ai_invalid_provider',
__( 'Invalid AI provider.', 'mcp-ai-wpoos-pro' )
);
}
}

/**
 * Get AI client for the provider.
 *
 * @param string $provider Provider name.
 * @param array  $settings Plugin settings.
 * @return object|WP_Error AI client or error.
 */
protected function get_ai_client( $provider, $settings ) {
switch ( $provider ) {
case 'openai':
if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
return new WP_Error(
'wp_mcp_ai_client_unavailable',
__( 'OpenAI client not available.', 'mcp-ai-wpoos-pro' )
);
}
return new WP_MCP_AI_OpenAI_Client( $settings['openai_api_key'] );

case 'gemini':
if ( ! class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
return new WP_Error(
'wp_mcp_ai_client_unavailable',
__( 'Gemini client not available.', 'mcp-ai-wpoos-pro' )
);
}
return new WP_MCP_AI_Gemini_Client( $settings['gemini_api_key'] );

case 'anthropic':
if ( ! class_exists( 'WP_MCP_AI_Anthropic_Client' ) ) {
return new WP_Error(
'wp_mcp_ai_client_unavailable',
__( 'Anthropic client not available.', 'mcp-ai-wpoos-pro' )
);
}
return new WP_MCP_AI_Anthropic_Client( $settings['anthropic_api_key'] );

default:
return new WP_Error(
'wp_mcp_ai_invalid_provider',
__( 'Invalid AI provider.', 'mcp-ai-wpoos-pro' )
);
}
}

/**
 * Parse and format the research results.
 *
 * @param array  $research_result AI research result.
 * @param string $topic           Research topic.
 * @param string $report_type     Report type.
 * @param array  $search_results  Search results.
 * @return array|WP_Error Formatted report data or error.
 */
protected function parse_and_format_research( $research_result, $topic, $report_type, $search_results ) {
$content = $research_result['content'];

// Extract JSON from markdown code blocks if present.
if ( preg_match( '/```json\s*(.*?)\s*```/s', $content, $matches ) ) {
$json = $matches[1];
} elseif ( preg_match( '/```\s*(.*?)\s*```/s', $content, $matches ) ) {
$json = $matches[1];
} else {
$json = $content;
}

// Parse JSON.
$data = json_decode( $json, true );

if ( json_last_error() !== JSON_ERROR_NONE ) {
return new WP_Error(
'wp_mcp_ai_parse_error',
sprintf(
/* translators: %s: JSON error message */
__( 'Failed to parse AI response as JSON: %s', 'mcp-ai-wpoos-pro' ),
json_last_error_msg()
)
);
}

// Validate required fields.
if ( empty( $data['title'] ) ) {
$data['title'] = ucwords( str_replace( '_', ' ', $report_type ) ) . ' Report: ' . $topic;
}

if ( empty( $data['sections'] ) || ! is_array( $data['sections'] ) ) {
return new WP_Error(
'wp_mcp_ai_invalid_data',
__( 'AI response missing required sections.', 'mcp-ai-wpoos-pro' )
);
}

// Format the report.
$title    = sanitize_text_field( $data['title'] );
$sections = $data['sections'];
$citations = isset( $data['citations'] ) && is_array( $data['citations'] ) ? $data['citations'] : array();

// Add sources from web search to citations if not already included.
if ( ! empty( $search_results['sources'] ) ) {
foreach ( $search_results['sources'] as $source ) {
if ( ! empty( $source['url'] ) ) {
$citation = isset( $source['title'] ) && ! empty( $source['title'] )
? $source['title'] . ' - ' . $source['url']
: $source['url'];
if ( ! in_array( $citation, $citations, true ) ) {
$citations[] = $citation;
}
}
}
}

// Build the formatted report.
$report  = "# {$title}\n\n";
$report .= '_Generated on ' . gmdate( 'F j, Y' ) . "_\n\n";

// Add table of contents if multiple sections.
if ( count( $sections ) > 1 ) {
$report .= "## Table of Contents\n\n";
foreach ( $sections as $i => $section ) {
$heading = isset( $section['heading'] ) ? $section['heading'] : 'Section ' . ( $i + 1 );
$report .= ( $i + 1 ) . '. ' . $heading . "\n";
}
$report .= "\n---\n\n";
}

// Add sections.
foreach ( $sections as $section ) {
$heading = isset( $section['heading'] ) ? sanitize_text_field( $section['heading'] ) : '';
$content = isset( $section['content'] ) ? wp_kses_post( $section['content'] ) : '';

if ( ! empty( $heading ) ) {
$report .= '## ' . $heading . "\n\n";
}
if ( ! empty( $content ) ) {
$report .= $content . "\n\n";
}
}

// Add citations.
if ( ! empty( $citations ) ) {
$report .= "## References\n\n";
foreach ( $citations as $i => $citation ) {
$report .= ( $i + 1 ) . '. ' . sanitize_text_field( $citation ) . "\n";
}
}

// Return structured response.
return array(
'success'       => true,
'title'         => $title,
'report'        => $report,
'word_count'    => str_word_count( $report ),
'section_count' => count( $sections ),
'sources'       => $search_results['sources'],
'report_type'   => $report_type,
);
}
}
