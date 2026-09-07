<?php
/**
 * Plugin bootstrap — wires the Pro addon into WordPress.
 *
 * The Pro addon keeps the base Pro addon's architecture: every subsystem
 * loads through the ported `WP_MCP_AI_Pro_Module_Registry` (global class
 * name, byte-identical public surface). `Plugin::register()` only boots the
 * registry — each module's factory wires its own CPTs, REST routes, tools,
 * and hooks, exactly like the monolith.
 *
 * @package NvoosContentGraphPro
 * @since   1.0.0
 */

declare(strict_types=1);

namespace NvoosContentGraphPro;

/**
 * Pro addon composition root.
 *
 * @since 1.0.0
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Prevent direct construction.
	 */
	private function __construct() {}

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register the addon — boots the ported Pro module registry.
	 *
	 * Idempotent: the registry guards its own boot with a
	 * `$bootstrapped` flag, so repeated `register()` calls are safe.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( class_exists( 'WP_MCP_AI_Pro_Module_Registry' ) ) {
			\WP_MCP_AI_Pro_Module_Registry::get_instance()->boot();
		}
	}

	/**
	 * Prevent cloning.
	 *
	 * @return void
	 */
	public function __clone() {
		throw new \Exception( 'Cannot clone singleton' );
	}

	/**
	 * Prevent unserialization.
	 *
	 * @return void
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton' );
	}
}
