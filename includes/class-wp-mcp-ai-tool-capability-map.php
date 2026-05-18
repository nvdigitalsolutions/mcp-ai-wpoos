<?php
/**
 * Authoritative slug → WordPress-capability map for tool payload filtering.
 *
 * The capability fence in {@see WP_MCP_AI_REST::build_tools_payload()} drops
 * a tool from the model's payload when the current user lacks
 * `get_required_capability()`. Today only a minority of tool classes declare
 * that method, which means most registered slugs leak to every user the
 * assistant is reachable to — including low-privilege roles and `*_validated`
 * wrapper slugs that don't have a single backing class.
 *
 * This map is the single source of truth that closes that gap. It is keyed
 * by tool slug and consulted as a *fallback* whenever a tool itself does not
 * declare `get_required_capability()`. Per-class methods always win; the map
 * fills the gap below them and reaches dynamically-registered slugs that
 * per-class edits cannot.
 *
 * The mapping is derived from the Capability-Fence Audit, applying the
 * decision tree documented in
 * docs/proposals/audits/P2b-required-capability-assignment-2026-05.md
 * (see also Unix Theory Compliance Enhancement Proposal §2.3).
 *
 * Adding a new tool? Either:
 *   1. Implement `public function get_required_capability()` on the tool
 *      class (preferred for new code), OR
 *   2. Register the slug here with the rationale recorded in the audit doc.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * @link    docs/proposals/audits/P2b-required-capability-assignment-2026-05.md
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_Capability_Map' ) ) {

	/**
	 * Class WP_MCP_AI_Tool_Capability_Map
	 *
	 * Stateless lookup helper. All accessors are static.
	 */
	class WP_MCP_AI_Tool_Capability_Map {

		/**
		 * Safe non-trivial default capability.
		 *
		 * `'edit_posts'` is the lowest WordPress capability that still excludes
		 * Subscribers (read-only role) and unauthenticated users. Tools that
		 * are pure reads with no external spend should override to `'read'`;
		 * tools that touch admin surfaces should override to
		 * `'manage_options'`.
		 *
		 * @var string
		 */
		const DEFAULT_CAPABILITY = 'edit_posts';

		/**
		 * Cached resolved slug → capability map.
		 *
		 * @var array<string,string>|null
		 */
		protected static $cached_map = null;

		/**
		 * Get the authoritative slug → capability map.
		 *
		 * Filterable via `wp_mcp_ai_tool_capability_map` so deployments can
		 * tighten or relax caps per-slug without forking the file.
		 *
		 * @return array<string,string>
		 */
		public static function get_map() {
			if ( null !== self::$cached_map ) {
				return self::$cached_map;
			}

			$map = self::build_map();

			/**
			 * Filter the slug → capability map used as a fallback when a tool
			 * does not declare `get_required_capability()`.
			 *
			 * @since 1.2.0
			 *
			 * @param array<string,string> $map Slug => WordPress-capability pairs.
			 */
			$map = apply_filters( 'wp_mcp_ai_tool_capability_map', $map );

			// Normalise: lowercase keys, sanitised capability strings, drop empties.
			$normalised = array();
			foreach ( (array) $map as $slug => $cap ) {
				if ( ! is_string( $slug ) || '' === $slug || ! is_string( $cap ) ) {
					continue;
				}
				$cap = sanitize_key( $cap );
				if ( '' === $cap ) {
					continue;
				}
				$normalised[ sanitize_key( $slug ) ] = $cap;
			}

			self::$cached_map = $normalised;

			return self::$cached_map;
		}

		/**
		 * Look up the capability for a single slug.
		 *
		 * @param string $slug Tool slug.
		 * @return string|null Capability string, or null if not mapped.
		 */
		public static function get_capability( $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug ) {
				return null;
			}

			$map = self::get_map();

			return isset( $map[ $slug ] ) ? $map[ $slug ] : null;
		}

		/**
		 * Resolve the effective capability for a tool, honouring (in order):
		 *
		 *   1. The tool's own `get_required_capability()` method (per-class
		 *      override — always wins).
		 *   2. The central slug → capability map (this class).
		 *   3. The safe default (`self::DEFAULT_CAPABILITY`).
		 *
		 * Filterable via `wp_mcp_ai_tool_required_capability` so individual
		 * deployments can lock down or relax caps per slug.
		 *
		 * @param object|null $tool Tool instance (may be null when only the
		 *                           slug is known).
		 * @param string      $slug Tool slug.
		 * @return string Effective WordPress capability string.
		 */
		public static function resolve( $tool, $slug ) {
			$slug       = sanitize_key( (string) $slug );
			$capability = '';

			// 1. Per-class override.
			if ( is_object( $tool ) && method_exists( $tool, 'get_required_capability' ) ) {
				$declared = $tool->get_required_capability();
				if ( is_string( $declared ) && '' !== $declared ) {
					$capability = $declared;
				}
			}

			// 2. Central map fallback.
			if ( '' === $capability && '' !== $slug ) {
				$mapped = self::get_capability( $slug );
				if ( null !== $mapped ) {
					$capability = $mapped;
				}
			}

			// 3. Safe default — never let a tool leak through with no fence.
			if ( '' === $capability ) {
				$capability = self::DEFAULT_CAPABILITY;
			}

			/**
			 * Filter the effective required capability for a tool.
			 *
			 * @since 1.2.0
			 *
			 * @param string      $capability Resolved capability string.
			 * @param string      $slug       Tool slug.
			 * @param object|null $tool       Tool instance (may be null).
			 */
			$filtered = apply_filters( 'wp_mcp_ai_tool_required_capability', $capability, $slug, $tool );

			if ( ! is_string( $filtered ) || '' === $filtered ) {
				return self::DEFAULT_CAPABILITY;
			}

			$filtered = sanitize_key( $filtered );

			return '' !== $filtered ? $filtered : self::DEFAULT_CAPABILITY;
		}

		/**
		 * Reset the cached map. Intended for tests.
		 *
		 * @return void
		 */
		public static function reset_cache() {
			self::$cached_map = null;
		}

		/**
		 * Build the raw slug → capability map.
		 *
		 * Grouped by domain for reviewer sanity. Each group's rationale is
		 * recorded in the P2b audit doc.
		 *
		 * @return array<string,string>
		 */
		protected static function build_map() {
			return array(

				// ---- Core WP reads (read-only, local or external) ----------
				'get_recent_posts'             => 'read',
				'search_content'               => 'read',
				'get_user_info'                => 'list_users',
				'get_site_summary'             => 'read',
				'count_tokens'                 => 'read',
				'load_skill'                   => 'read',
				'get_post'                     => 'read',
				'get_post_type_schema'         => 'read',
				'search_attachments'           => 'read',
				'recall_memory'                => 'read',

				// ---- OpenAI / model directory reads ------------------------
				'list_openai_files'            => 'read',
				'get_openai_file_details'      => 'read',
				'list_available_models'        => 'read',
				'get_model_information'        => 'read',
				'suggest_best_model'           => 'read',
				'semantic_content_search'      => 'read',
				'discover_new_models'          => 'edit_posts',
				'research_model'               => 'edit_posts',
				'add_model_config'             => 'manage_options',
				'create_text_embeddings'       => 'edit_posts',
				'batch_embed_content'          => 'edit_posts',

				// ---- Health / diagnostics / system surfaces ---------------
				'get_site_health'              => 'manage_options',
				'get_environment_status'       => 'manage_options',
				'get_system_logs'              => 'manage_options',
				'get_update_status'            => 'manage_options',
				'open_openai_logs'             => 'manage_options',
				'open_openai_usage'            => 'manage_options',

				// ---- Auth / security tools --------------------------------
				'generate_auth0_token'         => 'manage_options',
				'check_site_security'          => 'manage_options',
				'generate_simple_jwt_token'    => 'manage_options',

				// ---- External APIs (read-only research) -------------------
				'get_nhc_active_storms'        => 'read',
				'get_gdacs_events'             => 'read',
				'get_open_meteo_forecast'      => 'read',
				'reliefweb_reports'            => 'read',
				'crawl4ai_price_lookup'        => 'edit_posts',
				'web_search'                   => 'edit_posts',
				'run_openai_external_action'   => 'edit_posts',

				// ---- Probes / diagnostics ---------------------------------
				'probe_chat'                   => 'manage_options',
				'probe_remote_mcp'             => 'manage_options',

				// ---- OpenAI media (server-side, files/uploads) ------------
				'generate_openai_speech'       => 'edit_posts',
				'transcribe_openai_audio'      => 'edit_posts',
				'moderate_content'             => 'edit_posts',

				// ---- Client-side compute (in-browser, no server work) -----
				'client_summarize_text'        => 'read',
				'client_analyze_sentiment'     => 'read',
				'client_extract_entities'      => 'read',
				'client_translate_text'        => 'read',
				'client_question_answering'    => 'read',
				'client_semantic_search'       => 'read',

				// ---- Image / video / audio generation (state-changing) ---
				'generate_openai_image'        => 'edit_posts',
				'generate_sora_video'          => 'edit_posts',
				'generate_gemini_image'        => 'edit_posts',
				'cloudflareai_text_to_image'   => 'edit_posts',
				'generate_veo_video'           => 'edit_posts',
				'edit_gemini_image'            => 'edit_posts',
				'generate_music'               => 'edit_posts',
				'submit_document_prompt'       => 'edit_posts',

				// ---- WP posts / terms / assistants (writes) ---------------
				'save_post'                    => 'edit_posts',
				'create_post'                  => 'edit_posts',
				'delete_post'                  => 'delete_posts',
				'create_term'                  => 'manage_categories',
				'update_term'                  => 'manage_categories',
				'create_assistant'             => 'edit_posts',
				'run_crawl4ai_job'             => 'edit_posts',

				// ---- Cron / cache / mass-email / remote -------------------
				'create_cron_job'              => 'manage_options',
				'list_cron_jobs'               => 'manage_options',
				'get_cron_job'                 => 'manage_options',
				'delete_cron_job'              => 'manage_options',
				'send_group_email'             => 'manage_options',
				'purge_cloudflare_cache'       => 'manage_options',
				'purge_varnish_cache'          => 'manage_options',
				'purge_cache'                  => 'manage_options',
				'query_remote_site'            => 'manage_options',
				'query_mesh_intelligent'       => 'manage_options',

				// ---- Image generation captions / analysis -----------------
				'generate_image_alt_text'      => 'edit_posts',
				'generate_image_caption'       => 'edit_posts',
				'analyze_video'                => 'edit_posts',
				'generate_video_caption'       => 'edit_posts',
				'analyze_comment_content'      => 'edit_posts',
				'create_chart'                 => 'edit_posts',

				// ---- Professions CPT -------------------------------------
				'list_professions'             => 'read',
				'get_profession'               => 'read',
				'save_profession'              => 'edit_posts',
				'get_profession_stats'         => 'read',

				// ---- Agent / memory / orchestration ----------------------
				'create_agent_team'            => 'edit_posts',
				'delegate_to_agent'            => 'edit_posts',
				'delegate_to_a2a_agent'        => 'edit_posts',
				'aggregate_agent_results'      => 'edit_posts',
				'store_agent_context'          => 'edit_posts',
				'retrieve_agent_memory'        => 'edit_posts',
				'prioritize_context'           => 'edit_posts',
				'semantic_context_search'      => 'edit_posts',
				'manage_context_lifecycle'     => 'edit_posts',
				'batch_manage_memory'          => 'edit_posts',
				'memory_audit_trail'           => 'edit_posts',
				'mine_agent_memory'            => 'edit_posts',
				'wake_up_context'              => 'edit_posts',
				'execute_workflow'             => 'edit_posts',
				'check_workflow_health'        => 'edit_posts',

				// ---- Geocoding / maps ------------------------------------
				'geocode_address'              => 'read',
				'search_places'                => 'read',
				'gemini_geospatial_query'      => 'read',

				// ---- Commerce / scraping ---------------------------------
				'scrape_product'               => 'edit_posts',

				// ---- Image transforms (write to media library) -----------
				'resize_image'                 => 'upload_files',
				'crop_image'                   => 'upload_files',
				'rotate_image'                 => 'upload_files',
				'convert_image_format'         => 'upload_files',
				'vectorize_image'              => 'upload_files',

				// ---- Deep research ---------------------------------------
				'deep_research'                => 'edit_posts',

				// ---- Erlang C --------------------------------------------
				'calculate_erlang_c'           => 'read',
				'erlang_c_concurrency_advisor' => 'read',
				'erlang_c_staffing_advisor'    => 'read',
				'erlang_c_queue_health'        => 'edit_posts',

				// ---- Plugin integrations ---------------------------------
				'import_elementor_template_kit' => 'manage_options',
				'get_woo_recent_orders'        => 'manage_woocommerce',
				'get_woo_products'             => 'read',
				'create_woo_product'           => 'manage_woocommerce',

				// ---- PayHere / Flowhub ------------------------------------
				'payhere_get_payment'          => 'manage_woocommerce',
				'flowhub_get_inventory'        => 'manage_woocommerce',
				'flowhub_get_orders'           => 'manage_woocommerce',
				'flowhub_create_order'         => 'manage_woocommerce',
				'flowhub_get_customers'        => 'manage_woocommerce',
				'flowhub_manage_customer'      => 'manage_woocommerce',
				'flowhub_get_products'         => 'manage_woocommerce',
				'flowhub_manage_product'       => 'manage_woocommerce',

				// ---- JetEngine -------------------------------------------
				'get_jetengine_items'          => 'edit_posts',
				'list_jetengine_rest_routes'   => 'edit_posts',
				'invoke_jetengine_route'       => 'edit_posts',

				// ---- Vision ----------------------------------------------
				'vision_product_search'        => 'edit_posts',
				'vision_object_localization'   => 'edit_posts',

				// ---- Pro graphic editor ----------------------------------
				'graphic_editor_plus'          => 'upload_files',

				// ---- Graphify --------------------------------------------
				'graphify_build_graph'         => 'edit_posts',
				'graphify_graph_stats'         => 'read',
				'graphify_query_graph'         => 'read',
				'graphify_get_node'            => 'read',
				'graphify_get_neighbors'       => 'read',
				'graphify_get_community'       => 'read',
				'graphify_god_nodes'           => 'read',
				'graphify_shortest_path'       => 'read',
				'graphify_suggest_links'       => 'read',
				'graphify_content_gaps'        => 'read',
				'graphify_retrieve_context'    => 'read',
				'graphify_resolve_external'    => 'read',
				'graphify_sync_remote_source'  => 'edit_posts',
				'graphify_list_remote_sources' => 'read',

				// ---- *_validated wrappers (mirror un-validated cap) -------
				'save_post_validated'                  => 'edit_posts',
				'create_cron_job_validated'            => 'manage_options',
				'search_content_validated'             => 'read',
				'create_assistant_validated'           => 'edit_posts',
				'get_recent_posts_validated'           => 'read',
				'get_system_logs_validated'            => 'manage_options',
				'create_chart_validated'               => 'edit_posts',
				'send_group_email_validated'           => 'manage_options',
				'create_woo_product_validated'         => 'manage_woocommerce',
				'create_post_validated'                => 'edit_posts',
				'transcribe_openai_audio_validated'    => 'edit_posts',
				'generate_image_alt_text_validated'    => 'edit_posts',
				'generate_image_caption_validated'     => 'edit_posts',
				'generate_openai_speech_validated'     => 'edit_posts',
				'generate_music_validated'             => 'edit_posts',
				'generate_gemini_image_validated'      => 'edit_posts',
				'generate_veo_video_validated'         => 'edit_posts',
				'generate_openai_image_validated'      => 'edit_posts',
				'web_search_validated'                 => 'edit_posts',
				'edit_gemini_image_validated'          => 'edit_posts',
				'scrape_product_validated'             => 'edit_posts',
				'run_crawl4ai_job_validated'           => 'edit_posts',

				// ---- Pro: scene composition (GPU pipeline) ----------------
				'generate_scene_background'      => 'upload_files',
				'adapt_background_for_subject'   => 'upload_files',
				'outpaint_background'            => 'upload_files',
				'refine_subject_matte'           => 'upload_files',
				'auto_clean_white_background'    => 'upload_files',
				'harmonize_color'                => 'upload_files',
				'relight_subject'                => 'upload_files',
				'generate_shadow'                => 'upload_files',
				'generate_reflection'            => 'upload_files',
				'refine_composite_boundary'      => 'upload_files',
				'analyze_scene_lighting'         => 'edit_posts',
				'suggest_placement'              => 'edit_posts',
				'harmonize_image_into_background' => 'upload_files',
				'harmonize_batch'                => 'upload_files',

				// ---- Pro: channel / messaging (broadcasts = admin) -------
				'get_google_chat_spaces'         => 'edit_posts',
				'create_google_chat_space'       => 'manage_options',
				'get_google_chat_messages'       => 'edit_posts',
				'send_google_chat_message'       => 'manage_options',
				'list_google_chat_space_members' => 'edit_posts',
				'add_google_chat_space_member'   => 'manage_options',
				'remove_google_chat_space_member' => 'manage_options',
				'get_telegram_updates'           => 'edit_posts',
				'manage_telegram_webhook'        => 'manage_options',
				'add_telegram_message_reaction'  => 'manage_options',
				'manage_telegram_commands'       => 'manage_options',
				'get_whatsapp_messages'          => 'edit_posts',
				'send_whatsapp_interactive'      => 'manage_options',
				'send_whatsapp_media'            => 'manage_options',
				'send_whatsapp_template'         => 'manage_options',
				'get_slack_channels'             => 'edit_posts',
				'get_slack_messages'             => 'edit_posts',
				'send_slack_message'             => 'manage_options',
				'create_slack_channel'           => 'manage_options',
				'get_discord_channels'           => 'edit_posts',
				'get_discord_messages'           => 'edit_posts',
				'send_discord_message'           => 'manage_options',
				'create_discord_channel'         => 'manage_options',
				'add_discord_message_reaction'   => 'manage_options',
				'get_discord_voice_channel_members' => 'edit_posts',
				'get_teams_channels'             => 'edit_posts',
				'get_teams_messages'             => 'edit_posts',
				'send_teams_message'             => 'manage_options',
				'send_outlook_mail'              => 'manage_options',
				'get_outlook_messages'           => 'edit_posts',
				'list_onedrive_files'            => 'edit_posts',
				'get_onedrive_file'              => 'edit_posts',
				'upload_onedrive_file'           => 'manage_options',
				'get_messenger_conversations'    => 'edit_posts',
				'send_messenger_message'         => 'manage_options',
				'create_messenger_broadcast'     => 'manage_options',
				'send_twitter_dm'                => 'manage_options',
				'get_twitter_dms'                => 'edit_posts',
				'manage_twitter_webhook'         => 'manage_options',
				'unified_channel_broadcast'      => 'manage_options',
				'send_apple_message'             => 'manage_options',
				'send_apple_message_interactive' => 'manage_options',
				'get_apple_messages'             => 'edit_posts',
				'send_apple_message_group'       => 'manage_options',
				'list_icloud_drive_files'        => 'edit_posts',
				'get_icloud_drive_file'          => 'edit_posts',
				'upload_icloud_drive_file'       => 'manage_options',
				'send_whatsapp_message'          => 'manage_options',
				'send_telegram_message'          => 'manage_options',
				'send_mailjet_email'             => 'manage_options',
				'send_brevo_email'               => 'manage_options',
				'manage_brevo_contacts'          => 'manage_options',
				'get_brevo_statistics'           => 'edit_posts',
				'send_mailgun_email'             => 'manage_options',
				'create_google_calendar_event'   => 'edit_posts',
				'google_analytics_report'        => 'manage_options',
				'sitekit_get_analytics'          => 'manage_options',
				'sitekit_get_search_console'     => 'manage_options',
				'sitekit_get_adsense'            => 'manage_options',
				'sitekit_get_pagespeed'          => 'read',
				'quickbooks_report'              => 'manage_options',
				'quickbooks_desktop_sync'        => 'manage_options',
				'get_import_duty'                => 'read',
				'create_wpcode_snippet'          => 'manage_options',
				'remote_wp_connection'           => 'manage_options',

				// ---- Pro: remote/REST/toolkit registry --------------------
				'generic_rest'                   => 'edit_posts',
				'toolkit_cpt'                    => 'edit_posts',

				// ---- Pro: GitHub / codespace -----------------------------
				'github_repository_operations'   => 'edit_posts',
				'list_github_repositories'       => 'edit_posts',
				'manage_github_codespace'        => 'manage_options',

				// ---- Pro: site builder / plugin / theme installs ---------
				'site_creator'                   => 'manage_options',
				'install_and_activate_plugin'    => 'install_plugins',
				'install_and_activate_theme'     => 'install_themes',
				'update_option'                  => 'manage_options',

				// ---- Pro: scheduling -------------------------------------
				'create_pro_schedule'                  => 'manage_options',
				'update_pro_schedule'                  => 'manage_options',
				'delete_pro_schedule'                  => 'manage_options',
				'list_pro_schedules'                   => 'edit_posts',
				'get_schedule_run_history'             => 'edit_posts',
				'dry_run_pro_schedule'                 => 'manage_options',
				'schedule_channel_broadcast'           => 'manage_options',
				'plan_schedules_from_workflow'         => 'manage_options',
				'get_schedule_latest_result'           => 'edit_posts',
				'render_schedule_result'               => 'edit_posts',
				'configure_schedule_widget_defaults'   => 'manage_options',

				// ---- Pro: iSAMS / browser / capture / research -----------
				'isams_query'                    => 'edit_posts',
				'web_browser'                    => 'edit_posts',
				'capture_webpage_screenshot'     => 'edit_posts',
				'research_post'                  => 'edit_posts',
				'research_page'                  => 'edit_posts',
				'research_blog_post'             => 'edit_posts',

				// ---- Pro: vehicles ---------------------------------------
				'vin_decode'                     => 'read',
				'vehicle_repair_estimate'        => 'edit_posts',
				'vehicle_cleaning_estimate'      => 'edit_posts',

				// ---- Pro: WooCommerce wrappers + Shopify -----------------
				'woo_products'                   => 'manage_woocommerce',
				'woo_orders'                     => 'manage_woocommerce',
				'woo_customers'                  => 'manage_woocommerce',
				'woo_coupons'                    => 'manage_woocommerce',
				'research_product'               => 'manage_woocommerce',
				'remote_shopify_connection'      => 'manage_woocommerce',
				'shopify_products'               => 'manage_woocommerce',
				'shopify_orders'                 => 'manage_woocommerce',
				'shopify_customers'              => 'manage_woocommerce',
				'shopify_inventory'              => 'manage_woocommerce',
				'shopify_catalog'                => 'manage_woocommerce',

				// ---- Pro: JetEngine / Elementor wrappers -----------------
				'jetengine'                      => 'edit_posts',
				'elementor'                      => 'edit_posts',

				// ---- Pro: WooCommerce advanced ---------------------------
				'create_product_advanced'        => 'manage_woocommerce',
				'bulk_update_products'           => 'manage_woocommerce',
				'import_products_csv'            => 'manage_woocommerce',
				'export_products_report'         => 'manage_woocommerce',
				'sync_product_inventory'         => 'manage_woocommerce',
				'generate_invoice_pdf'           => 'manage_woocommerce',
				'bulk_order_status_update'       => 'manage_woocommerce',
				'get_order_analytics'            => 'manage_woocommerce',
				'process_order_workflow'         => 'manage_woocommerce',
				'refund_order_advanced'          => 'manage_woocommerce',
				'segment_customers'              => 'manage_woocommerce',
				'customer_lifetime_value'        => 'manage_woocommerce',
				'export_customer_data'           => 'manage_woocommerce',
				'track_inventory_movement'       => 'manage_woocommerce',
				'low_stock_alert_automation'     => 'manage_woocommerce',
				'inventory_forecast'             => 'manage_woocommerce',
				'create_discount_campaign'       => 'manage_woocommerce',
				'abandoned_cart_recovery'        => 'manage_woocommerce',
				'upsell_recommendations'         => 'manage_woocommerce',
				'sales_performance_dashboard'    => 'manage_woocommerce',
				'shipping_box_packer'            => 'manage_woocommerce',
				'shipping_rate_estimator'        => 'manage_woocommerce',

				// ---- Pro: documents (write to media library) -------------
				'generate_pdf'                   => 'upload_files',
				'generate_word'                  => 'upload_files',
				'generate_excel'                 => 'upload_files',
				'extract_pdf_text'               => 'upload_files',
				'ocr_pdf_text'                   => 'upload_files',
				'pro_document_ocr'               => 'upload_files',
				'html_to_pdf'                    => 'upload_files',
				'merge_pdfs'                     => 'upload_files',
				'add_watermark_to_pdf'           => 'upload_files',
				'excel_data_import'              => 'upload_files',
				'excel_data_export'              => 'upload_files',
				'docgen_capture_style_memory'    => 'edit_posts',

				// ---- Pro: CLI -------------------------------------------
				'check_wp_cli'                   => 'manage_options',

				// ---- Pro: video pipeline --------------------------------
				'extract_video_frames'           => 'upload_files',
				'get_video_metadata'             => 'read',
				'remove_background'              => 'upload_files',

				// ---- Pro: Jukebox music ---------------------------------
				'generate_jukebox_music'         => 'upload_files',
				'check_jukebox_status'           => 'read',

				// ---- Pro: architectural drawing -------------------------
				'generate_architectural_drawing' => 'upload_files',

				// ---- Pro: decision capture / pm -------------------------
				'pm_capture_decision'            => 'edit_posts',

				// ---- Pro: product actualization / validators ------------
				'product_actualization'          => 'edit_posts',
				'validate_image_for_product'     => 'edit_posts',
				'validate_image_for_vehicle'     => 'edit_posts',
				'lookup_product_price'           => 'edit_posts',

				// ---- Pro: image downloaders ------------------------------
				'download_google_maps_images'    => 'upload_files',
				'download_facebook_page_images'  => 'upload_files',
				'download_instagram_page_images' => 'upload_files',

				// ---- Pro: social posting & insights ---------------------
				'post_facebook_instagram'        => 'edit_posts',
				'post_tiktok_video'              => 'edit_posts',
				'post_linkedin_update'           => 'edit_posts',
				'post_google_business_update'    => 'edit_posts',
				'get_facebook_instagram_insights' => 'edit_posts',
				'get_tiktok_insights'            => 'edit_posts',
				'get_linkedin_insights'          => 'edit_posts',
				'get_google_business_insights'   => 'edit_posts',

				// ---- Harness / prompt cues ------------------------------
				'list_prompt_cues'               => 'read',
				'select_prompt_cue'              => 'read',
				'apply_prompt_cue'                => 'read',
				'self_consistency_vote'          => 'read',
				'retrieve_with_provenance'       => 'read',
				'record_reflection'              => 'edit_posts',
				'scope_memory'                   => 'read',

				// ---- Pro: healthcare -----------------------------------------
				'create_medical_record'          => 'manage_options',
				'get_medical_record'             => 'manage_options',
				'update_medical_record'          => 'manage_options',
				'delete_medical_record'          => 'manage_options',
				'list_medical_records'           => 'manage_options',
				'search_medical_records'         => 'manage_options',
				'create_allergy'                 => 'manage_options',
				'get_allergy'                    => 'manage_options',
				'update_allergy'                 => 'manage_options',
				'delete_allergy'                 => 'manage_options',
				'list_allergies'                 => 'manage_options',
				'create_prescription'            => 'manage_options',
				'get_prescription'               => 'manage_options',
				'update_prescription'            => 'manage_options',
				'delete_prescription'            => 'manage_options',
				'list_prescriptions'             => 'manage_options',
				'search_prescriptions'           => 'manage_options',
				'create_checkup'                 => 'manage_options',
				'get_checkup'                    => 'manage_options',
				'update_checkup'                 => 'manage_options',
				'delete_checkup'                 => 'manage_options',
				'list_checkups'                  => 'manage_options',
				'get_upcoming_checkups'          => 'manage_options',
				'log_vital_signs'                => 'manage_options',
				'log_health_metrics'             => 'manage_options',
				'import_vitals'                  => 'manage_options',
				'get_medication_schedule'        => 'manage_options',
				'get_member_health_summary'      => 'manage_options',
				'manage_care_plan'               => 'manage_options',
				'guide_health_record_creation'   => 'manage_options',
				'parse_health_information'       => 'manage_options',
				'compile_health_research_data'   => 'manage_options',
				'generate_health_chart'          => 'manage_options',
				'track_vaccinations'             => 'manage_options',
				'export_fhir_data'               => 'manage_options',
				'manage_imaging_studies'         => 'manage_options',
				'interpret_imaging_study'        => 'manage_options',

				// ---- Pro: ECA / educational activities ----------------------
				'create_eca'                     => 'edit_posts',
				'get_eca'                        => 'edit_posts',
				'update_eca'                     => 'edit_posts',
				'delete_eca'                     => 'edit_posts',
				'list_ecas'                      => 'edit_posts',
				'research_eca'                   => 'edit_posts',
				'enroll_student_eca'             => 'edit_posts',
				'withdraw_student_eca'           => 'edit_posts',
				'bulk_enroll_students'           => 'edit_posts',
				'mark_eca_attendance'            => 'edit_posts',
				'manage_eca_term'                => 'edit_posts',
				'manage_eca_waitlist'            => 'edit_posts',
				'set_eca_schedule'               => 'edit_posts',
				'create_eca_workflow_rule'       => 'edit_posts',
				'configure_eca_notifications'    => 'edit_posts',
				'check_eca_conflicts'            => 'edit_posts',
				'import_ecas_csv'                => 'edit_posts',
				'export_eca_data'                => 'edit_posts',
				'get_eca_attendance_report'      => 'edit_posts',
				'get_eca_timetable'              => 'edit_posts',
				'generate_eca_analytics'         => 'manage_options',
				'generate_eca_participation_report' => 'manage_options',
				'get_student_participation_summary' => 'edit_posts',
				'send_eca_notification'          => 'manage_options',
				'send_eca_parent_report'         => 'manage_options',
				'sync_eca_enrollments_from_isams' => 'manage_options',
				'sync_ecas_from_isams'           => 'manage_options',
				'sync_ecas_from_socs'            => 'manage_options',
				'sync_ecas_to_isams'             => 'manage_options',
				'sync_students_from_isams'       => 'manage_options',

				// ---- Pro: quiz / student / learning -------------------------
				'create_quiz'                    => 'edit_posts',
				'get_quiz'                       => 'edit_posts',
				'update_quiz'                    => 'edit_posts',
				'delete_quiz'                    => 'edit_posts',
				'list_quizzes'                   => 'edit_posts',
				'research_quiz_topic'            => 'edit_posts',
				'grade_quiz'                     => 'edit_posts',
				'get_quiz_results'               => 'edit_posts',
				'get_quiz_submissions'           => 'edit_posts',
				'get_quiz_analytics'             => 'manage_options',
				'submit_quiz_answer'             => 'edit_posts',
				'create_student'                 => 'edit_posts',
				'get_student'                    => 'edit_posts',
				'update_student'                 => 'edit_posts',
				'delete_student'                 => 'edit_posts',
				'list_students'                  => 'edit_posts',

				// ---- Pro: member / place / policy ---------------------------
				'create_member'                  => 'edit_posts',
				'get_member'                     => 'edit_posts',
				'update_member'                  => 'edit_posts',
				'delete_member'                  => 'edit_posts',
				'list_members'                   => 'edit_posts',
				'create_place'                   => 'edit_posts',
				'get_place'                      => 'edit_posts',
				'update_place'                   => 'edit_posts',
				'delete_place'                   => 'edit_posts',
				'list_places'                    => 'edit_posts',
				'search_and_save_places'         => 'edit_posts',
				'research_place'                 => 'edit_posts',
				'create_policy'                  => 'manage_options',
				'get_policy'                     => 'manage_options',
				'update_policy'                  => 'manage_options',
				'delete_policy'                  => 'manage_options',
				'list_policies'                  => 'manage_options',
				'search_policies'                => 'manage_options',
				'research_policy'                => 'manage_options',

				// ---- Pro: project / task management -------------------------
				'create_project'                 => 'edit_posts',
				'update_project'                 => 'edit_posts',
				'delete_project'                 => 'edit_posts',
				'list_projects'                  => 'edit_posts',
				'research_project'               => 'edit_posts',
				'create_task'                    => 'edit_posts',
				'update_task'                    => 'edit_posts',
				'delete_task'                    => 'edit_posts',
				'list_tasks'                     => 'edit_posts',
				'add_task_dependency'            => 'edit_posts',
				'remove_task_dependency'         => 'edit_posts',
				'get_task_dependencies'          => 'edit_posts',

				// ---- Pro: event / calendar ----------------------------------
				'create_event'                   => 'edit_posts',
				'update_event'                   => 'edit_posts',
				'delete_event'                   => 'edit_posts',
				'list_events'                    => 'edit_posts',
				'get_calendar_view'              => 'edit_posts',

				// ---- Pro: CRE debt ------------------------------------------
				'cre_servicing_fee_calculator'   => 'manage_options',
				'cre_workout_scenario_modeler'   => 'manage_options',
				'cre_capex_reserve_planner'      => 'manage_options',
				'cre_lease_expiration_manager'   => 'manage_options',
				'cre_watchlist_manager'          => 'manage_options',
				'cre_loan_surveillance_dashboard' => 'manage_options',
				'cre_tenant_credit_analyzer'     => 'manage_options',
				'cre_property_budget_manager'    => 'manage_options',
				'cre_hold_sell_analyzer'         => 'manage_options',
				'cre_asset_disposition_analyzer' => 'manage_options',
				'cre_loan_modification_calculator' => 'manage_options',
				'cre_property_performance_tracker' => 'manage_options',

				// ---- Pro: mathematical (pure compute) ----------------------
				'calculate_derivative'           => 'read',
				'calculate_integral'             => 'read',
				'simplify_expression'            => 'read',
				'solve_equation'                 => 'read',
				'graph_function'                 => 'read',
				'matrix_operations'              => 'read',
				'render_math_equation'           => 'read',
				'generate_password'              => 'read',
				'convert_html_to_markdown'       => 'read',

				// ---- Pro: extended-cognition --------------------------------
				'ext_cog_manage_sensor_permissions' => 'edit_posts',
				'ext_cog_capture_screen'         => 'edit_posts',
				'ext_cog_remember_sensory_context' => 'edit_posts',
				'ext_cog_capture_audio'          => 'edit_posts',
				'ext_cog_get_motion_context'     => 'edit_posts',
				'ext_cog_analyze_sensory_input'  => 'edit_posts',
				'ext_cog_capture_visual'         => 'edit_posts',

				// ---- Pro: vault / security ----------------------------------
				'vault_access'                   => 'manage_options',
				'vault_manage'                   => 'manage_options',

				// ---- Pro: autonomous session --------------------------------
				'manage_autonomous_session'      => 'manage_options',
				'get_session_status'             => 'manage_options',

				// ---- Pro: AI tool builder -----------------------------------
				'create_template'                => 'manage_options',
				'list_templates'                 => 'manage_options',
				'instantiate_template'           => 'manage_options',
				'seed_template_library'          => 'manage_options',

				// ---- Pro: research / analytics helpers ----------------------
				'aggregate_research_data'        => 'edit_posts',
				'generate_research_report'       => 'edit_posts',
				'extract_structured_data'        => 'edit_posts',
				'analyze_data_patterns'          => 'edit_posts',
				'detect_completion_indicators'   => 'edit_posts',
				'check_exit_conditions'          => 'edit_posts',
				'calculate_orchestration_capacity' => 'edit_posts',
				'create_task_plan'               => 'edit_posts',
				'get_task_plan'                  => 'edit_posts',
				'update_task_plan'               => 'edit_posts',
				'analyze_loop_health'            => 'edit_posts',
				'verify_information'             => 'edit_posts',
			);
		}
	}
}
