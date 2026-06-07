<?php
/**
 * Plugin composition root — wires the Platform addon into WordPress.
 *
 * Each subsystem (agents, skills, slash-commands, harness,
 * measurement, professions, A2A, ACP, federation, blueprints)
 * is registered here as it is extracted from the base plugin.
 *
 * @since 1.0.0
 * @package NvoosGraphifyPlatform
 */

declare(strict_types=1);

namespace NvoosGraphifyPlatform;

/**
 * Plugin bootstrap — singleton composition root.
 */
final class Plugin {

	/** @var self|null Singleton instance. */
	private static ?self $instance = null;

	/** Private constructor — use {@see instance()}. */
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
	 * Register all WordPress hooks and wire platform subsystems.
	 *
	 * Called once on plugins_loaded priority 10, after the
	 * AI addon has booted at priority 5.
	 *
	 * @return void
	 */
	public function register(): void {
		// ─── Admin UI ──────────────────────────────────────────
		if ( is_admin() ) {
			$this->registerAdmin();
		}

		// ─── Platform subsystems ──────────────────────────────
		// Each subsystem is registered via its own method below.
		// Subsystems are extracted incrementally from the base
		// plugin's includes/ directory per Priority 2.2.
		//
		// Future subsystems to wire here:
		//   $this->registerAgents();
		//   $this->registerSkills();
		//   $this->registerSlashCommands();
		//   $this->registerHarness();
		//   $this->registerMeasurement();
		//   $this->registerProfessions();
		//   $this->registerA2A();
		//   $this->registerACP();
		//   $this->registerFederation();
		//   $this->registerBlueprints();
	}

	// ───────────────────────────────────────────────────────────────
	// Subsystem registration (progressively filled in per subsystem)
	// ───────────────────────────────────────────────────────────────

	/**
	 * Register admin components.
	 *
	 * @return void
	 */
	private function registerAdmin(): void {
		if ( class_exists( 'NvoosGraphifyPlatform\Admin\PlatformSettings' ) ) {
			( new \NvoosGraphifyPlatform\Admin\PlatformSettings() )->register();
		}
	}

	/** Prevent cloning. */
	private function __clone() {}
}
