<?php
/**
 * RuntimeMode degradation-notice contract tests.
 *
 * The assertions adapt to the loaded matrix:
 *  - Monolith mode (base plugin booted — WP_MCP_AI_PATH defined): all
 *    bridged probes resolve, so only the greenfield Blueprints subsystem is
 *    reported missing.
 *  - Standalone mode (base plugin absent): all bridged subsystems are
 *    reported missing. Run the suite with WP_MCP_AI_PLATFORM_STANDALONE=1
 *    to exercise this matrix (extraction plan Phase 0, CI matrix).
 *
 * Note: the matrix discriminator is defined('WP_MCP_AI_PATH'), not
 * class_exists() — the monorepo root autoloader can map base-plugin classes
 * to disk without the plugin ever booting, which would produce false
 * positives in the standalone matrix.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\RuntimeMode;

/**
 * @group runtime-mode
 */
class Test_Platform_RuntimeMode extends \WP_UnitTestCase {

	public function test_unavailable_subsystems_lists_blueprints_until_built(): void {
		$missing = RuntimeMode::unavailable_subsystems();

		$this->assertContains( 'Blueprints', $missing );
	}

	public function test_a2a_is_never_reported_missing_after_extraction(): void {
		$missing = RuntimeMode::unavailable_subsystems();

		$this->assertNotContains( 'A2A', $missing );
	}

	public function test_bridged_subsystems_resolve_when_base_plugin_is_loaded(): void {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Base plugin not loaded — standalone matrix.' );
		}

		$missing = RuntimeMode::unavailable_subsystems();

		$this->assertNotContains( 'Agents', $missing );
		$this->assertNotContains( 'Skills', $missing );
		$this->assertNotContains( 'Slash Commands', $missing );
		$this->assertNotContains( 'Harness', $missing );
		$this->assertNotContains( 'Measurement', $missing );
		$this->assertNotContains( 'Professions', $missing );
	}

	public function test_bridged_subsystems_all_missing_in_standalone_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Base plugin loaded — monolith matrix.' );
		}

		$missing = RuntimeMode::unavailable_subsystems();

		$expected = array(
			'Agents',
			'Skills',
			'Slash Commands',
			'Harness',
			'Measurement',
			'Professions',
			'Blueprints',
		);
		foreach ( $expected as $subsystem ) {
			$this->assertContains( $subsystem, $missing );
		}
	}
}
