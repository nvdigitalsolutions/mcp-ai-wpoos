<?php
/**
 * Pro Schedule Presets
 *
 * Provides pre-configured schedule presets for the Pro Schedule Manager.
 * Each toolkit includes curated presets that can be installed with a single
 * click, automatically creating the corresponding scheduled task, workflow,
 * assistant run, or channel broadcast.
 *
 * Supported schedule types:
 * - task:              Fires a WordPress action hook on a cron interval.
 * - workflow:          Executes a sequence of tool calls.
 * - assistant_run:     Sends a message to a user-selected AI assistant.
 * - channel_broadcast: Broadcasts a message to chat channels.
 * - workflow_builder:  Runs a saved Pro Workflow Builder DAG.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage Schedule_Presets
 * @since      1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Presets' ) ) {

	/**
	 * Class WP_MCP_AI_Pro_Schedule_Presets
	 *
	 * Registry of pre-built schedule presets organised by toolkit.
	 * All methods are static so the class can be used without instantiation.
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_Pro_Schedule_Presets {

		// ------------------------------------------------------------------
		// Preset retrieval
		// ------------------------------------------------------------------

		/**
		 * Get every available schedule preset.
		 *
		 * Presets are grouped internally by toolkit but returned as a flat
		 * associative array keyed by preset ID.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Associative array of preset definitions.
		 */
		public static function get_presets() {
			$presets = array_merge(
				self::get_ecommerce_presets(),
				self::get_social_media_presets(),
				self::get_analytics_presets(),
				self::get_advanced_analytics_presets(),
				self::get_multilingual_presets(),
				self::get_video_production_presets(),
				self::get_financial_planner_presets(),
				self::get_dj_management_presets(),
				self::get_image_production_presets(),
				self::get_ai_tool_builder_presets(),
				self::get_architect_agent_presets(),
				self::get_architectural_design_presets(),
				self::get_site_creator_presets(),
				self::get_document_generation_presets(),
				self::get_crm_presets(),
				self::get_regulatory_registration_presets(),
				self::get_chat_channels_presets(),
				self::get_media_presets(),
				self::get_calendar_booking_presets(),
				self::get_health_wellness_presets()
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
			$presets = self::get_presets();

			return isset( $presets[ $preset_id ] ) ? $presets[ $preset_id ] : null;
		}

		/**
		 * Get all presets belonging to a specific toolkit.
		 *
		 * @since  1.0.0
		 * @param  string $toolkit Toolkit slug (e.g. 'ecommerce', 'crm').
		 * @return array<string, array> Filtered preset definitions.
		 */
		public static function get_presets_by_toolkit( $toolkit ) {
			$toolkit = sanitize_key( $toolkit );

			return array_filter(
				self::get_presets(),
				function ( $preset ) use ( $toolkit ) {
					return isset( $preset['toolkit'] ) && $preset['toolkit'] === $toolkit;
				}
			);
		}

		/**
		 * Get all presets matching a given category.
		 *
		 * @since  1.0.0
		 * @param  string $category Category slug (e.g. 'content', 'monitoring').
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
				'content'       => __( 'Content Creation & Management', 'mcp-ai-wpoos-pro' ),
				'monitoring'    => __( 'Site Monitoring & Health Checks', 'mcp-ai-wpoos-pro' ),
				'reporting'     => __( 'Reports & Analytics', 'mcp-ai-wpoos-pro' ),
				'communication' => __( 'Messaging & Notifications', 'mcp-ai-wpoos-pro' ),
				'maintenance'   => __( 'Site Maintenance & Cleanup', 'mcp-ai-wpoos-pro' ),
				'marketing'     => __( 'Marketing Automation', 'mcp-ai-wpoos-pro' ),
				'business'      => __( 'Business Operations', 'mcp-ai-wpoos-pro' ),
			);
		}

		// ------------------------------------------------------------------
		// Preset installation
		// ------------------------------------------------------------------

		/**
		 * Install a preset as a live schedule.
		 *
		 * Resolves the preset definition, merges it into the format expected
		 * by {@see WP_MCP_AI_Pro_Schedule_Manager::create_schedule()}, and
		 * creates the schedule entry.
		 *
		 * @since  1.0.0
		 * @param  string $preset_id The unique preset identifier.
		 * @param  int    $user_id   WordPress user ID performing the install.
		 * @return string|\WP_Error  Schedule ID on success, WP_Error on failure.
		 */
		public static function install_preset( $preset_id, $user_id ) {
			$preset = self::get_preset( $preset_id );

			if ( null === $preset ) {
				return new \WP_Error(
					'invalid_preset',
					/* translators: %s: preset identifier */
					sprintf( __( 'Schedule preset "%s" does not exist.', 'mcp-ai-wpoos-pro' ), sanitize_key( $preset_id ) )
				);
			}

			if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
				return new \WP_Error(
					'missing_dependency',
					__( 'The Pro Schedule Manager is not available.', 'mcp-ai-wpoos-pro' )
				);
			}

			$user_id = absint( $user_id );
			if ( 0 === $user_id ) {
				return new \WP_Error(
					'invalid_user',
					__( 'A valid user ID is required to install a preset.', 'mcp-ai-wpoos-pro' )
				);
			}

			$schedule_data = self::build_schedule_data( $preset );

			return WP_MCP_AI_Pro_Schedule_Manager::create_schedule( $schedule_data, $user_id );
		}

		// ------------------------------------------------------------------
		// Internal helpers
		// ------------------------------------------------------------------

		/**
		 * Convert a preset definition into the data array expected by the
		 * Schedule Manager's create_schedule() method.
		 *
		 * @since  1.0.0
		 * @param  array $preset Preset definition.
		 * @return array Schedule data suitable for create_schedule().
		 */
		private static function build_schedule_data( array $preset ) {
			$data = array(
				'name'          => isset( $preset['name'] ) ? $preset['name'] : '',
				'description'   => isset( $preset['description'] ) ? $preset['description'] : '',
				'schedule_type' => isset( $preset['schedule_type'] ) ? $preset['schedule_type'] : 'task',
				'schedule'      => isset( $preset['schedule'] ) ? $preset['schedule'] : 'daily',
				'tags'          => isset( $preset['tags'] ) ? $preset['tags'] : array(),
				'enabled'       => true,
			);

			$schedule_data = isset( $preset['schedule_data'] ) ? $preset['schedule_data'] : array();

			switch ( $data['schedule_type'] ) {
				case 'task':
					if ( isset( $schedule_data['hook'] ) ) {
						$data['hook'] = $schedule_data['hook'];
					}
					break;

				case 'workflow':
					if ( isset( $schedule_data['workflow_steps'] ) ) {
						$data['workflow_steps'] = $schedule_data['workflow_steps'];
					}
					break;

				case 'assistant_run':
					if ( isset( $schedule_data['assistant_config'] ) ) {
						$data['assistant_config'] = $schedule_data['assistant_config'];
					}
					break;

				case 'channel_broadcast':
					if ( isset( $schedule_data['broadcast_config'] ) ) {
						$data['broadcast_config'] = $schedule_data['broadcast_config'];
					}
					break;

				case 'workflow_builder':
					if ( isset( $schedule_data['workflow_builder_id'] ) ) {
						$data['workflow_builder_id'] = $schedule_data['workflow_builder_id'];
					}
					break;
			}

			return $data;
		}

		// ------------------------------------------------------------------
		// E-Commerce presets
		// ------------------------------------------------------------------

		/**
		 * Get E-Commerce toolkit presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Preset definitions.
		 */
		private static function get_ecommerce_presets() {
			return array(
				'inventory_check'         => array(
					'name'          => __( 'Inventory Level Check', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Periodically checks product inventory levels and flags items that are low in stock or out of stock.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'ecommerce',
					'category'      => 'monitoring',
					'icon'          => 'dashicons-products',
					'schedule_type' => 'task',
					'schedule'      => 'hourly',
					'tags'          => array( 'ecommerce', 'inventory', 'monitoring' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_ecommerce_inventory_check',
					),
				),
				'daily_sales_report'      => array(
					'name'          => __( 'Daily Sales Report', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Generates a comprehensive daily sales summary including revenue, top products, and order volume.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'ecommerce',
					'category'      => 'reporting',
					'icon'          => 'dashicons-chart-bar',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'daily',
					'tags'          => array( 'ecommerce', 'sales', 'reports' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Generate a daily sales report covering total revenue, number of orders, average order value, top-selling products, and comparison with the previous day.',
						),
					),
				),
				'abandoned_cart_followup' => array(
					'name'          => __( 'Abandoned Cart Follow-up', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Identifies abandoned carts and triggers a follow-up workflow to recover lost sales.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'ecommerce',
					'category'      => 'marketing',
					'icon'          => 'dashicons-cart',
					'schedule_type' => 'workflow',
					'schedule'      => 'wp_mcp_ai_every_30_minutes',
					'tags'          => array( 'ecommerce', 'cart', 'recovery', 'marketing' ),
					'schedule_data' => array(
						'workflow_steps' => array(
							array(
								'tool_slug' => 'get_abandoned_carts',
								'arguments' => array(
									'since' => '30 minutes ago',
								),
								'label'     => __( 'Fetch abandoned carts', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'tool_slug' => 'send_cart_recovery_email',
								'arguments' => array(),
								'label'     => __( 'Send recovery emails', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
				'price_monitoring'        => array(
					'name'          => __( 'Product Price Monitor', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Monitors product prices for unexpected changes and validates sale pricing is applied correctly.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'ecommerce',
					'category'      => 'monitoring',
					'icon'          => 'dashicons-money-alt',
					'schedule_type' => 'task',
					'schedule'      => 'wp_mcp_ai_every_15_minutes',
					'tags'          => array( 'ecommerce', 'pricing', 'monitoring' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_ecommerce_price_monitor',
					),
				),
				'order_status_broadcast'  => array(
					'name'          => __( 'Order Status Broadcast', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Broadcasts a daily summary of pending, processing, and completed orders to your team channels.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'ecommerce',
					'category'      => 'communication',
					'icon'          => 'dashicons-megaphone',
					'schedule_type' => 'channel_broadcast',
					'schedule'      => 'daily',
					'tags'          => array( 'ecommerce', 'orders', 'broadcast' ),
					'schedule_data' => array(
						'broadcast_config' => array(
							'message'  => 'Daily order status summary: review pending, processing, and completed orders for today.',
							'channels' => array( 'slack' ),
						),
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// Social Media presets
		// ------------------------------------------------------------------

		/**
		 * Get Social Media toolkit presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Preset definitions.
		 */
		private static function get_social_media_presets() {
			return array(
				'daily_content_scheduler'  => array(
					'name'          => __( 'Daily Content Scheduler', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Queues the day\'s social media posts across all connected platforms using your content calendar.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'social_media',
					'category'      => 'content',
					'icon'          => 'dashicons-share',
					'schedule_type' => 'workflow',
					'schedule'      => 'daily',
					'tags'          => array( 'social', 'content', 'scheduling' ),
					'schedule_data' => array(
						'workflow_steps' => array(
							array(
								'tool_slug' => 'get_content_calendar',
								'arguments' => array(
									'date' => 'today',
								),
								'label'     => __( 'Fetch today\'s content calendar', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'tool_slug' => 'schedule_social_posts',
								'arguments' => array(),
								'label'     => __( 'Queue posts to platforms', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
				'engagement_report'        => array(
					'name'          => __( 'Daily Engagement Report', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Compiles a summary of likes, shares, comments, and follower growth across social channels.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'social_media',
					'category'      => 'reporting',
					'icon'          => 'dashicons-chart-line',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'daily',
					'tags'          => array( 'social', 'engagement', 'reports' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Generate a daily social media engagement report including likes, shares, comments, follower growth, and top-performing posts across all connected platforms.',
						),
					),
				),
				'trending_topics_monitor'  => array(
					'name'          => __( 'Trending Topics Monitor', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Scans trending topics and hashtags relevant to your industry for timely content opportunities.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'social_media',
					'category'      => 'monitoring',
					'icon'          => 'dashicons-trending',
					'schedule_type' => 'task',
					'schedule'      => 'wp_mcp_ai_every_30_minutes',
					'tags'          => array( 'social', 'trends', 'monitoring' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_social_trending_monitor',
					),
				),
				'social_analytics_digest'  => array(
					'name'          => __( 'Weekly Social Analytics Digest', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Produces a weekly deep-dive into social media performance with actionable recommendations.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'social_media',
					'category'      => 'reporting',
					'icon'          => 'dashicons-analytics',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'weekly',
					'tags'          => array( 'social', 'analytics', 'weekly' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Create a weekly social media analytics digest covering reach, engagement rate, audience demographics, best posting times, and strategic recommendations for the coming week.',
						),
					),
				),
				'cross_platform_post'      => array(
					'name'          => __( 'Cross-Platform Post Syndicator', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Publishes new blog posts to all connected social media platforms with optimised captions per channel.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'social_media',
					'category'      => 'content',
					'icon'          => 'dashicons-share-alt',
					'schedule_type' => 'workflow',
					'schedule'      => 'daily',
					'tags'          => array( 'social', 'syndication', 'content' ),
					'schedule_data' => array(
						'workflow_steps' => array(
							array(
								'tool_slug' => 'get_recent_posts',
								'arguments' => array(
									'since'  => '24 hours ago',
									'status' => 'publish',
								),
								'label'     => __( 'Fetch recently published posts', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'tool_slug' => 'generate_social_captions',
								'arguments' => array(),
								'label'     => __( 'Generate platform-specific captions', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'tool_slug' => 'publish_to_social',
								'arguments' => array(),
								'label'     => __( 'Publish to social platforms', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// Analytics presets
		// ------------------------------------------------------------------

		/**
		 * Get Analytics toolkit presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Preset definitions.
		 */
		private static function get_analytics_presets() {
			return array(
				'daily_traffic_report'      => array(
					'name'          => __( 'Daily Traffic Report', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Summarises daily website traffic including page views, unique visitors, bounce rate, and traffic sources.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'analytics',
					'category'      => 'reporting',
					'icon'          => 'dashicons-chart-area',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'daily',
					'tags'          => array( 'analytics', 'traffic', 'reports' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Generate a daily website traffic report covering page views, unique visitors, bounce rate, average session duration, and top traffic sources compared to yesterday.',
						),
					),
				),
				'weekly_performance_digest' => array(
					'name'          => __( 'Weekly Performance Digest', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Compiles a week-over-week comparison of key performance indicators and growth trends.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'analytics',
					'category'      => 'reporting',
					'icon'          => 'dashicons-performance',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'weekly',
					'tags'          => array( 'analytics', 'performance', 'weekly' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Create a weekly performance digest comparing this week to last week for page views, conversions, revenue, top landing pages, and user engagement metrics.',
						),
					),
				),
				'realtime_alert_monitor'    => array(
					'name'          => __( 'Real-Time Traffic Alert Monitor', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Monitors traffic in near-real-time and fires alerts when unusual spikes or drops are detected.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'analytics',
					'category'      => 'monitoring',
					'icon'          => 'dashicons-warning',
					'schedule_type' => 'task',
					'schedule'      => 'wp_mcp_ai_every_5_minutes',
					'tags'          => array( 'analytics', 'realtime', 'alerts' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_analytics_realtime_alert',
					),
				),
				'conversion_funnel_report'  => array(
					'name'          => __( 'Conversion Funnel Report', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Analyses the full conversion funnel from landing page to purchase, highlighting drop-off points.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'analytics',
					'category'      => 'reporting',
					'icon'          => 'dashicons-filter',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'weekly',
					'tags'          => array( 'analytics', 'conversion', 'funnel' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Analyse the conversion funnel for the past week. Identify each stage from landing page visit through to completed purchase, calculate conversion rates between stages, and highlight the biggest drop-off points with improvement suggestions.',
						),
					),
				),
				'seo_ranking_check'         => array(
					'name'          => __( 'SEO Ranking Check', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Checks search engine ranking positions for tracked keywords and logs changes over time.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'analytics',
					'category'      => 'monitoring',
					'icon'          => 'dashicons-search',
					'schedule_type' => 'task',
					'schedule'      => 'daily',
					'tags'          => array( 'analytics', 'seo', 'rankings' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_analytics_seo_ranking_check',
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// Advanced Analytics presets
		// ------------------------------------------------------------------

		/**
		 * Get Advanced Analytics toolkit presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Preset definitions.
		 */
		private static function get_advanced_analytics_presets() {
			return array(
				'predictive_trend_report' => array(
					'name'          => __( 'Predictive Trend Report', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Uses historical data to predict upcoming traffic and conversion trends for the next week.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'advanced_analytics',
					'category'      => 'reporting',
					'icon'          => 'dashicons-chart-line',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'weekly',
					'tags'          => array( 'analytics', 'predictions', 'trends' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Analyse historical traffic and conversion data to generate a predictive trend report for the coming week. Include expected traffic volumes, conversion rate forecasts, and confidence intervals.',
						),
					),
				),
				'anomaly_detection_scan'  => array(
					'name'          => __( 'Anomaly Detection Scan', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Scans analytics data for statistical anomalies that may indicate issues or opportunities.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'advanced_analytics',
					'category'      => 'monitoring',
					'icon'          => 'dashicons-visibility',
					'schedule_type' => 'task',
					'schedule'      => 'wp_mcp_ai_every_15_minutes',
					'tags'          => array( 'analytics', 'anomaly', 'monitoring' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_advanced_analytics_anomaly_scan',
					),
				),
				'cohort_analysis_weekly'  => array(
					'name'          => __( 'Weekly Cohort Analysis', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Segments users into weekly cohorts and analyses retention, engagement, and lifetime value patterns.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'advanced_analytics',
					'category'      => 'reporting',
					'icon'          => 'dashicons-groups',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'weekly',
					'tags'          => array( 'analytics', 'cohorts', 'retention' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Perform a weekly cohort analysis. Segment users by their first visit week, then analyse retention rates, repeat engagement, and estimated lifetime value for each cohort over the past 8 weeks.',
						),
					),
				),
				'ab_test_monitor'         => array(
					'name'          => __( 'A/B Test Monitor', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Checks running A/B tests for statistical significance and alerts when results are conclusive.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'advanced_analytics',
					'category'      => 'monitoring',
					'icon'          => 'dashicons-randomize',
					'schedule_type' => 'task',
					'schedule'      => 'wp_mcp_ai_every_30_minutes',
					'tags'          => array( 'analytics', 'ab-test', 'experiments' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_advanced_analytics_ab_test_monitor',
					),
				),
				'revenue_forecast'        => array(
					'name'          => __( 'Monthly Revenue Forecast', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Projects monthly revenue based on current run rate, seasonality patterns, and pipeline data.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'advanced_analytics',
					'category'      => 'reporting',
					'icon'          => 'dashicons-money-alt',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'wp_mcp_ai_monthly',
					'tags'          => array( 'analytics', 'revenue', 'forecast' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Generate a monthly revenue forecast. Analyse current month-to-date revenue, compare with previous months, factor in seasonal trends, and project end-of-month revenue with optimistic and conservative scenarios.',
						),
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// Multilingual presets
		// ------------------------------------------------------------------

		/**
		 * Get Multilingual toolkit presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Preset definitions.
		 */
		private static function get_multilingual_presets() {
			return array(
				'translation_queue_check'    => array(
					'name'          => __( 'Translation Queue Check', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Checks for content awaiting translation and prioritises the queue by publication date and traffic.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'multilingual',
					'category'      => 'monitoring',
					'icon'          => 'dashicons-translation',
					'schedule_type' => 'task',
					'schedule'      => 'wp_mcp_ai_every_30_minutes',
					'tags'          => array( 'multilingual', 'translation', 'queue' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_multilingual_translation_queue_check',
					),
				),
				'language_coverage_report'   => array(
					'name'          => __( 'Language Coverage Report', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Reports on translation completeness for each configured locale across all content types.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'multilingual',
					'category'      => 'reporting',
					'icon'          => 'dashicons-admin-site-alt3',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'weekly',
					'tags'          => array( 'multilingual', 'coverage', 'reports' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Generate a language coverage report. For each configured locale, list the percentage of posts, pages, and custom post types that have been translated, and highlight the highest-priority untranslated content.',
						),
					),
				),
				'translation_memory_sync'    => array(
					'name'          => __( 'Translation Memory Sync', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Synchronises the translation memory database with the latest approved translations.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'multilingual',
					'category'      => 'maintenance',
					'icon'          => 'dashicons-update',
					'schedule_type' => 'task',
					'schedule'      => 'daily',
					'tags'          => array( 'multilingual', 'translation-memory', 'sync' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_multilingual_tm_sync',
					),
				),
				'locale_content_audit'       => array(
					'name'          => __( 'Locale Content Audit', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Audits localised content for quality issues such as broken formatting, placeholder mismatches, or outdated translations.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'multilingual',
					'category'      => 'maintenance',
					'icon'          => 'dashicons-editor-spellcheck',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'weekly',
					'tags'          => array( 'multilingual', 'audit', 'quality' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Audit all localised content for quality issues. Check for broken HTML formatting, mismatched placeholders, translations that are significantly longer or shorter than the source, and content that has not been updated since the source was revised.',
						),
					),
				),
				'untranslated_content_alert' => array(
					'name'          => __( 'Untranslated Content Alert', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Sends a daily notification listing content published without translations in required locales.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'multilingual',
					'category'      => 'communication',
					'icon'          => 'dashicons-flag',
					'schedule_type' => 'channel_broadcast',
					'schedule'      => 'daily',
					'tags'          => array( 'multilingual', 'alerts', 'untranslated' ),
					'schedule_data' => array(
						'broadcast_config' => array(
							'message'  => 'Daily untranslated content alert: the following recently published content is missing translations in one or more required locales.',
							'channels' => array( 'slack' ),
						),
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// Video Production presets
		// ------------------------------------------------------------------

		/**
		 * Get Video Production toolkit presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Preset definitions.
		 */
		private static function get_video_production_presets() {
			return array(
				'video_render_queue_check'   => array(
					'name'          => __( 'Video Render Queue Check', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Monitors the video render queue and alerts when jobs are stalled, failed, or completed.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'video_production',
					'category'      => 'monitoring',
					'icon'          => 'dashicons-video-alt3',
					'schedule_type' => 'task',
					'schedule'      => 'wp_mcp_ai_every_15_minutes',
					'tags'          => array( 'video', 'render', 'queue', 'monitoring' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_video_render_queue_check',
					),
				),
				'daily_upload_scheduler'     => array(
					'name'          => __( 'Daily Video Upload Scheduler', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Processes queued videos and uploads them to configured video hosting platforms on a daily schedule.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'video_production',
					'category'      => 'content',
					'icon'          => 'dashicons-upload',
					'schedule_type' => 'workflow',
					'schedule'      => 'daily',
					'tags'          => array( 'video', 'upload', 'scheduling' ),
					'schedule_data' => array(
						'workflow_steps' => array(
							array(
								'tool_slug' => 'get_queued_videos',
								'arguments' => array(
									'status' => 'ready',
								),
								'label'     => __( 'Fetch videos ready for upload', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'tool_slug' => 'upload_video_batch',
								'arguments' => array(),
								'label'     => __( 'Upload to hosting platforms', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
				'video_analytics_report'     => array(
					'name'          => __( 'Weekly Video Analytics Report', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Compiles a weekly report on video views, watch time, engagement, and audience retention metrics.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'video_production',
					'category'      => 'reporting',
					'icon'          => 'dashicons-chart-pie',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'weekly',
					'tags'          => array( 'video', 'analytics', 'reports' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Generate a weekly video analytics report covering total views, average watch time, audience retention curves, engagement rate, and top-performing videos with recommendations for content improvement.',
						),
					),
				),
				'thumbnail_generation_batch' => array(
					'name'          => __( 'Thumbnail Generation Batch', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Generates optimised thumbnails for videos that are missing custom thumbnail images.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'video_production',
					'category'      => 'content',
					'icon'          => 'dashicons-format-image',
					'schedule_type' => 'workflow',
					'schedule'      => 'daily',
					'tags'          => array( 'video', 'thumbnails', 'batch' ),
					'schedule_data' => array(
						'workflow_steps' => array(
							array(
								'tool_slug' => 'get_videos_without_thumbnails',
								'arguments' => array(),
								'label'     => __( 'Find videos missing thumbnails', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'tool_slug' => 'generate_video_thumbnails',
								'arguments' => array(
									'count' => 3,
								),
								'label'     => __( 'Generate thumbnail candidates', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
				'video_transcription_batch'  => array(
					'name'          => __( 'Video Transcription Batch', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Transcribes videos that lack captions or transcripts, improving accessibility and SEO.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'video_production',
					'category'      => 'content',
					'icon'          => 'dashicons-text',
					'schedule_type' => 'workflow',
					'schedule'      => 'daily',
					'tags'          => array( 'video', 'transcription', 'accessibility' ),
					'schedule_data' => array(
						'workflow_steps' => array(
							array(
								'tool_slug' => 'get_videos_without_transcripts',
								'arguments' => array(),
								'label'     => __( 'Find videos missing transcripts', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'tool_slug' => 'transcribe_video',
								'arguments' => array(),
								'label'     => __( 'Transcribe and attach captions', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// Financial Planner presets
		// ------------------------------------------------------------------

		/**
		 * Get Financial Planner toolkit presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Preset definitions.
		 */
		private static function get_financial_planner_presets() {
			return array(
				'daily_market_brief'           => array(
					'name'          => __( 'Daily Market Brief', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Generates a morning market brief covering major indices, sector performance, and notable market news.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'financial_planner',
					'category'      => 'reporting',
					'icon'          => 'dashicons-chart-bar',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'daily',
					'tags'          => array( 'financial', 'market', 'daily-brief' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Generate a morning market brief covering major stock indices, bond yields, commodity prices, notable sector performance, and key financial news for today.',
						),
					),
				),
				'portfolio_performance_report' => array(
					'name'          => __( 'Weekly Portfolio Performance Report', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Analyses portfolio holdings and returns over the past week with benchmark comparisons.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'financial_planner',
					'category'      => 'reporting',
					'icon'          => 'dashicons-portfolio',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'weekly',
					'tags'          => array( 'financial', 'portfolio', 'performance' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Generate a weekly portfolio performance report. Include total return, asset allocation breakdown, top and bottom performers, benchmark comparison, and rebalancing recommendations if allocations have drifted.',
						),
					),
				),
				'expense_categorization'       => array(
					'name'          => __( 'Daily Expense Categorisation', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Categorises new financial transactions and reconciles them against budget allocations.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'financial_planner',
					'category'      => 'business',
					'icon'          => 'dashicons-money-alt',
					'schedule_type' => 'workflow',
					'schedule'      => 'daily',
					'tags'          => array( 'financial', 'expenses', 'budgeting' ),
					'schedule_data' => array(
						'workflow_steps' => array(
							array(
								'tool_slug' => 'get_uncategorised_transactions',
								'arguments' => array(),
								'label'     => __( 'Fetch uncategorised transactions', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'tool_slug' => 'categorise_transactions',
								'arguments' => array(),
								'label'     => __( 'Auto-categorise against budget', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
				'tax_deadline_reminder'        => array(
					'name'          => __( 'Tax Deadline Reminder', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Broadcasts upcoming tax filing and payment deadlines to relevant team channels.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'financial_planner',
					'category'      => 'communication',
					'icon'          => 'dashicons-calendar-alt',
					'schedule_type' => 'channel_broadcast',
					'schedule'      => 'daily',
					'tags'          => array( 'financial', 'tax', 'deadlines', 'reminders' ),
					'schedule_data' => array(
						'broadcast_config' => array(
							'message'  => 'Tax deadline reminder: check for upcoming tax filing and payment deadlines within the next 30 days.',
							'channels' => array( 'slack' ),
						),
					),
				),
				'investment_rebalance_alert'   => array(
					'name'          => __( 'Investment Rebalance Alert', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Alerts the team when portfolio allocations drift beyond defined thresholds and rebalancing is needed.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'financial_planner',
					'category'      => 'communication',
					'icon'          => 'dashicons-image-rotate',
					'schedule_type' => 'channel_broadcast',
					'schedule'      => 'weekly',
					'tags'          => array( 'financial', 'investment', 'rebalance' ),
					'schedule_data' => array(
						'broadcast_config' => array(
							'message'  => 'Investment rebalance check: portfolio allocations have been reviewed. Any drifts beyond target thresholds are flagged for rebalancing action.',
							'channels' => array( 'slack' ),
						),
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// DJ Management presets
		// ------------------------------------------------------------------

		/**
		 * Get DJ Management toolkit presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Preset definitions.
		 */
		private static function get_dj_management_presets() {
			return array(
				'gig_reminder_broadcast'       => array(
					'name'          => __( 'Gig Reminder Broadcast', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Sends daily reminders for upcoming gigs including venue details, load-in times, and set requirements.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'dj_management',
					'category'      => 'communication',
					'icon'          => 'dashicons-microphone',
					'schedule_type' => 'channel_broadcast',
					'schedule'      => 'daily',
					'tags'          => array( 'dj', 'gigs', 'reminders' ),
					'schedule_data' => array(
						'broadcast_config' => array(
							'message'  => 'Daily gig reminder: here are your upcoming gigs for the next 48 hours with venue details, load-in times, and set length requirements.',
							'channels' => array( 'slack', 'telegram' ),
						),
					),
				),
				'playlist_rotation_update'     => array(
					'name'          => __( 'Playlist Rotation Update', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Rotates and refreshes playlists weekly based on trending tracks, audience preferences, and venue style.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'dj_management',
					'category'      => 'content',
					'icon'          => 'dashicons-playlist-audio',
					'schedule_type' => 'workflow',
					'schedule'      => 'weekly',
					'tags'          => array( 'dj', 'playlists', 'music' ),
					'schedule_data' => array(
						'workflow_steps' => array(
							array(
								'tool_slug' => 'get_trending_tracks',
								'arguments' => array(),
								'label'     => __( 'Fetch trending tracks', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'tool_slug' => 'update_playlist_rotation',
								'arguments' => array(),
								'label'     => __( 'Update playlist rotation', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
				'venue_availability_check'     => array(
					'name'          => __( 'Venue Availability Check', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Checks partner venue availability for upcoming booking windows and flags open slots.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'dj_management',
					'category'      => 'business',
					'icon'          => 'dashicons-building',
					'schedule_type' => 'task',
					'schedule'      => 'daily',
					'tags'          => array( 'dj', 'venues', 'availability' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_dj_venue_availability_check',
					),
				),
				'equipment_maintenance_alert'  => array(
					'name'          => __( 'Equipment Maintenance Alert', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Alerts the team when DJ equipment is due for maintenance or calibration based on usage hours.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'dj_management',
					'category'      => 'communication',
					'icon'          => 'dashicons-admin-tools',
					'schedule_type' => 'channel_broadcast',
					'schedule'      => 'weekly',
					'tags'          => array( 'dj', 'equipment', 'maintenance' ),
					'schedule_data' => array(
						'broadcast_config' => array(
							'message'  => 'Weekly equipment maintenance check: review equipment usage hours and flag any gear due for maintenance, calibration, or replacement.',
							'channels' => array( 'slack' ),
						),
					),
				),
				'setlist_generation'           => array(
					'name'          => __( 'AI Setlist Generation', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Generates suggested setlists for upcoming gigs based on venue type, audience demographics, and event theme.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'dj_management',
					'category'      => 'content',
					'icon'          => 'dashicons-format-audio',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'weekly',
					'tags'          => array( 'dj', 'setlist', 'ai' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Generate suggested setlists for upcoming gigs this week. Consider the venue type, expected audience demographics, event theme, and time slot. Include warm-up, peak, and cooldown segments with BPM progression.',
						),
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// Image Production presets
		// ------------------------------------------------------------------

		/**
		 * Get Image Production toolkit presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Preset definitions.
		 */
		private static function get_image_production_presets() {
			return array(
				'batch_image_optimization'   => array(
					'name'          => __( 'Batch Image Optimisation', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Optimises uncompressed images in the media library by applying lossless compression and WebP conversion.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'image_production',
					'category'      => 'maintenance',
					'icon'          => 'dashicons-images-alt2',
					'schedule_type' => 'workflow',
					'schedule'      => 'daily',
					'tags'          => array( 'images', 'optimisation', 'performance' ),
					'schedule_data' => array(
						'workflow_steps' => array(
							array(
								'tool_slug' => 'get_unoptimised_images',
								'arguments' => array(
									'limit' => 50,
								),
								'label'     => __( 'Find unoptimised images', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'tool_slug' => 'optimise_images_batch',
								'arguments' => array(
									'format' => 'webp',
								),
								'label'     => __( 'Compress and convert to WebP', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
				'daily_media_library_audit'  => array(
					'name'          => __( 'Daily Media Library Audit', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Audits the media library for oversized images, missing metadata, and duplicate files.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'image_production',
					'category'      => 'reporting',
					'icon'          => 'dashicons-format-gallery',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'daily',
					'tags'          => array( 'images', 'media-library', 'audit' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Audit the WordPress media library. Report on oversized images exceeding 2 MB, images missing alt text or title metadata, potential duplicates, and overall storage usage with cleanup recommendations.',
						),
					),
				),
				'image_alt_text_generation'  => array(
					'name'          => __( 'Image Alt Text Generation', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Uses AI to generate descriptive alt text for images that are missing accessibility attributes.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'image_production',
					'category'      => 'content',
					'icon'          => 'dashicons-edit',
					'schedule_type' => 'workflow',
					'schedule'      => 'daily',
					'tags'          => array( 'images', 'alt-text', 'accessibility' ),
					'schedule_data' => array(
						'workflow_steps' => array(
							array(
								'tool_slug' => 'get_images_without_alt',
								'arguments' => array(
									'limit' => 25,
								),
								'label'     => __( 'Find images without alt text', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'tool_slug' => 'generate_image_alt_text',
								'arguments' => array(),
								'label'     => __( 'Generate and save alt text', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
				'watermark_batch_process'    => array(
					'name'          => __( 'Watermark Batch Process', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Applies configured watermarks to newly uploaded images that match the watermark criteria.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'image_production',
					'category'      => 'content',
					'icon'          => 'dashicons-lock',
					'schedule_type' => 'workflow',
					'schedule'      => 'daily',
					'tags'          => array( 'images', 'watermark', 'batch' ),
					'schedule_data' => array(
						'workflow_steps' => array(
							array(
								'tool_slug' => 'get_unwatermarked_images',
								'arguments' => array(),
								'label'     => __( 'Find images needing watermarks', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'tool_slug' => 'apply_watermark_batch',
								'arguments' => array(),
								'label'     => __( 'Apply watermarks', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
				'image_cdn_sync'             => array(
					'name'          => __( 'Image CDN Sync', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Synchronises newly uploaded or modified images with the configured CDN for faster global delivery.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'image_production',
					'category'      => 'maintenance',
					'icon'          => 'dashicons-cloud-upload',
					'schedule_type' => 'task',
					'schedule'      => 'wp_mcp_ai_every_30_minutes',
					'tags'          => array( 'images', 'cdn', 'sync' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_image_cdn_sync',
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// AI Tool Builder presets
		// ------------------------------------------------------------------

		/**
		 * Get AI Tool Builder toolkit presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Preset definitions.
		 */
		private static function get_ai_tool_builder_presets() {
			return array(
				'tool_health_check'           => array(
					'name'          => __( 'Tool Health Check', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Pings every registered AI tool to verify availability, response time, and error rates.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'ai_tool_builder',
					'category'      => 'monitoring',
					'icon'          => 'dashicons-heart',
					'schedule_type' => 'task',
					'schedule'      => 'wp_mcp_ai_every_15_minutes',
					'tags'          => array( 'tools', 'health', 'monitoring' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_tool_builder_health_check',
					),
				),
				'tool_usage_report'           => array(
					'name'          => __( 'Weekly Tool Usage Report', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Reports on tool invocation counts, success rates, average execution time, and most active users.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'ai_tool_builder',
					'category'      => 'reporting',
					'icon'          => 'dashicons-chart-bar',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'weekly',
					'tags'          => array( 'tools', 'usage', 'reports' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Generate a weekly AI tool usage report. Include total invocations per tool, success and failure rates, average execution time, most active users, and recommendations for tools that may need attention or deprecation.',
						),
					),
				),
				'tool_schema_validation'      => array(
					'name'          => __( 'Tool Schema Validation', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Validates all registered tool schemas against the MCP specification to catch definition errors.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'ai_tool_builder',
					'category'      => 'monitoring',
					'icon'          => 'dashicons-yes-alt',
					'schedule_type' => 'task',
					'schedule'      => 'daily',
					'tags'          => array( 'tools', 'schema', 'validation' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_tool_builder_schema_validation',
					),
				),
				'deprecated_tool_scan'        => array(
					'name'          => __( 'Deprecated Tool Scan', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Identifies tools marked as deprecated that are still being invoked and recommends migration paths.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'ai_tool_builder',
					'category'      => 'maintenance',
					'icon'          => 'dashicons-dismiss',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'weekly',
					'tags'          => array( 'tools', 'deprecated', 'cleanup' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Scan for deprecated AI tools that are still receiving invocations. List each deprecated tool, its recent usage count, the recommended replacement tool, and a migration plan for each.',
						),
					),
				),
				'tool_performance_benchmark'  => array(
					'name'          => __( 'Tool Performance Benchmark', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Benchmarks tool execution times and resource consumption to identify performance bottlenecks.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'ai_tool_builder',
					'category'      => 'monitoring',
					'icon'          => 'dashicons-performance',
					'schedule_type' => 'task',
					'schedule'      => 'daily',
					'tags'          => array( 'tools', 'performance', 'benchmark' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_tool_builder_performance_benchmark',
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// Architect Agent presets
		// ------------------------------------------------------------------

		/**
		 * Get Architect Agent toolkit presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Preset definitions.
		 */
		private static function get_architect_agent_presets() {
			return array(
				'code_quality_scan'             => array(
					'name'          => __( 'Code Quality Scan', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Runs automated code quality checks including linting, complexity analysis, and coding standards compliance.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'architect_agent',
					'category'      => 'monitoring',
					'icon'          => 'dashicons-editor-code',
					'schedule_type' => 'task',
					'schedule'      => 'daily',
					'tags'          => array( 'code', 'quality', 'linting' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_architect_code_quality_scan',
					),
				),
				'dependency_update_check'       => array(
					'name'          => __( 'Dependency Update Check', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Checks Composer and npm dependencies for available updates, prioritising security patches.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'architect_agent',
					'category'      => 'maintenance',
					'icon'          => 'dashicons-update',
					'schedule_type' => 'task',
					'schedule'      => 'weekly',
					'tags'          => array( 'dependencies', 'updates', 'security' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_architect_dependency_update_check',
					),
				),
				'security_vulnerability_scan'   => array(
					'name'          => __( 'Security Vulnerability Scan', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Scans the codebase and dependencies for known security vulnerabilities and generates a risk report.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'architect_agent',
					'category'      => 'monitoring',
					'icon'          => 'dashicons-shield',
					'schedule_type' => 'task',
					'schedule'      => 'daily',
					'tags'          => array( 'security', 'vulnerabilities', 'scanning' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_architect_security_scan',
					),
				),
				'api_endpoint_monitor'          => array(
					'name'          => __( 'API Endpoint Monitor', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Monitors REST API endpoints for availability, response time, and error rates.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'architect_agent',
					'category'      => 'monitoring',
					'icon'          => 'dashicons-rest-api',
					'schedule_type' => 'task',
					'schedule'      => 'wp_mcp_ai_every_15_minutes',
					'tags'          => array( 'api', 'endpoints', 'uptime' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_architect_api_endpoint_monitor',
					),
				),
				'documentation_freshness_check' => array(
					'name'          => __( 'Documentation Freshness Check', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Reviews documentation for staleness by comparing last-updated dates against related code changes.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'architect_agent',
					'category'      => 'maintenance',
					'icon'          => 'dashicons-media-document',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'weekly',
					'tags'          => array( 'documentation', 'freshness', 'review' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Review all documentation files for freshness. Compare each document\'s last-updated date against recent code changes in related files. Flag any documentation that may be outdated and list the specific code changes that may require documentation updates.',
						),
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// Architectural Design presets
		// ------------------------------------------------------------------

		/**
		 * Get Architectural Design toolkit presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Preset definitions.
		 */
		private static function get_architectural_design_presets() {
			return array(
				'project_milestone_check'  => array(
					'name'          => __( 'Project Milestone Check', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Reviews active design projects against their milestones and flags approaching or overdue deadlines.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'architectural_design',
					'category'      => 'business',
					'icon'          => 'dashicons-flag',
					'schedule_type' => 'task',
					'schedule'      => 'daily',
					'tags'          => array( 'architecture', 'milestones', 'deadlines' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_arch_design_milestone_check',
					),
				),
				'material_price_update'    => array(
					'name'          => __( 'Material Price Update', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Fetches latest pricing for tracked building materials and updates project cost estimates.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'architectural_design',
					'category'      => 'business',
					'icon'          => 'dashicons-money-alt',
					'schedule_type' => 'task',
					'schedule'      => 'daily',
					'tags'          => array( 'architecture', 'materials', 'pricing' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_arch_design_material_price_update',
					),
				),
				'design_revision_reminder' => array(
					'name'          => __( 'Design Revision Reminder', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Notifies team members about pending design revisions that need review or client approval.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'architectural_design',
					'category'      => 'communication',
					'icon'          => 'dashicons-edit',
					'schedule_type' => 'channel_broadcast',
					'schedule'      => 'daily',
					'tags'          => array( 'architecture', 'revisions', 'reminders' ),
					'schedule_data' => array(
						'broadcast_config' => array(
							'message'  => 'Design revision reminder: the following design revisions are pending review or client approval. Please address them at your earliest convenience.',
							'channels' => array( 'slack' ),
						),
					),
				),
				'permit_deadline_alert'    => array(
					'name'          => __( 'Permit Deadline Alert', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Alerts the team about upcoming building permit submission deadlines and required documentation.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'architectural_design',
					'category'      => 'communication',
					'icon'          => 'dashicons-clipboard',
					'schedule_type' => 'channel_broadcast',
					'schedule'      => 'daily',
					'tags'          => array( 'architecture', 'permits', 'deadlines' ),
					'schedule_data' => array(
						'broadcast_config' => array(
							'message'  => 'Permit deadline alert: review upcoming building permit deadlines within the next 14 days and ensure all required documentation is prepared.',
							'channels' => array( 'slack' ),
						),
					),
				),
				'client_progress_report'   => array(
					'name'          => __( 'Client Progress Report', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Generates a client-ready progress report summarising design phase completion, next steps, and timeline.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'architectural_design',
					'category'      => 'reporting',
					'icon'          => 'dashicons-businessman',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'weekly',
					'tags'          => array( 'architecture', 'clients', 'reports' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Generate a client-facing progress report for all active architectural design projects. Include current phase completion percentage, completed milestones, upcoming deliverables, timeline adherence, and any risks or blockers.',
						),
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// Site Creator presets
		// ------------------------------------------------------------------

		/**
		 * Get Site Creator toolkit presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Preset definitions.
		 */
		private static function get_site_creator_presets() {
			return array(
				'site_health_monitor'     => array(
					'name'          => __( 'Site Health Monitor', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Runs WordPress Site Health checks and monitors critical site metrics at regular intervals.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'site_creator',
					'category'      => 'monitoring',
					'icon'          => 'dashicons-heart',
					'schedule_type' => 'task',
					'schedule'      => 'wp_mcp_ai_every_15_minutes',
					'tags'          => array( 'site', 'health', 'monitoring' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_site_creator_health_monitor',
					),
				),
				'plugin_update_check'     => array(
					'name'          => __( 'Plugin Update Check', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Checks for available plugin and theme updates, prioritising those with security fixes.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'site_creator',
					'category'      => 'maintenance',
					'icon'          => 'dashicons-update',
					'schedule_type' => 'task',
					'schedule'      => 'daily',
					'tags'          => array( 'site', 'plugins', 'updates' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_site_creator_plugin_update_check',
					),
				),
				'broken_link_scan'        => array(
					'name'          => __( 'Broken Link Scan', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Crawls the site for broken internal and external links and generates a report with fix suggestions.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'site_creator',
					'category'      => 'maintenance',
					'icon'          => 'dashicons-admin-links',
					'schedule_type' => 'task',
					'schedule'      => 'weekly',
					'tags'          => array( 'site', 'links', 'seo' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_site_creator_broken_link_scan',
					),
				),
				'performance_benchmark'   => array(
					'name'          => __( 'Performance Benchmark', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Measures page load times, TTFB, and Core Web Vitals across key pages and tracks changes over time.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'site_creator',
					'category'      => 'monitoring',
					'icon'          => 'dashicons-performance',
					'schedule_type' => 'task',
					'schedule'      => 'daily',
					'tags'          => array( 'site', 'performance', 'speed' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_site_creator_performance_benchmark',
					),
				),
				'ssl_certificate_monitor' => array(
					'name'          => __( 'SSL Certificate Monitor', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Monitors SSL certificate expiry dates and alerts well in advance of expiration.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'site_creator',
					'category'      => 'monitoring',
					'icon'          => 'dashicons-lock',
					'schedule_type' => 'task',
					'schedule'      => 'daily',
					'tags'          => array( 'site', 'ssl', 'security' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_site_creator_ssl_monitor',
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// Document Generation presets
		// ------------------------------------------------------------------

		/**
		 * Get Document Generation toolkit presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Preset definitions.
		 */
		private static function get_document_generation_presets() {
			return array(
				'daily_report_generation'    => array(
					'name'          => __( 'Daily Report Generation', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Generates daily operational reports from configured data sources and saves them as downloadable documents.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'document_generation',
					'category'      => 'reporting',
					'icon'          => 'dashicons-media-document',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'daily',
					'tags'          => array( 'documents', 'reports', 'generation' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Generate the daily operational report. Compile data from all configured sources, format it according to the standard report template, and save the completed document for download.',
						),
					),
				),
				'invoice_batch_process'      => array(
					'name'          => __( 'Invoice Batch Process', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Generates and sends invoices for completed orders or services that have not yet been invoiced.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'document_generation',
					'category'      => 'business',
					'icon'          => 'dashicons-money-alt',
					'schedule_type' => 'workflow',
					'schedule'      => 'daily',
					'tags'          => array( 'documents', 'invoices', 'billing' ),
					'schedule_data' => array(
						'workflow_steps' => array(
							array(
								'tool_slug' => 'get_uninvoiced_orders',
								'arguments' => array(),
								'label'     => __( 'Fetch uninvoiced orders', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'tool_slug' => 'generate_invoice_batch',
								'arguments' => array(),
								'label'     => __( 'Generate and send invoices', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
				'contract_renewal_reminder'  => array(
					'name'          => __( 'Contract Renewal Reminder', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Sends daily notifications for contracts approaching their renewal or expiry date.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'document_generation',
					'category'      => 'communication',
					'icon'          => 'dashicons-media-text',
					'schedule_type' => 'channel_broadcast',
					'schedule'      => 'daily',
					'tags'          => array( 'documents', 'contracts', 'renewals' ),
					'schedule_data' => array(
						'broadcast_config' => array(
							'message'  => 'Contract renewal reminder: the following contracts are approaching renewal or expiry within the next 30 days. Please review and take action.',
							'channels' => array( 'slack' ),
						),
					),
				),
				'document_archive_cleanup'   => array(
					'name'          => __( 'Document Archive Cleanup', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Archives old documents past their retention period and cleans up temporary or draft documents.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'document_generation',
					'category'      => 'maintenance',
					'icon'          => 'dashicons-archive',
					'schedule_type' => 'workflow',
					'schedule'      => 'weekly',
					'tags'          => array( 'documents', 'archive', 'cleanup' ),
					'schedule_data' => array(
						'workflow_steps' => array(
							array(
								'tool_slug' => 'get_expired_documents',
								'arguments' => array(
									'retention_days' => 90,
								),
								'label'     => __( 'Find documents past retention', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'tool_slug' => 'archive_documents',
								'arguments' => array(),
								'label'     => __( 'Archive and clean up', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
				'template_update_check'      => array(
					'name'          => __( 'Template Update Check', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Validates document templates are current and flags any that reference outdated data fields or branding.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'document_generation',
					'category'      => 'maintenance',
					'icon'          => 'dashicons-layout',
					'schedule_type' => 'task',
					'schedule'      => 'weekly',
					'tags'          => array( 'documents', 'templates', 'validation' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_docgen_template_update_check',
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// CRM presets
		// ------------------------------------------------------------------

		/**
		 * Get CRM toolkit presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Preset definitions.
		 */
		private static function get_crm_presets() {
			return array(
				'lead_followup_reminder'     => array(
					'name'          => __( 'Lead Follow-up Reminder', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Sends daily reminders for leads that are due for follow-up based on their pipeline stage and last contact date.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'crm',
					'category'      => 'communication',
					'icon'          => 'dashicons-phone',
					'schedule_type' => 'channel_broadcast',
					'schedule'      => 'daily',
					'tags'          => array( 'crm', 'leads', 'followup' ),
					'schedule_data' => array(
						'broadcast_config' => array(
							'message'  => 'Lead follow-up reminder: the following leads are due for contact today based on their pipeline stage and last interaction date.',
							'channels' => array( 'slack' ),
						),
					),
				),
				'contact_engagement_score'   => array(
					'name'          => __( 'Contact Engagement Scoring', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Recalculates engagement scores for all contacts based on recent interactions, email opens, and site visits.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'crm',
					'category'      => 'business',
					'icon'          => 'dashicons-star-filled',
					'schedule_type' => 'workflow',
					'schedule'      => 'daily',
					'tags'          => array( 'crm', 'engagement', 'scoring' ),
					'schedule_data' => array(
						'workflow_steps' => array(
							array(
								'tool_slug' => 'get_contact_interactions',
								'arguments' => array(
									'since' => '24 hours ago',
								),
								'label'     => __( 'Fetch recent interactions', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'tool_slug' => 'recalculate_engagement_scores',
								'arguments' => array(),
								'label'     => __( 'Recalculate engagement scores', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
				'pipeline_status_report'     => array(
					'name'          => __( 'Daily Pipeline Status Report', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Generates a daily snapshot of the sales pipeline showing deals by stage, value, and probability.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'crm',
					'category'      => 'reporting',
					'icon'          => 'dashicons-chart-bar',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'daily',
					'tags'          => array( 'crm', 'pipeline', 'sales' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Generate a daily sales pipeline report. Show deals grouped by stage with total value, win probability, expected close dates, and highlight deals that have been stagnant for more than 7 days.',
						),
					),
				),
				'customer_birthday_alerts'   => array(
					'name'          => __( 'Customer Birthday Alerts', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Sends daily alerts for customer birthdays coming up in the next 7 days for personalised outreach.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'crm',
					'category'      => 'communication',
					'icon'          => 'dashicons-buddicons-friends',
					'schedule_type' => 'channel_broadcast',
					'schedule'      => 'daily',
					'tags'          => array( 'crm', 'birthdays', 'engagement' ),
					'schedule_data' => array(
						'broadcast_config' => array(
							'message'  => 'Customer birthday alert: the following customers have birthdays in the next 7 days. Consider sending personalised greetings or offers.',
							'channels' => array( 'slack' ),
						),
					),
				),
				'crm_data_cleanup'           => array(
					'name'          => __( 'CRM Data Cleanup', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Identifies and cleans duplicate contacts, invalid emails, and stale records in the CRM database.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'crm',
					'category'      => 'maintenance',
					'icon'          => 'dashicons-database',
					'schedule_type' => 'workflow',
					'schedule'      => 'weekly',
					'tags'          => array( 'crm', 'data', 'cleanup' ),
					'schedule_data' => array(
						'workflow_steps' => array(
							array(
								'tool_slug' => 'scan_duplicate_contacts',
								'arguments' => array(),
								'label'     => __( 'Scan for duplicate contacts', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'tool_slug' => 'validate_contact_data',
								'arguments' => array(),
								'label'     => __( 'Validate emails and phone numbers', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'tool_slug' => 'archive_stale_contacts',
								'arguments' => array(
									'inactive_days' => 365,
								),
								'label'     => __( 'Archive stale contacts', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// Regulatory Registration presets
		// ------------------------------------------------------------------

		/**
		 * Get Regulatory Registration toolkit presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Preset definitions.
		 */
		private static function get_regulatory_registration_presets() {
			return array(
				'compliance_deadline_check'    => array(
					'name'          => __( 'Compliance Deadline Check', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Checks for upcoming regulatory compliance deadlines and ensures all required submissions are on track.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'regulatory_registration',
					'category'      => 'business',
					'icon'          => 'dashicons-clipboard',
					'schedule_type' => 'task',
					'schedule'      => 'daily',
					'tags'          => array( 'regulatory', 'compliance', 'deadlines' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_regulatory_compliance_deadline_check',
					),
				),
				'registration_renewal_alert'   => array(
					'name'          => __( 'Registration Renewal Alert', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Alerts the team about business registrations, licences, and certifications approaching their renewal date.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'regulatory_registration',
					'category'      => 'communication',
					'icon'          => 'dashicons-warning',
					'schedule_type' => 'channel_broadcast',
					'schedule'      => 'daily',
					'tags'          => array( 'regulatory', 'registration', 'renewals' ),
					'schedule_data' => array(
						'broadcast_config' => array(
							'message'  => 'Registration renewal alert: the following registrations, licences, or certifications are approaching renewal within the next 60 days.',
							'channels' => array( 'slack' ),
						),
					),
				),
				'audit_log_review'             => array(
					'name'          => __( 'Weekly Audit Log Review', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Reviews system audit logs for compliance-relevant activities and generates a summary for the compliance team.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'regulatory_registration',
					'category'      => 'reporting',
					'icon'          => 'dashicons-list-view',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'weekly',
					'tags'          => array( 'regulatory', 'audit', 'logs' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Review the system audit logs from the past week. Identify any compliance-relevant activities including data access, permission changes, export operations, and user account modifications. Summarise findings and flag any anomalies.',
						),
					),
				),
				'policy_update_notification'   => array(
					'name'          => __( 'Policy Update Notification', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Notifies stakeholders when internal policies or compliance documents are updated or due for review.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'regulatory_registration',
					'category'      => 'communication',
					'icon'          => 'dashicons-media-text',
					'schedule_type' => 'channel_broadcast',
					'schedule'      => 'weekly',
					'tags'          => array( 'regulatory', 'policies', 'notifications' ),
					'schedule_data' => array(
						'broadcast_config' => array(
							'message'  => 'Policy update notification: review internal policies that have been updated this week or are due for their scheduled review.',
							'channels' => array( 'slack' ),
						),
					),
				),
				'regulatory_change_monitor'    => array(
					'name'          => __( 'Regulatory Change Monitor', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Monitors for changes in relevant regulations and standards that may affect business operations.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'regulatory_registration',
					'category'      => 'monitoring',
					'icon'          => 'dashicons-visibility',
					'schedule_type' => 'task',
					'schedule'      => 'daily',
					'tags'          => array( 'regulatory', 'changes', 'monitoring' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_regulatory_change_monitor',
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// Chat Channels presets
		// ------------------------------------------------------------------

		/**
		 * Get Chat Channels toolkit presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Preset definitions.
		 */
		private static function get_chat_channels_presets() {
			return array(
				'daily_standup_reminder'       => array(
					'name'          => __( 'Daily Standup Reminder', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Sends a daily standup reminder prompting team members to share their status updates.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'chat_channels',
					'category'      => 'communication',
					'icon'          => 'dashicons-groups',
					'schedule_type' => 'channel_broadcast',
					'schedule'      => 'daily',
					'tags'          => array( 'chat', 'standup', 'team' ),
					'schedule_data' => array(
						'broadcast_config' => array(
							'message'  => 'Good morning, team! Time for the daily standup. Please share: 1) What you completed yesterday, 2) What you are working on today, 3) Any blockers.',
							'channels' => array( 'slack' ),
						),
					),
				),
				'channel_activity_digest'      => array(
					'name'          => __( 'Channel Activity Digest', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Compiles a daily digest of activity across all chat channels with key highlights and action items.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'chat_channels',
					'category'      => 'reporting',
					'icon'          => 'dashicons-format-chat',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'daily',
					'tags'          => array( 'chat', 'activity', 'digest' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Generate a daily digest of chat channel activity. Summarise the key discussions, decisions made, action items identified, and unresolved questions across all active channels.',
						),
					),
				),
				'welcome_message_scheduler'    => array(
					'name'          => __( 'Welcome Message Scheduler', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Checks for newly joined channel members and sends personalised welcome messages with onboarding links.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'chat_channels',
					'category'      => 'communication',
					'icon'          => 'dashicons-admin-users',
					'schedule_type' => 'task',
					'schedule'      => 'wp_mcp_ai_every_30_minutes',
					'tags'          => array( 'chat', 'welcome', 'onboarding' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_chat_welcome_message_scheduler',
					),
				),
				'support_queue_alert'          => array(
					'name'          => __( 'Support Queue Alert', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Monitors the support queue and broadcasts alerts when response times exceed defined SLA thresholds.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'chat_channels',
					'category'      => 'monitoring',
					'icon'          => 'dashicons-sos',
					'schedule_type' => 'channel_broadcast',
					'schedule'      => 'wp_mcp_ai_every_15_minutes',
					'tags'          => array( 'chat', 'support', 'sla' ),
					'schedule_data' => array(
						'broadcast_config' => array(
							'message'  => 'Support queue alert: there are unresolved support requests approaching or exceeding SLA response time thresholds. Immediate attention required.',
							'channels' => array( 'slack' ),
						),
					),
				),
				'team_announcement_broadcast'  => array(
					'name'          => __( 'Weekly Team Announcement', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Broadcasts a weekly team-wide announcement covering company updates, wins, and upcoming events.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'chat_channels',
					'category'      => 'communication',
					'icon'          => 'dashicons-megaphone',
					'schedule_type' => 'channel_broadcast',
					'schedule'      => 'weekly',
					'tags'          => array( 'chat', 'announcements', 'team' ),
					'schedule_data' => array(
						'broadcast_config' => array(
							'message'  => 'Weekly team update: here is a summary of this week\'s highlights, upcoming events, and important announcements for the team.',
							'channels' => array( 'slack', 'discord' ),
						),
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// Media presets
		// ------------------------------------------------------------------

		/**
		 * Get Media toolkit presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Preset definitions.
		 */
		private static function get_media_presets() {
			return array(
				'media_library_cleanup'      => array(
					'name'          => __( 'Media Library Cleanup', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Removes orphaned media files, clears broken attachment records, and reclaims storage space.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'media',
					'category'      => 'maintenance',
					'icon'          => 'dashicons-trash',
					'schedule_type' => 'workflow',
					'schedule'      => 'weekly',
					'tags'          => array( 'media', 'cleanup', 'storage' ),
					'schedule_data' => array(
						'workflow_steps' => array(
							array(
								'tool_slug' => 'scan_orphaned_media',
								'arguments' => array(),
								'label'     => __( 'Scan for orphaned media', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'tool_slug' => 'cleanup_orphaned_media',
								'arguments' => array(
									'dry_run' => false,
								),
								'label'     => __( 'Remove orphaned files', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
				'unused_media_scan'          => array(
					'name'          => __( 'Unused Media Scan', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Identifies media files that are not referenced in any post, page, or widget content.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'media',
					'category'      => 'maintenance',
					'icon'          => 'dashicons-search',
					'schedule_type' => 'task',
					'schedule'      => 'weekly',
					'tags'          => array( 'media', 'unused', 'audit' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_media_unused_scan',
					),
				),
				'media_optimization_report'  => array(
					'name'          => __( 'Weekly Media Optimisation Report', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Reports on media library health including total storage, compression savings, and files needing optimisation.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'media',
					'category'      => 'reporting',
					'icon'          => 'dashicons-chart-pie',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'weekly',
					'tags'          => array( 'media', 'optimisation', 'reports' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Generate a weekly media optimisation report. Include total media library size, number of files by type, compression savings achieved, files still needing optimisation, and recommendations for reducing storage usage.',
						),
					),
				),
				'media_backup_check'         => array(
					'name'          => __( 'Media Backup Check', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Verifies that all media files are included in the latest backup and flags any files missing from backup sets.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'media',
					'category'      => 'monitoring',
					'icon'          => 'dashicons-cloud-saved',
					'schedule_type' => 'task',
					'schedule'      => 'daily',
					'tags'          => array( 'media', 'backup', 'verification' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_media_backup_check',
					),
				),
				'media_usage_audit'          => array(
					'name'          => __( 'Monthly Media Usage Audit', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Comprehensive monthly audit of media usage patterns, storage growth trends, and bandwidth consumption.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'media',
					'category'      => 'reporting',
					'icon'          => 'dashicons-analytics',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'wp_mcp_ai_monthly',
					'tags'          => array( 'media', 'audit', 'usage' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Perform a comprehensive monthly media usage audit. Analyse storage growth trends, most-accessed media files, bandwidth consumption by file type, upload patterns, and provide recommendations for media management improvements.',
						),
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// Calendar Booking presets
		// ------------------------------------------------------------------

		/**
		 * Get Calendar Booking toolkit presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Preset definitions.
		 */
		private static function get_calendar_booking_presets() {
			return array(
				'daily_appointment_reminder'  => array(
					'name'          => __( 'Daily Appointment Reminder', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Sends daily reminders for upcoming appointments including time, participant details, and meeting links.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'calendar_booking',
					'category'      => 'communication',
					'icon'          => 'dashicons-calendar-alt',
					'schedule_type' => 'channel_broadcast',
					'schedule'      => 'daily',
					'tags'          => array( 'calendar', 'appointments', 'reminders' ),
					'schedule_data' => array(
						'broadcast_config' => array(
							'message'  => 'Daily appointment reminder: here are your scheduled appointments for today with times, participants, and meeting links.',
							'channels' => array( 'slack' ),
						),
					),
				),
				'availability_sync'           => array(
					'name'          => __( 'Availability Sync', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Synchronises availability across connected calendars to prevent double bookings and conflicts.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'calendar_booking',
					'category'      => 'maintenance',
					'icon'          => 'dashicons-update',
					'schedule_type' => 'task',
					'schedule'      => 'wp_mcp_ai_every_30_minutes',
					'tags'          => array( 'calendar', 'availability', 'sync' ),
					'schedule_data' => array(
						'hook' => 'wp_mcp_ai_calendar_availability_sync',
					),
				),
				'no_show_followup'            => array(
					'name'          => __( 'No-Show Follow-up', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Identifies no-show appointments and triggers a follow-up workflow to reschedule or collect feedback.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'calendar_booking',
					'category'      => 'business',
					'icon'          => 'dashicons-dismiss',
					'schedule_type' => 'workflow',
					'schedule'      => 'daily',
					'tags'          => array( 'calendar', 'no-show', 'followup' ),
					'schedule_data' => array(
						'workflow_steps' => array(
							array(
								'tool_slug' => 'get_no_show_appointments',
								'arguments' => array(
									'since' => '24 hours ago',
								),
								'label'     => __( 'Identify no-show appointments', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'tool_slug' => 'send_reschedule_invitation',
								'arguments' => array(),
								'label'     => __( 'Send reschedule invitations', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
				'booking_confirmation_batch'  => array(
					'name'          => __( 'Booking Confirmation Batch', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Processes new bookings and sends confirmation messages with calendar invites and preparation details.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'calendar_booking',
					'category'      => 'business',
					'icon'          => 'dashicons-yes',
					'schedule_type' => 'workflow',
					'schedule'      => 'wp_mcp_ai_every_30_minutes',
					'tags'          => array( 'calendar', 'bookings', 'confirmations' ),
					'schedule_data' => array(
						'workflow_steps' => array(
							array(
								'tool_slug' => 'get_unconfirmed_bookings',
								'arguments' => array(),
								'label'     => __( 'Fetch unconfirmed bookings', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'tool_slug' => 'send_booking_confirmations',
								'arguments' => array(),
								'label'     => __( 'Send confirmation messages', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
				'weekly_schedule_digest'      => array(
					'name'          => __( 'Weekly Schedule Digest', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Generates a weekly overview of the upcoming schedule with booking statistics and capacity analysis.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'calendar_booking',
					'category'      => 'reporting',
					'icon'          => 'dashicons-schedule',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'weekly',
					'tags'          => array( 'calendar', 'schedule', 'digest' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Generate a weekly schedule digest for the coming week. Include total appointments by day, available slots, booking utilisation rate, most popular time slots, and any scheduling conflicts that need attention.',
						),
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// Health & Wellness presets
		// ------------------------------------------------------------------

		/**
		 * Get Health & Wellness toolkit presets.
		 *
		 * @since  1.0.0
		 * @return array<string, array> Preset definitions.
		 */
		private static function get_health_wellness_presets() {
			return array(
				'daily_vitals_reminder'       => array(
					'name'          => __( 'Daily Vitals Reminder', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Sends daily reminders to log vital signs such as blood pressure, heart rate, and weight.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'health_wellness',
					'category'      => 'communication',
					'icon'          => 'dashicons-heart',
					'schedule_type' => 'channel_broadcast',
					'schedule'      => 'daily',
					'tags'          => array( 'health', 'vitals', 'reminders' ),
					'schedule_data' => array(
						'broadcast_config' => array(
							'message'  => 'Daily vitals reminder: please take a moment to log your vital signs including blood pressure, heart rate, and weight.',
							'channels' => array( 'telegram' ),
						),
					),
				),
				'medication_schedule_alert'   => array(
					'name'          => __( 'Medication Schedule Alert', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Sends medication reminders based on configured schedules to help maintain adherence.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'health_wellness',
					'category'      => 'communication',
					'icon'          => 'dashicons-plus-alt',
					'schedule_type' => 'channel_broadcast',
					'schedule'      => 'wp_mcp_ai_every_30_minutes',
					'tags'          => array( 'health', 'medication', 'reminders' ),
					'schedule_data' => array(
						'broadcast_config' => array(
							'message'  => 'Medication reminder: it is time to take your scheduled medication. Please confirm once completed.',
							'channels' => array( 'telegram' ),
						),
					),
				),
				'wellness_check_broadcast'    => array(
					'name'          => __( 'Daily Wellness Check', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Broadcasts a daily wellness check-in prompt encouraging users to log mood, energy, and activity levels.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'health_wellness',
					'category'      => 'communication',
					'icon'          => 'dashicons-smiley',
					'schedule_type' => 'channel_broadcast',
					'schedule'      => 'daily',
					'tags'          => array( 'health', 'wellness', 'check-in' ),
					'schedule_data' => array(
						'broadcast_config' => array(
							'message'  => 'Daily wellness check-in: how are you feeling today? Please log your mood, energy level, sleep quality, and any physical activity completed.',
							'channels' => array( 'telegram' ),
						),
					),
				),
				'health_metrics_report'       => array(
					'name'          => __( 'Weekly Health Metrics Report', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Compiles a weekly report of health metrics with trend analysis and personalised wellness recommendations.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'health_wellness',
					'category'      => 'reporting',
					'icon'          => 'dashicons-chart-line',
					'schedule_type' => 'assistant_run',
					'schedule'      => 'weekly',
					'tags'          => array( 'health', 'metrics', 'reports' ),
					'schedule_data' => array(
						'assistant_config' => array(
							'message' => 'Generate a weekly health metrics report. Analyse logged vitals, activity levels, sleep patterns, and mood data over the past 7 days. Identify trends, compare against targets, and provide personalised wellness recommendations.',
						),
					),
				),
				'appointment_followup_check'  => array(
					'name'          => __( 'Appointment Follow-up Check', 'mcp-ai-wpoos-pro' ),
					'description'   => __( 'Checks for recent health appointments and triggers follow-up workflows for post-visit tasks and feedback.', 'mcp-ai-wpoos-pro' ),
					'toolkit'       => 'health_wellness',
					'category'      => 'business',
					'icon'          => 'dashicons-clipboard',
					'schedule_type' => 'workflow',
					'schedule'      => 'daily',
					'tags'          => array( 'health', 'appointments', 'followup' ),
					'schedule_data' => array(
						'workflow_steps' => array(
							array(
								'tool_slug' => 'get_recent_health_appointments',
								'arguments' => array(
									'since' => '24 hours ago',
								),
								'label'     => __( 'Fetch recent health appointments', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'tool_slug' => 'send_appointment_followup',
								'arguments' => array(),
								'label'     => __( 'Send follow-up messages', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
			);
		}
	}
}
