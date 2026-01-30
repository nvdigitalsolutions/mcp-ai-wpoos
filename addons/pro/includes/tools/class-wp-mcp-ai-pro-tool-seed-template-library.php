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
			'name'         => 'seed_template_library',
			'description'  => 'Seeds the template library with pre-built professional templates for common workflows (research, content creation, data analysis, marketing). Only needs to be called once to set up the library.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'overwrite' => array(
						'type'        => 'boolean',
						'description' => 'Whether to overwrite existing templates with same names (default: false)',
					),
				),
				'required'   => array(),
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
	public function execute( array $arguments = array(), array $context = array() ) {
		$overwrite = $arguments['overwrite'] ?? false;

		// Get pre-built templates.
		$templates = $this->get_prebuilt_templates();

		$created = array();
		$skipped = array();
		$errors  = array();

		foreach ( $templates as $template ) {
			// Check if template already exists.
			if ( ! $overwrite && $this->template_exists( $template['template_name'] ) ) {
				$skipped[] = $template['template_name'];
				continue;
			}

			// Ensure the create_template tool class is loaded.
			if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Create_Template' ) ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-pro-tool-create-template.php';
			}

			// Create template using create_template tool.
			$create_tool = new WP_MCP_AI_Pro_Tool_Create_Template();
			$result      = $create_tool->execute( $template, $context );

			if ( $result['success'] ) {
				// Update status to published for pre-built templates.
				$template_id = $result['template_id'];
				$use_cct     = 'cct' === $result['storage_type'];

				if ( $use_cct ) {
					// Ensure CCT class is loaded.
					if ( ! class_exists( 'WP_MCP_AI_Task_Templates_CCT' ) ) {
						if ( defined( 'WP_MCP_AI_PRO_PATH' ) && file_exists( WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-task-templates-cct.php' ) ) {
							require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-task-templates-cct.php';
						}
					}

					if ( class_exists( 'WP_MCP_AI_Task_Templates_CCT' ) ) {
						$handler = WP_MCP_AI_Task_Templates_CCT::get_item_handler();
						if ( $handler ) {
							$handler->update_item( $template_id, array( 'status' => 'published' ) );
						}
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
			'success'           => true,
			'templates_created' => count( $created ),
			'templates_skipped' => count( $skipped ),
			'templates_errors'  => count( $errors ),
			'created'           => $created,
			'skipped'           => $skipped,
			'errors'            => $errors,
			'message'           => sprintf(
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
			// Ensure CCT class is loaded.
			if ( ! class_exists( 'WP_MCP_AI_Task_Templates_CCT' ) ) {
				if ( defined( 'WP_MCP_AI_PRO_PATH' ) && file_exists( WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-task-templates-cct.php' ) ) {
					require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-task-templates-cct.php';
				} else {
					return false;
				}
			}

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

			// E-commerce Toolkit templates.
			array(
				'template_name'     => 'E-commerce Store Launch',
				'description'       => 'Complete e-commerce store setup from platform configuration to go-live (E-commerce Toolkit)',
				'category'          => 'ecommerce',
				'markdown_template' => "# E-commerce Store: {{store_name}}\n\n## Foundation Setup\n- [ ] Configure WooCommerce and essential settings\n- [ ] Set up payment gateways ({{payment_methods}})\n- [ ] Configure shipping zones and rates for {{shipping_regions}}\n- [ ] Install SSL certificate and security features\n\n## Store Configuration\n- [ ] Customize theme and branding\n- [ ] Create essential pages (About, Contact, Policies, Shipping, Returns)\n- [ ] Set up tax rules for {{tax_regions}}\n- [ ] Configure inventory tracking\n\n## Product Management\n- [ ] Add {{product_count}} products with descriptions and images\n- [ ] Organize products into {{category_count}} categories\n- [ ] Set up product variations and attributes\n- [ ] Configure stock alerts and low inventory notifications\n\n## Marketing & SEO\n- [ ] Install analytics tracking (Google Analytics, pixels)\n- [ ] Set up email automation (welcome, abandoned cart, order confirmation)\n- [ ] Optimize product pages for SEO (meta, schema markup)\n- [ ] Create {{promotion_count}} promotional campaigns\n\n## Testing & Launch\n- [ ] Test complete checkout flow with test orders\n- [ ] Verify mobile responsiveness\n- [ ] Check page load speeds and optimize\n- [ ] Launch store and monitor initial performance\n\n**Toolkit:** E-commerce\n**Max Iterations:** {{max_iterations}}\n**Token Budget:** {{token_budget}}",
				'default_config'    => array(
					'max_iterations' => 30,
					'token_budget'   => 18000,
					'product_count'  => 20,
					'category_count' => 5,
				),
				'tags'              => array( 'ecommerce', 'woocommerce', 'store-launch', 'pro-toolkit' ),
				'version'           => '1.0.0',
			),

			// Social Media Toolkit templates.
			array(
				'template_name'     => 'Social Media Campaign Launch',
				'description'       => 'Multi-platform social media campaign with scheduling and analytics (Social Media Toolkit)',
				'category'          => 'social_media',
				'markdown_template' => "# Social Media Campaign: {{campaign_name}}\n\n## Campaign Planning\n- [ ] Define campaign goals and KPIs ({{target_metric}})\n- [ ] Research target audience and competitors\n- [ ] Select platforms: {{platforms}}\n- [ ] Create content calendar for {{campaign_duration}} days\n\n## Content Creation\n- [ ] Develop brand messaging and voice guidelines\n- [ ] Design {{asset_count}} graphics/videos for all platforms\n- [ ] Write {{post_count}} post copies with CTAs\n- [ ] Prepare landing pages and tracking URLs\n\n## Campaign Setup\n- [ ] Schedule content using social media automation tools\n- [ ] Set up campaign tracking (UTM parameters, pixels)\n- [ ] Configure social listening for {{brand_keywords}}\n- [ ] Set up automated responses and chatbot flows\n\n## Execution & Monitoring\n- [ ] Launch campaign across all platforms\n- [ ] Monitor engagement metrics (reach, clicks, conversions)\n- [ ] Respond to comments and messages within {{response_time}} hours\n- [ ] A/B test content variations\n\n## Analysis & Reporting\n- [ ] Track campaign performance against KPIs\n- [ ] Generate performance reports with visualizations\n- [ ] Document learnings and optimization opportunities\n- [ ] Prepare final campaign summary\n\n**Toolkit:** Social Media\n**Max Iterations:** {{max_iterations}}\n**Token Budget:** {{token_budget}}",
				'default_config'    => array(
					'max_iterations'    => 25,
					'token_budget'      => 15000,
					'campaign_duration' => 30,
					'post_count'        => 20,
					'asset_count'       => 15,
				),
				'tags'              => array( 'social-media', 'campaign', 'multi-platform', 'pro-toolkit' ),
				'version'           => '1.0.0',
			),

			// Financial Planner Toolkit templates.
			array(
				'template_name'     => 'Client Financial Portfolio Analysis',
				'description'       => 'Comprehensive financial planning with retirement projections and investment strategy (Financial Planner Toolkit)',
				'category'          => 'financial_planning',
				'markdown_template' => "# Financial Plan: {{client_name}}\n\n## Client Discovery\n- [ ] Schedule initial consultation\n- [ ] Gather financial documents (income, expenses, assets, liabilities)\n- [ ] Document goals (retirement at age {{retirement_age}}, {{other_goals}})\n- [ ] Assess risk tolerance using questionnaire\n- [ ] Define time horizons for {{goal_count}} financial goals\n\n## Current Situation Analysis\n- [ ] Analyze current cash flow and net worth\n- [ ] Review existing investments ({{current_portfolio_value}})\n- [ ] Evaluate insurance coverage and protection needs\n- [ ] Assess debt obligations ({{total_debt}})\n- [ ] Calculate savings rate and emergency fund adequacy\n\n## Financial Planning\n- [ ] Project retirement income needs ({{retirement_income_target}}/year)\n- [ ] Calculate required savings rate to meet goals\n- [ ] Develop tax-efficient investment strategy\n- [ ] Recommend portfolio allocation based on risk profile\n- [ ] Identify estate planning considerations\n\n## Investment Strategy\n- [ ] Design diversified portfolio across {{asset_classes}} asset classes\n- [ ] Recommend rebalancing strategy\n- [ ] Select low-cost investment vehicles\n- [ ] Plan tax-loss harvesting opportunities\n- [ ] Set up automatic investment contributions\n\n## Presentation & Implementation\n- [ ] Prepare comprehensive financial plan presentation\n- [ ] Review recommendations with client\n- [ ] Address questions and adjust plan as needed\n- [ ] Assist with account setup and transfers\n- [ ] Schedule {{review_frequency}} review meetings\n\n**Toolkit:** Financial Planner\n**Max Iterations:** {{max_iterations}}\n**Token Budget:** {{token_budget}}",
				'default_config'    => array(
					'max_iterations' => 35,
					'token_budget'   => 20000,
					'retirement_age' => 65,
					'goal_count'     => 3,
				),
				'tags'              => array( 'financial-planning', 'retirement', 'investment', 'pro-toolkit' ),
				'version'           => '1.0.0',
			),

			// Analytics Toolkit templates.
			array(
				'template_name'     => 'Website Analytics Audit',
				'description'       => 'Complete analytics audit with conversion funnel analysis and optimization recommendations (Analytics Toolkit)',
				'category'          => 'analytics',
				'markdown_template' => "# Analytics Audit: {{website_name}}\n\n## Setup & Verification\n- [ ] Audit existing analytics implementation\n- [ ] Verify tracking code on all {{page_count}} pages\n- [ ] Set up enhanced tracking (events, conversions, custom dimensions)\n- [ ] Configure data filters and exclude internal traffic\n- [ ] Set up {{goal_count}} conversion goals\n\n## Traffic Analysis\n- [ ] Analyze traffic sources and acquisition channels\n- [ ] Identify top {{top_pages_count}} performing pages\n- [ ] Review user demographics and interests\n- [ ] Analyze device usage (desktop vs mobile vs tablet)\n- [ ] Evaluate site speed metrics\n\n## Behavior Analysis\n- [ ] Map user flows and navigation patterns\n- [ ] Identify high bounce rate pages (>{{bounce_threshold}}%)\n- [ ] Analyze exit pages and drop-off points\n- [ ] Review on-site search behavior\n- [ ] Evaluate content engagement metrics\n\n## Conversion Analysis\n- [ ] Set up and analyze {{funnel_count}} conversion funnels\n- [ ] Identify conversion bottlenecks\n- [ ] Calculate conversion rates by channel\n- [ ] Analyze abandoned cart behavior\n- [ ] Calculate ROI by marketing channel\n\n## Reporting & Recommendations\n- [ ] Create comprehensive audit report with visualizations\n- [ ] Provide prioritized optimization recommendations\n- [ ] Set up automated reporting dashboard\n- [ ] Document tracking improvement opportunities\n- [ ] Present findings to stakeholders\n\n**Toolkit:** Analytics\n**Max Iterations:** {{max_iterations}}\n**Token Budget:** {{token_budget}}",
				'default_config'    => array(
					'max_iterations'   => 25,
					'token_budget'     => 16000,
					'page_count'       => 100,
					'goal_count'       => 5,
					'bounce_threshold' => 70,
				),
				'tags'              => array( 'analytics', 'conversion', 'audit', 'pro-toolkit' ),
				'version'           => '1.0.0',
			),

			// Video Production Toolkit templates.
			array(
				'template_name'     => 'Video Marketing Series',
				'description'       => 'Professional video series production from scripting to multi-platform distribution (Video Production Toolkit)',
				'category'          => 'video_production',
				'markdown_template' => "# Video Series: {{series_name}}\n\n## Pre-Production\n- [ ] Define series theme and target audience\n- [ ] Develop scripts for {{video_count}} videos\n- [ ] Create storyboards and shot lists\n- [ ] Plan shooting schedule and locations\n- [ ] Organize equipment (camera, lighting, audio)\n\n## Production\n- [ ] Set up filming equipment and lighting\n- [ ] Record {{video_count}} videos following scripts\n- [ ] Capture B-roll footage ({{broll_shots}} shots)\n- [ ] Record voiceover narration\n- [ ] Backup all footage\n\n## Post-Production\n- [ ] Edit videos with cuts, transitions, and effects\n- [ ] Add music and sound effects\n- [ ] Create captions/subtitles for accessibility\n- [ ] Color correct and enhance audio\n- [ ] Optimize video files for web ({{target_format}})\n\n## Distribution\n- [ ] Upload to YouTube, Vimeo, and {{other_platforms}}\n- [ ] Optimize metadata (titles, descriptions, tags)\n- [ ] Create custom thumbnails for each video\n- [ ] Generate video transcripts for SEO\n- [ ] Set up video schema markup\n\n## Promotion & Analytics\n- [ ] Create social media promotional posts\n- [ ] Send email announcements to {{subscriber_count}} subscribers\n- [ ] Monitor video analytics (views, watch time, engagement)\n- [ ] Respond to comments and build community\n- [ ] Generate performance report\n\n**Toolkit:** Video Production\n**Max Iterations:** {{max_iterations}}\n**Token Budget:** {{token_budget}}",
				'default_config'    => array(
					'max_iterations' => 30,
					'token_budget'   => 18000,
					'video_count'    => 5,
					'broll_shots'    => 20,
				),
				'tags'              => array( 'video', 'production', 'youtube', 'pro-toolkit' ),
				'version'           => '1.0.0',
			),

			// Multilingual Toolkit templates.
			array(
				'template_name'     => 'Multilingual Website Expansion',
				'description'       => 'Expand website to multiple languages with professional translation and localization (Multilingual Toolkit)',
				'category'          => 'multilingual',
				'markdown_template' => "# Multilingual Expansion: {{website_name}}\n\n## Planning & Analysis\n- [ ] Identify target languages: {{target_languages}}\n- [ ] Analyze market potential for each language\n- [ ] Configure multilingual plugin (WPML, Polylang, etc.)\n- [ ] Set up URL structure (subdomain vs subdirectory)\n- [ ] Create translation workflow and approval process\n\n## Translation Management\n- [ ] Create translation glossary with {{term_count}} terms\n- [ ] Translate core pages ({{core_page_count}} pages)\n- [ ] Translate {{product_count}} product descriptions\n- [ ] Translate {{blog_post_count}} blog posts\n- [ ] Review and approve all translations\n\n## Localization\n- [ ] Adapt images and graphics for cultural relevance\n- [ ] Localize date formats, currencies, and units\n- [ ] Adjust content for local regulations and customs\n- [ ] Configure language-specific contact information\n- [ ] Set up region-specific payment methods\n\n## Technical Implementation\n- [ ] Set up hreflang tags for all {{language_count}} languages\n- [ ] Configure language switcher UI\n- [ ] Test user experience in all languages\n- [ ] Ensure RTL support if needed (Arabic, Hebrew)\n- [ ] Verify all forms and checkout work in all languages\n\n## SEO & Marketing\n- [ ] Research keywords in each target language\n- [ ] Create localized meta titles and descriptions\n- [ ] Submit sitemaps for each language to search engines\n- [ ] Set up language-specific social media accounts\n- [ ] Plan localized marketing campaigns\n\n**Toolkit:** Multilingual\n**Max Iterations:** {{max_iterations}}\n**Token Budget:** {{token_budget}}",
				'default_config'    => array(
					'max_iterations'  => 28,
					'token_budget'    => 17000,
					'language_count'  => 3,
					'core_page_count' => 10,
				),
				'tags'              => array( 'multilingual', 'translation', 'localization', 'pro-toolkit' ),
				'version'           => '1.0.0',
			),

			// Image Production Toolkit templates.
			array(
				'template_name'     => 'Product Photography Workflow',
				'description'       => 'Professional product photography with batch processing and optimization (Image Production Toolkit)',
				'category'          => 'image_production',
				'markdown_template' => "# Product Photography: {{product_line}}\n\n## Setup & Planning\n- [ ] Set up photography studio with lighting\n- [ ] Create shot list for {{product_count}} products\n- [ ] Develop style guide for consistency\n- [ ] Prepare products (cleaning, staging)\n- [ ] Configure camera settings\n\n## Photography Session\n- [ ] Photograph products from {{angles_per_product}} angles\n- [ ] Capture detail shots ({{detail_shots_per_product}} per product)\n- [ ] Take lifestyle/contextual images\n- [ ] Shoot on white background for e-commerce\n- [ ] Review and select best shots\n\n## Post-Production\n- [ ] Remove backgrounds using AI tools\n- [ ] Color correct and enhance {{image_count}} images\n- [ ] Resize images for web ({{target_sizes}})\n- [ ] Optimize file sizes (target: <{{max_file_size}}KB)\n- [ ] Add watermarks if needed\n\n## Batch Processing\n- [ ] Apply consistent color grading across all images\n- [ ] Generate multiple sizes automatically\n- [ ] Create image variants (zoom, thumbnails)\n- [ ] Convert to optimized formats (WebP)\n- [ ] Organize files with consistent naming\n\n## Implementation\n- [ ] Upload optimized images to product database\n- [ ] Update {{product_count}} product pages\n- [ ] Add alt text for SEO and accessibility\n- [ ] Test image loading performance\n- [ ] Set up lazy loading\n\n**Toolkit:** Image Production\n**Max Iterations:** {{max_iterations}}\n**Token Budget:** {{token_budget}}",
				'default_config'    => array(
					'max_iterations'           => 22,
					'token_budget'             => 14000,
					'product_count'            => 25,
					'angles_per_product'       => 4,
					'detail_shots_per_product' => 2,
				),
				'tags'              => array( 'photography', 'image-optimization', 'product-images', 'pro-toolkit' ),
				'version'           => '1.0.0',
			),

			// Content Marketing (Media Toolkit) templates.
			array(
				'template_name'     => 'Content Marketing Strategy',
				'description'       => 'Complete content marketing strategy with blog series and distribution (Media Toolkit)',
				'category'          => 'content_marketing',
				'markdown_template' => "# Content Strategy: {{strategy_name}}\n\n## Strategy Development\n- [ ] Define content marketing goals ({{primary_goal}})\n- [ ] Create {{persona_count}} audience personas\n- [ ] Conduct keyword research for {{keyword_count}} target keywords\n- [ ] Analyze top {{competitor_count}} competitor content strategies\n- [ ] Create {{month_count}}-month content calendar\n\n## Content Creation\n- [ ] Write {{pillar_count}} pillar content pieces ({{pillar_word_count}} words each)\n- [ ] Create {{blog_count}} supporting blog posts\n- [ ] Design {{infographic_count}} infographics\n- [ ] Develop {{lead_magnet_count}} lead magnets (eBooks, templates)\n- [ ] Produce {{video_count}} videos\n\n## SEO Optimization\n- [ ] Optimize all content with target keywords\n- [ ] Build internal linking structure ({{links_per_post}} links/post)\n- [ ] Create compelling meta descriptions\n- [ ] Add schema markup to articles\n- [ ] Optimize images with alt text\n\n## Distribution Strategy\n- [ ] Publish content according to calendar\n- [ ] Share on {{social_platform_count}} social platforms\n- [ ] Set up email newsletter ({{subscriber_count}} subscribers)\n- [ ] Submit to content syndication platforms\n- [ ] Engage with industry communities\n\n## Performance Tracking\n- [ ] Track traffic and engagement metrics\n- [ ] Monitor keyword rankings\n- [ ] Measure conversion rates by content type\n- [ ] Generate monthly performance reports\n- [ ] Adjust strategy based on data insights\n\n**Toolkit:** Media\n**Max Iterations:** {{max_iterations}}\n**Token Budget:** {{token_budget}}",
				'default_config'    => array(
					'max_iterations' => 32,
					'token_budget'   => 19000,
					'pillar_count'   => 5,
					'blog_count'     => 20,
					'month_count'    => 3,
				),
				'tags'              => array( 'content-marketing', 'blogging', 'seo', 'pro-toolkit' ),
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
			// Try to load the CCT class.
			if ( defined( 'WP_MCP_AI_PRO_PATH' ) && file_exists( WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-task-templates-cct.php' ) ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-task-templates-cct.php';
			}
			// Check again after attempting to load.
			if ( ! class_exists( 'WP_MCP_AI_Task_Templates_CCT' ) ) {
				return false;
			}
		}
		$settings = get_option( 'wp_mcp_ai_project_settings', array() );
		return ! empty( $settings['use_cct_storage'] );
	}
}
