<?php
declare(strict_types=1);

namespace NvoosGraphify\Graph;

/**
 * Semantic extractor — AI-free structural-only stub.
 *
 * In the standalone core plugin, semantic extraction (entity recognition,
 * topic modeling, AI-powered content analysis) is deferred to the
 * `nvoos-graphify-ai` addon. This stub class provides:
 *
 *   1. A predictable class-exists target for the Builder and Plugin
 *      composition root so they don't break when discovery checks run.
 *   2. Public static method signatures that accept the same arguments
 *      as the full implementation, returning empty arrays so the build
 *      pipeline continues cleanly.
 *
 * When an AI provider addon (OpenAI, Gemini, Ollama, etc.) is active
 * and loaded before the Graphify core, it can replace this stub via a
 * filter or by registering its own implementation ahead of time.
 *
 * @since 1.0.0
 */
class SemanticExtractor {

	/**
	 * Extract semantic nodes and edges from posts.
	 *
	 * @param array $posts   Array of WP_Post objects.
	 * @param bool  $async   Whether to dispatch to WP Cron (unused in stub).
	 * @return array{nodes: array, edges: array}
	 */
	public static function extract( array $posts, bool $async = false ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- stub; implementation deferred to addon
		return array(
			'nodes' => array(),
			'edges' => array(),
		);
	}

	/**
	 * Extract semantic nodes and edges from JetEngine CCT items.
	 *
	 * @param array $ccts CCT rows from Detector::detectCcts().
	 * @param bool  $async Whether to dispatch to WP Cron.
	 * @return array{nodes: array, edges: array}
	 */
	public static function extractCcts( array $ccts, bool $async = false ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- stub; implementation deferred to addon
		return array(
			'nodes' => array(),
			'edges' => array(),
		);
	}

	/**
	 * Extract semantic nodes and edges from external table rows.
	 *
	 * @param array $externalRows Rows from external table detection.
	 * @param bool  $async        Whether to dispatch to WP Cron.
	 * @return array{nodes: array, edges: array}|null
	 */
	public static function extractExternal( array $externalRows, bool $async = false ): ?array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- stub; implementation deferred to addon
		return array(
			'nodes' => array(),
			'edges' => array(),
		);
	}
}
