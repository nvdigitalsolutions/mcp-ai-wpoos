<?php
/**
 * RuntimeMode degradation-notice contract tests.
 *
 * With extraction Waves A–C complete, every planned subsystem is ported
 * into the Platform addon (src/) — the bridged-subsystem list is empty in
 * BOTH matrices, and only the greenfield Blueprints subsystem is reported
 * missing. The assistant CPT stays in the base plugin by plan decision
 * (⏸️ Deferred) and is intentionally not reported here.
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

	public function test_unavailable_subsystems_empty_once_blueprints_lands(): void {
		// Phase 4 landed the greenfield Blueprints subsystem — with every
		// planned subsystem implemented, the notice list is empty in both
		// matrices.
		$missing = RuntimeMode::unavailable_subsystems();

		$this->assertSame( array(), $missing );
	}

	public function test_extracted_subsystems_never_reported_missing(): void {
		$missing = RuntimeMode::unavailable_subsystems();

		$this->assertNotContains( 'A2A', $missing );
		$this->assertNotContains( 'ACP', $missing );
		$this->assertNotContains( 'Federation', $missing );
		$this->assertNotContains( 'Teams', $missing );
		$this->assertNotContains( 'Professions', $missing );
		$this->assertNotContains( 'Skills', $missing );
		$this->assertNotContains( 'Slash Commands', $missing );
		$this->assertNotContains( 'Harness', $missing );
		$this->assertNotContains( 'Measurement', $missing );
		$this->assertNotContains( 'Agents', $missing );
	}

	public function test_only_blueprints_reported_in_standalone_mode(): void {
		$this->markTestSkipped( 'Blueprints landed in Phase 4 — no subsystem is reported missing in any matrix.' );
	}

	public function test_only_blueprints_reported_in_monolith_mode(): void {
		$this->markTestSkipped( 'Blueprints landed in Phase 4 — no subsystem is reported missing in any matrix.' );
	}
}
