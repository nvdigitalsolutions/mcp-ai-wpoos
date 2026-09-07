<?php
/**
 * Pro Module Registry (ecosystem port — Wave F1, sub-cluster 1).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/class-wp-mcp-ai-pro-module-registry.php` for the
 * standalone `nvoos-content-graph-pro` addon. The global class name is kept
 * byte-identical so ecosystem consumers see the same public surface; in
 * monolith installs the base Pro addon owns the class (this file is only
 * loaded standalone — see the plugin entry's `WP_MCP_AI_PRO_PATH` guard).
 *
 * Documented deviations from the monolith copy:
 *
 * 1. `declare(strict_types=1)` added; `boot()` coerces a non-array
 *    `wp_mcp_ai_settings` option to `array()` before `define_modules()`
 *    (the monolith relies on weak typing here).
 * 2. `final` dropped; constructor, `__clone`, `$modules`, `$loaded`,
 *    `$bootstrapped`, `add_module()`, `req()`, `check_*()`,
 *    `resolve_order()`, and `define_modules()` are `protected` instead of
 *    `private` — test-exposure seams (same deviation as prior waves).
 * 3. `define_modules()` defines only the Wave F1 pro-core modules
 *    (privacy, toolkit data-store factory, skills manager, vault, vector
 *    storage). F2–F7 modules land with their waves. Two F1 modules are new
 *    standalone entries with no monolith registry counterpart:
 *    - `toolkit_data_store`: the monolith loads the data-store factory
 *      on-demand from consumers; standalone loads it eagerly so every
 *      ported consumer can rely on `class_exists()`.
 *    - `vector_storage`: the monolith registers the prepare-file tool via
 *      `wp_mcp_ai_pro_register_tools()`; standalone owns it as a module.
 * 4. File paths resolve from `NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/'`
 *    instead of `WP_MCP_AI_PRO_PATH . 'includes/'`.
 * 5. Additive public accessors: `get_modules()`, `is_bootstrapped()`.
 *
 * @package NvoosContentGraphPro
 * @since   1.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Pro_Module_Registry' ) ) {
	/**
	 * Registry for Pro addon modules.
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_Pro_Module_Registry {

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
		protected $modules = array();

		/**
		 * Set of module IDs that have been loaded.
		 *
		 * @var array<string, bool>
		 */
		protected $loaded = array();

		/**
		 * Whether boot() has already run.
		 *
		 * @var bool
		 */
		protected $bootstrapped = false;

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
		 * Prevent direct construction (test doubles subclass and call the
		 * protected constructor directly — deviation 2).
		 */
		protected function __construct() {}

		/**
		 * Prevent cloning.
		 */
		protected function __clone() {}

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
			if ( ! is_array( $settings ) ) {
				// Strict-types coercion guard — deviation 1.
				$settings = array();
			}
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
		protected function check_context( array $mod ) {
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
		protected function check_required_classes( array $mod ) {
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
		protected function check_required_functions( array $mod ) {
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
		protected function check_required_files( array $mod ) {
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
		protected function debug_log( array $mod, $reason ) {
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
		protected function resolve_order() {
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

		/**
		 * Get every registered module descriptor (additive accessor —
		 * deviation 5).
		 *
		 * @return array<string, array>
		 */
		public function get_modules(): array {
			return $this->modules;
		}

		/**
		 * Whether boot() has already run (additive accessor — deviation 5).
		 *
		 * @return bool
		 */
		public function is_bootstrapped(): bool {
			return $this->bootstrapped;
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
		protected function add_module( $id, $label, array $deps, array $options, callable $factory ) {
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
		protected function req( $path ) {
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		/**
		 * Define the Wave F1 pro-core modules (deviation 3).
		 *
		 * The monolith registry defines ~79 modules across 7 tiers; the
		 * standalone addon starts with the F1 subset and grows with each
		 * port wave (F2–F7). Every factory carries a `files` guard so
		 * `boot()` degrades gracefully while a module's files are still in
		 * flight.
		 *
		 * @param array $settings Plugin settings.
		 * @return void
		 */
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature kept byte-identical with the monolith registry; F2–F7 module definitions will consume the settings.
		protected function define_modules( array $settings ) {
			$p = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/';

			// ── F1: pro-core ──────────────────────────────────────────
			$this->add_module(
				'privacy',
				'Privacy API',
				array(),
				array( 'files' => array( $p . 'class-wp-mcp-ai-pro-privacy.php' ) ),
				function () use ( $p ) {
					require_once $p . 'class-wp-mcp-ai-pro-privacy.php';
					WP_MCP_AI_Pro_Privacy::init();
				}
			);

			$this->add_module(
				'toolkit_data_store',
				'Toolkit Data Store Factory',
				array(),
				array(
					'files' => array(
						$p . 'interfaces/interface-wp-mcp-ai-toolkit-data-store.php',
						$p . 'class-wp-mcp-ai-tenant-repository.php',
						$p . 'class-wp-mcp-ai-toolkit-data-store-factory.php',
						$p . 'data-stores/class-wp-mcp-ai-toolkit-cct-store.php',
						$p . 'data-stores/class-wp-mcp-ai-toolkit-cpt-store.php',
					),
				),
				function () use ( $p ) {
					require_once $p . 'interfaces/interface-wp-mcp-ai-toolkit-data-store.php';
					require_once $p . 'class-wp-mcp-ai-tenant-repository.php';
					require_once $p . 'class-wp-mcp-ai-toolkit-data-store-factory.php';
					require_once $p . 'data-stores/class-wp-mcp-ai-toolkit-cct-store.php';
					require_once $p . 'data-stores/class-wp-mcp-ai-toolkit-cpt-store.php';
				}
			);

			$this->add_module(
				'pro_skills_manager',
				'Skills Manager',
				array(),
				array( 'files' => array( $p . 'skills-manager-init.php' ) ),
				function () use ( $p ) {
					require_once $p . 'skills-manager-init.php';
				}
			);

			$this->add_module(
				'toolkit_vault',
				'Password Vault',
				array(),
				array( 'files' => array( $p . 'tools/vault/init.php' ) ),
				function () use ( $p ) {
					require_once $p . 'tools/vault/init.php';
				}
			);

			$this->add_module(
				'vector_storage',
				'Vector Storage',
				array(),
				array(
					'files' => array(
						$p . 'services/class-wp-mcp-ai-vector-store-adapter.php',
					),
				),
				function () use ( $p ) {
					require_once $p . 'services/class-wp-mcp-ai-vector-store-adapter.php';
					// The prepare-file tool stays monolith-registered via
					// wp_mcp_ai_pro_register_tools(); standalone it loads once
					// the D8 wave ports the base tool infrastructure
					// (WP_MCP_AI_Tool_Interface + the chat-response trait +
					// WP_MCP_AI_File_Preprocessing_Helper) out of includes/.
				}
			);

			// ── F2: pro-business toolkits ────────────────────────────
			$this->add_module(
				'toolkit_crm',
				'CRM Toolkit',
				array(),
				array(
					// Byte-identical enabled gate (enable_crm_toolkit setting).
					'enabled' => ! empty( $settings['enable_crm_toolkit'] ),
					'files'   => array( $p . 'tools/crm/init.php' ),
				),
				function () use ( $p ) {
					require_once $p . 'tools/crm/init.php';
				}
			);
		}
	}
}
