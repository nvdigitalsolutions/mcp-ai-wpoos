<?php
/**
 * Testable subclass of the ported Pro privacy service.
 *
 * Exposes the protected helpers (deviation 3 of the ported class). Only
 * loadable in the standalone matrix — the monolith matrix provides the base
 * Pro addon's copy with `private` helpers, which cannot be extended. Tests
 * require this file inside methods after their standalone-mode skip guard.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphPro\Tests;

if ( ! class_exists( __NAMESPACE__ . '\Test_Pro_Privacy_Seam' ) ) {
	/**
	 * Test seam for WP_MCP_AI_Pro_Privacy.
	 */
	class Test_Pro_Privacy_Seam extends \WP_MCP_AI_Pro_Privacy {

		/**
		 * Expose get_health_cpt_map().
		 *
		 * @return array<string,string>
		 */
		public static function health_map(): array {
			return self::get_health_cpt_map();
		}

		/**
		 * Expose delete_directory_recursively().
		 *
		 * @param string $dir Absolute path to directory.
		 * @return void
		 */
		public static function delete_dir( $dir ): void {
			self::delete_directory_recursively( $dir );
		}
	}
}
