<?php
/**
 * Pre-configured Schedule Presets for Pro Toolkits.
 *
 * Provides industry-standard, ready-to-install schedule presets for each
 * pro toolkit.  Each toolkit has at least 5 presets covering common
 * automation patterns: daily monitoring, weekly reports, monthly audits,
 * and real-time processing pipelines.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Presets' ) ) {
	/**
	 * Schedule presets for pro toolkits.
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_Pro_Schedule_Presets {

		/**
		 * Get human-readable labels for each toolkit setting key.
		 *
		 * @since 1.0.0
		 * @return array Associative array of setting key => label.
		 */
		public static function get_toolkit_labels() {
			return array(
			'enable_ecommerce_toolkit' => __( 'E-commerce', 'mcp-ai-wpoos-pro' ),
			'enable_social_media_toolkit' => __( 'Social Media', 'mcp-ai-wpoos-pro' ),
			'enable_advanced_analytics_toolkit' => __( 'Advanced Analytics', 'mcp-ai-wpoos-pro' ),
			'enable_financial_planner_toolkit' => __( 'Financial Planning', 'mcp-ai-wpoos-pro' ),
			'enable_crm_toolkit' => __( 'CRM', 'mcp-ai-wpoos-pro' ),
			'enable_document_generation_toolkit' => __( 'Document Generation', 'mcp-ai-wpoos-pro' ),
			'enable_image_production_toolkit' => __( 'Image Production', 'mcp-ai-wpoos-pro' ),
			'enable_multilingual_toolkit' => __( 'Multilingual', 'mcp-ai-wpoos-pro' ),
			'enable_video_production_toolkit' => __( 'Video Production', 'mcp-ai-wpoos-pro' ),
			'enable_architectural_design_toolkit' => __( 'Architectural Design', 'mcp-ai-wpoos-pro' ),
			'enable_dj_management_toolkit' => __( 'DJ Management', 'mcp-ai-wpoos-pro' ),
			'enable_ai_tool_builder_toolkit' => __( 'AI Tool Builder', 'mcp-ai-wpoos-pro' ),
			'enable_site_creator_toolkit' => __( 'Site Creator', 'mcp-ai-wpoos-pro' ),
			'enable_health_wellness_management' => __( 'Health & Wellness', 'mcp-ai-wpoos-pro' ),
			'enable_regulatory_registration_toolkit' => __( 'Regulatory Registration', 'mcp-ai-wpoos-pro' ),
			'calendar_booking' => __( 'Calendar Booking', 'mcp-ai-wpoos-pro' ),
			'enable_project_management' => __( 'Project Management', 'mcp-ai-wpoos-pro' ),
			'enable_eca_management' => __( 'ECA Management', 'mcp-ai-wpoos-pro' ),
			'enable_places_management' => __( 'Places Management', 'mcp-ai-wpoos-pro' ),
			'enable_media_toolkit' => __( 'Media Toolkit', 'mcp-ai-wpoos-pro' ),
			);
		}

		/**
		 * Get all schedule presets across every toolkit.
		 *
		 * @since 1.0.0
		 * @return array Flat associative array of preset_slug => preset_data.
		 */
		public static function get_presets() {
			return array_merge(
			self::get_ecommerce_presets(),
			self::get_social_media_presets(),
			self::get_analytics_presets(),
			self::get_financial_presets(),
			self::get_crm_presets(),
			self::get_document_presets(),
			self::get_image_presets(),
			self::get_multilingual_presets(),
			self::get_video_presets(),
			self::get_architecture_presets(),
			self::get_dj_presets(),
			self::get_tool_builder_presets(),
			self::get_site_creator_presets(),
			self::get_health_presets(),
			self::get_regulatory_presets(),
			self::get_calendar_presets(),
			self::get_project_presets(),
			self::get_eca_presets(),
			self::get_places_presets(),
			self::get_media_presets(),
				array()
			);
		}

		/**
		 * Get presets for a specific toolkit.
		 *
		 * @since 1.0.0
		 * @param string $toolkit_key Toolkit setting key (e.g. 'enable_ecommerce_toolkit').
		 * @return array Presets for the given toolkit (may be empty).
		 */
		public static function get_presets_for_toolkit( $toolkit_key ) {
			$all = self::get_presets();
			return array_filter(
				$all,
				function ( $preset ) use ( $toolkit_key ) {
					return isset( $preset['toolkit'] ) && $preset['toolkit'] === $toolkit_key;
				}
			);
		}

		/**
		 * Get a single preset by its slug.
		 *
		 * @since 1.0.0
		 * @param string $slug Preset slug.
		 * @return array|null Preset data or null if not found.
		 */
		public static function get_preset( $slug ) {
			$all = self::get_presets();
			return isset( $all[ $slug ] ) ? $all[ $slug ] : null;
		}

		/**
		 * Get a preset formatted as schedule data ready for create_schedule().
		 *
		 * The returned array can be passed directly to
		 * WP_MCP_AI_Pro_Schedule_Manager::create_schedule().
		 * Presets are created in a disabled state so the admin can review
		 * before activation.
		 *
		 * @since 1.0.0
		 * @param string $slug Preset slug.
		 * @return array|null Schedule data array or null if preset not found.
		 */
		public static function get_preset_as_schedule_data( $slug ) {
			$preset = self::get_preset( $slug );
			if ( null === $preset ) {
				return null;
			}

			// Build schedule data compatible with create_schedule().
			return array(
				'name'              => $preset['name'],
				'description'       => $preset['description'],
				'schedule_type'     => $preset['schedule_type'],
				'schedule'          => $preset['schedule'],
				'timestamp'         => time() + 120,
				'enabled'           => false,
				'priority'          => $preset['priority'],
				'tags'              => $preset['tags'],
				'timeout'           => $preset['timeout'],
				'notify_on_failure' => $preset['notify_on_failure'],
				'max_retries'       => $preset['max_retries'],
				'retry_delay'       => $preset['retry_delay'],
				'workflow_steps'    => $preset['workflow_steps'],
			);
		}

		// ---------------------------------------------------------------------
		// Per-toolkit preset definitions
		// ---------------------------------------------------------------------


	/**
	 * Get E-commerce toolkit presets.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private static function get_ecommerce_presets() {
		return array(
			'ecom_daily_low_stock_alerts' => array(
				'name'              => __( 'Daily Low Stock Alerts', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Checks inventory levels and generates a report of products running low on stock.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📦',
				'toolkit'           => 'enable_ecommerce_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'ecommerce', 'inventory' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'low_stock_alert_automation',
						'arguments' => array(),
						'label'     => __( 'Check for low-stock products', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'export_products_report',
						'arguments' => array( 'format' => 'summary' ),
						'label'     => __( 'Export low-stock report', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'ecom_weekly_sales_report' => array(
				'name'              => __( 'Weekly Sales Performance Report', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Compiles order analytics and generates a comprehensive sales performance dashboard.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📊',
				'toolkit'           => 'enable_ecommerce_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'ecommerce', 'analytics' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'get_order_analytics',
						'arguments' => array( 'period' => 'last_7_days' ),
						'label'     => __( 'Pull order analytics for the week', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'sales_performance_dashboard',
						'arguments' => array(),
						'label'     => __( 'Generate sales dashboard', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'ecom_abandoned_cart_recovery' => array(
				'name'              => __( 'Abandoned Cart Recovery', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Processes abandoned carts and generates upsell recommendations every 6 hours.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🛒',
				'toolkit'           => 'enable_ecommerce_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_every_6_hours',
				'tags'              => array( 'preset', 'ecommerce', 'recovery' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'abandoned_cart_recovery',
						'arguments' => array(),
						'label'     => __( 'Process abandoned carts', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'upsell_recommendations',
						'arguments' => array(),
						'label'     => __( 'Generate upsell offers for recovered carts', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'ecom_monthly_customer_segmentation' => array(
				'name'              => __( 'Monthly Customer Segmentation', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Segments customers by behavior and calculates lifetime value for each segment.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '👥',
				'toolkit'           => 'enable_ecommerce_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_monthly',
				'tags'              => array( 'preset', 'ecommerce', 'customers' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'segment_customers',
						'arguments' => array(),
						'label'     => __( 'Segment customers by purchase behavior', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'customer_lifetime_value',
						'arguments' => array(),
						'label'     => __( 'Calculate lifetime value per segment', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'ecom_weekly_inventory_forecast' => array(
				'name'              => __( 'Weekly Inventory Forecast', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Syncs current inventory, generates demand forecasts, and tracks stock movement trends.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📈',
				'toolkit'           => 'enable_ecommerce_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'ecommerce', 'inventory' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'sync_product_inventory',
						'arguments' => array(),
						'label'     => __( 'Sync current inventory levels', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'inventory_forecast',
						'arguments' => array( 'horizon' => '30_days' ),
						'label'     => __( 'Generate demand forecast', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'track_inventory_movement',
						'arguments' => array(),
						'label'     => __( 'Track stock movement trends', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
		);
	}

	/**
	 * Get Social Media toolkit presets.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private static function get_social_media_presets() {
		return array(
			'social_daily_mention_monitoring' => array(
				'name'              => __( 'Daily Mention Monitoring', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Monitors brand mentions across platforms and auto-moderates flagged comments.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '👁️',
				'toolkit'           => 'enable_social_media_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'social-media', 'monitoring' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'monitor_mentions_replies',
						'arguments' => array(),
						'label'     => __( 'Scan platforms for brand mentions', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'moderate_comments',
						'arguments' => array( 'action' => 'flag' ),
						'label'     => __( 'Auto-moderate flagged comments', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'social_weekly_competitor_analysis' => array(
				'name'              => __( 'Weekly Competitor Analysis', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Analyzes competitor social presence and identifies trending topics in your niche.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🔍',
				'toolkit'           => 'enable_social_media_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'social-media', 'competitors' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'competitor_analysis',
						'arguments' => array(),
						'label'     => __( 'Analyze competitor social accounts', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'social_listening_trends',
						'arguments' => array(),
						'label'     => __( 'Identify trending topics in niche', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'social_daily_content_calendar' => array(
				'name'              => __( 'Daily Content Calendar Refresh', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Generates fresh post ideas and updates the content calendar with new suggestions.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📅',
				'toolkit'           => 'enable_social_media_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'social-media', 'content' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'generate_post_ideas',
						'arguments' => array( 'count' => '5' ),
						'label'     => __( 'Generate fresh post ideas', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'create_content_calendar',
						'arguments' => array(),
						'label'     => __( 'Update content calendar with new ideas', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'social_weekly_hashtag_tracking' => array(
				'name'              => __( 'Weekly Hashtag Performance', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Tracks hashtag performance and compiles cross-platform analytics summary.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '#️⃣',
				'toolkit'           => 'enable_social_media_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'social-media', 'analytics' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'track_hashtag_performance',
						'arguments' => array(),
						'label'     => __( 'Analyze hashtag reach and engagement', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'get_cross_platform_analytics',
						'arguments' => array(),
						'label'     => __( 'Compile cross-platform analytics', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'social_monthly_influencer_discovery' => array(
				'name'              => __( 'Monthly Influencer Discovery', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Identifies potential influencer partners and benchmarks against competitor collaborations.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '⭐',
				'toolkit'           => 'enable_social_media_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_monthly',
				'tags'              => array( 'preset', 'social-media', 'influencers' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'influencer_identification',
						'arguments' => array(),
						'label'     => __( 'Discover potential influencer partners', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'competitor_analysis',
						'arguments' => array( 'focus' => 'partnerships' ),
						'label'     => __( 'Benchmark competitor collaborations', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
		);
	}

	/**
	 * Get Advanced Analytics toolkit presets.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private static function get_analytics_presets() {
		return array(
			'analytics_weekly_executive_dashboard' => array(
				'name'              => __( 'Weekly Executive Dashboard', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Collects key metrics and generates an executive-level analytics dashboard.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📊',
				'toolkit'           => 'enable_advanced_analytics_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'analytics', 'executive' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'collect_custom_metrics',
						'arguments' => array(),
						'label'     => __( 'Collect key business metrics', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'generate_executive_dashboard',
						'arguments' => array(),
						'label'     => __( 'Generate executive dashboard', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'analytics_daily_metrics_collection' => array(
				'name'              => __( 'Daily Metrics Collection', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Collects custom metrics daily and syncs them to the data warehouse.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📥',
				'toolkit'           => 'enable_advanced_analytics_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'analytics', 'data' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'collect_custom_metrics',
						'arguments' => array(),
						'label'     => __( 'Collect daily metrics', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'data_warehouse_sync',
						'arguments' => array(),
						'label'     => __( 'Sync metrics to data warehouse', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'analytics_monthly_revenue_forecast' => array(
				'name'              => __( 'Monthly Revenue Forecast', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Generates revenue projections and creates a detailed forecast report.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '💰',
				'toolkit'           => 'enable_advanced_analytics_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_monthly',
				'tags'              => array( 'preset', 'analytics', 'revenue' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'revenue_forecast',
						'arguments' => array(),
						'label'     => __( 'Generate revenue projections', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'create_custom_report',
						'arguments' => array( 'type' => 'revenue_forecast' ),
						'label'     => __( 'Create forecast report', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'analytics_weekly_funnel_analysis' => array(
				'name'              => __( 'Weekly Funnel Analysis', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Analyzes conversion funnels and models multi-touch attribution.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🔄',
				'toolkit'           => 'enable_advanced_analytics_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'analytics', 'conversion' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'funnel_analysis',
						'arguments' => array(),
						'label'     => __( 'Analyze conversion funnels', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'attribution_modeling',
						'arguments' => array(),
						'label'     => __( 'Model multi-touch attribution', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'analytics_monthly_churn_prediction' => array(
				'name'              => __( 'Monthly Churn Prediction', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Predicts at-risk customers, segments them with ML, and creates an actionable report.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '⚠️',
				'toolkit'           => 'enable_advanced_analytics_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_monthly',
				'tags'              => array( 'preset', 'analytics', 'churn' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'churn_prediction',
						'arguments' => array(),
						'label'     => __( 'Predict at-risk customers', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'customer_segmentation_ml',
						'arguments' => array(),
						'label'     => __( 'Segment at-risk customers', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'create_custom_report',
						'arguments' => array( 'type' => 'churn_analysis' ),
						'label'     => __( 'Create churn analysis report', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
		);
	}

	/**
	 * Get Financial Planning toolkit presets.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private static function get_financial_presets() {
		return array(
			'finance_daily_market_digest' => array(
				'name'              => __( 'Daily Market News Digest', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Aggregates financial news and analyzes market sentiment for the day.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📰',
				'toolkit'           => 'enable_financial_planner_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'finance', 'market' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'financial_news_aggregator',
						'arguments' => array(),
						'label'     => __( 'Aggregate financial news', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'market_sentiment_analyzer',
						'arguments' => array(),
						'label'     => __( 'Analyze market sentiment', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'finance_weekly_portfolio_review' => array(
				'name'              => __( 'Weekly Portfolio Review', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Fetches latest stock data, visualizes portfolio allocations, and checks rebalancing needs.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '💼',
				'toolkit'           => 'enable_financial_planner_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'finance', 'portfolio' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'stock_data_fetcher',
						'arguments' => array(),
						'label'     => __( 'Fetch latest stock data', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'portfolio_visualizer',
						'arguments' => array(),
						'label'     => __( 'Visualize portfolio allocations', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'rebalancing_analyzer',
						'arguments' => array(),
						'label'     => __( 'Check rebalancing needs', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'finance_monthly_health_check' => array(
				'name'              => __( 'Monthly Financial Health Check', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Scores overall financial health, analyzes cash flow, and generates a comprehensive report.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🏥',
				'toolkit'           => 'enable_financial_planner_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_monthly',
				'tags'              => array( 'preset', 'finance', 'health' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'financial_health_score',
						'arguments' => array(),
						'label'     => __( 'Score overall financial health', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'cash_flow_analyzer',
						'arguments' => array(),
						'label'     => __( 'Analyze cash flow patterns', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'financial_report_generator',
						'arguments' => array(),
						'label'     => __( 'Generate comprehensive financial report', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'finance_daily_investment_signals' => array(
				'name'              => __( 'Daily Investment Signals', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Fetches market data, tracks investment signals, and analyzes forecast indicators.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📡',
				'toolkit'           => 'enable_financial_planner_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'finance', 'signals' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'stock_data_fetcher',
						'arguments' => array(),
						'label'     => __( 'Fetch latest market data', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'investment_signal_tracker',
						'arguments' => array(),
						'label'     => __( 'Track investment signals', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'market_forecast_analyzer',
						'arguments' => array(),
						'label'     => __( 'Analyze forecast indicators', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'finance_weekly_forecast_report' => array(
				'name'              => __( 'Weekly Market Forecast Report', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Generates market forecasts and compiles them into a formatted report.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🔮',
				'toolkit'           => 'enable_financial_planner_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'finance', 'forecast' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'market_forecast_analyzer',
						'arguments' => array(),
						'label'     => __( 'Generate market forecasts', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'financial_report_generator',
						'arguments' => array( 'type' => 'forecast' ),
						'label'     => __( 'Compile forecast report', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
		);
	}

	/**
	 * Get CRM toolkit presets.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private static function get_crm_presets() {
		return array(
			'crm_daily_lead_scoring' => array(
				'name'              => __( 'Daily Lead Scoring', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Searches for new leads via email and updates CRM contact records with scores.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🎯',
				'toolkit'           => 'enable_crm_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'crm', 'leads' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'crm_email_search_leads',
						'arguments' => array(),
						'label'     => __( 'Search for new leads', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'manage_crm_contact',
						'arguments' => array( 'action' => 'update_scores' ),
						'label'     => __( 'Update contact lead scores', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'crm_weekly_company_research' => array(
				'name'              => __( 'Weekly Company Research', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Lists active companies and conducts AI-powered research on each.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🏢',
				'toolkit'           => 'enable_crm_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'crm', 'research' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'get_companies',
						'arguments' => array(),
						'label'     => __( 'List active company records', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'research_company',
						'arguments' => array(),
						'label'     => __( 'Research companies with AI', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'crm_daily_correspondence_review' => array(
				'name'              => __( 'Daily Correspondence Review', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Searches recent email correspondence and updates contact records with interaction data.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '✉️',
				'toolkit'           => 'enable_crm_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'crm', 'email' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'crm_email_search_correspondence',
						'arguments' => array(),
						'label'     => __( 'Search recent correspondence', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'manage_crm_contact',
						'arguments' => array( 'action' => 'log_interaction' ),
						'label'     => __( 'Update contact interaction log', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'crm_monthly_accounting_reconciliation' => array(
				'name'              => __( 'Monthly Accounting Reconciliation', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Searches accounting emails for invoices/payments and reconciles with contact records.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '💰',
				'toolkit'           => 'enable_crm_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_monthly',
				'tags'              => array( 'preset', 'crm', 'accounting' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'crm_email_search_accounting',
						'arguments' => array(),
						'label'     => __( 'Search accounting correspondence', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'manage_crm_contact',
						'arguments' => array( 'action' => 'reconcile' ),
						'label'     => __( 'Reconcile with contact records', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'crm_weekly_pipeline_review' => array(
				'name'              => __( 'Weekly Pipeline Review', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Reviews the sales pipeline by listing companies, scoring leads, and updating contacts.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📋',
				'toolkit'           => 'enable_crm_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'crm', 'pipeline' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'get_companies',
						'arguments' => array( 'status' => 'active' ),
						'label'     => __( 'List active pipeline companies', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'crm_email_search_leads',
						'arguments' => array(),
						'label'     => __( 'Score pipeline leads', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'manage_crm_contact',
						'arguments' => array( 'action' => 'update_pipeline' ),
						'label'     => __( 'Update pipeline status', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
		);
	}

	/**
	 * Get Document Generation toolkit presets.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private static function get_document_presets() {
		return array(
			'doc_weekly_report_generation' => array(
				'name'              => __( 'Weekly Report Generation', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Generates a weekly data export in Excel format and converts it to PDF.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📄',
				'toolkit'           => 'enable_document_generation_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'documents', 'reports' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'generate_excel',
						'arguments' => array( 'template' => 'weekly_report' ),
						'label'     => __( 'Generate weekly Excel report', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'generate_pdf',
						'arguments' => array( 'source' => 'excel' ),
						'label'     => __( 'Convert report to PDF', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'doc_monthly_invoice_batch' => array(
				'name'              => __( 'Monthly Invoice Batch', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Generates invoices as PDFs and applies official watermarks for distribution.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🧾',
				'toolkit'           => 'enable_document_generation_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_monthly',
				'tags'              => array( 'preset', 'documents', 'invoices' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'generate_pdf',
						'arguments' => array( 'template' => 'invoice' ),
						'label'     => __( 'Generate invoice PDFs', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'add_watermark_to_pdf',
						'arguments' => array( 'text' => 'OFFICIAL' ),
						'label'     => __( 'Apply official watermark', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'doc_daily_ocr_processing' => array(
				'name'              => __( 'Daily OCR Processing Queue', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Processes uploaded documents with OCR and extracts searchable text.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🔍',
				'toolkit'           => 'enable_document_generation_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'documents', 'ocr' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'pro_document_ocr',
						'arguments' => array(),
						'label'     => __( 'Run OCR on queued documents', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'extract_pdf_text',
						'arguments' => array(),
						'label'     => __( 'Extract searchable text', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'doc_weekly_data_export' => array(
				'name'              => __( 'Weekly Data Export', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Exports site data to Excel spreadsheets for archival and analysis.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📊',
				'toolkit'           => 'enable_document_generation_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'documents', 'export' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'excel_data_export',
						'arguments' => array(),
						'label'     => __( 'Export data to Excel format', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'generate_excel',
						'arguments' => array( 'format' => 'xlsx' ),
						'label'     => __( 'Generate formatted spreadsheet', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'doc_monthly_archive' => array(
				'name'              => __( 'Monthly Document Archive', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Merges monthly documents into a single PDF archive with watermarks.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🗄️',
				'toolkit'           => 'enable_document_generation_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_monthly',
				'tags'              => array( 'preset', 'documents', 'archive' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'merge_pdfs',
						'arguments' => array(),
						'label'     => __( 'Merge monthly documents', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'add_watermark_to_pdf',
						'arguments' => array( 'text' => 'ARCHIVE' ),
						'label'     => __( 'Apply archive watermark', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
		);
	}

	/**
	 * Get Image Production toolkit presets.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private static function get_image_presets() {
		return array(
			'img_daily_optimization' => array(
				'name'              => __( 'Daily Image Optimization', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Batch processes new images with compression and web optimization.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🖼️',
				'toolkit'           => 'enable_image_production_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'images', 'optimization' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'batch_process_images',
						'arguments' => array(),
						'label'     => __( 'Find unoptimized images', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'compress_image',
						'arguments' => array( 'quality' => '85' ),
						'label'     => __( 'Compress images', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'optimize_for_web',
						'arguments' => array(),
						'label'     => __( 'Optimize for web delivery', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'img_weekly_responsive_generation' => array(
				'name'              => __( 'Weekly Responsive Image Generation', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Generates responsive image variants for all new media uploads.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📱',
				'toolkit'           => 'enable_image_production_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'images', 'responsive' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'batch_process_images',
						'arguments' => array( 'filter' => 'new' ),
						'label'     => __( 'Identify new images', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'generate_responsive_images',
						'arguments' => array(),
						'label'     => __( 'Generate responsive variants', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'img_daily_background_removal' => array(
				'name'              => __( 'Daily Background Removal Queue', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Processes product images by removing backgrounds and smart-resizing.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '✂️',
				'toolkit'           => 'enable_image_production_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'images', 'editing' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'remove_image_background',
						'arguments' => array(),
						'label'     => __( 'Remove image backgrounds', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'resize_image_smart',
						'arguments' => array(),
						'label'     => __( 'Smart-resize processed images', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'img_weekly_quality_enhancement' => array(
				'name'              => __( 'Weekly Quality Enhancement', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Enhances low-quality images and upscales them using AI.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '✨',
				'toolkit'           => 'enable_image_production_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'images', 'enhancement' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'enhance_image_quality',
						'arguments' => array(),
						'label'     => __( 'Enhance image quality', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'upscale_image_ai',
						'arguments' => array(),
						'label'     => __( 'AI-upscale enhanced images', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'img_monthly_format_conversion' => array(
				'name'              => __( 'Monthly Format Conversion', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Converts legacy image formats, compresses, and optimizes for modern browsers.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🔄',
				'toolkit'           => 'enable_image_production_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_monthly',
				'tags'              => array( 'preset', 'images', 'conversion' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'convert_image_format',
						'arguments' => array( 'target' => 'webp' ),
						'label'     => __( 'Convert to modern format', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'compress_image',
						'arguments' => array( 'quality' => '85' ),
						'label'     => __( 'Compress converted images', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'optimize_for_web',
						'arguments' => array(),
						'label'     => __( 'Final web optimization', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
		);
	}

	/**
	 * Get Multilingual toolkit presets.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private static function get_multilingual_presets() {
		return array(
			'ml_daily_translation_queue' => array(
				'name'              => __( 'Daily Translation Queue', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Finds untranslated content and auto-translates it into configured languages.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🌐',
				'toolkit'           => 'enable_multilingual_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'multilingual', 'translation' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'find_untranslated_strings',
						'arguments' => array(),
						'label'     => __( 'Find untranslated content', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'auto_translate_content',
						'arguments' => array(),
						'label'     => __( 'Auto-translate new content', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'ml_weekly_untranslated_scan' => array(
				'name'              => __( 'Weekly Untranslated Content Scan', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Scans for untranslated strings and detects content language mismatches.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🔎',
				'toolkit'           => 'enable_multilingual_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'multilingual', 'audit' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'find_untranslated_strings',
						'arguments' => array(),
						'label'     => __( 'Scan for untranslated strings', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'detect_content_language',
						'arguments' => array(),
						'label'     => __( 'Detect language mismatches', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'ml_monthly_quality_review' => array(
				'name'              => __( 'Monthly Translation Quality Review', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Reviews translation quality and audits multilingual SEO across all languages.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '✅',
				'toolkit'           => 'enable_multilingual_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_monthly',
				'tags'              => array( 'preset', 'multilingual', 'quality' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'translation_quality_check',
						'arguments' => array(),
						'label'     => __( 'Check translation quality', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'multilingual_seo_audit',
						'arguments' => array(),
						'label'     => __( 'Audit multilingual SEO', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'ml_weekly_seo_audit' => array(
				'name'              => __( 'Weekly Multilingual SEO Audit', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Audits multilingual SEO tags and optimizes RTL content where needed.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🔍',
				'toolkit'           => 'enable_multilingual_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'multilingual', 'seo' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'multilingual_seo_audit',
						'arguments' => array(),
						'label'     => __( 'Audit multilingual SEO', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'rtl_content_optimization',
						'arguments' => array(),
						'label'     => __( 'Optimize RTL content', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'ml_monthly_woocommerce_sync' => array(
				'name'              => __( 'Monthly WooCommerce Translation Sync', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Translates new WooCommerce products and verifies translation quality.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '��️',
				'toolkit'           => 'enable_multilingual_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_monthly',
				'tags'              => array( 'preset', 'multilingual', 'woocommerce' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'translate_woocommerce_products',
						'arguments' => array(),
						'label'     => __( 'Translate new products', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'translation_quality_check',
						'arguments' => array(),
						'label'     => __( 'Verify product translation quality', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
		);
	}

	/**
	 * Get Video Production toolkit presets.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private static function get_video_presets() {
		return array(
			'video_daily_compression' => array(
				'name'              => __( 'Daily Video Compression', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Compresses new video uploads and optimizes them for web streaming.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🎬',
				'toolkit'           => 'enable_video_production_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'video', 'compression' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'compress_video',
						'arguments' => array(),
						'label'     => __( 'Compress new video uploads', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'optimize_for_platform',
						'arguments' => array( 'platform' => 'web' ),
						'label'     => __( 'Optimize for web streaming', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'video_weekly_caption_generation' => array(
				'name'              => __( 'Weekly Caption Generation', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Generates captions for uncaptioned videos and optimizes for platform delivery.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '💬',
				'toolkit'           => 'enable_video_production_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'video', 'captions' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'generate_video_captions',
						'arguments' => array(),
						'label'     => __( 'Generate captions for videos', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'optimize_for_platform',
						'arguments' => array( 'platform' => 'all' ),
						'label'     => __( 'Optimize captioned videos', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'video_daily_thumbnail_generation' => array(
				'name'              => __( 'Daily Thumbnail Generation', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Generates thumbnails for new videos and optimizes for social platform specs.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🖼️',
				'toolkit'           => 'enable_video_production_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'video', 'thumbnails' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'generate_video_thumbnails',
						'arguments' => array(),
						'label'     => __( 'Generate video thumbnails', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'optimize_for_platform',
						'arguments' => array( 'platform' => 'social' ),
						'label'     => __( 'Optimize thumbnails for social', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'video_weekly_platform_optimization' => array(
				'name'              => __( 'Weekly Platform Optimization', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Resizes and optimizes videos for different platform specifications.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📐',
				'toolkit'           => 'enable_video_production_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'video', 'optimization' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'resize_video_resolution',
						'arguments' => array(),
						'label'     => __( 'Resize videos for platforms', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'optimize_for_platform',
						'arguments' => array( 'platform' => 'all' ),
						'label'     => __( 'Optimize for all platforms', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'video_monthly_metadata_extraction' => array(
				'name'              => __( 'Monthly Metadata Extraction', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Extracts metadata from the video library and generates captions for uncaptioned content.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🏷️',
				'toolkit'           => 'enable_video_production_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_monthly',
				'tags'              => array( 'preset', 'video', 'metadata' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'extract_video_metadata',
						'arguments' => array(),
						'label'     => __( 'Extract video metadata', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'generate_video_captions',
						'arguments' => array(),
						'label'     => __( 'Generate captions from metadata', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
		);
	}

	/**
	 * Get Architectural Design toolkit presets.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private static function get_architecture_presets() {
		return array(
			'arch_weekly_compliance_check' => array(
				'name'              => __( 'Weekly Compliance Check', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Checks building code compliance and exports updated documentation.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '✅',
				'toolkit'           => 'enable_architectural_design_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'architecture', 'compliance' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'check_building_code_compliance',
						'arguments' => array(),
						'label'     => __( 'Check building code compliance', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'export_architectural_documents',
						'arguments' => array(),
						'label'     => __( 'Export compliance documents', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'arch_monthly_cost_estimation' => array(
				'name'              => __( 'Monthly Cost Estimation', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Updates construction cost estimates and refreshes material schedules.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '💵',
				'toolkit'           => 'enable_architectural_design_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_monthly',
				'tags'              => array( 'preset', 'architecture', 'cost' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'estimate_construction_cost',
						'arguments' => array(),
						'label'     => __( 'Update cost estimates', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'generate_material_schedule',
						'arguments' => array(),
						'label'     => __( 'Refresh material schedules', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'arch_weekly_material_schedule' => array(
				'name'              => __( 'Weekly Material Schedule Refresh', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Refreshes material schedules with current pricing and availability.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📋',
				'toolkit'           => 'enable_architectural_design_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'architecture', 'materials' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'generate_material_schedule',
						'arguments' => array(),
						'label'     => __( 'Generate updated material schedule', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'estimate_construction_cost',
						'arguments' => array(),
						'label'     => __( 'Recalculate costs with new prices', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'arch_monthly_sustainability_audit' => array(
				'name'              => __( 'Monthly Sustainability Audit', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Calculates sustainability metrics and exports environmental compliance reports.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🌿',
				'toolkit'           => 'enable_architectural_design_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_monthly',
				'tags'              => array( 'preset', 'architecture', 'sustainability' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'calculate_sustainability_metrics',
						'arguments' => array(),
						'label'     => __( 'Calculate sustainability scores', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'export_architectural_documents',
						'arguments' => array( 'type' => 'sustainability' ),
						'label'     => __( 'Export sustainability report', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'arch_weekly_timeline_update' => array(
				'name'              => __( 'Weekly Timeline Update', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Updates construction timelines and validates structural feasibility.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📅',
				'toolkit'           => 'enable_architectural_design_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'architecture', 'timeline' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'generate_construction_timeline',
						'arguments' => array(),
						'label'     => __( 'Update construction timeline', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'analyze_structural_feasibility',
						'arguments' => array(),
						'label'     => __( 'Validate structural feasibility', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
		);
	}

	/**
	 * Get DJ Management toolkit presets.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private static function get_dj_presets() {
		return array(
			'dj_daily_equipment_check' => array(
				'name'              => __( 'Daily Equipment Check', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Tracks equipment maintenance status and generates an inventory report.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🎧',
				'toolkit'           => 'enable_dj_management_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'dj', 'equipment' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'track_equipment_maintenance',
						'arguments' => array(),
						'label'     => __( 'Check equipment maintenance status', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'equipment_inventory_report',
						'arguments' => array(),
						'label'     => __( 'Generate equipment inventory report', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'dj_weekly_payment_tracking' => array(
				'name'              => __( 'Weekly Payment Tracking', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Reviews outstanding event payments and sends invoices for overdue balances.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '💳',
				'toolkit'           => 'enable_dj_management_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'dj', 'payments' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'track_event_payments',
						'arguments' => array(),
						'label'     => __( 'Review outstanding payments', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'send_client_invoice',
						'arguments' => array(),
						'label'     => __( 'Send invoices for overdue balances', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'dj_daily_client_followup' => array(
				'name'              => __( 'Daily Client Follow-up', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Reviews client communications and generates upcoming event timelines.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📞',
				'toolkit'           => 'enable_dj_management_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'dj', 'clients' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'client_communication_log',
						'arguments' => array(),
						'label'     => __( 'Review client communications', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'generate_event_timeline',
						'arguments' => array(),
						'label'     => __( 'Generate upcoming event timelines', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'dj_weekly_music_analysis' => array(
				'name'              => __( 'Weekly Music Library Analysis', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Organizes the music library and analyzes BPM for mixing compatibility.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🎵',
				'toolkit'           => 'enable_dj_management_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'dj', 'music' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'manage_music_library',
						'arguments' => array(),
						'label'     => __( 'Organize music library', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'analyze_track_bpm',
						'arguments' => array(),
						'label'     => __( 'Analyze BPM for mixing', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'dj_monthly_inventory_report' => array(
				'name'              => __( 'Monthly Inventory Report', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Generates a comprehensive equipment inventory and maintenance report.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📦',
				'toolkit'           => 'enable_dj_management_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_monthly',
				'tags'              => array( 'preset', 'dj', 'inventory' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'equipment_inventory_report',
						'arguments' => array(),
						'label'     => __( 'Full equipment inventory audit', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'track_equipment_maintenance',
						'arguments' => array(),
						'label'     => __( 'Update maintenance schedules', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
		);
	}

	/**
	 * Get AI Tool Builder toolkit presets.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private static function get_tool_builder_presets() {
		return array(
			'toolbuilder_weekly_security' => array(
				'name'              => __( 'Weekly Security Analysis', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Analyzes custom tools for security vulnerabilities and compliance issues.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🔒',
				'toolkit'           => 'enable_ai_tool_builder_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'tool-builder', 'security' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'analyze_tool_security',
						'arguments' => array(),
						'label'     => __( 'Analyze tool security', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'check_tool_compliance',
						'arguments' => array(),
						'label'     => __( 'Check compliance standards', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'toolbuilder_daily_compliance' => array(
				'name'              => __( 'Daily Compliance Check', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Validates tool compliance and schema integrity daily.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '✅',
				'toolkit'           => 'enable_ai_tool_builder_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'tool-builder', 'compliance' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'check_tool_compliance',
						'arguments' => array(),
						'label'     => __( 'Check tool compliance', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'validate_tool_schema',
						'arguments' => array(),
						'label'     => __( 'Validate tool schemas', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'toolbuilder_weekly_benchmarks' => array(
				'name'              => __( 'Weekly Performance Benchmarks', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Benchmarks tool performance and generates updated documentation.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '⚡',
				'toolkit'           => 'enable_ai_tool_builder_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'tool-builder', 'performance' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'benchmark_tool_performance',
						'arguments' => array(),
						'label'     => __( 'Run performance benchmarks', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'generate_tool_documentation',
						'arguments' => array(),
						'label'     => __( 'Update tool documentation', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'toolbuilder_monthly_documentation' => array(
				'name'              => __( 'Monthly Documentation Generation', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Generates comprehensive documentation for all custom tools.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📚',
				'toolkit'           => 'enable_ai_tool_builder_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_monthly',
				'tags'              => array( 'preset', 'tool-builder', 'docs' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'generate_tool_documentation',
						'arguments' => array(),
						'label'     => __( 'Generate tool documentation', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'validate_tool_schema',
						'arguments' => array(),
						'label'     => __( 'Validate updated schemas', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'toolbuilder_weekly_schema_validation' => array(
				'name'              => __( 'Weekly Schema Validation', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Validates all tool schemas and generates test cases for failures.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🧪',
				'toolkit'           => 'enable_ai_tool_builder_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'tool-builder', 'testing' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'validate_tool_schema',
						'arguments' => array(),
						'label'     => __( 'Validate all tool schemas', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'generate_tool_tests',
						'arguments' => array(),
						'label'     => __( 'Generate test cases', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
		);
	}

	/**
	 * Get Site Creator toolkit presets.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private static function get_site_creator_presets() {
		return array(
			'site_weekly_competitor_analysis' => array(
				'name'              => __( 'Weekly Competitor Site Analysis', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Analyzes competitor websites and researches best practices for improvements.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🔍',
				'toolkit'           => 'enable_site_creator_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'site-creator', 'competitors' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'analyze_competitor_sites',
						'arguments' => array(),
						'label'     => __( 'Analyze competitor websites', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'research_site_best_practices',
						'arguments' => array(),
						'label'     => __( 'Research improvement best practices', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'site_monthly_best_practices' => array(
				'name'              => __( 'Monthly Best Practices Research', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Researches industry best practices and suggests template improvements.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📖',
				'toolkit'           => 'enable_site_creator_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_monthly',
				'tags'              => array( 'preset', 'site-creator', 'research' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'research_site_best_practices',
						'arguments' => array(),
						'label'     => __( 'Research best practices', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'suggest_template_patterns',
						'arguments' => array(),
						'label'     => __( 'Suggest template improvements', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'site_weekly_template_versioning' => array(
				'name'              => __( 'Weekly Template Versioning', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Manages template versions and exports updated template kits.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📦',
				'toolkit'           => 'enable_site_creator_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'site-creator', 'templates' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'manage_template_versions',
						'arguments' => array(),
						'label'     => __( 'Check template versions', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'export_template_kit',
						'arguments' => array(),
						'label'     => __( 'Export updated template kit', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'site_daily_dev_automation' => array(
				'name'              => __( 'Daily Development Automation', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Automates development workflows and keeps template versions in sync.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '⚙️',
				'toolkit'           => 'enable_site_creator_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'site-creator', 'automation' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'automate_development_workflow',
						'arguments' => array(),
						'label'     => __( 'Run development automation', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'manage_template_versions',
						'arguments' => array(),
						'label'     => __( 'Sync template versions', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'site_monthly_template_export' => array(
				'name'              => __( 'Monthly Template Kit Export', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Exports complete template kits and generates site deployment plans.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📤',
				'toolkit'           => 'enable_site_creator_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_monthly',
				'tags'              => array( 'preset', 'site-creator', 'export' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'export_template_kit',
						'arguments' => array(),
						'label'     => __( 'Export complete template kit', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'generate_site_plan',
						'arguments' => array(),
						'label'     => __( 'Generate site deployment plan', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
		);
	}

	/**
	 * Get Health & Wellness toolkit presets.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private static function get_health_presets() {
		return array(
			'health_daily_checkin' => array(
				'name'              => __( 'Daily Wellness Check-in', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Tracks daily health metrics and sends wellness reminders to participants.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '💚',
				'toolkit'           => 'enable_health_wellness_management',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'health', 'tracking' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'track_health_metrics',
						'arguments' => array(),
						'label'     => __( 'Track daily health metrics', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'send_health_reminder',
						'arguments' => array(),
						'label'     => __( 'Send wellness reminders', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'health_weekly_metrics_report' => array(
				'name'              => __( 'Weekly Health Metrics Report', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Compiles weekly health metrics into a comprehensive wellness report.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📊',
				'toolkit'           => 'enable_health_wellness_management',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'health', 'reports' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'track_health_metrics',
						'arguments' => array( 'period' => 'weekly' ),
						'label'     => __( 'Compile weekly metrics', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'generate_wellness_report',
						'arguments' => array(),
						'label'     => __( 'Generate wellness report', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'health_monthly_program_review' => array(
				'name'              => __( 'Monthly Wellness Program Review', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Reviews wellness program effectiveness and generates a progress report.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📋',
				'toolkit'           => 'enable_health_wellness_management',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_monthly',
				'tags'              => array( 'preset', 'health', 'programs' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'review_wellness_program',
						'arguments' => array(),
						'label'     => __( 'Review program effectiveness', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'generate_wellness_report',
						'arguments' => array( 'type' => 'program_review' ),
						'label'     => __( 'Generate program report', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'health_daily_appointment_reminders' => array(
				'name'              => __( 'Daily Appointment Reminders', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Checks upcoming appointments and sends reminders to participants.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '⏰',
				'toolkit'           => 'enable_health_wellness_management',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'health', 'appointments' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'check_appointment_schedule',
						'arguments' => array(),
						'label'     => __( 'Check upcoming appointments', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'send_health_reminder',
						'arguments' => array( 'type' => 'appointment' ),
						'label'     => __( 'Send appointment reminders', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'health_weekly_progress_tracking' => array(
				'name'              => __( 'Weekly Progress Tracking', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Tracks health progress metrics and reviews wellness program milestones.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📈',
				'toolkit'           => 'enable_health_wellness_management',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'health', 'progress' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'track_health_metrics',
						'arguments' => array( 'type' => 'progress' ),
						'label'     => __( 'Track progress metrics', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'review_wellness_program',
						'arguments' => array(),
						'label'     => __( 'Review program milestones', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
		);
	}

	/**
	 * Get Regulatory Registration toolkit presets.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private static function get_regulatory_presets() {
		return array(
			'reg_daily_expiry_check' => array(
				'name'              => __( 'Daily Document Expiry Check', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Checks for expiring documents and sends alerts for upcoming renewals.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📅',
				'toolkit'           => 'enable_regulatory_registration_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'regulatory', 'expiry' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'check_document_expiry',
						'arguments' => array(),
						'label'     => __( 'Check for expiring documents', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'send_expiry_alerts',
						'arguments' => array(),
						'label'     => __( 'Send expiry alerts', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'reg_weekly_compliance_review' => array(
				'name'              => __( 'Weekly Compliance Review', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Reviews product compliance status and generates a compliance report.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '✅',
				'toolkit'           => 'enable_regulatory_registration_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'regulatory', 'compliance' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'check_product_compliance',
						'arguments' => array(),
						'label'     => __( 'Check product compliance', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'generate_compliance_report',
						'arguments' => array(),
						'label'     => __( 'Generate compliance report', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'reg_monthly_renewal_alerts' => array(
				'name'              => __( 'Monthly Registration Renewal Alerts', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Lists expiring registrations and sends renewal notifications.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🔔',
				'toolkit'           => 'enable_regulatory_registration_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_monthly',
				'tags'              => array( 'preset', 'regulatory', 'renewals' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'list_expiring_registrations',
						'arguments' => array(),
						'label'     => __( 'List expiring registrations', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'send_expiry_alerts',
						'arguments' => array(),
						'label'     => __( 'Send renewal notifications', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'reg_daily_authority_monitor' => array(
				'name'              => __( 'Daily Authority Status Monitor', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Monitors regulatory authority status and updates pipeline reports.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🏛️',
				'toolkit'           => 'enable_regulatory_registration_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'regulatory', 'authorities' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'check_authority_status',
						'arguments' => array(),
						'label'     => __( 'Check authority status', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'generate_pipeline_report',
						'arguments' => array(),
						'label'     => __( 'Update pipeline report', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'reg_weekly_pipeline_report' => array(
				'name'              => __( 'Weekly Pipeline Report', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Generates a full registration pipeline report with compliance status.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📊',
				'toolkit'           => 'enable_regulatory_registration_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'regulatory', 'pipeline' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'generate_pipeline_report',
						'arguments' => array(),
						'label'     => __( 'Generate pipeline report', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'generate_compliance_report',
						'arguments' => array(),
						'label'     => __( 'Include compliance status', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
		);
	}

	/**
	 * Get Calendar Booking toolkit presets.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private static function get_calendar_presets() {
		return array(
			'cal_daily_sync' => array(
				'name'              => __( 'Daily Calendar Sync', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Syncs appointments between Google Calendar and Outlook Calendar.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🔄',
				'toolkit'           => 'calendar_booking',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'calendar', 'sync' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'sync_google_calendar',
						'arguments' => array(),
						'label'     => __( 'Sync Google Calendar', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'sync_outlook_calendar',
						'arguments' => array(),
						'label'     => __( 'Sync Outlook Calendar', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'cal_hourly_reminders' => array(
				'name'              => __( 'Hourly Appointment Reminders', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Sends reminders for upcoming appointments and checks availability.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '⏰',
				'toolkit'           => 'calendar_booking',
				'schedule_type'     => 'workflow',
				'schedule'          => 'hourly',
				'tags'              => array( 'preset', 'calendar', 'reminders' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'send_appointment_reminder',
						'arguments' => array(),
						'label'     => __( 'Send upcoming appointment reminders', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'check_availability',
						'arguments' => array(),
						'label'     => __( 'Update availability status', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'cal_weekly_optimization' => array(
				'name'              => __( 'Weekly Schedule Optimization', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Optimizes the booking schedule and refreshes available time slots.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '⚡',
				'toolkit'           => 'calendar_booking',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'calendar', 'optimization' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'optimize_schedule',
						'arguments' => array(),
						'label'     => __( 'Optimize booking schedule', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'get_available_slots',
						'arguments' => array(),
						'label'     => __( 'Refresh available slots', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'cal_daily_availability_refresh' => array(
				'name'              => __( 'Daily Availability Refresh', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Refreshes availability data and updates open time slots.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📅',
				'toolkit'           => 'calendar_booking',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'calendar', 'availability' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'check_availability',
						'arguments' => array(),
						'label'     => __( 'Check current availability', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'get_available_slots',
						'arguments' => array(),
						'label'     => __( 'Update available time slots', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'cal_weekly_booking_digest' => array(
				'name'              => __( 'Weekly Booking Digest', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Reviews available slots and optimizes the upcoming week schedule.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📋',
				'toolkit'           => 'calendar_booking',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'calendar', 'digest' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'get_available_slots',
						'arguments' => array( 'period' => 'next_week' ),
						'label'     => __( 'Review next week availability', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'optimize_schedule',
						'arguments' => array(),
						'label'     => __( 'Optimize upcoming week', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
		);
	}

	/**
	 * Get Project Management toolkit presets.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private static function get_project_presets() {
		return array(
			'pm_daily_status_review' => array(
				'name'              => __( 'Daily Project Status Review', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Reviews all active project tasks and updates status records.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📋',
				'toolkit'           => 'enable_project_management',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'project', 'status' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'list_project_tasks',
						'arguments' => array(),
						'label'     => __( 'List active project tasks', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'update_project_status',
						'arguments' => array(),
						'label'     => __( 'Update project status records', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'pm_weekly_milestone_check' => array(
				'name'              => __( 'Weekly Milestone Check', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Checks for overdue tasks and generates a milestone progress report.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🏁',
				'toolkit'           => 'enable_project_management',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'project', 'milestones' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'check_overdue_tasks',
						'arguments' => array(),
						'label'     => __( 'Check for overdue tasks', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'generate_project_report',
						'arguments' => array(),
						'label'     => __( 'Generate milestone report', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'pm_monthly_resource_report' => array(
				'name'              => __( 'Monthly Resource Report', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Calculates team capacity and generates a resource allocation report.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '👥',
				'toolkit'           => 'enable_project_management',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_monthly',
				'tags'              => array( 'preset', 'project', 'resources' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'calculate_team_capacity',
						'arguments' => array(),
						'label'     => __( 'Calculate team capacity', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'generate_project_report',
						'arguments' => array( 'type' => 'resource' ),
						'label'     => __( 'Generate resource report', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'pm_daily_overdue_alerts' => array(
				'name'              => __( 'Daily Overdue Task Alerts', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Flags overdue tasks and updates their status for team visibility.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🚨',
				'toolkit'           => 'enable_project_management',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'project', 'overdue' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'check_overdue_tasks',
						'arguments' => array(),
						'label'     => __( 'Flag overdue tasks', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'update_project_status',
						'arguments' => array( 'flag' => 'overdue' ),
						'label'     => __( 'Update overdue status', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'pm_weekly_capacity_report' => array(
				'name'              => __( 'Weekly Capacity Report', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Reviews team capacity and lists tasks for resource planning.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📊',
				'toolkit'           => 'enable_project_management',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'project', 'capacity' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'calculate_team_capacity',
						'arguments' => array(),
						'label'     => __( 'Review team capacity', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'list_project_tasks',
						'arguments' => array( 'view' => 'capacity' ),
						'label'     => __( 'List tasks for capacity planning', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
		);
	}

	/**
	 * Get ECA Management toolkit presets.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private static function get_eca_presets() {
		return array(
			'eca_daily_enrollment' => array(
				'name'              => __( 'Daily Enrollment Processing', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Processes new enrollments and tracks engagement metrics.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🎓',
				'toolkit'           => 'enable_eca_management',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'eca', 'enrollment' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'process_enrollments',
						'arguments' => array(),
						'label'     => __( 'Process new enrollments', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'track_engagement_metrics',
						'arguments' => array(),
						'label'     => __( 'Track engagement metrics', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'eca_weekly_progress_report' => array(
				'name'              => __( 'Weekly Course Progress Report', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Tracks learner engagement and generates a weekly course progress report.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📊',
				'toolkit'           => 'enable_eca_management',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'eca', 'progress' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'track_engagement_metrics',
						'arguments' => array(),
						'label'     => __( 'Track engagement metrics', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'generate_course_report',
						'arguments' => array(),
						'label'     => __( 'Generate progress report', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'eca_monthly_certification_review' => array(
				'name'              => __( 'Monthly Certification Review', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Reviews expiring certifications and generates a certification status report.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🏆',
				'toolkit'           => 'enable_eca_management',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_monthly',
				'tags'              => array( 'preset', 'eca', 'certifications' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'check_certifications',
						'arguments' => array(),
						'label'     => __( 'Check certification status', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'generate_course_report',
						'arguments' => array( 'type' => 'certification' ),
						'label'     => __( 'Generate certification report', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'eca_daily_assignment_reminders' => array(
				'name'              => __( 'Daily Assignment Reminders', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Sends reminders for pending assignments and updates engagement data.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📝',
				'toolkit'           => 'enable_eca_management',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'eca', 'assignments' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'send_assignment_reminder',
						'arguments' => array(),
						'label'     => __( 'Send assignment reminders', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'track_engagement_metrics',
						'arguments' => array(),
						'label'     => __( 'Update engagement tracking', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'eca_weekly_engagement_metrics' => array(
				'name'              => __( 'Weekly Engagement Metrics', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Compiles weekly engagement data and generates a comprehensive course report.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📈',
				'toolkit'           => 'enable_eca_management',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'eca', 'engagement' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'track_engagement_metrics',
						'arguments' => array( 'period' => 'weekly' ),
						'label'     => __( 'Compile weekly engagement data', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'generate_course_report',
						'arguments' => array(),
						'label'     => __( 'Generate engagement report', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
		);
	}

	/**
	 * Get Places Management toolkit presets.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private static function get_places_presets() {
		return array(
			'places_daily_availability' => array(
				'name'              => __( 'Daily Venue Availability Update', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Updates venue availability data and generates a booking summary report.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🏢',
				'toolkit'           => 'enable_places_management',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'places', 'availability' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'update_venue_availability',
						'arguments' => array(),
						'label'     => __( 'Update venue availability', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'generate_booking_report',
						'arguments' => array(),
						'label'     => __( 'Generate booking summary', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'places_weekly_booking_report' => array(
				'name'              => __( 'Weekly Booking Report', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Generates a weekly booking report with revenue analysis.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📊',
				'toolkit'           => 'enable_places_management',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'places', 'bookings' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'generate_booking_report',
						'arguments' => array(),
						'label'     => __( 'Generate booking report', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'analyze_venue_revenue',
						'arguments' => array(),
						'label'     => __( 'Analyze venue revenue', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'places_monthly_revenue_analysis' => array(
				'name'              => __( 'Monthly Revenue Analysis', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Analyzes venue revenue and generates an occupancy performance report.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '💰',
				'toolkit'           => 'enable_places_management',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_monthly',
				'tags'              => array( 'preset', 'places', 'revenue' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'analyze_venue_revenue',
						'arguments' => array(),
						'label'     => __( 'Analyze monthly revenue', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'generate_occupancy_report',
						'arguments' => array(),
						'label'     => __( 'Generate occupancy report', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'places_daily_maintenance' => array(
				'name'              => __( 'Daily Maintenance Schedule', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Checks maintenance schedules and updates venue availability accordingly.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🔧',
				'toolkit'           => 'enable_places_management',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'places', 'maintenance' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'check_maintenance_schedule',
						'arguments' => array(),
						'label'     => __( 'Check maintenance schedule', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'update_venue_availability',
						'arguments' => array(),
						'label'     => __( 'Update availability after maintenance', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'places_weekly_occupancy_report' => array(
				'name'              => __( 'Weekly Occupancy Report', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Generates occupancy statistics and analyzes revenue trends.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📈',
				'toolkit'           => 'enable_places_management',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'places', 'occupancy' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'generate_occupancy_report',
						'arguments' => array(),
						'label'     => __( 'Generate occupancy statistics', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'analyze_venue_revenue',
						'arguments' => array(),
						'label'     => __( 'Analyze revenue trends', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
		);
	}

	/**
	 * Get Media Toolkit toolkit presets.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private static function get_media_presets() {
		return array(
			'media_daily_optimization' => array(
				'name'              => __( 'Daily Media Optimization', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Optimizes new media uploads and batch-processes them for performance.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🎨',
				'toolkit'           => 'enable_media_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'media', 'optimization' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'optimize_media',
						'arguments' => array(),
						'label'     => __( 'Optimize new media uploads', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'batch_media_process',
						'arguments' => array(),
						'label'     => __( 'Batch process media files', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'media_weekly_template_refresh' => array(
				'name'              => __( 'Weekly Template Refresh', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Generates new media templates and exports a media status report.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🖼️',
				'toolkit'           => 'enable_media_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'media', 'templates' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'generate_media_template',
						'arguments' => array(),
						'label'     => __( 'Generate new media templates', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'export_media_report',
						'arguments' => array(),
						'label'     => __( 'Export media status report', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'media_daily_batch_processing' => array(
				'name'              => __( 'Daily Batch Processing', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Batch processes queued media files and optimizes output.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '⚙️',
				'toolkit'           => 'enable_media_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'daily',
				'tags'              => array( 'preset', 'media', 'batch' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'batch_media_process',
						'arguments' => array(),
						'label'     => __( 'Batch process queued media', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'optimize_media',
						'arguments' => array(),
						'label'     => __( 'Optimize processed output', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'media_weekly_report' => array(
				'name'              => __( 'Weekly Media Report', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Exports a comprehensive media library report with optimization stats.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '📊',
				'toolkit'           => 'enable_media_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_weekly',
				'tags'              => array( 'preset', 'media', 'reports' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'export_media_report',
						'arguments' => array(),
						'label'     => __( 'Export media report', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'optimize_media',
						'arguments' => array(),
						'label'     => __( 'Run optimization pass', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'media_monthly_audit' => array(
				'name'              => __( 'Monthly Media Audit', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Audits the full media library with batch processing and generates a report.', 'mcp-ai-wpoos-pro' ),
				'icon'              => '🔍',
				'toolkit'           => 'enable_media_toolkit',
				'schedule_type'     => 'workflow',
				'schedule'          => 'wp_mcp_ai_monthly',
				'tags'              => array( 'preset', 'media', 'audit' ),
				'priority'          => 5,
				'timeout'           => 300,
				'notify_on_failure' => true,
				'max_retries'       => 2,
				'retry_delay'       => 300,
				'workflow_steps'    => array(
					array(
						'tool_slug' => 'batch_media_process',
						'arguments' => array( 'scope' => 'full' ),
						'label'     => __( 'Full media library audit', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'tool_slug' => 'export_media_report',
						'arguments' => array(),
						'label'     => __( 'Export audit report', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
		);
	}

	} // end class
} // end class_exists
