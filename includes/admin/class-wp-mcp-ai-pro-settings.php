<?php
/**
 * Pro Settings Page
 *
 * Displays system information including npm package versions and pro toolkit settings.
 * Read-only status display for monitoring active packages and dependencies.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Pro_Settings' ) ) {
	/**
	 * Pro Settings page for displaying system information.
	 *
	 * Provides a centralized view of:
	 * - NPM package versions (dependencies and devDependencies)
	 * - Pro toolkit configuration status
	 * - System information relevant to pro features
	 *
	 * @since 1.1.0
	 */
	class WP_MCP_AI_Pro_Settings {
		/**
		 * Parent page slug (Pro Dashboard).
		 */
		const PARENT_SLUG = 'nvoos-pro-dashboard';

		/**
		 * Pro Settings page slug.
		 */
		const PAGE_SLUG = 'nvoos-pro-settings';

		/**
		 * Get npm package information from package.json.
		 *
		 * Parses package.json to extract dependencies and devDependencies.
		 * Now includes Pro addon packages as well.
		 * Lightweight approach that doesn't require npm CLI.
		 *
		 * @return array Array containing dependencies and devDependencies.
		 */
		public static function get_npm_packages() {
			$package_json_path = WP_MCP_AI_PATH . 'package.json';
			$packages          = array(
				'dependencies'    => array(),
				'devDependencies' => array(),
				'error'           => null,
			);

			if ( ! file_exists( $package_json_path ) ) {
				$packages['error'] = 'package.json not found';
				return $packages;
			}

			$json_content = file_get_contents( $package_json_path );
			if ( false === $json_content ) {
				$packages['error'] = 'Unable to read package.json';
				return $packages;
			}

			$package_data = json_decode( $json_content, true );
			if ( null === $package_data ) {
				$packages['error'] = 'Invalid JSON in package.json';
				return $packages;
			}

			// Extract dependencies.
			if ( isset( $package_data['dependencies'] ) && is_array( $package_data['dependencies'] ) ) {
				$packages['dependencies'] = $package_data['dependencies'];
			}

			// Extract devDependencies.
			if ( isset( $package_data['devDependencies'] ) && is_array( $package_data['devDependencies'] ) ) {
				$packages['devDependencies'] = $package_data['devDependencies'];
			}

			// Extract project metadata.
			$packages['name']    = isset( $package_data['name'] ) ? $package_data['name'] : 'unknown';
			$packages['version'] = isset( $package_data['version'] ) ? $package_data['version'] : 'unknown';

			// Merge in Pro addon packages if available.
			if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
				$pro_package_json_path = WP_MCP_AI_PRO_PATH . 'package.json';
				if ( file_exists( $pro_package_json_path ) ) {
					$pro_json_content = file_get_contents( $pro_package_json_path );
					if ( false !== $pro_json_content ) {
						$pro_package_data = json_decode( $pro_json_content, true );
						if ( null !== $pro_package_data ) {
							// Merge Pro dependencies.
							if ( isset( $pro_package_data['dependencies'] ) && is_array( $pro_package_data['dependencies'] ) ) {
								$packages['dependencies'] = array_merge( $packages['dependencies'], $pro_package_data['dependencies'] );
							}
							// Pro addon doesn't have devDependencies, but check anyway.
							if ( isset( $pro_package_data['devDependencies'] ) && is_array( $pro_package_data['devDependencies'] ) ) {
								$packages['devDependencies'] = array_merge( $packages['devDependencies'], $pro_package_data['devDependencies'] );
							}
						}
					}
				}
			}

			return $packages;
		}

		/**
		 * Get individual pro toolkit status information.
		 *
		 * Returns enable/disable status of each individual pro toolkit.
		 *
		 * @return array Individual toolkit status information.
		 */
		public static function get_individual_toolkit_status() {
			$settings = get_option( 'wp_mcp_ai_settings', array() );

			$toolkits = array(
				'enable_media_toolkit'                   => __( 'Media Toolkit', 'mcp-ai-wpoos' ),
				'enable_document_generation_toolkit'     => __( 'Document Generation Toolkit', 'mcp-ai-wpoos' ),
				'enable_quiz_system'                     => __( 'Quiz System', 'mcp-ai-wpoos' ),
				'enable_project_management'              => __( 'Project Management', 'mcp-ai-wpoos' ),
				'enable_health_wellness_management'      => __( 'Health & Wellness Management', 'mcp-ai-wpoos' ),
				'enable_places_management'               => __( 'Places Management', 'mcp-ai-wpoos' ),
				'enable_eca_management'                  => __( 'ECA Management', 'mcp-ai-wpoos' ),
				'enable_crm_toolkit'                     => __( 'CRM & Email Marketing Toolkit', 'mcp-ai-wpoos' ),
				'enable_ecommerce_toolkit'               => __( 'E-commerce Toolkit', 'mcp-ai-wpoos' ),
				'enable_social_media_toolkit'            => __( 'Social Media Management Toolkit', 'mcp-ai-wpoos' ),
				'enable_analytics_toolkit'               => __( 'Advanced Analytics Toolkit', 'mcp-ai-wpoos' ),
				'enable_multilingual_toolkit'            => __( 'Multilingual Content Toolkit', 'mcp-ai-wpoos' ),
				'enable_video_production_toolkit'        => __( 'Video Production Toolkit', 'mcp-ai-wpoos' ),
				'enable_ai_tool_builder_toolkit'         => __( 'AI Tool Builder Toolkit', 'mcp-ai-wpoos' ),
				'enable_architect_agent_toolkit'         => __( 'Architect Agent Toolkit', 'mcp-ai-wpoos' ),
				'enable_architectural_design_toolkit'    => __( 'Architectural Design Toolkit', 'mcp-ai-wpoos' ),
				'enable_calendar_booking_toolkit'        => __( 'Calendar Booking Toolkit', 'mcp-ai-wpoos' ),
				'enable_chat_channels_toolkit'           => __( 'Chat Channels Toolkit', 'mcp-ai-wpoos' ),
				'enable_dj_management_toolkit'           => __( 'DJ Management Toolkit', 'mcp-ai-wpoos' ),
				'enable_financial_planner_toolkit'       => __( 'Financial Planner Toolkit', 'mcp-ai-wpoos' ),
				'enable_image_production_toolkit'        => __( 'Image Production Toolkit', 'mcp-ai-wpoos' ),
				'enable_regulatory_registration_toolkit' => __( 'Regulatory Registration Toolkit', 'mcp-ai-wpoos' ),
				'enable_webchat_integration'             => __( 'WebChat Integration', 'mcp-ai-wpoos' ),
				'enable_woocommerce_tools'               => __( 'WooCommerce Tools', 'mcp-ai-wpoos' ),
				'enable_jetengine_tools'                 => __( 'JetEngine Tools', 'mcp-ai-wpoos' ),
				'enable_site_creator'                    => __( 'Site Creator', 'mcp-ai-wpoos' ),
				'enable_ai_cpt_management'               => __( 'AI CPT Management', 'mcp-ai-wpoos' ),
				'enable_fantasy_football'                => __( 'Fantasy Football Toolkit', 'mcp-ai-wpoos' ),
			);

			$toolkit_status = array();
			foreach ( $toolkits as $setting_key => $toolkit_name ) {
				$toolkit_status[ $setting_key ] = array(
					'name'    => $toolkit_name,
					'enabled' => ! empty( $settings[ $setting_key ] ),
				);
			}

			return $toolkit_status;
		}

		/**
		 * Get PHP function requirements status grouped by system.
		 *
		 * Checks availability of PHP functions needed for Pro features.
		 * Functions are grouped by the system/feature that requires them.
		 *
		 * @return array PHP function status information grouped by system.
		 */
		public static function get_php_requirements_status() {
			// Systems and their required PHP functions.
			$systems = array(
				'process_service' => array(
					'name'        => __( 'Process Service & Node.js Integration', 'mcp-ai-wpoos' ),
					'description' => __( 'Core service for external process execution, Node.js tools, and NPM package integration.', 'mcp-ai-wpoos' ),
					'functions'   => array( 'proc_open', 'proc_close', 'proc_terminate' ),
					'critical'    => true,
					'tools'       => array(
						__( 'All Node.js-based tools', 'mcp-ai-wpoos' ),
						__( 'NPM integration (Prettier, MJML, FFmpeg)', 'mcp-ai-wpoos' ),
						__( 'Image optimization (Sharp)', 'mcp-ai-wpoos' ),
						__( 'Video processing', 'mcp-ai-wpoos' ),
						__( 'Math equation rendering', 'mcp-ai-wpoos' ),
					),
				),
				'wp_cli'          => array(
					'name'        => __( 'WP-CLI Integration', 'mcp-ai-wpoos' ),
					'description' => __( 'Command-line interface for WordPress management and automation.', 'mcp-ai-wpoos' ),
					'functions'   => array( 'proc_open', 'proc_close' ),
					'critical'    => false,
					'tools'       => array(
						__( 'check_wp_cli tool', 'mcp-ai-wpoos' ),
						__( 'WP-CLI command execution', 'mcp-ai-wpoos' ),
					),
				),
				'performance'     => array(
					'name'        => __( 'Performance Monitoring & Testing', 'mcp-ai-wpoos' ),
					'description' => __( 'PHPUnit test execution and performance benchmarking from admin interface.', 'mcp-ai-wpoos' ),
					'functions'   => array( 'exec' ),
					'critical'    => false,
					'tools'       => array(
						__( 'Performance monitoring dashboard', 'mcp-ai-wpoos' ),
						__( 'PHPUnit test runner', 'mcp-ai-wpoos' ),
						__( 'Benchmark tests', 'mcp-ai-wpoos' ),
					),
				),
				'documents'       => array(
					'name'        => __( 'Document Generation', 'mcp-ai-wpoos' ),
					'description' => __( 'Advanced PDF, Word, and Excel document generation with external libraries.', 'mcp-ai-wpoos' ),
					'functions'   => array( 'exec' ),
					'critical'    => false,
					'tools'       => array(
						__( 'generate_pdf_document tool', 'mcp-ai-wpoos' ),
						__( 'generate_word_document tool', 'mcp-ai-wpoos' ),
						__( 'generate_excel_document tool', 'mcp-ai-wpoos' ),
					),
				),
			);

			// Check function availability.
			$results         = array();
			$all_critical_ok = true;

			foreach ( $systems as $system_id => $system ) {
				$system_functions = array();
				$all_available    = true;

				foreach ( $system['functions'] as $func_name ) {
					$available                      = function_exists( $func_name );
					$system_functions[ $func_name ] = array(
						'available' => $available,
						'name'      => $func_name,
					);

					if ( ! $available ) {
						$all_available = false;
						if ( $system['critical'] ) {
							$all_critical_ok = false;
						}
					}
				}

				$results[ $system_id ] = array(
					'name'          => $system['name'],
					'description'   => $system['description'],
					'functions'     => $system_functions,
					'tools'         => $system['tools'],
					'critical'      => $system['critical'],
					'all_available' => $all_available,
					'status'        => $all_available ? 'ok' : ( $system['critical'] ? 'critical' : 'warning' ),
				);
			}

			// Get disabled functions list.
			$disabled_functions = ini_get( 'disable_functions' );
			$disabled_list      = $disabled_functions ? array_map( 'trim', explode( ',', $disabled_functions ) ) : array();

			// Check if any systems have issues.
			$systems_with_issues = array_filter(
				$results,
				function ( $s ) {
					return ! $s['all_available'];
				}
			);
			$has_any_issues      = ! $all_critical_ok || count( $systems_with_issues ) > 0;

			return array(
				'systems'         => $results,
				'disabled_list'   => $disabled_list,
				'all_critical_ok' => $all_critical_ok,
				'has_any_issues'  => $has_any_issues,
			);
		}

		/**
		 * Get comprehensive toolkit details grouped by system.
		 *
		 * Returns detailed information about each toolkit including status,
		 * PHP requirements, NPM dependencies, tools count, and categorization.
		 *
		 * @return array Toolkit details grouped by system.
		 */
		public static function get_toolkit_details() {
			$settings = get_option( 'wp_mcp_ai_settings', array() );

			$toolkits = array(
				'media_toolkit'                   => array(
					'name'          => __( 'Media Toolkit', 'mcp-ai-wpoos' ),
					'description'   => __( 'Image optimization, video processing, SVG vectorization, and math equation rendering.', 'mcp-ai-wpoos' ),
					'enabled'       => ! empty( $settings['enable_media_toolkit'] ),
					'category'      => 'specialized',
					'php_functions' => array( 'proc_open', 'proc_close', 'proc_terminate' ),
					'npm_packages'  => array( 'sharp', 'fluent-ffmpeg', 'katex', '@neplex/vectorizer' ),
					'tools_count'   => 4,
					'tools'         => array(
						__( 'optimize_image tool', 'mcp-ai-wpoos' ),
						__( 'process_video tool', 'mcp-ai-wpoos' ),
						__( 'render_math_equation tool', 'mcp-ai-wpoos' ),
						__( 'vectorize_image tool', 'mcp-ai-wpoos' ),
					),
				),
				'document_generation'             => array(
					'name'          => __( 'Document Generation Toolkit', 'mcp-ai-wpoos' ),
					'description'   => __( 'Advanced PDF, Word, and Excel document generation with external libraries.', 'mcp-ai-wpoos' ),
					'enabled'       => ! empty( $settings['enable_document_generation_toolkit'] ),
					'category'      => 'specialized',
					'php_functions' => array( 'exec' ),
					'npm_packages'  => array( 'pdfkit', 'docx', 'exceljs' ),
					'tools_count'   => 3,
					'tools'         => array(
						__( 'generate_pdf_document tool', 'mcp-ai-wpoos' ),
						__( 'generate_word_document tool', 'mcp-ai-wpoos' ),
						__( 'generate_excel_document tool', 'mcp-ai-wpoos' ),
					),
				),
				'project_management'              => array(
					'name'          => __( 'Project Management', 'mcp-ai-wpoos' ),
					'description'   => __( 'ICS calendar file generation for project scheduling and event management.', 'mcp-ai-wpoos' ),
					'enabled'       => ! empty( $settings['enable_project_management'] ),
					'category'      => 'specialized',
					'php_functions' => array(),
					'npm_packages'  => array( 'ics' ),
					'tools_count'   => 1,
					'tools'         => array(
						__( 'generate_ics_calendar tool', 'mcp-ai-wpoos' ),
					),
				),
				'places_management'               => array(
					'name'          => __( 'Places Management', 'mcp-ai-wpoos' ),
					'description'   => __( 'Geographic data processing and spatial analysis with Turf.js.', 'mcp-ai-wpoos' ),
					'enabled'       => ! empty( $settings['enable_places_management'] ),
					'category'      => 'specialized',
					'php_functions' => array(),
					'npm_packages'  => array( '@turf/turf' ),
					'tools_count'   => 1,
					'tools'         => array(
						__( 'process_geospatial_data tool', 'mcp-ai-wpoos' ),
					),
				),
				'health_wellness_management'      => array(
					'name'          => __( 'Health & Wellness Management', 'mcp-ai-wpoos' ),
					'description'   => __( 'Health data visualization and chart generation with Chart.js.', 'mcp-ai-wpoos' ),
					'enabled'       => ! empty( $settings['enable_health_wellness_management'] ),
					'category'      => 'specialized',
					'php_functions' => array(),
					'npm_packages'  => array( 'chart.js' ),
					'tools_count'   => 1,
					'tools'         => array(
						__( 'generate_health_chart tool', 'mcp-ai-wpoos' ),
					),
				),
				'quiz_system'                     => array(
					'name'          => __( 'Quiz System', 'mcp-ai-wpoos' ),
					'description'   => __( 'Interactive quiz creation with math equation support.', 'mcp-ai-wpoos' ),
					'enabled'       => ! empty( $settings['enable_quiz_system'] ),
					'category'      => 'specialized',
					'php_functions' => array(),
					'npm_packages'  => array( 'katex' ),
					'tools_count'   => 2,
					'tools'         => array(
						__( 'create_quiz tool', 'mcp-ai-wpoos' ),
						__( 'render_math_equation tool', 'mcp-ai-wpoos' ),
					),
				),
				'eca_management'                  => array(
					'name'          => __( 'ECA Management', 'mcp-ai-wpoos' ),
					'description'   => __( 'Extracurricular activities management with no external dependencies.', 'mcp-ai-wpoos' ),
					'enabled'       => ! empty( $settings['enable_eca_management'] ),
					'category'      => 'core',
					'php_functions' => array(),
					'npm_packages'  => array(),
					'tools_count'   => 3,
					'tools'         => array(
						__( 'manage_eca tool', 'mcp-ai-wpoos' ),
						__( 'track_eca_attendance tool', 'mcp-ai-wpoos' ),
						__( 'generate_eca_report tool', 'mcp-ai-wpoos' ),
					),
				),
				'crm_toolkit'                     => array(
					'name'          => __( 'CRM & Email Marketing Toolkit', 'mcp-ai-wpoos' ),
					'description'   => __( 'Comprehensive customer relationship management and email marketing automation with contact management, campaign creation, list segmentation, email sending with nodemailer, validation, CSV import/export, and calendar integration.', 'mcp-ai-wpoos' ),
					'enabled'       => ! empty( $settings['enable_crm_toolkit'] ),
					'category'      => 'specialized',
					'php_functions' => array( 'proc_open', 'proc_close' ),
					'npm_packages'  => array( 'nodemailer', 'validator', 'email-validator', 'libphonenumber-js', 'mailparser', 'csv-parse', 'csv-stringify', 'ical-generator' ),
					'tools_count'   => 12,
					'tools'         => array(
						__( 'create_contact tool', 'mcp-ai-wpoos' ),
						__( 'update_contact tool', 'mcp-ai-wpoos' ),
						__( 'segment_contacts tool', 'mcp-ai-wpoos' ),
						__( 'import_contacts_csv tool', 'mcp-ai-wpoos' ),
						__( 'export_contacts_csv tool', 'mcp-ai-wpoos' ),
						__( 'create_email_campaign tool', 'mcp-ai-wpoos' ),
						__( 'send_email tool', 'mcp-ai-wpoos' ),
						__( 'parse_email tool', 'mcp-ai-wpoos' ),
						__( 'validate_email tool', 'mcp-ai-wpoos' ),
						__( 'validate_phone tool', 'mcp-ai-wpoos' ),
						__( 'track_campaign_metrics tool', 'mcp-ai-wpoos' ),
						__( 'send_calendar_invite tool', 'mcp-ai-wpoos' ),
					),
				),
				'code_formatting'                 => array(
					'name'          => __( 'Code Formatting', 'mcp-ai-wpoos' ),
					'description'   => __( 'Code and email template formatting with Prettier and MJML.', 'mcp-ai-wpoos' ),
					'enabled'       => true,
					'category'      => 'infrastructure',
					'php_functions' => array( 'proc_open', 'proc_close' ),
					'npm_packages'  => array( 'prettier', 'mjml' ),
					'tools_count'   => 2,
					'tools'         => array(
						__( 'format_code tool', 'mcp-ai-wpoos' ),
						__( 'compile_mjml tool', 'mcp-ai-wpoos' ),
					),
				),
				'ecommerce_toolkit'               => array(
					'name'          => __( 'E-commerce Toolkit', 'mcp-ai-wpoos' ),
					'description'   => __( 'Advanced WooCommerce integration with product management, order processing, inventory tracking, payment gateway support, and customer management.', 'mcp-ai-wpoos' ),
					'enabled'       => ! empty( $settings['enable_ecommerce_toolkit'] ),
					'category'      => 'specialized',
					'php_functions' => array(),
					'npm_packages'  => array( '@woocommerce/woocommerce-rest-api', 'stripe', 'currency.js' ),
					'tools_count'   => 8,
					'tools'         => array(
						__( 'create_product_advanced tool', 'mcp-ai-wpoos' ),
						__( 'update_product_inventory tool', 'mcp-ai-wpoos' ),
						__( 'process_payment tool', 'mcp-ai-wpoos' ),
						__( 'manage_orders tool', 'mcp-ai-wpoos' ),
						__( 'calculate_pricing tool', 'mcp-ai-wpoos' ),
						__( 'manage_customers tool', 'mcp-ai-wpoos' ),
						__( 'track_shipments tool', 'mcp-ai-wpoos' ),
						__( 'generate_reports tool', 'mcp-ai-wpoos' ),
					),
				),
				'social_media_toolkit'            => array(
					'name'          => __( 'Social Media Management Toolkit', 'mcp-ai-wpoos' ),
					'description'   => __( 'Multi-platform social media posting, scheduling, analytics, and engagement management for Twitter, Facebook, LinkedIn, and Instagram.', 'mcp-ai-wpoos' ),
					'enabled'       => ! empty( $settings['enable_social_media_toolkit'] ),
					'category'      => 'specialized',
					'php_functions' => array(),
					'npm_packages'  => array( 'twitter-api-v2', 'axios', 'facebook-nodejs-business-sdk', 'linkedin-api-client' ),
					'tools_count'   => 12,
					'tools'         => array(
						__( 'post_to_twitter tool', 'mcp-ai-wpoos' ),
						__( 'post_to_facebook tool', 'mcp-ai-wpoos' ),
						__( 'post_to_linkedin tool', 'mcp-ai-wpoos' ),
						__( 'post_to_instagram tool', 'mcp-ai-wpoos' ),
						__( 'schedule_social_post tool', 'mcp-ai-wpoos' ),
						__( 'analyze_engagement tool', 'mcp-ai-wpoos' ),
						__( 'manage_social_campaigns tool', 'mcp-ai-wpoos' ),
						__( 'cross_post_content tool', 'mcp-ai-wpoos' ),
						__( 'track_mentions tool', 'mcp-ai-wpoos' ),
						__( 'generate_hashtags tool', 'mcp-ai-wpoos' ),
						__( 'schedule_thread tool', 'mcp-ai-wpoos' ),
						__( 'monitor_trends tool', 'mcp-ai-wpoos' ),
					),
				),
				'analytics_toolkit'               => array(
					'name'          => __( 'Advanced Analytics Toolkit', 'mcp-ai-wpoos' ),
					'description'   => __( 'Business intelligence, predictive analytics, data visualization with D3.js, statistical analysis with Math.js, regression modeling, and CSV data export.', 'mcp-ai-wpoos' ),
					'enabled'       => ! empty( $settings['enable_analytics_toolkit'] ),
					'category'      => 'specialized',
					'php_functions' => array(),
					'npm_packages'  => array( 'd3', 'mathjs', 'regression', 'fast-csv' ),
					'tools_count'   => 10,
					'tools'         => array(
						__( 'create_dashboard tool', 'mcp-ai-wpoos' ),
						__( 'visualize_data tool', 'mcp-ai-wpoos' ),
						__( 'perform_regression_analysis tool', 'mcp-ai-wpoos' ),
						__( 'calculate_statistics tool', 'mcp-ai-wpoos' ),
						__( 'generate_predictions tool', 'mcp-ai-wpoos' ),
						__( 'export_csv tool', 'mcp-ai-wpoos' ),
						__( 'import_csv tool', 'mcp-ai-wpoos' ),
						__( 'create_charts tool', 'mcp-ai-wpoos' ),
						__( 'analyze_trends tool', 'mcp-ai-wpoos' ),
						__( 'generate_insights tool', 'mcp-ai-wpoos' ),
					),
				),
				'multilingual_toolkit'            => array(
					'name'          => __( 'Multilingual Content Toolkit', 'mcp-ai-wpoos' ),
					'description'   => __( 'Multi-language content management with i18next, automatic language detection with franc, Google Translate API integration, and ISO 639-1 language code support.', 'mcp-ai-wpoos' ),
					'enabled'       => ! empty( $settings['enable_multilingual_toolkit'] ),
					'category'      => 'specialized',
					'php_functions' => array(),
					'npm_packages'  => array( 'i18next', 'franc', 'google-translate-api-x', 'iso-639-1' ),
					'tools_count'   => 6,
					'tools'         => array(
						__( 'translate_content tool', 'mcp-ai-wpoos' ),
						__( 'detect_language tool', 'mcp-ai-wpoos' ),
						__( 'manage_translations tool', 'mcp-ai-wpoos' ),
						__( 'localize_content tool', 'mcp-ai-wpoos' ),
						__( 'validate_language_codes tool', 'mcp-ai-wpoos' ),
						__( 'batch_translate tool', 'mcp-ai-wpoos' ),
					),
				),
				'video_production_toolkit'        => array(
					'name'          => __( 'Video Production Toolkit', 'mcp-ai-wpoos' ),
					'description'   => __( 'Professional video creation, editing, and processing with FFmpeg, subtitle generation, GIF creation, and video stitching for content creators and marketers.', 'mcp-ai-wpoos' ),
					'enabled'       => ! empty( $settings['enable_video_production_toolkit'] ),
					'category'      => 'specialized',
					'php_functions' => array( 'proc_open', 'proc_close', 'proc_terminate', 'exec' ),
					'npm_packages'  => array( 'ffmpeg-static', 'ffprobe-static', 'gif-encoder', 'video-stitch', 'subtitle' ),
					'tools_count'   => 10,
					'tools'         => array(
						__( 'create_video tool', 'mcp-ai-wpoos' ),
						__( 'edit_video tool', 'mcp-ai-wpoos' ),
						__( 'convert_video tool', 'mcp-ai-wpoos' ),
						__( 'compress_video tool', 'mcp-ai-wpoos' ),
						__( 'add_subtitles tool', 'mcp-ai-wpoos' ),
						__( 'generate_gif tool', 'mcp-ai-wpoos' ),
						__( 'stitch_videos tool', 'mcp-ai-wpoos' ),
						__( 'extract_audio tool', 'mcp-ai-wpoos' ),
						__( 'add_watermark tool', 'mcp-ai-wpoos' ),
						__( 'trim_video tool', 'mcp-ai-wpoos' ),
					),
				),
				'ai_tool_builder_toolkit'         => array(
					'name'          => __( 'AI Tool Builder Toolkit', 'mcp-ai-wpoos' ),
					'description'   => __( 'Meta-toolkit for creating custom AI tools with scaffolding, code generation, testing, and documentation capabilities.', 'mcp-ai-wpoos' ),
					'enabled'       => ! empty( $settings['enable_ai_tool_builder_toolkit'] ),
					'category'      => 'infrastructure',
					'php_functions' => array(),
					'npm_packages'  => array( 'prettier' ),
					'tools_count'   => 5,
					'tools'         => array(
						__( 'scaffold_tool tool', 'mcp-ai-wpoos' ),
						__( 'generate_tool_code tool', 'mcp-ai-wpoos' ),
						__( 'test_tool tool', 'mcp-ai-wpoos' ),
						__( 'document_tool tool', 'mcp-ai-wpoos' ),
						__( 'validate_tool tool', 'mcp-ai-wpoos' ),
					),
				),
				'architect_agent_toolkit'         => array(
					'name'          => __( 'Architect Agent Toolkit', 'mcp-ai-wpoos' ),
					'description'   => __( 'Self-editing capabilities for AI agents with file operations, shell commands, git integration, and code search. Achieves GitHub Copilot CLI parity.', 'mcp-ai-wpoos' ),
					'enabled'       => ! empty( $settings['enable_architect_agent_toolkit'] ),
					'category'      => 'development',
					'php_functions' => array( 'proc_open', 'exec' ),
					'npm_packages'  => array(),
					'tools_count'   => 4,
					'tools'         => array(
						__( 'manage_files tool', 'mcp-ai-wpoos' ),
						__( 'execute_shell_command tool', 'mcp-ai-wpoos' ),
						__( 'git_operations tool', 'mcp-ai-wpoos' ),
						__( 'search_codebase tool', 'mcp-ai-wpoos' ),
					),
				),
				'architectural_design_toolkit'    => array(
					'name'          => __( 'Architectural Design Toolkit', 'mcp-ai-wpoos' ),
					'description'   => __( 'AI-powered floor plan generation, 3D modeling, blueprint creation, code compliance checking, and cost estimation for architectural projects.', 'mcp-ai-wpoos' ),
					'enabled'       => ! empty( $settings['enable_architectural_design_toolkit'] ),
					'category'      => 'specialized',
					'php_functions' => array(),
					'npm_packages'  => array( 'd3' ),
					'tools_count'   => 6,
					'tools'         => array(
						__( 'generate_floor_plan tool', 'mcp-ai-wpoos' ),
						__( 'create_3d_model tool', 'mcp-ai-wpoos' ),
						__( 'generate_blueprint tool', 'mcp-ai-wpoos' ),
						__( 'check_code_compliance tool', 'mcp-ai-wpoos' ),
						__( 'estimate_costs tool', 'mcp-ai-wpoos' ),
						__( 'optimize_layout tool', 'mcp-ai-wpoos' ),
					),
				),
				'calendar_booking_toolkit'        => array(
					'name'          => __( 'Calendar Booking Toolkit', 'mcp-ai-wpoos' ),
					'description'   => __( 'Appointment scheduling, availability management, calendar synchronization, booking management, and automated reminder system.', 'mcp-ai-wpoos' ),
					'enabled'       => ! empty( $settings['enable_calendar_booking_toolkit'] ),
					'category'      => 'specialized',
					'php_functions' => array(),
					'npm_packages'  => array( 'ics', 'ical-generator' ),
					'tools_count'   => 7,
					'tools'         => array(
						__( 'schedule_appointment tool', 'mcp-ai-wpoos' ),
						__( 'check_availability tool', 'mcp-ai-wpoos' ),
						__( 'sync_calendar tool', 'mcp-ai-wpoos' ),
						__( 'manage_bookings tool', 'mcp-ai-wpoos' ),
						__( 'send_reminders tool', 'mcp-ai-wpoos' ),
						__( 'cancel_booking tool', 'mcp-ai-wpoos' ),
						__( 'reschedule_appointment tool', 'mcp-ai-wpoos' ),
					),
				),
				'dj_management_toolkit'           => array(
					'name'          => __( 'DJ Management Toolkit', 'mcp-ai-wpoos' ),
					'description'   => __( 'Equipment tracking, playlist management, event scheduling, client management, and music library organization for DJs and event organizers.', 'mcp-ai-wpoos' ),
					'enabled'       => ! empty( $settings['enable_dj_management_toolkit'] ),
					'category'      => 'specialized',
					'php_functions' => array(),
					'npm_packages'  => array(),
					'tools_count'   => 8,
					'tools'         => array(
						__( 'track_equipment tool', 'mcp-ai-wpoos' ),
						__( 'manage_playlists tool', 'mcp-ai-wpoos' ),
						__( 'schedule_events tool', 'mcp-ai-wpoos' ),
						__( 'manage_clients tool', 'mcp-ai-wpoos' ),
						__( 'organize_music_library tool', 'mcp-ai-wpoos' ),
						__( 'generate_setlist tool', 'mcp-ai-wpoos' ),
						__( 'track_bookings tool', 'mcp-ai-wpoos' ),
						__( 'manage_invoices tool', 'mcp-ai-wpoos' ),
					),
				),
				'financial_planner_toolkit'       => array(
					'name'          => __( 'Financial Planner Toolkit', 'mcp-ai-wpoos' ),
					'description'   => __( 'Retirement planning, budgeting, investment tracking, debt management, and financial goal planning with advanced analytics.', 'mcp-ai-wpoos' ),
					'enabled'       => ! empty( $settings['enable_financial_planner_toolkit'] ),
					'category'      => 'specialized',
					'php_functions' => array(),
					'npm_packages'  => array( 'mathjs', 'regression', 'currency.js' ),
					'tools_count'   => 9,
					'tools'         => array(
						__( 'plan_retirement tool', 'mcp-ai-wpoos' ),
						__( 'create_budget tool', 'mcp-ai-wpoos' ),
						__( 'track_investments tool', 'mcp-ai-wpoos' ),
						__( 'manage_debt tool', 'mcp-ai-wpoos' ),
						__( 'set_financial_goals tool', 'mcp-ai-wpoos' ),
						__( 'calculate_roi tool', 'mcp-ai-wpoos' ),
						__( 'project_savings tool', 'mcp-ai-wpoos' ),
						__( 'analyze_expenses tool', 'mcp-ai-wpoos' ),
						__( 'generate_financial_report tool', 'mcp-ai-wpoos' ),
					),
				),
				'image_production_toolkit'        => array(
					'name'          => __( 'Image Production Toolkit', 'mcp-ai-wpoos' ),
					'description'   => __( 'AI-powered image generation, editing, enhancement, and optimization with advanced filters and effects.', 'mcp-ai-wpoos' ),
					'enabled'       => ! empty( $settings['enable_image_production_toolkit'] ),
					'category'      => 'specialized',
					'php_functions' => array( 'proc_open', 'proc_close' ),
					'npm_packages'  => array( 'sharp' ),
					'tools_count'   => 8,
					'tools'         => array(
						__( 'generate_image tool', 'mcp-ai-wpoos' ),
						__( 'edit_image tool', 'mcp-ai-wpoos' ),
						__( 'enhance_image tool', 'mcp-ai-wpoos' ),
						__( 'apply_filters tool', 'mcp-ai-wpoos' ),
						__( 'remove_background tool', 'mcp-ai-wpoos' ),
						__( 'upscale_image tool', 'mcp-ai-wpoos' ),
						__( 'batch_process_images tool', 'mcp-ai-wpoos' ),
						__( 'create_thumbnail tool', 'mcp-ai-wpoos' ),
					),
				),
				'regulatory_registration_toolkit' => array(
					'name'          => __( 'Regulatory Registration Toolkit', 'mcp-ai-wpoos' ),
					'description'   => __( 'Comprehensive regulatory product registration and compliance management for multi-country regulatory submissions (NMRA, MOHAP, SFDA). Features product registration tracking, document management, compliance validation, PDF generation, and multi-country support with registration timeline tracking and expiry notifications.', 'mcp-ai-wpoos' ),
					'enabled'       => ! empty( $settings['enable_regulatory_registration_toolkit'] ),
					'category'      => 'specialized',
					'php_functions' => array(),
					'npm_packages'  => array( 'pdfkit', 'exceljs', 'docx', 'csv-parse', 'csv-stringify', 'validator' ),
					'tools_count'   => 40,
					'tools'         => array(
						__( 'create_reg_product tool', 'mcp-ai-wpoos' ),
						__( 'list_reg_products tool', 'mcp-ai-wpoos' ),
						__( 'get_reg_product tool', 'mcp-ai-wpoos' ),
						__( 'update_reg_product tool', 'mcp-ai-wpoos' ),
						__( 'delete_reg_product tool', 'mcp-ai-wpoos' ),
						__( 'search_reg_products tool', 'mcp-ai-wpoos' ),
						__( 'duplicate_reg_product tool', 'mcp-ai-wpoos' ),
						__( 'validate_reg_product tool', 'mcp-ai-wpoos' ),
						__( 'create_registration tool', 'mcp-ai-wpoos' ),
						__( 'list_registrations tool', 'mcp-ai-wpoos' ),
						__( 'get_registration tool', 'mcp-ai-wpoos' ),
						__( 'update_registration_status tool', 'mcp-ai-wpoos' ),
						__( 'list_expiring_registrations tool', 'mcp-ai-wpoos' ),
						__( 'submit_registration tool', 'mcp-ai-wpoos' ),
						__( 'approve_registration tool', 'mcp-ai-wpoos' ),
						__( 'renew_registration tool', 'mcp-ai-wpoos' ),
						__( 'get_registration_timeline tool', 'mcp-ai-wpoos' ),
						__( 'list_registrations_by_country tool', 'mcp-ai-wpoos' ),
						__( 'list_reg_documents tool', 'mcp-ai-wpoos' ),
						__( 'check_document_expiry tool', 'mcp-ai-wpoos' ),
						__( 'upload_reg_document tool', 'mcp-ai-wpoos' ),
						__( 'update_reg_document tool', 'mcp-ai-wpoos' ),
						__( 'get_reg_document tool', 'mcp-ai-wpoos' ),
						__( 'validate_document_checklist tool', 'mcp-ai-wpoos' ),
						__( 'generate_submission_pack tool', 'mcp-ai-wpoos' ),
						__( 'track_document_version tool', 'mcp-ai-wpoos' ),
						__( 'add_regulatory_requirement tool', 'mcp-ai-wpoos' ),
						__( 'get_regulatory_requirements tool', 'mcp-ai-wpoos' ),
						__( 'check_product_compliance tool', 'mcp-ai-wpoos' ),
						__( 'validate_inci_ingredients tool', 'mcp-ai-wpoos' ),
						__( 'check_hs_code tool', 'mcp-ai-wpoos' ),
						__( 'get_regulatory_updates tool', 'mcp-ai-wpoos' ),
						__( 'generate_pdf_dossier tool', 'mcp-ai-wpoos' ),
						__( 'generate_cover_letter tool', 'mcp-ai-wpoos' ),
						__( 'generate_compliance_certificate tool', 'mcp-ai-wpoos' ),
						__( 'sync_with_nmra tool', 'mcp-ai-wpoos' ),
						__( 'sync_with_mohap tool', 'mcp-ai-wpoos' ),
						__( 'sync_with_sfda tool', 'mcp-ai-wpoos' ),
						__( 'validate_excel_import tool', 'mcp-ai-wpoos' ),
						__( 'configure_email_notifications tool', 'mcp-ai-wpoos' ),
					),
				),
				'fantasy_football'                => array(
					'name'          => __( 'Fantasy Football Toolkit', 'mcp-ai-wpoos' ),
					'description'   => __( 'Yahoo Fantasy Sports integration with team management, player research, trade analysis, and league reporting.', 'mcp-ai-wpoos' ),
					'enabled'       => ! empty( $settings['enable_fantasy_football'] ),
					'category'      => 'sports',
					'php_functions' => array(),
					'npm_packages'  => array(),
					'tools_count'   => 9,
					'tools'         => array(
						__( 'yahoo_ff_auth tool', 'mcp-ai-wpoos' ),
						__( 'yahoo_ff_get_leagues tool', 'mcp-ai-wpoos' ),
						__( 'yahoo_ff_get_roster tool', 'mcp-ai-wpoos' ),
						__( 'yahoo_ff_get_player_stats tool', 'mcp-ai-wpoos' ),
						__( 'yahoo_ff_league_standings tool', 'mcp-ai-wpoos' ),
						__( 'yahoo_ff_trade_analyzer tool', 'mcp-ai-wpoos' ),
						__( 'ff_player_research tool', 'mcp-ai-wpoos' ),
						__( 'ff_create_league_report tool', 'mcp-ai-wpoos' ),
						__( 'ff_generate_team_logo tool', 'mcp-ai-wpoos' ),
					),
				),
			);

			// Check PHP function availability for each toolkit.
			foreach ( $toolkits as $toolkit_id => &$toolkit ) {
				$toolkit['php_available'] = true;
				$toolkit['php_status']    = array();

				foreach ( $toolkit['php_functions'] as $func_name ) {
					$available                           = function_exists( $func_name );
					$toolkit['php_status'][ $func_name ] = $available;
					if ( ! $available ) {
						$toolkit['php_available'] = false;
					}
				}

				// Check NPM package availability.
				$toolkit['npm_available'] = true;
				$toolkit['npm_status']    = array();

				foreach ( $toolkit['npm_packages'] as $package ) {
					$installed                         = self::check_package_installed( $package );
					$toolkit['npm_status'][ $package ] = $installed;
					if ( ! $installed ) {
						$toolkit['npm_available'] = false;
					}
				}

				// Overall status.
				$toolkit['fully_operational'] = $toolkit['enabled'] && $toolkit['php_available'] && $toolkit['npm_available'];
				$toolkit['has_issues']        = ! $toolkit['php_available'] || ! $toolkit['npm_available'];
			}

			return $toolkits;
		}

		/**
		 * Render a toolkit card with detailed information.
		 *
		 * Displays toolkit status, requirements, dependencies, and tools list.
		 *
		 * @param string $toolkit_id Toolkit identifier.
		 * @param array  $toolkit    Toolkit details.
		 * @return void
		 */
		private static function render_toolkit_card( $toolkit_id, $toolkit ) {
			$status_class   = $toolkit['fully_operational'] ? 'operational' : ( $toolkit['enabled'] ? 'partial' : 'disabled' );
			$status_text    = $toolkit['fully_operational'] ? __( 'Operational', 'mcp-ai-wpoos' ) : ( $toolkit['enabled'] ? __( 'Enabled (Issues)', 'mcp-ai-wpoos' ) : __( 'Disabled', 'mcp-ai-wpoos' ) );
			$category_badge = $toolkit['category'];
			?>
			<div class="wp-mcp-ai-toolkit-card" data-toolkit="<?php echo esc_attr( $toolkit_id ); ?>">
					<div class="toolkit-header">
						<h3>
							<?php echo esc_html( $toolkit['name'] ); ?>
							<span class="toolkit-status-badge <?php echo esc_attr( $status_class ); ?>">
								<?php echo esc_html( $status_text ); ?>
							</span>
							<span class="toolkit-category-badge <?php echo esc_attr( $category_badge ); ?>">
								<?php echo esc_html( ucfirst( $category_badge ) ); ?>
							</span>
						</h3>
						<p class="toolkit-description"><?php echo esc_html( $toolkit['description'] ); ?></p>
					</div>

					<?php if ( $toolkit['has_issues'] && $toolkit['enabled'] ) : ?>
						<div class="toolkit-warning">
							<span class="dashicons dashicons-warning"></span>
							<strong><?php esc_html_e( 'Warning:', 'mcp-ai-wpoos' ); ?></strong>
							<?php esc_html_e( 'This toolkit is enabled but has missing dependencies or PHP function requirements.', 'mcp-ai-wpoos' ); ?>
						</div>
					<?php endif; ?>

					<?php if ( ! empty( $toolkit['php_functions'] ) ) : ?>
						<details class="toolkit-section">
							<summary>
								<?php esc_html_e( 'PHP Requirements', 'mcp-ai-wpoos' ); ?>
								<span class="section-badge <?php echo esc_attr( $toolkit['php_available'] ? 'ok' : 'error' ); ?>">
									<?php echo $toolkit['php_available'] ? esc_html__( 'OK', 'mcp-ai-wpoos' ) : esc_html__( 'Missing', 'mcp-ai-wpoos' ); ?>
								</span>
							</summary>
							<table class="toolkit-details-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'PHP Function', 'mcp-ai-wpoos' ); ?></th>
										<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $toolkit['php_status'] as $func_name => $available ) : ?>
										<tr>
											<td><code><?php echo esc_html( $func_name ); ?></code></td>
											<td>
												<span class="status-indicator <?php echo esc_attr( $available ? 'available' : 'unavailable' ); ?>">
													<?php echo $available ? '✓' : '✗'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static checkmark symbols. ?>
												</span>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</details>
					<?php endif; ?>

					<?php if ( ! empty( $toolkit['npm_packages'] ) ) : ?>
						<details class="toolkit-section">
							<summary>
								<?php esc_html_e( 'NPM Dependencies', 'mcp-ai-wpoos' ); ?>
								<span class="section-badge <?php echo esc_attr( $toolkit['npm_available'] ? 'ok' : 'error' ); ?>">
									<?php echo $toolkit['npm_available'] ? esc_html__( 'OK', 'mcp-ai-wpoos' ) : esc_html__( 'Missing', 'mcp-ai-wpoos' ); ?>
								</span>
							</summary>
							<table class="toolkit-details-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Package Name', 'mcp-ai-wpoos' ); ?></th>
										<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $toolkit['npm_status'] as $package => $installed ) : ?>
										<tr>
											<td><code><?php echo esc_html( $package ); ?></code></td>
											<td>
												<span class="status-indicator <?php echo esc_attr( $installed ? 'available' : 'unavailable' ); ?>">
													<?php echo $installed ? '✓' : '✗'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static checkmark symbols. ?>
												</span>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</details>
					<?php endif; ?>

					<details class="toolkit-section">
						<summary>
							<?php esc_html_e( 'Tools', 'mcp-ai-wpoos' ); ?>
							<span class="tools-count">(<?php echo absint( $toolkit['tools_count'] ); ?>)</span>
						</summary>
						<ul class="toolkit-tools-list">
							<?php foreach ( $toolkit['tools'] as $tool ) : ?>
								<li><?php echo esc_html( $tool ); ?></li>
							<?php endforeach; ?>
						</ul>
					</details>
				</div>
			<?php
		}

		/**
		 * Get pro toolkit status information.
		 *
		 * Returns status of various pro features and configurations.
		 *
		 * @return array Pro toolkit status information.
		 */
		public static function get_pro_toolkit_status() {
			$status = array(
				'pro_dashboard_enabled' => defined( 'WP_MCP_AI_PRO_DASHBOARD_ENABLED' ) && WP_MCP_AI_PRO_DASHBOARD_ENABLED,
				'base_version'          => ! defined( 'WP_MCP_AI_PRO_VERSION' ),
				'debug_mode'            => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'php_version'           => PHP_VERSION,
				'wp_version'            => get_bloginfo( 'version' ),
				'plugin_version'        => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : 'unknown',
			);

			// Check optional integrations.
			$status['integrations'] = array(
				'jetengine'      => class_exists( 'Jet_Engine' ),
				'jetformbuilder' => class_exists( 'Jet_Form_Builder' ),
				'woocommerce'    => class_exists( 'WooCommerce' ),
				'elementor'      => defined( 'ELEMENTOR_VERSION' ),
				'rankmath'       => defined( 'RANK_MATH_VERSION' ),
				'wpcode'         => defined( 'WPCODE_VERSION' ),
				'newsletter'     => class_exists( 'Newsletter' ) || class_exists( 'NewsletterEmails' ),
				'wpallimport'    => class_exists( 'PMXI_Plugin' ) || defined( 'PMXI_VERSION' ),
				'wpallexport'    => class_exists( 'PMXE_Plugin' ) || defined( 'PMXE_VERSION' ),
			);

			return $status;
		}

		/**
		 * Render npm packages table.
		 *
		 * Displays packages in a WordPress standard table format.
		 *
		 * @param array  $packages Package list.
		 * @param string $title    Table title.
		 * @return void
		 */
		private static function render_packages_table( $packages, $title ) {
			if ( empty( $packages ) ) {
				echo '<p><em>' . esc_html__( 'No packages found.', 'mcp-ai-wpoos' ) . '</em></p>';
				return;
			}

			?>
			<h3><?php echo esc_html( $title ); ?> <span class="count">(<?php echo count( $packages ); ?>)</span></h3>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width: 40%;"><?php esc_html_e( 'Package Name', 'mcp-ai-wpoos' ); ?></th>
						<th style="width: 20%;"><?php esc_html_e( 'Version', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					// Sort packages alphabetically.
					ksort( $packages );
					foreach ( $packages as $package => $version ) :
						// Check if package is installed (vendor file exists).
						$package_installed = self::check_package_installed( $package );
						$status_class      = $package_installed ? 'installed' : 'not-installed';
						$status_text       = $package_installed ? __( 'Installed', 'mcp-ai-wpoos' ) : __( 'Not Found', 'mcp-ai-wpoos' );
						?>
						<tr>
							<td><code><?php echo esc_html( $package ); ?></code></td>
							<td><code><?php echo esc_html( $version ); ?></code></td>
							<td>
								<span class="wp-mcp-ai-status-badge <?php echo esc_attr( $status_class ); ?>">
									<?php echo esc_html( $status_text ); ?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}

		/**
		 * Check if a package is installed by looking for vendor files or bundled builds.
		 *
		 * Checks vendor directories FIRST (production), then bundled builds, then node_modules (development).
		 * This ensures production deployments with vendor files are detected before dev fallbacks.
		 *
		 * @param string $package Package name.
		 * @return bool True if package appears to be installed.
		 */
		private static function check_package_installed( $package ) {
			// Check for base vendor copies (chart.js, vectorizer).
			if ( 'chart.js' === $package ) {
				return file_exists( WP_MCP_AI_PATH . 'assets/js/vendor/chart.min.js' );
			}
			if ( '@neplex/vectorizer' === $package ) {
				return file_exists( WP_MCP_AI_PATH . 'assets/js/vendor/neplex-vectorizer/' );
			}

			// ===================================================================
			// PRIORITY 1: Check Pro addon packages in vendor directory FIRST
			// This is the production deployment - check BEFORE dev fallbacks
			// ===================================================================
			$pro_vendor_packages = array(
				// Original packages (Phase 1).
				'@turf/turf'                        => 'turf/dist/esm/index.js',
				'@types/pdfkit'                     => false, // TypeScript types only, no runtime file.
				'fluent-ffmpeg'                     => 'fluent-ffmpeg/index.js',
				'ics'                               => 'ics/index.js',
				'katex'                             => 'katex/dist/katex.min.js',
				'mjml'                              => 'mjml/lib/index.js',
				'prettier'                          => 'prettier/standalone.js',
				'sharp'                             => 'sharp/lib/index.js',
				// E-commerce Toolkit packages (Phase 2).
				'@woocommerce/woocommerce-rest-api' => 'woocommerce-rest-api/index.js',
				'stripe'                            => 'stripe/cjs/stripe.core.js',
				'currency.js'                       => 'currency.js/currency.min.js',
				// Social Media Toolkit packages (Phase 2).
				'twitter-api-v2'                    => 'twitter-api-v2/dist/cjs/index.js',
				'axios'                             => 'axios/dist/axios.js',
				'facebook-nodejs-business-sdk'      => 'facebook-nodejs-business-sdk/dist/cjs.js',
				'linkedin-api-client'               => 'linkedin-api-client/dist/lib/auth.js',
				// Analytics Toolkit packages (Phase 2).
				'd3'                                => 'd3/dist/d3.min.js',
				'mathjs'                            => 'mathjs/lib/cjs/index.js',
				'regression'                        => 'regression/regression.min.js',
				'fast-csv'                          => 'fast-csv/build/src/index.js',
				// Multilingual Toolkit packages (Phase 2).
				'i18next'                           => 'i18next/dist/cjs/i18next.js',
				'franc'                             => 'franc/index.js',
				'google-translate-api-x'            => 'google-translate-api-x/index.cjs',
				'iso-639-1'                         => 'iso-639-1/index.js',
				// Video Production Toolkit packages (Phase 2).
				'ffmpeg-static'                     => 'ffmpeg-static/index.js',
				'ffprobe-static'                    => 'ffprobe-static/index.js',
				'gif-encoder'                       => 'gif-encoder/lib/GIFEncoder.js',
				'video-stitch'                      => 'video-stitch/index.js',
				'subtitle'                          => 'subtitle/dist/index.js',
				// CRM & Email Marketing Toolkit packages (Phase 2).
				'nodemailer'                        => 'nodemailer/lib/nodemailer.js',
				'validator'                         => 'validator/index.js',
				'email-validator'                   => 'email-validator/index.js',
				'libphonenumber-js'                 => 'libphonenumber-js/index.js',
				'mailparser'                        => 'mailparser/lib/mail-parser.js',
				'csv-parse'                         => 'csv-parse/lib/index.js',
				'csv-stringify'                     => 'csv-stringify/lib/index.js',
				'ical-generator'                    => 'ical-generator/dist/index.js',
			);
			if ( isset( $pro_vendor_packages[ $package ] ) && defined( 'WP_MCP_AI_PRO_PATH' ) ) {
				// @types packages don't have runtime files.
				if ( false === $pro_vendor_packages[ $package ] ) {
					return true; // TypeScript type definitions are always available.
				}
				$vendor_path = WP_MCP_AI_PRO_PATH . 'assets/vendor/' . $pro_vendor_packages[ $package ];
				if ( file_exists( $vendor_path ) ) {
					return true;
				}
			}

			// ===================================================================
			// PRIORITY 2: Check for packages bundled into built files
			// These are also production assets (chat-bundle, document bundles)
			// ===================================================================

			// Check for packages bundled into chat-bundle.min.js via esbuild.
			$bundled_packages = array(
				'@microsoft/fetch-event-source',
				'dompurify',
				'marked',
				'ky',
			);
			if ( in_array( $package, $bundled_packages, true ) ) {
				return file_exists( WP_MCP_AI_PATH . 'assets/js/chat-bundle.min.js' );
			}

			// Check for document generation packages bundled into local scripts.
			$script_bundled_packages = array(
				'pdfkit'  => 'generate-pdf.bundle.js',
				'docx'    => 'generate-word.bundle.js',
				'exceljs' => 'generate-excel.bundle.js',
			);
			if ( isset( $script_bundled_packages[ $package ] ) && defined( 'WP_MCP_AI_PRO_PATH' ) ) {
				$script_path = WP_MCP_AI_PRO_PATH . 'bin/' . $script_bundled_packages[ $package ];
				if ( file_exists( $script_path ) ) {
					return true;
				}
			}

			// Check for research packages bundled into research-bundle.min.js.
			// These packages (cheerio, turndown) are in Pro addon's node_modules.
			$research_bundled_packages = array(
				'cheerio',
				'turndown',
			);
			if ( in_array( $package, $research_bundled_packages, true ) && defined( 'WP_MCP_AI_PRO_PATH' ) ) {
				// Priority 1: Check bundled file (production).
				$research_bundle_path = WP_MCP_AI_PRO_PATH . 'assets/js/research-bundle.min.js';
				if ( file_exists( $research_bundle_path ) ) {
					return true;
				}
				// Priority 2: Fallback to Pro addon's node_modules (development).
				$pro_node_modules_path = WP_MCP_AI_PRO_PATH . 'node_modules/' . $package;
				if ( file_exists( $pro_node_modules_path ) ) {
					return true;
				}
				// These packages are not in base, so return false if not found.
				return false;
			}

			// Check for orchestration packages bundled into orchestration-bundle.min.js.
			// These packages (p-queue) are in Pro addon's node_modules.
			$orchestration_bundled_packages = array(
				'p-queue', // Promise queue with concurrency control.
			);
			if ( in_array( $package, $orchestration_bundled_packages, true ) && defined( 'WP_MCP_AI_PRO_PATH' ) ) {
				// Priority 1: Check bundled file (production).
				$orchestration_bundle_path = WP_MCP_AI_PRO_PATH . 'assets/js/orchestration-bundle.min.js';
				if ( file_exists( $orchestration_bundle_path ) ) {
					return true;
				}
				// Priority 2: Fallback to Pro addon's node_modules (development).
				$pro_node_modules_path = WP_MCP_AI_PRO_PATH . 'node_modules/' . $package;
				if ( file_exists( $pro_node_modules_path ) ) {
					return true;
				}
				// These packages are not in base, so return false if not found.
				return false;
			}

			// Check for client-side WebLLM (loaded via CDN, not bundled).
			// This package is used in embedded-llm-client.js which loads it from CDN at runtime.
			if ( '@mlc-ai/web-llm' === $package ) {
				// Check if the client-side embedded LLM JavaScript file exists.
				$embedded_client_path = WP_MCP_AI_PATH . 'assets/js/embedded-llm-client.js';
				return file_exists( $embedded_client_path );
			}

			// Check for qrcode package (Pro addon).
			// This package should be bundled in Pro addon vendor directory.
			if ( 'qrcode' === $package && defined( 'WP_MCP_AI_PRO_PATH' ) ) {
				// Priority 1: Check if it's in Pro addon's vendor directory (production).
				$qrcode_vendor_path = WP_MCP_AI_PRO_PATH . 'assets/vendor/qrcode/lib/index.js';
				if ( file_exists( $qrcode_vendor_path ) ) {
					return true;
				}

				// Priority 2: Check if it's bundled into a Pro script.
				// QR code might be bundled into a specific tool bundle.
				$qrcode_bundle_path = WP_MCP_AI_PRO_PATH . 'bin/generate-qrcode.bundle.js';
				if ( file_exists( $qrcode_bundle_path ) ) {
					return true;
				}

				// Priority 3: Check Pro addon's node_modules (development only).
				$qrcode_node_path = WP_MCP_AI_PRO_PATH . 'node_modules/qrcode';
				if ( file_exists( $qrcode_node_path ) ) {
					return true;
				}

				// If Pro addon is active, assume qrcode is available.
				// This is more lenient for production deployments.
				return defined( 'WP_MCP_AI_PRO_VERSION' );
			}

			// ===================================================================
			// PRIORITY 3: Fallback to node_modules (DEVELOPMENT ONLY)
			// Only check these if vendor files are not found
			// ===================================================================

			// Fallback: Check Pro node_modules (for development).
			if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
				$pro_node_modules_path = WP_MCP_AI_PRO_PATH . 'node_modules/' . $package;
				if ( file_exists( $pro_node_modules_path ) ) {
					return true;
				}
			}

			// Check base node_modules (if present).
			$node_modules_path = WP_MCP_AI_PATH . 'node_modules/' . $package;
			return file_exists( $node_modules_path );
		}

		/**
		 * Render pro toolkit status section.
		 *
		 * @param array $status Pro toolkit status.
		 * @return void
		 */
		private static function render_pro_toolkit_status( $status ) {
			?>
			<h3><?php esc_html_e( 'Pro Toolkit Status', 'mcp-ai-wpoos' ); ?></h3>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width: 40%;"><?php esc_html_e( 'Feature', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Plugin Version', 'mcp-ai-wpoos' ); ?></strong></td>
						<td><code><?php echo esc_html( $status['plugin_version'] ); ?></code></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Pro Dashboard', 'mcp-ai-wpoos' ); ?></strong></td>
						<td>
							<span class="wp-mcp-ai-status-badge <?php echo esc_attr( $status['pro_dashboard_enabled'] ? 'enabled' : 'disabled' ); ?>">
								<?php echo $status['pro_dashboard_enabled'] ? esc_html__( 'Enabled', 'mcp-ai-wpoos' ) : esc_html__( 'Disabled', 'mcp-ai-wpoos' ); ?>
							</span>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Base Version Mode', 'mcp-ai-wpoos' ); ?></strong></td>
						<td>
							<span class="wp-mcp-ai-status-badge <?php echo esc_attr( $status['base_version'] ? 'active' : 'inactive' ); ?>">
								<?php echo $status['base_version'] ? esc_html__( 'Active (165 base tools)', 'mcp-ai-wpoos' ) : esc_html__( 'Inactive (519 total tools)', 'mcp-ai-wpoos' ); ?>
							</span>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Debug Mode', 'mcp-ai-wpoos' ); ?></strong></td>
						<td>
							<span class="wp-mcp-ai-status-badge <?php echo esc_attr( $status['debug_mode'] ? 'enabled' : 'disabled' ); ?>">
								<?php echo $status['debug_mode'] ? esc_html__( 'Enabled', 'mcp-ai-wpoos' ) : esc_html__( 'Disabled', 'mcp-ai-wpoos' ); ?>
							</span>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'PHP Version', 'mcp-ai-wpoos' ); ?></strong></td>
						<td><code><?php echo esc_html( $status['php_version'] ); ?></code></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'WordPress Version', 'mcp-ai-wpoos' ); ?></strong></td>
						<td><code><?php echo esc_html( $status['wp_version'] ); ?></code></td>
					</tr>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'Optional Integrations', 'mcp-ai-wpoos' ); ?></h3>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width: 40%;"><?php esc_html_e( 'Integration', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					// Integration display names.
					$integration_names = array(
						'jetengine'      => __( 'JetEngine', 'mcp-ai-wpoos' ),
						'jetformbuilder' => __( 'JetFormBuilder', 'mcp-ai-wpoos' ),
						'woocommerce'    => __( 'WooCommerce', 'mcp-ai-wpoos' ),
						'elementor'      => __( 'Elementor', 'mcp-ai-wpoos' ),
						'rankmath'       => __( 'Rank Math', 'mcp-ai-wpoos' ),
						'wpcode'         => __( 'WPCode', 'mcp-ai-wpoos' ),
						'newsletter'     => __( 'Newsletter', 'mcp-ai-wpoos' ),
						'wpallimport'    => __( 'WP All Import', 'mcp-ai-wpoos' ),
						'wpallexport'    => __( 'WP All Export', 'mcp-ai-wpoos' ),
					);

					foreach ( $status['integrations'] as $integration => $is_active ) :
						$display_name = isset( $integration_names[ $integration ] ) ? $integration_names[ $integration ] : ucfirst( $integration );
						?>
						<tr>
							<td><strong><?php echo esc_html( $display_name ); ?></strong></td>
							<td>
								<span class="wp-mcp-ai-status-badge <?php echo esc_attr( $is_active ? 'active' : 'inactive' ); ?>">
									<?php echo $is_active ? esc_html__( 'Active', 'mcp-ai-wpoos' ) : esc_html__( 'Inactive', 'mcp-ai-wpoos' ); ?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}

		/**
		 * Render individual pro toolkits status section.
		 *
		 * @param array $toolkit_status Individual toolkit status.
		 * @return void
		 */
		private static function render_individual_toolkits_status( $toolkit_status ) {
			?>
			<h3><?php esc_html_e( 'Individual Pro Toolkits', 'mcp-ai-wpoos' ); ?></h3>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width: 40%;"><?php esc_html_e( 'Toolkit Name', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $toolkit_status as $setting_key => $toolkit_info ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $toolkit_info['name'] ); ?></strong></td>
							<td>
								<span class="wp-mcp-ai-status-badge <?php echo esc_attr( $toolkit_info['enabled'] ? 'enabled' : 'disabled' ); ?>">
									<?php echo $toolkit_info['enabled'] ? esc_html__( 'Enabled', 'mcp-ai-wpoos' ) : esc_html__( 'Disabled', 'mcp-ai-wpoos' ); ?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}

		/**
		 * Render the Pro Settings page.
		 *
		 * @return void
		 */
		public static function render_page() {
			// Verify user capability.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos' ) );
			}

			$packages        = self::get_npm_packages();
			$pro_status      = self::get_pro_toolkit_status();
			$toolkit_status  = self::get_individual_toolkit_status();
			$toolkit_details = self::get_toolkit_details();
			$total_packages  = count( $packages['dependencies'] ) + count( $packages['devDependencies'] );
			?>
			<div class="wrap wp-mcp-ai-pro-settings">
				<h1>
					<span class="dashicons dashicons-admin-settings"></span>
					<?php esc_html_e( 'Pro Settings & System Information', 'mcp-ai-wpoos' ); ?>
				</h1>

				<p class="description">
					<?php esc_html_e( 'View npm package status, pro toolkit configuration, and system information. This is a read-only display for monitoring your NV oOS installation.', 'mcp-ai-wpoos' ); ?>
				</p>

				<?php if ( isset( $packages['error'] ) ) : ?>
					<div class="notice notice-info">
						<p>
							<strong><?php esc_html_e( 'NPM Package Information:', 'mcp-ai-wpoos' ); ?></strong>
							<?php esc_html_e( 'Not available in this installation (package.json not found). This is normal for WordPress.org and production builds where development files are excluded.', 'mcp-ai-wpoos' ); ?>
						</p>
						<p style="margin: 5px 0 0 0;">
							<em><?php esc_html_e( 'Your plugin is fully functional. NPM package information is only needed for development purposes.', 'mcp-ai-wpoos' ); ?></em>
						</p>
					</div>
				<?php else : ?>
					<div class="notice notice-info">
						<p>
							<strong><?php esc_html_e( 'Project:', 'mcp-ai-wpoos' ); ?></strong> <?php echo esc_html( $packages['name'] ); ?> 
							<strong><?php esc_html_e( 'Version:', 'mcp-ai-wpoos' ); ?></strong> <?php echo esc_html( $packages['version'] ); ?>
							<strong style="margin-left: 20px;"><?php esc_html_e( 'Total NPM Packages:', 'mcp-ai-wpoos' ); ?></strong> <?php echo absint( $total_packages ); ?>
						</p>
					</div>
				<?php endif; ?>

				<div class="wp-mcp-ai-settings-columns">
					<!-- Pro Toolkit Status -->
					<div class="wp-mcp-ai-settings-column">
						<div class="wp-mcp-ai-settings-card">
							<?php self::render_pro_toolkit_status( $pro_status ); ?>
							
							<div style="margin-top: 30px;"></div>
							
							<?php self::render_individual_toolkits_status( $toolkit_status ); ?>
						</div>
					</div>

					<!-- NPM Packages -->
					<div class="wp-mcp-ai-settings-column">
						<div class="wp-mcp-ai-settings-card">
							<h2><?php esc_html_e( 'NPM Packages', 'mcp-ai-wpoos' ); ?></h2>

							<?php if ( ! isset( $packages['error'] ) ) : ?>
								<?php self::render_packages_table( $packages['dependencies'], __( 'Production Dependencies', 'mcp-ai-wpoos' ) ); ?>
								
								<div style="margin-top: 30px;"></div>
								
								<?php self::render_packages_table( $packages['devDependencies'], __( 'Development Dependencies', 'mcp-ai-wpoos' ) ); ?>

								<div style="margin-top: 20px; padding: 15px; background: #f0f0f1; border-left: 4px solid #72aee6;">
									<p style="margin: 0;">
										<strong><?php esc_html_e( 'Note:', 'mcp-ai-wpoos' ); ?></strong>
										<?php esc_html_e( 'Package status is determined by checking for vendor files. Some packages may be installed in node_modules but not visible here after deployment.', 'mcp-ai-wpoos' ); ?>
									</p>
								</div>
							<?php else : ?>
								<div style="padding: 20px; background: #f0f0f1; border-left: 4px solid #72aee6; text-align: center;">
									<p style="margin: 0 0 10px 0;">
										<span class="dashicons dashicons-info" style="font-size: 48px; width: 48px; height: 48px; color: #72aee6;"></span>
									</p>
									<p style="margin: 0 0 5px 0; font-weight: 600;">
										<?php esc_html_e( 'NPM Package Information Not Available', 'mcp-ai-wpoos' ); ?>
									</p>
									<p style="margin: 0; color: #666;">
										<?php esc_html_e( 'This is normal for WordPress.org and production builds.', 'mcp-ai-wpoos' ); ?>
									</p>
									<p style="margin: 10px 0 0 0; font-size: 12px; color: #666;">
										<?php esc_html_e( 'Your plugin functionality is not affected. Package information is only for development reference.', 'mcp-ai-wpoos' ); ?>
									</p>
								</div>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<!-- Toolkit Details Section -->
				<div style="margin-top: 30px;">
					<h2><?php esc_html_e( 'Comprehensive Toolkit Information', 'mcp-ai-wpoos' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Detailed view of each toolkit including status, dependencies, and tools.', 'mcp-ai-wpoos' ); ?>
					</p>
					
					<div class="wp-mcp-ai-toolkit-grid">
						<?php foreach ( $toolkit_details as $toolkit_id => $toolkit ) : ?>
							<?php self::render_toolkit_card( $toolkit_id, $toolkit ); ?>
						<?php endforeach; ?>
					</div>
				</div>


<!-- Embedded LLM (Client-Side) Section -->
<div style="margin-top: 30px;">
			<?php self::render_embedded_llm_section(); ?>
</div>

<!-- NPM Production: WebGPU/WebAssembly Section -->
<div style="margin-top: 30px;">
			<?php self::render_webgpu_webassembly_section(); ?>
</div>

<!-- Visual Workflow Builder Card -->
<div style="margin-top: 30px;">
			<?php self::render_visual_workflow_builder_card(); ?>
</div>

<!-- Pro Toolkit Features Card -->
<div style="margin-top: 30px;">
			<?php self::render_pro_toolkit_features_card(); ?>
</div>

				<div style="margin-top: 20px; padding: 15px; background: #fff; border: 1px solid #c3c4c7;">
					<h3><?php esc_html_e( 'About This Page', 'mcp-ai-wpoos' ); ?></h3>
					<p>
						<?php esc_html_e( 'This page provides a centralized view of your NV oOS Pro installation. It displays:', 'mcp-ai-wpoos' ); ?>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'NPM package versions from package.json (read-only)', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Pro Dashboard and feature flags status', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Optional integration status (JetEngine, WooCommerce, etc.)', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'System information (PHP, WordPress versions)', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Comprehensive toolkit details with dependencies and requirements', 'mcp-ai-wpoos' ); ?></li>
					</ul>
					<p>
						<em><?php esc_html_e( 'This is a lightweight, read-only display. No package management functionality is included to keep the plugin size minimal.', 'mcp-ai-wpoos' ); ?></em>
					</p>
				</div>
			</div>

			<?php
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Small inline styles for pro settings page layout and styling on this admin page only
			?>
			<style>
				.wp-mcp-ai-pro-settings h1 .dashicons {
					font-size: 30px;
					width: 30px;
					height: 30px;
					vertical-align: middle;
					margin-right: 8px;
					color: #2271b1;
				}

				.wp-mcp-ai-settings-columns {
					display: grid;
					grid-template-columns: 1fr 1fr;
					gap: 20px;
					margin-top: 20px;
				}

				@media (max-width: 1280px) {
					.wp-mcp-ai-settings-columns {
						grid-template-columns: 1fr;
					}
				}

				.wp-mcp-ai-settings-card {
					background: #fff;
					border: 1px solid #c3c4c7;
					padding: 20px;
					box-shadow: 0 1px 1px rgba(0,0,0,.04);
				}

				.wp-mcp-ai-settings-card h2 {
					margin-top: 0;
					padding-bottom: 10px;
					border-bottom: 1px solid #c3c4c7;
				}

				.wp-mcp-ai-settings-card h3 {
					margin-top: 20px;
					margin-bottom: 10px;
					font-size: 14px;
					font-weight: 600;
					color: #1d2327;
				}

				.wp-mcp-ai-settings-card h3 .count {
					color: #646970;
					font-weight: 400;
				}

				.wp-mcp-ai-status-badge {
					display: inline-block;
					padding: 3px 10px;
					border-radius: 3px;
					font-size: 12px;
					font-weight: 600;
					text-transform: uppercase;
				}

				.wp-mcp-ai-status-badge.installed,
				.wp-mcp-ai-status-badge.enabled,
				.wp-mcp-ai-status-badge.active {
					background: #00a32a;
					color: #fff;
				}

				.wp-mcp-ai-status-badge.not-installed,
				.wp-mcp-ai-status-badge.disabled,
				.wp-mcp-ai-status-badge.inactive {
					background: #dba617;
					color: #fff;
				}

				.wp-mcp-ai-pro-settings .wp-list-table {
					margin-top: 10px;
				}

				.wp-mcp-ai-pro-settings code {
					background: #f0f0f1;
					padding: 2px 6px;
					border-radius: 3px;
					font-size: 13px;
				}

				/* Toolkit Grid */
				.wp-mcp-ai-toolkit-grid {
					display: grid;
					grid-template-columns: repeat(auto-fill, minmax(450px, 1fr));
					gap: 20px;
					margin-top: 20px;
				}

				@media (max-width: 768px) {
					.wp-mcp-ai-toolkit-grid {
						grid-template-columns: 1fr;
					}
				}

				/* Toolkit Cards */
				.wp-mcp-ai-toolkit-card {
					background: #fff;
					border: 1px solid #c3c4c7;
					padding: 20px;
					box-shadow: 0 1px 1px rgba(0,0,0,.04);
					border-radius: 4px;
				}

				.wp-mcp-ai-toolkit-card .toolkit-header h3 {
					margin: 0 0 10px 0;
					font-size: 16px;
					font-weight: 600;
					display: flex;
					align-items: center;
					gap: 8px;
					flex-wrap: wrap;
				}

				.wp-mcp-ai-toolkit-card .toolkit-description {
					margin: 0 0 15px 0;
					color: #646970;
					font-size: 14px;
				}

				/* Toolkit Status Badges */
				.toolkit-status-badge {
					display: inline-block;
					padding: 3px 10px;
					border-radius: 3px;
					font-size: 11px;
					font-weight: 600;
					text-transform: uppercase;
				}

				.toolkit-status-badge.operational {
					background: #00a32a;
					color: #fff;
				}

				.toolkit-status-badge.partial {
					background: #d63638;
					color: #fff;
				}

				.toolkit-status-badge.disabled {
					background: #646970;
					color: #fff;
				}

				/* Category Badges */
				.toolkit-category-badge {
					display: inline-block;
					padding: 3px 10px;
					border-radius: 3px;
					font-size: 11px;
					font-weight: 600;
					text-transform: uppercase;
				}

				.toolkit-category-badge.core {
					background: #2271b1;
					color: #fff;
				}

				.toolkit-category-badge.specialized {
					background: #8c7ae6;
					color: #fff;
				}

				.toolkit-category-badge.infrastructure {
					background: #50e3c2;
					color: #000;
				}

				/* Toolkit Warning */
				.toolkit-warning {
					background: #fcf0f1;
					border-left: 4px solid #d63638;
					padding: 12px;
					margin-bottom: 15px;
					display: flex;
					align-items: center;
					gap: 8px;
					font-size: 13px;
				}

				.toolkit-warning .dashicons {
					color: #d63638;
					flex-shrink: 0;
				}

				/* Toolkit Sections (Collapsible) */
				.toolkit-section {
					margin: 15px 0;
					border: 1px solid #e0e0e0;
					border-radius: 4px;
					overflow: hidden;
				}

				.toolkit-section summary {
					padding: 12px 15px;
					background: #f6f7f7;
					cursor: pointer;
					font-weight: 600;
					font-size: 13px;
					display: flex;
					justify-content: space-between;
					align-items: center;
					user-select: none;
				}

				.toolkit-section summary:hover {
					background: #e9eaeb;
				}

				.toolkit-section[open] summary {
					border-bottom: 1px solid #e0e0e0;
				}

				.toolkit-section .section-badge {
					display: inline-block;
					padding: 2px 8px;
					border-radius: 3px;
					font-size: 11px;
					font-weight: 600;
					text-transform: uppercase;
				}

				.toolkit-section .section-badge.ok {
					background: #00a32a;
					color: #fff;
				}

				.toolkit-section .section-badge.error {
					background: #d63638;
					color: #fff;
				}

				.toolkit-section .tools-count {
					color: #646970;
					font-weight: 400;
				}

				/* Toolkit Details Tables */
				.toolkit-details-table {
					width: 100%;
					border-collapse: collapse;
					margin: 0;
				}

				.toolkit-details-table thead th {
					background: #f9f9f9;
					padding: 10px 15px;
					text-align: left;
					font-size: 12px;
					font-weight: 600;
					border-bottom: 1px solid #e0e0e0;
				}

				.toolkit-details-table tbody td {
					padding: 10px 15px;
					border-bottom: 1px solid #f0f0f1;
					font-size: 13px;
				}

				.toolkit-details-table tbody tr:last-child td {
					border-bottom: none;
				}

				.toolkit-details-table .status-indicator {
					font-size: 16px;
					font-weight: bold;
				}

				.toolkit-details-table .status-indicator.available {
					color: #00a32a;
				}

				.toolkit-details-table .status-indicator.unavailable {
					color: #d63638;
				}

				/* Toolkit Tools List */
				.toolkit-tools-list {
					margin: 0;
					padding: 15px 15px 15px 40px;
					list-style: disc;
				}

				.toolkit-tools-list li {
					padding: 5px 0;
					font-size: 13px;
					color: #646970;
				}
			</style>
			<?php
		}

		/**
		 * Add Pro Settings page to Pro Dashboard menu.
		 *
		 * @return void
		 */
		/**
		 * Render Embedded LLM (Client-Side) section.
		 *
		 * @return void
		 */
		private static function render_embedded_llm_section() {
			// Get embedded LLM models.
			// All available models are listed. Some models support function calling, others are for general chat.
			$models = array(
				'Hermes-2-Pro-Llama-3-8B-q4f16_1-MLC' => array(
					'name'        => 'Hermes 2 Pro Llama 3 8B',
					'size'        => '~4.5GB',
					'description' => 'Best for function calling and tool use',
					'context'     => '8K tokens',
					'license'     => 'Apache 2.0',
					'recommended' => true,
				),
				'Qwen2.5-7B-Instruct-q4f16_1-MLC'     => array(
					'name'        => 'Qwen2.5 7B Instruct',
					'size'        => '~4.5GB',
					'description' => 'Advanced multilingual model with function calling',
					'context'     => '32K tokens',
					'license'     => 'Apache 2.0',
					'recommended' => false,
				),
				'Phi-3.5-mini-instruct-q4f16_1-MLC'   => array(
					'name'        => 'Phi-3.5 Mini Instruct',
					'size'        => '~2.5GB',
					'description' => 'Smaller Microsoft model, supports function calling',
					'context'     => '128K tokens',
					'license'     => 'MIT',
					'recommended' => false,
				),
				'Llama-3.2-3B-Instruct-q4f16_1-MLC'   => array(
					'name'        => 'Llama 3.2 3B Instruct',
					'size'        => '~2GB',
					'description' => 'Balanced model for general chat (does not support function calling)',
					'context'     => '128K tokens',
					'license'     => 'Llama 3.2 Community License',
					'recommended' => false,
				),
				'Qwen2.5-1.5B-Instruct-q4f16_1-MLC'   => array(
					'name'        => 'Qwen2.5 1.5B Instruct',
					'size'        => '~1GB',
					'description' => 'Compact multilingual model with function calling support',
					'context'     => '32K tokens',
					'license'     => 'Apache 2.0',
					'recommended' => false,
				),
				'Llama-3.2-1B-Instruct-q4f16_1-MLC'   => array(
					'name'        => 'Llama 3.2 1B Instruct',
					'size'        => '~800MB',
					'description' => 'Fast, lightweight model for basic chat (does not support function calling)',
					'context'     => '128K tokens',
					'license'     => 'Llama 3.2 Community License',
					'recommended' => false,
				),
				'Qwen2.5-0.5B-Instruct-q4f16_1-MLC'   => array(
					'name'        => 'Qwen2.5 0.5B Instruct',
					'size'        => '~400MB',
					'description' => 'Ultra-compact model for simple responses (does not support function calling)',
					'context'     => '32K tokens',
					'license'     => 'Apache 2.0',
					'recommended' => false,
				),
			);
			?>
<div class="wp-mcp-ai-settings-card">
<h2>
<span class="dashicons dashicons-smartphone"></span>
			<?php esc_html_e( 'Embedded LLM (Client-Side) - Pro Feature', 'mcp-ai-wpoos' ); ?>
</h2>

<div class="notice notice-success inline" style="margin: 15px 0;">
<p>
<strong><?php esc_html_e( '✓ Everything Pre-Packaged', 'mcp-ai-wpoos' ); ?></strong><br>
			<?php esc_html_e( 'Client-side LLM inference using WebLLM. No server installation required. Runs in user browser with WebGPU/WebAssembly.', 'mcp-ai-wpoos' ); ?>
</p>
</div>

<div style="margin: 20px 0;">
<h3><?php esc_html_e( 'NPM Dependencies', 'mcp-ai-wpoos' ); ?></h3>
<table class="wp-list-table widefat fixed striped" style="max-width: 800px;">
<thead>
<tr>
<th style="width: 40%;"><?php esc_html_e( 'Package', 'mcp-ai-wpoos' ); ?></th>
<th style="width: 20%;"><?php esc_html_e( 'Version', 'mcp-ai-wpoos' ); ?></th>
<th style="width: 40%;"><?php esc_html_e( 'Purpose', 'mcp-ai-wpoos' ); ?></th>
</tr>
</thead>
<tbody>
<tr>
<td><code>@mlc-ai/web-llm</code></td>
<td>^0.2.80</td>
<td><?php esc_html_e( 'Core WebLLM library for browser LLM inference', 'mcp-ai-wpoos' ); ?></td>
</tr>
</tbody>
</table>
</div>

<div style="margin: 20px 0;">
<h3><?php esc_html_e( 'Available Models', 'mcp-ai-wpoos' ); ?></h3>
<p class="description">
			<?php esc_html_e( 'All models are pre-configured and download automatically to browser cache when first used.', 'mcp-ai-wpoos' ); ?>
</p>

<div class="wp-mcp-ai-model-cards" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; margin-top: 15px;">
			<?php foreach ( $models as $model_id => $model ) : ?>
<div class="wp-mcp-ai-model-card" style="border: 1px solid <?php echo esc_attr( $model['recommended'] ? '#00a32a' : '#c3c4c7' ); ?>; padding: 15px; background: #fff; border-radius: 4px;">
<div style="display: flex; align-items: start; justify-content: space-between; margin-bottom: 10px;">
<h4 style="margin: 0; font-size: 14px;">
				<?php echo esc_html( $model['name'] ); ?>
				<?php if ( $model['recommended'] ) : ?>
<span class="dashicons dashicons-star-filled" style="color: #f0b849; font-size: 16px;" title="<?php esc_attr_e( 'Recommended', 'mcp-ai-wpoos' ); ?>"></span>
<?php endif; ?>
</h4>
<span style="background: #f0f0f1; padding: 3px 8px; border-radius: 3px; font-size: 12px; font-weight: bold;">
				<?php echo esc_html( $model['size'] ); ?>
</span>
</div>

<p style="margin: 10px 0; font-size: 13px; color: #50575e;">
				<?php echo esc_html( $model['description'] ); ?>
</p>

<div style="display: flex; gap: 15px; margin-top: 10px; font-size: 12px; color: #646970;">
<div>
<strong><?php esc_html_e( 'Context:', 'mcp-ai-wpoos' ); ?></strong>
				<?php echo esc_html( $model['context'] ); ?>
</div>
<div>
<strong><?php esc_html_e( 'License:', 'mcp-ai-wpoos' ); ?></strong>
				<?php echo esc_html( $model['license'] ); ?>
</div>
</div>

<div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #f0f0f1;">
<code style="font-size: 11px; background: #f6f7f7; padding: 4px 6px; display: block; word-break: break-all;">
				<?php echo esc_html( $model_id ); ?>
</code>
</div>
</div>
<?php endforeach; ?>
</div>
</div>

<div style="margin: 20px 0; padding: 15px; background: #f0f6fc; border-left: 4px solid #0073aa;">
<h4 style="margin-top: 0;"><?php esc_html_e( 'Browser Requirements', 'mcp-ai-wpoos' ); ?></h4>
<ul style="margin: 10px 0 0 20px;">
<li><?php esc_html_e( 'WebGPU support (Chrome 113+, Edge 113+, Safari 18+)', 'mcp-ai-wpoos' ); ?></li>
<li><?php esc_html_e( 'Automatic fallback to WebAssembly (CPU) if GPU unavailable', 'mcp-ai-wpoos' ); ?></li>
<li><?php esc_html_e( 'Models cached in browser IndexedDB (first load takes time, subsequent loads instant)', 'mcp-ai-wpoos' ); ?></li>
<li><?php esc_html_e( 'No server resources used - all inference runs in user browser', 'mcp-ai-wpoos' ); ?></li>
</ul>
</div>

<div style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #c3c4c7;">
<h4 style="margin-top: 0;"><?php esc_html_e( 'How to Enable', 'mcp-ai-wpoos' ); ?></h4>
<ol style="margin: 10px 0 0 20px;">
<li>
			<?php
			printf(
			/* translators: %s: settings page URL */
				esc_html__( 'Go to %s', 'mcp-ai-wpoos' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=providers&subtab=embedded' ) ) . '"><strong>' . esc_html__( 'Settings → Providers → Embedded LLM', 'mcp-ai-wpoos' ) . '</strong></a>'
			);
			?>
</li>
<li><?php esc_html_e( 'Check "Enable client-side embedded language models (Pro)"', 'mcp-ai-wpoos' ); ?></li>
<li><?php esc_html_e( 'Select a default model (Llama 3.2 1B recommended)', 'mcp-ai-wpoos' ); ?></li>
<li><?php esc_html_e( 'Save settings', 'mcp-ai-wpoos' ); ?></li>
<li><?php esc_html_e( 'When users chat with assistants using "embedded" provider, models download automatically to their browser', 'mcp-ai-wpoos' ); ?></li>
</ol>
</div>
</div>
			<?php
		}

		/**
		 * Render WebGPU/WebAssembly NPM Production section.
		 *
		 * Displays production-ready NPM packages for WebGPU/WebAssembly deployment.
		 *
		 * @since 1.1.0
		 * @return void
		 */
		private static function render_webgpu_webassembly_section() {
			?>
<div class="wp-mcp-ai-settings-card">
	<h2>
		<span class="dashicons dashicons-performance"></span>
			<?php esc_html_e( 'NPM Production: WebGPU/WebAssembly', 'mcp-ai-wpoos' ); ?>
	</h2>

	<div class="notice notice-info inline" style="margin: 15px 0;">
		<p>
			<strong><?php esc_html_e( 'Production-Ready Browser AI', 'mcp-ai-wpoos' ); ?></strong><br>
			<?php esc_html_e( 'Leverage cutting-edge WebGPU and WebAssembly technologies for high-performance AI inference directly in the browser. No server resources required.', 'mcp-ai-wpoos' ); ?>
		</p>
	</div>

	<div style="margin: 20px 0;">
		<h3><?php esc_html_e( 'Core NPM Package', 'mcp-ai-wpoos' ); ?></h3>
		<table class="wp-list-table widefat fixed striped" style="max-width: 900px;">
			<thead>
				<tr>
					<th style="width: 30%;"><?php esc_html_e( 'Package', 'mcp-ai-wpoos' ); ?></th>
					<th style="width: 15%;"><?php esc_html_e( 'Version', 'mcp-ai-wpoos' ); ?></th>
					<th style="width: 55%;"><?php esc_html_e( 'Description', 'mcp-ai-wpoos' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>@mlc-ai/web-llm</code></td>
					<td><code>^0.2.80</code></td>
					<td><?php esc_html_e( 'High-performance browser-based LLM inference using WebGPU with automatic WebAssembly fallback', 'mcp-ai-wpoos' ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>

	<div style="margin: 20px 0;">
		<h3><?php esc_html_e( 'Technology Stack', 'mcp-ai-wpoos' ); ?></h3>
		<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
			<!-- WebGPU Card -->
			<div style="border: 1px solid #72aee6; padding: 15px; background: #f0f6fc; border-radius: 4px;">
				<h4 style="margin: 0 0 10px 0; color: #0073aa;">
					<span class="dashicons dashicons-performance"></span>
					<?php esc_html_e( 'WebGPU (Preferred)', 'mcp-ai-wpoos' ); ?>
				</h4>
				<ul style="margin: 0 0 0 20px; font-size: 13px;">
					<li><?php esc_html_e( 'Hardware-accelerated GPU compute', 'mcp-ai-wpoos' ); ?></li>
					<li><?php esc_html_e( 'Up to 100x faster than CPU', 'mcp-ai-wpoos' ); ?></li>
					<li><?php esc_html_e( 'Chrome 113+, Edge 113+, Safari 18+', 'mcp-ai-wpoos' ); ?></li>
					<li><?php esc_html_e( 'Best for real-time inference', 'mcp-ai-wpoos' ); ?></li>
				</ul>
			</div>

			<!-- WebAssembly Card -->
			<div style="border: 1px solid #c3c4c7; padding: 15px; background: #f6f7f7; border-radius: 4px;">
				<h4 style="margin: 0 0 10px 0; color: #50575e;">
					<span class="dashicons dashicons-admin-generic"></span>
					<?php esc_html_e( 'WebAssembly (Fallback)', 'mcp-ai-wpoos' ); ?>
				</h4>
				<ul style="margin: 0 0 0 20px; font-size: 13px;">
					<li><?php esc_html_e( 'CPU-based computation', 'mcp-ai-wpoos' ); ?></li>
					<li><?php esc_html_e( 'Automatic fallback if no GPU', 'mcp-ai-wpoos' ); ?></li>
					<li><?php esc_html_e( 'Universal browser support', 'mcp-ai-wpoos' ); ?></li>
					<li><?php esc_html_e( 'Slower but reliable', 'mcp-ai-wpoos' ); ?></li>
				</ul>
			</div>
		</div>
	</div>

	<div style="margin: 20px 0; padding: 15px; background: #fff3cd; border-left: 4px solid #f0b849;">
		<h4 style="margin-top: 0;">
			<span class="dashicons dashicons-warning"></span>
			<?php esc_html_e( 'Production Deployment Notes', 'mcp-ai-wpoos' ); ?>
		</h4>
		<ul style="margin: 10px 0 0 20px;">
			<li><?php esc_html_e( 'WebGPU requires HTTPS in production (security requirement)', 'mcp-ai-wpoos' ); ?></li>
			<li><?php esc_html_e( 'Models are cached in browser IndexedDB (persistent across sessions)', 'mcp-ai-wpoos' ); ?></li>
			<li><?php esc_html_e( 'First model download per user takes 30-60s depending on model size', 'mcp-ai-wpoos' ); ?></li>
			<li><?php esc_html_e( 'Subsequent loads are instant (served from cache)', 'mcp-ai-wpoos' ); ?></li>
			<li><?php esc_html_e( 'No server resources consumed - all inference runs client-side', 'mcp-ai-wpoos' ); ?></li>
			<li><?php esc_html_e( 'Fully GDPR compliant - data never leaves user device', 'mcp-ai-wpoos' ); ?></li>
		</ul>
	</div>

	<div style="margin: 20px 0; padding: 15px; background: #d5f4e6; border-left: 4px solid #00a32a;">
		<h4 style="margin-top: 0;">
			<span class="dashicons dashicons-yes-alt"></span>
			<?php esc_html_e( 'Benefits for Production', 'mcp-ai-wpoos' ); ?>
		</h4>
		<ul style="margin: 10px 0 0 20px;">
			<li><strong><?php esc_html_e( 'Zero API Costs:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'No OpenAI/Anthropic charges', 'mcp-ai-wpoos' ); ?></li>
			<li><strong><?php esc_html_e( 'Infinite Scale:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Each user runs inference on their own device', 'mcp-ai-wpoos' ); ?></li>
			<li><strong><?php esc_html_e( 'Privacy-First:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Data never transmitted to servers', 'mcp-ai-wpoos' ); ?></li>
			<li><strong><?php esc_html_e( 'Offline Capable:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Works without internet after initial download', 'mcp-ai-wpoos' ); ?></li>
			<li><strong><?php esc_html_e( 'Low Latency:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'No network round-trips for inference', 'mcp-ai-wpoos' ); ?></li>
		</ul>
	</div>

	<div style="margin: 20px 0;">
		<h3><?php esc_html_e( 'Browser Compatibility Matrix', 'mcp-ai-wpoos' ); ?></h3>
		<table class="wp-list-table widefat fixed striped" style="max-width: 800px;">
			<thead>
				<tr>
					<th style="width: 25%;"><?php esc_html_e( 'Browser', 'mcp-ai-wpoos' ); ?></th>
					<th style="width: 25%;"><?php esc_html_e( 'WebGPU', 'mcp-ai-wpoos' ); ?></th>
					<th style="width: 25%;"><?php esc_html_e( 'WebAssembly', 'mcp-ai-wpoos' ); ?></th>
					<th style="width: 25%;"><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><strong>Chrome 113+</strong></td>
					<td><span style="color: #00a32a;">✓</span></td>
					<td><span style="color: #00a32a;">✓</span></td>
					<td><span class="wp-mcp-ai-status-badge enabled"><?php esc_html_e( 'Fully Supported', 'mcp-ai-wpoos' ); ?></span></td>
				</tr>
				<tr>
					<td><strong>Edge 113+</strong></td>
					<td><span style="color: #00a32a;">✓</span></td>
					<td><span style="color: #00a32a;">✓</span></td>
					<td><span class="wp-mcp-ai-status-badge enabled"><?php esc_html_e( 'Fully Supported', 'mcp-ai-wpoos' ); ?></span></td>
				</tr>
				<tr>
					<td><strong>Safari 18+</strong></td>
					<td><span style="color: #00a32a;">✓</span></td>
					<td><span style="color: #00a32a;">✓</span></td>
					<td><span class="wp-mcp-ai-status-badge enabled"><?php esc_html_e( 'Fully Supported', 'mcp-ai-wpoos' ); ?></span></td>
				</tr>
				<tr>
					<td><strong>Firefox</strong></td>
					<td><span style="color: #f0b849;">⚠</span></td>
					<td><span style="color: #00a32a;">✓</span></td>
					<td><span class="wp-mcp-ai-status-badge active"><?php esc_html_e( 'WASM Only', 'mcp-ai-wpoos' ); ?></span></td>
				</tr>
				<tr>
					<td><strong>Mobile Browsers</strong></td>
					<td><span style="color: #f0b849;">⚠</span></td>
					<td><span style="color: #00a32a;">✓</span></td>
					<td><span class="wp-mcp-ai-status-badge active"><?php esc_html_e( 'WASM Only', 'mcp-ai-wpoos' ); ?></span></td>
				</tr>
			</tbody>
		</table>
	</div>
</div>
			<?php
		}

		/**
		 * Render Visual Workflow Builder card.
		 *
		 * Displays information about the React-based visual workflow builder Pro feature.
		 *
		 * @since 1.2.0
		 * @return void
		 */
		private static function render_visual_workflow_builder_card() {
			?>
<div class="wp-mcp-ai-settings-card">
	<h2>
		<span class="dashicons dashicons-networking" style="color: #2271b1;"></span>
		<?php esc_html_e( 'Visual Workflow Builder', 'mcp-ai-wpoos' ); ?>
		<span class="pro-badge" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: 600; margin-left: 10px; text-transform: uppercase; letter-spacing: 0.5px;">PRO</span>
	</h2>

	<div class="notice notice-info inline" style="margin: 15px 0;">
		<p>
			<strong><?php esc_html_e( 'Modern React-Based Workflow Editor', 'mcp-ai-wpoos' ); ?></strong><br>
			<?php esc_html_e( 'Professional drag-and-drop workflow builder powered by React Flow. Create complex automation workflows visually without writing code.', 'mcp-ai-wpoos' ); ?>
		</p>
	</div>

	<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin: 20px 0;">
		
		<!-- Key Features -->
		<div style="border: 1px solid #2271b1; padding: 15px; background: #f0f6fc; border-radius: 4px;">
			<h3 style="margin: 0 0 15px 0; color: #2271b1;">
				<span class="dashicons dashicons-star-filled"></span>
				<?php esc_html_e( 'Key Features', 'mcp-ai-wpoos' ); ?>
			</h3>
			<ul style="margin: 0; font-size: 13px; line-height: 1.8;">
				<li><strong><?php esc_html_e( 'Visual Canvas:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Zoom, pan, and navigate large workflows', 'mcp-ai-wpoos' ); ?></li>
				<li><strong><?php esc_html_e( 'Drag & Drop:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Intuitive step creation and reordering', 'mcp-ai-wpoos' ); ?></li>
				<li><strong><?php esc_html_e( 'Command Palette:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Searchable library of 250+ commands', 'mcp-ai-wpoos' ); ?></li>
				<li><strong><?php esc_html_e( 'Form-Based Config:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'No more JSON editing! Visual forms', 'mcp-ai-wpoos' ); ?></li>
				<li><strong><?php esc_html_e( 'Real-Time Validation:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Instant feedback as you build', 'mcp-ai-wpoos' ); ?></li>
				<li><strong><?php esc_html_e( 'Undo/Redo:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Full history support', 'mcp-ai-wpoos' ); ?></li>
				<li><strong><?php esc_html_e( 'Accessibility:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'WCAG 2.1 compliant, keyboard shortcuts', 'mcp-ai-wpoos' ); ?></li>
				<li><strong><?php esc_html_e( 'Touch Support:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Works on mobile and tablets', 'mcp-ai-wpoos' ); ?></li>
			</ul>
		</div>

		<!-- Technology Stack -->
		<div style="border: 1px solid #00a32a; padding: 15px; background: #f0f9f4; border-radius: 4px;">
			<h3 style="margin: 0 0 15px 0; color: #00a32a;">
				<span class="dashicons dashicons-admin-tools"></span>
				<?php esc_html_e( 'Technology Stack', 'mcp-ai-wpoos' ); ?>
			</h3>
			<table style="width: 100%; font-size: 13px;">
				<tr>
					<td style="padding: 5px 0;"><strong><?php esc_html_e( 'Framework:', 'mcp-ai-wpoos' ); ?></strong></td>
					<td style="padding: 5px 0;"><code>React 18.2</code></td>
				</tr>
				<tr>
					<td style="padding: 5px 0;"><strong><?php esc_html_e( 'Workflow Canvas:', 'mcp-ai-wpoos' ); ?></strong></td>
					<td style="padding: 5px 0;"><code>React Flow 11.10</code></td>
				</tr>
				<tr>
					<td style="padding: 5px 0;"><strong><?php esc_html_e( 'Drag & Drop:', 'mcp-ai-wpoos' ); ?></strong></td>
					<td style="padding: 5px 0;"><code>dnd-kit 6.1</code></td>
				</tr>
				<tr>
					<td style="padding: 5px 0;"><strong><?php esc_html_e( 'UI Components:', 'mcp-ai-wpoos' ); ?></strong></td>
					<td style="padding: 5px 0;"><code>@wordpress/components</code></td>
				</tr>
				<tr>
					<td style="padding: 5px 0;"><strong><?php esc_html_e( 'State Management:', 'mcp-ai-wpoos' ); ?></strong></td>
					<td style="padding: 5px 0;"><code>@wordpress/data</code></td>
				</tr>
				<tr>
					<td style="padding: 5px 0;"><strong><?php esc_html_e( 'Build Tools:', 'mcp-ai-wpoos' ); ?></strong></td>
					<td style="padding: 5px 0;"><code>@wordpress/scripts</code></td>
				</tr>
			</table>
		</div>
	</div>

	<div style="margin: 20px 0;">
		<h3><?php esc_html_e( 'npm Packages (Production)', 'mcp-ai-wpoos' ); ?></h3>
		<table class="wp-list-table widefat fixed striped" style="max-width: 900px;">
			<thead>
				<tr>
					<th style="width: 35%;"><?php esc_html_e( 'Package', 'mcp-ai-wpoos' ); ?></th>
					<th style="width: 15%;"><?php esc_html_e( 'Version', 'mcp-ai-wpoos' ); ?></th>
					<th style="width: 50%;"><?php esc_html_e( 'Purpose', 'mcp-ai-wpoos' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>react</code></td>
					<td><code>^18.2.0</code></td>
					<td><?php esc_html_e( 'Core UI framework for building interactive interfaces', 'mcp-ai-wpoos' ); ?></td>
				</tr>
				<tr>
					<td><code>react-dom</code></td>
					<td><code>^18.2.0</code></td>
					<td><?php esc_html_e( 'DOM rendering engine for React applications', 'mcp-ai-wpoos' ); ?></td>
				</tr>
				<tr>
					<td><code>reactflow</code></td>
					<td><code>^11.10.4</code></td>
					<td><?php esc_html_e( 'Visual workflow canvas - industry standard (MIT license)', 'mcp-ai-wpoos' ); ?></td>
				</tr>
				<tr>
					<td><code>@dnd-kit/core</code></td>
					<td><code>^6.1.0</code></td>
					<td><?php esc_html_e( 'Modern drag-and-drop toolkit with accessibility', 'mcp-ai-wpoos' ); ?></td>
				</tr>
				<tr>
					<td><code>@dnd-kit/sortable</code></td>
					<td><code>^8.0.0</code></td>
					<td><?php esc_html_e( 'Sortable list support for command palette', 'mcp-ai-wpoos' ); ?></td>
				</tr>
				<tr>
					<td><code>@dnd-kit/utilities</code></td>
					<td><code>^3.2.2</code></td>
					<td><?php esc_html_e( 'Helper utilities for drag-and-drop operations', 'mcp-ai-wpoos' ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>

	<div style="margin: 20px 0;">
		<h3><?php esc_html_e( 'npm Packages (Development)', 'mcp-ai-wpoos' ); ?></h3>
		<table class="wp-list-table widefat fixed striped" style="max-width: 900px;">
			<thead>
				<tr>
					<th style="width: 35%;"><?php esc_html_e( 'Package', 'mcp-ai-wpoos' ); ?></th>
					<th style="width: 15%;"><?php esc_html_e( 'Version', 'mcp-ai-wpoos' ); ?></th>
					<th style="width: 50%;"><?php esc_html_e( 'Purpose', 'mcp-ai-wpoos' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>@wordpress/scripts</code></td>
					<td><code>^27.0.0</code></td>
					<td><?php esc_html_e( 'Official WordPress build tooling (webpack, babel, eslint)', 'mcp-ai-wpoos' ); ?></td>
				</tr>
				<tr>
					<td><code>@wordpress/components</code></td>
					<td><code>^27.0.0</code></td>
					<td><?php esc_html_e( 'WordPress UI component library for consistent design', 'mcp-ai-wpoos' ); ?></td>
				</tr>
				<tr>
					<td><code>@wordpress/data</code></td>
					<td><code>^9.0.0</code></td>
					<td><?php esc_html_e( 'Redux-based state management for WordPress', 'mcp-ai-wpoos' ); ?></td>
				</tr>
				<tr>
					<td><code>@wordpress/i18n</code></td>
					<td><code>^4.0.0</code></td>
					<td><?php esc_html_e( 'Internationalization support for translations', 'mcp-ai-wpoos' ); ?></td>
				</tr>
				<tr>
					<td><code>@wordpress/element</code></td>
					<td><code>^5.0.0</code></td>
					<td><?php esc_html_e( 'WordPress abstraction over React', 'mcp-ai-wpoos' ); ?></td>
				</tr>
				<tr>
					<td><code>@wordpress/hooks</code></td>
					<td><code>^3.0.0</code></td>
					<td><?php esc_html_e( 'WordPress hooks system for extensibility', 'mcp-ai-wpoos' ); ?></td>
				</tr>
			</tbody>
		</table>
		<p class="description" style="margin-top: 10px;">
			<strong><?php esc_html_e( 'Note:', 'mcp-ai-wpoos' ); ?></strong>
			<?php esc_html_e( 'Development packages are NOT included in the plugin distribution. They are only used during the build process.', 'mcp-ai-wpoos' ); ?>
		</p>
	</div>

	<div style="margin: 20px 0; padding: 15px; background: #fff3cd; border-left: 4px solid #f0b849;">
		<h3 style="margin: 0 0 10px 0;">
			<span class="dashicons dashicons-info"></span>
			<?php esc_html_e( 'Build Information', 'mcp-ai-wpoos' ); ?>
		</h3>
		<table style="width: 100%; font-size: 13px;">
			<tr>
				<td style="padding: 5px 0; width: 30%;"><strong><?php esc_html_e( 'Build Command:', 'mcp-ai-wpoos' ); ?></strong></td>
				<td style="padding: 5px 0;"><code>npm run build:pro</code></td>
			</tr>
			<tr>
				<td style="padding: 5px 0;"><strong><?php esc_html_e( 'Output Location:', 'mcp-ai-wpoos' ); ?></strong></td>
				<td style="padding: 5px 0;"><code>build/workflow-builder/</code></td>
			</tr>
			<tr>
				<td style="padding: 5px 0;"><strong><?php esc_html_e( 'Bundle Size:', 'mcp-ai-wpoos' ); ?></strong></td>
				<td style="padding: 5px 0;">~200-300KB (minified & optimized)</td>
			</tr>
			<tr>
				<td style="padding: 5px 0;"><strong><?php esc_html_e( 'Compilation:', 'mcp-ai-wpoos' ); ?></strong></td>
				<td style="padding: 5px 0;">Webpack + Babel (via @wordpress/scripts)</td>
			</tr>
			<tr>
				<td style="padding: 5px 0;"><strong><?php esc_html_e( 'Pre-Built:', 'mcp-ai-wpoos' ); ?></strong></td>
				<td style="padding: 5px 0;">✅ <?php esc_html_e( 'Yes - Committed to repository', 'mcp-ai-wpoos' ); ?></td>
			</tr>
		</table>
	</div>

	<div style="margin: 20px 0; padding: 15px; background: #f0f6fc; border-left: 4px solid #2271b1;">
		<h3 style="margin: 0 0 10px 0;">
			<span class="dashicons dashicons-admin-settings"></span>
			<?php esc_html_e( 'Usage', 'mcp-ai-wpoos' ); ?>
		</h3>
		<p style="margin: 0 0 10px 0;">
			<strong><?php esc_html_e( 'Location:', 'mcp-ai-wpoos' ); ?></strong>
			<?php esc_html_e( 'NV oOS → Workflows (Pro version only)', 'mcp-ai-wpoos' ); ?>
		</p>
		<p style="margin: 0 0 10px 0;">
			<strong><?php esc_html_e( 'Requirements:', 'mcp-ai-wpoos' ); ?></strong>
			<?php esc_html_e( 'Pro version of the plugin', 'mcp-ai-wpoos' ); ?>
		</p>
		<p style="margin: 0;">
			<strong><?php esc_html_e( 'Browser Support:', 'mcp-ai-wpoos' ); ?></strong>
			<?php esc_html_e( 'Modern browsers (Chrome, Firefox, Safari, Edge - latest 2 versions)', 'mcp-ai-wpoos' ); ?>
		</p>
	</div>

	<div style="margin: 20px 0;">
		<h3><?php esc_html_e( 'Why These Packages?', 'mcp-ai-wpoos' ); ?></h3>
		<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">
			
			<div style="padding: 15px; background: #f9f9f9; border-left: 3px solid #2271b1;">
				<h4 style="margin: 0 0 8px 0; color: #2271b1;"><?php esc_html_e( 'React Flow', 'mcp-ai-wpoos' ); ?></h4>
				<p style="margin: 0; font-size: 13px;">
					<?php esc_html_e( 'Industry standard for workflow builders. Used by Stripe, Typeform, and thousands of production apps. 18K+ GitHub stars, MIT license.', 'mcp-ai-wpoos' ); ?>
				</p>
			</div>

			<div style="padding: 15px; background: #f9f9f9; border-left: 3px solid #00a32a;">
				<h4 style="margin: 0 0 8px 0; color: #00a32a;"><?php esc_html_e( 'dnd-kit', 'mcp-ai-wpoos' ); ?></h4>
				<p style="margin: 0; font-size: 13px;">
					<?php esc_html_e( 'Modern drag-and-drop with built-in accessibility. Keyboard support, screen readers, touch-friendly. Zero dependencies, performant.', 'mcp-ai-wpoos' ); ?>
				</p>
			</div>

			<div style="padding: 15px; background: #f9f9f9; border-left: 3px solid #f0b849;">
				<h4 style="margin: 0 0 8px 0; color: #f0b849;"><?php esc_html_e( '@wordpress/scripts', 'mcp-ai-wpoos' ); ?></h4>
				<p style="margin: 0; font-size: 13px;">
					<?php esc_html_e( 'Official WordPress tooling ensures compatibility and follows WordPress standards. Zero-config webpack, babel, and eslint.', 'mcp-ai-wpoos' ); ?>
				</p>
			</div>
		</div>
	</div>
</div>
			<?php
		}

		/**
		 * Render Pro Toolkit Features card.
		 *
		 * Displays a summary of all Pro toolkit features and capabilities.
		 *
		 * @since 1.1.0
		 * @return void
		 */
		private static function render_pro_toolkit_features_card() {
			?>
<div class="wp-mcp-ai-settings-card">
	<h2>
		<span class="dashicons dashicons-star-filled" style="color: #f0b849;"></span>
			<?php esc_html_e( 'Pro Toolkit Features & Capabilities', 'mcp-ai-wpoos' ); ?>
	</h2>

	<div class="notice notice-success inline" style="margin: 15px 0;">
		<p>
			<strong><?php esc_html_e( 'Enterprise-Grade AI Orchestration', 'mcp-ai-wpoos' ); ?></strong><br>
			<?php esc_html_e( 'Comprehensive suite of professional toolkits for advanced AI-powered automation, content generation, and business intelligence.', 'mcp-ai-wpoos' ); ?>
		</p>
	</div>

	<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px; margin: 20px 0;">
		
		<!-- Content & Media Production -->
		<div class="pro-feature-category">
			<h3 style="margin: 0 0 15px 0; padding-bottom: 10px; border-bottom: 2px solid #2271b1; color: #2271b1;">
				<span class="dashicons dashicons-admin-media"></span>
				<?php esc_html_e( 'Content & Media', 'mcp-ai-wpoos' ); ?>
			</h3>
			<ul style="margin: 0; list-style: none; padding: 0;">
				<li style="padding: 8px 0; border-bottom: 1px solid #f0f0f1;">
					<strong><?php esc_html_e( 'Media Toolkit', 'mcp-ai-wpoos' ); ?></strong>
					<br><small><?php esc_html_e( 'Image optimization, video processing, SVG vectorization', 'mcp-ai-wpoos' ); ?></small>
				</li>
				<li style="padding: 8px 0; border-bottom: 1px solid #f0f0f1;">
					<strong><?php esc_html_e( 'Video Production', 'mcp-ai-wpoos' ); ?></strong>
					<br><small><?php esc_html_e( 'Professional video editing with FFmpeg, subtitle generation', 'mcp-ai-wpoos' ); ?></small>
				</li>
				<li style="padding: 8px 0; border-bottom: 1px solid #f0f0f1;">
					<strong><?php esc_html_e( 'Image Production', 'mcp-ai-wpoos' ); ?></strong>
					<br><small><?php esc_html_e( 'Advanced image generation and manipulation', 'mcp-ai-wpoos' ); ?></small>
				</li>
				<li style="padding: 8px 0;">
					<strong><?php esc_html_e( 'Document Generation', 'mcp-ai-wpoos' ); ?></strong>
					<br><small><?php esc_html_e( 'PDF, Word, Excel document creation with templates', 'mcp-ai-wpoos' ); ?></small>
				</li>
			</ul>
		</div>

		<!-- Business & E-commerce -->
		<div class="pro-feature-category">
			<h3 style="margin: 0 0 15px 0; padding-bottom: 10px; border-bottom: 2px solid #00a32a; color: #00a32a;">
				<span class="dashicons dashicons-cart"></span>
				<?php esc_html_e( 'Business & E-commerce', 'mcp-ai-wpoos' ); ?>
			</h3>
			<ul style="margin: 0; list-style: none; padding: 0;">
				<li style="padding: 8px 0; border-bottom: 1px solid #f0f0f1;">
					<strong><?php esc_html_e( 'E-commerce Toolkit', 'mcp-ai-wpoos' ); ?></strong>
					<br><small><?php esc_html_e( 'WooCommerce integration, product management, payments', 'mcp-ai-wpoos' ); ?></small>
				</li>
				<li style="padding: 8px 0; border-bottom: 1px solid #f0f0f1;">
					<strong><?php esc_html_e( 'CRM & Email Marketing', 'mcp-ai-wpoos' ); ?></strong>
					<br><small><?php esc_html_e( 'Customer relationship management, email campaigns', 'mcp-ai-wpoos' ); ?></small>
				</li>
				<li style="padding: 8px 0; border-bottom: 1px solid #f0f0f1;">
					<strong><?php esc_html_e( 'Financial Planner', 'mcp-ai-wpoos' ); ?></strong>
					<br><small><?php esc_html_e( 'QuickBooks integration, financial reports, budgeting', 'mcp-ai-wpoos' ); ?></small>
				</li>
				<li style="padding: 8px 0;">
					<strong><?php esc_html_e( 'Advanced Analytics', 'mcp-ai-wpoos' ); ?></strong>
					<br><small><?php esc_html_e( 'Business intelligence, data visualization with D3.js', 'mcp-ai-wpoos' ); ?></small>
				</li>
			</ul>
		</div>

		<!-- Marketing & Social -->
		<div class="pro-feature-category">
			<h3 style="margin: 0 0 15px 0; padding-bottom: 10px; border-bottom: 2px solid #f0b849; color: #f0b849;">
				<span class="dashicons dashicons-share"></span>
				<?php esc_html_e( 'Marketing & Social', 'mcp-ai-wpoos' ); ?>
			</h3>
			<ul style="margin: 0; list-style: none; padding: 0;">
				<li style="padding: 8px 0; border-bottom: 1px solid #f0f0f1;">
					<strong><?php esc_html_e( 'Social Media Management', 'mcp-ai-wpoos' ); ?></strong>
					<br><small><?php esc_html_e( 'Twitter, Facebook, LinkedIn, Instagram posting & analytics', 'mcp-ai-wpoos' ); ?></small>
				</li>
				<li style="padding: 8px 0; border-bottom: 1px solid #f0f0f1;">
					<strong><?php esc_html_e( 'Multilingual Content', 'mcp-ai-wpoos' ); ?></strong>
					<br><small><?php esc_html_e( 'Translation, language detection, localization', 'mcp-ai-wpoos' ); ?></small>
				</li>
				<li style="padding: 8px 0;">
					<strong><?php esc_html_e( 'SEO & Analytics', 'mcp-ai-wpoos' ); ?></strong>
					<br><small><?php esc_html_e( 'Google Analytics integration, SEO optimization', 'mcp-ai-wpoos' ); ?></small>
				</li>
			</ul>
		</div>

		<!-- Project & Task Management -->
		<div class="pro-feature-category">
			<h3 style="margin: 0 0 15px 0; padding-bottom: 10px; border-bottom: 2px solid #9b51e0; color: #9b51e0;">
				<span class="dashicons dashicons-list-view"></span>
				<?php esc_html_e( 'Project Management', 'mcp-ai-wpoos' ); ?>
			</h3>
			<ul style="margin: 0; list-style: none; padding: 0;">
				<li style="padding: 8px 0; border-bottom: 1px solid #f0f0f1;">
					<strong><?php esc_html_e( 'Project Management', 'mcp-ai-wpoos' ); ?></strong>
					<br><small><?php esc_html_e( 'Projects, tasks, events, calendar integration', 'mcp-ai-wpoos' ); ?></small>
				</li>
				<li style="padding: 8px 0; border-bottom: 1px solid #f0f0f1;">
					<strong><?php esc_html_e( 'Calendar Booking', 'mcp-ai-wpoos' ); ?></strong>
					<br><small><?php esc_html_e( 'Scheduling, bookings, Google Calendar sync', 'mcp-ai-wpoos' ); ?></small>
				</li>
				<li style="padding: 8px 0;">
					<strong><?php esc_html_e( 'AI Orchestration', 'mcp-ai-wpoos' ); ?></strong>
					<br><small><?php esc_html_e( 'Autonomous task execution, Ralph pattern implementation', 'mcp-ai-wpoos' ); ?></small>
				</li>
			</ul>
		</div>

		<!-- Specialized Toolkits -->
		<div class="pro-feature-category">
			<h3 style="margin: 0 0 15px 0; padding-bottom: 10px; border-bottom: 2px solid #d63638; color: #d63638;">
				<span class="dashicons dashicons-hammer"></span>
				<?php esc_html_e( 'Specialized Tools', 'mcp-ai-wpoos' ); ?>
			</h3>
			<ul style="margin: 0; list-style: none; padding: 0;">
				<li style="padding: 8px 0; border-bottom: 1px solid #f0f0f1;">
					<strong><?php esc_html_e( 'Architectural Design', 'mcp-ai-wpoos' ); ?></strong>
					<br><small><?php esc_html_e( 'Architectural drawing generation and design tools', 'mcp-ai-wpoos' ); ?></small>
				</li>
				<li style="padding: 8px 0; border-bottom: 1px solid #f0f0f1;">
					<strong><?php esc_html_e( 'DJ Management', 'mcp-ai-wpoos' ); ?></strong>
					<br><small><?php esc_html_e( 'Music library, playlist management, event planning', 'mcp-ai-wpoos' ); ?></small>
				</li>
				<li style="padding: 8px 0; border-bottom: 1px solid #f0f0f1;">
					<strong><?php esc_html_e( 'Quiz System', 'mcp-ai-wpoos' ); ?></strong>
					<br><small><?php esc_html_e( 'Interactive quizzes, assessments, learning management', 'mcp-ai-wpoos' ); ?></small>
				</li>
				<li style="padding: 8px 0;">
					<strong><?php esc_html_e( 'AI Tool Builder', 'mcp-ai-wpoos' ); ?></strong>
					<br><small><?php esc_html_e( 'Create custom AI tools and integrations', 'mcp-ai-wpoos' ); ?></small>
				</li>
			</ul>
		</div>

		<!-- Development & Integration -->
		<div class="pro-feature-category">
			<h3 style="margin: 0 0 15px 0; padding-bottom: 10px; border-bottom: 2px solid #1d2327; color: #1d2327;">
				<span class="dashicons dashicons-editor-code"></span>
				<?php esc_html_e( 'Development & Integration', 'mcp-ai-wpoos' ); ?>
			</h3>
			<ul style="margin: 0; list-style: none; padding: 0;">
				<li style="padding: 8px 0; border-bottom: 1px solid #f0f0f1;">
					<strong><?php esc_html_e( 'Remote Site Connections', 'mcp-ai-wpoos' ); ?></strong>
					<br><small><?php esc_html_e( 'Connect to remote WordPress/WooCommerce sites via REST API', 'mcp-ai-wpoos' ); ?></small>
				</li>
				<li style="padding: 8px 0; border-bottom: 1px solid #f0f0f1;">
					<strong><?php esc_html_e( 'GitHub Integration', 'mcp-ai-wpoos' ); ?></strong>
					<br><small><?php esc_html_e( 'Repository operations, Codespace management', 'mcp-ai-wpoos' ); ?></small>
				</li>
				<li style="padding: 8px 0; border-bottom: 1px solid #f0f0f1;">
					<strong><?php esc_html_e( 'Code Formatting', 'mcp-ai-wpoos' ); ?></strong>
					<br><small><?php esc_html_e( 'Prettier integration for code formatting', 'mcp-ai-wpoos' ); ?></small>
				</li>
				<li style="padding: 8px 0;">
					<strong><?php esc_html_e( 'Password Vault', 'mcp-ai-wpoos' ); ?></strong>
					<br><small><?php esc_html_e( 'AES-256-GCM encrypted credential storage', 'mcp-ai-wpoos' ); ?></small>
				</li>
			</ul>
		</div>

	</div>

	<div style="margin: 20px 0; padding: 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 8px;">
		<h3 style="margin: 0 0 15px 0; color: white;">
			<span class="dashicons dashicons-chart-line" style="color: white;"></span>
			<?php esc_html_e( 'Key Statistics', 'mcp-ai-wpoos' ); ?>
		</h3>
		<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
			<div style="text-align: center; padding: 15px; background: rgba(255, 255, 255, 0.1); border-radius: 4px;">
				<div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;">150+</div>
				<div style="font-size: 14px; opacity: 0.9;"><?php esc_html_e( 'Pro Tools', 'mcp-ai-wpoos' ); ?></div>
			</div>
			<div style="text-align: center; padding: 15px; background: rgba(255, 255, 255, 0.1); border-radius: 4px;">
				<div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;">20+</div>
				<div style="font-size: 14px; opacity: 0.9;"><?php esc_html_e( 'Toolkits', 'mcp-ai-wpoos' ); ?></div>
			</div>
			<div style="text-align: center; padding: 15px; background: rgba(255, 255, 255, 0.1); border-radius: 4px;">
				<div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;">50+</div>
				<div style="font-size: 14px; opacity: 0.9;"><?php esc_html_e( 'NPM Packages', 'mcp-ai-wpoos' ); ?></div>
			</div>
			<div style="text-align: center; padding: 15px; background: rgba(255, 255, 255, 0.1); border-radius: 4px;">
				<div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;">10+</div>
				<div style="font-size: 14px; opacity: 0.9;"><?php esc_html_e( 'AI Providers', 'mcp-ai-wpoos' ); ?></div>
			</div>
		</div>
	</div>

	<div style="margin: 20px 0; padding: 15px; background: #f0f0f1; border-left: 4px solid #2271b1;">
		<p style="margin: 0;">
			<strong><?php esc_html_e( 'Getting Started:', 'mcp-ai-wpoos' ); ?></strong>
			<?php
			printf(
				/* translators: %s: settings page URL */
				esc_html__( 'Enable individual toolkits in %s to unlock their features and tools.', 'mcp-ai-wpoos' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=advanced' ) ) . '"><strong>' . esc_html__( 'Settings → Advanced', 'mcp-ai-wpoos' ) . '</strong></a>'
			);
			?>
		</p>
	</div>
</div>
			<?php
		}

		/**
		 * Add Pro Settings submenu page.
		 *
		 * Registers the Pro Settings page under the Pro Dashboard menu.
		 *
		 * @since 1.1.0
		 * @return void
		 */
		public static function add_menu_page() {
			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Pro Settings', 'mcp-ai-wpoos' ),
				__( 'Pro Settings', 'mcp-ai-wpoos' ),
				'manage_options',
				self::PAGE_SLUG,
				array( __CLASS__, 'render_page' )
			);
		}
	}
}

// Register Pro Settings page in admin menu.
add_action( 'admin_menu', array( 'WP_MCP_AI_Pro_Settings', 'add_menu_page' ), 100 );
