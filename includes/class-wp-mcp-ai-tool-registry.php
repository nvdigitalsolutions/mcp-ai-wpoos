<?php
/**
 * Tool registry singleton.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';

if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
	/**
	 * Maintains a list of available tool providers.
	 */
	class WP_MCP_AI_Tool_Registry {
		/**
		 * Singleton instance.
		 *
		 * @var WP_MCP_AI_Tool_Registry
		 */
		protected static $instance = null;

		/**
		 * Registered tools keyed by slug.
		 *
		 * @var WP_MCP_AI_Tool_Interface[]
		 */
		protected $tools = array();

		/**
		 * Whether the registry has been initialised.
		 *
		 * @var bool
		 */
		protected $bootstrapped = false;

		/**
		 * Human readable messages describing tools that were skipped.
		 *
		 * @var string[]
		 */
		protected $unavailable_tool_messages = array();

		/**
		 * Retrieve the singleton instance.
		 *
		 * @return WP_MCP_AI_Tool_Registry
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Prevent direct construction.
		 */
		protected function __construct() {}

		/**
		 * Prevent cloning.
		 */
		protected function __clone() {}

		/**
		 * Prevent unserialisation.
		 */
		public function __wakeup() {} // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore

		/**
		 * Initialise the registry by loading default tools and triggering hooks.
		 */
		public function init() {
			if ( $this->bootstrapped ) {
				return;
			}

			$this->bootstrapped = true;

			$this->load_default_tools();

			if ( is_admin() && ! empty( $this->unavailable_tool_messages ) ) {
				add_action( 'admin_notices', array( $this, 'render_unavailable_tool_notices' ) );
			}

			/**
			 * Allow third parties to register additional tools.
			 *
			 * @param WP_MCP_AI_Tool_Registry $registry Registry instance.
			 */
			do_action( 'wp_mcp_ai_register_tools', $this );
		}

		/**
		 * Render admin notices for tools that were skipped during registration.
		 */
		public function render_unavailable_tool_notices() {
			if ( empty( $this->unavailable_tool_messages ) || ! current_user_can( 'manage_options' ) ) {
				return;
			}

			if ( ! function_exists( 'get_current_screen' ) ) {
				return;
			}

			$screen = get_current_screen();
			if ( ! $screen ) {
				return;
			}

			$allowed_screens = array(
				'plugins',
				'plugins-network',
				'plugin-install',
				'plugin-install-network',
			);

			if ( ! in_array( $screen->id, $allowed_screens, true ) ) {
				return;
			}

			foreach ( $this->unavailable_tool_messages as $message ) {
				if ( empty( $message ) ) {
					continue;
				}

				printf( '<div class="notice notice-info"><p>%s</p></div>', esc_html( $message ) );
			}
		}

		/**
		 * Register a tool implementation.
		 *
		 * @param string|WP_MCP_AI_Tool_Interface $tool Tool class name or instance.
		 * @return bool Whether the tool was registered.
		 */
		public function register_tool( $tool ) {
			if ( is_string( $tool ) ) {
				if ( ! class_exists( $tool ) ) {
					return false;
				}

				$tool = new $tool();
			}

			if ( ! $tool instanceof WP_MCP_AI_Tool_Interface ) {
				return false;
			}

			$slug = sanitize_key( $tool->get_slug() );

			if ( empty( $slug ) ) {
				return false;
			}

			$this->tools[ $slug ] = $tool;

			return true;
		}

		/**
		 * Unregister a tool by slug.
		 *
		 * @param string $slug Tool slug.
		 */
		public function unregister_tool( $slug ) {
			$slug = sanitize_key( $slug );
			unset( $this->tools[ $slug ] );
		}

		/**
		 * Retrieve a tool instance.
		 *
		 * @param string $slug Tool slug.
		 * @return WP_MCP_AI_Tool_Interface|null
		 */
		public function get_tool( $slug ) {
			$slug = sanitize_key( $slug );

			return isset( $this->tools[ $slug ] ) ? $this->tools[ $slug ] : null;
		}

		/**
		 * Retrieve all registered tools.
		 *
		 * @return WP_MCP_AI_Tool_Interface[]
		 */
		public function get_tools() {
			return array_values( $this->tools );
		}

		/**
		 * Execute a tool.
		 *
		 * @param string $slug      Tool slug.
		 * @param array  $arguments Tool arguments.
		 * @param array  $context   Execution context.
		 * @return mixed|WP_Error Tool result or error.
		 */
		public function execute_tool( $slug, $arguments = array(), $context = array() ) {
			$slug = sanitize_key( $slug );
			$tool = $this->get_tool( $slug );

			if ( ! $tool ) {
				return new WP_Error(
					'wp_mcp_ai_tool_not_found',
					sprintf(
						/* translators: %s: tool slug */
						__( 'Tool "%s" not found.', 'wp-mcp-ai' ),
						$slug
					)
				);
			}

			// Validate flow stage eligibility.
			$flow_stage_validation = $this->validate_tool_flow_stage( $slug, $context );
			if ( is_wp_error( $flow_stage_validation ) ) {
				return $flow_stage_validation;
			}

			// Execute the tool.
			return $tool->execute( $arguments, $context );
		}

		/**
		 * Check if a tool is registered.
		 *
		 * @param string $slug Tool slug.
		 * @return bool Whether the tool is registered.
		 */
		public function is_tool_registered( $slug ) {
			$slug = sanitize_key( $slug );
			return isset( $this->tools[ $slug ] );
		}

		/**
		 * Get tool capability requirement.
		 *
		 * @param string $slug Tool slug.
		 * @return string|null Required capability or null.
		 */
		public function get_tool_capability( $slug ) {
			// This method is referenced but not yet implemented.
			// For now, return null to maintain compatibility.
			return null;
		}

		/**
		 * Get tool definition for LLM payload.
		 *
		 * @param string $slug Tool slug.
		 * @return array|null Tool definition or null.
		 */
		public function get_tool_definition( $slug ) {
			$tool = $this->get_tool( $slug );
			if ( ! $tool ) {
				return null;
			}

			return array(
				'name'        => $tool->get_slug(),
				'description' => $tool->get_description(),
				'parameters'  => $tool->get_parameters_schema(),
			);
		}

		/**
		 * Retrieve the default tool grouping map keyed by tool slug.
		 *
		 * @return array<string, string>
		 */
		public function get_tool_group_map() {
			$default_map = array(
				// WordPress Core - Tools that work with base WordPress, no external dependencies.
				'submit_document_prompt'           => 'wordpress-core',
				'search_content'                   => 'wordpress-core',
				'search_attachments'               => 'wordpress-core',
				'get_recent_posts'                 => 'wordpress-core',
				'save_post'                        => 'wordpress-core',
				'get_user_info'                    => 'wordpress-core',
				'get_site_summary'                 => 'wordpress-core',
				'get_system_logs'                  => 'wordpress-core',
				'get_update_status'                => 'wordpress-core',
				'get_site_health'                  => 'wordpress-core',
				'get_environment_status'           => 'wordpress-core',
				'create_cron_job'                  => 'wordpress-core',
				'list_cron_jobs'                   => 'wordpress-core',
				'get_cron_job'                     => 'wordpress-core',
				'delete_cron_job'                  => 'wordpress-core',
				'send_group_email'                 => 'wordpress-core',
				'purge_cache'                      => 'wordpress-core',
				'check_wp_cli'                     => 'wordpress-core',
				'probe_chat'                       => 'wordpress-core',
				'probe_remote_mcp'                 => 'wordpress-core',
				'query_remote_site'                => 'wordpress-core',
				'query_mesh_intelligent'           => 'wordpress-core',
				'check_site_security'              => 'wordpress-core',
				'count_tokens'                     => 'wordpress-core',

				// WordPress Plugins - Tools requiring specific WordPress plugins.
				'get_elementor_templates'          => 'wordpress-plugins',
				'get_woo_recent_orders'            => 'wordpress-plugins',
				'get_woo_products'                 => 'wordpress-plugins',
				'create_woo_product'               => 'wordpress-plugins',
				'get_jetengine_items'              => 'wordpress-plugins',
				'list_jetengine_rest_routes'       => 'wordpress-plugins',
				'invoke_jetengine_route'           => 'wordpress-plugins',
				'get_jetformbuilder_forms'         => 'wordpress-plugins',
				'get_jetformbuilder_submissions'   => 'wordpress-plugins',
				'get_rankmath_seo'                 => 'wordpress-plugins',
				'create_wpcode_snippet'            => 'wordpress-plugins',
				'generate_simple_jwt_token'        => 'wordpress-plugins',

				// External Tools - Tools requiring external APIs or credentials.
				'generate_openai_image'            => 'external-tools',
				'generate_gemini_image'            => 'external-tools',
				'edit_gemini_image'                => 'external-tools',
				'generate_openai_speech'           => 'external-tools',
				'transcribe_openai_audio'          => 'external-tools',
				'open_openai_usage'                => 'external-tools',
				'open_openai_logs'                 => 'external-tools',
				'run_openai_external_action'       => 'external-tools',
				'create_google_calendar_event'     => 'external-tools',
				'google_analytics_report'          => 'external-tools',
				'vision_product_search'            => 'external-tools',
				'vision_object_localization'       => 'external-tools',
				'post_google_business_update'      => 'external-tools',
				'get_google_business_insights'     => 'external-tools',
				'search_gmail'                     => 'external-tools',
				'send_mailjet_email'               => 'external-tools',
				'send_telegram_message'            => 'external-tools',
				'schedule_notify_sms'              => 'external-tools',
				'send_whatsapp_message'            => 'external-tools',
				'post_facebook_instagram'          => 'external-tools',
				'post_tiktok_video'                => 'external-tools',
				'post_linkedin_update'             => 'external-tools',
				'get_facebook_instagram_insights'  => 'external-tools',
				'get_tiktok_insights'              => 'external-tools',
				'get_linkedin_insights'            => 'external-tools',
				'web_search'                       => 'external-tools',
				'crawl4ai_price_lookup'            => 'external-tools',
				'run_crawl4ai_job'                 => 'external-tools',
				'get_gdacs_events'                 => 'external-tools',
				'get_open_meteo_forecast'          => 'external-tools',
				'get_nhc_active_storms'            => 'external-tools',
				'reliefweb_reports'                => 'external-tools',
				'get_import_duty'                  => 'external-tools',
				'quickbooks_report'                => 'external-tools',
				'generate_auth0_token'             => 'external-tools',
				'purge_cloudflare_cache'           => 'external-tools',
				'purge_varnish_cache'              => 'external-tools',
			);

			/**
			 * Filter the tool grouping map used throughout the admin UI.
			 *
			 * @param array<string, string> $default_map Associative array of tool slugs to group identifiers.
			 */
			return apply_filters( 'wp_mcp_ai_tool_group_map', $default_map );
		}

		/**
		 * Retrieve the default labels for tool groups.
		 *
		 * @return array<string, string>
		 */
		public function get_tool_group_labels() {
			$default_labels = array(
				'wordpress-core'    => __( 'WordPress Core', 'wp-mcp-ai' ),
				'wordpress-plugins' => __( 'WordPress Plugins', 'wp-mcp-ai' ),
				'external-tools'    => __( 'External Tools', 'wp-mcp-ai' ),
				'other'             => __( 'Other tools', 'wp-mcp-ai' ),
			);

			/**
			 * Filter the tool group labels used throughout the admin UI.
			 *
			 * @param array<string, string> $default_labels Associative array of group identifiers to labels.
			 */
			return apply_filters( 'wp_mcp_ai_tool_group_labels', $default_labels );
		}

		/**
		 * Retrieve capability flags for a specific tool.
		 *
		 * Capability flags provide metadata about tool requirements and
		 * characteristics for orchestrating agentic workflows.
		 *
		 * @param string $slug Tool slug.
		 * @return array<string> Array of capability flags, or empty array if tool not found or has no flags.
		 */
		public function get_tool_capability_flags( $slug ) {
			$tool = $this->get_tool( $slug );

			if ( ! $tool ) {
				return array();
			}

			if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
				$flags = $tool->get_capability_flags();
				return is_array( $flags ) ? $flags : array();
			}

			return array();
		}

		/**
		 * Retrieve capability flags for all registered tools.
		 *
		 * Returns an associative array mapping tool slugs to their capability flags.
		 *
		 * @return array<string, array<string>> Associative array of tool slugs to capability flag arrays.
		 */
		public function get_all_tool_capability_flags() {
			$flags_map = array();

			foreach ( $this->tools as $slug => $tool ) {
				$flags = $this->get_tool_capability_flags( $slug );
				if ( ! empty( $flags ) ) {
					$flags_map[ $slug ] = $flags;
				}
			}

			/**
			 * Filter the tool capability flags map used throughout the system.
			 *
			 * @param array<string, array<string>> $flags_map Associative array of tool slugs to capability flag arrays.
			 */
			return apply_filters( 'wp_mcp_ai_tool_capability_flags', $flags_map );
		}

		/**
		 * Filter tools by capability flag.
		 *
		 * Returns tools that have the specified capability flag.
		 *
		 * @param string $flag Capability flag to filter by.
		 * @return WP_MCP_AI_Tool_Interface[] Array of tools with the specified flag.
		 */
		public function get_tools_by_capability_flag( $flag ) {
			$filtered_tools = array();

			foreach ( $this->tools as $slug => $tool ) {
				$flags = $this->get_tool_capability_flags( $slug );
				if ( in_array( $flag, $flags, true ) ) {
					$filtered_tools[] = $tool;
				}
			}

			return $filtered_tools;
		}

		/**
		 * Retrieve tool-specific rules for a tool.
		 *
		 * Tool rules provide detailed constraints and requirements beyond capability flags.
		 *
		 * @param string $slug Tool slug.
		 * @return array Tool rules, or empty array if tool not found or has no rules.
		 */
		public function get_tool_rules( $slug ) {
			$tool = $this->get_tool( $slug );

			if ( ! $tool ) {
				return array();
			}

			if ( $tool instanceof WP_MCP_AI_Tool_Rules_Interface ) {
				$rules = $tool->get_tool_rules();
				return is_array( $rules ) ? $rules : array();
			}

			return array();
		}

		/**
		 * Retrieve rules for all registered tools.
		 *
		 * Returns an associative array mapping tool slugs to their rules.
		 *
		 * @return array<string, array> Associative array of tool slugs to rule arrays.
		 */
		public function get_all_tool_rules() {
			$rules_map = array();

			foreach ( $this->tools as $slug => $tool ) {
				$rules = $this->get_tool_rules( $slug );
				if ( ! empty( $rules ) ) {
					$rules_map[ $slug ] = $rules;
				}
			}

			/**
			 * Filter the tool rules map used throughout the system.
			 *
			 * @param array<string, array> $rules_map Associative array of tool slugs to rule arrays.
			 */
			return apply_filters( 'wp_mcp_ai_tool_rules', $rules_map );
		}

		/**
		 * Retrieve flow stages for a specific tool.
		 *
		 * Flow stages define when a tool can be invoked during an agentic workflow.
		 *
		 * @param string $slug Tool slug.
		 * @return array<string> Array of eligible stage identifiers, or array('anytime') if not defined.
		 */
		public function get_tool_flow_stages( $slug ) {
			$tool = $this->get_tool( $slug );

			if ( ! $tool ) {
				return array( 'anytime' );
			}

			if ( $tool instanceof WP_MCP_AI_Tool_Flow_Stage_Interface ) {
				$stages = $tool->get_flow_stages();
				return is_array( $stages ) && ! empty( $stages ) ? $stages : array( 'anytime' );
			}

			return array( 'anytime' );
		}

		/**
		 * Retrieve flow stages for all registered tools.
		 *
		 * Returns an associative array mapping tool slugs to their eligible stages.
		 *
		 * @return array<string, array<string>> Associative array of tool slugs to stage arrays.
		 */
		public function get_all_tool_flow_stages() {
			$stages_map = array();

			foreach ( $this->tools as $slug => $tool ) {
				$stages = $this->get_tool_flow_stages( $slug );
				if ( ! in_array( 'anytime', $stages, true ) ) {
					// Only include tools with restricted stages.
					$stages_map[ $slug ] = $stages;
				}
			}

			/**
			 * Filter the tool flow stages map used throughout the system.
			 *
			 * @param array<string, array<string>> $stages_map Associative array of tool slugs to stage arrays.
			 */
			return apply_filters( 'wp_mcp_ai_tool_flow_stages', $stages_map );
		}

		/**
		 * Validate tool execution against its flow stage eligibility.
		 *
		 * Checks if the tool can be executed in the current flow stage.
		 *
		 * @param string $slug Tool slug.
		 * @param array  $context Execution context with 'flow_stage' or 'iteration' and 'max_iterations'.
		 * @return true|WP_Error True if valid, WP_Error if validation fails.
		 */
		public function validate_tool_flow_stage( $slug, $context = array() ) {
			$eligible_stages = $this->get_tool_flow_stages( $slug );

			// If tool is eligible anytime, no validation needed.
			if ( in_array( 'anytime', $eligible_stages, true ) ) {
				return true;
			}

			// Determine current flow stage from context.
			$current_stage = $this->determine_flow_stage( $context );

			// Check if tool is eligible for current stage.
			if ( ! in_array( $current_stage, $eligible_stages, true ) ) {
				return new WP_Error(
					'tool_flow_stage_not_eligible',
					sprintf(
						/* translators: 1: tool slug, 2: current stage, 3: eligible stages */
						__( 'Tool "%1$s" cannot be used in the "%2$s" stage. Eligible stages: %3$s', 'wp-mcp-ai' ),
						$slug,
						$current_stage,
						implode( ', ', $eligible_stages )
					),
					array(
						'tool'            => $slug,
						'current_stage'   => $current_stage,
						'eligible_stages' => $eligible_stages,
					)
				);
			}

			return true;
		}

		/**
		 * Determine the current flow stage from execution context.
		 *
		 * @param array $context Execution context.
		 * @return string Current flow stage: 'start', 'middle', 'end', or 'anytime'.
		 */
		protected function determine_flow_stage( $context ) {
			// Check for explicit flow_stage in context.
			if ( ! empty( $context['flow_stage'] ) ) {
				$stage = sanitize_key( $context['flow_stage'] );
				if ( in_array( $stage, array( 'start', 'middle', 'end', 'anytime' ), true ) ) {
					return $stage;
				}
			}

			// Determine stage from iteration context.
			if ( isset( $context['iteration'] ) && isset( $context['max_iterations'] ) ) {
				$iteration      = absint( $context['iteration'] );
				$max_iterations = absint( $context['max_iterations'] );

				if ( $max_iterations <= 1 ) {
					// Single iteration workflow - consider it both start and end.
					return 'start';
				}

				if ( 0 === $iteration ) {
					return 'start';
				} elseif ( $iteration >= $max_iterations - 1 ) {
					return 'end';
				} else {
					return 'middle';
				}
			}

			// Default to anytime if stage cannot be determined.
			return 'anytime';
		}

		/**
		 * Validate tool execution against its rules.
		 *
		 * Checks if the current environment and parameters meet the tool's requirements.
		 *
		 * @param string $slug Tool slug.
		 * @param array  $arguments Tool arguments.
		 * @param array  $context Execution context.
		 * @return true|WP_Error True if valid, WP_Error if validation fails.
		 */
		public function validate_tool_execution( $slug, $arguments = array(), $context = array() ) {
			$rules = $this->get_tool_rules( $slug );

			if ( empty( $rules ) ) {
				return true; // No rules to validate.
			}

			$errors = array();

			// Validate model requirements.
			if ( ! empty( $rules['model_requirements'] ) ) {
				$model_error = $this->validate_model_requirements( $rules['model_requirements'], $arguments, $context );
				if ( is_wp_error( $model_error ) ) {
					$errors[] = $model_error->get_error_message();
				}
			}

			// Validate parameter constraints.
			if ( ! empty( $rules['parameter_constraints'] ) ) {
				$param_error = $this->validate_parameter_constraints( $rules['parameter_constraints'], $arguments );
				if ( is_wp_error( $param_error ) ) {
					$errors[] = $param_error->get_error_message();
				}
			}

			// Validate dependencies.
			if ( ! empty( $rules['dependencies'] ) ) {
				$dep_error = $this->validate_dependencies( $rules['dependencies'] );
				if ( is_wp_error( $dep_error ) ) {
					$errors[] = $dep_error->get_error_message();
				}
			}

			if ( ! empty( $errors ) ) {
				return new WP_Error( 'tool_validation_failed', implode( '; ', $errors ), array( 'errors' => $errors ) );
			}

			return true;
		}

		/**
		 * Validate model requirements.
		 *
		 * @param array $requirements Model requirements.
		 * @param array $arguments Tool arguments.
		 * @param array $context Execution context.
		 * @return true|WP_Error
		 */
		protected function validate_model_requirements( $requirements, $arguments, $context ) {
			// Check if model is specified when required.
			if ( ! empty( $requirements['required'] ) && empty( $arguments['model'] ) && empty( $context['model'] ) ) {
				return new WP_Error( 'model_required', 'This tool requires a model to be specified' );
			}

			$model = $arguments['model'] ?? $context['model'] ?? '';

			// Validate allowed providers.
			if ( ! empty( $requirements['providers'] ) && ! empty( $model ) ) {
				$provider = explode( ':', $model )[0] ?? '';
				if ( ! in_array( $provider, $requirements['providers'], true ) ) {
					return new WP_Error(
						'invalid_provider',
						sprintf( 'Model provider must be one of: %s', implode( ', ', $requirements['providers'] ) )
					);
				}
			}

			// Validate specific models.
			if ( ! empty( $requirements['models'] ) && ! empty( $model ) ) {
				if ( ! in_array( $model, $requirements['models'], true ) ) {
					return new WP_Error(
						'invalid_model',
						sprintf( 'Model must be one of: %s', implode( ', ', $requirements['models'] ) )
					);
				}
			}

			return true;
		}

		/**
		 * Validate parameter constraints.
		 *
		 * @param array $constraints Parameter constraints.
		 * @param array $arguments Tool arguments.
		 * @return true|WP_Error
		 */
		protected function validate_parameter_constraints( $constraints, $arguments ) {
			// Check required fields.
			if ( ! empty( $constraints['required_fields'] ) ) {
				foreach ( $constraints['required_fields'] as $field ) {
					if ( ! isset( $arguments[ $field ] ) || '' === $arguments[ $field ] ) {
						return new WP_Error( 'missing_parameter', "Required parameter '{$field}' is missing" );
					}
				}
			}

			// Check max items constraint.
			if ( ! empty( $constraints['max_items'] ) && isset( $arguments['items'] ) ) {
				if ( is_array( $arguments['items'] ) && count( $arguments['items'] ) > $constraints['max_items'] ) {
					return new WP_Error(
						'too_many_items',
						sprintf( 'Maximum %d items allowed, %d provided', $constraints['max_items'], count( $arguments['items'] ) )
					);
				}
			}

			return true;
		}

		/**
		 * Validate dependencies.
		 *
		 * @param array $dependencies Dependency requirements.
		 * @return true|WP_Error
		 */
		protected function validate_dependencies( $dependencies ) {
			// Check required plugins.
			if ( ! empty( $dependencies['required_plugins'] ) ) {
				foreach ( $dependencies['required_plugins'] as $plugin ) {
					if ( 'woocommerce' === $plugin && ! class_exists( 'WooCommerce' ) ) {
						return new WP_Error( 'missing_plugin', 'WooCommerce plugin is required' );
					}
					// Add more plugin checks as needed.
				}
			}

			// Check required PHP extensions.
			if ( ! empty( $dependencies['required_extensions'] ) ) {
				foreach ( $dependencies['required_extensions'] as $extension ) {
					if ( ! extension_loaded( $extension ) ) {
						return new WP_Error( 'missing_extension', "PHP extension '{$extension}' is required" );
					}
				}
			}

			// Check required settings.
			if ( ! empty( $dependencies['required_settings'] ) ) {
				foreach ( $dependencies['required_settings'] as $setting => $option_name ) {
					if ( empty( get_option( $option_name ) ) ) {
						return new WP_Error( 'missing_setting', "Required setting '{$setting}' is not configured" );
					}
				}
			}

			return true;
		}

		/**
		 * Determine if base version mode is enabled.
		 *
		 * Base version mode excludes tools that require third-party plugins or external API credentials.
		 *
		 * @return bool
		 */
		protected function is_base_version() {
			/**
			 * Filter whether to enable base version mode.
			 *
			 * When true, only tools that work with a base WordPress installation are loaded.
			 * Tools requiring WooCommerce, JetEngine, JetFormBuilder, Elementor, RankMath,
			 * WPCode, or external API credentials are excluded.
			 *
			 * @param bool $is_base_version Whether base version mode is enabled.
			 */
			return apply_filters( 'wp_mcp_ai_base_version', function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() );
		}

		/**
		 * Load the plugin's default tool providers.
		 */
		protected function load_default_tools() {
			$is_base_version = $this->is_base_version();

			// Tools that work with base WordPress (no third-party plugins or external APIs).
			$base_tools = array(
				'WP_MCP_AI_Tool_Get_Recent_Posts'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-recent-posts.php',
				'WP_MCP_AI_Tool_Search_Content'          => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-search-content.php',
				'WP_MCP_AI_Tool_Get_User_Info'           => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-user-info.php',
				'WP_MCP_AI_Tool_Get_Site_Summary'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-site-summary.php',
				'WP_MCP_AI_Tool_Count_Tokens'            => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-count-tokens.php',
				'WP_MCP_AI_Tool_Get_Site_Health'         => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-site-health.php',
				'WP_MCP_AI_Tool_Get_Environment_Status'  => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-environment-status.php',
				'WP_MCP_AI_Tool_Generate_Auth0_Token'    => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-auth0-token.php',
				'WP_MCP_AI_Tool_Get_NHC_Active_Storms'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-nhc-active-storms.php',
				'WP_MCP_AI_Tool_Search_Attachments'      => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-search-attachments.php',
				'WP_MCP_AI_Tool_Web_Search'              => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-web-search.php',
				'WP_MCP_AI_Tool_Crawl4AI_Price_Lookup'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-crawl4ai-price-lookup.php',
				'WP_MCP_AI_Tool_Get_GDACS_Events'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-gdacs-events.php',
				'WP_MCP_AI_Tool_Get_Open_Meteo_Forecast' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-open-meteo-forecast.php',
				'WP_MCP_AI_Tool_Run_OpenAI_External_Action' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-run-openai-external-action.php',
				'WP_MCP_AI_Tool_Probe_Chat'              => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-probe-chat.php',
				'WP_MCP_AI_Tool_Probe_Remote_MCP'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-probe-remote-mcp.php',
				'WP_MCP_AI_Tool_Generate_OpenAI_Speech'  => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-openai-speech.php',
				'WP_MCP_AI_Tool_Transcribe_OpenAI_Audio' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-transcribe-openai-audio.php',
				'WP_MCP_AI_Tool_Generate_OpenAI_Image'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-openai-image.php',
				'WP_MCP_AI_Tool_Generate_Gemini_Image'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-gemini-image.php',
				'WP_MCP_AI_Tool_Submit_Document_Prompt'  => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-submit-document-prompt.php',
				'WP_MCP_AI_Tool_Save_Post'               => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-save-post.php',
				'WP_MCP_AI_Tool_Run_Crawl4AI_Job'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-run-crawl4ai-job.php',
				'WP_MCP_AI_Tool_Open_OpenAI_Logs'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-open-openai-logs.php',
				'WP_MCP_AI_Tool_Open_OpenAI_Usage'       => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-open-openai-usage.php',
				'WP_MCP_AI_Tool_Get_System_Logs'         => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-system-logs.php',
				'WP_MCP_AI_Tool_Get_Update_Status'       => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-update-status.php',
				'WP_MCP_AI_Tool_Create_Cron_Job'         => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-cron-job.php',
				'WP_MCP_AI_Tool_List_Cron_Jobs'          => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-cron-jobs.php',
				'WP_MCP_AI_Tool_Get_Cron_Job'            => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-cron-job.php',
				'WP_MCP_AI_Tool_Delete_Cron_Job'         => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-cron-job.php',
				'WP_MCP_AI_Tool_Send_Group_Email'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-send-group-email.php',
				'WP_MCP_AI_Tool_Purge_Cloudflare_Cache'  => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-purge-cloudflare-cache.php',
				'WP_MCP_AI_Tool_Purge_Varnish_Cache'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-purge-varnish-cache.php',
				'WP_MCP_AI_Tool_Purge_Cache'             => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-purge-cache.php',
				'WP_MCP_AI_Tool_Get_Import_Duty'         => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-import-duty.php',
				'WP_MCP_AI_Tool_ReliefWeb_Reports'       => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-reliefweb-reports.php',
				'WP_MCP_AI_Tool_Check_WP_CLI'            => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-check-wp-cli.php',
				'WP_MCP_AI_Tool_Query_Remote_Site'       => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-query-remote-site.php',
				'WP_MCP_AI_Tool_Query_Mesh_Intelligent'  => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-query-mesh-intelligent.php',
				'WP_MCP_AI_Tool_Check_Site_Security'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-check-site-security.php',
			);

			// Additional tools that require third-party plugins or external API credentials.
			$extended_tools = array(
				'WP_MCP_AI_Tool_Search_Gmail'              => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-search-gmail.php',
				'WP_MCP_AI_Tool_Get_Elementor_Templates'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-elementor-templates.php',
				'WP_MCP_AI_Tool_Get_Woo_Orders'            => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-woo-recent-orders.php',
				'WP_MCP_AI_Tool_Get_Woo_Products'          => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-woo-products.php',
				'WP_MCP_AI_Tool_Create_Woo_Product'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-woo-product.php',
				'WP_MCP_AI_Tool_Get_JetEngine_Items'       => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-jetengine-items.php',
				'WP_MCP_AI_Tool_Get_JetFormBuilder_Forms'  => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-jetformbuilder-forms.php',
				'WP_MCP_AI_Tool_Get_JetFormBuilder_Submissions' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-jetformbuilder-submissions.php',
				'WP_MCP_AI_Tool_List_JetEngine_Routes'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-jetengine-routes.php',
				'WP_MCP_AI_Tool_Invoke_JetEngine_Route'    => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-invoke-jetengine-route.php',
				'WP_MCP_AI_Tool_Create_Google_Calendar_Event' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-google-calendar-event.php',
				'WP_MCP_AI_Tool_Get_RankMath_SEO'          => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-rankmath-seo.php',
				'WP_MCP_AI_Tool_Generate_Simple_JWT_Token' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-simple-jwt-token.php',
				'WP_MCP_AI_Tool_Send_Mailjet_Email'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-send-mailjet-email.php',
				'WP_MCP_AI_Tool_Send_Telegram_Message'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-send-telegram-message.php',
				'WP_MCP_AI_Tool_Schedule_Notify_SMS'       => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-schedule-notify-sms.php',
				'WP_MCP_AI_Tool_Send_WhatsApp_Message'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-send-whatsapp-message.php',
				'WP_MCP_AI_Tool_Get_QuickBooks_Report'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-quickbooks-report.php',
				'WP_MCP_AI_Tool_Get_Google_Analytics_Report' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-google-analytics-report.php',
				'WP_MCP_AI_Tool_Create_WPCode_Snippet'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-wpcode-snippet.php',
				'WP_MCP_AI_Tool_Post_Facebook_Instagram'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-post-facebook-instagram.php',
				'WP_MCP_AI_Tool_Post_Tiktok_Video'         => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-post-tiktok-video.php',
				'WP_MCP_AI_Tool_Post_Google_Business_Update' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-post-google-business-update.php',
				'WP_MCP_AI_Tool_Post_Linkedin_Update'      => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-post-linkedin-update.php',
				'WP_MCP_AI_Tool_Get_Facebook_Instagram_Insights' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-facebook-instagram-insights.php',
				'WP_MCP_AI_Tool_Get_Tiktok_Insights'       => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-tiktok-insights.php',
				'WP_MCP_AI_Tool_Get_Google_Business_Insights' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-google-business-insights.php',
				'WP_MCP_AI_Tool_Get_Linkedin_Insights'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-linkedin-insights.php',
				'WP_MCP_AI_Tool_Vision_Product_Search'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-vision-product-search.php',
				'WP_MCP_AI_Tool_Vision_Object_Localization' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-vision-object-localization.php',
			);

			// Combine tools based on version mode.
			$default_tools = $is_base_version ? $base_tools : array_merge( $base_tools, $extended_tools );

			/**
			 * Filter the list of default tools to load.
			 *
			 * @param array $default_tools Array of tool class names and file paths.
			 * @param bool  $is_base_version Whether base version mode is enabled.
			 */
			$default_tools = apply_filters( 'wp_mcp_ai_default_tools', $default_tools, $is_base_version );

			foreach ( $default_tools as $class => $file ) {
				if ( file_exists( $file ) ) {
					require_once $file;
				}

				if ( class_exists( $class ) ) {
					$should_register = true;

					if ( method_exists( $class, 'is_available' ) ) {
						$should_register = (bool) call_user_func( array( $class, 'is_available' ) );

						if ( ! $should_register && method_exists( $class, 'get_unavailable_reason' ) ) {
							$message = (string) call_user_func( array( $class, 'get_unavailable_reason' ) );
							if ( $message && ! in_array( $message, $this->unavailable_tool_messages, true ) ) {
								$this->unavailable_tool_messages[] = $message;
							}
						}
					}

					if ( $should_register ) {
						$this->register_tool( new $class() );
					}
				}
			}
		}
	}
}
