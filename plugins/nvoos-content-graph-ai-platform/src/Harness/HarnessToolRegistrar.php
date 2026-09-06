<?php
/**
 * Harness tool registrar (D8 Cluster 3).
 *
 * Registers the eight ported harness tools into the Content Graph AI
 * core tool registry in standalone installs, so `tools/list` and
 * `tools/call` surface `evolve_harness`, `apply_prompt_cue`,
 * `list_prompt_cues`, `record_reflection`, `retrieve_with_provenance`,
 * `scope_memory`, `select_prompt_cue`, and `self_consistency_vote`.
 *
 * Monolith installs: the base plugin owns the same slugs through its
 * own registry — this registrar is a documented no-op there (mirrors
 * CoreToolFactory's per-mode contract).
 *
 * @package NvoosContentGraphAiPlatform\Harness
 * @since   2.0.0
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness;

use NvoosContentGraphAiPlatform\Harness\Tools\ApplyPromptCueTool;
use NvoosContentGraphAiPlatform\Harness\Tools\EvolveHarnessTool;
use NvoosContentGraphAiPlatform\Harness\Tools\ListPromptCuesTool;
use NvoosContentGraphAiPlatform\Harness\Tools\RecordReflectionTool;
use NvoosContentGraphAiPlatform\Harness\Tools\RetrieveWithProvenanceTool;
use NvoosContentGraphAiPlatform\Harness\Tools\ScopeMemoryTool;
use NvoosContentGraphAiPlatform\Harness\Tools\SelectPromptCueTool;
use NvoosContentGraphAiPlatform\Harness\Tools\SelfConsistencyVoteTool;

/**
 * Registers the harness tool surface standalone.
 *
 * @since 2.0.0
 */
final class HarnessToolRegistrar {

	/**
	 * Register every harness tool into the CG-AI core registry.
	 *
	 * Additive and defensive: slugs already present (AI tools, graph
	 * tools, base-registry surface in monolith) are never overridden.
	 *
	 * @return int Number of tools newly registered.
	 */
	public static function register(): int {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the base registry owns the same surface.
			return 0;
		}

		if ( ! class_exists( 'NvoosContentGraphAi\CoreBridge' ) ) {
			return 0;
		}

		$bridge = \NvoosContentGraphAi\CoreBridge::instance();

		$classes = array(
			ApplyPromptCueTool::class,
			ListPromptCuesTool::class,
			RecordReflectionTool::class,
			RetrieveWithProvenanceTool::class,
			ScopeMemoryTool::class,
			SelectPromptCueTool::class,
			SelfConsistencyVoteTool::class,
			EvolveHarnessTool::class,
		);

		$registered = 0;

		foreach ( $classes as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			$tool = new $class( $bridge->errors );

			if ( $bridge->tools->has( $tool->getSlug() ) ) {
				continue;
			}

			$bridge->tools->register( $tool );
			++$registered;
		}

		if ( $registered > 0 ) {
			$bridge->tools->notifyRegistered();
		}

		return $registered;
	}
}
