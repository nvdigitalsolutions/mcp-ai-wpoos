<?php
/**
 * Pro Module Registry.
 *
 * Replaces the monolithic wp_mcp_ai_pro_init() function with a discoverable,
 * self-documenting module registry. Each Pro subsystem (toolkit, admin section,
 * REST controller, CPT set, service, bridge) is defined as a module descriptor
 * with explicit dependencies, context gates, and enable flags.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.1.27
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Pro_Module_Registry' ) ) {
	/**
	 * Registry for Pro addon modules.
	 *
	 * @since 1.1.27
	 */
	final class WP_MCP_AI_Pro_Module_Registry {

		/**
		 * Singleton instance.
		 *
		 * @var self|null
		 */
		private static $instance = null;

		/**
		 * Registered module descriptors keyed by module ID.
		 *
		 * @var array<string, array>
		 */
		private $modules = array();

		/**
		 * Set of module IDs that have been loaded.
		 *
		 * @var array<string, bool>
		 */
		private $loaded = array();

		/**
		 * Whether boot() has already run.
		 *
		 * @var bool
		 */
		private $bootstrapped = false;

		/**
		 * Retrieve the singleton instance.
		 *
		 * @return self
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
		private function __construct() {}

		/**
		 * Prevent cloning.
		 */
		private function __clone() {}

		/**
		 * Prevent unserialization.
		 *
		 * @return void
		 * @throws \Exception Always.
		 */
		public function __wakeup() {
			throw new \Exception( 'Cannot unserialize singleton' );
		}

		/**
		 * Boot all eligible modules in dependency order. Idempotent.
		 *
		 * @return void
		 */
		public function boot() {
			if ( $this->bootstrapped ) {
				return;
			}
			$this->bootstrapped = true;

			$settings = get_option( 'wp_mcp_ai_settings', array() );
			$this->define_modules( $settings );
			$order = $this->resolve_order();

			foreach ( $order as $id ) {
				$mod = $this->modules[ $id ];
				if ( ! $this->check_context( $mod ) ) {
					continue;
				}
				if ( isset( $mod['enabled'] ) && ! $mod['enabled'] ) {
					continue;
				}
				if ( ! $this->check_required_classes( $mod ) ) {
					continue;
				}
				if ( ! $this->check_required_functions( $mod ) ) {
					continue;
				}
				if ( ! $this->check_required_files( $mod ) ) {
					continue;
				}
				call_user_func( $mod['factory'] );
				$this->loaded[ $id ] = true;
			}
		}

		/**
		 * Check whether a module's context gate is satisfied.
		 *
		 * @param array $mod Module descriptor.
		 * @return bool
		 */
		private function check_context( array $mod ) {
			if ( empty( $mod['context'] ) ) {
				return true;
			}
			if ( 'admin' === $mod['context'] && ! is_admin() ) {
				return false;
			}
			return true;
		}

		/**
		 * Check that all required classes exist.
		 *
		 * @param array $mod Module descriptor.
		 * @return bool
		 */
		private function check_required_classes( array $mod ) {
			if ( empty( $mod['requires'] ) ) {
				return true;
			}
			foreach ( $mod['requires'] as $class ) {
				if ( ! class_exists( $class ) ) {
					$this->debug_log( $mod, "required class \"{$class}\" not found" );
					return false;
				}
			}
			return true;
		}

		/**
		 * Check that all required functions exist.
		 *
		 * @param array $mod Module descriptor.
		 * @return bool
		 */
		private function check_required_functions( array $mod ) {
			if ( empty( $mod['requires_fn'] ) ) {
				return true;
			}
			foreach ( $mod['requires_fn'] as $fn ) {
				if ( ! function_exists( $fn ) ) {
					$this->debug_log( $mod, "required function \"{$fn}\" not found" );
					return false;
				}
			}
			return true;
		}

		/**
		 * Check that all required files exist (graceful degradation).
		 *
		 * @param array $mod Module descriptor.
		 * @return bool
		 */
		private function check_required_files( array $mod ) {
			if ( empty( $mod['files'] ) ) {
				return true;
			}
			foreach ( $mod['files'] as $file ) {
				if ( ! file_exists( $file ) ) {
					$this->debug_log( $mod, "required file \"{$file}\" not found" );
					return false;
				}
			}
			return true;
		}

		/**
		 * Log a debug message when a module is skipped.
		 *
		 * @param array  $mod    Module descriptor.
		 * @param string $reason Reason for skipping.
		 * @return void
		 */
		private function debug_log( array $mod, $reason ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'WP MCP AI Pro: Module "%s" skipped — %s.',
						isset( $mod['label'] ) ? $mod['label'] : 'unknown',
						$reason
					)
				);
			}
		}

		/**
		 * Topological sort — deps load first. Circular deps fall back to insertion order.
		 *
		 * @return string[]
		 */
		private function resolve_order() {
			$ids     = array_keys( $this->modules );
			$order   = array();
			$pending = $ids;

			while ( ! empty( $pending ) ) {
				$progress      = false;
				$still_pending = array();

				foreach ( $pending as $id ) {
					$deps       = isset( $this->modules[ $id ]['deps'] ) ? $this->modules[ $id ]['deps'] : array();
					$all_loaded = true;
					foreach ( $deps as $dep ) {
						if ( ! in_array( $dep, $order, true ) ) {
							$all_loaded = false;
							break;
						}
					}

					if ( $all_loaded ) {
						$order[]  = $id;
						$progress = true;
					} else {
						$still_pending[] = $id;
					}
				}

				if ( ! $progress ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						error_log( 'WP MCP AI Pro: Unresolved dependencies for modules: ' . implode( ', ', $still_pending ) );
					}
					$order = array_merge( $order, $still_pending );
					break;
				}

				$pending = $still_pending;
			}

			return $order;
		}

		/**
		 * Get the list of module IDs that have been loaded.
		 *
		 * @return string[]
		 */
		public function get_loaded_modules() {
			return array_keys( $this->loaded );
		}

		/**
		 * Check whether a specific module was loaded.
		 *
		 * @param string $id Module ID.
		 * @return bool
		 */
		public function is_loaded( $id ) {
			return isset( $this->loaded[ $id ] );
		}

		// ──────────────────────────────────────────────────────────────────
		// Module definitions
		// ──────────────────────────────────────────────────────────────────

		/**
		 * Register a module descriptor.
		 *
		 * @param string   $id      Unique module ID.
		 * @param string   $label   Human-readable name.
		 * @param string[] $deps    Module IDs that must load first.
		 * @param array    $options {
		 *     Optional. Module options.
		 *     @type string   $context     'admin' or null.
		 *     @type string[] $requires    Class names that must exist.
		 *     @type string[] $requires_fn Function names that must exist.
		 *     @type string[] $files       File paths that must exist.
		 *     @type bool     $enabled     Whether the module is enabled.
		 * }
		 * @param callable $factory Loader callable.
		 * @return void
		 */
		private function add_module( $id, $label, array $deps, array $options, callable $factory ) {
			$this->modules[ $id ] = array_merge(
				array(
					'label' => $label,
					'deps'  => $deps,
				),
				$options,
				array( 'factory' => $factory )
			);
		}

		/**
		 * Require a file if it exists (silent skip otherwise).
		 *
		 * @param string $path File path.
		 * @return void
		 */
		private function req( $path ) {
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		/**
		 * Define all Pro modules (~79 subsystems in 7 dependency tiers).
		 *
		 * @param array $settings Plugin settings.
		 * @return void
		 */
		private function define_modules( array $settings ) {
			$p = WP_MCP_AI_PRO_PATH . 'includes/';

			// ── Tier 1: Infrastructure ──────────────────────────────────
			$this->add_module(
				'npm_integration',
				'NPM Integration',
				array(),
				array( 'files' => array( $p . 'npm-integration-filters.php' ) ),
				function () use ( $p ) {
					require_once $p . 'npm-integration-filters.php';
				}
			);

			$this->add_module(
				'cdn_loader',
				'CDN Loader',
				array(),
				array( 'files' => array( $p . 'class-wp-mcp-ai-pro-cdn-loader.php' ) ),
				function () use ( $p ) {
					require_once $p . 'class-wp-mcp-ai-pro-cdn-loader.php';
				}
			);

			$this->add_module(
				'cpt_meta_schema',
				'CPT Meta Schema',
				array(),
				array(),
				function () use ( $p ) {
					require_once $p . 'class-wp-mcp-ai-pro-cpt-meta-schema.php';
					WP_MCP_AI_Pro_CPT_Meta_Schema::init();
				}
			);

			$this->add_module(
				'privacy',
				'Privacy API',
				array(),
				array(),
				function () use ( $p ) {
					require_once $p . 'class-wp-mcp-ai-pro-privacy.php';
					WP_MCP_AI_Pro_Privacy::init();
				}
			);

			$this->add_module(
				'schedule_manager',
				'Schedule Manager',
				array(),
				array(),
				function () use ( $p ) {
					$this->req( $p . 'class-wp-mcp-ai-pro-schedule-manager.php' );
					$this->req( $p . 'services/class-wp-mcp-ai-result-delivery-service.php' );
				}
			);

			$this->add_module(
				'mcp_servers_framework',
				'MCP Servers Framework',
				array(),
				array(),
				function () use ( $p ) {
					require_once $p . 'mcp-servers/mcp-servers-init.php';
				}
			);

			$this->add_module(
				'analytics_service',
				'Shared Analytics Service',
				array(),
				array(),
				function () use ( $p ) {
					require_once $p . 'analytics/init.php';
				}
			);

			// ── Tier 2: Utility classes ─────────────────────────────────
			$this->add_module(
				'product_type_helper',
				'Product Type Helper',
				array(),
				array(),
				function () use ( $p ) {
					require_once $p . 'class-wp-mcp-ai-product-type-helper.php';
				}
			);

			$this->add_module(
				'remote_connection',
				'Remote Connection',
				array(),
				array(),
				function () use ( $p ) {
					require_once $p . 'class-wp-mcp-ai-remote-connection.php';
				}
			);

			$this->add_module(
				'erp_interface',
				'ERP Interface + EZuite',
				array(),
				array(),
				function () use ( $p ) {
					require_once $p . 'interface-wp-mcp-ai-erp-connector.php';
					require_once $p . 'class-wp-mcp-ai-erp-ezuite.php';
				}
			);

			// ── Tier 3: CPT subsystems ──────────────────────────────────
			$this->add_module(
				'maintenance',
				'Maintenance Window',
				array(),
				array(),
				function () use ( $p ) {
					$this->req( $p . 'class-wp-mcp-ai-maintenance-cpt.php' );
					$this->req( $p . 'class-wp-mcp-ai-maintenance-rest.php' );
					$this->req( $p . 'class-wp-mcp-ai-maintenance-banner.php' );
					$this->req( $p . 'class-wp-mcp-ai-maintenance-notifier.php' );
				}
			);

			$this->add_module(
				'incidents',
				'Incident System',
				array(),
				array(),
				function () use ( $p ) {
					$this->req( $p . 'class-wp-mcp-ai-incident-cpt.php' );
					$this->req( $p . 'class-wp-mcp-ai-incident-rest.php' );
					$this->req( $p . 'class-wp-mcp-ai-incident-notifier.php' );
					$this->req( $p . 'class-wp-mcp-ai-incident-lesson-bridge.php' );
				}
			);

			$this->add_module(
				'content_format_templates',
				'Content Format Templates',
				array(),
				array(),
				function () use ( $p ) {
					$f = $p . 'class-wp-mcp-ai-content-format-template-cpt.php';
					if ( file_exists( $f ) ) {
						require_once $f;
						WP_MCP_AI_Content_Format_Template_CPT::init();
						WP_MCP_AI_Content_Format_Template_CPT::seed_defaults();
					}
					$engine = $p . 'services/class-wp-mcp-ai-content-template-engine.php';
					if ( file_exists( $engine ) ) {
						require_once $engine;
					}
				}
			);

			// ── Tier 4: Admin sections ──────────────────────────────────
			$this->add_module(
				'admin_sections',
				'Admin Sections',
				array(),
				array( 'context' => 'admin' ),
				function () {
					wp_mcp_ai_pro_load_admin_sections();
				}
			);

			$this->add_module(
				'admin_remote_sites',
				'Remote Sites Admin',
				array(),
				array( 'context' => 'admin' ),
				function () use ( $p ) {
					require_once $p . 'admin/class-wp-mcp-ai-pro-remote-sites-admin.php';
					require_once $p . 'admin/class-wp-mcp-ai-pro-metabox-remote-connections.php';
				}
			);

			$this->add_module(
				'admin_toolkit_mcp',
				'Toolkit MCP Servers Admin',
				array( 'mcp_servers_framework' ),
				array(
					'context'  => 'admin',
					'requires' => array( 'WP_MCP_AI_Toolkit_Server_Registry' ),
				),
				function () use ( $p ) {
					require_once $p . 'admin/class-wp-mcp-ai-pro-metabox-toolkit-mcp-servers.php';
					new WP_MCP_AI_Pro_Metabox_Toolkit_MCP_Servers();
					require_once $p . 'admin/class-wp-mcp-ai-pro-toolkit-mcp-servers-page.php';
					new WP_MCP_AI_Pro_Toolkit_MCP_Servers_Page();
				}
			);

			$this->add_module(
				'admin_analytics_service',
				'Analytics Service Admin',
				array( 'analytics_service' ),
				array( 'context' => 'admin' ),
				function () use ( $p ) {
					require_once $p . 'admin/class-wp-mcp-ai-analytics-service-page.php';
					new WP_MCP_AI_Analytics_Service_Page();
				}
			);

			$this->add_module(
				'admin_blueprints',
				'Unified Blueprints',
				array(),
				array( 'context' => 'admin' ),
				function () use ( $p ) {
					require_once $p . 'admin/class-wp-mcp-ai-unified-blueprints-page.php';
					WP_MCP_AI_Unified_Blueprints_Page::init();
				}
			);

			$this->add_module(
				'admin_ai_cpt',
				'AI CPT Management',
				array(),
				array(
					'context' => 'admin',
					'enabled' => ! empty( $settings['enable_ai_cpt_management'] ),
				),
				function () use ( $p ) {
					require_once $p . 'admin/class-wp-mcp-ai-pro-cpt-ai-integration.php';
					WP_MCP_AI_Pro_CPT_AI_Integration::get_instance();
					require_once $p . 'admin/class-wp-mcp-ai-post-research-page.php';
					require_once $p . 'admin/class-wp-mcp-ai-page-research-page.php';
					require_once $p . 'admin/class-wp-mcp-ai-post-settings-page.php';
					require_once $p . 'admin/class-wp-mcp-ai-page-settings-page.php';
				}
			);

			// ── Tier 5: REST controllers + UI ───────────────────────────
			$this->add_module(
				'rest_spa_bootstrap',
				'SPA Bootstrap REST',
				array(),
				array(),
				function () use ( $p ) {
					$f = $p . 'rest/class-wp-mcp-ai-pro-spa-bootstrap-controller.php';
					if ( file_exists( $f ) ) {
						require_once $f;
						add_action( 'rest_api_init', array( 'WP_MCP_AI_Pro_SPA_Bootstrap_Controller', 'register_routes' ) );
					}
				}
			);

			$this->add_module(
				'rest_tool_shortcuts',
				'Tool Shortcuts REST',
				array(),
				array(),
				function () use ( $p ) {
					$f = $p . 'rest/class-wp-mcp-ai-pro-rest-tool-shortcuts.php';
					if ( file_exists( $f ) ) {
						require_once $f;
						add_action( 'rest_api_init', array( 'WP_MCP_AI_Pro_REST_Tool_Shortcuts', 'register_routes' ) );
					}
				}
			);

			$this->add_module(
				'rest_slash_commands',
				'Slash Commands REST',
				array(),
				array(),
				function () use ( $p ) {
					$f = $p . 'rest/class-wp-mcp-ai-pro-rest-slash-commands.php';
					if ( file_exists( $f ) ) {
						require_once $f;
						add_action( 'rest_api_init', array( 'WP_MCP_AI_Pro_REST_Slash_Commands', 'register_routes' ) );
					}
				}
			);

			$this->add_module(
				'spa_loader',
				'SPA Loader',
				array(),
				array( 'context' => 'admin' ),
				function () use ( $p ) {
					$f = $p . 'class-wp-mcp-ai-pro-spa-loader.php';
					if ( file_exists( $f ) ) {
						require_once $f;
						$loader = new WP_MCP_AI_Pro_SPA_Loader();
						$loader->register();
					}
				}
			);

			$this->add_module(
				'inline_assistant',
				'Inline Assistant',
				array(),
				array(),
				function () use ( $p ) {
					$f = $p . 'class-wp-mcp-ai-pro-inline-assistant.php';
					if ( file_exists( $f ) ) {
						require_once $f;
						WP_MCP_AI_Pro_Inline_Assistant::init();
					}
				}
			);

			$this->add_module(
				'parallel_dispatcher',
				'Parallel Model Dispatcher',
				array(),
				array(),
				function () use ( $p ) {
					$f1 = $p . 'class-wp-mcp-ai-pro-parallel-model-dispatcher.php';
					$f2 = $p . 'rest/class-wp-mcp-ai-pro-model-comparison-controller.php';
					if ( file_exists( $f1 ) ) {
						require_once $f1;
					}
					if ( file_exists( $f2 ) ) {
						require_once $f2;
						add_action( 'rest_api_init', array( 'WP_MCP_AI_Pro_Model_Comparison_Controller', 'register_routes' ) );
					}
				}
			);

			$this->add_module(
				'collaborative_presence',
				'Collaborative Presence',
				array(),
				array(),
				function () use ( $p ) {
					$f = $p . 'class-wp-mcp-ai-pro-collaborative-presence.php';
					if ( file_exists( $f ) ) {
						require_once $f;
						WP_MCP_AI_Pro_Collaborative_Presence::init();
					}
				}
			);

			$this->add_module(
				'mcp_apps',
				'MCP Apps',
				array(),
				array(),
				function () use ( $p ) {
					require_once $p . 'mcp-apps/mcp-apps-init.php';
				}
			);

			// ── New Tier 4.5: jetengine_meta_helper (must load before toolkits that call it) ──
			$this->add_module(
				'jetengine_meta_helper',
				'JetEngine Meta Helper',
				array(),
				array(),
				function () use ( $p ) {
					require_once $p . 'class-wp-mcp-ai-jetengine-meta-helper.php';
				}
			);

			// ── Tier 6: Toolkits (conditional on settings) ──────────────
			$this->add_module(
				'toolkit_media',
				'Media Toolkit',
				array( 'jetengine_meta_helper' ),
				array(
					'enabled' => true, // Always loaded per original code (no enable_* guard).
				),
				function () use ( $p ) {
					require_once $p . 'tools/media/init.php';
				}
			);

			$this->add_module(
				'toolkit_project_management',
				'Project Management Toolkit',
				array( 'jetengine_meta_helper' ),
				array(),
				function () use ( $p ) {
					require_once $p . 'tools/project-management/init.php';
				}
			);

			// Ralph orchestration CCTs (load after project management, requires JetEngine).
			$this->add_module(
				'ralph_cct_schemas',
				'Ralph Orchestration CCTs',
				array( 'toolkit_project_management' ),
				array(
					'requires_fn' => array( 'jet_engine' ),
				),
				function () use ( $p ) {
					require_once $p . 'class-wp-mcp-ai-autonomous-sessions-cct.php';
					require_once $p . 'class-wp-mcp-ai-task-plans-cct.php';
					require_once $p . 'class-wp-mcp-ai-execution-history-cct.php';
					require_once $p . 'class-wp-mcp-ai-task-templates-cct.php';
				}
			);

			$this->add_module(
				'pro_orchestration_dashboard',
				'Pro Orchestration Dashboard',
				array(),
				array( 'context' => 'admin' ),
				function () use ( $p ) {
					require_once $p . 'admin/class-wp-mcp-ai-orchestration-dashboard.php';
					new WP_MCP_AI_Orchestration_Dashboard();
				}
			);

			$this->add_module(
				'toolkit_places',
				'Places Toolkit',
				array( 'jetengine_meta_helper' ),
				array(),
				function () use ( $p ) {
					require_once $p . 'tools/places/init.php';
				}
			);

			$this->add_module(
				'toolkit_eca',
				'ECA Management Toolkit',
				array( 'jetengine_meta_helper' ),
				array(),
				function () use ( $p ) {
					require_once $p . 'tools/eca-management/init.php';
				}
			);

			$this->add_module(
				'rest_schedule_result',
				'Schedule Result REST',
				array(),
				array(),
				function () use ( $p ) {
					require_once $p . 'rest/class-wp-mcp-ai-pro-schedule-result-controller.php';
				}
			);

			$this->add_module(
				'rest_schedule_crud',
				'Schedule REST CRUD',
				array(),
				array(),
				function () use ( $p ) {
					require_once $p . 'rest/class-wp-mcp-ai-pro-schedule-rest-controller.php';
					WP_MCP_AI_Pro_Schedule_REST_Controller::init();
				}
			);

			$this->add_module(
				'toolkit_quiz',
				'Quiz Toolkit',
				array( 'jetengine_meta_helper' ),
				array(),
				function () use ( $p ) {
					require_once $p . 'tools/quiz-management/init.php';
				}
			);

			$this->add_module(
				'toolkit_healthcare',
				'Healthcare Toolkit',
				array( 'jetengine_meta_helper' ),
				array(),
				function () use ( $p ) {
					require_once $p . 'tools/healthcare/init.php';
				}
			);

			$this->add_module(
				'toolkit_calendar_booking',
				'Calendar Booking Toolkit',
				array(),
				array(),
				function () use ( $p ) {
					require_once $p . 'tools/calendar-booking/init.php';
				}
			);

			// Booking adapters (interface + factory always; concrete adapters conditionally).
			$this->add_module(
				'booking_adapters',
				'Booking Adapters',
				array(),
				array(),
				function () use ( $p ) {
					$adapters = $p . 'adapters/';
					require_once $adapters . 'interface-wp-mcp-ai-booking-adapter.php';
					require_once $adapters . 'class-wp-mcp-ai-booking-adapter-factory.php';
					if ( function_exists( 'jet_engine' ) ) {
						require_once $adapters . 'class-wp-mcp-ai-jetappointment-adapter.php';
					}
					if ( class_exists( 'Jet_Booking' ) ) {
						require_once $adapters . 'class-wp-mcp-ai-jetbooking-adapter.php';
					}
				}
			);

			// ── Conditional toolkits (gated by enable_* settings) ─────
			$conditional_toolkits = array(
				'ecommerce'               => 'E-commerce',
				'flowhub'                 => 'FlowHub',
				'ezuite'                  => 'EZuite ERP',
				'shopify_sync'            => 'Shopify Sync',
				'social_media'            => 'Social Media',
				'analytics'               => 'Advanced Analytics',
				'multilingual'            => 'Multi-language Content',
				'video_production'        => 'Video Production',
				'cloudways'               => 'Cloudways',
				'financial_planning'      => 'Financial Planning',
				'dj_management'           => 'DJ Management',
				'image_production'        => 'Image Production',
				'comic_creation'          => 'Comic Creation',
				'ai_tool_builder'         => 'AI Tool Builder',
				'architect_agent'         => 'Architect Agent',
				'architectural_design'    => 'Architectural Design',
				'site_creator'            => 'Site Creator',
				'document_generation'     => 'Document Generation',
				'crm'                     => 'CRM',
				'regulatory_registration' => 'Regulatory Registration',
				'cre_debt'                => 'CRE Debt & Securitization',
				'law_firm'                => 'Law Firm',
				'chat_channels'           => 'Chat Channels',
				'dietpi'                  => 'DietPi',
				'extended_cognition'      => 'Extended Cognition',
			);

			foreach ( $conditional_toolkits as $slug => $label ) {
				$key = 'enable_' . $slug . '_toolkit';
				$dir = $slug;
				// Map slugs to actual directory names.
				$dir_map = array(
					'ecommerce'               => 'ecommerce',
					'flowhub'                 => 'flowhub',
					'ezuite'                  => 'erp-ezuite',
					'shopify_sync'            => 'shopify-sync',
					'social_media'            => 'social-media',
					'analytics'               => 'analytics',
					'multilingual'            => 'multilingual',
					'video_production'        => 'video-production',
					'cloudways'               => 'cloudways',
					'financial_planning'      => 'financial-planning',
					'dj_management'           => 'dj-management',
					'image_production'        => 'image-production',
					'comic_creation'          => 'comic-creation',
					'ai_tool_builder'         => 'ai-tool-builder',
					'architect_agent'         => 'architect-agent',
					'architectural_design'    => 'architectural-design',
					'site_creator'            => 'site-creator-toolkit',
					'document_generation'     => 'document-generation',
					'crm'                     => 'crm',
					'regulatory_registration' => 'regulatory-registration',
					'cre_debt'                => 'cre-debt',
					'law_firm'                => 'law-firm',
					'chat_channels'           => 'chat-channels',
					'dietpi'                  => 'dietpi', // Special: uses dietpi-toolkit-init.php.
					'extended_cognition'      => 'extended-cognition',
				);

				$toolkit_dir = isset( $dir_map[ $slug ] ) ? $dir_map[ $slug ] : $slug;
				$enabled     = ! empty( $settings[ $key ] );

				$this->add_module(
					"toolkit_{$slug}",
					"{$label} Toolkit",
					array(),
					array(
						'enabled' => $enabled,
					),
					function () use ( $p, $toolkit_dir, $slug, $settings ) {
						// Special handling for chat_channels (loads webhook controllers after init).
						if ( 'chat_channels' === $slug ) {
							require_once $p . 'tools/chat-channels/init.php';
							require_once $p . 'class-wp-mcp-ai-webhook-context-manager.php';
							require_once $p . 'rest/class-wp-mcp-ai-whatsapp-webhook-controller.php';
							require_once $p . 'rest/class-wp-mcp-ai-messenger-webhook-controller.php';
							require_once $p . 'rest/class-wp-mcp-ai-telegram-webhook-controller.php';
							require_once $p . 'rest/class-wp-mcp-ai-telegram-login-controller.php';
							require_once $p . 'rest/class-wp-mcp-ai-telegram-mini-app-controller.php';
							require_once $p . 'rest/class-wp-mcp-ai-slack-event-controller.php';
							require_once $p . 'rest/class-wp-mcp-ai-discord-interaction-controller.php';
							require_once $p . 'rest/class-wp-mcp-ai-teams-webhook-controller.php';
							require_once $p . 'rest/class-wp-mcp-ai-google-chat-webhook-controller.php';
							require_once $p . 'rest/class-wp-mcp-ai-outlook-webhook-controller.php';
							require_once $p . 'rest/class-wp-mcp-ai-twitter-webhook-controller.php';
							require_once $p . 'rest/class-wp-mcp-ai-apple-messages-webhook-controller.php';
							require_once $p . 'rest/class-wp-mcp-ai-icloud-webhook-controller.php';
							return;
						}

						// Special handling for dietpi (uses dietpi-toolkit-init.php in includes root).
						if ( 'dietpi' === $slug ) {
							require_once $p . 'dietpi-toolkit-init.php';
							return;
						}

						// Special handling for extended_cognition (loads extra classes + AS callbacks).
						if ( 'extended_cognition' === $slug ) {
							require_once $p . 'tools/extended-cognition/init.php';
							require_once $p . 'class-wp-mcp-ai-product-brand-taxonomy.php';
							WP_MCP_AI_Product_Brand_Taxonomy::init();
							require_once $p . 'services/class-wp-mcp-ai-hf-vision-inference-service.php';
							require_once $p . 'tools/extended-cognition/as-callbacks.php';
							add_action( 'init', 'wp_mcp_ai_ext_cog_register_as_hooks', 30 );
							return;
						}

						// Standard toolkit init.
						$init_file = $p . 'tools/' . $toolkit_dir . '/init.php';
						if ( file_exists( $init_file ) ) {
							require_once $init_file;
						}
					}
				);
			}

			// WooCommerce product pages (admin-only, conditional on WC + settings).
			$this->add_module(
				'admin_wc_product_pages',
				'WooCommerce Product Pages',
				array(),
				array(
					'context' => 'admin',
					'enabled' => wp_mcp_ai_pro_is_woocommerce_tools_enabled( $settings ),
				),
				function () use ( $p, $settings ) {
					$is_base = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
					if ( ! $is_base && class_exists( 'WooCommerce' ) ) {
						require_once $p . 'admin/class-wp-mcp-ai-product-research-page.php';
						require_once $p . 'admin/class-wp-mcp-ai-product-settings-page.php';
					}
				}
			);

			// ── Tier 7: Bridges and late-binding services ──────────────
			// Vault (always enabled).
			$this->add_module(
				'toolkit_vault',
				'Password Vault',
				array(),
				array(),
				function () use ( $p ) {
					require_once $p . 'tools/vault/init.php';
				}
			);

			$this->add_module(
				'pro_skills_manager',
				'Skills Manager',
				array(),
				array(),
				function () use ( $p ) {
					require_once $p . 'skills-manager-init.php';
				}
			);

			$this->add_module(
				'pro_harness',
				'Harness Layer H',
				array(),
				array(),
				function () use ( $p ) {
					require_once $p . 'harness-init.php';
				}
			);

			$this->add_module(
				'pro_services_phase6',
				'Services Phase 6',
				array(),
				array(),
				function () use ( $p ) {
					require_once $p . 'services/services-init-phase6.php';
				}
			);

			$this->add_module(
				'pro_nv_cloud',
				'NV Cloud',
				array(),
				array(),
				function () use ( $p ) {
					$f = $p . 'nv-cloud-init.php';
					if ( file_exists( $f ) ) {
						require_once $f;
					} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						error_log( 'WP MCP AI Pro: nv-cloud-init.php not found — NV oOS Cloud features unavailable.' );
					}
				}
			);

			$this->add_module(
				'pro_paper_store',
				'Paper Store Pro',
				array(),
				array(),
				function () use ( $p ) {
					require_once $p . 'paper-store/paper-store-pro-init.php';
				}
			);

			$this->add_module(
				'pro_composio',
				'Composio Connect',
				array(),
				array(),
				function () use ( $p ) {
					require_once $p . 'composio/composio-init.php';
				}
			);

			$this->add_module(
				'pro_workflow_bridge',
				'Workflow Bridge',
				array(),
				array(),
				function () use ( $p ) {
					require_once $p . 'services/class-wp-mcp-ai-pro-workflow-bridge.php';
					add_action( 'init', array( 'WP_MCP_AI_Pro_Workflow_Bridge', 'get_instance' ), 27 );
				}
			);

			$this->add_module(
				'pro_chat_notifier',
				'Chat Continuation Notifier',
				array(),
				array(),
				function () use ( $p ) {
					require_once $p . 'services/class-wp-mcp-ai-pro-chat-continuation-notifier.php';
					WP_MCP_AI_Pro_Chat_Continuation_Notifier::init();
				}
			);

			$this->add_module(
				'pro_toolkit_integration',
				'Toolkit Integration',
				array( 'schedule_manager' ),
				array(),
				function () use ( $p ) {
					require_once $p . 'class-wp-mcp-ai-pro-toolkit-integration.php';
					WP_MCP_AI_Pro_Toolkit_Integration::get_instance();
				}
			);

			$this->add_module(
				'pro_measurement',
				'Pro Measurement',
				array(),
				array(),
				function () use ( $p ) {
					require_once $p . 'measurement/class-wp-mcp-ai-pro-rubric-verifier.php';
					require_once $p . 'measurement/class-wp-mcp-ai-pro-rubric-presets.php';
					require_once $p . 'measurement/class-wp-mcp-ai-pro-budget-guarded-reward.php';
					require_once $p . 'measurement/class-wp-mcp-ai-pro-schedule-metrics.php';
					require_once $p . 'measurement/class-wp-mcp-ai-pro-schedule-otel-subscriber.php';
					require_once $p . 'measurement/class-wp-mcp-ai-pro-measurement-bootstrap.php';
					WP_MCP_AI_Pro_Measurement_Bootstrap::boot();
				}
			);

			$this->add_module(
				'pro_para',
				'PARA Init',
				array(),
				array(),
				function () use ( $p ) {
					require_once $p . 'para/class-wp-mcp-ai-para-init.php';
				}
			);

			$this->add_module(
				'pro_qms',
				'QMS Init',
				array(),
				array(),
				function () use ( $p ) {
					require_once $p . 'qms/class-wp-mcp-ai-qms-init.php';
				}
			);

			// OOS composition subsystem (Proposal 029, Phase 5.2): scoped
			// assistant compositions + composeFrom child binding. Flag-gated
			// (default off); the CLI dump and tests construct the service
			// directly and do not depend on this gate.
			$this->add_module(
				'oos_composition',
				'OOS Composition Service',
				array(),
				array(
					'files'   => array( $p . 'composition/class-wp-mcp-ai-pro-composition-service.php' ),
					'enabled' => (bool) apply_filters( 'wp_mcp_ai_pro_enable_oos_composition', ! empty( $settings['enable_oos_composition'] ) ),
				),
				function () use ( $p ) {
					require_once $p . 'composition/class-wp-mcp-ai-pro-composition.php';
					require_once $p . 'composition/class-wp-mcp-ai-pro-legacy-tool-resolver.php';
					require_once $p . 'composition/class-wp-mcp-ai-pro-composition-service.php';
					require_once $p . 'composition/composition-init.php';
				}
			);
		}
	}
}
