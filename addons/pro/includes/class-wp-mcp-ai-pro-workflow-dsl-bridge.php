<?php
/**
 * Pro workflow DSL bridge — Proposal 091, Track A (Pro side).
 *
 * Bridges the base plugin's DSL seams:
 *
 *   - `wp_mcp_ai_workflow_preset_export` — resolves a bundled preset slug to
 *     a canonical workflow array so presets export directly (no save-first).
 *   - `wp_mcp_ai_workflow_dify_import` — best-effort Dify workflow DSL
 *     adapter (decision D1: one-way import only, honest degradation via
 *     `foreign` stub nodes).
 *
 * Side-effect free on load (filters only), like the presets registry it
 * wraps, so the module registry requires it on every request.
 *
 * @package WP_MCP_AI
 * @since   1.2.13
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro workflow DSL bridge.
 *
 * @since 1.2.13
 */
class WP_MCP_AI_Pro_Workflow_DSL_Bridge {

	/**
	 * Resolve a preset slug to a canonical workflow array.
	 *
	 * @since 1.2.13
	 *
	 * @param array|null $workflow    Workflow array or null (unresolved).
	 * @param string     $preset_slug Preset slug.
	 * @return array|null Canonical workflow array, or null when unknown.
	 */
	public static function resolve_preset( $workflow, $preset_slug ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Workflow_Presets' ) ) {
			return $workflow;
		}

		$preset = WP_MCP_AI_Pro_Workflow_Presets::get_preset( $preset_slug );
		if ( null === $preset ) {
			return $workflow;
		}

		return self::normalize_preset( $preset );
	}

	/**
	 * Normalize a preset definition into the canonical workflow shape.
	 *
	 * @since 1.2.13
	 *
	 * @param array $preset Preset definition array.
	 * @return array Canonical workflow array.
	 */
	private static function normalize_preset( array $preset ) {
		$nodes = array();
		foreach ( ( isset( $preset['nodes'] ) && is_array( $preset['nodes'] ) ) ? $preset['nodes'] : array() as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$data = isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : array();

			$nodes[] = array(
				'id'     => isset( $node['id'] ) ? (string) $node['id'] : '',
				'type'   => isset( $node['type'] ) ? sanitize_key( (string) $node['type'] ) : 'action',
				'label'  => isset( $data['label'] ) ? sanitize_text_field( (string) $data['label'] ) : '',
				'params' => isset( $data['arguments'] ) && is_array( $data['arguments'] ) ? $data['arguments'] : array(),
			);
		}

		$edges = array();
		foreach ( ( isset( $preset['edges'] ) && is_array( $preset['edges'] ) ) ? $preset['edges'] : array() as $edge ) {
			if ( ! is_array( $edge ) || empty( $edge['source'] ) || empty( $edge['target'] ) ) {
				continue;
			}
			$edges[] = array(
				'source' => (string) $edge['source'],
				'target' => (string) $edge['target'],
			);
		}

		return array(
			'name'        => isset( $preset['name'] ) ? sanitize_text_field( (string) $preset['name'] ) : '',
			'description' => isset( $preset['description'] ) ? sanitize_text_field( (string) $preset['description'] ) : '',
			'version'     => '1.0.0',
			'tags'        => ( isset( $preset['tags'] ) && is_array( $preset['tags'] ) ) ? $preset['tags'] : array(),
			'graph'       => array(
				'nodes' => $nodes,
				'edges' => $edges,
			),
		);
	}

	/**
	 * Adapt a Dify workflow DSL to the canonical workflow array (best-effort).
	 *
	 * Dify node kinds are mapped onto our vocabulary where possible; anything
	 * unmappable becomes a `foreign` action stub carrying the original node
	 * payload so no information is silently dropped (decision D1).
	 *
	 * @since 1.2.13
	 *
	 * @param array|null $canonical Canonical workflow array or null (no adapter).
	 * @param array      $dify_dsl  Dify DSL payload.
	 * @return array|null Canonical workflow array, or null to signal no adapter.
	 */
	public static function adapt_dify( $canonical, $dify_dsl ) {
		if ( ! is_array( $dify_dsl ) ) {
			return $canonical;
		}

		$app      = isset( $dify_dsl['app'] ) && is_array( $dify_dsl['app'] ) ? $dify_dsl['app'] : array();
		$workflow = isset( $dify_dsl['workflow'] ) && is_array( $dify_dsl['workflow'] ) ? $dify_dsl['workflow'] : array();
		$graph    = isset( $workflow['graph'] ) && is_array( $workflow['graph'] ) ? $workflow['graph'] : array();

		$type_map = array(
			'llm'                 => 'tool',
			'knowledge-retrieval' => 'tool',
			'http-request'        => 'tool',
			'code'                => 'tool',
			'tool'                => 'tool',
			'template-transform'  => 'action',
			'if-else'             => 'action',
			'iteration'           => 'action',
			'question-classifier' => 'action',
			'start'               => 'input',
			'end'                 => 'output',
			'answer'              => 'output',
		);

		$nodes = array();
		foreach ( ( isset( $graph['nodes'] ) && is_array( $graph['nodes'] ) ) ? $graph['nodes'] : array() as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$data      = isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : array();
			$dify_type = isset( $data['type'] ) ? (string) $data['type'] : 'unknown';
			$type      = isset( $type_map[ $dify_type ] ) ? $type_map[ $dify_type ] : null;

			$entry = array(
				'id'     => isset( $node['id'] ) ? (string) $node['id'] : '',
				'type'   => null === $type ? 'action' : $type,
				'label'  => isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : $dify_type,
				'params' => array(
					'dify_data' => $data,
				),
			);

			if ( null === $type ) {
				$entry['foreign']             = true;
				$entry['params']['dify_type'] = $dify_type;
			}

			$nodes[] = $entry;
		}

		$edges = array();
		foreach ( ( isset( $graph['edges'] ) && is_array( $graph['edges'] ) ) ? $graph['edges'] : array() as $edge ) {
			if ( ! is_array( $edge ) || empty( $edge['source'] ) || empty( $edge['target'] ) ) {
				continue;
			}
			$edges[] = array(
				'source' => (string) $edge['source'],
				'target' => (string) $edge['target'],
			);
		}

		return array(
			'name'        => isset( $app['name'] ) ? sanitize_text_field( (string) $app['name'] ) : __( 'Imported Dify workflow', 'mcp-ai-wpoos-pro' ),
			'description' => isset( $app['description'] ) ? sanitize_text_field( (string) $app['description'] ) : '',
			'version'     => '1.0.0',
			'tags'        => array( 'dify-import' ),
			'graph'       => array(
				'nodes' => $nodes,
				'edges' => $edges,
			),
		);
	}
}

add_filter( 'wp_mcp_ai_workflow_preset_export', array( 'WP_MCP_AI_Pro_Workflow_DSL_Bridge', 'resolve_preset' ), 10, 2 );
add_filter( 'wp_mcp_ai_workflow_dify_import', array( 'WP_MCP_AI_Pro_Workflow_DSL_Bridge', 'adapt_dify' ), 10, 2 );
