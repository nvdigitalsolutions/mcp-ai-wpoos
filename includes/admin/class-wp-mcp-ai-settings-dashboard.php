<?php
/**
 * Settings Dashboard Controller for NV oOS
 *
 * Manages the modern tabbed settings interface.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Settings_Dashboard' ) ) {
	/**
	 * Main controller for the settings dashboard.
	 */
	class WP_MCP_AI_Settings_Dashboard {
		const PAGE_SLUG = 'wp-mcp-ai-dashboard';

		/**
		 * AJAX handlers component.
		 *
		 * @var WP_MCP_AI_Admin_AJAX_Handlers
		 */
		private $ajax_handlers;

		/**
		 * Constructor.
		 *
		 * @param WP_MCP_AI_Admin_AJAX_Handlers|null $ajax_handlers Optional. AJAX handlers instance for dependency injection.
		 */
		public function __construct( $ajax_handlers = null ) {
			// Initialize AJAX handlers using dependency injection or create instance.
			$this->ajax_handlers = $ajax_handlers ?? wp_mcp_ai_container()->get( 'admin.ajax_handlers' );

			add_action( 'admin_menu', array( $this, 'register_menu' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_action( 'admin_post_wp_mcp_ai_save_settings', array( $this, 'handle_save_settings' ) );

			// Hide WordPress admin footer on the settings dashboard to prevent overlay issues.
			add_filter( 'admin_footer_text', array( $this, 'hide_admin_footer_text' ) );
			add_filter( 'update_footer', array( $this, 'hide_update_footer_text' ), 999 );

			// Register AJAX handlers.
			add_action( 'wp_ajax_wp_mcp_ai_test_ollama_connection', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_fetch_ollama_models', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_test_lm_studio_connection', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_fetch_lm_studio_models', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_fetch_cloudways_data', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_test_cloudflare_connection', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_test_brave_search_connection', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_test_mubert_connection', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_reset_user_token_usage', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_reset_all_token_usage', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_save_tool_limits', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_save_tool_settings', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_apply_orchestration_preset', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_export_token_usage_csv', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_bulk_assign_tier', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_apply_all_recommendations', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_apply_preset', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_get_usage_trend', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_get_tier_distribution', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_get_tool_breakdown', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_get_provider_distribution', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_get_model_distribution', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_update_chart_period', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_refresh_chart', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_toggle_tool', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_reseed_professions', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_reseed_teams', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_seed_orchestration', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_migrate_gemini_costs', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_regenerate_playbook', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_sync_all_playbooks', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_delete_old_playbooks', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_get_models_for_provider', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_save_model_config', array( $this->ajax_handlers, 'handle_save_model_config' ) );
		}

		/**
		 * Register the settings page in WordPress admin menu.
		 */
		public function register_menu() {
			add_menu_page(
				__( 'NV oOS Settings', 'mcp-ai-wpoos' ),
				__( 'NV oOS', 'mcp-ai-wpoos' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_dashboard' ),
				'dashicons-format-chat',
				30
			);

			// Remove the auto-generated submenu item (has same title as top-level menu).
			remove_submenu_page( self::PAGE_SLUG, self::PAGE_SLUG );

			// Add submenu item for General Settings with proper label.
			add_submenu_page(
				self::PAGE_SLUG,
				__( 'General Settings', 'mcp-ai-wpoos' ),
				__( 'General Settings', 'mcp-ai-wpoos' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_dashboard' )
			);
		}

		/**
		 * Register settings with WordPress.
		 */
		public function register_settings() {
			register_setting(
				'wp_mcp_ai_settings_group',
				WP_MCP_AI_Admin_Settings::OPTION_NAME,
				array(
					'type'              => 'array',
					'sanitize_callback' => array( $this, 'sanitize_settings' ),
				)
			);
		}

		/**
		 * Sanitize settings before saving.
		 *
		 * @param array  $input Raw settings input.
		 * @param string $active_tab Optional. The active tab to process. If not provided, processes all tabs.
		 * @param array  $active_subtabs Optional. Map of section IDs to their active subtabs.
		 * @return array Sanitized settings.
		 */
		public function sanitize_settings( $input, $active_tab = '', $active_subtabs = array() ) {
			$sanitized = array();

			// Get sections to process - either from a specific tab or all sections.
			if ( ! empty( $active_tab ) ) {
				$sections = WP_MCP_AI_Settings_Registry::get_sections( $active_tab );
			} else {
				$sections = WP_MCP_AI_Settings_Registry::get_sections();
			}

			foreach ( $sections as $section ) {
				// P0 FIX #1 & #2: Pass section-specific subtab and whether this is the active tab.
				$section_subtab = isset( $active_subtabs[ $section->get_id() ] ) ? $active_subtabs[ $section->get_id() ] : null;
				$is_active_tab  = ( $section->get_tab() === $active_tab );
				
				// Pass both subtab and active status to section sanitize method.
				$section_input = $section->sanitize( $input, $section_subtab, $is_active_tab );
				$validated     = $section->validate( $section_input );

				if ( is_wp_error( $validated ) ) {
					add_settings_error(
						'wp_mcp_ai_settings',
						$section->get_id(),
						$validated->get_error_message(),
						'error'
					);
					continue;
				}

				$sanitized = array_merge( $sanitized, $section_input );
			}

			// Clear settings cache.
			WP_MCP_AI_Admin_Settings::reset_settings_cache();

			return $sanitized;
		}

		/**
		 * Handle settings save via admin_post.
		 */
		public function handle_save_settings() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-ai-wpoos' ) );
			}

			check_admin_referer( 'wp_mcp_ai_save_settings' );

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array passed to sanitize_settings() method below.
			$posted_settings = isset( $_POST['wp_mcp_ai_settings'] ) ? wp_unslash( $_POST['wp_mcp_ai_settings'] ) : array();
			$active_tab      = isset( $_POST['active_tab'] ) ? sanitize_key( $_POST['active_tab'] ) : '';
			$active_view     = isset( $_POST['view'] ) ? sanitize_key( $_POST['view'] ) : '';

			// P0 FIX #1: Collect ALL subtabs from all sections on the tab.
			// Multiple sections on same tab may have subtabs, so we collect all subtab_* fields.
			// Previous code broke after finding first subtab, causing data loss in other sections.
			$active_subtabs = array();
			foreach ( $_POST as $key => $value ) {
				if ( preg_match( '/^subtab_([a-z_]+)$/i', $key, $matches ) && ! empty( $value ) ) {
					$section_id = $matches[1];
					$active_subtabs[ $section_id ] = sanitize_key( $value );
				}
			}

			// Fallback to legacy 'subtab' and 'connection' fields for backward compatibility.
			// Only use if no section-specific subtabs were found.
			if ( empty( $active_subtabs ) ) {
				if ( isset( $_POST['subtab'] ) && ! empty( $_POST['subtab'] ) ) {
					$active_subtabs['_legacy'] = sanitize_key( $_POST['subtab'] );
				} elseif ( isset( $_POST['connection'] ) && ! empty( $_POST['connection'] ) ) {
					$active_subtabs['_legacy'] = sanitize_key( $_POST['connection'] );
				}
			}

			// Check if logging is enabled for diagnostic purposes.
			$existing_for_logging = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
			$enable_logging       = ! empty( $existing_for_logging['enable_logging'] ) || ! empty( $existing_for_logging['enable_extended_logging'] );

			// Log save attempt for debugging (only if logging enabled).
			if ( $enable_logging ) {
				error_log(
					sprintf(
						'[NV oOS Settings] Save attempt - Tab: %s, Subtabs: %s, Posted fields: %d, Posted keys: %s',
						$active_tab,
						wp_json_encode( $active_subtabs ),
						count( $posted_settings ),
						implode( ', ', array_keys( $posted_settings ) )
					)
				);
			}

			// Only sanitize settings from the active tab to avoid clearing checkboxes from other tabs.
			// Pass active subtabs so each section can find its specific subtab.
			$sanitized_new = $this->sanitize_settings( $posted_settings, $active_tab, $active_subtabs );

			// Log sanitization results.
			if ( $enable_logging ) {
				error_log(
					sprintf(
						'[NV oOS Settings] After sanitization - Sanitized fields: %d, Sanitized keys: %s',
						count( $sanitized_new ),
						implode( ', ', array_keys( $sanitized_new ) )
					)
				);
			}

			// Merge with existing settings to avoid wiping unrelated fields.
			// This is critical for display-only sections (like Overview) that have no editable fields,.
			// and for preserving settings from other tabs.
			$existing_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

			// Log critical provider keys BEFORE merge to help diagnose data loss issues.
			if ( $enable_logging ) {
				$provider_keys = array( 'openai_api_key', 'gemini_api_key', 'ollama_endpoint_url', 'lm_studio_endpoint_url' );
				$existing_providers = array();
				$sanitized_providers = array();
				foreach ( $provider_keys as $key ) {
					if ( isset( $existing_settings[ $key ] ) && ! empty( $existing_settings[ $key ] ) ) {
						$existing_providers[ $key ] = '(exists)';
					}
					if ( isset( $sanitized_new[ $key ] ) ) {
						$sanitized_providers[ $key ] = empty( $sanitized_new[ $key ] ) ? '(EMPTY!)' : '(has value)';
					}
				}
				if ( ! empty( $existing_providers ) || ! empty( $sanitized_providers ) ) {
					error_log(
						sprintf(
							'[NV oOS Settings] Provider keys - Existing: %s, Sanitized: %s',
							empty( $existing_providers ) ? 'none' : wp_json_encode( $existing_providers ),
							empty( $sanitized_providers ) ? 'none' : wp_json_encode( $sanitized_providers )
						)
					);
				}
			}

			// CRITICAL FIX: Filter out empty sensitive keys from sanitized data to prevent accidental deletion.
			// This protects against bugs where subtab sanitization might incorrectly include empty sensitive fields.
			// Sensitive keys should only be cleared when the user is actually on the relevant tab/subtab.
			$sensitive_keys = $this->get_sensitive_keys();

			foreach ( $sensitive_keys as $key ) {
				// If a sensitive key is present in sanitized data but is empty/null,
				// remove it to prevent overwriting existing values during merge.
				// BUT allow false values (for checkboxes) and 0 values.
				if ( isset( $sanitized_new[ $key ] ) && '' === $sanitized_new[ $key ] ) {
					if ( $enable_logging ) {
						error_log(
							sprintf(
								'[NV oOS Settings] CRITICAL: Removing empty %s from sanitized data to prevent data loss (tab=%s)',
								$key,
								$active_tab
							)
						);
					}
					unset( $sanitized_new[ $key ] );
				}
			}

			// PATTERN-BASED PROTECTION: Also catch any keys that follow sensitive naming patterns.
			// This provides additional protection for keys that might be added in the future.
			$sensitive_patterns = $this->get_sensitive_key_patterns();

			foreach ( $sanitized_new as $key => $value ) {
				// Skip if already processed or not an empty string.
				if ( ! isset( $sanitized_new[ $key ] ) || '' !== $value ) {
					continue;
				}

				// Check if key matches any sensitive pattern.
				foreach ( $sensitive_patterns as $pattern ) {
					if ( preg_match( $pattern, $key ) ) {
						// Check if existing value exists and is not empty.
						if ( isset( $existing_settings[ $key ] ) && '' !== $existing_settings[ $key ] ) {
							if ( $enable_logging ) {
								error_log(
									sprintf(
										'[NV oOS Settings] PATTERN-BASED PROTECTION: Removing empty %s (matched pattern %s) to prevent data loss (tab=%s)',
										$key,
										$pattern,
										$active_tab
									)
								);
							}
							unset( $sanitized_new[ $key ] );
							break; // Stop checking other patterns for this key.
						}
					}
				}
			}

			// ADDITIONAL PROTECTION: Prevent any empty string from overwriting an existing non-empty value.
			// This is a safety net for cases where subtab logic might not properly isolate fields.
			// Exception: We allow intentional clearing when the active tab matches the setting's tab.
			foreach ( $sanitized_new as $key => $value ) {
				// Skip if value is not an empty string (empty arrays, false, 0 are allowed).
				if ( '' !== $value ) {
					continue;
				}

				// If there's an existing non-empty value, don't overwrite it with empty string.
				if ( isset( $existing_settings[ $key ] ) && '' !== $existing_settings[ $key ] ) {
					// Allow clearing values only when we're on the relevant tab.
					// This prevents cross-tab pollution while allowing intentional field clearing.
					$setting_belongs_to_active_tab = $this->is_setting_in_tab( $key, $active_tab );
					
					if ( ! $setting_belongs_to_active_tab ) {
						if ( $enable_logging ) {
							error_log(
								sprintf(
									'[NV oOS Settings] PROTECTION: Preventing empty string for %s from overwriting existing value (not in active tab %s)',
									$key,
									$active_tab
								)
							);
						}
						unset( $sanitized_new[ $key ] );
					}
				}
			}

			$merged_settings   = array_merge( $existing_settings, $sanitized_new );

			// Save to database and log result.
			$update_result = update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $merged_settings );

			if ( $enable_logging ) {
				error_log(
					sprintf(
						'[NV oOS Settings] Database update - Result: %s, Existing fields: %d, Merged fields: %d',
						$update_result ? 'SUCCESS' : 'UNCHANGED',
						count( $existing_settings ),
						count( $merged_settings )
					)
				);
			}

			// Check if media toolkit was just enabled and seed presets if needed.
			$was_toolkit_disabled = empty( $existing_settings['enable_media_toolkit'] );
			$is_toolkit_enabled   = ! empty( $merged_settings['enable_media_toolkit'] );
			if ( $was_toolkit_disabled && $is_toolkit_enabled ) {
				// Media toolkit was just enabled, seed the template presets.
				if ( class_exists( 'WP_MCP_AI_Media_Template_Presets' ) ) {
					WP_MCP_AI_Media_Template_Presets::seed_presets();

					if ( $enable_logging ) {
						error_log( '[NV oOS Settings] Media toolkit enabled - triggered template preset seeding' );
					}
				}
			}

			// P0 FIX #3: Clear caches when settings are updated (comprehensive).
			$this->invalidate_tab_caches( $active_tab, $merged_settings );

			// Redirect back to the same tab that was being edited.
			$redirect_args = array(
				'page'    => self::PAGE_SLUG,
				'updated' => 'true',
			);

			if ( ! empty( $active_tab ) ) {
				$redirect_args['tab'] = $active_tab;
			}

			// Preserve subtab parameter for sections with sub-navigation.
			// Use legacy subtab if available, or the first subtab from the array.
			if ( ! empty( $active_subtabs ) ) {
				if ( isset( $active_subtabs['_legacy'] ) ) {
					$redirect_args['subtab'] = $active_subtabs['_legacy'];
				} else {
					// Use the first subtab for redirect (maintains backward compatibility).
					$redirect_args['subtab'] = reset( $active_subtabs );
				}
			}

			// Preserve view parameter for sections with view-based navigation (e.g., Orchestration, Token Manager).
			if ( ! empty( $active_view ) ) {
				$redirect_args['view'] = $active_view;
			}

			wp_safe_redirect(
				add_query_arg(
					$redirect_args,
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		/**
		 * Get the appropriate asset file (minified or unminified).
		 *
		 * Uses minified version in production, unminified when SCRIPT_DEBUG is enabled.
		 * Falls back to unminified if minified doesn't exist.
		 *
		 * @param string $file_path Relative path to the asset file (e.g., 'assets/js/settings-dashboard.js').
		 * @return array Array with 'url', 'path', and 'version' keys.
		 */
		private function get_asset_file( $file_path ) {
			$use_minified = ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG );
			$extension    = pathinfo( $file_path, PATHINFO_EXTENSION );
			$base_path    = preg_replace( '/\.' . $extension . '$/', '', $file_path );

			// Try minified version first if not in debug mode.
			if ( $use_minified ) {
				$minified_path = $base_path . '.min.' . $extension;
				$full_path     = WP_MCP_AI_PATH . $minified_path;

				if ( file_exists( $full_path ) ) {
					return array(
						'url'     => WP_MCP_AI_URL . $minified_path,
						'path'    => $full_path,
						'version' => filemtime( $full_path ),
					);
				}
			}

			// Fall back to unminified version.
			$full_path = WP_MCP_AI_PATH . $file_path;
			return array(
				'url'     => WP_MCP_AI_URL . $file_path,
				'path'    => $full_path,
				'version' => file_exists( $full_path ) ? filemtime( $full_path ) : WP_MCP_AI_VERSION,
			);
		}

		/**
		 * Enqueue CSS and JavaScript assets for the dashboard.
		 *
		 * @param string $hook Current admin page hook.
		 */
		public function enqueue_assets( $hook ) {
			if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
				return;
			}

			// Get asset files (automatically uses minified in production, unminified in debug mode).
			$responsive_css = $this->get_asset_file( 'assets/css/admin-responsive-utilities.css' );
			$dashboard_css  = $this->get_asset_file( 'assets/css/settings-dashboard.css' );
			$ajax_error_js  = $this->get_asset_file( 'assets/js/ajax-error-service.js' );
			$dashboard_js   = $this->get_asset_file( 'assets/js/settings-dashboard.js' );

			// Enqueue responsive utilities first (base styles).
			wp_enqueue_style(
				'wp-mcp-ai-responsive-utilities',
				$responsive_css['url'],
				array(),
				$responsive_css['version']
			);

			// Enqueue dashboard styles with file modification time for cache busting.
			wp_enqueue_style(
				'wp-mcp-ai-dashboard',
				$dashboard_css['url'],
				array( 'wp-mcp-ai-responsive-utilities' ),
				$dashboard_css['version']
			);

			// Enqueue tools manager styles if on tools tab.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter check.
			if ( isset( $_GET['tab'] ) && 'tools' === $_GET['tab'] ) {
				$tools_css = $this->get_asset_file( 'assets/css/tools-manager.css' );
				wp_enqueue_style(
					'wp-mcp-ai-tools-manager',
					$tools_css['url'],
					array( 'wp-mcp-ai-dashboard' ),
					$tools_css['version']
				);
			}

			// Enqueue AJAX error service (must be loaded before other scripts) with filemtime for cache busting.
			wp_enqueue_script(
				'wp-mcp-ai-ajax-error-service',
				$ajax_error_js['url'],
				array( 'jquery' ),
				$ajax_error_js['version'],
				true
			);

			// Enqueue dashboard scripts with jQuery UI Sortable dependency and filemtime for cache busting.
			wp_enqueue_script(
				'wp-mcp-ai-dashboard',
				$dashboard_js['url'],
				array( 'jquery', 'jquery-ui-sortable', 'wp-mcp-ai-ajax-error-service' ),
				$dashboard_js['version'],
				true
			);

			// Enqueue tools manager scripts if on tools tab.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter check.
			if ( isset( $_GET['tab'] ) && 'tools' === $_GET['tab'] ) {
				$tools_js = $this->get_asset_file( 'assets/js/tools-manager.js' );
				wp_enqueue_script(
					'wp-mcp-ai-tools-manager',
					$tools_js['url'],
					array( 'jquery', 'wp-mcp-ai-ajax-error-service' ),
					$tools_js['version'],
					true
				);
			}

			// Enqueue tool orchestration scripts if on orchestration tab.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter check.
			if ( isset( $_GET['tab'] ) && 'orchestration' === $_GET['tab'] ) {
				$orchestration_js = $this->get_asset_file( 'assets/js/admin-tool-orchestration.js' );
				wp_enqueue_script(
					'wp-mcp-ai-tool-orchestration',
					$orchestration_js['url'],
					array( 'jquery', 'wp-mcp-ai-ajax-error-service', 'wp-i18n' ),
					$orchestration_js['version'],
					true
				);
				wp_set_script_translations( 'wp-mcp-ai-tool-orchestration', 'mcp-ai-wpoos' );
			}

			// Enqueue performance admin scripts if on advanced tab with performance_monitoring subtab.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter check.
			if ( isset( $_GET['tab'] ) && 'advanced' === $_GET['tab'] && isset( $_GET['subtab'] ) && 'performance_monitoring' === $_GET['subtab'] ) {
				$performance_js = $this->get_asset_file( 'assets/js/performance-admin.js' );
				wp_enqueue_script(
					'wp-mcp-ai-performance-admin',
					$performance_js['url'],
					array( 'jquery' ),
					$performance_js['version'],
					true
				);

				wp_localize_script(
					'wp-mcp-ai-performance-admin',
					'wpMcpAiPerformance',
					array(
						'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
						'nonce'       => wp_create_nonce( 'wp_mcp_ai_performance' ),
						'runningText' => __( 'Running...', 'mcp-ai-wpoos' ),
					)
				);
			}

			// Localize script with settings.
			wp_localize_script(
				'wp-mcp-ai-dashboard',
				'wpMcpAiDashboard',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'wp_mcp_ai_dashboard' ),
				)
			);

			// Add admin nonce for tools manager and connection tests.
			// Using 'wp-mcp-ai-settings' nonce to match AJAX handler expectations.
			wp_localize_script(
				'wp-mcp-ai-dashboard',
				'wpMcpAiAdmin',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'wp-mcp-ai-settings' ),
					'i18n'    => array(
						'enabled'  => __( 'Enabled', 'mcp-ai-wpoos' ),
						'disabled' => __( 'Disabled', 'mcp-ai-wpoos' ),
					),
				)
			);
		}

		/**
		 * Get the currently active tab.
		 *
		 * @return string Active tab ID.
		 */
		private function get_active_tab() {
			$tabs       = WP_MCP_AI_Settings_Registry::get_tabs();
			$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';

			if ( ! isset( $tabs[ $active_tab ] ) ) {
				$active_tab = 'general';
			}

			return $active_tab;
		}

		/**
		 * Render the settings dashboard.
		 */
		public function render_dashboard() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-ai-wpoos' ) );
			}

			$active_tab = $this->get_active_tab();
			$tabs       = WP_MCP_AI_Settings_Registry::get_tabs();
			$sections   = WP_MCP_AI_Settings_Registry::get_sections( $active_tab );

			?>
			<div class="wrap wp-mcp-ai-settings-dashboard">
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

				<?php settings_errors( 'wp_mcp_ai_settings' ); ?>

				<?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for admin notice display.
				if ( isset( $_GET['updated'] ) && 'true' === sanitize_key( wp_unslash( $_GET['updated'] ) ) ) :
					?>
					<div class="notice notice-success is-dismissible">
						<p><?php esc_html_e( 'Settings saved successfully.', 'mcp-ai-wpoos' ); ?></p>
					</div>
				<?php endif; ?>

				<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Settings tabs', 'mcp-ai-wpoos' ); ?>">
					<?php foreach ( $tabs as $tab_id => $tab ) : ?>
						<?php
						$tab_url = add_query_arg(
							array(
								'page' => self::PAGE_SLUG,
								'tab'  => $tab_id,
							),
							admin_url( 'admin.php' )
						);
						$active  = ( $tab_id === $active_tab ) ? 'nav-tab-active' : '';
						?>
						<a href="<?php echo esc_url( $tab_url ); ?>" class="nav-tab <?php echo esc_attr( $active ); ?>">
							<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
							<?php echo esc_html( $tab['title'] ); ?>
						</a>
					<?php endforeach; ?>
				</nav>

				<form id="wp-mcp-ai-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wp_mcp_ai_save_settings' ); ?>
					<input type="hidden" name="action" value="wp_mcp_ai_save_settings" />
					<input type="hidden" name="active_tab" value="<?php echo esc_attr( $active_tab ); ?>" />

					<div class="tab-content">
						<?php if ( empty( $sections ) ) : ?>
							<div class="notice notice-info">
								<p><?php esc_html_e( 'No settings available for this tab.', 'mcp-ai-wpoos' ); ?></p>
							</div>
						<?php else : ?>
							<?php foreach ( $sections as $section ) : ?>
								<?php $section->render_wrapper(); ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>

					<?php if ( ! empty( $sections ) ) : ?>
						<?php submit_button(); ?>
					<?php endif; ?>
				</form>
			</div>
			<?php
		}

		/**
		 * Get list of all sensitive keys that should be protected from accidental clearing.
		 *
		 * This method returns a comprehensive list of all API keys, credentials, tokens,
		 * and other sensitive settings that should never be cleared by empty form submissions.
		 *
		 * @return array List of sensitive setting keys.
		 */
		private function get_sensitive_keys() {
			return array(
				// OpenAI Provider.
				'openai_api_key',
				'openai_organization_id',
				// Anthropic Provider.
				'anthropic_api_key',
				// Google Gemini Provider.
				'gemini_api_key',
				// Hugging Face Provider.
				'huggingface_api_key',
				'huggingface_endpoint_url',
				'huggingface_datasets_api_token',
				// Ollama Provider (Local).
				'ollama_endpoint_url',
				// LM Studio Provider (Local).
				'lm_studio_endpoint_url',
				// Cloudflare Provider.
				'cloudflare_account_id',
				'cloudflare_api_token',
				'cloudflare_zone_id',
				// Brave Search Integration.
				'brave_search_api_key',
				// Mubert Integration.
				'mubert_api_key',
				// Google Maps Integration.
				'google_maps_api_key',
				// OAuth and Auth0 Integration.
				'auth0_domain',
				'auth0_client_id',
				'auth0_client_secret',
				'auth0_management_client_id',
				'auth0_management_client_secret',
				'oauth_google_client_id',
				'oauth_google_client_secret',
				'gmail_client_id',
				'gmail_client_secret',
				'google_drive_client_id',
				'google_drive_client_secret',
				'github_client_id',
				'github_client_secret',
				// External Service Keys.
				'cloudways_api_key',
				'cloudways_api_email',
				'cloudways_server_id',
				'cloudways_app_id',
				'crawl4ai_api_key',
				'removebg_api_key',
				'mailjet_api_key',
				'mailjet_api_secret',
				'mailjet_client_id',
				'mailjet_client_secret',
				'ita_tariff_api_key',
				'google_analytics_credentials',
				'google_analytics_credentials_json',
				'mesh_inbound_api_key',
				'quickbooks_api_key',
				'quickbooks_client_id',
				'quickbooks_client_secret',
				'meta_app_id',
				'meta_business_account_id',
				'tiktok_client_secret',
			);
		}

		/**
		 * Get list of regex patterns that match sensitive keys.
		 *
		 * This provides additional protection for keys added in the future
		 * that follow standard naming conventions.
		 *
		 * @return array List of regex patterns.
		 */
		private function get_sensitive_key_patterns() {
			return array(
				'/_api_key$/',
				'/_api_secret$/',
				'/_api_token$/',
				'/_client_id$/',
				'/_client_secret$/',
				'/_access_token$/',
				'/_refresh_token$/',
				'/_private_key$/',
				'/_credentials$/',
				'/_credentials_json$/',
			);
		}

		/**
		 * Check if a setting key belongs to fields in the specified tab.
		 *
		 * @param string $key Setting key to check.
		 * @param string $tab Tab ID to check against.
		 * @return bool True if the setting belongs to the tab, false otherwise.
		 */
		private function is_setting_in_tab( $key, $tab ) {
			if ( empty( $tab ) ) {
				return false;
			}

			$sections = WP_MCP_AI_Settings_Registry::get_sections( $tab );
			
			foreach ( $sections as $section ) {
				$fields = $section->get_fields();
				
				if ( isset( $fields[ $key ] ) ) {
					return true;
				}

				// For sections with subtabs, check all subtab fields.
				if ( method_exists( $section, 'get_subtab_groups' ) ) {
					$subtab_groups = $section->get_subtab_groups();
					foreach ( $subtab_groups as $group ) {
						if ( isset( $group['fields'] ) && in_array( $key, $group['fields'], true ) ) {
							return true;
						}
					}
				}
			}

			return false;
		}

		/**
		 * Hide the WordPress admin footer text on the NV oOS settings dashboard.
		 *
		 * @param string $footer_text The default footer text.
		 * @return string Empty string on settings dashboard, original text elsewhere.
		 */
		public function hide_admin_footer_text( $footer_text ) {
			if ( ! $this->is_settings_dashboard() ) {
				return $footer_text;
			}

			return '';
		}

		/**
		 * Hide the WordPress version footer text on the NV oOS settings dashboard.
		 *
		 * @param string $footer_text The default version footer text.
		 * @return string Empty string on settings dashboard, original text elsewhere.
		 */
		public function hide_update_footer_text( $footer_text ) {
			if ( ! $this->is_settings_dashboard() ) {
				return $footer_text;
			}

			return '';
		}

		/**
		 * Check if the current admin page is the NV oOS settings dashboard.
		 *
		 * @return bool True if on settings dashboard, false otherwise.
		 */
		protected function is_settings_dashboard() {
			if ( ! function_exists( 'get_current_screen' ) ) {
				return false;
			}

			$screen = get_current_screen();
			if ( ! $screen ) {
				return false;
			}

			// Check if we're on the settings dashboard.
			// The screen ID format is 'toplevel_page_{page_slug}'.
			return 'toplevel_page_' . self::PAGE_SLUG === $screen->id;
		}

		/**
		 * Invalidate caches based on which tab was modified.
		 *
		 * P0 FIX #3: Comprehensive cache invalidation for all tabs.
		 * Previous code only cleared orchestration caches, leaving stale data
		 * when providers, tools, or auth settings changed.
		 *
		 * @param string $active_tab The tab that was saved.
		 * @param array  $merged_settings The merged settings after save.
		 */
		private function invalidate_tab_caches( $active_tab, $merged_settings ) {
			switch ( $active_tab ) {
				case 'providers':
					// Clear provider and model caches.
					wp_cache_delete( 'wp_mcp_ai_providers' );
					wp_cache_delete( 'wp_mcp_ai_models' );
					wp_cache_delete( 'wp_mcp_ai_provider_priority' );
					do_action( 'wp_mcp_ai_providers_updated' );
					break;

				case 'tools':
					// Clear tool-related caches.
					if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
						WP_MCP_AI_Cache_Helper::invalidate_tool_caches();
					}
					wp_cache_delete( 'wp_mcp_ai_available_tools' );
					wp_cache_delete( 'wp_mcp_ai_tool_limits' );
					do_action( 'wp_mcp_ai_tools_updated' );
					break;

				case 'authentication':
					// Clear authentication caches.
					wp_cache_delete( 'wp_mcp_ai_auth_config' );
					wp_cache_delete( 'wp_mcp_ai_oauth_tokens' );
					do_action( 'wp_mcp_ai_authentication_updated' );
					break;

				case 'orchestration':
					// Clear orchestration caches (existing code).
					if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
						WP_MCP_AI_Cache_Helper::invalidate_orchestration_caches();
					}
					if ( class_exists( 'WP_MCP_AI_Orchestration_Health_Service' ) ) {
						WP_MCP_AI_Orchestration_Health_Service::clear_health_cache();
					}
					break;

				case 'advanced':
					// Clear advanced settings caches.
					if ( isset( $merged_settings['enable_logging'] ) ||
						isset( $merged_settings['enable_extended_logging'] ) ) {
						wp_cache_delete( 'wp_mcp_ai_logging_config' );
					}
					if ( isset( $merged_settings['mesh_peer_sites'] ) ) {
						wp_cache_delete( 'wp_mcp_ai_mesh_peers' );
					}
					break;

				case 'general':
					// Clear general settings cache.
					wp_cache_delete( 'wp_mcp_ai_general_config' );
					break;
			}

			// Always clear the settings cache.
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}
	}
}
