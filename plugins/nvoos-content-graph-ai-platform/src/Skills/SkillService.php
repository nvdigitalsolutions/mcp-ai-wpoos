<?php
/**
 * Skills subsystem — skill registry, parser, and pack management.
 *
 * Extracted from the base plugin's:
 * - `includes/class-wp-mcp-ai-skill-registry.php`
 * - `includes/class-wp-mcp-ai-skill-parser.php`
 * - `includes/class-wp-mcp-ai-skill-pack-registry.php`
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\Skills
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Skills;

/**
 * Skills service — wires skill management into the Platform addon.
 */
final class SkillService {

	/** @var self|null */
	private static ?self $instance = null;

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register skill-related hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( is_admin() ) {
			$this->registerAdmin();
		}
	}

	private function registerAdmin(): void {
		if ( class_exists( 'NvoosContentGraphAiPlatform\Skills\Admin\SkillsAdmin' ) ) {
			( new \NvoosContentGraphAiPlatform\Skills\Admin\SkillsAdmin() )->register();
		}
	}

	/** Prevent cloning. */
	private function __clone() {}
}
