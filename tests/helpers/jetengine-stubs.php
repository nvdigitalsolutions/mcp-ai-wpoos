<?php
/**
 * Shared JetEngine stubs for the single-process unit test run.
 *
 * Only ONE definition of these global symbols may exist per process, so every
 * suite that needs a JetEngine mock requires this file instead of declaring
 * its own `Jet_Engine` / `jet_engine()` stubs. Suites install their own mock
 * instance via wp_mcp_ai_jetengine_stub_set_instance() in setUp() and reset it
 * with wp_mcp_ai_jetengine_stub_reset() in tearDown(). Suites that need the
 * ABSENCE of JetEngine must gate on the JET_ENGINE_VERSION constant — the real
 * plugin's load marker — because the shared function exists for the whole run.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! class_exists( 'Jet_Engine_Modules' ) ) {
	/**
	 * Mock JetEngine Modules class.
	 */
	class Jet_Engine_Modules {
		/**
		 * Activated module IDs.
		 *
		 * @var array
		 */
		private $active_modules = array();

		/**
		 * Registered modules keyed by module ID.
		 *
		 * @var array
		 */
		private $modules = array();

		/**
		 * Constructor.
		 */
		public function __construct() {
			// Mock data stores module.
			$this->modules['data-stores'] = new stdClass();
		}

		/**
		 * Whether a module is active.
		 *
		 * @param string $module_id Module ID.
		 * @return bool
		 */
		public function is_module_active( $module_id ) {
			return in_array( $module_id, $this->active_modules, true );
		}

		/**
		 * Retrieve a module instance.
		 *
		 * @param string $module_id Module ID.
		 * @return object|false
		 */
		public function get_module( $module_id ) {
			return isset( $this->modules[ $module_id ] ) ? $this->modules[ $module_id ] : false;
		}

		/**
		 * Activate a module.
		 *
		 * @param string $module Module ID.
		 */
		public function activate_module( $module ) {
			if ( ! in_array( $module, $this->active_modules, true ) ) {
				$this->active_modules[] = $module;
			}
		}

		/**
		 * List activated modules.
		 *
		 * @return array
		 */
		public function get_active_modules() {
			return $this->active_modules;
		}
	}
}

if ( ! class_exists( 'Jet_Engine' ) ) {
	/**
	 * Mock JetEngine class (superset shape: `$api` + `$modules`).
	 */
	class Jet_Engine {
		/**
		 * REST API mock slot.
		 *
		 * @var object|null
		 */
		public $api;

		/**
		 * Modules manager mock.
		 *
		 * @var Jet_Engine_Modules
		 */
		public $modules;

		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->modules = new Jet_Engine_Modules();
		}
	}
}

$wp_mcp_ai_shared_jetengine_stub = null;

if ( ! function_exists( 'jet_engine' ) ) {
	/**
	 * Mock jet_engine() accessor returning the currently installed stub.
	 *
	 * @return Jet_Engine
	 */
	function jet_engine() {
		global $wp_mcp_ai_shared_jetengine_stub;

		if ( null === $wp_mcp_ai_shared_jetengine_stub ) {
			$wp_mcp_ai_shared_jetengine_stub = new Jet_Engine();
		}

		return $wp_mcp_ai_shared_jetengine_stub;
	}
}

if ( ! function_exists( 'wp_mcp_ai_jetengine_stub_set_instance' ) ) {
	/**
	 * Install the current suite's mock instance.
	 *
	 * @param object $instance JetEngine mock instance.
	 */
	function wp_mcp_ai_jetengine_stub_set_instance( $instance ) {
		$GLOBALS['wp_mcp_ai_shared_jetengine_stub'] = $instance;
	}
}

if ( ! function_exists( 'wp_mcp_ai_jetengine_stub_reset' ) ) {
	/**
	 * Reset the shared stub so jet_engine() lazily recreates a fresh one.
	 */
	function wp_mcp_ai_jetengine_stub_reset() {
		$GLOBALS['wp_mcp_ai_shared_jetengine_stub'] = null;
	}
}
