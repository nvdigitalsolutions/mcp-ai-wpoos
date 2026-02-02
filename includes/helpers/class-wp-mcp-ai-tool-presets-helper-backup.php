<?php
/**
 * Tool Presets Helper.
 *
 * Provides reusable functionality for tool selection presets across the plugin.
 *
 * @package WP_MCP_AI
 * phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Descriptive file names follow WordPress kebab-case conventions for better readability.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper class for managing and rendering tool presets.
 */
class WP_MCP_AI_Tool_Presets_Helper {

	/**
	 * Get the tool presets configuration.
	 *
	 * @return array Array of presets with name, description, and tools.
	 */
	public static function get_presets() {
		$presets = array(
			'ai_ml'               => array(
				'name'        => __( 'AI/ML', 'mcp-ai-wpoos' ),
				'description' => __( 'AI model management, embeddings, batches, and ML operations', 'mcp-ai-wpoos' ),
				'tools'       => array(
					'list_available_models',
					'suggest_best_model',
					'get_model_information',
					'count_tokens',
					'create_text_embeddings',
					'batch_embed_content',
					'semantic_content_search',
					'create_batch',
					'list_batches',
					'get_batch_status',
					'monitor_batch',
					'create_vector_store',
					'list_vector_stores',
					'get_vector_store',
					'manage_vector_store_files',
					'openai_usage_analytics',
					'open_openai_usage',
					'open_openai_logs',
					'moderate_content',
					'analyze_comment_content',
				),
			),
			'media'               => array(
				'name'        => __( 'Media', 'mcp-ai-wpoos' ),
				'description' => __( 'Image, video, and audio generation, editing, and processing tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					'generate_openai_image',
					'generate_gemini_image',
					'edit_gemini_image',
					'edit_openai_image',
					'create_image_variation',
					'resize_image',
					'crop_image',
					'rotate_image',
					'convert_image_format',
					'remove_background',
					'generate_image_alt_text',
					'generate_image_caption',
					'vision_object_localization',
					'vision_product_search',
					'generate_veo_video',
					'generate_sora_video',
					'check_video_status',
					'analyze_video',
					'extract_video_frames',
					'get_video_metadata',
					'generate_video_caption',
					'generate_music',
					'generate_jukebox_music',
					'check_jukebox_status',
					'generate_openai_speech',
					'transcribe_openai_audio',
				),
			),
			'content_writing'     => array(
				'name'        => __( 'Content Writing', 'mcp-ai-wpoos' ),
				'description' => __( 'Tools for creating and managing content, posts, and pages', 'mcp-ai-wpoos' ),
				'tools'       => array(
					'search_content',
					'search_attachments',
					'get_recent_posts',
					'save_post',
					'create_post',
					'create_term',
					'update_term',
					'get_rankmath_seo',
					'generate_openai_image',
					'generate_gemini_image',
					'web_search',
					'semantic_content_search',
					'moderate_content',
					'analyze_comment_content',
					'generate_image_caption',
					'generate_image_alt_text',
					'submit_document_prompt',
				),
			),
			'ecommerce'           => array(
				'name'        => __( 'E-commerce Support', 'mcp-ai-wpoos' ),
				'description' => __( 'WooCommerce and product management tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					'get_woo_recent_orders',
					'get_woo_products',
					'create_woo_product',
					'send_group_email',
					'send_mailjet_email',
					'woo_orders',
					'woo_products',
					'product_actualization',
					'scrape_product',
					'lookup_product_price',
					'crawl4ai_price_lookup',
					'vision_product_search',
					'get_import_duty',
					'remote_wp_connection',
				),
			),
			'site_management'     => array(
				'name'        => __( 'Site Management', 'mcp-ai-wpoos' ),
				'description' => __( 'WordPress core management and monitoring tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					'get_site_summary',
					'get_system_logs',
					'get_update_status',
					'get_site_health',
					'get_environment_status',
					'check_site_security',
					'purge_cache',
					'purge_cloudflare_cache',
					'purge_varnish_cache',
					'create_cron_job',
					'list_cron_jobs',
					'get_cron_job',
					'delete_cron_job',
					'install_and_activate_plugin',
					'install_and_activate_theme',
					'update_option',
					'site_creator',
					'remote_wp_connection',
				),
			),
			'seo_marketing'       => array(
				'name'        => __( 'SEO & Marketing', 'mcp-ai-wpoos' ),
				'description' => __( 'SEO analysis and social media management tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					'get_rankmath_seo',
					'web_search',
					'post_facebook_instagram',
					'post_linkedin_update',
					'get_facebook_instagram_insights',
					'google_analytics_report',
					'create_google_calendar_event',
					'post_tiktok_video',
					'get_tiktok_insights',
					'get_linkedin_insights',
					'post_google_business_update',
					'get_google_business_insights',
					'send_telegram_message',
					'send_whatsapp_message',
					'schedule_notify_sms',
					'search_gmail',
				),
			),
			'development'         => array(
				'name'        => __( 'Development', 'mcp-ai-wpoos' ),
				'description' => __( 'Code snippets, CLI, and technical development tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					'create_wpcode_snippet',
					'check_wp_cli',
					'get_system_logs',
					'count_tokens',
					'probe_chat',
					'query_remote_site',
					'github_repository_operations',
					'list_github_repositories',
					'manage_github_codespace',
					'probe_remote_mcp',
					'query_mesh_intelligent',
					'run_openai_external_action',
					'generic_rest',
					'get_user_info',
					'remote_wp_connection',
				),
			),
			'data_analytics'      => array(
				'name'        => __( 'Data & Analytics', 'mcp-ai-wpoos' ),
				'description' => __( 'Data collection, reporting, and analytics tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					'get_jetengine_items',
					'list_jetengine_rest_routes',
					'invoke_jetengine_route',
					'get_jetformbuilder_forms',
					'get_jetformbuilder_submissions',
					'google_analytics_report',
					'quickbooks_report',
					'jetengine',
					'list_openai_files',
					'get_openai_file_details',
					'analyze_file_suitability',
					'list_professions',
					'get_profession',
					'get_profession_stats',
					'save_profession',
					'create_chart',
				),
			),
			'design_professional' => array(
				'name'        => __( 'Design Professional', 'mcp-ai-wpoos' ),
				'description' => __( 'CAD, rendering, 3D modeling, branding, and visual design tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					'generate_openai_image',
					'generate_gemini_image',
					'edit_gemini_image',
					'edit_openai_image',
					'generate_veo_video',
					'check_video_status',
					'resize_image',
					'crop_image',
					'rotate_image',
					'convert_image_format',
					'create_chart',
					'generate_music',
					'analyze_video',
					'extract_video_frames',
					'get_video_metadata',
					'vision_object_localization',
					'vision_product_search',
					'generate_image_alt_text',
					'generate_image_caption',
					'remove_background',
					'create_image_variation',
					'get_elementor_templates',
					'import_elementor_template_kit',
					'elementor',
				),
			),
		);

		/**
		 * Filter the tool selection presets.
		 *
		 * @param array $presets Array of presets with name, description, and tools.
		 */
		return apply_filters( 'wp_mcp_ai_tool_presets', $presets );
	}

	/**
	 * Render tool preset buttons.
	 *
	 * @param array $args {
	 *     Optional. Arguments for rendering presets.
	 *
	 *     @type array  $available_tools Array of available tool slugs to filter presets. Default empty (no filtering).
	 *     @type string $title           Title for the presets section. Default 'Quick Tool Selection Presets'.
	 *     @type string $description     Description text. Default preset description.
	 *     @type string $button_class    Additional CSS class for buttons. Default 'button'.
	 *     @type string $container_class CSS class for the container. Default 'wp-mcp-ai-tool-presets'.
	 *     @type bool   $include_script  Whether to include JavaScript. Default true.
	 *     @type string $checkbox_selector CSS selector for tool checkboxes. Default 'input[name="wp_mcp_ai_tools[]"]'.
	 * }
	 */
	public static function render_presets( $args = array() ) {
		$defaults = array(
			'available_tools'   => array(),
			'title'             => __( 'Quick Tool Selection Presets', 'mcp-ai-wpoos' ),
			'description'       => __( 'Click a preset to add its tools to your selection. Click again to remove them. You can combine multiple presets.', 'mcp-ai-wpoos' ),
			'button_class'      => 'button',
			'container_class'   => 'wp-mcp-ai-tool-presets',
			'include_script'    => true,
			'checkbox_selector' => 'input[name="wp_mcp_ai_tools[]"]',
		);

		$args    = wp_parse_args( $args, $defaults );
		$presets = self::get_presets();

		if ( empty( $presets ) ) {
			return;
		}

		// If available tools are provided, filter presets.
		$filter_tools = ! empty( $args['available_tools'] ) && is_array( $args['available_tools'] );

		$container_style = 'margin-top: 1rem;';
		$title_style     = 'margin-top: 0; margin-bottom: 0.5rem; font-size: 14px;';
		$desc_style      = 'margin-top: 0; margin-bottom: 1rem;';
		$buttons_style   = 'display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem;';

		echo '<div class="' . esc_attr( $args['container_class'] ) . '" style="' . esc_attr( $container_style ) . '">';
		echo '<h3 class="' . esc_attr( $args['container_class'] ) . '__title" style="' . esc_attr( $title_style ) . '">' . esc_html( $args['title'] ) . '</h3>';
		echo '<p class="' . esc_attr( $args['container_class'] ) . '__description description" style="' . esc_attr( $desc_style ) . '">' . esc_html( $args['description'] ) . '</p>';
		echo '<div class="' . esc_attr( $args['container_class'] ) . '__buttons" style="' . esc_attr( $buttons_style ) . '">';

		foreach ( $presets as $preset_key => $preset_data ) {
			if ( ! isset( $preset_data['name'], $preset_data['tools'] ) || ! is_array( $preset_data['tools'] ) ) {
				continue;
			}

			$preset_tools = $preset_data['tools'];

			// Filter to only include available tools if filtering is enabled.
			if ( $filter_tools ) {
				$preset_tools = array_intersect( $preset_tools, $args['available_tools'] );
				if ( empty( $preset_tools ) ) {
					continue;
				}
			}

			$preset_name        = sanitize_text_field( $preset_data['name'] );
			$preset_description = isset( $preset_data['description'] ) ? sanitize_text_field( $preset_data['description'] ) : '';
			$preset_tools_json  = wp_json_encode( array_values( $preset_tools ) );

			printf(
				'<button type="button" class="%1$s wp-mcp-ai-tool-preset-btn" data-preset="%2$s" data-tools="%3$s" title="%4$s">%5$s</button>',
				esc_attr( $args['button_class'] ),
				esc_attr( $preset_key ),
				esc_attr( $preset_tools_json ),
				esc_attr( $preset_description ),
				esc_html( $preset_name )
			);
		}

		echo '</div>';
		echo '</div>';

		// Include JavaScript if requested.
		if ( $args['include_script'] ) {
			self::render_preset_script( $args['checkbox_selector'] );
		}
	}

	/**
	 * Render the JavaScript for preset functionality.
	 *
	 * @param string $checkbox_selector CSS selector for tool checkboxes.
	 */
	protected static function render_preset_script( $checkbox_selector ) {
		static $preset_script_printed = false;

		if ( $preset_script_printed ) {
			return;
		}

		$preset_script_printed = true;
		?>
		<script type="text/javascript">
		( function() {
			'use strict';

			document.addEventListener( 'DOMContentLoaded', function() {
				var presetButtons = document.querySelectorAll( '.wp-mcp-ai-tool-preset-btn' );
				var checkboxSelector = <?php echo wp_json_encode( $checkbox_selector ); ?>;

				presetButtons.forEach( function( button ) {
					button.addEventListener( 'click', function( e ) {
						e.preventDefault();

						var toolsData = button.getAttribute( 'data-tools' );
						if ( ! toolsData ) {
							return;
						}

						var presetTools;
						try {
							presetTools = JSON.parse( toolsData );
						} catch ( error ) {
							console.error( 'Failed to parse preset tools:', error );
							return;
						}

						if ( ! Array.isArray( presetTools ) ) {
							return;
						}

						// Check if preset is currently active (toggle behavior).
						var isActive = button.classList.contains( 'wp-mcp-ai-preset-active' );

						if ( isActive ) {
							// Deactivate: uncheck all tools in this preset.
							presetTools.forEach( function( toolSlug ) {
								var checkbox = document.querySelector( checkboxSelector + '[value="' + toolSlug + '"]' );
								if ( checkbox && checkbox.checked ) {
									checkbox.checked = false;
									// Trigger change event to update UI.
									var event = new Event( 'change', { bubbles: true } );
									checkbox.dispatchEvent( event );
								}
							} );

							// Remove active state.
							button.classList.remove( 'wp-mcp-ai-preset-active' );
							button.style.backgroundColor = '';
							button.style.color = '';
							button.style.borderColor = '';
						} else {
							// Activate: check all tools in this preset (add to current selection).
							presetTools.forEach( function( toolSlug ) {
								var checkbox = document.querySelector( checkboxSelector + '[value="' + toolSlug + '"]' );
								if ( checkbox && ! checkbox.checked ) {
									checkbox.checked = true;
									// Trigger change event to update UI.
									var event = new Event( 'change', { bubbles: true } );
									checkbox.dispatchEvent( event );
								}
							} );

							// Add active state.
							button.classList.add( 'wp-mcp-ai-preset-active' );
							button.style.backgroundColor = '#2271b1';
							button.style.color = '#fff';
							button.style.borderColor = '#2271b1';
						}
					} );
				} );
			} );
		} )();
		</script>
		<?php
	}
}
