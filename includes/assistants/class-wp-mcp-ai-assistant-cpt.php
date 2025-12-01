<?php
/**
 * Assistant custom post type.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
	/**
	 * Registers the assistant custom post type and associated meta boxes.
	 */
	class WP_MCP_AI_Assistant_CPT {
		const POST_TYPE                    = 'mcp_ai_assistant';
		const META_TOOLS                   = '_wp_mcp_ai_tools';
		const META_PROVIDER                = '_wp_mcp_ai_provider';
		const META_MODEL                   = '_wp_mcp_ai_model';
		const META_TEMPERATURE             = '_wp_mcp_ai_temperature';
		const META_SYSTEM_PROMPT           = '_wp_mcp_ai_system_prompt';
		const META_MEMORY_FILES            = '_wp_mcp_ai_memory_files';
		const META_VECTOR_STORE_ID         = '_wp_mcp_ai_vector_store_id';
		const META_TOOL_SHORTCUTS          = '_wp_mcp_ai_tool_shortcuts';
		const META_TOOL_PREBUILT_SHORTCUTS = '_wp_mcp_ai_tool_prebuilt_shortcuts';
		const META_DISABLE_TOOL_SHORTCUTS  = '_wp_mcp_ai_disable_tool_shortcuts';
		const META_TOOL_ROLE_RULES         = '_wp_mcp_ai_tool_role_rules';
		const META_CREDENTIALS             = WP_MCP_AI_Credentials::META_KEY;
		const META_EXTERNAL_ACTION_ID      = '_wp_mcp_ai_external_action_id';
		const META_EXTERNAL_ACTION_TYPE    = '_wp_mcp_ai_external_action_type';
		const META_REQUIRED_CAPABILITY     = 'mcp_ai_required_capability';
		const META_PRIMARY_ROLES           = '_wp_mcp_ai_primary_roles';
		const SYNC_LOCK_TIMEOUT            = 5;

		/**
		 * Tool registry instance.
		 *
		 * @var WP_MCP_AI_Tool_Registry
		 */
		protected $registry;

		/**
		 * Metabox instances.
		 *
		 * @var array<string, WP_MCP_AI_Metabox_Base>
		 */
		protected $metaboxes = array();

		/**
		 * Track whether the credential action script has been printed.
		 *
		 * @var bool
		 */
		protected static $credential_action_script_printed = false;

		/**
		 * Constructor.
		 *
		 * @param WP_MCP_AI_Tool_Registry $registry Tool registry.
		 */
		public function __construct( WP_MCP_AI_Tool_Registry $registry ) {
			$this->registry = $registry;

			// Initialize metabox instances.
			$this->metaboxes['credentials']    = new WP_MCP_AI_Metabox_Credentials( $this );
			$this->metaboxes['defaults']       = new WP_MCP_AI_Metabox_Defaults( $this );
			$this->metaboxes['primary-roles']  = new WP_MCP_AI_Metabox_Primary_Roles( $this );
			$this->metaboxes['base-knowledge'] = new WP_MCP_AI_Metabox_Base_Knowledge( $this );
			$this->metaboxes['mesh-routing']   = new WP_MCP_AI_Metabox_Mesh_Routing( $this );

			add_action( 'init', array( __CLASS__, 'register_post_type' ) );
			add_action( 'init', array( __CLASS__, 'register_meta' ) );
			add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'disable_block_editor_for_post_type' ), 10, 2 );
			add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
			add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_post' ), 10, 2 );
			add_action( 'admin_post_wp_mcp_ai_issue_credential', array( $this, 'handle_issue_credential' ) );
			add_action( 'admin_post_wp_mcp_ai_revoke_credential', array( $this, 'handle_revoke_credential' ) );
			add_action( 'admin_post_wp_mcp_ai_delete_credential', array( $this, 'handle_delete_credential' ) );
			// Register admin_notices on init to avoid early translation loading (WordPress 6.7.0+).
			add_action( 'init', array( $this, 'register_admin_notices' ) );
			add_action( 'delete_' . self::POST_TYPE, array( $this, 'cleanup_deleted_assistant_credentials' ) );
		}

		/**
		 * Register admin notices on init action.
		 *
		 * WordPress 6.7.0+ requires translations to be loaded at init or later.
		 */
		public function register_admin_notices() {
			add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
		}

		/**
		 * Get tool selection presets for common use cases.
		 *
		 * @return array Array of presets with name, description, and tools.
		 */
		protected function get_tool_presets() {
			$presets = array(
				'content_writing'     => array(
					'name'        => __( 'Content Writing', 'wp-mcp-ai' ),
					'description' => __( 'Tools for creating and managing content, posts, and pages', 'wp-mcp-ai' ),
					'tools'       => array(
						'search_content',
						'search_attachments',
						'get_recent_posts',
						'save_post',
						'get_rankmath_seo',
						'generate_openai_image',
						'generate_gemini_image',
						'web_search',
					),
				),
				'ecommerce'           => array(
					'name'        => __( 'E-commerce Support', 'wp-mcp-ai' ),
					'description' => __( 'WooCommerce and product management tools', 'wp-mcp-ai' ),
					'tools'       => array(
						'get_woo_recent_orders',
						'get_woo_products',
						'create_woo_product',
						'send_group_email',
						'send_mailjet_email',
					),
				),
				'site_management'     => array(
					'name'        => __( 'Site Management', 'wp-mcp-ai' ),
					'description' => __( 'WordPress core management and monitoring tools', 'wp-mcp-ai' ),
					'tools'       => array(
						'get_site_summary',
						'get_system_logs',
						'get_update_status',
						'get_site_health',
						'get_environment_status',
						'check_site_security',
						'purge_cache',
						'create_cron_job',
						'list_cron_jobs',
					),
				),
				'seo_marketing'       => array(
					'name'        => __( 'SEO & Marketing', 'wp-mcp-ai' ),
					'description' => __( 'SEO analysis and social media management tools', 'wp-mcp-ai' ),
					'tools'       => array(
						'get_rankmath_seo',
						'web_search',
						'post_facebook_instagram',
						'post_linkedin_update',
						'get_facebook_instagram_insights',
						'google_analytics_report',
						'create_google_calendar_event',
					),
				),
				'development'         => array(
					'name'        => __( 'Development', 'wp-mcp-ai' ),
					'description' => __( 'Code snippets, CLI, and technical development tools', 'wp-mcp-ai' ),
					'tools'       => array(
						'create_wpcode_snippet',
						'check_wp_cli',
						'get_system_logs',
						'count_tokens',
						'probe_chat',
						'query_remote_site',
					),
				),
				'data_analytics'      => array(
					'name'        => __( 'Data & Analytics', 'wp-mcp-ai' ),
					'description' => __( 'Data collection, reporting, and analytics tools', 'wp-mcp-ai' ),
					'tools'       => array(
						'get_jetengine_items',
						'list_jetengine_rest_routes',
						'invoke_jetengine_route',
						'get_jetformbuilder_forms',
						'get_jetformbuilder_submissions',
						'google_analytics_report',
						'quickbooks_report',
					),
				),
				'design_professional' => array(
					'name'        => __( 'Design Professional', 'wp-mcp-ai' ),
					'description' => __( 'CAD, rendering, 3D modeling, branding, and visual design tools', 'wp-mcp-ai' ),
					'tools'       => array(
						'generate_openai_image',
						'generate_gemini_image',
						'edit_gemini_image',
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
		 * Render tool selection preset buttons.
		 *
		 * @param array $selected_tools Currently selected tool slugs.
		 */
		protected function render_tool_presets( $selected_tools ) {
			$presets = $this->get_tool_presets();

			if ( empty( $presets ) ) {
				return;
			}

			// Get all available tools for validation.
			$available_tools = array();
			foreach ( $this->registry->get_tools() as $tool ) {
				if ( $tool instanceof WP_MCP_AI_Tool_Interface ) {
					$available_tools[] = $tool->get_slug();
				}
			}

			echo '<div class="wp-mcp-ai-tool-presets" style="margin-top: 1rem;">';
			echo '<h3 style="margin-top: 0; margin-bottom: 0.5rem; font-size: 14px;">' . esc_html__( 'Quick Tool Selection Presets', 'wp-mcp-ai' ) . '</h3>';
			echo '<p class="description" style="margin-top: 0; margin-bottom: 1rem;">' . esc_html__( 'Click a preset to quickly select tools for common tasks. This will replace your current tool selection.', 'wp-mcp-ai' ) . '</p>';
			echo '<div class="wp-mcp-ai-tool-presets__buttons" style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem;">';

			foreach ( $presets as $preset_key => $preset_data ) {
				if ( ! isset( $preset_data['name'], $preset_data['tools'] ) || ! is_array( $preset_data['tools'] ) ) {
					continue;
				}

				// Filter tools to only include those that are actually available.
				$preset_tools = array_intersect( $preset_data['tools'], $available_tools );
				if ( empty( $preset_tools ) ) {
					continue;
				}

				$preset_name        = sanitize_text_field( $preset_data['name'] );
				$preset_description = isset( $preset_data['description'] ) ? sanitize_text_field( $preset_data['description'] ) : '';
				$preset_tools_json  = wp_json_encode( array_values( $preset_tools ) );

				printf(
					'<button type="button" class="button wp-mcp-ai-tool-preset-btn" data-preset="%1$s" data-tools="%2$s" title="%3$s">%4$s</button>',
					esc_attr( $preset_key ),
					esc_attr( $preset_tools_json ),
					esc_attr( $preset_description ),
					esc_html( $preset_name )
				);
			}

			echo '</div>';
			echo '</div>';

			// Add JavaScript for preset functionality.
			static $preset_script_printed = false;
			if ( ! $preset_script_printed ) {
				$preset_script_printed = true;
				?>
				<script type="text/javascript">
				( function() {
					document.addEventListener( 'DOMContentLoaded', function() {
						var presetButtons = document.querySelectorAll( '.wp-mcp-ai-tool-preset-btn' );

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

								// First, uncheck all tool checkboxes.
								var allToolCheckboxes = document.querySelectorAll( '.wp-mcp-ai-tools__checkbox' );
								allToolCheckboxes.forEach( function( checkbox ) {
									if ( checkbox.checked ) {
										checkbox.checked = false;
										// Trigger change event to update UI.
										var event = new Event( 'change', { bubbles: true } );
										checkbox.dispatchEvent( event );
									}
								} );

								// Then, check the checkboxes for tools in the preset.
								presetTools.forEach( function( toolSlug ) {
									var checkbox = document.querySelector( 'input[name="wp_mcp_ai_tools[]"][value="' + toolSlug + '"]' );
									if ( checkbox && ! checkbox.checked ) {
										checkbox.checked = true;
										// Trigger change event to update UI.
										var event = new Event( 'change', { bubbles: true } );
										checkbox.dispatchEvent( event );
									}
								} );

								// Scroll to the tools section.
								var toolsSection = document.querySelector( '.wp-mcp-ai-tools' );
								if ( toolsSection ) {
									toolsSection.scrollIntoView( { behavior: 'smooth', block: 'start' } );
								}

								// Show a brief visual confirmation.
								button.style.backgroundColor = '#2271b1';
								button.style.color = '#fff';
								button.style.borderColor = '#2271b1';
								setTimeout( function() {
									button.style.backgroundColor = '';
									button.style.color = '';
									button.style.borderColor = '';
								}, 500 );
							} );
						} );
					} );
				} )();
				</script>
				<?php
			}
		}

		/**
		 * Render controls that allow editing the pre-built shortcuts contributed by tools.
		 *
		 * @param WP_Post $post               Post object.
		 * @param array   $selected_tools     Selected tool slugs.
		 * @param array   $prebuilt_shortcuts Sanitized custom pre-built shortcut configuration.
		 */
		protected function render_prebuilt_shortcuts_editor( $post, array $selected_tools, array $prebuilt_shortcuts ) {
			if ( ! $post instanceof WP_Post ) {
				return;
			}

			$selected_tools = array_values(
				array_unique(
					array_filter(
						array_map( 'sanitize_key', $selected_tools )
					)
				)
			);

			echo '<div class="wp-mcp-ai-prebuilt-shortcuts">';
			echo '<h3>' . esc_html__( 'Pre-built prompt shortcuts', 'wp-mcp-ai' ) . '</h3>';
			echo '<p class="description">' . esc_html__( 'Adjust the shortcuts that selected tools contribute to the assistant chat interface.', 'wp-mcp-ai' ) . '</p>';

			if ( empty( $selected_tools ) ) {
				echo '<p class="description">' . esc_html__( 'Select at least one tool above to configure its pre-built shortcuts.', 'wp-mcp-ai' ) . '</p>';
			}

			$default_shortcuts_map = $this->get_default_prebuilt_shortcuts_map( $selected_tools, $post->ID );

			$tool_index = 0;

			foreach ( $selected_tools as $tool_slug ) {
				$tool = $this->registry->get_tool( $tool_slug );

				if ( ! $tool ) {
					continue;
				}

				$tool_name           = $tool->get_name();
				$defaults            = isset( $default_shortcuts_map[ $tool_slug ] ) ? $default_shortcuts_map[ $tool_slug ] : array();
				$settings            = isset( $prebuilt_shortcuts[ $tool_slug ] ) ? $prebuilt_shortcuts[ $tool_slug ] : array();
				$mode                = ( isset( $settings['mode'] ) && 'custom' === $settings['mode'] ) ? 'custom' : 'inherit';
				$custom_rows         = ( 'custom' === $mode && isset( $settings['shortcuts'] ) && is_array( $settings['shortcuts'] ) ) ? $settings['shortcuts'] : array();
				$next_index          = ( 'custom' === $mode ) ? count( $custom_rows ) : 0;
				$defaults_json       = wp_json_encode( $defaults );
				$has_existing_custom = ( 'custom' === $mode );
				$rows_aria_hidden    = ( 'custom' === $mode ) ? 'false' : 'true';
				$mode_label_inherit  = __( 'Using defaults', 'wp-mcp-ai' );
				$mode_label_custom   = __( 'Custom prompts', 'wp-mcp-ai' );
				$mode_label          = ( 'custom' === $mode ) ? $mode_label_custom : $mode_label_inherit;
				$open_attr           = ( 0 === $tool_index || 'custom' === $mode ) ? ' open' : '';

				if ( false === $defaults_json ) {
					$defaults_json = '[]';
				}

				echo '<details class="wp-mcp-ai-prebuilt-shortcuts__tool" data-tool="' . esc_attr( $tool_slug ) . '" data-defaults="' . esc_attr( $defaults_json ) . '" data-has-existing-custom="' . ( $has_existing_custom ? 'true' : 'false' ) . '" data-mode-label-inherit="' . esc_attr( $mode_label_inherit ) . '" data-mode-label-custom="' . esc_attr( $mode_label_custom ) . '"' . esc_attr( $open_attr ) . '>';
				echo '<summary class="wp-mcp-ai-prebuilt-shortcuts__summary">';
				echo '<span class="wp-mcp-ai-prebuilt-shortcuts__summary-title">' . esc_html( $tool_name ) . '</span>';
				echo '<span class="wp-mcp-ai-prebuilt-shortcuts__summary-mode" aria-live="polite">' . esc_html( $mode_label ) . '</span>';
				echo '</summary>';
				echo '<div class="wp-mcp-ai-prebuilt-shortcuts__content">';
				echo '<p class="wp-mcp-ai-prebuilt-shortcuts__mode">';
				printf(
					'<label><input type="radio" name="wp_mcp_ai_prebuilt_shortcuts[%1$s][mode]" value="inherit" %2$s /> %3$s</label>',
					esc_attr( $tool_slug ),
					checked( 'inherit', $mode, false ),
					esc_html__( 'Use defaults', 'wp-mcp-ai' )
				);
				printf(
					'<label><input type="radio" name="wp_mcp_ai_prebuilt_shortcuts[%1$s][mode]" value="custom" %2$s /> %3$s</label>',
					esc_attr( $tool_slug ),
					checked( 'custom', $mode, false ),
					esc_html__( 'Customize', 'wp-mcp-ai' )
				);
				echo '</p>';

				echo '<div class="wp-mcp-ai-prebuilt-shortcuts__defaults">';
				if ( ! empty( $defaults ) ) {
					echo '<p class="description">' . esc_html__( 'Default prompts provided by this tool:', 'wp-mcp-ai' ) . '</p>';
					echo '<ul class="wp-mcp-ai-prebuilt-shortcuts__defaults-list">';

					foreach ( $defaults as $default_shortcut ) {
						$default_label   = isset( $default_shortcut['label'] ) ? (string) $default_shortcut['label'] : '';
						$default_payload = isset( $default_shortcut['payload'] ) ? (string) $default_shortcut['payload'] : '';
						$summary         = '';

						if ( '' !== $default_payload ) {
							$summary = wp_html_excerpt( $default_payload, 100, '&hellip;' );
						}

						echo '<li>';
						if ( '' !== $default_label ) {
							echo '<strong>' . esc_html( $default_label ) . '</strong>';
						} else {
							echo '<strong>' . esc_html__( 'Shortcut', 'wp-mcp-ai' ) . '</strong>';
						}

						if ( '' !== $summary ) {
							echo '<span class="wp-mcp-ai-prebuilt-shortcuts__defaults-summary"> ' . esc_html( $summary ) . '</span>';
						}

						echo '</li>';
					}

					echo '</ul>';
				} else {
					echo '<p class="description">' . esc_html__( 'This tool does not provide any pre-built shortcuts.', 'wp-mcp-ai' ) . '</p>';
				}
				echo '</div>';

				echo '<div class="wp-mcp-ai-prebuilt-shortcuts__rows" data-tool="' . esc_attr( $tool_slug ) . '" data-next-index="' . esc_attr( $next_index ) . '" aria-hidden="' . esc_attr( $rows_aria_hidden ) . '"';
				if ( 'custom' !== $mode ) {
					echo ' hidden';
				}
				echo '>';

				if ( 'custom' === $mode ) {
					foreach ( $custom_rows as $index => $shortcut ) {
						$index       = intval( $index );
						$label       = isset( $shortcut['label'] ) ? (string) $shortcut['label'] : '';
						$payload     = isset( $shortcut['payload'] ) ? (string) $shortcut['payload'] : '';
						$description = isset( $shortcut['description'] ) ? (string) $shortcut['description'] : '';
						/* translators: 1: Shortcut number. 2: Tool name. */
						$legend_text = sprintf( __( 'Shortcut %1$d for %2$s', 'wp-mcp-ai' ), $index + 1, $tool_name );

						echo '<fieldset class="wp-mcp-ai-prebuilt-shortcuts__row" data-index="' . esc_attr( $index ) . '">';
						echo '<legend class="screen-reader-text">' . esc_html( $legend_text ) . '</legend>';
						echo '<p>';
						printf(
							'<label><strong>%1$s</strong><input type="text" class="widefat" name="wp_mcp_ai_prebuilt_shortcuts[%2$s][shortcuts][%3$s][label]" value="%4$s"%5$s /></label>',
							esc_html__( 'Shortcut label', 'wp-mcp-ai' ),
							esc_attr( $tool_slug ),
							esc_attr( $index ),
							esc_attr( $label ),
							disabled( 'custom' !== $mode, true, false )
						);
						echo '</p>';
						echo '<p>';
						printf(
							'<label><strong>%1$s</strong><textarea class="widefat" rows="4" name="wp_mcp_ai_prebuilt_shortcuts[%2$s][shortcuts][%3$s][payload]"%4$s>%5$s</textarea></label>',
							esc_html__( 'Prompt text', 'wp-mcp-ai' ),
							esc_attr( $tool_slug ),
							esc_attr( $index ),
							disabled( 'custom' !== $mode, true, false ),
							esc_textarea( $payload )
						);
						echo '</p>';
						echo '<p>';
						printf(
							'<label><strong>%1$s</strong><textarea class="widefat" rows="3" name="wp_mcp_ai_prebuilt_shortcuts[%2$s][shortcuts][%3$s][description]"%4$s>%5$s</textarea></label>',
							esc_html__( 'Optional description', 'wp-mcp-ai' ),
							esc_attr( $tool_slug ),
							esc_attr( $index ),
							disabled( 'custom' !== $mode, true, false ),
							esc_textarea( $description )
						);
						echo '</p>';
						echo '<p>';
						printf(
							'<button type="button" class="button-link-delete wp-mcp-ai-prebuilt-shortcuts__remove"%1$s>%2$s</button>',
							disabled( 'custom' !== $mode, true, false ),
							esc_html__( 'Remove shortcut', 'wp-mcp-ai' )
						);
						echo '</p>';
						echo '<hr />';
						echo '</fieldset>';
					}
				}

				echo '</div>';
				echo '<p>';
				printf(
					'<button type="button" class="button wp-mcp-ai-prebuilt-shortcuts__add" data-tool="%1$s"%2$s>%3$s</button>',
					esc_attr( $tool_slug ),
					disabled( 'custom' !== $mode, true, false ),
					esc_html__( 'Add shortcut', 'wp-mcp-ai' )
				);
				echo '</p>';
				echo '</div>';
				echo '</details>';

				++$tool_index;
			}

			echo '</div>';

			static $prebuilt_shortcut_template_printed = false;

			if ( ! $prebuilt_shortcut_template_printed ) {
				$prebuilt_shortcut_template_printed = true;
				?>
			<template id="wp-mcp-ai-prebuilt-shortcut-template">
				<fieldset class="wp-mcp-ai-prebuilt-shortcuts__row" data-index="__INDEX__">
					<legend class="screen-reader-text"><?php esc_html_e( 'New pre-built shortcut', 'wp-mcp-ai' ); ?></legend>
					<p>
						<label>
							<strong><?php esc_html_e( 'Shortcut label', 'wp-mcp-ai' ); ?></strong>
							<input type="text" class="widefat" name="wp_mcp_ai_prebuilt_shortcuts[__TOOL__][shortcuts][__INDEX__][label]" />
						</label>
					</p>
					<p>
						<label>
							<strong><?php esc_html_e( 'Prompt text', 'wp-mcp-ai' ); ?></strong>
							<textarea class="widefat" rows="4" name="wp_mcp_ai_prebuilt_shortcuts[__TOOL__][shortcuts][__INDEX__][payload]"></textarea>
						</label>
					</p>
					<p>
						<label>
							<strong><?php esc_html_e( 'Optional description', 'wp-mcp-ai' ); ?></strong>
							<textarea class="widefat" rows="3" name="wp_mcp_ai_prebuilt_shortcuts[__TOOL__][shortcuts][__INDEX__][description]"></textarea>
						</label>
					</p>
					<p>
						<button type="button" class="button-link-delete wp-mcp-ai-prebuilt-shortcuts__remove"><?php esc_html_e( 'Remove shortcut', 'wp-mcp-ai' ); ?></button>
					</p>
					<hr />
				</fieldset>
			</template>
				<?php
			}
		}

		/**
		 * Retrieve the default pre-built shortcuts for the supplied tools.
		 *
		 * @param array $tool_slugs   Tool slugs to inspect.
		 * @param int   $assistant_id Assistant post ID.
		 * @return array
		 */
		protected function get_default_prebuilt_shortcuts_map( array $tool_slugs, $assistant_id ) {
			if ( empty( $tool_slugs ) ) {
				return array();
			}

			$assistant_id = absint( $assistant_id );
			$shortcuts    = array();

			foreach ( $tool_slugs as $tool_slug ) {
				$tool_slug = sanitize_key( $tool_slug );

				if ( '' === $tool_slug ) {
					continue;
				}

				$tool = $this->registry->get_tool( $tool_slug );

				if ( ! $tool ) {
					continue;
				}

				$tasks         = array();
				$skip_fallback = false;

				if ( $tool instanceof WP_MCP_AI_Tool_Shortcuts_Interface ) {
					$tasks = $tool->get_shortcut_tasks();
				} elseif ( method_exists( $tool, 'get_shortcut_tasks' ) ) {
					$tasks = $tool->get_shortcut_tasks();
				}

				if ( null === $tasks ) {
					$skip_fallback = true;
				}

				$tasks = apply_filters( 'wp_mcp_ai_tool_shortcut_tasks', $tasks, $tool, $assistant_id );
				$tasks = apply_filters( 'wp_mcp_ai_tool_shortcut_tasks_' . $tool_slug, $tasks, $tool, $assistant_id );

				if ( null === $tasks ) {
					$shortcuts[ $tool_slug ] = array();
					continue;
				}

				$entries = array();

				if ( empty( $tasks ) || ! is_array( $tasks ) ) {
					$should_register_fallback = ! $skip_fallback;

					if ( $tool instanceof WP_MCP_AI_Tool_Fallback_Shortcut_Interface ) {
						$should_register_fallback = (bool) $tool->should_register_fallback_shortcut( $assistant_id );
					} elseif ( method_exists( $tool, 'should_register_fallback_shortcut' ) ) {
						$should_register_fallback = (bool) $tool->should_register_fallback_shortcut( $assistant_id );
					}

					$should_register_fallback = apply_filters(
						'wp_mcp_ai_tool_should_register_fallback_shortcut',
						$should_register_fallback,
						$tool,
						$assistant_id,
						$tasks
					);

					if ( $should_register_fallback ) {
						$entries[] = array(
							'label'   => sanitize_text_field( $tool->get_slug() ),
							'payload' => sanitize_textarea_field( $tool->get_slug() ),
						);
					}

					$shortcuts[ $tool_slug ] = $entries;
					continue;
				}

				foreach ( $tasks as $task ) {
					if ( ! is_array( $task ) ) {
						continue;
					}

					$label   = isset( $task['label'] ) && is_string( $task['label'] ) ? sanitize_text_field( $task['label'] ) : '';
					$payload = isset( $task['payload'] ) && is_string( $task['payload'] ) ? sanitize_textarea_field( $task['payload'] ) : '';

					if ( '' === $label && '' === $payload ) {
						continue;
					}

					if ( '' === $label ) {
						$label = $tool->get_slug();
					}

					if ( '' === $payload ) {
						$payload = $tool->get_slug();
					}

					$entry = array(
						'label'   => $label,
						'payload' => $payload,
					);

					if ( isset( $task['description'] ) && is_string( $task['description'] ) ) {
						$entry['description'] = sanitize_textarea_field( $task['description'] );
					}

					$entries[] = $entry;
				}

				if ( empty( $entries ) ) {
					$should_register_fallback = ! $skip_fallback;

					if ( $tool instanceof WP_MCP_AI_Tool_Fallback_Shortcut_Interface ) {
						$should_register_fallback = (bool) $tool->should_register_fallback_shortcut( $assistant_id );
					} elseif ( method_exists( $tool, 'should_register_fallback_shortcut' ) ) {
						$should_register_fallback = (bool) $tool->should_register_fallback_shortcut( $assistant_id );
					}

					$should_register_fallback = apply_filters(
						'wp_mcp_ai_tool_should_register_fallback_shortcut',
						$should_register_fallback,
						$tool,
						$assistant_id,
						$tasks
					);

					if ( $should_register_fallback ) {
						$entries[] = array(
							'label'   => sanitize_text_field( $tool->get_slug() ),
							'payload' => sanitize_textarea_field( $tool->get_slug() ),
						);
					}
				}

				$shortcuts[ $tool_slug ] = $entries;
			}

			return $shortcuts;
		}

		/**
		 * Register the assistant custom post type.
		 */
		public static function register_post_type() {
			$labels = array(
				'name'               => __( 'AI Assistants', 'wp-mcp-ai' ),
				'singular_name'      => __( 'AI Assistant', 'wp-mcp-ai' ),
				'add_new'            => __( 'Add New', 'wp-mcp-ai' ),
				'add_new_item'       => __( 'Add New Assistant', 'wp-mcp-ai' ),
				'edit_item'          => __( 'Edit Assistant', 'wp-mcp-ai' ),
				'new_item'           => __( 'New Assistant', 'wp-mcp-ai' ),
				'view_item'          => __( 'View Assistant', 'wp-mcp-ai' ),
				'search_items'       => __( 'Search Assistants', 'wp-mcp-ai' ),
				'not_found'          => __( 'No assistants found', 'wp-mcp-ai' ),
				'not_found_in_trash' => __( 'No assistants found in Trash', 'wp-mcp-ai' ),
				'all_items'          => __( 'All Assistants', 'wp-mcp-ai' ),
			);

			$args = array(
				'labels'            => $labels,
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => true,
				'show_in_rest'      => true,
				'rest_base'         => 'mcp-ai-assistants',
				'capability_type'   => 'post',
				'supports'          => array( 'title', 'editor' ),
				'menu_icon'         => 'dashicons-lightbulb',
				'menu_position'     => 56,
				'has_archive'       => false,
				'rewrite'           => false,
				'show_in_nav_menus' => false,
				'map_meta_cap'      => true,
			);

			register_post_type( self::POST_TYPE, $args );
		}

		/**
		 * Disable the block editor for the assistant post type so meta boxes save correctly.
		 *
		 * @param bool   $use_block_editor Whether the block editor should be used.
		 * @param string $post_type        Current post type being edited.
		 * @return bool
		 */
		public static function disable_block_editor_for_post_type( $use_block_editor, $post_type ) {
			if ( self::POST_TYPE === $post_type ) {
				return false;
			}

			return $use_block_editor;
		}

		/**
		 * Register assistant post meta for REST access and sanitization.
		 */
		public static function register_meta() {
			$auth_callback = array( __CLASS__, 'meta_auth_callback' );

			register_post_meta(
				self::POST_TYPE,
				self::META_TOOLS,
				array(
					'type'              => 'array',
					'single'            => true,
					'show_in_rest'      => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array(
								'type' => 'string',
							),
						),
					),
					'sanitize_callback' => array( __CLASS__, 'sanitize_tools_meta' ),
					'auth_callback'     => $auth_callback,
				)
			);

			register_post_meta(
				self::POST_TYPE,
				self::META_PROVIDER,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => array( __CLASS__, 'sanitize_provider_meta' ),
					'auth_callback'     => $auth_callback,
				)
			);

			register_post_meta(
				self::POST_TYPE,
				self::META_MODEL,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => array( __CLASS__, 'sanitize_model_meta' ),
					'auth_callback'     => $auth_callback,
				)
			);

			register_post_meta(
				self::POST_TYPE,
				self::META_TEMPERATURE,
				array(
					'type'              => 'number',
					'single'            => true,
					'show_in_rest'      => array(
						'schema' => array(
							'type'    => 'number',
							'minimum' => 0,
							'maximum' => 2,
						),
					),
					'sanitize_callback' => array( __CLASS__, 'sanitize_temperature_meta' ),
					'auth_callback'     => $auth_callback,
				)
			);

			register_post_meta(
				self::POST_TYPE,
				self::META_SYSTEM_PROMPT,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => array( __CLASS__, 'sanitize_system_prompt_meta' ),
					'auth_callback'     => $auth_callback,
				)
			);

			register_post_meta(
				self::POST_TYPE,
				self::META_MEMORY_FILES,
				array(
					'type'              => 'array',
					'single'            => true,
					'show_in_rest'      => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array(
								'type' => 'integer',
							),
						),
					),
					'sanitize_callback' => array( __CLASS__, 'sanitize_memory_files_meta' ),
					'auth_callback'     => $auth_callback,
				)
			);

			register_post_meta(
				self::POST_TYPE,
				self::META_VECTOR_STORE_ID,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => array( __CLASS__, 'sanitize_vector_store_meta' ),
					'auth_callback'     => $auth_callback,
				)
			);

			register_post_meta(
				self::POST_TYPE,
				self::META_TOOL_SHORTCUTS,
				array(
					'type'              => 'array',
					'single'            => true,
					'show_in_rest'      => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'properties'           => array(
									'label'       => array(
										'type' => 'string',
									),
									'payload'     => array(
										'type' => 'string',
									),
									'tool'        => array(
										'type' => 'string',
									),
									'description' => array(
										'type' => 'string',
									),
								),
								'additionalProperties' => false,
							),
						),
					),
					'sanitize_callback' => array( __CLASS__, 'sanitize_tool_shortcuts_meta' ),
					'auth_callback'     => $auth_callback,
				)
			);

			register_post_meta(
				self::POST_TYPE,
				self::META_TOOL_PREBUILT_SHORTCUTS,
				array(
					'type'              => 'object',
					'single'            => true,
					'show_in_rest'      => array(
						'schema' => array(
							'type'                 => 'object',
							'additionalProperties' => array(
								'type'                 => 'object',
								'properties'           => array(
									'mode'      => array(
										'type' => 'string',
										'enum' => array( 'custom' ),
									),
									'shortcuts' => array(
										'type'  => 'array',
										'items' => array(
											'type'       => 'object',
											'properties' => array(
												'label'   => array(
													'type' => 'string',
												),
												'payload' => array(
													'type' => 'string',
												),
												'description' => array(
													'type' => 'string',
												),
											),
											'additionalProperties' => false,
										),
									),
								),
								'additionalProperties' => false,
							),
						),
					),
					'sanitize_callback' => array( __CLASS__, 'sanitize_prebuilt_tool_shortcuts_meta' ),
					'auth_callback'     => $auth_callback,
				)
			);

			$flag_schema = array(
				'type'  => 'array',
				'items' => array(
					'type' => 'string',
				),
			);

			$allowed_flags = self::get_allowed_tool_role_flags();

			if ( ! empty( $allowed_flags ) ) {
				$flag_schema['items']['enum'] = $allowed_flags;
			}

			register_post_meta(
				self::POST_TYPE,
				self::META_TOOL_ROLE_RULES,
				array(
					'type'              => 'array',
					'single'            => true,
					'show_in_rest'      => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'properties'           => array(
									'tool'   => array(
										'type' => 'string',
									),
									'roles'  => array(
										'type'  => 'array',
										'items' => array(
											'type' => 'string',
										),
									),
									'groups' => array(
										'type'  => 'array',
										'items' => array(
											'type'    => 'integer',
											'minimum' => 1,
										),
									),
									'flags'  => $flag_schema,
								),
								'additionalProperties' => false,
								'required'             => array( 'tool' ),
							),
						),
					),
					'sanitize_callback' => array( __CLASS__, 'sanitize_tool_role_rules_meta' ),
					'auth_callback'     => $auth_callback,
				)
			);

			register_post_meta(
				self::POST_TYPE,
				self::META_DISABLE_TOOL_SHORTCUTS,
				array(
					'type'              => 'boolean',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => array( __CLASS__, 'sanitize_disable_tool_shortcuts_meta' ),
					'auth_callback'     => $auth_callback,
				)
			);

			register_post_meta(
				self::POST_TYPE,
				self::META_EXTERNAL_ACTION_ID,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => array( __CLASS__, 'sanitize_external_action_id_meta' ),
					'auth_callback'     => $auth_callback,
				)
			);

			register_post_meta(
				self::POST_TYPE,
				self::META_EXTERNAL_ACTION_TYPE,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => array( __CLASS__, 'sanitize_external_action_type_meta' ),
					'auth_callback'     => $auth_callback,
				)
			);

			register_post_meta(
				self::POST_TYPE,
				self::META_REQUIRED_CAPABILITY,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => array( __CLASS__, 'sanitize_required_capability_meta' ),
					'auth_callback'     => $auth_callback,
				)
			);

			register_post_meta(
				self::POST_TYPE,
				self::META_PRIMARY_ROLES,
				array(
					'type'              => 'array',
					'single'            => true,
					'show_in_rest'      => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array(
								'type' => 'integer',
							),
						),
					),
					'sanitize_callback' => array( __CLASS__, 'sanitize_primary_roles_meta' ),
					'auth_callback'     => $auth_callback,
				)
			);
		}

		/**
		 * Meta capability check for assistant meta values.
		 *
		 * @param bool         $allowed Existing permission.
		 * @param string       $meta_key Meta key being modified.
		 * @param int          $post_id Post ID.
		 * @param int          $user_id User ID.
		 * @param string|array $cap Capability name(s).
		 * @param array        $caps Primitive caps.
		 * @return bool
		 */
		public static function meta_auth_callback( $allowed, $meta_key, $post_id, $user_id, $cap, $caps ) {
			unset( $allowed, $meta_key, $user_id, $cap, $caps );

			return current_user_can( 'edit_post', $post_id );
		}

		/**
		 * Sanitize tools meta value.
		 *
		 * @param mixed $tools Raw tools value.
		 * @return array
		 */
		public static function sanitize_tools_meta( $tools ) {
			if ( ! is_array( $tools ) ) {
				return array();
			}

			$registry = WP_MCP_AI_Tool_Registry::get_instance();
			$registry->init();

			$sanitized = array();

			foreach ( $tools as $tool_slug ) {
				$tool_slug = sanitize_key( $tool_slug );

				if ( '' === $tool_slug ) {
					continue;
				}

				if ( null === $registry->get_tool( $tool_slug ) ) {
					continue;
				}

				$sanitized[] = $tool_slug;
			}

			return array_values( array_unique( $sanitized ) );
		}

		/**
		 * Sanitize provider meta value.
		 *
		 * @param mixed $provider Raw provider value.
		 * @return string
		 */
		public static function sanitize_provider_meta( $provider ) {
			$provider = is_string( $provider ) ? sanitize_key( $provider ) : '';

			$allowed_providers = apply_filters( 'wp_mcp_ai_allowed_providers', array( 'openai', 'anthropic', 'gemini', 'ollama', 'lm_studio' ) );
			if ( ! is_array( $allowed_providers ) ) {
				$allowed_providers = array( 'openai', 'anthropic', 'gemini', 'ollama', 'lm_studio' );
			}

			if ( ! in_array( $provider, $allowed_providers, true ) ) {
				return '';
			}

			return $provider;
		}

		/**
		 * Sanitize model meta value.
		 *
		 * @param mixed $model Raw model value.
		 * @return string
		 */
		public static function sanitize_model_meta( $model ) {
			if ( ! is_string( $model ) ) {
				return '';
			}

			return sanitize_text_field( $model );
		}

		/**
		 * Sanitize temperature meta value.
		 *
		 * @param mixed $temperature Raw temperature value.
		 * @return float|null
		 */
		public static function sanitize_temperature_meta( $temperature ) {
			if ( is_string( $temperature ) ) {
				$temperature = trim( $temperature );
			}

			if ( '' === $temperature || null === $temperature ) {
				return null;
			}

			if ( is_numeric( $temperature ) ) {
				$temperature = floatval( $temperature );
				if ( $temperature < 0 || $temperature > 2 ) {
					return null;
				}

				return $temperature;
			}

			return null;
		}

		/**
		 * Sanitize system prompt meta value.
		 *
		 * @param mixed $prompt Raw prompt value.
		 * @return string
		 */
		public static function sanitize_system_prompt_meta( $prompt ) {
			if ( ! is_string( $prompt ) ) {
				return '';
			}

			return wp_kses_post( $prompt );
		}

		/**
		 * Sanitize memory files meta value.
		 *
		 * @param mixed $memory_files Raw memory file IDs.
		 * @return array
		 */
		public static function sanitize_memory_files_meta( $memory_files ) {
			if ( ! is_array( $memory_files ) ) {
				return array();
			}

			$sanitized = array();

			foreach ( $memory_files as $file_id ) {
				$file_id = absint( $file_id );
				if ( $file_id && 'attachment' === get_post_type( $file_id ) ) {
					$sanitized[] = $file_id;
				}
			}

			return array_values( array_unique( $sanitized ) );
		}

		/**
		 * Sanitize vector store ID meta value.
		 *
		 * @param mixed $vector_store_id Raw vector store ID.
		 * @return string
		 */
		public static function sanitize_vector_store_meta( $vector_store_id ) {
			if ( ! is_string( $vector_store_id ) ) {
				return '';
			}

			return sanitize_text_field( $vector_store_id );
		}

		/**
		 * Sanitize the default external action identifier meta value.
		 *
		 * @param mixed $identifier Raw identifier value.
		 * @return string
		 */
		public static function sanitize_external_action_id_meta( $identifier ) {
			if ( ! is_string( $identifier ) ) {
				return '';
			}

			return sanitize_text_field( $identifier );
		}

		/**
		 * Sanitize the default external action type meta value.
		 *
		 * @param mixed $action_type Raw action type value.
		 * @return string
		 */
		public static function sanitize_external_action_type_meta( $action_type ) {
			$action_type = is_string( $action_type ) ? sanitize_key( $action_type ) : '';

			if ( ! in_array( $action_type, array( 'workflow', 'assistant' ), true ) ) {
				return '';
			}

			return $action_type;
		}

		/**
		 * Sanitize the required capability meta value.
		 *
		 * @param mixed $capability Raw capability value.
		 * @return string
		 */
		public static function sanitize_required_capability_meta( $capability ) {
			if ( ! is_string( $capability ) ) {
				return '';
			}

			$capability = sanitize_key( $capability );

			// Allow empty (no requirement), 'public' (anyone), or valid WordPress capabilities.
			if ( '' === $capability || 'public' === $capability ) {
				return $capability;
			}

			// Validate it's a known capability or follows WordPress naming convention.
			// This is permissive to allow custom capabilities.
			if ( preg_match( '/^[a-z_]+$/', $capability ) ) {
				return $capability;
			}

			return '';
		}

		/**
		 * Sanitize the primary roles meta value.
		 *
		 * Primary roles are profession post IDs. Max 3 allowed.
		 *
		 * @param mixed $roles Raw primary roles value.
		 * @return array
		 */
		public static function sanitize_primary_roles_meta( $roles ) {
			if ( ! is_array( $roles ) ) {
				return array();
			}

			// Sanitize each role ID and validate it's a valid profession post.
			$sanitized = array();
			foreach ( $roles as $role_id ) {
				$role_id = absint( $role_id );
				if ( $role_id > 0 && 'mcp_ai_profession' === get_post_type( $role_id ) ) {
					$sanitized[] = $role_id;
				}
			}

			// Limit to 3 roles maximum.
			$sanitized = array_slice( array_unique( $sanitized ), 0, 3 );

			return $sanitized;
		}

		/**
		 * Register meta boxes for the assistant CPT.
		 */
		public function register_meta_boxes() {
			// Only register metaboxes for assistant post type.
			$screen = get_current_screen();
			if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
				return;
			}

			add_meta_box(
				'wp-mcp-ai-tools',
				__( 'Available Tools', 'wp-mcp-ai' ),
				array( $this, 'render_tools_meta_box' ),
				self::POST_TYPE,
				'normal',
				'default'
			);

			add_meta_box(
				'wp-mcp-ai-tool-shortcuts',
				__( 'Prompt Shortcuts', 'wp-mcp-ai' ),
				array( $this, 'render_tool_shortcuts_meta_box' ),
				self::POST_TYPE,
				'normal',
				'default'
			);

			add_meta_box(
				'wp-mcp-ai-required-capability',
				__( 'Access Control', 'wp-mcp-ai' ),
				array( $this, 'render_required_capability_meta_box' ),
				self::POST_TYPE,
				'side',
				'default'
			);

			// Register metaboxes from dedicated metabox classes.
			if ( isset( $this->metaboxes['defaults'] ) ) {
				$metabox = $this->metaboxes['defaults'];
				add_meta_box(
					$metabox->get_id(),
					$metabox->get_title(),
					array( $metabox, 'render' ),
					self::POST_TYPE,
					$metabox->get_context(),
					$metabox->get_priority()
				);
			}

			if ( isset( $this->metaboxes['base-knowledge'] ) ) {
				$metabox = $this->metaboxes['base-knowledge'];
				add_meta_box(
					$metabox->get_id(),
					$metabox->get_title(),
					array( $metabox, 'render' ),
					self::POST_TYPE,
					$metabox->get_context(),
					$metabox->get_priority()
				);
			}

			if ( isset( $this->metaboxes['credentials'] ) ) {
				$metabox = $this->metaboxes['credentials'];
				add_meta_box(
					$metabox->get_id(),
					$metabox->get_title(),
					array( $metabox, 'render' ),
					self::POST_TYPE,
					$metabox->get_context(),
					$metabox->get_priority()
				);
			}

			if ( isset( $this->metaboxes['primary-roles'] ) ) {
				$metabox = $this->metaboxes['primary-roles'];
				add_meta_box(
					$metabox->get_id(),
					$metabox->get_title(),
					array( $metabox, 'render' ),
					self::POST_TYPE,
					$metabox->get_context(),
					$metabox->get_priority()
				);
			}

			// Only show mesh routing meta box if mesh is enabled.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			if ( ! empty( $settings['enable_mesh'] ) && isset( $this->metaboxes['mesh-routing'] ) ) {
				$metabox = $this->metaboxes['mesh-routing'];
				add_meta_box(
					$metabox->get_id(),
					$metabox->get_title(),
					array( $metabox, 'render' ),
					self::POST_TYPE,
					$metabox->get_context(),
					$metabox->get_priority()
				);
			}
		}

		/**
		 * Render the credentials meta box content.
		 *
		 * @param WP_Post $post Post object.
		 */
		public function render_credentials_meta_box( $post ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				echo '<p>' . esc_html__( 'You do not have permission to manage credentials.', 'wp-mcp-ai' ) . '</p>';
				return;
			}

			$credentials = WP_MCP_AI_Credentials::get_credentials( $post->ID );

			echo '<p>' . esc_html__( 'Issue tokens for remote integrations. Store the generated token securely; it will not be shown again.', 'wp-mcp-ai' ) . '</p>';

			if ( empty( $credentials ) ) {
				echo '<p>' . esc_html__( 'No credentials have been issued for this assistant.', 'wp-mcp-ai' ) . '</p>';
			} else {
				echo '<table class="widefat striped">';
				echo '<thead><tr>';
				echo '<th>' . esc_html__( 'Credential ID', 'wp-mcp-ai' ) . '</th>';
				echo '<th>' . esc_html__( 'Created', 'wp-mcp-ai' ) . '</th>';
				echo '<th>' . esc_html__( 'Status', 'wp-mcp-ai' ) . '</th>';
				echo '<th>' . esc_html__( 'Actions', 'wp-mcp-ai' ) . '</th>';
				echo '</tr></thead>';
				echo '<tbody>';

				foreach ( $credentials as $credential ) {
					$created_at   = ! empty( $credential['created_at'] ) ? get_date_from_gmt( $credential['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : __( 'Unknown', 'wp-mcp-ai' );
					$status       = __( 'Active', 'wp-mcp-ai' );
					$action_links = array();

					if ( ! empty( $credential['revoked_at'] ) ) {
						$status = sprintf(
						/* translators: %s: revocation timestamp */
							__( 'Revoked %s', 'wp-mcp-ai' ),
							get_date_from_gmt( $credential['revoked_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
						);
					} else {
						$action_links[] = $this->build_credential_action_button(
							$post->ID,
							$credential['id'],
							'wp_mcp_ai_revoke_credential',
							'wp_mcp_ai_revoke_credential_' . $post->ID . '_' . $credential['id'],
							$this->get_credential_nonce_field_name( 'wp_mcp_ai_revoke_credential_nonce', $credential['id'] ),
							__( 'Revoke', 'wp-mcp-ai' ),
							__( 'Revoke this credential? This action cannot be undone.', 'wp-mcp-ai' )
						);
					}

					$action_links[] = $this->build_credential_action_button(
						$post->ID,
						$credential['id'],
						'wp_mcp_ai_delete_credential',
						'wp_mcp_ai_delete_credential_' . $post->ID . '_' . $credential['id'],
						$this->get_credential_nonce_field_name( 'wp_mcp_ai_delete_credential_nonce', $credential['id'] ),
						__( 'Delete', 'wp-mcp-ai' ),
						__( 'Delete this credential? This action cannot be undone.', 'wp-mcp-ai' ),
						'button button-secondary delete'
					);

					$actions = empty( $action_links ) ? '&#8212;' : implode( ' ', $action_links );

					echo '<tr>';
					echo '<td><code>' . esc_html( $credential['id'] ) . '</code></td>';
					echo '<td>' . esc_html( $created_at ) . '</td>';
					echo '<td>' . esc_html( $status ) . '</td>';
					echo '<td>' . wp_kses_post( $actions ) . '</td>';
					echo '</tr>';
				}

				echo '</tbody>';
				echo '</table>';
			}

			$issue_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'  => 'wp_mcp_ai_issue_credential',
						'post_id' => $post->ID,
					),
					admin_url( 'admin-post.php' )
				),
				'wp_mcp_ai_issue_credential_' . $post->ID,
				'wp_mcp_ai_issue_credential_nonce'
			);

			printf(
				'<p><a class="button button-secondary" href="%1$s">%2$s</a></p>',
				esc_url( $issue_url ),
				esc_html__( 'Generate Credential', 'wp-mcp-ai' )
			);

			$this->print_credential_action_script();
		}

		/**
		 * Build the markup for a credential action button.
		 *
		 * @param int    $post_id        Assistant post ID.
		 * @param string $credential_id  Credential identifier.
		 * @param string $action         Admin-post action hook name.
		 * @param string $nonce_action   Action name for nonce verification.
		 * @param string $nonce_name     Nonce field name.
		 * @param string $button_label   Button label.
		 * @param string $confirm_prompt Confirmation prompt shown before submit.
		 * @param string $button_class   CSS classes to apply to the button element.
		 *
		 * @return string
		 */
		protected function build_credential_action_button( $post_id, $credential_id, $action, $nonce_action, $nonce_name, $button_label, $confirm_prompt, $button_class = 'button button-secondary' ) {
			$classes    = trim( $button_class . ' wp-mcp-ai-credential-action' );
			$attributes = array(
				'type'               => 'button',
				'class'              => $classes,
				'data-action'        => $action,
				'data-post-id'       => $post_id,
				'data-credential-id' => $credential_id,
				'data-nonce-name'    => $nonce_name,
				'data-nonce-value'   => wp_create_nonce( $nonce_action ),
				'data-endpoint'      => admin_url( 'admin-post.php' ),
			);

			if ( $confirm_prompt ) {
				$attributes['data-confirm'] = $confirm_prompt;
			}

			$attribute_string = '';
			foreach ( $attributes as $name => $value ) {
				if ( '' === $value || null === $value ) {
					continue;
				}

				$escaped_value     = ( 'data-endpoint' === $name ) ? esc_url( $value ) : esc_attr( $value );
				$attribute_string .= sprintf( ' %s="%s"', esc_attr( $name ), $escaped_value );
			}

			return sprintf( '<button%1$s>%2$s</button>', $attribute_string, esc_html( $button_label ) );
		}

		/**
		 * Print the JavaScript required to submit credential action buttons as POST requests.
		 */
		protected function print_credential_action_script() {
			if ( self::$credential_action_script_printed ) {
				return;
			}

			self::$credential_action_script_printed = true;
			?>
		<script type="text/javascript">
		( function() {
			function submitCredentialAction( button ) {
				if ( ! button ) {
					return;
				}

				var confirmMessage = button.getAttribute( 'data-confirm' );
				if ( confirmMessage && ! window.confirm( confirmMessage ) ) {
					return;
				}

				var endpoint = button.getAttribute( 'data-endpoint' );
				if ( ! endpoint ) {
					return;
				}

				var form = document.createElement( 'form' );
				form.method = 'post';
				form.action = endpoint;
				form.style.display = 'none';

				var fields = {
					action: button.getAttribute( 'data-action' ),
					post_id: button.getAttribute( 'data-post-id' ),
					credential_id: button.getAttribute( 'data-credential-id' )
				};

				var nonceName = button.getAttribute( 'data-nonce-name' );
				var nonceValue = button.getAttribute( 'data-nonce-value' );

				if ( nonceName && nonceValue ) {
					fields[ nonceName ] = nonceValue;
				}

				for ( var key in fields ) {
					if ( Object.prototype.hasOwnProperty.call( fields, key ) && fields[ key ] ) {
						var input = document.createElement( 'input' );
						input.type = 'hidden';
						input.name = key;
						input.value = fields[ key ];
						form.appendChild( input );
					}
				}

				document.body.appendChild( form );
				form.submit();
			}

			document.addEventListener( 'click', function( event ) {
				var target = event.target;
				if ( target && target.classList && target.classList.contains( 'wp-mcp-ai-credential-action' ) ) {
					event.preventDefault();
					submitCredentialAction( target );
				}
			} );
		} )();
		</script>
			<?php
		}

		/**
		 * Generate a nonce field name unique to a credential.
		 *
		 * @param string $base_name     Base nonce field name.
		 * @param string $credential_id Credential identifier.
		 * @return string
		 */
		protected function get_credential_nonce_field_name( $base_name, $credential_id ) {
			$suffix = sanitize_key( $credential_id );

			if ( '' === $suffix ) {
				return $base_name;
			}

			return $base_name . '_' . $suffix;
		}

		/**
		 * Render the tools meta box content.
		 *
		 * @param WP_Post $post Post object.
		 */
		public function render_tools_meta_box( $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				wp_die( esc_html__( 'You do not have permission to edit this assistant.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
			}

			wp_nonce_field( 'wp_mcp_ai_tools_meta', 'wp_mcp_ai_tools_meta_nonce' );

			$selected_tools = get_post_meta( $post->ID, self::META_TOOLS, true );
			if ( ! is_array( $selected_tools ) ) {
				$selected_tools = array();
			}

			$tools = $this->registry->get_tools();

			$disable_tool_shortcuts = get_post_meta( $post->ID, self::META_DISABLE_TOOL_SHORTCUTS, true );
			$disable_tool_shortcuts = self::sanitize_disable_tool_shortcuts_meta( $disable_tool_shortcuts );

			$prebuilt_shortcuts = get_post_meta( $post->ID, self::META_TOOL_PREBUILT_SHORTCUTS, true );
			if ( ! is_array( $prebuilt_shortcuts ) ) {
				$prebuilt_shortcuts = array();
			}

			$prebuilt_shortcuts = self::sanitize_prebuilt_tool_shortcuts_meta( $prebuilt_shortcuts );

			$tool_role_rules = get_post_meta( $post->ID, self::META_TOOL_ROLE_RULES, true );
			if ( ! is_array( $tool_role_rules ) ) {
				$tool_role_rules = array();
			}

			$tool_role_rules = self::sanitize_tool_role_rules_meta( $tool_role_rules );

			$tool_role_rules_by_slug = array();

			foreach ( $tool_role_rules as $rule ) {
				if ( isset( $rule['tool'] ) ) {
					$tool_role_rules_by_slug[ $rule['tool'] ] = $rule;
				}
			}

			$external_action_id   = get_post_meta( $post->ID, self::META_EXTERNAL_ACTION_ID, true );
			$external_action_id   = self::sanitize_external_action_id_meta( $external_action_id );
			$external_action_type = get_post_meta( $post->ID, self::META_EXTERNAL_ACTION_TYPE, true );
			$external_action_type = self::sanitize_external_action_type_meta( $external_action_type );

			if ( empty( $tools ) ) {
				echo '<p>' . esc_html__( 'No tools are currently registered.', 'wp-mcp-ai' ) . '</p>';
				return;
			}

			echo '<p>' . esc_html__( 'Select the tools this assistant is permitted to invoke.', 'wp-mcp-ai' ) . '</p>';
			echo '<p class="description">' . esc_html__( 'Expand a group to review related capabilities. You can optionally limit who can call each tool by assigning WordPress roles.', 'wp-mcp-ai' ) . '</p>';

			echo '<fieldset class="wp-mcp-ai-tools__shortcuts-toggle">';
			echo '<legend class="screen-reader-text">' . esc_html__( 'Tool shortcut options', 'wp-mcp-ai' ) . '</legend>';
			echo '<label for="wp-mcp-ai-disable-tool-shortcuts" class="wp-mcp-ai-tools__shortcuts-toggle-label">';
			printf(
				'<input type="checkbox" id="wp-mcp-ai-disable-tool-shortcuts" name="wp_mcp_ai_disable_prebuilt_shortcuts" value="1" %s />',
				checked( $disable_tool_shortcuts, true, false )
			);
			echo '<span>' . esc_html__( 'Disable pre-built prompt shortcuts from selected tools', 'wp-mcp-ai' ) . '</span>';
			echo '</label>';
			echo '<p class="description">' . esc_html__( 'When enabled, only the custom shortcuts you define below will appear in the chat interface.', 'wp-mcp-ai' ) . '</p>';
			echo '</fieldset>';

			// Render tool selection presets.
			$this->render_tool_presets( $selected_tools );

			$group_map = array();
			if ( method_exists( $this->registry, 'get_tool_group_map' ) ) {
				$group_map = $this->registry->get_tool_group_map();
			}
			if ( ! is_array( $group_map ) ) {
				$group_map = array();
			}

			$group_labels = array();
			if ( method_exists( $this->registry, 'get_tool_group_labels' ) ) {
				$group_labels = $this->registry->get_tool_group_labels();
			}
			if ( ! is_array( $group_labels ) ) {
				$group_labels = array();
			}
			if ( ! isset( $group_labels['other'] ) ) {
				$group_labels['other'] = __( 'Other tools', 'wp-mcp-ai' );
			}

			$grouped_tools = array();

			foreach ( $tools as $tool ) {
				if ( ! $tool instanceof WP_MCP_AI_Tool_Interface ) {
					continue;
				}

				$slug = $tool->get_slug();

				if ( '' === $slug ) {
					continue;
				}

				$group_id = isset( $group_map[ $slug ] ) ? (string) $group_map[ $slug ] : 'other';

				if ( '' === $group_id ) {
					$group_id = 'other';
				}

				if ( ! isset( $grouped_tools[ $group_id ] ) ) {
					$grouped_tools[ $group_id ] = array();
				}

				$grouped_tools[ $group_id ][] = $tool;
			}

			if ( empty( $grouped_tools ) ) {
				echo '<p>' . esc_html__( 'No tools are currently registered.', 'wp-mcp-ai' ) . '</p>';
				return;
			}

			$ordered_group_ids = array();

			foreach ( $group_labels as $group_id => $label ) {
				if ( isset( $grouped_tools[ $group_id ] ) ) {
					$ordered_group_ids[] = (string) $group_id;
				}
			}

			foreach ( $grouped_tools as $group_id => $unused ) {
				if ( ! in_array( $group_id, $ordered_group_ids, true ) ) {
					$ordered_group_ids[] = (string) $group_id;
				}
			}

			$role_options = array();

			if ( function_exists( 'get_editable_roles' ) ) {
				$editable_roles = get_editable_roles();

				if ( is_array( $editable_roles ) ) {
					foreach ( $editable_roles as $role_slug => $role_details ) {
						$role_slug = sanitize_key( $role_slug );

						if ( '' === $role_slug ) {
							continue;
						}

						$role_name = isset( $role_details['name'] ) ? (string) $role_details['name'] : $role_slug;

						$role_options[ $role_slug ] = translate_user_role( $role_name );
					}
				}
			}

			if ( empty( $role_options ) ) {
				$registered_roles = self::get_registered_role_slugs();

				foreach ( $registered_roles as $role_slug ) {
					if ( '' === $role_slug ) {
						continue;
					}

					$role_options[ $role_slug ] = ucwords( str_replace( '_', ' ', $role_slug ) );
				}
			}

			if ( ! empty( $role_options ) ) {
				uasort( $role_options, 'strnatcasecmp' );
			}

			static $tools_styles_printed = false;

			if ( ! $tools_styles_printed ) {
				$tools_styles_printed = true;
				?>
			<style>
			.wp-mcp-ai-tools{display:flex;flex-direction:column;gap:1rem;margin-top:1rem}
			.wp-mcp-ai-tools__group{border:1px solid #dcdcde;border-radius:4px;background:#f6f7f7}
			.wp-mcp-ai-tools__group summary{list-style:none;cursor:pointer;padding:0.75rem 1rem;display:flex;align-items:center;gap:0.75rem;font-weight:600;outline:none}
			.wp-mcp-ai-tools__group summary::-webkit-details-marker{display:none}
			.wp-mcp-ai-tools__summary-title{flex:1 1 auto}
			.wp-mcp-ai-tools__summary-count{font-size:0.875rem;color:#50575e;background:#fff;border:1px solid #dcdcde;border-radius:999px;padding:0 0.5rem;line-height:1.6}
			.wp-mcp-ai-tools__group[open]{background:#fff}
			.wp-mcp-ai-tools__group[open] summary{border-bottom:1px solid #dcdcde}
			.wp-mcp-ai-tools__list{margin:0;padding:1rem;list-style:none;display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem}
			.wp-mcp-ai-tools__item{border:1px solid #dcdcde;border-radius:4px;background:#fff;padding:1rem;display:flex;flex-direction:column;gap:0.5rem;transition:box-shadow 0.2s ease}
			.wp-mcp-ai-tools__item:focus-within{box-shadow:0 0 0 1px #2271b1}
			.wp-mcp-ai-tools__header{display:flex;align-items:flex-start;gap:0.75rem}
			.wp-mcp-ai-tools__checkbox{margin-top:0.2rem}
			.wp-mcp-ai-tools__name{display:block;font-weight:600;font-size:14px}
			.wp-mcp-ai-tools__description{margin:0;color:#50575e;font-size:13px}
			.wp-mcp-ai-tools__controls label{font-weight:600;font-size:13px;margin-bottom:0.25rem;display:block}
			.wp-mcp-ai-tools__role-select{width:100%}
			.wp-mcp-ai-tools__helper{margin:0;color:#646970;font-size:12px}
			.wp-mcp-ai-tools__extra{margin-top:0.5rem;padding-top:0.5rem;border-top:1px solid #dcdcde}
			.wp-mcp-ai-tools__item[data-tool-selected="false"]{opacity:0.75}
			.wp-mcp-ai-tools__item[data-tool-selected="false"] .wp-mcp-ai-tools__extra{display:none}
			.wp-mcp-ai-tools__shortcuts-toggle{margin:1rem 0 0;padding:1rem;border:1px solid #dcdcde;border-radius:4px;background:#fff;display:flex;flex-direction:column;gap:0.5rem}
			.wp-mcp-ai-tools__shortcuts-toggle-label{font-weight:600;display:flex;align-items:center;gap:0.5rem;font-size:14px}
			.wp-mcp-ai-tools__shortcuts-toggle .description{margin:0;font-size:13px;color:#50575e}
			.wp-mcp-ai-prebuilt-shortcuts{margin-top:1.5rem;padding:1.5rem;border:1px solid #dcdcde;border-radius:4px;background:#fff;display:flex;flex-direction:column;gap:1rem}
			.wp-mcp-ai-prebuilt-shortcuts h3{margin:0;font-size:16px}
			.wp-mcp-ai-prebuilt-shortcuts__tool{border:1px solid #dcdcde;border-radius:4px;background:#f6f7f7}
			.wp-mcp-ai-prebuilt-shortcuts__summary{list-style:none;cursor:pointer;padding:0.75rem 1rem;display:flex;align-items:center;gap:0.75rem;font-weight:600;outline:none}
			.wp-mcp-ai-prebuilt-shortcuts__summary::-webkit-details-marker{display:none}
			.wp-mcp-ai-prebuilt-shortcuts__summary-title{flex:1 1 auto}
			.wp-mcp-ai-prebuilt-shortcuts__summary-mode{font-size:0.875rem;color:#50575e;background:#fff;border:1px solid #dcdcde;border-radius:999px;padding:0 0.5rem;line-height:1.6}
			.wp-mcp-ai-prebuilt-shortcuts__tool[open]{background:#fff}
			.wp-mcp-ai-prebuilt-shortcuts__tool[open] .wp-mcp-ai-prebuilt-shortcuts__summary{border-bottom:1px solid #dcdcde}
			.wp-mcp-ai-prebuilt-shortcuts__content{padding:1rem;display:flex;flex-direction:column;gap:1rem;border-top:1px solid #dcdcde}
			.wp-mcp-ai-prebuilt-shortcuts__mode{display:flex;flex-wrap:wrap;gap:1rem;margin:0}
			.wp-mcp-ai-prebuilt-shortcuts__mode label{display:flex;align-items:center;gap:0.5rem;font-weight:600}
			.wp-mcp-ai-prebuilt-shortcuts__defaults{margin:0}
			.wp-mcp-ai-prebuilt-shortcuts__defaults p{margin:0;color:#50575e;font-size:13px}
			.wp-mcp-ai-prebuilt-shortcuts__defaults-list{margin:0.5rem 0 0;padding-left:1.25rem}
			.wp-mcp-ai-prebuilt-shortcuts__defaults-list li{margin-bottom:0.5rem;font-size:13px}
			.wp-mcp-ai-prebuilt-shortcuts__defaults-summary{display:block;color:#50575e;font-size:12px;margin-top:0.25rem}
			.wp-mcp-ai-prebuilt-shortcuts__rows{display:flex;flex-direction:column;gap:1rem}
			.wp-mcp-ai-prebuilt-shortcuts__row{border:1px solid #dcdcde;border-radius:4px;padding:1rem;background:#fff}
			.wp-mcp-ai-prebuilt-shortcuts__row hr{margin:1rem -1rem 0}
			.wp-mcp-ai-tool-shortcuts{margin-top:1.5rem;padding:1.5rem;border:1px solid #dcdcde;border-radius:4px;background:#fff;display:flex;flex-direction:column;gap:1rem}
			.wp-mcp-ai-tool-shortcuts h3{margin:0;font-size:16px}
			.wp-mcp-ai-tool-shortcuts__rows{display:flex;flex-direction:column;gap:1rem}
			.wp-mcp-ai-tool-shortcuts__item{border:1px solid #dcdcde;border-radius:4px;background:#f6f7f7}
			.wp-mcp-ai-tool-shortcuts__item[open]{background:#fff}
			.wp-mcp-ai-tool-shortcuts__summary{list-style:none;cursor:pointer;padding:0.75rem 1rem;display:flex;align-items:center;gap:0.75rem;font-weight:600;outline:none}
			.wp-mcp-ai-tool-shortcuts__summary::-webkit-details-marker{display:none}
			.wp-mcp-ai-tool-shortcuts__summary-title{flex:1 1 auto}
			.wp-mcp-ai-tool-shortcuts__summary-tool{font-size:0.875rem;color:#50575e;background:#fff;border:1px solid #dcdcde;border-radius:999px;padding:0 0.5rem;line-height:1.6}
			.wp-mcp-ai-tool-shortcuts__item[open] .wp-mcp-ai-tool-shortcuts__summary{border-bottom:1px solid #dcdcde}
			.wp-mcp-ai-tool-shortcuts__row{margin:0;padding:1rem;display:flex;flex-direction:column;gap:1rem;background:#fff;border-top:1px solid #dcdcde;border-radius:0 0 4px 4px}
			.wp-mcp-ai-tool-shortcuts__row hr{display:none}
			@media (max-width:782px){.wp-mcp-ai-tools__list{grid-template-columns:1fr}}
			</style>
				<?php
			}

			static $tools_script_printed = false;

			if ( ! $tools_script_printed ) {
				$tools_script_printed = true;
				?>
			<script>
			( function() {
				function syncToolControls( container ) {
					if ( ! container ) {
						return;
					}

					var checkbox = container.querySelector( '.wp-mcp-ai-tools__checkbox' );
					if ( ! checkbox ) {
						return;
					}

					var controls = container.querySelectorAll( '[data-tool-control]' );
					var selected = checkbox.checked;

					container.setAttribute( 'data-tool-selected', selected ? 'true' : 'false' );

					controls.forEach( function( control ) {
						if ( selected ) {
							control.removeAttribute( 'disabled' );
							control.setAttribute( 'aria-disabled', 'false' );
						} else {
							control.setAttribute( 'disabled', 'disabled' );
							control.setAttribute( 'aria-disabled', 'true' );
						}
					} );
				}

				document.addEventListener( 'DOMContentLoaded', function() {
					var toolItems = document.querySelectorAll( '.wp-mcp-ai-tools__item' );
					var prebuiltTemplate = document.getElementById( 'wp-mcp-ai-prebuilt-shortcut-template' );

					toolItems.forEach( function( item ) {
						var checkbox = item.querySelector( '.wp-mcp-ai-tools__checkbox' );

						if ( ! checkbox ) {
							return;
						}

						syncToolControls( item );

						checkbox.addEventListener( 'change', function() {
							syncToolControls( item );
						} );
					} );

					if ( ! prebuiltTemplate ) {
						return;
					}

					var prebuiltFieldsets = document.querySelectorAll( '.wp-mcp-ai-prebuilt-shortcuts__tool' );

					prebuiltFieldsets.forEach( function( fieldset ) {
						var rowsContainer = fieldset.querySelector( '.wp-mcp-ai-prebuilt-shortcuts__rows' );
						var addButton = fieldset.querySelector( '.wp-mcp-ai-prebuilt-shortcuts__add' );
						var modeInputs = fieldset.querySelectorAll( '.wp-mcp-ai-prebuilt-shortcuts__mode input[type="radio"]' );
						var defaults = [];
						var datasetDefaults = fieldset.getAttribute( 'data-defaults' );
						var hasExistingCustom = fieldset.getAttribute( 'data-has-existing-custom' ) === 'true';
						var summaryModeElement = fieldset.querySelector( '.wp-mcp-ai-prebuilt-shortcuts__summary-mode' );
						var modeLabelInherit = fieldset.getAttribute( 'data-mode-label-inherit' ) || '';
						var modeLabelCustom = fieldset.getAttribute( 'data-mode-label-custom' ) || '';

						if ( datasetDefaults ) {
							try {
								defaults = JSON.parse( datasetDefaults ) || [];
							} catch ( error ) {
								defaults = [];
							}
						}

						function getNextIndex() {
							if ( ! rowsContainer ) {
								return 0;
							}

							var next = parseInt( rowsContainer.getAttribute( 'data-next-index' ), 10 );

							if ( isNaN( next ) ) {
								next = rowsContainer.querySelectorAll( '.wp-mcp-ai-prebuilt-shortcuts__row' ).length;
							}

							rowsContainer.setAttribute( 'data-next-index', next + 1 );

							return next;
						}

						function addRow( index, values ) {
							if ( ! rowsContainer ) {
								return null;
							}

							var fragment = document.importNode( prebuiltTemplate.content, true );
							var html = '';

							if ( fragment.firstElementChild ) {
								html = fragment.firstElementChild.outerHTML;
							} else if ( fragment.children.length ) {
								html = fragment.children[0].outerHTML;
							}

							if ( ! html ) {
								return null;
							}

							var tool = fieldset.getAttribute( 'data-tool' ) || '';
							html = html.replace( /__INDEX__/g, index );
							html = html.replace( /__TOOL__/g, tool );

							var wrapper = document.createElement( 'div' );
							wrapper.innerHTML = html;
							var row = wrapper.firstElementChild;

							if ( ! row ) {
								return null;
							}

							rowsContainer.appendChild( row );

							if ( values && typeof values === 'object' ) {
								var labelField = row.querySelector( 'input[name*="[label]"]' );
								var payloadField = row.querySelector( 'textarea[name*="[payload]"]' );
								var descriptionField = row.querySelector( 'textarea[name*="[description]"]' );

								if ( labelField && typeof values.label === 'string' ) {
									labelField.value = values.label;
								}

								if ( payloadField && typeof values.payload === 'string' ) {
									payloadField.value = values.payload;
								}

								if ( descriptionField && typeof values.description === 'string' ) {
									descriptionField.value = values.description;
								}
							}

							return row;
						}

						function setFieldsDisabled( disabledState ) {
							if ( rowsContainer ) {
								var fields = rowsContainer.querySelectorAll( 'input, textarea' );
								fields.forEach( function( field ) {
									if ( disabledState ) {
										field.setAttribute( 'disabled', 'disabled' );
									} else {
										field.removeAttribute( 'disabled' );
									}
								} );

								var removeButtons = rowsContainer.querySelectorAll( '.wp-mcp-ai-prebuilt-shortcuts__remove' );
								removeButtons.forEach( function( button ) {
									if ( disabledState ) {
										button.setAttribute( 'disabled', 'disabled' );
									} else {
										button.removeAttribute( 'disabled' );
									}
								} );
							}

							if ( addButton ) {
								if ( disabledState ) {
									addButton.setAttribute( 'disabled', 'disabled' );
								} else {
									addButton.removeAttribute( 'disabled' );
								}
							}
						}

						function ensureDefaultRows() {
							if ( ! rowsContainer || rowsContainer.querySelector( '.wp-mcp-ai-prebuilt-shortcuts__row' ) ) {
								return;
							}

							if ( ! defaults.length ) {
								return;
							}

							defaults.forEach( function( shortcut ) {
								var index = getNextIndex();
								addRow( index, shortcut );
							} );
						}

						function toggleMode( mode ) {
							var isCustom = mode === 'custom';

							if ( rowsContainer ) {
								if ( isCustom ) {
									rowsContainer.removeAttribute( 'hidden' );
									rowsContainer.setAttribute( 'aria-hidden', 'false' );
								} else {
									rowsContainer.setAttribute( 'hidden', 'hidden' );
									rowsContainer.setAttribute( 'aria-hidden', 'true' );
								}
							}

							if ( summaryModeElement ) {
								summaryModeElement.textContent = isCustom
									? ( modeLabelCustom || summaryModeElement.textContent )
									: ( modeLabelInherit || summaryModeElement.textContent );
							}

							if ( isCustom && ! hasExistingCustom ) {
								ensureDefaultRows();
								hasExistingCustom = true;
								fieldset.setAttribute( 'data-has-existing-custom', 'true' );
							}

							if ( isCustom ) {
								fieldset.setAttribute( 'open', 'open' );
							}

							setFieldsDisabled( ! isCustom );
						}

						if ( addButton ) {
							addButton.addEventListener( 'click', function() {
								var index = getNextIndex();
								addRow( index );
							} );
						}

						if ( rowsContainer ) {
							rowsContainer.addEventListener( 'click', function( event ) {
								var target = event.target;

								if ( target && target.classList && target.classList.contains( 'wp-mcp-ai-prebuilt-shortcuts__remove' ) ) {
									event.preventDefault();

									var row = target.closest( '.wp-mcp-ai-prebuilt-shortcuts__row' );

									if ( row && rowsContainer.contains( row ) ) {
										rowsContainer.removeChild( row );
									}
								}
							} );
						}

						modeInputs.forEach( function( input ) {
							input.addEventListener( 'change', function() {
								if ( input.checked ) {
									toggleMode( input.value );
								}
							} );
						} );

						var initialMode = 'inherit';

						modeInputs.forEach( function( input ) {
							if ( input.checked ) {
								initialMode = input.value;
							}
						} );

						toggleMode( initialMode );
					} );
				} );
			} )();
			</script>
				<?php
			}

			echo '<div class="wp-mcp-ai-tools" role="group" aria-label="' . esc_attr__( 'Assistant tool permissions', 'wp-mcp-ai' ) . '">';

			foreach ( $ordered_group_ids as $group_index => $group_id ) {
				if ( ! isset( $grouped_tools[ $group_id ] ) || empty( $grouped_tools[ $group_id ] ) ) {
					continue;
				}

				$group_label  = isset( $group_labels[ $group_id ] ) ? $group_labels[ $group_id ] : ucwords( str_replace( '-', ' ', (string) $group_id ) );
				$group_label  = (string) $group_label;
				$group_count  = count( $grouped_tools[ $group_id ] );
				$group_suffix = sanitize_html_class( $group_id );
				$summary_id   = 'wp-mcp-ai-tools-summary-' . $group_suffix;
				$list_id      = 'wp-mcp-ai-tools-list-' . $group_suffix;
				$open_attr    = 0 === $group_index ? ' open' : '';

				echo '<details class="wp-mcp-ai-tools__group" role="group" aria-labelledby="' . esc_attr( $summary_id ) . '"' . esc_attr( $open_attr ) . '>';
				echo '<summary id="' . esc_attr( $summary_id ) . '" class="wp-mcp-ai-tools__summary">';
				echo '<span class="wp-mcp-ai-tools__summary-title">' . esc_html( $group_label ) . '</span>';
				echo '<span class="wp-mcp-ai-tools__summary-count" aria-hidden="true">' . esc_html( number_format_i18n( $group_count ) ) . '</span>';
				/* translators: %d: number of tools */
				echo '<span class="screen-reader-text">' . esc_html( sprintf( _n( '%d tool in this group', '%d tools in this group', $group_count, 'wp-mcp-ai' ), $group_count ) ) . '</span>';
				echo '</summary>';
				echo '<ul class="wp-mcp-ai-tools__list" id="' . esc_attr( $list_id ) . '" role="group" aria-label="' . esc_attr( $group_label ) . '">';

				foreach ( $grouped_tools[ $group_id ] as $tool ) {
					$slug = $tool->get_slug();

					if ( '' === $slug ) {
						continue;
					}

					$is_selected      = in_array( $slug, $selected_tools, true );
					$checkbox_id      = 'wp-mcp-ai-tool-' . sanitize_html_class( $slug );
					$description_id   = 'wp-mcp-ai-tool-description-' . sanitize_html_class( $slug );
					$role_select_id   = 'wp-mcp-ai-tool-roles-' . sanitize_html_class( $slug );
					$role_helper_id   = $role_select_id . '-help';
					$control_disabled = $is_selected ? '' : ' disabled="disabled"';
					$aria_disabled    = $is_selected ? 'false' : 'true';
					$selected_roles   = isset( $tool_role_rules_by_slug[ $slug ]['roles'] ) ? (array) $tool_role_rules_by_slug[ $slug ]['roles'] : array();
					$persisted_groups = isset( $tool_role_rules_by_slug[ $slug ]['groups'] ) ? (array) $tool_role_rules_by_slug[ $slug ]['groups'] : array();
					$persisted_flags  = isset( $tool_role_rules_by_slug[ $slug ]['flags'] ) ? (array) $tool_role_rules_by_slug[ $slug ]['flags'] : array();
					$select_size      = ! empty( $role_options ) ? min( max( count( $role_options ), 4 ), 8 ) : 4;

					echo '<li class="wp-mcp-ai-tools__item" data-tool-selected="' . ( $is_selected ? 'true' : 'false' ) . '">';
					echo '<div class="wp-mcp-ai-tools__header">';
					printf(
						'<input type="checkbox" class="wp-mcp-ai-tools__checkbox" id="%1$s" name="wp_mcp_ai_tools[]" value="%2$s" %3$s aria-describedby="%4$s" />',
						esc_attr( $checkbox_id ),
						esc_attr( $slug ),
						checked( $is_selected, true, false ),
						esc_attr( $description_id )
					);
					echo '<label for="' . esc_attr( $checkbox_id ) . '">';
					echo '<span class="wp-mcp-ai-tools__name">' . esc_html( $tool->get_name() ) . '</span>';
					echo '<p class="wp-mcp-ai-tools__description" id="' . esc_attr( $description_id ) . '">' . esc_html( $tool->get_description() ) . '</p>';
					echo '</label>';
					echo '</div>';

					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $control_disabled is a safe attribute string.
					echo '<input type="hidden" name="wp_mcp_ai_tool_role_rules[' . esc_attr( $slug ) . '][tool]" value="' . esc_attr( $slug ) . '" data-tool-control="1"' . $control_disabled . ' />';

					foreach ( $persisted_groups as $group_value ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $control_disabled is a safe attribute string.
						echo '<input type="hidden" name="wp_mcp_ai_tool_role_rules[' . esc_attr( $slug ) . '][groups][]" value="' . esc_attr( (string) absint( $group_value ) ) . '" data-tool-control="1"' . $control_disabled . ' />';
					}

					foreach ( $persisted_flags as $flag_value ) {
						$flag_value = sanitize_key( $flag_value );

						if ( '' === $flag_value ) {
							continue;
						}

						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $control_disabled is a safe attribute string.
						echo '<input type="hidden" name="wp_mcp_ai_tool_role_rules[' . esc_attr( $slug ) . '][flags][]" value="' . esc_attr( $flag_value ) . '" data-tool-control="1"' . $control_disabled . ' />';
					}

					echo '<div class="wp-mcp-ai-tools__controls">';

					if ( ! empty( $role_options ) ) {
						echo '<label for="' . esc_attr( $role_select_id ) . '">' . esc_html__( 'Limit to selected roles', 'wp-mcp-ai' ) . '</label>';
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $control_disabled is a safe attribute string.
						echo '<select id="' . esc_attr( $role_select_id ) . '" name="wp_mcp_ai_tool_role_rules[' . esc_attr( $slug ) . '][roles][]" class="wp-mcp-ai-tools__role-select" multiple size="' . esc_attr( $select_size ) . '" data-tool-control="1"' . $control_disabled . ' aria-describedby="' . esc_attr( $role_helper_id ) . '" aria-disabled="' . esc_attr( $aria_disabled ) . '">';

						foreach ( $role_options as $role_slug => $role_label ) {
							printf(
								'<option value="%1$s" %2$s>%3$s</option>',
								esc_attr( $role_slug ),
								selected( in_array( $role_slug, $selected_roles, true ), true, false ),
								esc_html( $role_label )
							);
						}

						echo '</select>';
						echo '<p class="description wp-mcp-ai-tools__helper" id="' . esc_attr( $role_helper_id ) . '">' . esc_html__( 'Hold Ctrl (Windows) or Command (macOS) to toggle multiple roles. Leave blank to allow any role with access to the assistant interface.', 'wp-mcp-ai' ) . '</p>';
					} else {
						echo '<p class="description wp-mcp-ai-tools__helper" id="' . esc_attr( $role_helper_id ) . '">' . esc_html__( 'No editable roles are available. All authenticated operators will be able to request this tool.', 'wp-mcp-ai' ) . '</p>';
					}

					if ( 'run_openai_external_action' === $slug ) {
						$identifier_field_id = 'wp-mcp-ai-external-action-id';
						$type_field_id       = 'wp-mcp-ai-external-action-type';
						?>
					<div class="wp-mcp-ai-tools__extra">
						<p>
							<label for="<?php echo esc_attr( $identifier_field_id ); ?>">
								<strong><?php esc_html_e( 'Default workflow or assistant ID', 'wp-mcp-ai' ); ?></strong>
							</label>
							<input
								type="text"
								id="<?php echo esc_attr( $identifier_field_id ); ?>"
								name="wp_mcp_ai_external_action_identifier"
								value="<?php echo esc_attr( $external_action_id ); ?>"
								class="widefat"
								data-tool-control="1"<?php echo $control_disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							/>
						</p>
						<p>
							<label for="<?php echo esc_attr( $type_field_id ); ?>">
								<strong><?php esc_html_e( 'Default action type', 'wp-mcp-ai' ); ?></strong>
							</label>
							<select id="<?php echo esc_attr( $type_field_id ); ?>" name="wp_mcp_ai_external_action_type" class="widefat" data-tool-control="1"<?php echo $control_disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
								<option value="">
									<?php esc_html_e( 'Use runtime choice', 'wp-mcp-ai' ); ?>
								</option>
								<option value="workflow" <?php selected( $external_action_type, 'workflow' ); ?>>
									<?php esc_html_e( 'Workflow', 'wp-mcp-ai' ); ?>
								</option>
								<option value="assistant" <?php selected( $external_action_type, 'assistant' ); ?>>
									<?php esc_html_e( 'Assistant', 'wp-mcp-ai' ); ?>
								</option>
							</select>
						</p>
					</div>
						<?php
					}

					echo '</div>';
					echo '</li>';
				}

				echo '</ul>';
				echo '</details>';
			}

			echo '</div>';

			$this->render_prebuilt_shortcuts_editor( $post, $selected_tools, $prebuilt_shortcuts );
		}

		/**
		 * Render the tool shortcuts meta box content.
		 *
		 * @param WP_Post $post Post object.
		 */
		public function render_tool_shortcuts_meta_box( $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				wp_die( esc_html__( 'You do not have permission to edit this assistant.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
			}

			wp_nonce_field( 'wp_mcp_ai_tool_shortcuts_meta', 'wp_mcp_ai_tool_shortcuts_meta_nonce' );

			$shortcuts = get_post_meta( $post->ID, self::META_TOOL_SHORTCUTS, true );
			if ( ! is_array( $shortcuts ) ) {
				$shortcuts = array();
			}

			$shortcuts = self::sanitize_tool_shortcuts_meta( $shortcuts );

			if ( empty( $shortcuts ) ) {
				$shortcuts = array(
					array(
						'label'       => '',
						'payload'     => '',
						'tool'        => '',
						'description' => '',
					),
				);
			}

			$tools          = $this->registry->get_tools();
			$tool_options   = array( '' => __( 'No specific tool', 'wp-mcp-ai' ) );
			$selected_tools = get_post_meta( $post->ID, self::META_TOOLS, true );
			if ( ! is_array( $selected_tools ) ) {
				$selected_tools = array();
			}

			foreach ( $tools as $tool ) {
				$slug = $tool->get_slug();

				if ( ! empty( $selected_tools ) && ! in_array( $slug, $selected_tools, true ) ) {
					continue;
				}

				$tool_options[ $slug ] = $tool->get_name();
			}

			/* translators: %d: shortcut number */
			$summary_template = __( 'Shortcut %d', 'wp-mcp-ai' );
			$tool_default     = __( 'No specific tool', 'wp-mcp-ai' );
			?>
		<div class="wp-mcp-ai-tool-shortcuts">
			<h3><?php esc_html_e( 'Prompt shortcuts', 'wp-mcp-ai' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Create ready-to-use prompts that will show as shortcuts in the chat interface for this assistant.', 'wp-mcp-ai' ); ?></p>
			<div
				id="wp-mcp-ai-tool-shortcuts-rows"
				class="wp-mcp-ai-tool-shortcuts__rows"
				data-next-index="<?php echo esc_attr( count( $shortcuts ) ); ?>"
			>
				<?php
				foreach ( $shortcuts as $index => $shortcut ) :
					$label            = isset( $shortcut['label'] ) ? $shortcut['label'] : '';
					$payload          = isset( $shortcut['payload'] ) ? $shortcut['payload'] : '';
					$tool             = isset( $shortcut['tool'] ) ? $shortcut['tool'] : '';
					$description      = isset( $shortcut['description'] ) ? $shortcut['description'] : '';
					$summary_fallback = sprintf( $summary_template, intval( $index ) + 1 );
					$tool_label       = isset( $tool_options[ $tool ] ) ? $tool_options[ $tool ] : $tool_default;
					?>
					<details
						class="wp-mcp-ai-tool-shortcuts__item"
						data-index="<?php echo esc_attr( $index ); ?>"
						data-summary-template="<?php echo esc_attr( $summary_template ); ?>"
						data-tool-default="<?php echo esc_attr( $tool_default ); ?>"
						<?php
						if ( 0 === intval( $index ) ) :
							?>
							open<?php endif; ?>
					>
						<summary class="wp-mcp-ai-tool-shortcuts__summary">
							<span class="wp-mcp-ai-tool-shortcuts__summary-title"><?php echo esc_html( '' !== $label ? $label : $summary_fallback ); ?></span>
							<span class="wp-mcp-ai-tool-shortcuts__summary-tool"><?php echo esc_html( $tool_label ); ?></span>
						</summary>
						<fieldset class="wp-mcp-ai-tool-shortcuts__row" data-index="<?php echo esc_attr( $index ); ?>">
							<legend class="screen-reader-text">
								<?php
								/* translators: %d: shortcut number */
								printf( esc_html__( 'Shortcut %d', 'wp-mcp-ai' ), intval( $index ) + 1 );
								?>
							</legend>
							<p>
								<label>
									<strong><?php esc_html_e( 'Shortcut label', 'wp-mcp-ai' ); ?></strong>
									<input
										type="text"
										class="widefat"
										name="wp_mcp_ai_tool_shortcuts[<?php echo esc_attr( $index ); ?>][label]"
										value="<?php echo esc_attr( $label ); ?>"
									/>
								</label>
							</p>
							<p>
								<label>
									<strong><?php esc_html_e( 'Prompt text', 'wp-mcp-ai' ); ?></strong>
									<textarea
										class="widefat"
										rows="4"
										name="wp_mcp_ai_tool_shortcuts[<?php echo esc_attr( $index ); ?>][payload]"
									><?php echo esc_textarea( $payload ); ?></textarea>
								</label>
							</p>
							<p>
								<label>
									<strong><?php esc_html_e( 'Optional description', 'wp-mcp-ai' ); ?></strong>
									<textarea
										class="widefat"
										rows="3"
										name="wp_mcp_ai_tool_shortcuts[<?php echo esc_attr( $index ); ?>][description]"
									><?php echo esc_textarea( $description ); ?></textarea>
								</label>
							</p>
							<p>
								<label>
									<strong><?php esc_html_e( 'Associated tool', 'wp-mcp-ai' ); ?></strong>
									<select
										class="widefat"
										name="wp_mcp_ai_tool_shortcuts[<?php echo esc_attr( $index ); ?>][tool]"
									>
										<?php foreach ( $tool_options as $slug => $name ) : ?>
											<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $tool, $slug ); ?>>
												<?php echo esc_html( $name ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</label>
							</p>
							<p>
								<button type="button" class="button-link-delete wp-mcp-ai-remove-tool-shortcut">
									<?php esc_html_e( 'Remove shortcut', 'wp-mcp-ai' ); ?>
								</button>
							</p>
						</fieldset>
					</details>
				<?php endforeach; ?>
			</div>
			<p>
				<button type="button" class="button" id="wp-mcp-ai-add-tool-shortcut">
						<?php esc_html_e( 'Add shortcut', 'wp-mcp-ai' ); ?>
				</button>
			</p>
		</div>

		<template id="wp-mcp-ai-tool-shortcut-template">
			<details
				class="wp-mcp-ai-tool-shortcuts__item"
				data-index="__INDEX__"
				data-summary-template="<?php echo esc_attr( $summary_template ); ?>"
				data-tool-default="<?php echo esc_attr( $tool_default ); ?>"
				open
			>
				<summary class="wp-mcp-ai-tool-shortcuts__summary">
					<span class="wp-mcp-ai-tool-shortcuts__summary-title"><?php esc_html_e( 'New shortcut', 'wp-mcp-ai' ); ?></span>
					<span class="wp-mcp-ai-tool-shortcuts__summary-tool"><?php echo esc_html( $tool_default ); ?></span>
				</summary>
				<fieldset class="wp-mcp-ai-tool-shortcuts__row" data-index="__INDEX__">
					<legend class="screen-reader-text"><?php esc_html_e( 'New shortcut', 'wp-mcp-ai' ); ?></legend>
					<p>
						<label>
							<strong><?php esc_html_e( 'Shortcut label', 'wp-mcp-ai' ); ?></strong>
							<input type="text" class="widefat" name="wp_mcp_ai_tool_shortcuts[__INDEX__][label]" />
						</label>
					</p>
					<p>
						<label>
							<strong><?php esc_html_e( 'Prompt text', 'wp-mcp-ai' ); ?></strong>
							<textarea class="widefat" rows="4" name="wp_mcp_ai_tool_shortcuts[__INDEX__][payload]"></textarea>
						</label>
					</p>
					<p>
						<label>
							<strong><?php esc_html_e( 'Optional description', 'wp-mcp-ai' ); ?></strong>
							<textarea class="widefat" rows="3" name="wp_mcp_ai_tool_shortcuts[__INDEX__][description]"></textarea>
						</label>
					</p>
					<p>
						<label>
							<strong><?php esc_html_e( 'Associated tool', 'wp-mcp-ai' ); ?></strong>
							<select class="widefat" name="wp_mcp_ai_tool_shortcuts[__INDEX__][tool]">
								<?php foreach ( $tool_options as $slug => $name ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $name ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</p>
					<p>
						<button type="button" class="button-link-delete wp-mcp-ai-remove-tool-shortcut"><?php esc_html_e( 'Remove shortcut', 'wp-mcp-ai' ); ?></button>
					</p>
				</fieldset>
			</details>
		</template>

		<script>
		( function() {
			var container = document.getElementById( 'wp-mcp-ai-tool-shortcuts-rows' );
			var addButton = document.getElementById( 'wp-mcp-ai-add-tool-shortcut' );
			var template = document.getElementById( 'wp-mcp-ai-tool-shortcut-template' );

			if ( ! container || ! template ) {
				return;
			}

			function getNextIndex() {
				var next = parseInt( container.getAttribute( 'data-next-index' ), 10 );

				if ( isNaN( next ) ) {
					next = container.querySelectorAll( '.wp-mcp-ai-tool-shortcuts__item' ).length;
				}

				container.setAttribute( 'data-next-index', next + 1 );

				return next;
			}

			function formatSummaryTemplate( templateText, index ) {
				if ( ! templateText ) {
					return '';
				}

				var replacements = {
					'%s': index + 1,
					'%1$s': index + 1,
					'%d': index + 1,
					'%1$d': index + 1
				};

				var result = templateText;

				Object.keys( replacements ).forEach( function( token ) {
					if ( result.indexOf( token ) !== -1 ) {
						result = result.replace( token, replacements[ token ] );
					}
				} );

				return result;
			}

			function getSummaryFallback( item, index ) {
				var templateText = item.getAttribute( 'data-summary-template' ) || '';

				if ( templateText && typeof index === 'number' && ! isNaN( index ) ) {
					return formatSummaryTemplate( templateText, index );
				}

				return templateText;
			}

			function updateSummary( item ) {
				if ( ! item ) {
					return;
				}

				var index = parseInt( item.getAttribute( 'data-index' ), 10 );

				if ( isNaN( index ) ) {
					index = 0;
				}

				var title = item.querySelector( '.wp-mcp-ai-tool-shortcuts__summary-title' );
				var toolBadge = item.querySelector( '.wp-mcp-ai-tool-shortcuts__summary-tool' );

				if ( ! title || ! toolBadge ) {
					return;
				}

				var labelField = item.querySelector( 'input[name*="[label]"]' );
				var toolField = item.querySelector( 'select[name*="[tool]"]' );
				var label = labelField ? labelField.value.trim() : '';
				var fallbackTitle = getSummaryFallback( item, index ) || title.textContent || '';

				title.textContent = label || fallbackTitle;

				var toolDefault = item.getAttribute( 'data-tool-default' ) || toolBadge.textContent || '';
				var toolText = toolDefault;

				if ( toolField && toolField.options.length ) {
					var selectedOption = toolField.options[ toolField.selectedIndex ];

					if ( selectedOption && selectedOption.text ) {
						var trimmed = selectedOption.text.trim();

						if ( trimmed ) {
							toolText = trimmed;
						}
					}
				}

				toolBadge.textContent = toolText;
			}

			function bindItem( item ) {
				if ( ! item ) {
					return;
				}

				var labelField = item.querySelector( 'input[name*="[label]"]' );
				var toolField = item.querySelector( 'select[name*="[tool]"]' );

				if ( labelField ) {
					labelField.addEventListener( 'input', function() {
						updateSummary( item );
					} );
				}

				if ( toolField ) {
					toolField.addEventListener( 'change', function() {
						updateSummary( item );
					} );
				}

				updateSummary( item );
			}

			function createItem( index ) {
				var fragment = document.importNode( template.content, true );
				var html = '';

				if ( fragment.firstElementChild ) {
					html = fragment.firstElementChild.outerHTML;
				} else if ( fragment.children.length ) {
					html = fragment.children[0].outerHTML;
				}

				if ( ! html ) {
					return null;
				}

				html = html.replace( /__INDEX__/g, index );

				var wrapper = document.createElement( 'div' );
				wrapper.innerHTML = html;
				var item = wrapper.firstElementChild;

				if ( ! item ) {
					return null;
				}

				item.setAttribute( 'data-index', index );
				container.appendChild( item );

				bindItem( item );

				item.setAttribute( 'open', 'open' );

				return item;
			}

			if ( addButton ) {
				addButton.addEventListener( 'click', function() {
					var index = getNextIndex();
					createItem( index );
				} );
			}

			container.addEventListener( 'click', function( event ) {
				var target = event.target;

				if ( target && target.classList && target.classList.contains( 'wp-mcp-ai-remove-tool-shortcut' ) ) {
					event.preventDefault();

					var item = target.closest( '.wp-mcp-ai-tool-shortcuts__item' );

					if ( item && container.contains( item ) ) {
						container.removeChild( item );
					}
				}
			} );

			var existingItems = container.querySelectorAll( '.wp-mcp-ai-tool-shortcuts__item' );

			Array.prototype.forEach.call( existingItems, function( item ) {
				bindItem( item );
			} );
		} )();
		</script>
			<?php
		}

		/**
		 * Render the defaults meta box content.
		 *
		 * @param WP_Post $post Post object.
		 */
		public function render_defaults_meta_box( $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				wp_die( esc_html__( 'You do not have permission to edit this assistant.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
			}

			wp_nonce_field( 'wp_mcp_ai_defaults_meta', 'wp_mcp_ai_defaults_meta_nonce' );

			$provider      = get_post_meta( $post->ID, self::META_PROVIDER, true );
			$provider      = self::sanitize_provider_meta( $provider );
			$model         = get_post_meta( $post->ID, self::META_MODEL, true );
			$temperature   = get_post_meta( $post->ID, self::META_TEMPERATURE, true );
			$system_prompt = get_post_meta( $post->ID, self::META_SYSTEM_PROMPT, true );

			$settings         = WP_MCP_AI_Admin_Settings::get_settings();
			$default_provider = isset( $settings['default_provider'] ) ? sanitize_key( $settings['default_provider'] ) : 'openai';

			if ( '' === $provider ) {
				$provider = $default_provider;
			}

			$provider_choices = apply_filters( 'wp_mcp_ai_allowed_providers', array( 'openai', 'anthropic', 'gemini', 'ollama', 'lm_studio' ) );
			if ( ! is_array( $provider_choices ) ) {
				$provider_choices = array( 'openai', 'anthropic', 'gemini', 'ollama', 'lm_studio' );
			}

			if ( '' === $temperature ) {
				$temperature = '';
			}

			?>
		<p>
			<label for="wp-mcp-ai-provider"><strong><?php esc_html_e( 'Provider', 'wp-mcp-ai' ); ?></strong></label>
			<select id="wp-mcp-ai-provider" name="wp_mcp_ai_provider" class="widefat">
				<?php
				foreach ( $provider_choices as $choice ) {
					$choice = sanitize_key( $choice );
					if ( '' === $choice ) {
						continue;
					}

					$provider_labels = array(
						'openai'    => __( 'OpenAI', 'wp-mcp-ai' ),
						'gemini'    => __( 'Gemini', 'wp-mcp-ai' ),
						'ollama'    => __( 'Ollama', 'wp-mcp-ai' ),
						'lm_studio' => __( 'LM Studio', 'wp-mcp-ai' ),
					);

					$label = isset( $provider_labels[ $choice ] ) ? $provider_labels[ $choice ] : ucfirst( str_replace( '_', ' ', $choice ) );
					?>
					<option value="<?php echo esc_attr( $choice ); ?>" <?php selected( $provider, $choice ); ?>><?php echo esc_html( $label ); ?></option>
					<?php
				}
				?>
			</select>
		</p>
		<p>
			<label for="wp-mcp-ai-model"><strong><?php esc_html_e( 'Model', 'wp-mcp-ai' ); ?></strong></label>
			<input type="text" id="wp-mcp-ai-model" name="wp_mcp_ai_model" value="<?php echo esc_attr( $model ); ?>" class="widefat" />
		</p>
		<p>
			<label for="wp-mcp-ai-temperature"><strong><?php esc_html_e( 'Temperature', 'wp-mcp-ai' ); ?></strong></label>
			<input type="number" step="0.1" min="0" max="2" id="wp-mcp-ai-temperature" name="wp_mcp_ai_temperature" value="<?php echo esc_attr( $temperature ); ?>" class="widefat" />
		</p>
		<p>
			<label for="wp-mcp-ai-system-prompt"><strong><?php esc_html_e( 'System Prompt', 'wp-mcp-ai' ); ?></strong></label>
			<textarea id="wp-mcp-ai-system-prompt" name="wp_mcp_ai_system_prompt" class="widefat" rows="5"><?php echo esc_textarea( $system_prompt ); ?></textarea>
		</p>
			<?php
		}

		/**
		 * Render the base knowledge meta box content.
		 *
		 * @param WP_Post $post Post object.
		 */
		public function render_base_knowledge_meta_box( $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				wp_die( esc_html__( 'You do not have permission to edit this assistant.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
			}

			wp_nonce_field( 'wp_mcp_ai_base_knowledge_meta', 'wp_mcp_ai_base_knowledge_meta_nonce' );

			wp_enqueue_media();
			wp_enqueue_script( 'jquery' );

			$memory_files    = get_post_meta( $post->ID, self::META_MEMORY_FILES, true );
			$vector_store_id = get_post_meta( $post->ID, self::META_VECTOR_STORE_ID, true );

			if ( ! is_array( $memory_files ) ) {
				$memory_files = array();
			}

			if ( ! is_string( $vector_store_id ) ) {
				$vector_store_id = '';
			}

			$memory_entries    = array();
			$memory_size_bytes = 0;

			foreach ( $memory_files as $file_id ) {
				$file_id    = absint( $file_id );
				$attachment = get_post( $file_id );

				if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
					continue;
				}

				$file_size_bytes = 0;
				$file_size_label = '';
				$file_path       = get_attached_file( $file_id );

				if ( $file_path && file_exists( $file_path ) ) {
					$file_size = filesize( $file_path );
					if ( false !== $file_size ) {
						$file_size_bytes    = (int) $file_size;
						$file_size_label    = size_format( $file_size_bytes );
						$memory_size_bytes += $file_size_bytes;
					}
				}

				$memory_entries[] = array(
					'id'    => $file_id,
					'title' => get_the_title( $attachment ),
					'size'  => $file_size_label,
				);
			}

			$memory_size_label = size_format( $memory_size_bytes );

			?>
		<p><?php esc_html_e( 'Select Media Library items that should be preloaded as reference material for this assistant.', 'wp-mcp-ai' ); ?></p>
		<ul id="wp-mcp-ai-memory-files-list" class="wp-mcp-ai-memory-files">
			<?php
			foreach ( $memory_entries as $entry ) :
				$file_id = $entry['id'];
				$title   = $entry['title'];
				$size    = isset( $entry['size'] ) ? $entry['size'] : '';
				?>
				<li data-id="<?php echo esc_attr( $file_id ); ?>">
					<span class="wp-mcp-ai-memory-file-title">
					<?php
					/* translators: %d: attachment ID */
					echo esc_html( $title ? $title : sprintf( __( 'Attachment #%d', 'wp-mcp-ai' ), $file_id ) );
					?>
				</span>
					<?php if ( '' !== $size ) : ?>
						<span class="wp-mcp-ai-memory-file-size">(<?php echo esc_html( $size ); ?>)</span>
					<?php endif; ?>
					<button type="button" class="button-link wp-mcp-ai-remove-memory"><?php esc_html_e( 'Remove', 'wp-mcp-ai' ); ?></button>
					<input type="hidden" name="wp_mcp_ai_memory_files[]" value="<?php echo esc_attr( $file_id ); ?>" />
				</li>
			<?php endforeach; ?>
		</ul>
		<p class="description">
				<?php
				printf(
				/* translators: %s: Human-readable size of the memory payload. */
					esc_html__( 'Total memory size sent with each request: %s.', 'wp-mcp-ai' ),
					esc_html( $memory_size_label )
				);
				?>
		</p>
		<p>
			<button type="button" class="button" id="wp-mcp-ai-memory-select">
				<?php esc_html_e( 'Add Knowledge Files', 'wp-mcp-ai' ); ?>
			</button>
		</p>
		<p>
			<label for="wp-mcp-ai-vector-store-id"><strong><?php esc_html_e( 'Vector Store ID', 'wp-mcp-ai' ); ?></strong></label>
			<input type="text" id="wp-mcp-ai-vector-store-id" name="wp_mcp_ai_vector_store_id" value="<?php echo esc_attr( $vector_store_id ); ?>" class="widefat" />
			<span class="description"><?php esc_html_e( 'Optional identifier for an external vector store that should be associated with this assistant.', 'wp-mcp-ai' ); ?></span>
		</p>
		<style type="text/css">
			.wp-mcp-ai-memory-file-size {
				color: #646970;
				font-size: 0.9em;
				margin-left: 0.5em;
			}
		</style>
		<script type="text/javascript">
		jQuery( function( $ ) {
			var frame;
			var list = $( '#wp-mcp-ai-memory-files-list' );

			function addAttachment( attachment ) {
				var id = attachment.id || attachment.ID;
				if ( ! id ) {
					return;
				}

				if ( list.find( 'li[data-id="' + id + '"]' ).length ) {
					return;
				}

				var title = attachment.title || attachment.filename || attachment.name || '<?php echo esc_js( __( 'Attachment', 'wp-mcp-ai' ) ); ?>';
				var label = title + ' (ID: ' + id + ')';
				var filesize = attachment.filesizeHumanReadable || '';

				var item = $( '<li />', { 'data-id': id } );
				item.append( $( '<span />', { 'class': 'wp-mcp-ai-memory-file-title', 'text': label } ) );
				if ( filesize ) {
					item.append( $( '<span />', { 'class': 'wp-mcp-ai-memory-file-size', 'text': '(' + filesize + ')' } ) );
				}
				item.append( $( '<button />', { 'type': 'button', 'class': 'button-link wp-mcp-ai-remove-memory', 'text': '<?php echo esc_js( __( 'Remove', 'wp-mcp-ai' ) ); ?>' } ) );
				item.append( $( '<input />', { 'type': 'hidden', 'name': 'wp_mcp_ai_memory_files[]', 'value': id } ) );

				list.append( item );
			}

			$( '#wp-mcp-ai-memory-select' ).on( 'click', function( event ) {
				event.preventDefault();

				if ( frame ) {
					frame.open();
					return;
				}

				frame = wp.media({
					title: '<?php echo esc_js( __( 'Select knowledge files', 'wp-mcp-ai' ) ); ?>',
					button: {
						text: '<?php echo esc_js( __( 'Use files', 'wp-mcp-ai' ) ); ?>'
					},
					multiple: true
				});

				frame.on( 'select', function() {
					var selection = frame.state().get( 'selection' );
					if ( ! selection ) {
						return;
					}

					selection.each( function( attachment ) {
						addAttachment( attachment.toJSON() );
					} );
				});

				frame.open();
			} );

			list.on( 'click', '.wp-mcp-ai-remove-memory', function( event ) {
				event.preventDefault();
				$( this ).closest( 'li' ).remove();
			} );
		} );
		</script>
			<?php
		}

		/**
		 * Render the required capability meta box.
		 *
		 * @param WP_Post $post Post object.
		 */
		public function render_required_capability_meta_box( $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				wp_die( esc_html__( 'You do not have permission to edit this assistant.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
			}

			wp_nonce_field( 'wp_mcp_ai_required_capability_meta', 'wp_mcp_ai_required_capability_meta_nonce' );

			$required_capability = get_post_meta( $post->ID, self::META_REQUIRED_CAPABILITY, true );
			if ( ! is_string( $required_capability ) ) {
				$required_capability = '';
			}

			$required_capability = self::sanitize_required_capability_meta( $required_capability );

			?>
			<p>
				<label for="wp-mcp-ai-required-capability">
					<strong><?php esc_html_e( 'Required Capability', 'wp-mcp-ai' ); ?></strong>
				</label>
			</p>
			<p>
				<select id="wp-mcp-ai-required-capability" name="wp_mcp_ai_required_capability" class="widefat">
					<option value="" <?php selected( $required_capability, '' ); ?>>
						<?php esc_html_e( 'No requirement (use global setting)', 'wp-mcp-ai' ); ?>
					</option>
					<option value="public" <?php selected( $required_capability, 'public' ); ?>>
						<?php esc_html_e( 'Public (anyone can access)', 'wp-mcp-ai' ); ?>
					</option>
					<option value="read" <?php selected( $required_capability, 'read' ); ?>>
						<?php esc_html_e( 'Read (subscribers and above)', 'wp-mcp-ai' ); ?>
					</option>
					<option value="edit_posts" <?php selected( $required_capability, 'edit_posts' ); ?>>
						<?php esc_html_e( 'Edit Posts (contributors and above)', 'wp-mcp-ai' ); ?>
					</option>
					<option value="publish_posts" <?php selected( $required_capability, 'publish_posts' ); ?>>
						<?php esc_html_e( 'Publish Posts (authors and above)', 'wp-mcp-ai' ); ?>
					</option>
					<option value="edit_pages" <?php selected( $required_capability, 'edit_pages' ); ?>>
						<?php esc_html_e( 'Edit Pages (editors and above)', 'wp-mcp-ai' ); ?>
					</option>
					<option value="manage_options" <?php selected( $required_capability, 'manage_options' ); ?>>
						<?php esc_html_e( 'Manage Options (administrators only)', 'wp-mcp-ai' ); ?>
					</option>
				</select>
			</p>
			<p class="description">
				<?php esc_html_e( 'Set the WordPress capability required to access this assistant. Leave empty to use the global capability setting. Set to "Public" to allow anyone (including guests) to access this assistant.', 'wp-mcp-ai' ); ?>
			</p>
			<?php
		}

		/**
		 * Handle credential issuance requests from the admin UI.
		 */
		public function handle_issue_credential() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to manage assistant credentials.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
			}

			// Extract post_id before nonce verification to construct proper nonce action.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified immediately after on line 2506.
			$post_id = isset( $_REQUEST['post_id'] ) ? absint( wp_unslash( $_REQUEST['post_id'] ) ) : 0;
			if ( ! $post_id ) {
				wp_die( esc_html__( 'Invalid assistant.', 'wp-mcp-ai' ), '', array( 'response' => 400 ) );
			}

			check_admin_referer( 'wp_mcp_ai_issue_credential_' . $post_id, 'wp_mcp_ai_issue_nonce' );

			$post = get_post( $post_id );
			if ( ! $post || self::POST_TYPE !== $post->post_type ) {
				wp_die( esc_html__( 'Invalid assistant.', 'wp-mcp-ai' ), '', array( 'response' => 404 ) );
			}

			$user_id = get_current_user_id();
			$issued  = WP_MCP_AI_Credentials::issue_credential( $post_id, $user_id );

			if ( is_wp_error( $issued ) ) {
				$error_code = sanitize_key( $issued->get_error_code() );
				$this->redirect_with_notice( $post_id, 'credential_error', array( 'error' => $error_code ) );
			}

			set_transient(
				$this->get_token_transient_key( $user_id ),
				array(
					'assistant_id' => $post_id,
					'token'        => $issued['token'],
				),
				10 * MINUTE_IN_SECONDS
			);

			$this->redirect_with_notice( $post_id, 'credential_created' );
		}

		/**
		 * Handle credential revocation requests from the admin UI.
		 */
		public function handle_revoke_credential() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to manage assistant credentials.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
			}

			// Extract parameters before nonce verification to construct proper nonce action.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified immediately after on line 2550.
			$post_id = isset( $_REQUEST['post_id'] ) ? absint( wp_unslash( $_REQUEST['post_id'] ) ) : 0;
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified immediately after on line 2550.
			$credential_id = isset( $_REQUEST['credential_id'] ) ? sanitize_key( wp_unslash( $_REQUEST['credential_id'] ) ) : '';

			if ( ! $post_id || '' === $credential_id ) {
				wp_die( esc_html__( 'Invalid credential request.', 'wp-mcp-ai' ), '', array( 'response' => 400 ) );
			}

			$nonce_field = $this->get_credential_nonce_field_name( 'wp_mcp_ai_revoke_nonce', $credential_id );

			check_admin_referer( 'wp_mcp_ai_revoke_credential_' . $post_id . '_' . $credential_id, $nonce_field );

			$post = get_post( $post_id );
			if ( ! $post || self::POST_TYPE !== $post->post_type ) {
				wp_die( esc_html__( 'Invalid assistant.', 'wp-mcp-ai' ), '', array( 'response' => 404 ) );
			}

			$result = WP_MCP_AI_Credentials::revoke_credential( $post_id, $credential_id, get_current_user_id() );

			if ( is_wp_error( $result ) ) {
				$error_code = sanitize_key( $result->get_error_code() );
				$this->redirect_with_notice( $post_id, 'credential_error', array( 'error' => $error_code ) );
			}

			$this->redirect_with_notice( $post_id, 'credential_revoked' );
		}

		/**
		 * Handle credential deletion requests from the admin UI.
		 */
		public function handle_delete_credential() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to manage assistant credentials.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
			}

			// Extract parameters before nonce verification to construct proper nonce action.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified immediately after on line 2584.
			$post_id = isset( $_REQUEST['post_id'] ) ? absint( wp_unslash( $_REQUEST['post_id'] ) ) : 0;
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified immediately after on line 2584.
			$credential_id = isset( $_REQUEST['credential_id'] ) ? sanitize_key( wp_unslash( $_REQUEST['credential_id'] ) ) : '';

			if ( ! $post_id || '' === $credential_id ) {
				wp_die( esc_html__( 'Invalid credential request.', 'wp-mcp-ai' ), '', array( 'response' => 400 ) );
			}

			$nonce_field = $this->get_credential_nonce_field_name( 'wp_mcp_ai_delete_nonce', $credential_id );

			check_admin_referer( 'wp_mcp_ai_delete_credential_' . $post_id . '_' . $credential_id, $nonce_field );

			$post = get_post( $post_id );
			if ( ! $post || self::POST_TYPE !== $post->post_type ) {
				wp_die( esc_html__( 'Invalid assistant.', 'wp-mcp-ai' ), '', array( 'response' => 404 ) );
			}

			$result = WP_MCP_AI_Credentials::delete_credential( $post_id, $credential_id, get_current_user_id() );

			if ( is_wp_error( $result ) ) {
				$error_code = sanitize_key( $result->get_error_code() );
				$this->redirect_with_notice( $post_id, 'credential_error', array( 'error' => $error_code ) );
			}

			$this->redirect_with_notice( $post_id, 'credential_deleted' );
		}

		/**
		 * Display notices related to credential management.
		 */
		public function render_admin_notices() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			if ( ! function_exists( 'get_current_screen' ) ) {
				return;
			}

			$screen = get_current_screen();

			if ( ! $screen || 'post' !== $screen->base || self::POST_TYPE !== $screen->post_type ) {
				return;
			}

			// Get post ID from query string for admin edit screen.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Standard WordPress admin screen parameter.
			$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
			if ( ! $post_id ) {
				return;
			}

			$user_id       = get_current_user_id();
			$transient_key = $this->get_token_transient_key( $user_id );
			$token_notice  = get_transient( $transient_key );

			if ( is_array( $token_notice ) && isset( $token_notice['assistant_id'], $token_notice['token'] ) && absint( $token_notice['assistant_id'] ) === $post_id ) {
				delete_transient( $transient_key );

				printf(
					'<div class="notice notice-success"><p>%s</p></div>',
					sprintf(
					/* translators: %s: credential token */
						esc_html__( 'New credential issued. Copy this token now: %s', 'wp-mcp-ai' ),
						'<code>' . esc_html( $token_notice['token'] ) . '</code>'
					)
				);
			}

			// Display admin notice if present in query string after redirect.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for admin notice display.
			$notice = isset( $_GET['wp_mcp_ai_notice'] ) ? sanitize_key( wp_unslash( $_GET['wp_mcp_ai_notice'] ) ) : '';

			if ( '' === $notice ) {
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for admin notice display.
			$error_code = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
			$message    = $this->get_notice_message( $notice, $error_code );

			if ( '' === $message ) {
				return;
			}

			$class = in_array( $notice, array( 'credential_created', 'credential_revoked', 'credential_deleted' ), true ) ? 'notice-success' : 'notice-error';

			printf( '<div class="notice %1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
		}

		/**
		 * Build the transient key used for temporary token storage.
		 *
		 * @param int $user_id User ID.
		 * @return string
		 */
		protected function get_token_transient_key( $user_id ) {
			return 'wp_mcp_ai_new_token_' . absint( $user_id );
		}

		/**
		 * Redirect back to the assistant edit screen with a notice.
		 *
		 * @param int    $post_id Assistant post ID.
		 * @param string $notice  Notice identifier.
		 * @param array  $extra   Additional query arguments.
		 */
		protected function redirect_with_notice( $post_id, $notice, $extra = array() ) {
			$args = array_merge(
				array(
					'post'             => absint( $post_id ),
					'action'           => 'edit',
					'wp_mcp_ai_notice' => sanitize_key( $notice ),
				),
				array_change_key_case( $extra, CASE_LOWER )
			);

			wp_safe_redirect( add_query_arg( $args, admin_url( 'post.php' ) ) );
			exit;
		}

		/**
		 * Map notice identifiers to user-facing messages.
		 *
		 * @param string $notice     Notice identifier.
		 * @param string $error_code Optional error code.
		 * @return string
		 */
		protected function get_notice_message( $notice, $error_code = '' ) {
			switch ( $notice ) {
				case 'credential_created':
					return __( 'Credential issued successfully.', 'wp-mcp-ai' );
				case 'credential_revoked':
					return __( 'Credential revoked successfully.', 'wp-mcp-ai' );
				case 'credential_deleted':
					return __( 'Credential deleted successfully.', 'wp-mcp-ai' );
				case 'credential_error':
					switch ( $error_code ) {
						case 'wp_mcp_ai_unknown_credential':
							return __( 'The requested credential could not be found.', 'wp-mcp-ai' );
						case 'wp_mcp_ai_credential_already_revoked':
							return __( 'The credential has already been revoked.', 'wp-mcp-ai' );
						case 'wp_mcp_ai_invalid_assistant':
							return __( 'Unable to manage credentials for this assistant.', 'wp-mcp-ai' );
						default:
							return __( 'An error occurred while managing the credential.', 'wp-mcp-ai' );
					}
			}

			return '';
		}

		/**
		 * Remove credential index entries when an assistant is deleted.
		 *
		 * @param int     $post_id Post ID being deleted.
		 * @param WP_Post $post    Post object being deleted (optional, provided by WordPress).
		 */
		public function cleanup_deleted_assistant_credentials( $post_id, $post = null ) {
			// Post type check is no longer needed since we use delete_{post_type} hook.
			WP_MCP_AI_Credentials::purge_assistant_credentials( $post_id );

			// Also remove the linked CCT item if it exists.
			$this->delete_cct_item( $post_id );
		}

		/**
		 * Delete the linked JetEngine CCT item for this assistant.
		 *
		 * @param int $post_id Post ID.
		 */
		protected function delete_cct_item( $post_id ) {
			// Only attempt deletion in Full Version when JetEngine is available.
			if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
				return;
			}

			if ( ! class_exists( 'WP_MCP_AI_JetEngine_Assistants_CCT' ) ) {
				return;
			}

			$cct_item_id = get_post_meta( $post_id, '_wp_mcp_ai_cct_item_id', true );

			if ( ! $cct_item_id ) {
				return;
			}

			$handler = WP_MCP_AI_JetEngine_Assistants_CCT::get_item_handler();

			if ( ! $handler ) {
				return;
			}

			// Delete the CCT item.
			$handler->delete_item( absint( $cct_item_id ) );

			// Remove the meta link.
			delete_post_meta( $post_id, '_wp_mcp_ai_cct_item_id' );
		}

		/**
		 * Persist assistant meta fields.
		 *
		 * @param int     $post_id Post ID.
		 * @param WP_Post $post    Post object.
		 */
		public function save_post( $post_id, $post ) {
			// Post type check is no longer needed since we use save_post_{post_type} hook.
			if ( ! $post instanceof WP_Post ) {
				return;
			}

			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			// Handle tools meta.
			if ( isset( $_POST['wp_mcp_ai_tools_meta_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_tools_meta_nonce'] ) ), 'wp_mcp_ai_tools_meta' ) ) {
				$tool_slugs = array();
				if ( isset( $_POST['wp_mcp_ai_tools'] ) ) {
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_tools_meta().
					$tool_slugs = self::sanitize_tools_meta( wp_unslash( $_POST['wp_mcp_ai_tools'] ) );
				}

				update_post_meta( $post_id, self::META_TOOLS, $tool_slugs );

				$external_action_id = isset( $_POST['wp_mcp_ai_external_action_identifier'] )
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_external_action_id_meta().
				? self::sanitize_external_action_id_meta( wp_unslash( $_POST['wp_mcp_ai_external_action_identifier'] ) )
				: '';

				if ( '' === $external_action_id ) {
					delete_post_meta( $post_id, self::META_EXTERNAL_ACTION_ID );
				} else {
					update_post_meta( $post_id, self::META_EXTERNAL_ACTION_ID, $external_action_id );
				}

				$external_action_type = isset( $_POST['wp_mcp_ai_external_action_type'] )
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_external_action_type_meta().
				? self::sanitize_external_action_type_meta( wp_unslash( $_POST['wp_mcp_ai_external_action_type'] ) )
				: '';

				if ( '' === $external_action_type ) {
					delete_post_meta( $post_id, self::META_EXTERNAL_ACTION_TYPE );
				} else {
					update_post_meta( $post_id, self::META_EXTERNAL_ACTION_TYPE, $external_action_type );
				}

				$tool_role_rules = array();

				if ( isset( $_POST['wp_mcp_ai_tool_role_rules'] ) ) {
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_tool_role_rules_meta().
					$tool_role_rules = self::sanitize_tool_role_rules_meta( wp_unslash( $_POST['wp_mcp_ai_tool_role_rules'] ) );
				}

				if ( empty( $tool_role_rules ) ) {
					delete_post_meta( $post_id, self::META_TOOL_ROLE_RULES );
				} else {
					update_post_meta( $post_id, self::META_TOOL_ROLE_RULES, $tool_role_rules );
				}

				$disable_tool_shortcuts = isset( $_POST['wp_mcp_ai_disable_prebuilt_shortcuts'] )
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_disable_tool_shortcuts_meta().
				? self::sanitize_disable_tool_shortcuts_meta( wp_unslash( $_POST['wp_mcp_ai_disable_prebuilt_shortcuts'] ) )
				: false;

				if ( $disable_tool_shortcuts ) {
					update_post_meta( $post_id, self::META_DISABLE_TOOL_SHORTCUTS, true );
				} else {
					delete_post_meta( $post_id, self::META_DISABLE_TOOL_SHORTCUTS );
				}

				$prebuilt_tool_shortcuts = array();

				if ( isset( $_POST['wp_mcp_ai_prebuilt_shortcuts'] ) ) {
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_prebuilt_tool_shortcuts_meta().
					$prebuilt_tool_shortcuts = self::sanitize_prebuilt_tool_shortcuts_meta( wp_unslash( $_POST['wp_mcp_ai_prebuilt_shortcuts'] ) );
				}

				if ( empty( $prebuilt_tool_shortcuts ) ) {
					delete_post_meta( $post_id, self::META_TOOL_PREBUILT_SHORTCUTS );
				} else {
					update_post_meta( $post_id, self::META_TOOL_PREBUILT_SHORTCUTS, $prebuilt_tool_shortcuts );
				}
			}

			// Handle tool shortcuts meta.
			if ( isset( $_POST['wp_mcp_ai_tool_shortcuts_meta_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_tool_shortcuts_meta_nonce'] ) ), 'wp_mcp_ai_tool_shortcuts_meta' ) ) {
				$tool_shortcuts = array();

				if ( isset( $_POST['wp_mcp_ai_tool_shortcuts'] ) ) {
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_tool_shortcuts_meta().
					$tool_shortcuts = self::sanitize_tool_shortcuts_meta( wp_unslash( $_POST['wp_mcp_ai_tool_shortcuts'] ) );
				}

				if ( empty( $tool_shortcuts ) ) {
					delete_post_meta( $post_id, self::META_TOOL_SHORTCUTS );
				} else {
					update_post_meta( $post_id, self::META_TOOL_SHORTCUTS, $tool_shortcuts );
				}
			}

			// Handle required capability meta.
			if ( isset( $_POST['wp_mcp_ai_required_capability_meta_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_required_capability_meta_nonce'] ) ), 'wp_mcp_ai_required_capability_meta' ) ) {
				$required_capability = isset( $_POST['wp_mcp_ai_required_capability'] )
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_required_capability_meta().
				? self::sanitize_required_capability_meta( wp_unslash( $_POST['wp_mcp_ai_required_capability'] ) )
				: '';

				if ( '' === $required_capability ) {
					delete_post_meta( $post_id, self::META_REQUIRED_CAPABILITY );
				} else {
					update_post_meta( $post_id, self::META_REQUIRED_CAPABILITY, $required_capability );
				}
			}

			// Handle primary roles meta.
			if ( isset( $_POST['wp_mcp_ai_primary_roles_meta_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_primary_roles_meta_nonce'] ) ), 'wp_mcp_ai_primary_roles_meta' ) ) {
				$primary_roles = array();
				if ( isset( $_POST['wp_mcp_ai_primary_roles'] ) ) {
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_primary_roles_meta().
					$primary_roles = self::sanitize_primary_roles_meta( wp_unslash( $_POST['wp_mcp_ai_primary_roles'] ) );
				}

				if ( empty( $primary_roles ) ) {
					delete_post_meta( $post_id, self::META_PRIMARY_ROLES );
				} else {
					update_post_meta( $post_id, self::META_PRIMARY_ROLES, $primary_roles );
				}
			}

			// Handle defaults meta.
			if ( isset( $_POST['wp_mcp_ai_defaults_meta_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_defaults_meta_nonce'] ) ), 'wp_mcp_ai_defaults_meta' ) ) {
				$provider = isset( $_POST['wp_mcp_ai_provider'] )
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_provider_meta().
				? self::sanitize_provider_meta( wp_unslash( $_POST['wp_mcp_ai_provider'] ) )
				: '';

				if ( '' === $provider ) {
					delete_post_meta( $post_id, self::META_PROVIDER );
				} else {
					update_post_meta( $post_id, self::META_PROVIDER, $provider );
				}

				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_model_meta().
				$model = isset( $_POST['wp_mcp_ai_model'] ) ? self::sanitize_model_meta( wp_unslash( $_POST['wp_mcp_ai_model'] ) ) : '';
				update_post_meta( $post_id, self::META_MODEL, $model );

				$temperature = null;
				if ( isset( $_POST['wp_mcp_ai_temperature'] ) ) {
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_temperature_meta().
					$temperature = self::sanitize_temperature_meta( wp_unslash( $_POST['wp_mcp_ai_temperature'] ) );
				}

				if ( null === $temperature ) {
					delete_post_meta( $post_id, self::META_TEMPERATURE );
				} else {
					update_post_meta( $post_id, self::META_TEMPERATURE, $temperature );
				}

				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_system_prompt_meta().
				$system_prompt = isset( $_POST['wp_mcp_ai_system_prompt'] ) ? self::sanitize_system_prompt_meta( wp_unslash( $_POST['wp_mcp_ai_system_prompt'] ) ) : '';
				update_post_meta( $post_id, self::META_SYSTEM_PROMPT, $system_prompt );
			}

			// Handle base knowledge meta.
			if ( isset( $_POST['wp_mcp_ai_base_knowledge_meta_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_base_knowledge_meta_nonce'] ) ), 'wp_mcp_ai_base_knowledge_meta' ) ) {
				$memory_files = array();
				if ( isset( $_POST['wp_mcp_ai_memory_files'] ) ) {
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_memory_files_meta().
					$memory_files = self::sanitize_memory_files_meta( wp_unslash( $_POST['wp_mcp_ai_memory_files'] ) );
				}

				update_post_meta( $post_id, self::META_MEMORY_FILES, $memory_files );

				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_vector_store_meta().
				$vector_store_id = isset( $_POST['wp_mcp_ai_vector_store_id'] ) ? self::sanitize_vector_store_meta( wp_unslash( $_POST['wp_mcp_ai_vector_store_id'] ) ) : '';
				update_post_meta( $post_id, self::META_VECTOR_STORE_ID, $vector_store_id );
			}

			// Handle mesh routing configuration.
			if ( isset( $_POST['wp_mcp_ai_save_mesh_routing_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_save_mesh_routing_nonce'] ) ), 'wp_mcp_ai_save_mesh_routing' ) ) {
				if ( current_user_can( 'manage_options' ) ) {
					$mesh_config = array();

					// Routing strategy.
					if ( isset( $_POST['wp_mcp_ai_mesh_routing']['routing_strategy'] ) ) {
						$strategy           = sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_mesh_routing']['routing_strategy'] ) );
						$allowed_strategies = array( 'ai_optimized', 'round_robin', 'least_loaded', 'preferred_with_fallback' );
						if ( in_array( $strategy, $allowed_strategies, true ) ) {
							$mesh_config['routing_strategy'] = $strategy;
						}
					}

					// Compute hubs.
					if ( isset( $_POST['wp_mcp_ai_mesh_routing']['compute_hubs'] ) && is_array( $_POST['wp_mcp_ai_mesh_routing']['compute_hubs'] ) ) {
						$compute_hubs                = array_map( 'sanitize_text_field', wp_unslash( $_POST['wp_mcp_ai_mesh_routing']['compute_hubs'] ) );
						$mesh_config['compute_hubs'] = array_filter( $compute_hubs );
					} else {
						$mesh_config['compute_hubs'] = array();
					}

					// Preferred peers.
					if ( isset( $_POST['wp_mcp_ai_mesh_routing']['preferred_peers'] ) && is_array( $_POST['wp_mcp_ai_mesh_routing']['preferred_peers'] ) ) {
						$preferred_peers                = array_map( 'sanitize_text_field', wp_unslash( $_POST['wp_mcp_ai_mesh_routing']['preferred_peers'] ) );
						$mesh_config['preferred_peers'] = array_filter( $preferred_peers );
					} else {
						$mesh_config['preferred_peers'] = array();
					}

					// Enable retry.
					$mesh_config['enable_retry'] = isset( $_POST['wp_mcp_ai_mesh_routing']['enable_retry'] );

					// Max retries.
					if ( isset( $_POST['wp_mcp_ai_mesh_routing']['max_retries'] ) ) {
						$max_retries                = absint( wp_unslash( $_POST['wp_mcp_ai_mesh_routing']['max_retries'] ) );
						$mesh_config['max_retries'] = min( max( 1, $max_retries ), 10 );
					}

					WP_MCP_AI_Mesh_Router::update_hub_config( $post_id, $mesh_config );
				}
			}

			// Delegate to metabox save methods for additional processing.
			foreach ( $this->metaboxes as $metabox ) {
				if ( $metabox instanceof WP_MCP_AI_Metabox_Base ) {
					$metabox->save( $post_id, $post );
				}
			}

			// Sync to JetEngine CCT if available.
			$this->sync_to_cct( $post_id, $post );
		}

		/**
		 * Synchronize CPT data to the JetEngine assistants CCT.
		 *
		 * This ensures that API consumers using the JetEngine CCT endpoint
		 * have access to the same assistant configuration as the CPT.
		 *
		 * @param int     $post_id Post ID.
		 * @param WP_Post $post    Post object.
		 */
		protected function sync_to_cct( $post_id, $post ) {
			// Only sync in Full Version when JetEngine is available.
			if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
				return;
			}

			if ( ! class_exists( 'WP_MCP_AI_JetEngine_Assistants_CCT' ) ) {
				return;
			}

			// Prevent concurrent sync operations using a transient lock.
			$lock_key = 'wp_mcp_ai_sync_lock_' . $post_id;
			if ( get_transient( $lock_key ) ) {
				// Another sync is in progress, skip to prevent locking.
				return;
			}

			// Set a short-lived lock (5 seconds should be more than enough).
			set_transient( $lock_key, true, self::SYNC_LOCK_TIMEOUT );

			try {
				// Get the CCT item handler.
				$handler = WP_MCP_AI_JetEngine_Assistants_CCT::get_item_handler();

				if ( ! $handler ) {
					return;
				}

				// Validate handler has required methods.
				if ( ! method_exists( $handler, 'update_item' ) ) {
					return;
				}

				// Get the full assistant configuration.
				$config = self::get_assistant_configuration( $post_id );

				// Map CPT data to CCT fields.
				$cct_data = array(
					'title'         => $post->post_title,
					'description'   => $post->post_content,
					'provider'      => isset( $config['provider'] ) ? $config['provider'] : '',
					'model'         => isset( $config['model'] ) ? $config['model'] : '',
					'system_prompt' => isset( $config['system_prompt'] ) ? $config['system_prompt'] : '',
					'temperature'   => isset( $config['temperature'] ) ? $config['temperature'] : null,
					'tools'         => isset( $config['tools'] ) && is_array( $config['tools'] ) ? wp_json_encode( $config['tools'] ) : '[]',
				);

				// Remove null temperature to avoid type issues.
				if ( null === $cct_data['temperature'] ) {
					unset( $cct_data['temperature'] );
				}

				// Check if a CCT item already exists for this CPT post ID.
				// We use a meta field or custom strategy to link CPT ID to CCT item ID.
				$cct_item_id = get_post_meta( $post_id, '_wp_mcp_ai_cct_item_id', true );

				if ( $cct_item_id ) {
					// Update existing CCT item.
					$cct_data['_ID'] = absint( $cct_item_id );
					$result          = $handler->update_item( $cct_data );

					if ( ! $result ) {
						// If update failed, the item might have been deleted. Clear the link and create new.
						delete_post_meta( $post_id, '_wp_mcp_ai_cct_item_id' );
						$cct_item_id = 0;
					}
				}

				if ( ! $cct_item_id ) {
					// Create new CCT item.
					$new_item_id = $handler->update_item( $cct_data );

					if ( $new_item_id ) {
						// Store the link between CPT post ID and CCT item ID.
						update_post_meta( $post_id, '_wp_mcp_ai_cct_item_id', $new_item_id );
					}
				}
			} catch ( Exception $e ) {
				// Log error but don't block the save process.
				if ( function_exists( 'error_log' ) ) {
					error_log( 'WP MCP AI: CCT sync failed for post ' . $post_id . ': ' . $e->getMessage() );
				}
			} finally {
				// Always release the lock.
				delete_transient( $lock_key );
			}
		}

		/**
		 * Build system prompt from primary roles.
		 *
		 * Programmatically constructs a system prompt by combining role descriptions
		 * and knowledge bases from assigned profession posts.
		 *
		 * @param array $primary_roles Array of profession post IDs.
		 * @return string The constructed system prompt.
		 */
		public static function build_prompt_from_primary_roles( $primary_roles ) {
			if ( ! is_array( $primary_roles ) || empty( $primary_roles ) ) {
				return '';
			}

			$prompt_parts = array();

			// Add each role's instructions.
			foreach ( $primary_roles as $role_id ) {
				$role_id = absint( $role_id );
				if ( $role_id <= 0 || 'mcp_ai_profession' !== get_post_type( $role_id ) ) {
					continue;
				}

				$profession = get_post( $role_id );
				if ( ! $profession ) {
					continue;
				}

				// Get role description.
				$role_description = get_post_meta( $role_id, '_wp_mcp_ai_profession_role_description', true );
				if ( ! empty( $role_description ) ) {
					$prompt_parts[] = sprintf(
						"## Role: %s\n\n%s",
						$profession->post_title,
						$role_description
					);
				}

				// Get knowledge base.
				$knowledge_base = get_post_meta( $role_id, '_wp_mcp_ai_profession_knowledge_base', true );
				if ( ! empty( $knowledge_base ) ) {
					$prompt_parts[] = sprintf(
						"### Knowledge Base for %s\n\n%s",
						$profession->post_title,
						$knowledge_base
					);
				}

				// Get expertise areas.
				$expertise = get_post_meta( $role_id, '_wp_mcp_ai_profession_expertise', true );
				if ( is_array( $expertise ) && ! empty( $expertise ) ) {
					$expertise_list = implode( "\n- ", $expertise );
					$prompt_parts[] = sprintf(
						"### Expertise Areas for %s\n\n- %s",
						$profession->post_title,
						$expertise_list
					);
				}

				// Get warnings.
				$warnings = get_post_meta( $role_id, '_wp_mcp_ai_profession_warnings', true );
				if ( is_array( $warnings ) && ! empty( $warnings ) ) {
					$warnings_list  = implode( "\n- ", $warnings );
					$prompt_parts[] = sprintf(
						"### Important Warnings for %s\n\n- %s",
						$profession->post_title,
						$warnings_list
					);
				}
			}

			if ( empty( $prompt_parts ) ) {
				return '';
			}

			// Combine all parts with separators.
			$prompt  = "# Your Roles and Capabilities\n\n";
			$prompt .= "You are an AI assistant with the following professional roles:\n\n";
			$prompt .= implode( "\n\n---\n\n", $prompt_parts );

			return $prompt;
		}

		/**
		 * Retrieve the configuration for a specific assistant.
		 *
		 * @param int $assistant_id Assistant post ID.
		 * @return array
		 */
		public static function get_assistant_configuration( $assistant_id ) {
			$assistant_id = absint( $assistant_id );
			if ( ! $assistant_id ) {
				return array();
			}

			$config = array(
				'tools'                      => get_post_meta( $assistant_id, self::META_TOOLS, true ),
				'provider'                   => get_post_meta( $assistant_id, self::META_PROVIDER, true ),
				'model'                      => get_post_meta( $assistant_id, self::META_MODEL, true ),
				'temperature'                => get_post_meta( $assistant_id, self::META_TEMPERATURE, true ),
				'system_prompt'              => get_post_meta( $assistant_id, self::META_SYSTEM_PROMPT, true ),
				'memory_files'               => get_post_meta( $assistant_id, self::META_MEMORY_FILES, true ),
				'vector_store_id'            => get_post_meta( $assistant_id, self::META_VECTOR_STORE_ID, true ),
				'tool_shortcuts'             => get_post_meta( $assistant_id, self::META_TOOL_SHORTCUTS, true ),
				'tool_prebuilt_shortcuts'    => get_post_meta( $assistant_id, self::META_TOOL_PREBUILT_SHORTCUTS, true ),
				'tool_role_rules'            => get_post_meta( $assistant_id, self::META_TOOL_ROLE_RULES, true ),
				'disable_prebuilt_shortcuts' => get_post_meta( $assistant_id, self::META_DISABLE_TOOL_SHORTCUTS, true ),
				'external_action_identifier' => get_post_meta( $assistant_id, self::META_EXTERNAL_ACTION_ID, true ),
				'external_action_type'       => get_post_meta( $assistant_id, self::META_EXTERNAL_ACTION_TYPE, true ),
				'required_capability'        => get_post_meta( $assistant_id, self::META_REQUIRED_CAPABILITY, true ),
			);

			if ( ! is_array( $config['tools'] ) ) {
				$config['tools'] = array();
			}

			if ( ! is_string( $config['provider'] ) ) {
				$config['provider'] = '';
			} else {
				$config['provider'] = self::sanitize_provider_meta( $config['provider'] );
			}

			if ( ! is_string( $config['model'] ) ) {
				$config['model'] = '';
			} else {
				$config['model'] = self::sanitize_model_meta( $config['model'] );
			}

			if ( ! is_numeric( $config['temperature'] ) && '' !== $config['temperature'] ) {
				$config['temperature'] = null;
			} elseif ( '' === $config['temperature'] || false === $config['temperature'] || null === $config['temperature'] ) {
				$config['temperature'] = null;
			} else {
				$config['temperature'] = floatval( $config['temperature'] );
			}

			if ( ! is_string( $config['system_prompt'] ) ) {
				$config['system_prompt'] = '';
			} else {
				$config['system_prompt'] = self::sanitize_system_prompt_meta( $config['system_prompt'] );
			}

			// Build prompt from primary roles if assigned.
			$primary_roles = get_post_meta( $assistant_id, self::META_PRIMARY_ROLES, true );
			if ( is_array( $primary_roles ) && ! empty( $primary_roles ) ) {
				$roles_prompt = self::build_prompt_from_primary_roles( $primary_roles );
				if ( ! empty( $roles_prompt ) ) {
					// Prepend roles prompt to existing system prompt.
					if ( ! empty( $config['system_prompt'] ) ) {
						$config['system_prompt'] = $roles_prompt . "\n\n---\n\n# Additional Instructions\n\n" . $config['system_prompt'];
					} else {
						$config['system_prompt'] = $roles_prompt;
					}
				}
			}

			if ( ! is_array( $config['memory_files'] ) ) {
				$config['memory_files'] = array();
			}

			$config['memory_files'] = array_values( array_filter( array_map( 'absint', $config['memory_files'] ) ) );

			if ( ! is_string( $config['vector_store_id'] ) ) {
				$config['vector_store_id'] = '';
			} else {
				$config['vector_store_id'] = sanitize_text_field( $config['vector_store_id'] );
			}

			if ( ! is_array( $config['tool_shortcuts'] ) ) {
				$config['tool_shortcuts'] = array();
			} else {
				$config['tool_shortcuts'] = self::sanitize_tool_shortcuts_meta( $config['tool_shortcuts'] );
			}

			if ( ! is_array( $config['tool_prebuilt_shortcuts'] ) ) {
				$config['tool_prebuilt_shortcuts'] = array();
			} else {
				$config['tool_prebuilt_shortcuts'] = self::sanitize_prebuilt_tool_shortcuts_meta( $config['tool_prebuilt_shortcuts'] );
			}

			if ( ! is_array( $config['tool_role_rules'] ) ) {
				$config['tool_role_rules'] = array();
			} else {
				$config['tool_role_rules'] = self::sanitize_tool_role_rules_meta( $config['tool_role_rules'] );
			}

			$config['disable_prebuilt_shortcuts'] = self::sanitize_disable_tool_shortcuts_meta( $config['disable_prebuilt_shortcuts'] );

			if ( ! is_string( $config['external_action_identifier'] ) ) {
				$config['external_action_identifier'] = '';
			} else {
				$config['external_action_identifier'] = self::sanitize_external_action_id_meta( $config['external_action_identifier'] );
			}

			if ( ! is_string( $config['external_action_type'] ) ) {
				$config['external_action_type'] = '';
			} else {
				$config['external_action_type'] = self::sanitize_external_action_type_meta( $config['external_action_type'] );
			}

			if ( ! is_string( $config['required_capability'] ) ) {
				$config['required_capability'] = '';
			} else {
				$config['required_capability'] = self::sanitize_required_capability_meta( $config['required_capability'] );
			}

			return $config;
		}

		/**
		 * Sanitize tool shortcut metadata value.
		 *
		 * @param mixed $shortcuts Raw shortcuts value.
		 * @return array
		 */
		public static function sanitize_tool_shortcuts_meta( $shortcuts ) {
			if ( ! is_array( $shortcuts ) ) {
				return array();
			}

			$registry = null;

			if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
				$registry = WP_MCP_AI_Tool_Registry::get_instance();

				if ( method_exists( $registry, 'init' ) ) {
					$registry->init();
				}
			}

			$sanitized = array();

			foreach ( $shortcuts as $shortcut ) {
				if ( ! is_array( $shortcut ) ) {
					continue;
				}

				$label = isset( $shortcut['label'] ) && is_string( $shortcut['label'] )
				? sanitize_text_field( $shortcut['label'] )
				: '';

				$payload = isset( $shortcut['payload'] ) && is_string( $shortcut['payload'] )
				? sanitize_textarea_field( $shortcut['payload'] )
				: '';

				if ( '' === $label && '' === $payload ) {
					continue;
				}

				$entry = array(
					'label'   => $label,
					'payload' => $payload,
				);

				if ( isset( $shortcut['description'] ) && is_string( $shortcut['description'] ) ) {
					$description = sanitize_textarea_field( $shortcut['description'] );
					if ( '' !== $description ) {
						$entry['description'] = $description;
					}
				}

				if ( isset( $shortcut['tool'] ) && is_string( $shortcut['tool'] ) ) {
					$tool = sanitize_key( $shortcut['tool'] );

					if ( '' !== $tool ) {
						$is_known_tool = true;

						if ( null !== $registry && method_exists( $registry, 'get_tool' ) ) {
							$is_known_tool = ( null !== $registry->get_tool( $tool ) );
						}

						if ( $is_known_tool ) {
							$entry['tool'] = $tool;
						}
					}
				}

				$sanitized[] = $entry;
			}

			return array_values( $sanitized );
		}

		/**
		 * Sanitize customized pre-built tool shortcut metadata.
		 *
		 * @param mixed $value Raw pre-built shortcut configuration.
		 * @return array
		 */
		public static function sanitize_prebuilt_tool_shortcuts_meta( $value ) {
			if ( ! is_array( $value ) ) {
				return array();
			}

			$sanitized = array();

			foreach ( $value as $key => $settings ) {
				$tool = '';

				if ( is_string( $key ) ) {
					$tool = sanitize_key( $key );
				}

				if ( '' === $tool && is_array( $settings ) && isset( $settings['tool'] ) && is_string( $settings['tool'] ) ) {
					$tool = sanitize_key( $settings['tool'] );
				}

				if ( '' === $tool || ! is_array( $settings ) ) {
					continue;
				}

				if ( isset( $settings['mode'] ) && is_string( $settings['mode'] ) ) {
					$mode = strtolower( sanitize_text_field( $settings['mode'] ) );

					if ( 'inherit' === $mode || 'default' === $mode ) {
						continue;
					}
				}

				$entry = array(
					'mode'      => 'custom',
					'shortcuts' => array(),
				);

				if ( isset( $settings['shortcuts'] ) && is_array( $settings['shortcuts'] ) ) {
					foreach ( $settings['shortcuts'] as $shortcut ) {
						if ( ! is_array( $shortcut ) ) {
							continue;
						}

						$label   = isset( $shortcut['label'] ) && is_string( $shortcut['label'] )
						? sanitize_text_field( $shortcut['label'] )
						: '';
						$payload = isset( $shortcut['payload'] ) && is_string( $shortcut['payload'] )
						? sanitize_textarea_field( $shortcut['payload'] )
						: '';

						if ( '' === $label && '' === $payload ) {
							continue;
						}

						$item = array(
							'label'   => $label,
							'payload' => $payload,
						);

						if ( isset( $shortcut['description'] ) && is_string( $shortcut['description'] ) ) {
							$description = sanitize_textarea_field( $shortcut['description'] );

							if ( '' !== $description ) {
								$item['description'] = $description;
							}
						}

						$entry['shortcuts'][] = $item;
					}
				}

				if ( empty( $entry['shortcuts'] ) ) {
					continue;
				}

				$entry['shortcuts'] = array_values( $entry['shortcuts'] );

				$sanitized[ $tool ] = $entry;
			}

			return $sanitized;
		}

		/**
		 * Sanitize tool role rule metadata values.
		 *
		 * @param mixed $rules Raw rules value.
		 * @return array
		 */
		public static function sanitize_tool_role_rules_meta( $rules ) {
			if ( is_string( $rules ) ) {
				$decoded_rules = json_decode( $rules, true );
				if ( is_array( $decoded_rules ) ) {
					$rules = $decoded_rules;
				}
			}

			if ( ! is_array( $rules ) ) {
				return array();
			}

			$registry = null;

			if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
				$registry = WP_MCP_AI_Tool_Registry::get_instance();

				if ( method_exists( $registry, 'init' ) ) {
					$registry->init();
				}
			}

			$allowed_roles = self::get_registered_role_slugs();
			$allowed_flags = self::get_allowed_tool_role_flags();

			$sanitized = array();

			foreach ( $rules as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}

				$tool_slug = isset( $rule['tool'] ) ? sanitize_key( $rule['tool'] ) : '';

				if ( '' === $tool_slug ) {
					continue;
				}

				$is_known_tool = true;

				if ( null !== $registry && method_exists( $registry, 'get_tool' ) ) {
					$is_known_tool = ( null !== $registry->get_tool( $tool_slug ) );
				}

				if ( ! $is_known_tool ) {
					continue;
				}

				$entry = array(
					'tool' => $tool_slug,
				);

				if ( isset( $rule['roles'] ) && is_array( $rule['roles'] ) ) {
					$valid_roles = array();

					foreach ( $rule['roles'] as $role ) {
						$role_slug = sanitize_key( $role );

						if ( '' === $role_slug ) {
							continue;
						}

						if ( empty( $allowed_roles ) || in_array( $role_slug, $allowed_roles, true ) ) {
							$valid_roles[] = $role_slug;
						}
					}

					if ( ! empty( $valid_roles ) ) {
						$entry['roles'] = array_values( array_unique( $valid_roles ) );
					}
				}

				if ( isset( $rule['groups'] ) ) {
					$raw_groups = $rule['groups'];

					if ( is_string( $raw_groups ) || is_numeric( $raw_groups ) ) {
						$raw_groups = array( $raw_groups );
					}

					if ( is_array( $raw_groups ) ) {
						$valid_groups = array();

						foreach ( $raw_groups as $group_id ) {
							$group_id = absint( $group_id );

							if ( $group_id > 0 ) {
								$valid_groups[] = $group_id;
							}
						}

						if ( ! empty( $valid_groups ) ) {
							$entry['groups'] = array_values( array_unique( $valid_groups ) );
						}
					}
				}

				$flags = array();

				if ( isset( $rule['flags'] ) ) {
					$raw_flags = $rule['flags'];

					if ( is_string( $raw_flags ) ) {
						$raw_flags = array( $raw_flags );
					}

					if ( is_array( $raw_flags ) ) {
						foreach ( $raw_flags as $flag ) {
							$flag_slug = sanitize_key( $flag );

							if ( '' !== $flag_slug && in_array( $flag_slug, $allowed_flags, true ) ) {
								$flags[] = $flag_slug;
							}
						}
					}
				}

				foreach ( $allowed_flags as $flag_slug ) {
					if ( isset( $rule[ $flag_slug ] ) && wp_validate_boolean( $rule[ $flag_slug ] ) ) {
						$flags[] = $flag_slug;
					}
				}

				if ( ! empty( $flags ) ) {
					$entry['flags'] = array_values( array_unique( $flags ) );
				}

				if ( empty( $entry['roles'] ) && empty( $entry['groups'] ) && empty( $entry['flags'] ) ) {
					continue;
				}

				$sanitized[] = $entry;
			}

			return array_values( $sanitized );
		}

		/**
		 * Sanitize disable tool shortcut metadata value.
		 *
		 * @param mixed $value Raw value.
		 * @return bool
		 */
		public static function sanitize_disable_tool_shortcuts_meta( $value ) {
			if ( function_exists( 'rest_sanitize_boolean' ) ) {
				return rest_sanitize_boolean( $value );
			}

			if ( is_string( $value ) ) {
				$value = strtolower( $value );

				if ( in_array( $value, array( 'false', '0', '' ), true ) ) {
					return false;
				}
			}

			return (bool) $value;
		}

		/**
		 * Retrieve the allowlist of tool role rule flags.
		 *
		 * @return array
		 */
		protected static function get_allowed_tool_role_flags() {
			$default_flags = array(
				'allow_authenticated',
				'allow_guests',
				'allow_all_roles',
			);

			$flags = apply_filters( 'wp_mcp_ai_tool_role_rule_flags', $default_flags );

			if ( ! is_array( $flags ) ) {
				return array();
			}

			$flags = array_map( 'sanitize_key', $flags );
			$flags = array_filter(
				$flags,
				static function ( $flag ) {
					return '' !== $flag;
				}
			);

			return array_values( array_unique( $flags ) );
		}

		/**
		 * Retrieve the registered WordPress role slugs.
		 *
		 * @return array
		 */
		protected static function get_registered_role_slugs() {
			static $role_slugs = null;

			if ( null !== $role_slugs ) {
				return $role_slugs;
			}

			$role_slugs = array();

			if ( function_exists( 'wp_roles' ) ) {
				$wp_roles = wp_roles();

				if ( $wp_roles && is_a( $wp_roles, 'WP_Roles' ) && isset( $wp_roles->roles ) && is_array( $wp_roles->roles ) ) {
					$role_slugs = array_keys( $wp_roles->roles );
				}
			}

			if ( ! empty( $role_slugs ) ) {
				$role_slugs = array_map( 'sanitize_key', $role_slugs );
				$role_slugs = array_filter(
					$role_slugs,
					static function ( $role ) {
						return '' !== $role;
					}
				);
				$role_slugs = array_values( array_unique( $role_slugs ) );
			}

			return $role_slugs;
		}

		/**
		 * Render the mesh compute routing configuration meta box.
		 *
		 * @param WP_Post $post Post object.
		 */
		public function render_mesh_routing_meta_box( $post ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				echo '<p>' . esc_html__( 'You do not have permission to configure mesh routing.', 'wp-mcp-ai' ) . '</p>';
				return;
			}

			$hub_config = WP_MCP_AI_Mesh_Router::get_hub_config( $post->ID );
			$settings   = WP_MCP_AI_Admin_Settings::get_settings();
			$peer_sites = isset( $settings['mesh_peer_sites'] ) && is_array( $settings['mesh_peer_sites'] )
				? $settings['mesh_peer_sites']
				: array();

			wp_nonce_field( 'wp_mcp_ai_save_mesh_routing', 'wp_mcp_ai_save_mesh_routing_nonce' );

			?>
			<div class="wp-mcp-ai-mesh-routing-config">
				<p class="description">
					<?php esc_html_e( 'Configure intelligent routing for this assistant. The system can use AI to automatically select optimal compute resources - either mesh peer sites, different AI providers (OpenAI, Gemini, Ollama), or both.', 'wp-mcp-ai' ); ?>
				</p>

				<h3><?php esc_html_e( 'Routing Strategy', 'wp-mcp-ai' ); ?></h3>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="wp-mcp-ai-routing-strategy"><?php esc_html_e( 'Strategy', 'wp-mcp-ai' ); ?></label>
						</th>
						<td>
							<select name="wp_mcp_ai_mesh_routing[routing_strategy]" id="wp-mcp-ai-routing-strategy" class="regular-text">
								<option value="ai_optimized" <?php selected( $hub_config['routing_strategy'], 'ai_optimized' ); ?>>
									<?php esc_html_e( 'AI Optimized (Recommended) - Intelligently route based on load, complexity, and response times', 'wp-mcp-ai' ); ?>
								</option>
								<option value="round_robin" <?php selected( $hub_config['routing_strategy'], 'round_robin' ); ?>>
									<?php esc_html_e( 'Round Robin - Distribute requests evenly across peers', 'wp-mcp-ai' ); ?>
								</option>
								<option value="least_loaded" <?php selected( $hub_config['routing_strategy'], 'least_loaded' ); ?>>
									<?php esc_html_e( 'Least Loaded - Route to peer with lowest current load', 'wp-mcp-ai' ); ?>
								</option>
								<option value="preferred_with_fallback" <?php selected( $hub_config['routing_strategy'], 'preferred_with_fallback' ); ?>>
									<?php esc_html_e( 'Preferred with Fallback - Try preferred peers first, then fallback', 'wp-mcp-ai' ); ?>
								</option>
							</select>
							<p class="description">
								<?php esc_html_e( 'AI Optimized works even with a single site by routing between multiple providers (OpenAI, Gemini, Ollama) based on task complexity, rate limits, and cost.', 'wp-mcp-ai' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php if ( ! empty( $peer_sites ) ) : ?>
				<h3><?php esc_html_e( 'Mesh Peer Configuration', 'wp-mcp-ai' ); ?></h3>
				<table class="form-table">
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Compute Hubs', 'wp-mcp-ai' ); ?>
						</th>
						<td>
							<p class="description">
								<?php esc_html_e( 'Designate which peer sites are "compute hubs" with larger models or more capacity. The AI router will prefer these for complex tasks.', 'wp-mcp-ai' ); ?>
							</p>
							<?php
							$compute_hubs = isset( $hub_config['compute_hubs'] ) ? $hub_config['compute_hubs'] : array();
							foreach ( $peer_sites as $peer ) {
								$peer_name = isset( $peer['name'] ) ? $peer['name'] : '';
								if ( empty( $peer_name ) ) {
									continue;
								}
								$checked = in_array( $peer_name, $compute_hubs, true );
								?>
								<label style="display: block; margin: 5px 0;">
									<input type="checkbox" name="wp_mcp_ai_mesh_routing[compute_hubs][]" value="<?php echo esc_attr( $peer_name ); ?>" <?php checked( $checked ); ?> />
									<?php echo esc_html( $peer_name ); ?>
								</label>
								<?php
							}
							?>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<?php esc_html_e( 'Preferred Peers', 'wp-mcp-ai' ); ?>
						</th>
						<td>
							<p class="description">
								<?php esc_html_e( 'Select preferred peers in order of priority. Used when routing strategy is "Preferred with Fallback".', 'wp-mcp-ai' ); ?>
							</p>
							<div id="wp-mcp-ai-preferred-peers-list">
								<?php
								$preferred_peers = isset( $hub_config['preferred_peers'] ) ? $hub_config['preferred_peers'] : array();
								$peer_index      = 0;
								foreach ( $preferred_peers as $preferred ) {
									?>
									<div class="wp-mcp-ai-preferred-peer-row" style="margin-bottom: 10px;">
										<select name="wp_mcp_ai_mesh_routing[preferred_peers][]" class="regular-text">
											<option value=""><?php esc_html_e( '-- Select Peer --', 'wp-mcp-ai' ); ?></option>
											<?php
											foreach ( $peer_sites as $peer ) {
												$peer_name = isset( $peer['name'] ) ? $peer['name'] : '';
												if ( empty( $peer_name ) ) {
													continue;
												}
												?>
												<option value="<?php echo esc_attr( $peer_name ); ?>" <?php selected( $preferred, $peer_name ); ?>>
													<?php echo esc_html( $peer_name ); ?>
												</option>
												<?php
											}
											?>
										</select>
										<button type="button" class="button wp-mcp-ai-remove-preferred-peer"><?php esc_html_e( 'Remove', 'wp-mcp-ai' ); ?></button>
									</div>
									<?php
									++$peer_index;
								}
								?>
							</div>
							<button type="button" class="button" id="wp-mcp-ai-add-preferred-peer"><?php esc_html_e( 'Add Preferred Peer', 'wp-mcp-ai' ); ?></button>
						</td>
					</tr>
				</table>
				<?php else : ?>
				<div class="notice notice-info inline">
					<p>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: Settings URL */
								__( 'No mesh peer sites configured. <a href="%s">Configure mesh peers</a> to enable distributed compute routing, or use AI routing with multiple providers on this site.', 'wp-mcp-ai' ),
								admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools' )
							)
						);
						?>
					</p>
					<p>
						<?php esc_html_e( 'Even without mesh peers, AI Optimized routing can intelligently balance load across OpenAI, Gemini, and Ollama based on task complexity and rate limits.', 'wp-mcp-ai' ); ?>
					</p>
				</div>
				<?php endif; ?>

				<h3><?php esc_html_e( 'Retry & Failover', 'wp-mcp-ai' ); ?></h3>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="wp-mcp-ai-enable-retry"><?php esc_html_e( 'Enable Retry', 'wp-mcp-ai' ); ?></label>
						</th>
						<td>
							<label>
								<input type="checkbox" name="wp_mcp_ai_mesh_routing[enable_retry]" id="wp-mcp-ai-enable-retry" value="1" <?php checked( $hub_config['enable_retry'] ); ?> />
								<?php esc_html_e( 'Automatically retry failed requests with different peers or providers', 'wp-mcp-ai' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'When enabled, failed requests will automatically be retried with alternative peers or AI providers for resilience.', 'wp-mcp-ai' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="wp-mcp-ai-max-retries"><?php esc_html_e( 'Max Retries', 'wp-mcp-ai' ); ?></label>
						</th>
						<td>
							<input type="number" name="wp_mcp_ai_mesh_routing[max_retries]" id="wp-mcp-ai-max-retries" value="<?php echo esc_attr( $hub_config['max_retries'] ); ?>" min="1" max="10" class="small-text" />
							<p class="description">
								<?php esc_html_e( 'Maximum number of retry attempts (1-10).', 'wp-mcp-ai' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<script type="text/javascript">
				jQuery(document).ready(function($) {
					var peerOptions = <?php echo wp_json_encode( array_column( $peer_sites, 'name' ) ); ?>;

					$('#wp-mcp-ai-add-preferred-peer').on('click', function() {
						var optionsHtml = '<option value=""><?php echo esc_js( __( '-- Select Peer --', 'wp-mcp-ai' ) ); ?></option>';
						peerOptions.forEach(function(peerName) {
							optionsHtml += '<option value="' + peerName + '">' + peerName + '</option>';
						});

						var newRow = $('<div class="wp-mcp-ai-preferred-peer-row" style="margin-bottom: 10px;">' +
							'<select name="wp_mcp_ai_mesh_routing[preferred_peers][]" class="regular-text">' +
							optionsHtml +
							'</select> ' +
							'<button type="button" class="button wp-mcp-ai-remove-preferred-peer"><?php echo esc_js( __( 'Remove', 'wp-mcp-ai' ) ); ?></button>' +
							'</div>');
						$('#wp-mcp-ai-preferred-peers-list').append(newRow);
					});

					$(document).on('click', '.wp-mcp-ai-remove-preferred-peer', function() {
						$(this).closest('.wp-mcp-ai-preferred-peer-row').remove();
					});
				});
				</script>
			</div>
			<?php
		}
	}
}
