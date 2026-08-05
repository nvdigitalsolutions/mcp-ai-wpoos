<?php
/**
 * Generate Research Report Tool
 *
 * Generate professional research reports with citations, TOC, and formatting.
 * Performs comprehensive multi-step research using web search and AI analysis.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
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

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'generate_research_report';
	}

	/**
	 * Get tool definition.
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'generate_research_report',
			'description'         => 'Generate professional research reports with multi-step research, web search, citations, and industry-standard formatting. Supports AIA project reports, NCS drawing documentation, and CSI MasterFormat specifications. Can also format pre-written content.',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					// Multi-step research mode parameters.
					'topic'                  => array(
						'type'        => 'string',
						'description' => 'Research topic (e.g., "residential project feasibility" or "concrete specifications"). Use this for automatic research.',
					),
					'report_type'            => array(
						'type'        => 'string',
						'description' => 'Type of report to generate',
						'enum'        => array( 'aia_project', 'ncs_drawing', 'csi_specification', 'general' ),
					),
					'depth'                  => array(
						'type'        => 'string',
						'description' => 'Research depth level',
						'enum'        => array( 'basic', 'standard', 'comprehensive' ),
						'default'     => 'standard',
					),
					'focus_areas'            => array(
						'type'        => 'array',
						'description' => 'Specific areas to focus research on (e.g., ["sustainability", "cost estimating"])',
						'items'       => array( 'type' => 'string' ),
					),
					// Legacy formatting mode parameters (backward compatibility).
					'title'                  => array(
						'type'        => 'string',
						'description' => 'Report title (for formatting pre-written content)',
					),
					'sections'               => array(
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
					'include_toc'            => array(
						'type'        => 'boolean',
						'description' => 'Include table of contents (default: true)',
						'default'     => true,
					),
					'include_citations'      => array(
						'type'        => 'boolean',
						'description' => 'Include citations section (default: true)',
						'default'     => true,
					),
					'citations'              => array(
						'type'        => 'array',
						'description' => 'Pre-provided citations (for formatting mode)',
						'items'       => array( 'type' => 'string' ),
					),
					'save_to_paper_store'    => array(
						'type'        => 'boolean',
						'description' => __( 'Whether to save the generated report to the Paper Store as a temporary record for later review, export, or post creation.', 'mcp-ai-wpoos-pro' ),
						'default'     => false,
					),
					'paper_store_collection' => array(
						'type'        => 'string',
						'description' => __( 'Paper Store collection name for saving the report. Default: "research-reports". Only used when save_to_paper_store is true.', 'mcp-ai-wpoos-pro' ),
					),
					'create_draft_post'      => array(
						'type'        => 'boolean',
						'description' => __( 'Whether to automatically create a WordPress draft post from the research report content.', 'mcp-ai-wpoos-pro' ),
						'default'     => false,
					),
					'draft_post_type'        => array(
						'type'        => 'string',
						'description' => __( 'WordPress post type for the draft. Default: "post".', 'mcp-ai-wpoos-pro' ),
						'default'     => 'post',
					),
					'draft_post_status'      => array(
						'type'        => 'string',
						'description' => __( 'WordPress post status for the draft. Use "draft" for private review or "pending" for editorial workflow.', 'mcp-ai-wpoos-pro' ),
						'enum'        => array( 'draft', 'pending' ),
						'default'     => 'draft',
					),
					'draft_post_category'    => array(
						'type'        => 'integer',
						'description' => __( 'Category term ID to assign to the draft post.', 'mcp-ai-wpoos-pro' ),
					),
					'draft_post_tags'        => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Tags to assign to the draft post.', 'mcp-ai-wpoos-pro' ),
					),
				),
				'required'   => array(),
			),
			'required_capability' => 'edit_posts',
			'category'            => array( 'research', 'orchestration', 'content' ),
		);
	}


	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Determine execution mode: research mode vs formatting mode.
		$is_research_mode   = ! empty( $arguments['topic'] );
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

		// Optionally save report to Paper Store as temp storage.
		if ( ! empty( $arguments['save_to_paper_store'] ) ) {
			$paper_save_result = $this->save_report_to_paper_store( $report_data, $arguments, $context );
			if ( ! is_wp_error( $paper_save_result ) ) {
				$report_data['paper_store_id']         = $paper_save_result;
				$report_data['paper_store_collection'] = isset( $arguments['paper_store_collection'] ) && ! empty( $arguments['paper_store_collection'] )
					? sanitize_key( $arguments['paper_store_collection'] )
					: 'research-reports';

				/**
				 * Fires when research results are saved to the Paper Store.
				 *
				 * Unified hook for all research tools that persist to Paper Store.
				 *
				 * @since 1.4.0
				 *
				 * @param string $record_id  The Paper Store record ID.
				 * @param string $collection The Paper Store collection name.
				 * @param array  $data       The research report data that was saved.
				 * @param string $tool_slug  The tool that performed the save.
				 * @param array  $arguments  Original tool arguments.
				 * @param array  $context    Execution context.
				 */
				do_action( 'wp_mcp_ai_research_saved_to_paper_store', $paper_save_result, $report_data['paper_store_collection'], $report_data, 'generate_research_report', $arguments, $context );
			}
		}

		// Optionally create a WordPress draft post from the report.
		if ( ! empty( $arguments['create_draft_post'] ) ) {
			$draft_result = $this->create_draft_post_from_report( $report_data, $arguments, $context );
			if ( ! is_wp_error( $draft_result ) ) {
				$report_data['draft_post'] = $draft_result;

				/**
				 * Fires when a draft WordPress post is created from research data.
				 *
				 * @since 1.4.0
				 *
				 * @param int    $post_id    The WordPress post ID.
				 * @param string $post_type  The post type.
				 * @param string $post_status The post status.
				 * @param array  $data       The research data used.
				 * @param string $tool_slug  The tool that created the post.
				 * @param array  $arguments  Original tool arguments.
				 * @param array  $context    Execution context.
				 */
				do_action( 'wp_mcp_ai_research_draft_post_created', $draft_result['post_id'], $draft_result['post_type'], $draft_result['post_status'], $report_data, 'generate_research_report', $arguments, $context );
			}
		}

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
						'query'      => $search_query,
						'topic'      => $topic,
						'error_code' => $search_result->get_error_code(),
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

		$prompt .= '**Report Type:** ' . $this->get_report_type_label( $report_type ) . "\n";
		$prompt .= '**Depth Level:** ' . ucfirst( $depth ) . "\n\n";

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
						$prompt .= '    ' . substr( $source['snippet'], 0, 150 ) . "...\n";
				}
			}
			$prompt .= "\n";
		}

		// Add focus areas if provided.
		if ( ! empty( $focus_areas ) ) {
			$prompt .= '**Focus Areas:** ' . implode( ', ', $focus_areas ) . "\n\n";
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

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$model = $this->get_research_model( $provider, $settings );
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
		// $settings kept for backward compatibility with subclasses.
		unset( $settings );

		// Prefer OpenAI or Gemini for research tasks.
		if ( WP_MCP_AI_Credential_Resolver::has_credentials( 'openai' ) ) {
			return 'openai';
		}

		if ( WP_MCP_AI_Credential_Resolver::has_credentials( 'gemini' ) ) {
			return 'gemini';
		}

		if ( WP_MCP_AI_Credential_Resolver::has_credentials( 'anthropic' ) ) {
			return 'anthropic';
		}
		if ( WP_MCP_AI_Credential_Resolver::has_credentials( 'deepseek' ) ) {
			return 'deepseek';
		}

		// Providers requiring multi-field or non-standard credential checks.
		$settings_raw = get_option( 'wp_mcp_ai_settings', array() );
		if ( ! empty( $settings_raw['cloudflare_api_token'] ) && ! empty( $settings_raw['cloudflare_account_id'] ) && class_exists( 'WP_MCP_AI_Cloudflare_Client' ) ) {
			return 'cloudflare';
		}
		if ( ! empty( $settings_raw['huggingface_api_key'] ) && ! empty( $settings_raw['huggingface_endpoint_url'] ) && class_exists( 'WP_MCP_AI_Huggingface_Client' ) ) {
			return 'huggingface';
		}
		if ( ! empty( $settings_raw['ollama_endpoint_url'] ) && class_exists( 'WP_MCP_AI_Ollama_Client' ) ) {
			return 'ollama';
		}
		if ( ! empty( $settings_raw['lm_studio_endpoint_url'] ) && class_exists( 'WP_MCP_AI_LM_Studio_Client' ) ) {
			return 'lm_studio';
		}

		// Standard API-key providers.
		if ( WP_MCP_AI_Credential_Resolver::has_credentials( 'openrouter' ) ) {
			return 'openrouter';
		}
		if ( WP_MCP_AI_Credential_Resolver::has_credentials( 'nvidia' ) ) {
			return 'nvidia';
		}
		if ( WP_MCP_AI_Credential_Resolver::has_credentials( 'digitalocean' ) ) {
			return 'digitalocean';
		}
		if ( WP_MCP_AI_Credential_Resolver::has_credentials( 'kimi' ) ) {
			return 'kimi';
		}
		if ( WP_MCP_AI_Credential_Resolver::has_credentials( 'baseten' ) ) {
			return 'baseten';
		}
		if ( WP_MCP_AI_Credential_Resolver::has_credentials( 'zai' ) ) {
			return 'zai';
		}

		return new WP_Error(
			'wp_mcp_ai_no_provider',
			__( 'No AI provider configured. Please configure an AI provider (OpenAI, Gemini, Anthropic, DeepSeek, Cloudflare, HuggingFace, Ollama, OpenRouter, NVIDIA, LM Studio, DigitalOcean, Kimi, Baseten, or Z.AI) in plugin settings.', 'mcp-ai-wpoos-pro' )
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
				return isset( $settings['openai_model'] ) ? $settings['openai_model'] : 'gpt-4.1';

			case 'gemini':
				return isset( $settings['gemini_model'] ) ? $settings['gemini_model'] : 'gemini-2.5-flash';

			case 'anthropic':
				return isset( $settings['anthropic_model'] ) ? $settings['anthropic_model'] : 'claude-sonnet-5';

			case 'deepseek':
				return ! empty( $settings['deepseek_model'] ) ? $settings['deepseek_model'] : 'deepseek-chat';

			case 'cloudflare':
				return ! empty( $settings['cloudflare_model'] ) ? $settings['cloudflare_model'] : '@cf/meta/llama-4-scout-17b-16e-instruct';

			case 'huggingface':
				return ! empty( $settings['huggingface_model'] ) ? $settings['huggingface_model'] : 'meta-llama/Llama-3.3-70B-Instruct';

			case 'ollama':
				return ! empty( $settings['ollama_model'] ) ? $settings['ollama_model'] : 'llama3.3';

			case 'openrouter':
				return ! empty( $settings['openrouter_model'] ) ? $settings['openrouter_model'] : 'openrouter/auto';

			case 'nvidia':
				return ! empty( $settings['nvidia_model'] ) ? $settings['nvidia_model'] : 'meta/llama-3.1-8b-instruct';

			case 'lm_studio':
				return ! empty( $settings['lm_studio_model'] ) ? $settings['lm_studio_model'] : '';

			case 'digitalocean':
				return ! empty( $settings['digitalocean_model'] ) ? $settings['digitalocean_model'] : 'llama3.3-70b-instruct';

			case 'kimi':
				return ! empty( $settings['kimi_model'] ) ? $settings['kimi_model'] : 'kimi-k2.7-code';

			case 'baseten':
				return ! empty( $settings['baseten_model'] ) ? $settings['baseten_model'] : 'deepseek-ai/DeepSeek-V3';

			case 'zai':
				return ! empty( $settings['zai_model'] ) ? $settings['zai_model'] : 'glm-4';

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
		// $settings kept for backward compatibility with subclasses.
		unset( $settings );

		switch ( $provider ) {
			case 'openai':
				if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'OpenAI client not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				return new WP_MCP_AI_OpenAI_Client( WP_MCP_AI_Credential_Resolver::get_api_key( 'openai' ) );

			case 'gemini':
				if ( ! class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'Gemini client not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				return new WP_MCP_AI_Gemini_Client( WP_MCP_AI_Credential_Resolver::get_api_key( 'gemini' ) );

			case 'anthropic':
				if ( ! class_exists( 'WP_MCP_AI_Anthropic_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'Anthropic client not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				return new WP_MCP_AI_Anthropic_Client( WP_MCP_AI_Credential_Resolver::get_api_key( 'anthropic' ) );

			case 'deepseek':
				if ( ! class_exists( 'WP_MCP_AI_DeepSeek_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'DeepSeek client not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				return new WP_MCP_AI_DeepSeek_Client( WP_MCP_AI_Credential_Resolver::get_api_key( 'deepseek' ) );

			case 'cloudflare':
				if ( ! class_exists( 'WP_MCP_AI_Cloudflare_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'Cloudflare client not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				return new WP_MCP_AI_Cloudflare_Client();

			case 'huggingface':
				if ( ! class_exists( 'WP_MCP_AI_Huggingface_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'HuggingFace client not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				return new WP_MCP_AI_Huggingface_Client();

			case 'ollama':
				if ( ! class_exists( 'WP_MCP_AI_Ollama_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'Ollama client not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				return new WP_MCP_AI_Ollama_Client();

			case 'openrouter':
				if ( ! class_exists( 'WP_MCP_AI_OpenRouter_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'OpenRouter client not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				return new WP_MCP_AI_OpenRouter_Client();

			case 'nvidia':
				if ( ! class_exists( 'WP_MCP_AI_Nvidia_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'NVIDIA client not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				return new WP_MCP_AI_Nvidia_Client();

			case 'lm_studio':
				if ( ! class_exists( 'WP_MCP_AI_LM_Studio_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'LM Studio client not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				return new WP_MCP_AI_LM_Studio_Client();

			case 'digitalocean':
				if ( ! class_exists( 'WP_MCP_AI_DigitalOcean_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'DigitalOcean client not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				return new WP_MCP_AI_DigitalOcean_Client();

			case 'kimi':
				if ( ! class_exists( 'WP_MCP_AI_Kimi_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'Kimi client not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				return new WP_MCP_AI_Kimi_Client();

			case 'baseten':
				if ( ! class_exists( 'WP_MCP_AI_Baseten_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'Baseten client not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				return new WP_MCP_AI_Baseten_Client();

			case 'zai':
				if ( ! class_exists( 'WP_MCP_AI_ZAI_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'Z.AI client not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				return new WP_MCP_AI_ZAI_Client();

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
		$title     = sanitize_text_field( $data['title'] );
		$sections  = $data['sections'];
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

	/**
	 * Save a research report to the Paper Store.
	 *
	 * @since 1.4.0
	 *
	 * @param array $report_data The formatted report data from parse_and_format_research().
	 * @param array $arguments   Tool arguments.
	 * @param array $context     Execution context.
	 * @return string|WP_Error   Record ID on success, WP_Error on failure.
	 */
	private function save_report_to_paper_store( $report_data, $arguments, $context ) {
		// Gate 1 — Sanitize at entry.
		$collection = isset( $arguments['paper_store_collection'] ) && ! empty( $arguments['paper_store_collection'] )
			? sanitize_key( $arguments['paper_store_collection'] )
			: 'research-reports';

		$title  = isset( $report_data['title'] ) ? sanitize_text_field( $report_data['title'] ) : __( 'Research Report', 'mcp-ai-wpoos-pro' );
		$report = isset( $report_data['report'] ) ? $report_data['report'] : '';

		// Generate unique record ID.
		$slug = sanitize_title( $title );
		if ( strlen( $slug ) > 40 ) {
			$slug = substr( $slug, 0, 40 );
		}
		$record_id = $slug . '-' . substr( md5( $title . time() ), 0, 8 );

		// Build tags from report metadata.
		$tags = array( 'research-report' );
		if ( isset( $report_data['report_type'] ) ) {
			$tags[] = sanitize_key( $report_data['report_type'] );
		}

		// Build record.
		$record = array(
			'id'          => $record_id,
			'type'        => $collection,
			'title'       => $title,
			'description' => sprintf(
				/* translators: 1: title, 2: word count, 3: section count */
				__( 'Research report "%1$s" — %2$d words across %3$d sections.', 'mcp-ai-wpoos-pro' ),
				$title,
				isset( $report_data['word_count'] ) ? (int) $report_data['word_count'] : str_word_count( $report ),
				isset( $report_data['section_count'] ) ? (int) $report_data['section_count'] : 0
			),
			'tags'        => $tags,
			'status'      => 'draft',
			'meta'        => array(
				'report_type'   => isset( $report_data['report_type'] ) ? $report_data['report_type'] : 'general',
				'word_count'    => isset( $report_data['word_count'] ) ? (int) $report_data['word_count'] : str_word_count( $report ),
				'section_count' => isset( $report_data['section_count'] ) ? (int) $report_data['section_count'] : 0,
				'user_id'       => isset( $context['user_id'] ) ? (int) $context['user_id'] : get_current_user_id(),
				'topic'         => isset( $arguments['topic'] ) ? sanitize_text_field( $arguments['topic'] ) : '',
			),
			'body'        => array(
				'markdown' => $report,
				'sources'  => isset( $report_data['sources'] ) ? $report_data['sources'] : array(),
			),
		);

		// Save to Paper Store.
		$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
		$repo    = $manager->get_repository( $collection );

		$saved = $repo->save( $record );

		if ( is_wp_error( $saved ) ) {
			WP_MCP_AI_Logger::log_error(
				'Failed to save research report to Paper Store: ' . $saved->get_error_message(),
				array(
					'title'      => $title,
					'collection' => $collection,
					'record_id'  => $record_id,
				)
			);
			return $saved;
		}

		return $record_id;
	}

	/**
	 * Create a WordPress draft post from a research report.
	 *
	 * @since 1.4.0
	 *
	 * @param array $report_data The formatted report data.
	 * @param array $arguments   Tool arguments.
	 * @param array $context     Execution context.
	 * @return array|WP_Error    Array with post_id, post_type, post_status, edit_url on success.
	 */
	private function create_draft_post_from_report( $report_data, $arguments, $context ) {
		// Gate 1 — Sanitize at entry.
		$post_type   = isset( $arguments['draft_post_type'] ) ? sanitize_key( $arguments['draft_post_type'] ) : 'post';
		$post_status = isset( $arguments['draft_post_status'] ) ? sanitize_key( $arguments['draft_post_status'] ) : 'draft';
		$category_id = isset( $arguments['draft_post_category'] ) ? absint( $arguments['draft_post_category'] ) : 0;

		// Validate post type.
		if ( ! post_type_exists( $post_type ) ) {
			$post_type = 'post';
		}

		// Validate post status.
		$allowed_statuses = array( 'draft', 'pending' );
		if ( ! in_array( $post_status, $allowed_statuses, true ) ) {
			$post_status = 'draft';
		}

		// Check publish capability.
		$user_id          = isset( $context['user_id'] ) ? (int) $context['user_id'] : get_current_user_id();
		$post_type_object = get_post_type_object( $post_type );
		if ( $post_type_object && ! user_can( $user_id, $post_type_object->cap->publish_posts ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				sprintf(
					/* translators: %s: post type */
					__( 'You do not have permission to create %s posts.', 'mcp-ai-wpoos-pro' ),
					$post_type
				)
			);
		}

		$title   = isset( $report_data['title'] ) ? sanitize_text_field( $report_data['title'] ) : __( 'Research Report', 'mcp-ai-wpoos-pro' );
		$content = isset( $report_data['report'] ) ? wp_kses_post( $report_data['report'] ) : '';

		// Build post data.
		$post_data = array(
			'post_type'    => $post_type,
			'post_status'  => $post_status,
			'post_title'   => $title,
			'post_content' => $content,
			'post_author'  => $user_id,
		);

		// Add category if provided and post type supports it.
		if ( $category_id > 0 && is_object_in_taxonomy( $post_type, 'category' ) ) {
			$post_data['post_category'] = array( $category_id );
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			WP_MCP_AI_Logger::log_error(
				'Failed to create draft post from research report: ' . $post_id->get_error_message(),
				array(
					'title'     => $title,
					'post_type' => $post_type,
				)
			);
			return $post_id;
		}

		// Set tags if provided and post type supports it.
		if ( isset( $arguments['draft_post_tags'] ) && is_array( $arguments['draft_post_tags'] ) && is_object_in_taxonomy( $post_type, 'post_tag' ) ) {
			$tag_names = array_map( 'sanitize_text_field', $arguments['draft_post_tags'] );
			$tag_names = array_filter( $tag_names );
			if ( ! empty( $tag_names ) ) {
				wp_set_post_tags( $post_id, $tag_names, false );
			}
		}

		// Gate 2 — Escape at exit.
		return array(
			'post_id'     => $post_id,
			'post_type'   => esc_html( $post_type ),
			'post_status' => esc_html( $post_status ),
			'edit_url'    => esc_url( get_edit_post_link( $post_id, 'raw' ) ),
			'permalink'   => esc_url( get_permalink( $post_id ) ),
		);
	}
}
