<?php
/**
 * Generate Research Report Tool
 *
 * Generate professional research reports with citations, TOC, and formatting.
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

	public function get_slug() {
		return 'generate_research_report';
	}

	public function get_definition() {
		return array(
			'name'                => 'generate_research_report',
			'description'         => 'Generate professional research reports with table of contents, citations, executive summary, and proper formatting.',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'title'             => array(
						'type'        => 'string',
						'description' => 'Report title',
					),
					'sections'          => array(
						'type'        => 'array',
						'description' => 'Report sections with content',
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
						'description' => 'List of citations/sources',
						'items'       => array( 'type' => 'string' ),
					),
				),
				'required'   => array( 'title', 'sections' ),
			),
			'required_capability' => 'edit_posts',
			'category'            => array( 'research', 'orchestration', 'content' ),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ) {
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
}
