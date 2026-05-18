<?php
/**
 * Tests for the central tool capability map.
 *
 * The map at `includes/class-wp-mcp-ai-tool-capability-map.php` is the
 * authoritative slug → WordPress-capability fallback consulted by
 * {@see WP_MCP_AI_REST::build_tools_payload()} whenever a tool does not
 * declare `get_required_capability()` itself.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * @group capability-map
 */
class Test_Tool_Required_Capability_Coverage extends WP_UnitTestCase {

	/**
	 * The capability resolver must always return a non-empty string.
	 *
	 * Even when both the tool's own method and the central map are missing,
	 * the resolver falls back to the safe default (`edit_posts`). This is the
	 * guarantee that closes the historical leak.
	 *
	 * @return void
	 */
	public function test_resolve_falls_back_to_safe_default() {
		$capability = WP_MCP_AI_Tool_Capability_Map::resolve( null, 'completely_unmapped_slug_xyz' );

		$this->assertSame( WP_MCP_AI_Tool_Capability_Map::DEFAULT_CAPABILITY, $capability );
		$this->assertNotEmpty( $capability );
	}

	/**
	 * A slug present in the map resolves to that capability when the tool
	 * itself does not declare one.
	 *
	 * @return void
	 */
	public function test_resolve_uses_map_when_tool_declares_nothing() {
		// `get_recent_posts` → `read` per the audit.
		$this->assertSame( 'read', WP_MCP_AI_Tool_Capability_Map::resolve( null, 'get_recent_posts' ) );

		// `delete_post` → `delete_posts` (destructive).
		$this->assertSame( 'delete_posts', WP_MCP_AI_Tool_Capability_Map::resolve( null, 'delete_post' ) );

		// `create_cron_job` → `manage_options` (admin surface).
		$this->assertSame( 'manage_options', WP_MCP_AI_Tool_Capability_Map::resolve( null, 'create_cron_job' ) );

		// `install_and_activate_plugin` → `install_plugins`.
		$this->assertSame( 'install_plugins', WP_MCP_AI_Tool_Capability_Map::resolve( null, 'install_and_activate_plugin' ) );
	}

	/**
	 * A tool's own `get_required_capability()` method wins over the map.
	 *
	 * @return void
	 */
	public function test_tool_method_overrides_map() {
		$tool = new class() {
			public function get_required_capability() {
				return 'manage_woocommerce';
			}
		};

		// `get_recent_posts` is mapped to `read`, but the per-class method wins.
		$this->assertSame(
			'manage_woocommerce',
			WP_MCP_AI_Tool_Capability_Map::resolve( $tool, 'get_recent_posts' )
		);
	}

	/**
	 * `*_validated` wrappers must resolve to the same capability as their
	 * un-validated peers. This is the security invariant: a wrapper must
	 * never accidentally widen access.
	 *
	 * @return void
	 */
	public function test_validated_wrappers_mirror_base_capability() {
		$pairs = array(
			'save_post'                 => 'save_post_validated',
			'create_cron_job'           => 'create_cron_job_validated',
			'search_content'            => 'search_content_validated',
			'create_assistant'          => 'create_assistant_validated',
			'get_recent_posts'          => 'get_recent_posts_validated',
			'get_system_logs'           => 'get_system_logs_validated',
			'create_chart'              => 'create_chart_validated',
			'send_group_email'          => 'send_group_email_validated',
			'create_woo_product'        => 'create_woo_product_validated',
			'create_post'               => 'create_post_validated',
			'transcribe_openai_audio'   => 'transcribe_openai_audio_validated',
			'generate_image_alt_text'   => 'generate_image_alt_text_validated',
			'generate_image_caption'    => 'generate_image_caption_validated',
			'generate_openai_speech'    => 'generate_openai_speech_validated',
			'generate_music'            => 'generate_music_validated',
			'generate_gemini_image'     => 'generate_gemini_image_validated',
			'generate_veo_video'        => 'generate_veo_video_validated',
			'generate_openai_image'     => 'generate_openai_image_validated',
			'web_search'                => 'web_search_validated',
			'edit_gemini_image'         => 'edit_gemini_image_validated',
			'scrape_product'            => 'scrape_product_validated',
			'run_crawl4ai_job'          => 'run_crawl4ai_job_validated',
		);

		foreach ( $pairs as $base => $validated ) {
			$this->assertSame(
				WP_MCP_AI_Tool_Capability_Map::resolve( null, $base ),
				WP_MCP_AI_Tool_Capability_Map::resolve( null, $validated ),
				sprintf( '%s and %s must resolve to the same capability', $base, $validated )
			);
		}
	}

	/**
	 * Sensitive destructive / admin-only slugs must NEVER fall back to
	 * `edit_posts`. Subscriber-level chats must not see these tools.
	 *
	 * @return void
	 */
	public function test_sensitive_slugs_are_locked_to_manage_options_or_stricter() {
		$sensitive = array(
			'create_cron_job', 'list_cron_jobs', 'delete_cron_job', 'get_cron_job',
			'purge_cache', 'purge_cloudflare_cache', 'purge_varnish_cache',
			'send_group_email',
			'get_system_logs', 'get_environment_status', 'get_update_status',
			'open_openai_logs', 'open_openai_usage',
			'query_remote_site', 'query_mesh_intelligent', 'remote_wp_connection',
			'site_creator', 'update_option',
			'check_wp_cli',
			'check_site_security', 'generate_auth0_token', 'generate_simple_jwt_token',
			'probe_chat', 'probe_remote_mcp',
		);

		$strict = array( 'manage_options', 'install_plugins', 'install_themes', 'delete_posts' );

		foreach ( $sensitive as $slug ) {
			$capability = WP_MCP_AI_Tool_Capability_Map::resolve( null, $slug );
			$this->assertContains(
				$capability,
				$strict,
				sprintf( 'Sensitive slug %s resolved to %s — must be one of: %s', $slug, $capability, implode( ', ', $strict ) )
			);
		}
	}

	/**
	 * Every slug that appears in the audit's "Missing!" column must now
	 * resolve to a non-empty capability via the map. This is the regression
	 * guard against the historical leak.
	 *
	 * @return void
	 */
	public function test_all_previously_missing_slugs_now_resolve() {
		// Subset chosen as representative across every PR scope in the plan.
		$slugs = array(
			// Core read.
			'get_recent_posts', 'search_content', 'get_user_info', 'get_site_summary',
			'count_tokens', 'load_skill', 'get_post', 'search_attachments',

			// OpenAI / models.
			'list_openai_files', 'list_available_models', 'research_model',
			'add_model_config', 'discover_new_models', 'create_text_embeddings',
			'batch_embed_content',

			// Generation.
			'generate_openai_image', 'generate_sora_video', 'generate_gemini_image',
			'cloudflareai_text_to_image', 'generate_veo_video', 'generate_music',

			// Posts / terms.
			'save_post', 'create_post', 'delete_post', 'create_term', 'update_term',
			'create_assistant',

			// Cron / cache / email / remote.
			'create_cron_job', 'delete_cron_job', 'send_group_email', 'purge_cache',
			'remote_wp_connection',

			// Agent / orchestration / memory.
			'create_agent_team', 'delegate_to_agent', 'delegate_to_a2a_agent',
			'store_agent_context', 'retrieve_agent_memory', 'execute_workflow',

			// Vision / image / video / OCR / docs.
			'analyze_video', 'generate_video_caption', 'analyze_comment_content',
			'resize_image', 'crop_image', 'rotate_image', 'convert_image_format',
			'vectorize_image', 'extract_video_frames', 'remove_background',
			'generate_pdf', 'generate_word', 'generate_excel', 'merge_pdfs',
			'html_to_pdf', 'add_watermark_to_pdf', 'extract_pdf_text', 'ocr_pdf_text',

			// Channels / messaging / social.
			'send_slack_message', 'send_discord_message', 'send_teams_message',
			'send_outlook_mail', 'send_messenger_message', 'send_whatsapp_message',
			'send_telegram_message', 'send_mailjet_email', 'send_brevo_email',
			'send_mailgun_email', 'post_facebook_instagram', 'post_tiktok_video',
			'post_linkedin_update', 'post_google_business_update',

			// Commerce.
			'get_woo_recent_orders', 'get_woo_products', 'create_woo_product',
			'flowhub_get_inventory', 'flowhub_create_order', 'payhere_get_payment',
			'woo_products', 'woo_orders', 'woo_customers', 'woo_coupons',
			'shopify_products', 'shopify_orders', 'shopify_customers',
			'create_product_advanced', 'bulk_update_products', 'segment_customers',

			// JetEngine / Elementor / WPCode / GitHub / site builder.
			'get_jetengine_items', 'invoke_jetengine_route', 'import_elementor_template_kit',
			'create_wpcode_snippet', 'site_creator', 'install_and_activate_plugin',
			'install_and_activate_theme', 'update_option', 'github_repository_operations',

			// Scheduling.
			'create_pro_schedule', 'delete_pro_schedule', 'schedule_channel_broadcast',

			// Harness / prompt cues / reasoning.
			'list_prompt_cues', 'select_prompt_cue', 'apply_prompt_cue',
			'self_consistency_vote', 'record_reflection', 'scope_memory',

			// Validated wrappers.
			'save_post_validated', 'create_cron_job_validated', 'create_woo_product_validated',
			'generate_openai_image_validated', 'web_search_validated',
		);

		foreach ( $slugs as $slug ) {
			$capability = WP_MCP_AI_Tool_Capability_Map::resolve( null, $slug );
			$this->assertNotEmpty(
				$capability,
				sprintf( 'Slug %s must resolve to a non-empty capability via the map or default', $slug )
			);
		}
	}

	/**
	 * The `wp_mcp_ai_tool_required_capability` filter can override per-slug.
	 *
	 * @return void
	 */
	public function test_filter_can_override_capability() {
		$callback = static function ( $cap, $slug ) {
			return 'get_recent_posts' === $slug ? 'manage_options' : $cap;
		};

		add_filter( 'wp_mcp_ai_tool_required_capability', $callback, 10, 2 );
		try {
			$this->assertSame(
				'manage_options',
				WP_MCP_AI_Tool_Capability_Map::resolve( null, 'get_recent_posts' )
			);
		} finally {
			remove_filter( 'wp_mcp_ai_tool_required_capability', $callback, 10 );
		}
	}

	/**
	 * The `wp_mcp_ai_tool_capability_map` filter can extend the map.
	 *
	 * @return void
	 */
	public function test_filter_can_extend_map() {
		WP_MCP_AI_Tool_Capability_Map::reset_cache();

		$callback = static function ( $map ) {
			$map['my_custom_tool'] = 'manage_woocommerce';
			return $map;
		};

		add_filter( 'wp_mcp_ai_tool_capability_map', $callback );
		try {
			$this->assertSame(
				'manage_woocommerce',
				WP_MCP_AI_Tool_Capability_Map::resolve( null, 'my_custom_tool' )
			);
		} finally {
			remove_filter( 'wp_mcp_ai_tool_capability_map', $callback );
			WP_MCP_AI_Tool_Capability_Map::reset_cache();
		}
	}
}
