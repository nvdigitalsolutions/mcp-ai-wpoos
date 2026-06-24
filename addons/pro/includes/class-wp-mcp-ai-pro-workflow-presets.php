<?php
/**
 * Pro Workflow Builder Presets
 *
 * Provides pre-built workflow DAG (Directed Acyclic Graph) presets for the
 * Pro Workflow Builder. Each preset is a complete workflow template that can
 * be loaded into the React-based builder UI, giving users a head start with
 * common automation patterns.
 *
 * Preset categories:
 * - content:       Content creation and publishing workflows.
 * - seo:           Search engine optimisation workflows.
 * - ecommerce:     E-commerce and product management workflows.
 * - marketing:     Marketing automation workflows.
 * - data:          Data processing and analysis workflows.
 * - communication: Messaging and notification workflows.
 * - maintenance:   Site maintenance workflows.
 * - onboarding:    User and content onboarding workflows.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage Workflow_Presets
 * @since      1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Pro_Workflow_Presets' ) ) {

	/**
	 * Class WP_MCP_AI_Pro_Workflow_Presets
	 *
	 * Registry of pre-built workflow DAG presets for the Pro Workflow Builder.
	 * All methods are static so the class can be used without instantiation.
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_Pro_Workflow_Presets {

		// ------------------------------------------------------------------
		// Preset retrieval
		// ------------------------------------------------------------------

		/**
		 * Get every available workflow preset.
		 *
		 * Presets are grouped internally by category but returned as a flat
		 * associative array keyed by preset ID.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Associative array of preset definitions.
		 */
		public static function get_presets() {
			$presets = array_merge(
				self::get_content_presets(),
				self::get_seo_presets(),
				self::get_ecommerce_presets(),
				self::get_marketing_presets(),
				self::get_data_presets(),
				self::get_communication_presets(),
				self::get_maintenance_presets(),
				self::get_onboarding_presets(),
				self::get_crm_support_presets()
			);

			return $presets;
		}

		/**
		 * Get a single preset by its ID.
		 *
		 * @since  1.0.0
		 * @param  string $preset_id The unique preset identifier.
		 * @return array|null Preset definition array, or null if not found.
		 */
		public static function get_preset( $preset_id ) {
			$preset_id = sanitize_key( $preset_id );

			if ( '' === $preset_id ) {
				return null;
			}

			$presets = self::get_presets();

			return isset( $presets[ $preset_id ] ) ? $presets[ $preset_id ] : null;
		}

		/**
		 * Get all presets belonging to a specific category.
		 *
		 * @since  1.0.0
		 * @param  string $category Category slug (e.g. 'content', 'seo').
		 * @return array<string, array> Filtered preset definitions.
		 */
		public static function get_presets_by_category( $category ) {
			$category = sanitize_key( $category );

			return array_filter(
				self::get_presets(),
				function ( $preset ) use ( $category ) {
					return isset( $preset['category'] ) && $preset['category'] === $category;
				}
			);
		}

		/**
		 * Get the list of available preset categories.
		 *
		 * @since  1.0.0
		 * @return array<string, string> Slug => label pairs.
		 */
		public static function get_categories() {
			return array(
				'content'       => __( 'Content Creation & Publishing', 'mcp-ai-wpoos-pro' ),
				'seo'           => __( 'Search Engine Optimisation', 'mcp-ai-wpoos-pro' ),
				'ecommerce'     => __( 'E-Commerce & Product Management', 'mcp-ai-wpoos-pro' ),
				'marketing'     => __( 'Marketing Automation', 'mcp-ai-wpoos-pro' ),
				'data'          => __( 'Data Processing & Analysis', 'mcp-ai-wpoos-pro' ),
				'communication' => __( 'Messaging & Notifications', 'mcp-ai-wpoos-pro' ),
				'maintenance'   => __( 'Site Maintenance', 'mcp-ai-wpoos-pro' ),
				'onboarding'    => __( 'User & Content Onboarding', 'mcp-ai-wpoos-pro' ),
				'crm_support'   => __( 'CRM — Support & Ticket Management', 'mcp-ai-wpoos-pro' ),
			);
		}

		// ------------------------------------------------------------------
		// Preset installation
		// ------------------------------------------------------------------

		/**
		 * Prepare a preset for loading into the Workflow Builder.
		 *
		 * Returns the nodes and edges arrays ready to be consumed by the
		 * ReactFlow-based builder UI.
		 *
		 * @since  1.0.0
		 * @param  string $preset_id The unique preset identifier.
		 * @return array|\WP_Error Workflow data on success, WP_Error on failure.
		 */
		public static function install_preset( $preset_id ) {
			$preset = self::get_preset( $preset_id );

			if ( null === $preset ) {
				return new \WP_Error(
					'invalid_preset',
					/* translators: %s: preset identifier */
					sprintf( __( 'Workflow preset "%s" does not exist.', 'mcp-ai-wpoos-pro' ), sanitize_key( $preset_id ) )
				);
			}

			return array(
				'name'        => $preset['name'],
				'description' => $preset['description'],
				'category'    => $preset['category'],
				'icon'        => $preset['icon'],
				'tags'        => $preset['tags'],
				'nodes'       => $preset['nodes'],
				'edges'       => $preset['edges'],
			);
		}

		// ------------------------------------------------------------------
		// Content presets
		// ------------------------------------------------------------------

		/**
		 * Get content creation and publishing workflow presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Content preset definitions.
		 */
		private static function get_content_presets() {
			return array(
				'content_pipeline'         => array(
					'name'        => __( 'Blog Post Pipeline', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Research a topic, generate an outline, write a draft, optimise for SEO, and publish.', 'mcp-ai-wpoos-pro' ),
					'category'    => 'content',
					'icon'        => 'dashicons-admin-page',
					'tags'        => array( 'content', 'seo', 'publishing' ),
					'nodes'       => array(
						array(
							'id'       => 'node_1',
							'type'     => 'input',
							'position' => array(
								'x' => 250,
								'y' => 0,
							),
							'data'     => array(
								'label'       => __( 'Topic Input', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Enter the blog topic to research.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_2',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 150,
							),
							'data'     => array(
								'label'       => __( 'Research Topic', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'web_search',
								'arguments'   => array( 'query' => '{{input.topic}}' ),
								'description' => __( 'Research the topic using web search.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_3',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 300,
							),
							'data'     => array(
								'label'       => __( 'Generate Outline', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'client_summarize_text',
								'arguments'   => array( 'format' => 'outline' ),
								'description' => __( 'Generate a structured outline from research.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_4',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Write Draft', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'create_post',
								'arguments'   => array(
									'post_status' => 'draft',
									'post_type'   => 'post',
								),
								'description' => __( 'Create a draft post from the outline.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_5',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 600,
							),
							'data'     => array(
								'label'       => __( 'Optimise SEO', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'seo_meta_optimizer',
								'arguments'   => array( 'post_id' => '{{node_4.post_id}}' ),
								'description' => __( 'Optimise post meta for search engines.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_6',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 750,
							),
							'data'     => array(
								'label'       => __( 'Publish Post', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'save_post',
								'arguments'   => array(
									'post_id'     => '{{node_4.post_id}}',
									'post_status' => 'publish',
								),
								'description' => __( 'Publish the finished post.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_7',
							'type'     => 'output',
							'position' => array(
								'x' => 250,
								'y' => 900,
							),
							'data'     => array(
								'label'       => __( 'Pipeline Complete', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Blog post published successfully.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
					'edges'       => array(
						array(
							'id'           => 'edge_1_2',
							'source'       => 'node_1',
							'target'       => 'node_2',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_2_3',
							'source'       => 'node_2',
							'target'       => 'node_3',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_3_4',
							'source'       => 'node_3',
							'target'       => 'node_4',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_4_5',
							'source'       => 'node_4',
							'target'       => 'node_5',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_5_6',
							'source'       => 'node_5',
							'target'       => 'node_6',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_6_7',
							'source'       => 'node_6',
							'target'       => 'node_7',
							'sourceHandle' => 'output',
						),
					),
				),
				'content_refresh'          => array(
					'name'        => __( 'Content Refresh', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Find old posts, analyse performance, update content, and regenerate images.', 'mcp-ai-wpoos-pro' ),
					'category'    => 'content',
					'icon'        => 'dashicons-update',
					'tags'        => array( 'content', 'maintenance', 'images' ),
					'nodes'       => array(
						array(
							'id'       => 'node_1',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 0,
							),
							'data'     => array(
								'label'       => __( 'Find Old Posts', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'content_freshness_checker',
								'arguments'   => array( 'max_age_days' => 180 ),
								'description' => __( 'Identify posts older than 180 days.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_2',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 150,
							),
							'data'     => array(
								'label'       => __( 'Analyse Performance', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'sitekit_get_analytics',
								'arguments'   => array( 'metric' => 'pageviews' ),
								'description' => __( 'Pull analytics data for identified posts.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_3',
							'type'     => 'condition',
							'position' => array(
								'x' => 250,
								'y' => 300,
							),
							'data'     => array(
								'label'       => __( 'Needs Update?', 'mcp-ai-wpoos-pro' ),
								'expression'  => 'node_2.pageviews < 100',
								'description' => __( 'Check if post performance is below threshold.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_4',
							'type'     => 'tool',
							'position' => array(
								'x' => 100,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Update Content', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'save_post',
								'arguments'   => array( 'post_status' => 'draft' ),
								'description' => __( 'Refresh the post content with updated information.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_5',
							'type'     => 'tool',
							'position' => array(
								'x' => 100,
								'y' => 600,
							),
							'data'     => array(
								'label'       => __( 'Regenerate Images', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'generate_openai_image',
								'arguments'   => array( 'size' => '1024x1024' ),
								'description' => __( 'Generate fresh featured images for the post.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_6',
							'type'     => 'output',
							'position' => array(
								'x' => 250,
								'y' => 750,
							),
							'data'     => array(
								'label'       => __( 'Refresh Complete', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Content refresh workflow finished.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
					'edges'       => array(
						array(
							'id'           => 'edge_1_2',
							'source'       => 'node_1',
							'target'       => 'node_2',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_2_3',
							'source'       => 'node_2',
							'target'       => 'node_3',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_3_4',
							'source'       => 'node_3',
							'target'       => 'node_4',
							'sourceHandle' => 'true',
						),
						array(
							'id'           => 'edge_3_6',
							'source'       => 'node_3',
							'target'       => 'node_6',
							'sourceHandle' => 'false',
						),
						array(
							'id'           => 'edge_4_5',
							'source'       => 'node_4',
							'target'       => 'node_5',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_5_6',
							'source'       => 'node_5',
							'target'       => 'node_6',
							'sourceHandle' => 'output',
						),
					),
				),
				'social_content_repurpose' => array(
					'name'        => __( 'Repurpose for Social', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Take an existing post, generate social media snippets, and schedule them.', 'mcp-ai-wpoos-pro' ),
					'category'    => 'content',
					'icon'        => 'dashicons-share',
					'tags'        => array( 'content', 'social', 'scheduling' ),
					'nodes'       => array(
						array(
							'id'       => 'node_1',
							'type'     => 'input',
							'position' => array(
								'x' => 250,
								'y' => 0,
							),
							'data'     => array(
								'label'       => __( 'Select Post', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Choose the post to repurpose for social media.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_2',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 150,
							),
							'data'     => array(
								'label'       => __( 'Get Post Content', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'get_post',
								'arguments'   => array( 'post_id' => '{{input.post_id}}' ),
								'description' => __( 'Retrieve the full post content.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_3',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 300,
							),
							'data'     => array(
								'label'       => __( 'Generate Social Snippets', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'client_summarize_text',
								'arguments'   => array( 'format' => 'social_snippets' ),
								'description' => __( 'Create platform-specific social media snippets.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_4',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Generate Social Image', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'generate_openai_image',
								'arguments'   => array( 'size' => '1200x630' ),
								'description' => __( 'Create an optimised image for social sharing.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_5',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 600,
							),
							'data'     => array(
								'label'       => __( 'Schedule Posts', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'create_post',
								'arguments'   => array(
									'post_type'   => 'post',
									'post_status' => 'future',
								),
								'description' => __( 'Schedule the social media posts for publishing.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_6',
							'type'     => 'output',
							'position' => array(
								'x' => 250,
								'y' => 750,
							),
							'data'     => array(
								'label'       => __( 'Repurpose Complete', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Social media content scheduled.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
					'edges'       => array(
						array(
							'id'           => 'edge_1_2',
							'source'       => 'node_1',
							'target'       => 'node_2',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_2_3',
							'source'       => 'node_2',
							'target'       => 'node_3',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_3_4',
							'source'       => 'node_3',
							'target'       => 'node_4',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_4_5',
							'source'       => 'node_4',
							'target'       => 'node_5',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_5_6',
							'source'       => 'node_5',
							'target'       => 'node_6',
							'sourceHandle' => 'output',
						),
					),
				),
				'newsletter_workflow'      => array(
					'name'        => __( 'Newsletter Builder', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Collect top posts, generate a summary, format the email, and send the newsletter.', 'mcp-ai-wpoos-pro' ),
					'category'    => 'content',
					'icon'        => 'dashicons-email-alt',
					'tags'        => array( 'content', 'email', 'newsletter' ),
					'nodes'       => array(
						array(
							'id'       => 'node_1',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 0,
							),
							'data'     => array(
								'label'       => __( 'Collect Top Posts', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'get_recent_posts',
								'arguments'   => array(
									'count'   => 5,
									'orderby' => 'comment_count',
								),
								'description' => __( 'Get the top-performing recent posts.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_2',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 150,
							),
							'data'     => array(
								'label'       => __( 'Generate Summary', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'client_summarize_text',
								'arguments'   => array( 'format' => 'newsletter_digest' ),
								'description' => __( 'Summarise each post into a newsletter-ready digest.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_3',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 300,
							),
							'data'     => array(
								'label'       => __( 'Format Email', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'client_summarize_text',
								'arguments'   => array( 'format' => 'email_html' ),
								'description' => __( 'Format the digest into an email-friendly layout.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_4',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Send Newsletter', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'probe_chat',
								'arguments'   => array( 'channel' => 'newsletter' ),
								'description' => __( 'Broadcast the newsletter to subscribers.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_5',
							'type'     => 'output',
							'position' => array(
								'x' => 250,
								'y' => 600,
							),
							'data'     => array(
								'label'       => __( 'Newsletter Sent', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Newsletter has been sent to all subscribers.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
					'edges'       => array(
						array(
							'id'           => 'edge_1_2',
							'source'       => 'node_1',
							'target'       => 'node_2',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_2_3',
							'source'       => 'node_2',
							'target'       => 'node_3',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_3_4',
							'source'       => 'node_3',
							'target'       => 'node_4',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_4_5',
							'source'       => 'node_4',
							'target'       => 'node_5',
							'sourceHandle' => 'output',
						),
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// SEO presets
		// ------------------------------------------------------------------

		/**
		 * Get search engine optimisation workflow presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> SEO preset definitions.
		 */
		private static function get_seo_presets() {
			return array(
				'seo_audit'                 => array(
					'name'        => __( 'SEO Audit', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Crawl pages, check meta tags, analyse keywords, and generate an audit report.', 'mcp-ai-wpoos-pro' ),
					'category'    => 'seo',
					'icon'        => 'dashicons-search',
					'tags'        => array( 'seo', 'audit', 'meta' ),
					'nodes'       => array(
						array(
							'id'       => 'node_1',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 0,
							),
							'data'     => array(
								'label'       => __( 'Crawl Pages', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'run_crawl4ai_job',
								'arguments'   => array( 'depth' => 2 ),
								'description' => __( 'Crawl the site to discover all pages.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_2',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 150,
							),
							'data'     => array(
								'label'       => __( 'Check Meta Tags', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'get_rankmath_seo',
								'arguments'   => array( 'check' => 'meta_tags' ),
								'description' => __( 'Analyse meta tags for each discovered page.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_3',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 300,
							),
							'data'     => array(
								'label'       => __( 'Analyse Keywords', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'sitekit_get_search_console',
								'arguments'   => array( 'metric' => 'keywords' ),
								'description' => __( 'Pull keyword performance from Search Console.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_4',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Generate Report', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'create_chart',
								'arguments'   => array( 'type' => 'seo_audit_report' ),
								'description' => __( 'Compile findings into a visual SEO audit report.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_5',
							'type'     => 'output',
							'position' => array(
								'x' => 250,
								'y' => 600,
							),
							'data'     => array(
								'label'       => __( 'Audit Complete', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'SEO audit report generated.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
					'edges'       => array(
						array(
							'id'           => 'edge_1_2',
							'source'       => 'node_1',
							'target'       => 'node_2',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_2_3',
							'source'       => 'node_2',
							'target'       => 'node_3',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_3_4',
							'source'       => 'node_3',
							'target'       => 'node_4',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_4_5',
							'source'       => 'node_4',
							'target'       => 'node_5',
							'sourceHandle' => 'output',
						),
					),
				),
				'broken_link_repair'        => array(
					'name'        => __( 'Link Repair', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Scan for broken links, suggest replacements, and update posts automatically.', 'mcp-ai-wpoos-pro' ),
					'category'    => 'seo',
					'icon'        => 'dashicons-admin-links',
					'tags'        => array( 'seo', 'links', 'maintenance' ),
					'nodes'       => array(
						array(
							'id'       => 'node_1',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 0,
							),
							'data'     => array(
								'label'       => __( 'Scan Links', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'run_crawl4ai_job',
								'arguments'   => array( 'mode' => 'link_scan' ),
								'description' => __( 'Crawl the site to identify all outbound and internal links.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_2',
							'type'     => 'condition',
							'position' => array(
								'x' => 250,
								'y' => 150,
							),
							'data'     => array(
								'label'       => __( 'Broken Links Found?', 'mcp-ai-wpoos-pro' ),
								'expression'  => 'node_1.broken_count > 0',
								'description' => __( 'Check if any broken links were detected.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_3',
							'type'     => 'tool',
							'position' => array(
								'x' => 100,
								'y' => 300,
							),
							'data'     => array(
								'label'       => __( 'Suggest Replacements', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'suggest_internal_links',
								'arguments'   => array( 'post_id' => '{{node_1.post_id}}' ),
								'description' => __( 'Find replacement URLs for broken links.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_4',
							'type'     => 'tool',
							'position' => array(
								'x' => 100,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Update Posts', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'save_post',
								'arguments'   => array(
									'post_id' => '{{node_3.post_id}}',
									'content' => '{{node_3.updated_content}}',
								),
								'description' => __( 'Replace broken links with suggested alternatives.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_5',
							'type'     => 'output',
							'position' => array(
								'x' => 250,
								'y' => 600,
							),
							'data'     => array(
								'label'       => __( 'Repair Complete', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Broken links have been repaired.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
					'edges'       => array(
						array(
							'id'           => 'edge_1_2',
							'source'       => 'node_1',
							'target'       => 'node_2',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_2_3',
							'source'       => 'node_2',
							'target'       => 'node_3',
							'sourceHandle' => 'true',
						),
						array(
							'id'           => 'edge_2_5',
							'source'       => 'node_2',
							'target'       => 'node_5',
							'sourceHandle' => 'false',
						),
						array(
							'id'           => 'edge_3_4',
							'source'       => 'node_3',
							'target'       => 'node_4',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_4_5',
							'source'       => 'node_4',
							'target'       => 'node_5',
							'sourceHandle' => 'output',
						),
					),
				),
				'keyword_research_pipeline' => array(
					'name'        => __( 'Keyword Pipeline', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Research keywords, analyse competition, prioritise opportunities, generate an image, and create content briefs.', 'mcp-ai-wpoos-pro' ),
					'category'    => 'seo',
					'icon'        => 'dashicons-search',
					'tags'        => array( 'seo', 'keywords', 'research' ),
					'nodes'       => array(
						array(
							'id'       => 'node_1',
							'type'     => 'input',
							'position' => array(
								'x' => 250,
								'y' => 0,
							),
							'data'     => array(
								'label'       => __( 'Seed Keywords', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Enter seed keywords to begin research.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_2',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 150,
							),
							'data'     => array(
								'label'       => __( 'Research Keywords', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'web_search',
								'arguments'   => array( 'query' => '{{input.keywords}} keyword research' ),
								'description' => __( 'Find related keywords and search volumes.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_3',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 300,
							),
							'data'     => array(
								'label'       => __( 'Analyse Competition', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'sitekit_get_search_console',
								'arguments'   => array( 'metric' => 'impressions' ),
								'description' => __( 'Evaluate competition levels for each keyword.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_4',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Prioritise Keywords', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'content_recommendation_engine',
								'arguments'   => array( 'mode' => 'ranking_potential' ),
								'description' => __( 'Rank keywords by opportunity score.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_4b',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 525,
							),
							'data'     => array(
								'label'       => __( 'Generate Featured Image', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'generate_openai_image',
								'arguments'   => array(
									'prompt'  => '{{node_1.value}}',
									'size'    => '1792x1024',
									'model'   => 'dall-e-3',
									'quality' => 'hd',
								),
								'description' => __( 'Generate an AI featured image for the content.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_5',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 600,
							),
							'data'     => array(
								'label'       => __( 'Create Content Briefs', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'create_post',
								'arguments'   => array(
									'post_type'         => 'post',
									'post_status'       => 'draft',
									'featured_image_id' => '{{node_4b.attachment_id}}',
								),
								'description' => __( 'Generate content briefs for prioritised keywords.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_6',
							'type'     => 'output',
							'position' => array(
								'x' => 250,
								'y' => 750,
							),
							'data'     => array(
								'label'       => __( 'Pipeline Complete', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Keyword research pipeline finished. Content briefs ready.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
					'edges'       => array(
						array(
							'id'           => 'edge_1_2',
							'source'       => 'node_1',
							'target'       => 'node_2',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_2_3',
							'source'       => 'node_2',
							'target'       => 'node_3',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_3_4',
							'source'       => 'node_3',
							'target'       => 'node_4',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_4_4b',
							'source'       => 'node_4',
							'target'       => 'node_4b',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_4b_5',
							'source'       => 'node_4b',
							'target'       => 'node_5',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_5_6',
							'source'       => 'node_5',
							'target'       => 'node_6',
							'sourceHandle' => 'output',
						),

					),
				),
			);
		}

		// ------------------------------------------------------------------
		// E-commerce presets
		// ------------------------------------------------------------------

		/**
		 * Get e-commerce and product management workflow presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> E-commerce preset definitions.
		 */
		private static function get_ecommerce_presets() {
			return array(
				'product_launch'          => array(
					'name'        => __( 'Product Launch', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Create a product, generate description, set pricing, publish, and announce.', 'mcp-ai-wpoos-pro' ),
					'category'    => 'ecommerce',
					'icon'        => 'dashicons-cart',
					'tags'        => array( 'ecommerce', 'product', 'launch' ),
					'nodes'       => array(
						array(
							'id'       => 'node_1',
							'type'     => 'input',
							'position' => array(
								'x' => 250,
								'y' => 0,
							),
							'data'     => array(
								'label'       => __( 'Product Details', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Enter basic product information.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_2',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 150,
							),
							'data'     => array(
								'label'       => __( 'Create Product', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'create_woo_product',
								'arguments'   => array( 'status' => 'draft' ),
								'description' => __( 'Create the WooCommerce product in draft mode.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_3',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 300,
							),
							'data'     => array(
								'label'       => __( 'Generate Description', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'client_summarize_text',
								'arguments'   => array( 'format' => 'product_description' ),
								'description' => __( 'Generate an SEO-optimised product description.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_4',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Generate Product Image', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'generate_openai_image',
								'arguments'   => array( 'size' => '1024x1024' ),
								'description' => __( 'Create a product image using AI.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_5',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 600,
							),
							'data'     => array(
								'label'       => __( 'Publish Product', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'save_post',
								'arguments'   => array(
									'post_status'       => 'publish',
									'featured_image_id' => '{{node_4.attachment_id}}',
								),
								'description' => __( 'Set the product to published status.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_6',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 750,
							),
							'data'     => array(
								'label'       => __( 'Announce Launch', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'probe_chat',
								'arguments'   => array( 'channel' => 'announcements' ),
								'description' => __( 'Broadcast the product launch announcement.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_7',
							'type'     => 'output',
							'position' => array(
								'x' => 250,
								'y' => 900,
							),
							'data'     => array(
								'label'       => __( 'Launch Complete', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Product launched and announced successfully.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
					'edges'       => array(
						array(
							'id'           => 'edge_1_2',
							'source'       => 'node_1',
							'target'       => 'node_2',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_2_3',
							'source'       => 'node_2',
							'target'       => 'node_3',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_3_4',
							'source'       => 'node_3',
							'target'       => 'node_4',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_4_5',
							'source'       => 'node_4',
							'target'       => 'node_5',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_5_6',
							'source'       => 'node_5',
							'target'       => 'node_6',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_6_7',
							'source'       => 'node_6',
							'target'       => 'node_7',
							'sourceHandle' => 'output',
						),
					),
				),
				'order_fulfillment_check' => array(
					'name'        => __( 'Order Fulfillment Check', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Get pending orders, check inventory, flag issues, and notify the team.', 'mcp-ai-wpoos-pro' ),
					'category'    => 'ecommerce',
					'icon'        => 'dashicons-clipboard',
					'tags'        => array( 'ecommerce', 'orders', 'inventory' ),
					'nodes'       => array(
						array(
							'id'       => 'node_1',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 0,
							),
							'data'     => array(
								'label'       => __( 'Get Pending Orders', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'get_woo_recent_orders',
								'arguments'   => array( 'status' => 'pending' ),
								'description' => __( 'Retrieve all orders awaiting fulfillment.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_2',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 150,
							),
							'data'     => array(
								'label'       => __( 'Check Inventory', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'get_woo_products',
								'arguments'   => array( 'check' => 'stock_status' ),
								'description' => __( 'Verify stock levels for ordered products.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_3',
							'type'     => 'condition',
							'position' => array(
								'x' => 250,
								'y' => 300,
							),
							'data'     => array(
								'label'       => __( 'Stock Issues?', 'mcp-ai-wpoos-pro' ),
								'expression'  => 'node_2.out_of_stock_count > 0',
								'description' => __( 'Check if any ordered items are out of stock.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_4',
							'type'     => 'tool',
							'position' => array(
								'x' => 100,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Flag Issues', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'create_post',
								'arguments'   => array(
									'post_type'   => 'mcp_ai_task',
									'post_status' => 'publish',
								),
								'description' => __( 'Create a task for stock issues requiring attention.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_5',
							'type'     => 'tool',
							'position' => array(
								'x' => 100,
								'y' => 600,
							),
							'data'     => array(
								'label'       => __( 'Notify Team', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'probe_chat',
								'arguments'   => array( 'channel' => 'operations' ),
								'description' => __( 'Alert the operations team about stock issues.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_6',
							'type'     => 'output',
							'position' => array(
								'x' => 250,
								'y' => 750,
							),
							'data'     => array(
								'label'       => __( 'Check Complete', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Order fulfillment check completed.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
					'edges'       => array(
						array(
							'id'           => 'edge_1_2',
							'source'       => 'node_1',
							'target'       => 'node_2',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_2_3',
							'source'       => 'node_2',
							'target'       => 'node_3',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_3_4',
							'source'       => 'node_3',
							'target'       => 'node_4',
							'sourceHandle' => 'true',
						),
						array(
							'id'           => 'edge_3_6',
							'source'       => 'node_3',
							'target'       => 'node_6',
							'sourceHandle' => 'false',
						),
						array(
							'id'           => 'edge_4_5',
							'source'       => 'node_4',
							'target'       => 'node_5',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_5_6',
							'source'       => 'node_5',
							'target'       => 'node_6',
							'sourceHandle' => 'output',
						),
					),
				),
				'review_response'         => array(
					'name'        => __( 'Review Response', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Get new product reviews, analyse sentiment, and draft responses.', 'mcp-ai-wpoos-pro' ),
					'category'    => 'ecommerce',
					'icon'        => 'dashicons-star-filled',
					'tags'        => array( 'ecommerce', 'reviews', 'sentiment' ),
					'nodes'       => array(
						array(
							'id'       => 'node_1',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 0,
							),
							'data'     => array(
								'label'       => __( 'Get New Reviews', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'search_content',
								'arguments'   => array(
									'post_type' => 'product',
									'meta_key'  => 'rating', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Staging environment preset.
								),
								'description' => __( 'Fetch recent product reviews.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_2',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 150,
							),
							'data'     => array(
								'label'       => __( 'Analyse Sentiment', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'client_analyze_sentiment',
								'arguments'   => array( 'text' => '{{node_1.reviews}}' ),
								'description' => __( 'Determine the sentiment of each review.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_3',
							'type'     => 'condition',
							'position' => array(
								'x' => 250,
								'y' => 300,
							),
							'data'     => array(
								'label'       => __( 'Negative Review?', 'mcp-ai-wpoos-pro' ),
								'expression'  => 'node_2.sentiment === "negative"',
								'description' => __( 'Route based on review sentiment.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_4',
							'type'     => 'tool',
							'position' => array(
								'x' => 100,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Draft Apology Response', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'client_summarize_text',
								'arguments'   => array( 'tone' => 'empathetic' ),
								'description' => __( 'Draft a thoughtful response to the negative review.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_5',
							'type'     => 'tool',
							'position' => array(
								'x' => 400,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Draft Thank-You Response', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'client_summarize_text',
								'arguments'   => array( 'tone' => 'grateful' ),
								'description' => __( 'Draft a thank-you response for the positive review.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_6',
							'type'     => 'output',
							'position' => array(
								'x' => 250,
								'y' => 600,
							),
							'data'     => array(
								'label'       => __( 'Responses Ready', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Review responses drafted and ready for approval.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
					'edges'       => array(
						array(
							'id'           => 'edge_1_2',
							'source'       => 'node_1',
							'target'       => 'node_2',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_2_3',
							'source'       => 'node_2',
							'target'       => 'node_3',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_3_4',
							'source'       => 'node_3',
							'target'       => 'node_4',
							'sourceHandle' => 'true',
						),
						array(
							'id'           => 'edge_3_5',
							'source'       => 'node_3',
							'target'       => 'node_5',
							'sourceHandle' => 'false',
						),
						array(
							'id'           => 'edge_4_6',
							'source'       => 'node_4',
							'target'       => 'node_6',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_5_6',
							'source'       => 'node_5',
							'target'       => 'node_6',
							'sourceHandle' => 'output',
						),
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// Marketing presets
		// ------------------------------------------------------------------

		/**
		 * Get marketing automation workflow presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Marketing preset definitions.
		 */
		private static function get_marketing_presets() {
			return array(
				'lead_nurture_sequence' => array(
					'name'        => __( 'Lead Nurture', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Identify cold leads, segment them, generate personalised messages, and send.', 'mcp-ai-wpoos-pro' ),
					'category'    => 'marketing',
					'icon'        => 'dashicons-groups',
					'tags'        => array( 'marketing', 'leads', 'email' ),
					'nodes'       => array(
						array(
							'id'       => 'node_1',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 0,
							),
							'data'     => array(
								'label'       => __( 'Identify Cold Leads', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'search_content',
								'arguments'   => array(
									'post_type' => 'mcp_ai_contact',
									'status'    => 'cold',
								),
								'description' => __( 'Find leads that have gone cold.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_2',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 150,
							),
							'data'     => array(
								'label'       => __( 'Segment Leads', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'auto_categorize_content',
								'arguments'   => array( 'taxonomy' => 'lead_segment' ),
								'description' => __( 'Segment leads by industry and engagement level.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_3',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 300,
							),
							'data'     => array(
								'label'       => __( 'Generate Personalised Message', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'client_summarize_text',
								'arguments'   => array( 'format' => 'personalised_email' ),
								'description' => __( 'Create a personalised nurture message for each segment.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_4',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Send Messages', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'probe_chat',
								'arguments'   => array( 'channel' => 'email' ),
								'description' => __( 'Deliver the personalised messages to leads.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_5',
							'type'     => 'output',
							'position' => array(
								'x' => 250,
								'y' => 600,
							),
							'data'     => array(
								'label'       => __( 'Nurture Complete', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Lead nurture sequence dispatched.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
					'edges'       => array(
						array(
							'id'           => 'edge_1_2',
							'source'       => 'node_1',
							'target'       => 'node_2',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_2_3',
							'source'       => 'node_2',
							'target'       => 'node_3',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_3_4',
							'source'       => 'node_3',
							'target'       => 'node_4',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_4_5',
							'source'       => 'node_4',
							'target'       => 'node_5',
							'sourceHandle' => 'output',
						),
					),
				),
				'campaign_performance'  => array(
					'name'        => __( 'Campaign Review', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Collect campaign metrics, analyse ROI, generate a report, and broadcast a summary.', 'mcp-ai-wpoos-pro' ),
					'category'    => 'marketing',
					'icon'        => 'dashicons-chart-area',
					'tags'        => array( 'marketing', 'analytics', 'reporting' ),
					'nodes'       => array(
						array(
							'id'       => 'node_1',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 0,
							),
							'data'     => array(
								'label'       => __( 'Collect Metrics', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'sitekit_get_analytics',
								'arguments'   => array( 'metric' => 'conversions' ),
								'description' => __( 'Pull campaign conversion and traffic metrics.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_2',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 150,
							),
							'data'     => array(
								'label'       => __( 'Analyse ROI', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'visualize_workflow_metrics',
								'arguments'   => array( 'mode' => 'roi_analysis' ),
								'description' => __( 'Calculate return on investment for each campaign.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_3',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 300,
							),
							'data'     => array(
								'label'       => __( 'Generate Report', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'create_chart',
								'arguments'   => array( 'type' => 'campaign_report' ),
								'description' => __( 'Compile a visual campaign performance report.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_4',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Broadcast Summary', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'probe_chat',
								'arguments'   => array( 'channel' => 'marketing' ),
								'description' => __( 'Share the campaign summary with the marketing team.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_5',
							'type'     => 'output',
							'position' => array(
								'x' => 250,
								'y' => 600,
							),
							'data'     => array(
								'label'       => __( 'Review Complete', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Campaign performance review completed.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
					'edges'       => array(
						array(
							'id'           => 'edge_1_2',
							'source'       => 'node_1',
							'target'       => 'node_2',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_2_3',
							'source'       => 'node_2',
							'target'       => 'node_3',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_3_4',
							'source'       => 'node_3',
							'target'       => 'node_4',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_4_5',
							'source'       => 'node_4',
							'target'       => 'node_5',
							'sourceHandle' => 'output',
						),
					),
				),
				'competitor_analysis'   => array(
					'name'        => __( 'Competitor Watch', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Monitor competitors, compare metrics, summarise findings, and alert the team.', 'mcp-ai-wpoos-pro' ),
					'category'    => 'marketing',
					'icon'        => 'dashicons-visibility',
					'tags'        => array( 'marketing', 'competitors', 'research' ),
					'nodes'       => array(
						array(
							'id'       => 'node_1',
							'type'     => 'input',
							'position' => array(
								'x' => 250,
								'y' => 0,
							),
							'data'     => array(
								'label'       => __( 'Competitor URLs', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Enter competitor website URLs to monitor.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_2',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 150,
							),
							'data'     => array(
								'label'       => __( 'Monitor Competitors', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'run_crawl4ai_job',
								'arguments'   => array( 'mode' => 'competitor_scan' ),
								'description' => __( 'Crawl competitor sites for changes.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_3',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 300,
							),
							'data'     => array(
								'label'       => __( 'Compare Metrics', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'sitekit_get_pagespeed',
								'arguments'   => array(
									'url'      => '{{input.urls}}',
									'strategy' => 'both',
								),
								'description' => __( 'Compare performance metrics against competitors.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_4',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Summarise Findings', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'client_summarize_text',
								'arguments'   => array( 'format' => 'competitive_analysis' ),
								'description' => __( 'Compile a summary of competitive insights.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_5',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 600,
							),
							'data'     => array(
								'label'       => __( 'Alert Team', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'probe_chat',
								'arguments'   => array( 'channel' => 'strategy' ),
								'description' => __( 'Notify the strategy team of key findings.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_6',
							'type'     => 'output',
							'position' => array(
								'x' => 250,
								'y' => 750,
							),
							'data'     => array(
								'label'       => __( 'Analysis Complete', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Competitor analysis finished and team notified.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
					'edges'       => array(
						array(
							'id'           => 'edge_1_2',
							'source'       => 'node_1',
							'target'       => 'node_2',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_2_3',
							'source'       => 'node_2',
							'target'       => 'node_3',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_3_4',
							'source'       => 'node_3',
							'target'       => 'node_4',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_4_5',
							'source'       => 'node_4',
							'target'       => 'node_5',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_5_6',
							'source'       => 'node_5',
							'target'       => 'node_6',
							'sourceHandle' => 'output',
						),
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// Data presets
		// ------------------------------------------------------------------

		/**
		 * Get data processing and analysis workflow presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Data preset definitions.
		 */
		private static function get_data_presets() {
			return array(
				'data_cleanup_pipeline' => array(
					'name'        => __( 'Data Cleanup', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Audit records, find duplicates, merge them, validate, and generate a report.', 'mcp-ai-wpoos-pro' ),
					'category'    => 'data',
					'icon'        => 'dashicons-database',
					'tags'        => array( 'data', 'cleanup', 'duplicates' ),
					'nodes'       => array(
						array(
							'id'       => 'node_1',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 0,
							),
							'data'     => array(
								'label'       => __( 'Audit Records', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'search_content',
								'arguments'   => array( 'post_type' => 'any' ),
								'description' => __( 'Scan all content records for data quality issues.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_2',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 150,
							),
							'data'     => array(
								'label'       => __( 'Find Duplicates', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'semantic_content_search',
								'arguments'   => array( 'threshold' => 0.9 ),
								'description' => __( 'Use semantic search to identify duplicate content.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_3',
							'type'     => 'condition',
							'position' => array(
								'x' => 250,
								'y' => 300,
							),
							'data'     => array(
								'label'       => __( 'Duplicates Found?', 'mcp-ai-wpoos-pro' ),
								'expression'  => 'node_2.duplicate_count > 0',
								'description' => __( 'Check if duplicate records were detected.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_4',
							'type'     => 'tool',
							'position' => array(
								'x' => 100,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Merge Records', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'save_post',
								'arguments'   => array(
									'post_id'    => '{{node_2.canonical_id}}',
									'merge_from' => '{{node_2.duplicate_ids}}',
								),
								'description' => __( 'Merge duplicate records into canonical entries.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_5',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 600,
							),
							'data'     => array(
								'label'       => __( 'Validate Data', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'validate_reasoning_chain',
								'arguments'   => array( 'data_set' => '{{node_4.result}}' ),
								'description' => __( 'Run validation checks on the cleaned data set.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_6',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 750,
							),
							'data'     => array(
								'label'       => __( 'Generate Report', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'create_chart',
								'arguments'   => array( 'type' => 'cleanup_report' ),
								'description' => __( 'Create a cleanup summary report.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_7',
							'type'     => 'output',
							'position' => array(
								'x' => 250,
								'y' => 900,
							),
							'data'     => array(
								'label'       => __( 'Cleanup Complete', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Data cleanup pipeline finished.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
					'edges'       => array(
						array(
							'id'           => 'edge_1_2',
							'source'       => 'node_1',
							'target'       => 'node_2',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_2_3',
							'source'       => 'node_2',
							'target'       => 'node_3',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_3_4',
							'source'       => 'node_3',
							'target'       => 'node_4',
							'sourceHandle' => 'true',
						),
						array(
							'id'           => 'edge_3_5',
							'source'       => 'node_3',
							'target'       => 'node_5',
							'sourceHandle' => 'false',
						),
						array(
							'id'           => 'edge_4_5',
							'source'       => 'node_4',
							'target'       => 'node_5',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_5_6',
							'source'       => 'node_5',
							'target'       => 'node_6',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_6_7',
							'source'       => 'node_6',
							'target'       => 'node_7',
							'sourceHandle' => 'output',
						),
					),
				),
				'analytics_digest'      => array(
					'name'        => __( 'Analytics Digest', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Pull metrics, calculate trends, generate charts, and email a report.', 'mcp-ai-wpoos-pro' ),
					'category'    => 'data',
					'icon'        => 'dashicons-chart-line',
					'tags'        => array( 'data', 'analytics', 'reporting' ),
					'nodes'       => array(
						array(
							'id'       => 'node_1',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 0,
							),
							'data'     => array(
								'label'       => __( 'Pull Metrics', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'sitekit_get_analytics',
								'arguments'   => array( 'metric' => 'all' ),
								'description' => __( 'Retrieve site analytics data.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_2',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 150,
							),
							'data'     => array(
								'label'       => __( 'Calculate Trends', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'openai_usage_analytics',
								'arguments'   => array( 'mode' => 'trend_analysis' ),
								'description' => __( 'Identify trends and patterns in the data.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_3',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 300,
							),
							'data'     => array(
								'label'       => __( 'Generate Charts', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'create_chart',
								'arguments'   => array( 'type' => 'line' ),
								'description' => __( 'Visualise the metrics as charts.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_4',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Email Report', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'probe_chat',
								'arguments'   => array( 'channel' => 'email_digest' ),
								'description' => __( 'Send the analytics digest via email.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_5',
							'type'     => 'output',
							'position' => array(
								'x' => 250,
								'y' => 600,
							),
							'data'     => array(
								'label'       => __( 'Digest Sent', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Analytics digest emailed to stakeholders.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
					'edges'       => array(
						array(
							'id'           => 'edge_1_2',
							'source'       => 'node_1',
							'target'       => 'node_2',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_2_3',
							'source'       => 'node_2',
							'target'       => 'node_3',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_3_4',
							'source'       => 'node_3',
							'target'       => 'node_4',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_4_5',
							'source'       => 'node_4',
							'target'       => 'node_5',
							'sourceHandle' => 'output',
						),
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// Communication presets
		// ------------------------------------------------------------------

		/**
		 * Get messaging and notification workflow presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Communication preset definitions.
		 */
		private static function get_communication_presets() {
			return array(
				'support_escalation' => array(
					'name'        => __( 'Support Escalation', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Check the support queue, identify urgent tickets, assign agents, and notify.', 'mcp-ai-wpoos-pro' ),
					'category'    => 'communication',
					'icon'        => 'dashicons-sos',
					'tags'        => array( 'communication', 'support', 'escalation' ),
					'nodes'       => array(
						array(
							'id'       => 'node_1',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 0,
							),
							'data'     => array(
								'label'       => __( 'Check Support Queue', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'search_content',
								'arguments'   => array(
									'post_type' => 'mcp_ai_task',
									'status'    => 'open',
								),
								'description' => __( 'Retrieve open support tickets from the queue.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_2',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 150,
							),
							'data'     => array(
								'label'       => __( 'Identify Urgent Tickets', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'client_analyze_sentiment',
								'arguments'   => array( 'mode' => 'urgency_detection' ),
								'description' => __( 'Analyse tickets to identify urgent issues.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_3',
							'type'     => 'condition',
							'position' => array(
								'x' => 250,
								'y' => 300,
							),
							'data'     => array(
								'label'       => __( 'Urgent Tickets Found?', 'mcp-ai-wpoos-pro' ),
								'expression'  => 'node_2.urgency_level === "high"',
								'description' => __( 'Check if any tickets require immediate attention.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_4',
							'type'     => 'tool',
							'position' => array(
								'x' => 100,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Assign Agents', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'delegate_to_agent',
								'arguments'   => array( 'priority' => 'high' ),
								'description' => __( 'Assign urgent tickets to available support agents.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_5',
							'type'     => 'tool',
							'position' => array(
								'x' => 100,
								'y' => 600,
							),
							'data'     => array(
								'label'       => __( 'Notify Team', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'probe_chat',
								'arguments'   => array( 'channel' => 'support' ),
								'description' => __( 'Alert the support team about escalated tickets.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_6',
							'type'     => 'output',
							'position' => array(
								'x' => 250,
								'y' => 750,
							),
							'data'     => array(
								'label'       => __( 'Escalation Complete', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Support escalation workflow finished.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
					'edges'       => array(
						array(
							'id'           => 'edge_1_2',
							'source'       => 'node_1',
							'target'       => 'node_2',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_2_3',
							'source'       => 'node_2',
							'target'       => 'node_3',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_3_4',
							'source'       => 'node_3',
							'target'       => 'node_4',
							'sourceHandle' => 'true',
						),
						array(
							'id'           => 'edge_3_6',
							'source'       => 'node_3',
							'target'       => 'node_6',
							'sourceHandle' => 'false',
						),
						array(
							'id'           => 'edge_4_5',
							'source'       => 'node_4',
							'target'       => 'node_5',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_5_6',
							'source'       => 'node_5',
							'target'       => 'node_6',
							'sourceHandle' => 'output',
						),
					),
				),
				'team_standup'       => array(
					'name'        => __( 'Daily Standup', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Collect status updates from the team, summarise progress, and broadcast to channels.', 'mcp-ai-wpoos-pro' ),
					'category'    => 'communication',
					'icon'        => 'dashicons-megaphone',
					'tags'        => array( 'communication', 'standup', 'team' ),
					'nodes'       => array(
						array(
							'id'       => 'node_1',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 0,
							),
							'data'     => array(
								'label'       => __( 'Collect Status Updates', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'search_content',
								'arguments'   => array(
									'post_type'  => 'mcp_ai_task',
									'date_query' => 'today',
								),
								'description' => __( 'Gather recent task updates from team members.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_2',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 150,
							),
							'data'     => array(
								'label'       => __( 'Summarise Progress', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'client_summarize_text',
								'arguments'   => array( 'format' => 'standup_summary' ),
								'description' => __( 'Create a concise standup summary from updates.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_3',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 300,
							),
							'data'     => array(
								'label'       => __( 'Broadcast to Channels', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'probe_chat',
								'arguments'   => array( 'channel' => 'general' ),
								'description' => __( 'Post the standup summary to team channels.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_4',
							'type'     => 'output',
							'position' => array(
								'x' => 250,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Standup Complete', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Daily standup summary has been shared.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
					'edges'       => array(
						array(
							'id'           => 'edge_1_2',
							'source'       => 'node_1',
							'target'       => 'node_2',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_2_3',
							'source'       => 'node_2',
							'target'       => 'node_3',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_3_4',
							'source'       => 'node_3',
							'target'       => 'node_4',
							'sourceHandle' => 'output',
						),
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// Maintenance presets
		// ------------------------------------------------------------------

		/**
		 * Get site maintenance workflow presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Maintenance preset definitions.
		 */
		private static function get_maintenance_presets() {
			return array(
				'health_check'  => array(
					'name'        => __( 'Site Health Check', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Check site performance, scan for security issues, audit plugins, and generate a report.', 'mcp-ai-wpoos-pro' ),
					'category'    => 'maintenance',
					'icon'        => 'dashicons-heart',
					'tags'        => array( 'maintenance', 'health', 'security' ),
					'nodes'       => array(
						array(
							'id'       => 'node_1',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 0,
							),
							'data'     => array(
								'label'       => __( 'Check Performance', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'sitekit_get_pagespeed',
								'arguments'   => array( 'strategy' => 'both' ),
								'description' => __( 'Run a PageSpeed performance check.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_2',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 150,
							),
							'data'     => array(
								'label'       => __( 'Scan Security', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'run_crawl4ai_job',
								'arguments'   => array( 'mode' => 'security_scan' ),
								'description' => __( 'Scan for common security vulnerabilities.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_3',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 300,
							),
							'data'     => array(
								'label'       => __( 'Audit Plugins', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'search_content',
								'arguments'   => array( 'type' => 'plugin_audit' ),
								'description' => __( 'Check installed plugins for updates and compatibility.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_4',
							'type'     => 'condition',
							'position' => array(
								'x' => 250,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Issues Found?', 'mcp-ai-wpoos-pro' ),
								'expression'  => 'node_3.issues_count > 0',
								'description' => __( 'Determine if any health issues need attention.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_5',
							'type'     => 'tool',
							'position' => array(
								'x' => 100,
								'y' => 600,
							),
							'data'     => array(
								'label'       => __( 'Generate Report', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'create_chart',
								'arguments'   => array( 'type' => 'health_report' ),
								'description' => __( 'Create a detailed site health report.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_6',
							'type'     => 'tool',
							'position' => array(
								'x' => 100,
								'y' => 750,
							),
							'data'     => array(
								'label'       => __( 'Notify Admin', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'probe_chat',
								'arguments'   => array( 'channel' => 'admin' ),
								'description' => __( 'Alert the site administrator about issues.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_7',
							'type'     => 'output',
							'position' => array(
								'x' => 250,
								'y' => 900,
							),
							'data'     => array(
								'label'       => __( 'Health Check Complete', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Site health check finished.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
					'edges'       => array(
						array(
							'id'           => 'edge_1_2',
							'source'       => 'node_1',
							'target'       => 'node_2',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_2_3',
							'source'       => 'node_2',
							'target'       => 'node_3',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_3_4',
							'source'       => 'node_3',
							'target'       => 'node_4',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_4_5',
							'source'       => 'node_4',
							'target'       => 'node_5',
							'sourceHandle' => 'true',
						),
						array(
							'id'           => 'edge_4_7',
							'source'       => 'node_4',
							'target'       => 'node_7',
							'sourceHandle' => 'false',
						),
						array(
							'id'           => 'edge_5_6',
							'source'       => 'node_5',
							'target'       => 'node_6',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_6_7',
							'source'       => 'node_6',
							'target'       => 'node_7',
							'sourceHandle' => 'output',
						),
					),
				),
				'backup_verify' => array(
					'name'        => __( 'Backup Verify', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Trigger a backup, verify its integrity, log the result, and notify the admin.', 'mcp-ai-wpoos-pro' ),
					'category'    => 'maintenance',
					'icon'        => 'dashicons-backup',
					'tags'        => array( 'maintenance', 'backup', 'verification' ),
					'nodes'       => array(
						array(
							'id'       => 'node_1',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 0,
							),
							'data'     => array(
								'label'       => __( 'Trigger Backup', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'execute_workflow',
								'arguments'   => array( 'action' => 'backup_database' ),
								'description' => __( 'Initiate a full site backup.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_2',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 150,
							),
							'data'     => array(
								'label'       => __( 'Verify Integrity', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'validate_workflow',
								'arguments'   => array( 'check' => 'backup_integrity' ),
								'description' => __( 'Validate the backup file integrity.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_3',
							'type'     => 'condition',
							'position' => array(
								'x' => 250,
								'y' => 300,
							),
							'data'     => array(
								'label'       => __( 'Backup Valid?', 'mcp-ai-wpoos-pro' ),
								'expression'  => 'node_2.is_valid === true',
								'description' => __( 'Check if the backup passed integrity verification.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_4',
							'type'     => 'tool',
							'position' => array(
								'x' => 100,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Log Success', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'create_post',
								'arguments'   => array(
									'post_type'   => 'mcp_ai_task',
									'post_status' => 'publish',
								),
								'description' => __( 'Record the successful backup in the log.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_5',
							'type'     => 'tool',
							'position' => array(
								'x' => 400,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Log Failure', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'create_post',
								'arguments'   => array(
									'post_type'   => 'mcp_ai_task',
									'post_status' => 'publish',
									'priority'    => 'high',
								),
								'description' => __( 'Record the backup failure for investigation.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_6',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 600,
							),
							'data'     => array(
								'label'       => __( 'Notify Admin', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'probe_chat',
								'arguments'   => array( 'channel' => 'admin' ),
								'description' => __( 'Send backup status notification to the admin.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_7',
							'type'     => 'output',
							'position' => array(
								'x' => 250,
								'y' => 750,
							),
							'data'     => array(
								'label'       => __( 'Backup Workflow Complete', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Backup verification process finished.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
					'edges'       => array(
						array(
							'id'           => 'edge_1_2',
							'source'       => 'node_1',
							'target'       => 'node_2',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_2_3',
							'source'       => 'node_2',
							'target'       => 'node_3',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_3_4',
							'source'       => 'node_3',
							'target'       => 'node_4',
							'sourceHandle' => 'true',
						),
						array(
							'id'           => 'edge_3_5',
							'source'       => 'node_3',
							'target'       => 'node_5',
							'sourceHandle' => 'false',
						),
						array(
							'id'           => 'edge_4_6',
							'source'       => 'node_4',
							'target'       => 'node_6',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_5_6',
							'source'       => 'node_5',
							'target'       => 'node_6',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_6_7',
							'source'       => 'node_6',
							'target'       => 'node_7',
							'sourceHandle' => 'output',
						),
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// Onboarding presets
		// ------------------------------------------------------------------

		/**
		 * Get user and content onboarding workflow presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Onboarding preset definitions.
		 */
		private static function get_onboarding_presets() {
			return array(
				'user_onboarding' => array(
					'name'        => __( 'User Onboarding', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Detect a new user, create welcome content, assign resources, and send a welcome message.', 'mcp-ai-wpoos-pro' ),
					'category'    => 'onboarding',
					'icon'        => 'dashicons-admin-users',
					'tags'        => array( 'onboarding', 'users', 'welcome' ),
					'nodes'       => array(
						array(
							'id'       => 'node_1',
							'type'     => 'input',
							'position' => array(
								'x' => 250,
								'y' => 0,
							),
							'data'     => array(
								'label'       => __( 'New User Detected', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Triggered when a new user registers.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_2',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 150,
							),
							'data'     => array(
								'label'       => __( 'Create Welcome Content', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'create_post',
								'arguments'   => array(
									'post_type'   => 'page',
									'post_status' => 'publish',
								),
								'description' => __( 'Generate a personalised welcome page for the user.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_3',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 300,
							),
							'data'     => array(
								'label'       => __( 'Assign Resources', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'delegate_to_agent',
								'arguments'   => array( 'task' => 'resource_assignment' ),
								'description' => __( 'Assign onboarding resources and tutorials to the user.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_4',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Generate Welcome Image', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'generate_openai_image',
								'arguments'   => array( 'prompt' => 'welcome banner' ),
								'description' => __( 'Create a personalised welcome banner.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_5',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 600,
							),
							'data'     => array(
								'label'       => __( 'Send Welcome Message', 'mcp-ai-wpoos-pro' ),
								'toolSlug'    => 'probe_chat',
								'arguments'   => array( 'channel' => 'welcome' ),
								'description' => __( 'Send the welcome message to the new user.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_6',
							'type'     => 'output',
							'position' => array(
								'x' => 250,
								'y' => 750,
							),
							'data'     => array(
								'label'       => __( 'Onboarding Complete', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'User onboarding workflow finished.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
					'edges'       => array(
						array(
							'id'           => 'edge_1_2',
							'source'       => 'node_1',
							'target'       => 'node_2',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_2_3',
							'source'       => 'node_2',
							'target'       => 'node_3',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_3_4',
							'source'       => 'node_3',
							'target'       => 'node_4',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_4_5',
							'source'       => 'node_4',
							'target'       => 'node_5',
							'sourceHandle' => 'output',
						),
						array(
							'id'           => 'edge_5_6',
							'source'       => 'node_5',
							'target'       => 'node_6',
							'sourceHandle' => 'output',
						),
					),
				),
			);
		}

		/**
		 * Get CRM Support Ticket workflow presets.
		 *
		 * @since  2.6.0
		 * @return array<string, array> Preset definitions.
		 */
		private static function get_crm_support_presets() {
			return array(
				'support_ticket_triage'        => array(
					'name'        => __( 'Support Ticket Triage', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Auto-triage pipeline: classify incoming support tickets, suggest priority and category, assign to the right team member.', 'mcp-ai-wpoos-pro' ),
					'category'    => 'crm_support',
					'icon'        => 'dashicons-sos',
					'tags'        => array( 'crm', 'support', 'triage', 'classification' ),
					'nodes'       => array(
						array(
							'id'       => 'node_1',
							'type'     => 'input',
							'position' => array(
								'x' => 250,
								'y' => 0,
							),
							'data'     => array(
								'label'       => __( 'New Ticket Created', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Triggered when a new support ticket is created.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_2',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 150,
							),
							'data'     => array(
								'label'       => __( 'Classify Ticket', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'AI classifies the ticket category and suggests priority.', 'mcp-ai-wpoos-pro' ),
								'tool_slug'   => 'classify_support_ticket',
								'tool_args'   => array( 'apply_results' => true ),
							),
						),
						array(
							'id'       => 'node_3',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 300,
							),
							'data'     => array(
								'label'       => __( 'Update Status', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Move ticket to Triaged status.', 'mcp-ai-wpoos-pro' ),
								'tool_slug'   => 'update_support_ticket',
								'tool_args'   => array( 'status' => 'triaged' ),
							),
						),
						array(
							'id'       => 'node_4',
							'type'     => 'output',
							'position' => array(
								'x' => 250,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Ticket Triaged', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Ticket is classified and ready for agent.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
					'edges'       => array(
						array(
							'source' => 'node_1',
							'target' => 'node_2',
						),
						array(
							'source' => 'node_2',
							'target' => 'node_3',
						),
						array(
							'source' => 'node_3',
							'target' => 'node_4',
						),
					),
				),
				'support_ticket_resolution'    => array(
					'name'        => __( 'Support Ticket Resolution', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Resolution workflow: review ticket, add resolution notes, mark as resolved, and trigger CSAT survey.', 'mcp-ai-wpoos-pro' ),
					'category'    => 'crm_support',
					'icon'        => 'dashicons-yes-alt',
					'tags'        => array( 'crm', 'support', 'resolution', 'csat' ),
					'nodes'       => array(
						array(
							'id'       => 'node_1',
							'type'     => 'input',
							'position' => array(
								'x' => 250,
								'y' => 0,
							),
							'data'     => array(
								'label'       => __( 'Ticket in Progress', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Ticket is being worked on by an agent.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_2',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 150,
							),
							'data'     => array(
								'label'       => __( 'Add Resolution Note', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Add internal resolution note to the ticket.', 'mcp-ai-wpoos-pro' ),
								'tool_slug'   => 'update_support_ticket',
								'tool_args'   => array( 'note' => 'Resolution note' ),
							),
						),
						array(
							'id'       => 'node_3',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 300,
							),
							'data'     => array(
								'label'       => __( 'Resolve Ticket', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Mark the ticket as resolved.', 'mcp-ai-wpoos-pro' ),
								'tool_slug'   => 'resolve_support_ticket',
								'tool_args'   => array( 'resolution_type' => 'solved' ),
							),
						),
						array(
							'id'       => 'node_4',
							'type'     => 'output',
							'position' => array(
								'x' => 250,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Ticket Resolved + CSAT Queued', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Resolution complete, CSAT survey triggered.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
					'edges'       => array(
						array(
							'source' => 'node_1',
							'target' => 'node_2',
						),
						array(
							'source' => 'node_2',
							'target' => 'node_3',
						),
						array(
							'source' => 'node_3',
							'target' => 'node_4',
						),
					),
				),
				'support_escalation_handling'  => array(
					'name'        => __( 'Support Escalation Handling', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Escalation workflow: detect SLA breaches, escalate priority, notify managers, and update ticket status.', 'mcp-ai-wpoos-pro' ),
					'category'    => 'crm_support',
					'icon'        => 'dashicons-warning',
					'tags'        => array( 'crm', 'support', 'escalation', 'sla' ),
					'nodes'       => array(
						array(
							'id'       => 'node_1',
							'type'     => 'input',
							'position' => array(
								'x' => 250,
								'y' => 0,
							),
							'data'     => array(
								'label'       => __( 'SLA Breach Detected', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'A ticket SLA has been breached or is at risk.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_2',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 150,
							),
							'data'     => array(
								'label'       => __( 'Escalate Priority', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Bump the ticket priority level.', 'mcp-ai-wpoos-pro' ),
								'tool_slug'   => 'escalate_support_ticket',
								'tool_args'   => array(),
							),
						),
						array(
							'id'       => 'node_3',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 300,
							),
							'data'     => array(
								'label'       => __( 'Get SLA Report', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Generate updated SLA compliance report.', 'mcp-ai-wpoos-pro' ),
								'tool_slug'   => 'get_ticket_sla_report',
								'tool_args'   => array(),
							),
						),
						array(
							'id'       => 'node_4',
							'type'     => 'output',
							'position' => array(
								'x' => 250,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Escalation Complete', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Ticket escalated and managers notified.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
					'edges'       => array(
						array(
							'source' => 'node_1',
							'target' => 'node_2',
						),
						array(
							'source' => 'node_2',
							'target' => 'node_3',
						),
						array(
							'source' => 'node_3',
							'target' => 'node_4',
						),
					),
				),

				// -- Customer Management workflow presets (v2.6.0) --
				'lead_to_customer_conversion'  => array(
					'name'        => __( 'Lead-to-Customer Conversion', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'End-to-end lead conversion pipeline: qualify lead, convert to customer record, create linked deal.', 'mcp-ai-wpoos-pro' ),
					'category'    => 'crm_support',
					'icon'        => 'dashicons-update',
					'tags'        => array( 'crm', 'customers', 'conversion', 'pipeline' ),
					'nodes'       => array(
						array(
							'id'       => 'node_1',
							'type'     => 'input',
							'position' => array(
								'x' => 250,
								'y' => 0,
							),
							'data'     => array(
								'label'       => __( 'Lead Ready for Conversion', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Lead has reached SQL/Opportunity stage.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_2',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 150,
							),
							'data'     => array(
								'label'       => __( 'Qualify Lead (BANT)', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Run BANT qualification to validate lead readiness.', 'mcp-ai-wpoos-pro' ),
								'tool_slug'   => 'qualify_lead_bant',
								'tool_args'   => array(),
							),
						),
						array(
							'id'       => 'node_3',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 300,
							),
							'data'     => array(
								'label'       => __( 'Convert to Customer', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Create customer record with linked deal.', 'mcp-ai-wpoos-pro' ),
								'tool_slug'   => 'convert_lead_to_customer',
								'tool_args'   => array( 'create_deal' => true ),
							),
						),
						array(
							'id'       => 'node_4',
							'type'     => 'output',
							'position' => array(
								'x' => 250,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Customer Created + Deal Open', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Customer record created with linked deal in pipeline.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
					'edges'       => array(
						array(
							'source' => 'node_1',
							'target' => 'node_2',
						),
						array(
							'source' => 'node_2',
							'target' => 'node_3',
						),
						array(
							'source' => 'node_3',
							'target' => 'node_4',
						),
					),
				),
				'customer_onboarding_sequence' => array(
					'name'        => __( 'Customer Onboarding', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'Post-conversion onboarding workflow: create welcome activity and schedule follow-up.', 'mcp-ai-wpoos-pro' ),
					'category'    => 'crm_support',
					'icon'        => 'dashicons-welcome-learn-more',
					'tags'        => array( 'crm', 'customers', 'onboarding', 'automation' ),
					'nodes'       => array(
						array(
							'id'       => 'node_1',
							'type'     => 'input',
							'position' => array(
								'x' => 250,
								'y' => 0,
							),
							'data'     => array(
								'label'       => __( 'Customer Created', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'A new customer record has been created.', 'mcp-ai-wpoos-pro' ),
							),
						),
						array(
							'id'       => 'node_2',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 150,
							),
							'data'     => array(
								'label'       => __( 'Get Customer Details', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Retrieve full customer record.', 'mcp-ai-wpoos-pro' ),
								'tool_slug'   => 'get_customer',
								'tool_args'   => array(),
							),
						),
						array(
							'id'       => 'node_3',
							'type'     => 'tool',
							'position' => array(
								'x' => 250,
								'y' => 300,
							),
							'data'     => array(
								'label'       => __( 'Create Welcome Activity', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Schedule a welcome call or onboarding meeting.', 'mcp-ai-wpoos-pro' ),
								'tool_slug'   => 'create_crm_activity',
								'tool_args'   => array(
									'activity_type' => 'call',
									'subject'       => 'Customer Welcome Call',
								),
							),
						),
						array(
							'id'       => 'node_4',
							'type'     => 'output',
							'position' => array(
								'x' => 250,
								'y' => 450,
							),
							'data'     => array(
								'label'       => __( 'Onboarding Initiated', 'mcp-ai-wpoos-pro' ),
								'description' => __( 'Welcome activity created, onboarding sequence started.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
					'edges'       => array(
						array(
							'source' => 'node_1',
							'target' => 'node_2',
						),
						array(
							'source' => 'node_2',
							'target' => 'node_3',
						),
						array(
							'source' => 'node_3',
							'target' => 'node_4',
						),
					),
				),
			);
		}
	}
}
