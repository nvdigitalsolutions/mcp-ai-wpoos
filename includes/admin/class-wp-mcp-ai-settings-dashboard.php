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
			add_action( 'admin_menu', array( $this, 'reorder_main_menu' ), 999 );
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

			// Settings management AJAX handlers.
			add_action( 'wp_ajax_wp_mcp_ai_export_settings', array( $this, 'handle_export_settings' ) );
			add_action( 'wp_ajax_wp_mcp_ai_import_settings', array( $this, 'handle_import_settings' ) );
			add_action( 'wp_ajax_wp_mcp_ai_clear_settings_cache', array( $this, 'handle_clear_cache' ) );
			add_action( 'wp_ajax_wp_mcp_ai_reset_settings', array( $this, 'handle_reset_settings' ) );
			add_action( 'wp_ajax_wp_mcp_ai_check_settings_health', array( $this, 'handle_check_settings_health' ) );
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
		 * Reorder submenu items to ensure proper menu order.
		 *
		 * Ensures General Settings appears before Orchestration Dashboard and Task Plans.
		 * This method reorganizes the submenu items under the main NV oOS menu to maintain
		 * a logical and consistent navigation structure.
		 *
		 * @since 2.1.0
		 * @return void
		 */
		public function reorder_main_menu() {
			global $submenu;

			// Only reorder if the main NV oOS submenu exists.
			if ( ! isset( $submenu[ self::PAGE_SLUG ] ) || ! is_array( $submenu[ self::PAGE_SLUG ] ) ) {
				return;
			}

			$main_submenu = $submenu[ self::PAGE_SLUG ];

			// Define desired order: General Settings (0), Orchestration (10), Task Plans (20).
			$ordered_items         = array();
			$general_settings_item = null;
			$orchestration_item    = null;
			$task_plans_item       = null;
			$other_items           = array();

			// Categorize menu items.
			foreach ( $main_submenu as $item ) {
				// General Settings (the main dashboard page).
				if ( isset( $item[2] ) && self::PAGE_SLUG === $item[2] ) {
					$general_settings_item = $item;
				} elseif ( isset( $item[2] ) && false !== strpos( $item[2], 'mcp-ai-orchestration' ) ) {
					// Orchestration Dashboard.
					$orchestration_item = $item;
				} elseif ( isset( $item[2] ) && false !== strpos( $item[2], 'post_type=mcp_task_plan' ) ) {
					// Task Plans CPT.
					$task_plans_item = $item;
				} else {
					// Other items.
					$other_items[] = $item;
				}
			}

			// Rebuild menu in desired order.
			if ( $general_settings_item ) {
				$ordered_items[0] = $general_settings_item;
			}
			if ( $orchestration_item ) {
				$ordered_items[10] = $orchestration_item;
			}
			if ( $task_plans_item ) {
				$ordered_items[20] = $task_plans_item;
			}

			// Add other items after.
			$position = 30;
			foreach ( $other_items as $item ) {
				$ordered_items[ $position ] = $item;
				++$position;
			}

			// Update global submenu.
			ksort( $ordered_items );
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required to reorder admin menu items.
			$submenu[ self::PAGE_SLUG ] = $ordered_items;
		}

		/**
		 * Register settings with WordPress.
		 *
		 * IMPORTANT: We do NOT register a sanitize_callback here because:
		 * 1. We manually sanitize in handle_save_settings() with proper context
		 * 2. WordPress would call the callback on EVERY update_option(), causing double-sanitization
		 * 3. The callback has no POST context during update_option(), breaking subtab protection
		 * 4. This would cause provider keys to be cleared when navigating tabs
		 *
		 * See: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues/TBD
		 */
		public function register_settings() {
			register_setting(
				'wp_mcp_ai_settings_group',
				WP_MCP_AI_Admin_Settings::OPTION_NAME,
				array(
					'type' => 'array',
				// No sanitize_callback - we handle sanitization manually in handle_save_settings().
				)
			);
		}

		/**
		 * Sanitize settings before saving.
		 *
		 * @param array  $input Raw settings input.
		 * @param string $active_tab Optional. The active tab to process. If not provided, processes all tabs.
		 * @return array Sanitized settings.
		 */
		public function sanitize_settings( $input, $active_tab = '' ) {
			$sanitized = array();

			// Get sections to process - either from a specific tab or all sections.
			if ( ! empty( $active_tab ) ) {
				$sections = WP_MCP_AI_Settings_Registry::get_sections( $active_tab );
			} else {
				$sections = WP_MCP_AI_Settings_Registry::get_sections();
			}

			foreach ( $sections as $section ) {
				$section_input = $section->sanitize( $input );
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
			$save_all_tabs   = isset( $_POST['save_all_tabs'] ) && '1' === $_POST['save_all_tabs'];

			// DEBUG: Log checkbox values in posted data.
			$existing_for_logging = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
			$enable_logging       = ! empty( $existing_for_logging['enable_logging'] ) || ! empty( $existing_for_logging['enable_extended_logging'] );
			if ( $enable_logging ) {
				$checkbox_keys     = array( 'enable_mesh', 'enable_federation', 'enable_federation_directory' );
				$posted_checkboxes = array();
				foreach ( $checkbox_keys as $key ) {
					if ( isset( $posted_settings[ $key ] ) ) {
						$posted_checkboxes[ $key ] = $posted_settings[ $key ];
					} else {
						$posted_checkboxes[ $key ] = 'NOT_IN_POST';
					}
				}
				error_log(
					sprintf(
						'[NV oOS Posted Data] Tab: %s, Checkbox values in $_POST[wp_mcp_ai_settings]: %s',
						$active_tab,
						wp_json_encode( $posted_checkboxes )
					)
				);
			}

			// Find subtab from section-specific subtab fields (subtab_sectionid format).
			// Multiple sections on same tab may have subtabs, so we check all subtab_* fields.
			// IMPORTANT: Many sections use subtabs with critical data tables:
			// - Providers tab: Each provider (OpenAI, Gemini, Ollama, etc.) has its own subtab
			// - Advanced tab: Performance, Logging, Federation, Data Management, Settings Management
			// - Integrations tab: Various third-party integrations
			// - Tools tab: Tool categories and individual tool configurations
			// The subtab value is used to determine which specific fields to sanitize during save.
			$active_subtab = '';
			foreach ( $_POST as $key => $value ) {
				if ( strpos( $key, 'subtab_' ) === 0 && ! empty( $value ) ) {
					$active_subtab = sanitize_key( $value );
					break; // Use the first subtab found.
				}
			}

			// Fallback to legacy 'subtab' field for backward compatibility.
			if ( empty( $active_subtab ) && isset( $_POST['subtab'] ) ) {
				$active_subtab = sanitize_key( $_POST['subtab'] );
			}

			// Check if logging is enabled for diagnostic purposes.
			$existing_for_logging = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
			$enable_logging       = ! empty( $existing_for_logging['enable_logging'] ) || ! empty( $existing_for_logging['enable_extended_logging'] );

			// Log save attempt for debugging (only if logging enabled).
			if ( $enable_logging ) {
				error_log(
					sprintf(
						'[NV oOS Settings] Save attempt - Tab: %s, Subtab: %s, Save all tabs: %s, Posted fields: %d, Posted keys: %s',
						$active_tab,
						$active_subtab,
						$save_all_tabs ? 'YES' : 'NO',
						count( $posted_settings ),
						implode( ', ', array_keys( $posted_settings ) )
					)
				);
			}

			// DEPRECATED: Simple Settings Saver is disabled because it's incompatible with partial forms.
			// All settings pages in this plugin use tabs/subtabs that only show partial fields.
			// The Simple Settings Saver's checkbox handling (setting all unposted checkboxes to false)
			// causes data loss when saving from tabs/subtabs. Use section-based sanitization instead.
			// See: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues/XXXX.
			$use_simple_settings_saver = false; // Force disabled.

			if ( $use_simple_settings_saver && $save_all_tabs && empty( $active_subtab ) && class_exists( 'WP_MCP_AI_Simple_Settings_Saver' ) ) {
				// Initialize field types for the Simple Settings Saver.
				WP_MCP_AI_Simple_Settings_Saver::init_field_types();

				// Use Simple Settings Saver for optimized individual field sanitization.
				$sanitized_new = WP_MCP_AI_Simple_Settings_Saver::save_settings( $posted_settings, true );

				// Log that we used the simplified saver.
				if ( $enable_logging ) {
					error_log(
						sprintf(
							'[NV oOS Settings] Using Simple Settings Saver - Sanitized fields: %d, Keys: %s',
							count( $sanitized_new ),
							implode( ', ', array_keys( $sanitized_new ) )
						)
					);
				}

				// Since Simple Settings Saver already saves to database, we skip the merge below.
				// Set flag to skip the database update step.
				$already_saved = true;
			} else {
				// Use section-based sanitization for main dashboard.
				// When save_all_tabs is true (e.g., from Simple Settings page), sanitize ALL tabs.
				// Otherwise, only sanitize the active tab to avoid clearing checkboxes from other tabs.
				//
				// CLARIFICATION FOR save_all_tabs FLAG:
				// - When save_all_tabs=1: $tab_to_sanitize is empty string ''
				// - Empty string '' in sanitize_settings() triggers ALL sections across ALL tabs (see line 139)
				// - This is safe for Simple Settings Page because it displays ALL fields from multiple tabs
				// - For regular tab-based saves, we only sanitize the active tab to preserve other tabs' data
				//
				// SUBTAB HANDLING (VERY IMPORTANT):
				// - When saving from a subtab (e.g., Providers → Gemini), only that subtab's fields are posted
				// - Section-based sanitization knows which fields belong to each subtab
				// - The merge strategy below (line 326+) preserves data from OTHER subtabs
				// - Sensitive key protection (line 329+) prevents empty values from overwriting existing keys
				// - This ensures subtabbed views with important tables save correctly without data loss.
				$tab_to_sanitize = $save_all_tabs ? '' : $active_tab;
				$sanitized_new   = $this->sanitize_settings( $posted_settings, $tab_to_sanitize );
				$already_saved   = false;

				// DEBUG: Specifically log checkbox values in sanitized_new to diagnose save issue.
				if ( $enable_logging ) {
					$checkbox_keys              = array( 'enable_mesh', 'enable_federation', 'enable_federation_directory' );
					$sanitized_checkboxes_after = array();
					foreach ( $checkbox_keys as $key ) {
						$sanitized_checkboxes_after[ $key ] = isset( $sanitized_new[ $key ] ) ?
							( $sanitized_new[ $key ] ? 'true' : 'false' ) :
							'NOT_IN_SANITIZED';
					}
					error_log(
						sprintf(
							'[NV oOS After Sanitize] Tab: %s, Subtab: %s, Checkbox values in sanitized_new: %s',
							$active_tab,
							$active_subtab,
							wp_json_encode( $sanitized_checkboxes_after )
						)
					);
				}

				// Log sanitization results.
				if ( $enable_logging ) {
					error_log(
						sprintf(
							'[NV oOS Settings] After section-based sanitization - Sanitized fields: %d, Sanitized keys: %s',
							count( $sanitized_new ),
							implode( ', ', array_keys( $sanitized_new ) )
						)
					);
				}
			}

			// Skip database operations if Simple Settings Saver already saved.
			if ( ! isset( $already_saved ) || ! $already_saved ) {
				// ========================================================================
				// CRITICAL SETTINGS PERSISTENCE FIX
				// ========================================================================
				// This section implements robust settings handling to prevent data loss:
				// 1. Cache invalidation BEFORE reading to prevent stale data
				// 2. Backup current settings before merge for rollback capability
				// 3. Atomic update with validation
				// 4. Clear all related caches (object cache, transients)
				// ========================================================================

				// Step 1: Clear ALL caches before reading existing settings.
				// This prevents race conditions and ensures we have the latest database values.
				WP_MCP_AI_Admin_Settings::reset_settings_cache();

				// Also clear any object cache entries for this option.
				wp_cache_delete( WP_MCP_AI_Admin_Settings::OPTION_NAME, 'options' );

				// Clear any transients that might cache settings.
				delete_transient( 'wp_mcp_ai_settings_cache' );

				// Step 2: Read current settings from database (bypassing all caches).
				// Use get_option() directly to ensure we get fresh data.
				$existing_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

				// Step 3: Create a backup of current settings for potential rollback.
				// Store with timestamp for auditing purposes.
				$backup_key = 'wp_mcp_ai_settings_backup_' . time();
				update_option( $backup_key, $existing_settings, false ); // No autoload for backups.

				// Clean up old backups (keep last 5).
				$this->cleanup_old_setting_backups( 5 );

				// Log critical provider keys BEFORE merge to help diagnose data loss issues.
				if ( $enable_logging ) {
					$provider_keys       = array( 'openai_api_key', 'gemini_api_key', 'ollama_endpoint_url', 'lm_studio_endpoint_url' );
					$existing_providers  = array();
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

				// ========================================================================
				// STEP 4: Enhanced Sensitive Key Protection
				// ========================================================================
				// Filter out empty provider keys from sanitized data to prevent accidental deletion.
				// This protects against bugs where subtab sanitization might incorrectly include empty provider fields.
				// Provider keys should only come from the Providers tab sections, never from General or other tabs.
				$sensitive_keys = array(
					'openai_api_key',
					'gemini_api_key',
					'anthropic_api_key',
					'huggingface_api_key',
					'ollama_endpoint_url',
					'lm_studio_endpoint_url',
					'cloudflare_account_id',
					'cloudflare_api_token',
					'brave_search_api_key',
					'mubert_api_key',
					// Add more sensitive keys from integrations.
					'gmail_client_secret',
					'gmail_refresh_token',
					'google_drive_refresh_token',
					'auth0_management_client_secret',
					'rabbitmq_password',
					'meta_app_secret',
					'meta_access_token',
					'tiktok_access_token',
					'tiktok_client_secret',
				);

				foreach ( $sensitive_keys as $key ) {
					// If a sensitive key is present in sanitized data but is empty/null,
					// remove it to prevent overwriting existing values during merge.
					if ( isset( $sanitized_new[ $key ] ) && empty( $sanitized_new[ $key ] ) ) {
						if ( $enable_logging ) {
							error_log(
								sprintf(
									'[NV oOS Settings] PROTECTION: Removing empty %s from sanitized data to prevent data loss (tab=%s, save_all=%s)',
									$key,
									$active_tab,
									$save_all_tabs ? 'YES' : 'NO'
								)
							);
						}
						unset( $sanitized_new[ $key ] );
					}
				}

				// ========================================================================
				// STEP 5: Merge Settings with Validation
				// ========================================================================
				// DEBUG: Log checkbox values before merge to diagnose persistence issue.
				if ( $enable_logging ) {
					$checkbox_keys        = array( 'enable_mesh', 'enable_federation', 'enable_federation_directory' );
					$existing_checkboxes  = array();
					$sanitized_checkboxes = array();
					foreach ( $checkbox_keys as $key ) {
						if ( isset( $existing_settings[ $key ] ) ) {
							$existing_checkboxes[ $key ] = $existing_settings[ $key ] ? 'true' : 'false';
						}
						if ( isset( $sanitized_new[ $key ] ) ) {
							$sanitized_checkboxes[ $key ] = $sanitized_new[ $key ] ? 'true' : 'false';
						}
					}
					error_log(
						sprintf(
							'[NV oOS Checkbox Merge] Existing: %s, Sanitized: %s',
							wp_json_encode( $existing_checkboxes ),
							wp_json_encode( $sanitized_checkboxes )
						)
					);
				}

				$merged_settings = array_merge( $existing_settings, $sanitized_new );

				// CRITICAL: Always log federation checkbox merge for debugging.
				$fed_keys = array( 'enable_mesh', 'enable_federation', 'enable_federation_directory' );
				$has_fed = false;
				$before_merge = array();
				$after_merge = array();
				foreach ( $fed_keys as $key ) {
					if ( isset( $existing_settings[ $key ] ) || isset( $sanitized_new[ $key ] ) || isset( $merged_settings[ $key ] ) ) {
						$has_fed = true;
						$before_merge[ $key ] = isset( $existing_settings[ $key ] ) ? var_export( $existing_settings[ $key ], true ) : 'NOT_SET';
						$from_sanitized[ $key ] = isset( $sanitized_new[ $key ] ) ? var_export( $sanitized_new[ $key ], true ) : 'NOT_SET';
						$after_merge[ $key ] = isset( $merged_settings[ $key ] ) ? var_export( $merged_settings[ $key ], true ) : 'NOT_SET';
					}
				}
				if ( $has_fed ) {
					error_log(
						sprintf(
							'[NV oOS FEDERATION DEBUG] MERGE: Before=%s, From Sanitized=%s, After=%s',
							wp_json_encode( $before_merge ),
							wp_json_encode( $from_sanitized ),
							wp_json_encode( $after_merge )
						)
					);
				}


				// ========================================================================
				// STEP 5a: Auto-generate mesh API key if needed
				// ========================================================================
				// Check if mesh networking or federation directory was just enabled and generate API key if needed.
				// This must happen BEFORE validation and save to avoid race conditions.
				$mesh_features_enabled = ! empty( $merged_settings['enable_mesh'] ) || ! empty( $merged_settings['enable_federation_directory'] );
				if ( $mesh_features_enabled && empty( $merged_settings['mesh_inbound_api_key'] ) ) {
					try {
						// Generate mesh inbound API key for peer authentication.
						$merged_settings['mesh_inbound_api_key'] = 'mesh_' . bin2hex( random_bytes( 32 ) );

						if ( $enable_logging ) {
							error_log( '[NV oOS Settings] Mesh/Federation enabled - auto-generated mesh_inbound_api_key' );
						}
					} catch ( Exception $e ) {
						// Handle random_bytes() exception gracefully.
						if ( $enable_logging ) {
							error_log(
								sprintf(
									'[NV oOS Settings] Failed to generate mesh API key: %s',
									$e->getMessage()
								)
							);
						}
						add_settings_error(
							'wp_mcp_ai_settings',
							'mesh_key_generation_failed',
							sprintf(
								/* translators: %s: Error message from exception */
								__( 'Failed to generate mesh API key due to insufficient system entropy: %s. This is typically a server configuration issue. Please ensure your server has proper random number generation available (check /dev/urandom on Linux systems) or contact your hosting provider.', 'mcp-ai-wpoos' ),
								esc_html( $e->getMessage() )
							),
							'error'
						);
					}
				}

				// Validate merged settings before saving.
				$validation_errors = $this->validate_merged_settings( $merged_settings, $existing_settings );
				if ( ! empty( $validation_errors ) ) {
					if ( $enable_logging ) {
						error_log(
							sprintf(
								'[NV oOS Settings] VALIDATION ERRORS: %s',
								implode( '; ', $validation_errors )
							)
						);
					}
					// Add settings errors for display to user.
					foreach ( $validation_errors as $error ) {
						add_settings_error( 'wp_mcp_ai_settings', 'validation_error', $error, 'error' );
					}
					// Rollback: Don't save if validation failed.
					$update_result = false;
				} else {
					// ========================================================================
					// STEP 6: Atomic Database Update
					// ========================================================================
					// Save to database with autoload=yes for performance.
					$update_result = update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $merged_settings, true );

					// CRITICAL: Always log federation checkbox save result for debugging.
					$fed_keys = array( 'enable_mesh', 'enable_federation', 'enable_federation_directory' );
					$has_fed = false;
					$saved_values = array();
					foreach ( $fed_keys as $key ) {
						if ( isset( $merged_settings[ $key ] ) ) {
							$has_fed = true;
							$saved_values[ $key ] = var_export( $merged_settings[ $key ], true );
						}
					}
					if ( $has_fed ) {
						error_log(
							sprintf(
								'[NV oOS FEDERATION DEBUG] SAVE: Result=%s, Values=%s',
								$update_result ? 'SUCCESS' : 'UNCHANGED',
								wp_json_encode( $saved_values )
							)
						);
						// Immediately read back from database to verify.
						$verified = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
						$verified_values = array();
						foreach ( $fed_keys as $key ) {
							if ( isset( $verified[ $key ] ) ) {
								$verified_values[ $key ] = var_export( $verified[ $key ], true );
							}
						}
						error_log(
							sprintf(
								'[NV oOS FEDERATION DEBUG] VERIFY: Read back from DB=%s',
								wp_json_encode( $verified_values )
							)
						);
					}


					if ( $enable_logging ) {
						error_log(
							sprintf(
								'[NV oOS Settings] Database update - Result: %s, Existing fields: %d, Merged fields: %d, Changed keys: %s',
								$update_result ? 'SUCCESS' : 'UNCHANGED',
								count( $existing_settings ),
								count( $merged_settings ),
								implode( ', ', array_keys( array_diff_assoc( $merged_settings, $existing_settings ) ) )
							)
						);
					}

					// ========================================================================
					// STEP 7: Post-Save Cache Invalidation
					// ========================================================================
					// Clear all caches again after successful save.
					WP_MCP_AI_Admin_Settings::reset_settings_cache();
					wp_cache_delete( WP_MCP_AI_Admin_Settings::OPTION_NAME, 'options' );
					delete_transient( 'wp_mcp_ai_settings_cache' );

					// Fire action hook for extensions to clear their own caches.
					do_action( 'wp_mcp_ai_settings_saved', $merged_settings, $existing_settings, $sanitized_new );
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

				// Check if Architect Agent Toolkit was just enabled and create assistant.
				$was_architect_disabled = empty( $existing_settings['enable_architect_agent_toolkit'] );
				$is_architect_enabled   = ! empty( $merged_settings['enable_architect_agent_toolkit'] );
				if ( $was_architect_disabled && $is_architect_enabled ) {
					// Architect Agent Toolkit was just enabled, create the assistant.
					if ( class_exists( 'WP_MCP_AI_Default_Assistants' ) ) {
						$result = WP_MCP_AI_Default_Assistants::install_architect_agent_assistant();

						if ( $enable_logging ) {
							if ( is_wp_error( $result ) ) {
								error_log(
									sprintf(
										'[NV oOS Settings] Architect Agent Toolkit enabled - assistant creation failed: %s',
										$result->get_error_message()
									)
								);
							} else {
								error_log(
									sprintf(
										'[NV oOS Settings] Architect Agent Toolkit enabled - assistant created (ID: %d)',
										$result
									)
								);
							}
						}
					}
				}
			} else {
				// Get the merged settings from database (Simple Settings Saver already saved).
				$merged_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

				if ( $enable_logging ) {
					error_log( '[NV oOS Settings] Skipped database update - Simple Settings Saver already saved' );
				}
			}

			// Clear caches when settings are updated.
			if ( 'orchestration' === $active_tab ) {
				// Clear orchestration-related caches using Cache Helper.
				WP_MCP_AI_Cache_Helper::invalidate_orchestration_caches();

				if ( class_exists( 'WP_MCP_AI_Orchestration_Health_Service' ) ) {
					WP_MCP_AI_Orchestration_Health_Service::clear_health_cache();
				}
			}

			// Redirect back to the same tab that was being edited.
			// Check if a custom redirect page is specified (e.g., for simple settings page).
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
			$redirect_page = isset( $_POST['redirect_page'] ) ? sanitize_key( $_POST['redirect_page'] ) : self::PAGE_SLUG;

			$redirect_args = array(
				'page'    => $redirect_page,
				'updated' => 'true',
			);

			if ( ! empty( $active_tab ) ) {
				$redirect_args['tab'] = $active_tab;
			}

			// Preserve subtab parameter for sections with sub-navigation (e.g., Authentication).
			if ( ! empty( $active_subtab ) ) {
				$redirect_args['subtab'] = $active_subtab;
			}

			// Preserve view parameter for sections with view-based navigation (e.g., Orchestration, Token Manager).
			if ( ! empty( $active_view ) ) {
				$redirect_args['view'] = $active_view;
			}

			// Add count of saved fields for user feedback.
			if ( isset( $sanitized_new ) && is_array( $sanitized_new ) ) {
				$redirect_args['saved'] = count( $sanitized_new );
			}

			// Determine redirect URL based on page type.
			if ( self::PAGE_SLUG === $redirect_page ) {
				// Main dashboard - use admin.php.
				$redirect_url = admin_url( 'admin.php' );
			} else {
				// Simple settings page - use options-general.php.
				$redirect_url = admin_url( 'options-general.php' );
			}

			wp_safe_redirect(
				add_query_arg(
					$redirect_args,
					$redirect_url
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

			// Enqueue admin settings scripts if on providers tab (for embedded model management).
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter check.
			if ( isset( $_GET['tab'] ) && 'providers' === $_GET['tab'] ) {
				$admin_settings_js = $this->get_asset_file( 'assets/js/admin-settings.js' );
				wp_enqueue_script(
					'wp-mcp-ai-admin-settings',
					$admin_settings_js['url'],
					array( 'jquery', 'jquery-ui-sortable', 'wp-mcp-ai-ajax-error-service' ),
					$admin_settings_js['version'],
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
			$tabs = WP_MCP_AI_Settings_Registry::get_tabs();
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only parameter for tab display.
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
		 * Validate merged settings before saving.
		 *
		 * Performs integrity checks to prevent corrupt or invalid data from being saved.
		 *
		 * @param array $merged_settings The settings after merging new values with existing.
		 * @param array $existing_settings The previous settings from database.
		 * @return array Array of validation error messages (empty if valid).
		 */
		private function validate_merged_settings( $merged_settings, $existing_settings ) {
			$errors = array();

			// Check 1: Ensure merged settings is an array.
			if ( ! is_array( $merged_settings ) ) {
				$errors[] = 'Merged settings must be an array.';
				return $errors; // Fatal error, return immediately.
			}

			// Check 2: Verify no critical settings were accidentally removed.
			$critical_keys = array( 'default_provider', 'default_model', 'provider_priority_list' );
			foreach ( $critical_keys as $key ) {
				if ( isset( $existing_settings[ $key ] ) && ! isset( $merged_settings[ $key ] ) ) {
					$errors[] = sprintf( 'Critical setting "%s" was removed during merge.', $key );
				}
			}

			// Check 3: Validate provider priority list structure.
			if ( isset( $merged_settings['provider_priority_list'] ) && ! is_array( $merged_settings['provider_priority_list'] ) ) {
				$errors[] = 'Provider priority list must be an array.';
			}

			// Check 4: Validate mesh peer sites structure if present.
			if ( isset( $merged_settings['mesh_peer_sites'] ) && ! is_array( $merged_settings['mesh_peer_sites'] ) ) {
				$errors[] = 'Mesh peer sites must be an array.';
			}

			// Check 5: Ensure numeric settings are actually numeric.
			$numeric_keys = array( 'default_assistant', 'max_history_messages', 'request_timeout', 'memory_max_file_bytes' );
			foreach ( $numeric_keys as $key ) {
				if ( isset( $merged_settings[ $key ] ) && ! is_numeric( $merged_settings[ $key ] ) ) {
					$errors[] = sprintf( 'Setting "%s" must be numeric, got: %s', $key, gettype( $merged_settings[ $key ] ) );
				}
			}

			// Check 6: Validate URL format for endpoint settings.
			$url_keys = array( 'ollama_endpoint_url', 'lm_studio_endpoint_url', 'crawl4ai_base_url', 'playwright_service_url' );
			foreach ( $url_keys as $key ) {
				if ( ! empty( $merged_settings[ $key ] ) && false === filter_var( $merged_settings[ $key ], FILTER_VALIDATE_URL ) ) {
					$errors[] = sprintf( 'Setting "%s" must be a valid URL: %s', $key, esc_html( $merged_settings[ $key ] ) );
				}
			}

			// Check 7: Validate email format for email settings.
			$email_keys = array( 'cloudways_email', 'mailjet_from_email', 'gmail_user_email' );
			foreach ( $email_keys as $key ) {
				if ( ! empty( $merged_settings[ $key ] ) && ! is_email( $merged_settings[ $key ] ) ) {
					$errors[] = sprintf( 'Setting "%s" must be a valid email address: %s', $key, esc_html( $merged_settings[ $key ] ) );
				}
			}

			/**
			 * Filter validation errors before saving settings.
			 *
			 * Allows plugins to add custom validation rules.
			 *
			 * @param array $errors Array of error messages.
			 * @param array $merged_settings Settings after merge.
			 * @param array $existing_settings Previous settings.
			 */
			return apply_filters( 'wp_mcp_ai_validate_settings', $errors, $merged_settings, $existing_settings );
		}

		/**
		 * Clean up old settings backups.
		 *
		 * Keeps only the most recent N backups to prevent database bloat.
		 *
		 * @param int $keep_count Number of backups to keep.
		 */
		private function cleanup_old_setting_backups( $keep_count = 5 ) {
			global $wpdb;

			// Find all backup options.
			$backup_options = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} 
					WHERE option_name LIKE %s 
					ORDER BY option_name DESC",
					'wp_mcp_ai_settings_backup_%'
				)
			);

			// If we have more backups than we want to keep, delete the oldest ones.
			if ( count( $backup_options ) > $keep_count ) {
				$backups_to_delete = array_slice( $backup_options, $keep_count );
				foreach ( $backups_to_delete as $backup_option ) {
					delete_option( $backup_option );
				}
			}
		}

		/**
		 * Handle settings export AJAX request.
		 *
		 * Exports all plugin settings as a JSON file for backup or migration.
		 */
		public function handle_export_settings() {
			check_ajax_referer( 'wp-mcp-ai-dashboard', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos' ) ) );
			}

			// Clear cache before export to ensure fresh data.
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
			wp_cache_delete( WP_MCP_AI_Admin_Settings::OPTION_NAME, 'options' );

			$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

			// Add export metadata.
			$export_data = array(
				'version'        => '1.0',
				'exported_at'    => current_time( 'mysql' ),
				'exported_by'    => wp_get_current_user()->user_login,
				'site_url'       => get_site_url(),
				'plugin_version' => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : 'unknown',
				'settings'       => $settings,
			);

			// Create JSON with pretty print.
			$json = wp_json_encode( $export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

			if ( false === $json ) {
				wp_send_json_error( array( 'message' => __( 'Failed to encode settings as JSON.', 'mcp-ai-wpoos' ) ) );
			}

			// Generate filename with timestamp.
			$filename = 'nv-oos-settings-' . gmdate( 'Y-m-d-H-i-s' ) . '.json';

			// Send as downloadable file.
			header( 'Content-Type: application/json' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( 'Content-Length: ' . strlen( $json ) );
			header( 'Cache-Control: no-cache, no-store, must-revalidate' );
			header( 'Pragma: no-cache' );
			header( 'Expires: 0' );

			// Output JSON for file download. JSON is already safely encoded via wp_json_encode() on line 1055.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON output for file download, already encoded with wp_json_encode().
			echo $json;
			exit;
		}

		/**
		 * Handle settings import AJAX request.
		 *
		 * Imports settings from a JSON file.
		 */
		public function handle_import_settings() {
			check_ajax_referer( 'wp-mcp-ai-dashboard', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos' ) ) );
			}

			// Check if file was uploaded.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checking existence only, not using the value.
			if ( ! isset( $_FILES['settings_file'] ) || ! isset( $_FILES['settings_file']['error'] ) || UPLOAD_ERR_OK !== $_FILES['settings_file']['error'] ) {
				wp_send_json_error( array( 'message' => __( 'No file uploaded or upload error occurred.', 'mcp-ai-wpoos' ) ) );
			}

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- File data validated below.
			$file = $_FILES['settings_file'];

			// Validate file size (max 5MB for settings file).
			$max_size = 5 * MB_IN_BYTES;
			if ( $file['size'] > $max_size ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
						/* translators: %s: Maximum file size */
							__( 'File too large. Maximum size: %s', 'mcp-ai-wpoos' ),
							size_format( $max_size )
						),
					)
				);
			}

			// Validate file type using WordPress function (more secure than client MIME type).
			$filetype = wp_check_filetype( $file['name'], array( 'json' => 'application/json' ) );
			if ( 'json' !== $filetype['ext'] || 'application/json' !== $filetype['type'] ) {
				wp_send_json_error( array( 'message' => __( 'Invalid file type. Please upload a JSON file.', 'mcp-ai-wpoos' ) ) );
			}

			// Read file contents with size validation.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading uploaded file for import.
			$json_content = file_get_contents( $file['tmp_name'] );

			if ( false === $json_content || empty( $json_content ) ) {
				wp_send_json_error( array( 'message' => __( 'Failed to read uploaded file or file is empty.', 'mcp-ai-wpoos' ) ) );
			}

			// Additional size check after reading to prevent memory issues.
			if ( strlen( $json_content ) > $max_size ) {
				wp_send_json_error( array( 'message' => __( 'File content too large.', 'mcp-ai-wpoos' ) ) );
			}

			// Decode JSON.
			$import_data = json_decode( $json_content, true );

			if ( null === $import_data || JSON_ERROR_NONE !== json_last_error() ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
						/* translators: %s: JSON error message */
							__( 'Invalid JSON format: %s', 'mcp-ai-wpoos' ),
							json_last_error_msg()
						),
					)
				);
			}

			// Validate import data structure.
			if ( ! isset( $import_data['settings'] ) || ! is_array( $import_data['settings'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid settings file structure.', 'mcp-ai-wpoos' ) ) );
			}

			// Backup current settings before import.
			$current_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
			update_option( 'wp_mcp_ai_settings_backup_pre_import_' . time(), $current_settings, false );

			// Sanitize imported settings through section-based sanitization.
			$sanitized_settings = $this->sanitize_settings( $import_data['settings'], '' );

			// Validate the sanitized settings.
			$validation_errors = $this->validate_merged_settings( $sanitized_settings, $current_settings );

			if ( ! empty( $validation_errors ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Settings validation failed:', 'mcp-ai-wpoos' ),
						'errors'  => $validation_errors,
					)
				);
			}

			// Save imported settings.
			$update_result = update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $sanitized_settings, true );

			if ( false === $update_result ) {
				wp_send_json_error( array( 'message' => __( 'Failed to save imported settings.', 'mcp-ai-wpoos' ) ) );
			}

			// Clear all caches.
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
			wp_cache_delete( WP_MCP_AI_Admin_Settings::OPTION_NAME, 'options' );
			delete_transient( 'wp_mcp_ai_settings_cache' );

			wp_send_json_success(
				array(
					'message'        => __( 'Settings imported successfully!', 'mcp-ai-wpoos' ),
					'imported_count' => count( $sanitized_settings ),
					'imported_from'  => isset( $import_data['site_url'] ) ? esc_url( $import_data['site_url'] ) : 'unknown',
				)
			);
		}

		/**
		 * Handle clear cache AJAX request.
		 *
		 * Clears all settings-related caches.
		 */
		public function handle_clear_cache() {
			check_ajax_referer( 'wp-mcp-ai-dashboard', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos' ) ) );
			}

			// Clear all settings caches.
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
			wp_cache_delete( WP_MCP_AI_Admin_Settings::OPTION_NAME, 'options' );
			delete_transient( 'wp_mcp_ai_settings_cache' );

			// Clear any other related caches.
			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}

			wp_send_json_success(
				array(
					'message' => __( 'All settings caches cleared successfully!', 'mcp-ai-wpoos' ),
				)
			);
		}

		/**
		 * Handle reset settings AJAX request.
		 *
		 * Resets all settings to default values.
		 */
		public function handle_reset_settings() {
			check_ajax_referer( 'wp-mcp-ai-dashboard', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos' ) ) );
			}

			// Backup current settings before reset.
			$current_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
			update_option( 'wp_mcp_ai_settings_backup_pre_reset_' . time(), $current_settings, false );

			// Get default settings.
			$default_settings = WP_MCP_AI_Admin_Settings_Base::get_default_settings();

			// Save default settings.
			$update_result = update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $default_settings, true );

			if ( false === $update_result ) {
				wp_send_json_error( array( 'message' => __( 'Failed to reset settings.', 'mcp-ai-wpoos' ) ) );
			}

			// Clear all caches.
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
			wp_cache_delete( WP_MCP_AI_Admin_Settings::OPTION_NAME, 'options' );
			delete_transient( 'wp_mcp_ai_settings_cache' );

			wp_send_json_success(
				array(
					'message' => __( 'Settings reset to defaults successfully!', 'mcp-ai-wpoos' ),
				)
			);
		}

		/**
		 * Handle settings health check AJAX request.
		 *
		 * Checks settings integrity and reports any issues.
		 */
		public function handle_check_settings_health() {
			check_ajax_referer( 'wp-mcp-ai-dashboard', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos' ) ) );
			}

			// Clear cache and get fresh settings.
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
			wp_cache_delete( WP_MCP_AI_Admin_Settings::OPTION_NAME, 'options' );
			$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

			$issues   = array();
			$warnings = array();
			$info     = array();

			// Check 1: Settings exist.
			if ( empty( $settings ) ) {
				$issues[] = __( 'No settings found in database. Settings may need to be initialized.', 'mcp-ai-wpoos' );
			}

			// Check 2: Settings is an array.
			if ( ! is_array( $settings ) ) {
				$issues[] = __( 'Settings data is not an array. Data corruption detected.', 'mcp-ai-wpoos' );
			} else {
				/* translators: %d: number of settings fields */
				$info[] = sprintf( __( 'Total settings fields: %d', 'mcp-ai-wpoos' ), count( $settings ) );
			}

			// Check 3: Critical settings present.
			$critical_fields = array( 'default_provider', 'default_model' );
			foreach ( $critical_fields as $field ) {
				if ( ! isset( $settings[ $field ] ) || empty( $settings[ $field ] ) ) {
					/* translators: %s: field name */
					$warnings[] = sprintf( __( 'Critical field "%s" is missing or empty.', 'mcp-ai-wpoos' ), $field );
				}
			}

			// Check 4: Provider keys configured.
			$provider_keys        = array( 'openai_api_key', 'gemini_api_key', 'ollama_endpoint_url' );
			$configured_providers = 0;
			foreach ( $provider_keys as $key ) {
				if ( ! empty( $settings[ $key ] ) ) {
					++$configured_providers;
				}
			}
			if ( 0 === $configured_providers ) {
				$warnings[] = __( 'No AI providers configured. At least one provider is required.', 'mcp-ai-wpoos' );
			} else {
				/* translators: %d: number of configured providers */
				$info[] = sprintf( __( 'Configured providers: %d', 'mcp-ai-wpoos' ), $configured_providers );
			}

			// Check 5: Cache status.
			$cache_exists = false !== wp_cache_get( WP_MCP_AI_Admin_Settings::OPTION_NAME, 'options' );
			/* translators: %s: cache status (Active or Not cached) */
			$info[] = sprintf( __( 'Object cache status: %s', 'mcp-ai-wpoos' ), $cache_exists ? 'Active' : 'Not cached' );

			// Check 6: Backup count.
			global $wpdb;
			$backup_count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
					'wp_mcp_ai_settings_backup_%'
				)
			);
			/* translators: %d: number of settings backups */
			$info[] = sprintf( __( 'Settings backups available: %d', 'mcp-ai-wpoos' ), (int) $backup_count );

			// Prepare response.
			$health_status = 'good';
			if ( ! empty( $issues ) ) {
				$health_status = 'critical';
			} elseif ( ! empty( $warnings ) ) {
				$health_status = 'warning';
			}

			wp_send_json_success(
				array(
					'status'   => $health_status,
					'issues'   => $issues,
					'warnings' => $warnings,
					'info'     => $info,
					'message'  => sprintf(
						/* translators: %s: health status (GOOD, WARNING, or CRITICAL) */
						__( 'Health check complete. Status: %s', 'mcp-ai-wpoos' ),
						strtoupper( $health_status )
					),
				)
			);
		}
	}
}
