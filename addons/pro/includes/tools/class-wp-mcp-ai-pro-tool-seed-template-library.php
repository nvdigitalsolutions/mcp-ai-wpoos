<?php
/**
 * Tool: Seed Template Library
 *
 * Seeds the template library with pre-built professional templates for common workflows
 *
 * @package MCP_AI_WP_OOS_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seed Template Library Tool Class
 */
class WP_MCP_AI_Pro_Tool_Seed_Template_Library {

	/**
	 * Get tool slug
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'seed_template_library';
	}

	/**
	 * Get tool definition for AI
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'        => 'seed_template_library',
			'description' => 'Seeds the template library with pre-built professional templates for common workflows (research, content creation, data analysis, marketing). Only needs to be called once to set up the library.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'overwrite' => array(
						'type'        => 'boolean',
						'description' => 'Whether to overwrite existing templates with same names (default: false)',
					),
				),
				'required' => array(),
			),
		);
	}

	/**
	 * Execute the tool
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array
	 */
	public function execute( $arguments, $context ) {
		$overwrite = $arguments['overwrite'] ?? false;

		// Get pre-built templates.
		$templates = $this->get_prebuilt_templates();

		$created   = array();
		$skipped   = array();
		$errors    = array();

		foreach ( $templates as $template ) {
			// Check if template already exists.
			if ( ! $overwrite && $this->template_exists( $template['template_name'] ) ) {
				$skipped[] = $template['template_name'];
				continue;
			}

			// Create template using create_template tool.
			$create_tool = new WP_MCP_AI_Pro_Tool_Create_Template();
			$result      = $create_tool->execute( $template, $context );

			if ( $result['success'] ) {
				// Update status to published for pre-built templates.
				$template_id = $result['template_id'];
				$use_cct     = 'cct' === $result['storage_type'];

				if ( $use_cct ) {
					$handler = WP_MCP_AI_Task_Templates_CCT::get_item_handler();
					if ( $handler ) {
						$handler->update_item( $template_id, array( 'status' => 'published' ) );
					}
				} else {
					wp_update_post(
						array(
							'ID'          => $template_id,
							'post_status' => 'publish',
						)
					);
					update_post_meta( $template_id, 'status', 'published' );
				}

				$created[] = array(
					'name'     => $template['template_name'],
					'category' => $template['category'],
					'id'       => $template_id,
				);
			} else {
				$errors[] = array(
					'name'  => $template['template_name'],
					'error' => $result['error'] ?? 'Unknown error',
				);
			}
		}

		return array(
			'success'        => true,
			'templates_created' => count( $created ),
			'templates_skipped' => count( $skipped ),
			'templates_errors'  => count( $errors ),
			'created'        => $created,
			'skipped'        => $skipped,
			'errors'         => $errors,
			'message'        => sprintf(
				'Template library seeded: %d created, %d skipped, %d errors',
				count( $created ),
				count( $skipped ),
				count( $errors )
			),
		);
	}

	/**
	 * Check if template exists by name
	 *
	 * @param string $name Template name.
	 * @return bool
	 */
	private function template_exists( $name ) {
		$use_cct = $this->should_use_cct();

		if ( $use_cct ) {
			$handler = WP_MCP_AI_Task_Templates_CCT::get_item_handler();
			if ( ! $handler ) {
				return false;
			}
			$factory   = jet_engine()->listings->data->get_listing_data( 'mcp_task_templates' );
			$templates = $factory ? $factory->db->query( array( 'template_name' => $name ) ) : array();
			return ! empty( $templates );
		} else {
			$query = new WP_Query(
				array(
					'post_type'      => 'mcp_task_template',
					'post_status'    => 'any',
					'title'          => $name,
					'posts_per_page' => 1,
				)
			);
			return $query->have_posts();
		}
	}

	/**
	 * Get pre-built templates
	 *
	 * @return array
	 */
	private function get_prebuilt_templates() {
		return array(
			// Research templates.
			array(
				'template_name'     => 'Comprehensive Market Research',
				'description'       => 'Deep-dive market research template for analyzing industry trends, competitors, and opportunities',
				'category'          => 'research',
				'markdown_template' => "# {{goal}}\n\n## Research Objectives\n- [ ] Define research scope and key questions\n- [ ] Identify primary data sources\n- [ ] Set success criteria\n\n## Data Collection\n- [ ] Search for {{topic}} industry overview\n- [ ] Analyze top {{competitor_count}} competitors\n- [ ] Review {{source_count}} authoritative sources\n- [ ] Extract market size and trends data\n- [ ] Identify key players and stakeholders\n\n## Analysis\n- [ ] Aggregate research findings\n- [ ] Cross-verify critical information\n- [ ] Identify patterns and trends\n- [ ] Analyze competitive landscape\n- [ ] Assess market opportunities and threats\n\n## Reporting\n- [ ] Generate executive summary\n- [ ] Create detailed research report with citations\n- [ ] Prepare recommendations based on findings\n- [ ] Review and finalize report\n\n**Estimated Time:** {{estimated_hours}} hours\n**Max Iterations:** {{max_iterations}}\n**Token Budget:** {{token_budget}}",
				'default_config'    => array(
					'max_iterations' => 25,
					'token_budget'   => 15000,
				),
				'tags'              => array( 'market-research', 'analysis', 'competitive-intelligence' ),
				'version'           => '1.0.0',
			),
			array(
				'template_name'     => 'Quick Topic Research',
				'description'       => 'Fast research template for quick topic exploration and summary generation',
				'category'          => 'research',
				'markdown_template' => "# Quick Research: {{topic}}\n\n## Tasks\n- [ ] Search for {{topic}} overview\n- [ ] Find {{source_count}} reliable sources\n- [ ] Extract key facts and statistics\n- [ ] Summarize main points\n- [ ] Create markdown report\n\n**Max Iterations:** {{max_iterations}}\n**Token Budget:** {{token_budget}}",
				'default_config'    => array(
					'max_iterations' => 10,
					'token_budget'   => 5000,
				),
				'tags'              => array( 'quick-research', 'summary' ),
				'version'           => '1.0.0',
			),

			// Content templates.
			array(
				'template_name'     => 'Blog Series Creator',
				'description'       => 'Create a complete blog series with multiple interconnected articles',
				'category'          => 'content',
				'markdown_template' => "# Blog Series: {{series_title}}\n\n## Planning\n- [ ] Define series theme and objectives\n- [ ] Outline {{article_count}} article topics\n- [ ] Research keywords and SEO strategy\n- [ ] Create content calendar\n\n## Content Creation\n- [ ] Write article 1: {{article_1_topic}}\n- [ ] Write article 2: {{article_2_topic}}\n- [ ] Write article 3: {{article_3_topic}}\n- [ ] Add more articles as needed\n- [ ] Cross-link articles for series cohesion\n\n## Optimization\n- [ ] Add meta descriptions and keywords\n- [ ] Optimize headings and structure\n- [ ] Include relevant images/media\n- [ ] Proofread and edit\n\n## Publishing\n- [ ] Schedule publication dates\n- [ ] Prepare social media promotions\n- [ ] Set up tracking and analytics\n\n**Max Iterations:** {{max_iterations}}\n**Token Budget:** {{token_budget}}",
				'default_config'    => array(
					'max_iterations' => 30,
					'token_budget'   => 20000,
				),
				'tags'              => array( 'blog', 'content-series', 'seo' ),
				'version'           => '1.0.0',
			),

			// Data Analysis templates.
			array(
				'template_name'     => 'Dataset Analysis Pipeline',
				'description'       => 'Systematic data analysis workflow from collection to insights',
				'category'          => 'data_analysis',
				'markdown_template' => "# Data Analysis: {{dataset_name}}\n\n## Data Collection\n- [ ] Define data requirements\n- [ ] Collect {{source_count}} data sources\n- [ ] Validate data quality\n- [ ] Clean and normalize data\n\n## Exploration\n- [ ] Calculate summary statistics\n- [ ] Identify data patterns and trends\n- [ ] Detect outliers and anomalies\n- [ ] Analyze correlations\n\n## Analysis\n- [ ] Apply statistical tests\n- [ ] Generate visualizations\n- [ ] Interpret findings\n- [ ] Validate hypotheses\n\n## Reporting\n- [ ] Create analysis report\n- [ ] Document methodology\n- [ ] Present actionable insights\n- [ ] Make recommendations\n\n**Max Iterations:** {{max_iterations}}\n**Token Budget:** {{token_budget}}",
				'default_config'    => array(
					'max_iterations' => 20,
					'token_budget'   => 12000,
				),
				'tags'              => array( 'data-analysis', 'statistics', 'insights' ),
				'version'           => '1.0.0',
			),

			// Marketing templates.
			array(
				'template_name'     => 'Marketing Campaign Planner',
				'description'       => 'Complete marketing campaign planning from strategy to execution',
				'category'          => 'marketing',
				'markdown_template' => "# Marketing Campaign: {{campaign_name}}\n\n## Strategy\n- [ ] Define campaign objectives and KPIs\n- [ ] Identify target audience segments\n- [ ] Analyze competitor campaigns\n- [ ] Set budget and timeline\n\n## Content Planning\n- [ ] Create content calendar\n- [ ] Plan {{content_piece_count}} content pieces\n- [ ] Design creative assets\n- [ ] Write copy and messaging\n\n## Channel Strategy\n- [ ] Plan {{channel_count}} marketing channels\n- [ ] Create channel-specific content\n- [ ] Set up tracking and attribution\n- [ ] Configure automation workflows\n\n## Execution\n- [ ] Launch campaign\n- [ ] Monitor performance metrics\n- [ ] Optimize based on data\n- [ ] Generate performance report\n\n**Max Iterations:** {{max_iterations}}\n**Token Budget:** {{token_budget}}",
				'default_config'    => array(
					'max_iterations' => 25,
					'token_budget'   => 15000,
				),
				'tags'              => array( 'marketing', 'campaign', 'strategy' ),
				'version'           => '1.0.0',
			),

			// Development templates.
			array(
				'template_name'     => 'Feature Implementation Plan',
				'description'       => 'Structured approach to implementing new software features',
				'category'          => 'development',
				'markdown_template' => "# Feature: {{feature_name}}\n\n## Planning\n- [ ] Define feature requirements\n- [ ] Create technical specifications\n- [ ] Design system architecture\n- [ ] Identify dependencies\n\n## Implementation\n- [ ] Set up development environment\n- [ ] Implement core functionality\n- [ ] Add error handling\n- [ ] Write unit tests\n- [ ] Perform integration testing\n\n## Quality Assurance\n- [ ] Code review\n- [ ] Security audit\n- [ ] Performance testing\n- [ ] Fix identified issues\n\n## Documentation\n- [ ] Write technical documentation\n- [ ] Create user guide\n- [ ] Update API documentation\n- [ ] Prepare release notes\n\n## Deployment\n- [ ] Deploy to staging\n- [ ] Final testing\n- [ ] Deploy to production\n- [ ] Monitor for issues\n\n**Max Iterations:** {{max_iterations}}\n**Token Budget:** {{token_budget}}",
				'default_config'    => array(
					'max_iterations' => 30,
					'token_budget'   => 20000,
				),
				'tags'              => array( 'development', 'feature', 'implementation' ),
				'version'           => '1.0.0',
			),
		);
	}

	/**
	 * Check if should use CCT
	 *
	 * @return bool
	 */
	private function should_use_cct() {
		if ( ! class_exists( 'Jet_Engine' ) ) {
			return false;
		}
		if ( ! class_exists( 'WP_MCP_AI_Task_Templates_CCT' ) ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_project_settings', array() );
		return ! empty( $settings['use_cct_storage'] );
	}
}
