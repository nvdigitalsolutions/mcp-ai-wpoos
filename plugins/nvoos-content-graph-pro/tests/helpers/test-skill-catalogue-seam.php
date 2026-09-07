<?php
/**
 * Testable subclass of the ported skill-catalogue service.
 *
 * Exposes the protected per-mode registry seam. Only loadable in the
 * standalone matrix — the monolith matrix provides the base Pro addon's
 * copy without the seam method. Tests require this file inside methods
 * after their standalone-mode skip guard.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphPro\Tests;

if ( ! class_exists( __NAMESPACE__ . '\Test_Skill_Catalogue_Seam' ) ) {
	/**
	 * Test seam for WP_MCP_AI_Skill_Catalogue_Service.
	 */
	class Test_Skill_Catalogue_Seam extends \WP_MCP_AI_Skill_Catalogue_Service {

		/**
		 * Expose registry_class().
		 *
		 * @return string
		 */
		// phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod -- Test seam widening a protected member.
		public static function registry_class() {
			return parent::registry_class();
		}
	}
}
