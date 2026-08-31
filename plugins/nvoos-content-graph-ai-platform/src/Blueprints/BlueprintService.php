<?php
/**
 * Blueprints subsystem service.
 *
 * Composition root for the greenfield Blueprints subsystem: admin UI plus
 * the REST CRUD surface. The blueprint classes (registry, validator,
 * exporter, importer, REST controller) are greenfield — no base-plugin
 * counterpart exists, so the wiring is active in every runtime mode
 * (extraction Phase 4).
 *
 * @package NvoosContentGraphAiPlatform\Blueprints
 * @since 2.0.0
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Blueprints;

final class BlueprintService {

	/**
	 * Singleton instance.
	 *
	 * @var BlueprintService|null
	 */
	private static ?self $instance = null;

	/**
	 * Private constructor (singleton).
	 */
	private function __construct() {}

	/**
	 * Get the singleton instance.
	 *
	 * @return BlueprintService
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register the Blueprints subsystem.
	 */
	public function register(): void {
		if ( is_admin() && class_exists( 'NvoosContentGraphAiPlatform\Blueprints\Admin\BlueprintAdmin' ) ) {
			( new \NvoosContentGraphAiPlatform\Blueprints\Admin\BlueprintAdmin() )->register();
		}

		// REST CRUD surface — greenfield, active in every runtime mode.
		( new BlueprintRestController() )->register();
	}

	/**
	 * Prevent cloning (singleton).
	 */
	private function __clone() {}
}
