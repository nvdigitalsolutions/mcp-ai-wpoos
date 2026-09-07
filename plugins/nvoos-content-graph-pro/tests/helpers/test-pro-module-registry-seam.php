<?php
/**
 * Testable subclass of the ported Pro module registry.
 *
 * Exposes the protected seams (deviation 2 of the ported registry) so the
 * characterization tests can drive module definitions, ordering, and the
 * context/requires gates without touching the real singleton.
 *
 * Only loadable in the standalone matrix — the monolith matrix provides the
 * base Pro addon's `final` registry, which cannot be subclassed. Tests
 * require this file inside methods after their standalone-mode skip guard.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphPro\Tests;

if ( ! class_exists( __NAMESPACE__ . '\Test_Pro_Module_Registry_Seam' ) ) {
	/**
	 * Test seam for WP_MCP_AI_Pro_Module_Registry.
	 */
	class Test_Pro_Module_Registry_Seam extends \WP_MCP_AI_Pro_Module_Registry {

		/**
		 * Build a fresh, non-singleton instance.
		 *
		 * @return self
		 */
		public static function make(): self {
			return new self();
		}

		/**
		 * Expose define_modules().
		 *
		 * @param array $settings Plugin settings.
		 * @return void
		 */
		public function define_test_modules( array $settings ): void {
			$this->define_modules( $settings );
		}

		/**
		 * Expose add_module().
		 *
		 * @param string   $id      Module ID.
		 * @param string   $label   Label.
		 * @param string[] $deps    Dependency IDs.
		 * @param array    $options Options.
		 * @param callable $factory Factory.
		 * @return void
		 */
		public function add( string $id, string $label, array $deps, array $options, callable $factory ): void {
			$this->add_module( $id, $label, $deps, $options, $factory );
		}

		/**
		 * Expose the module descriptor table.
		 *
		 * @return array<string, array>
		 */
		public function modules(): array {
			return $this->get_modules();
		}

		/**
		 * Expose resolve_order().
		 *
		 * @return string[]
		 */
		public function ordered(): array {
			return $this->resolve_order();
		}

		/**
		 * Expose check_context().
		 *
		 * @param array $mod Module descriptor.
		 * @return bool
		 */
		public function context_ok( array $mod ): bool {
			return $this->check_context( $mod );
		}

		/**
		 * Expose check_required_classes().
		 *
		 * @param array $mod Module descriptor.
		 * @return bool
		 */
		public function classes_ok( array $mod ): bool {
			return $this->check_required_classes( $mod );
		}

		/**
		 * Expose check_required_functions().
		 *
		 * @param array $mod Module descriptor.
		 * @return bool
		 */
		public function functions_ok( array $mod ): bool {
			return $this->check_required_functions( $mod );
		}

		/**
		 * Expose check_required_files().
		 *
		 * @param array $mod Module descriptor.
		 * @return bool
		 */
		public function files_ok( array $mod ): bool {
			return $this->check_required_files( $mod );
		}
	}
}
