<?php
/**
 * Workflow DSL codec — Proposal 091, Track A.
 *
 * Upgrades the existing raw JSON workflow export/import
 * (WP_MCP_AI_Workflow_CPT::export_json()/import_json()) into a versioned DSL:
 *
 *   - `dsl_version: 1` marks the NV oOS DSL envelope (the legacy
 *     `schema_version: "1.0"` string shape remains importable).
 *   - Validation produces an actionable findings list (errors block, warnings
 *     do not) instead of silent partial imports.
 *   - Credential-bearing node params are redacted on export by default.
 *   - Dry-run imports return the findings + canonical preview without writing.
 *
 * The codec operates on the same portable workflow array the CPT methods
 * already use (`{ name, description, version, tags, graph: { nodes, edges } }`),
 * so no storage migration is required.
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
 * Workflow DSL codec.
 *
 * @since 1.2.13
 */
class WP_MCP_AI_Workflow_DSL_Codec {

	/**
	 * NV oOS DSL envelope version.
	 *
	 * @since 1.2.13
	 * @var int
	 */
	const DSL_VERSION = 1;

	/**
	 * Engine V2 node type vocabulary.
	 *
	 * @since 1.2.13
	 * @var string[]
	 */
	const NODE_TYPES = array( 'tool', 'action', 'agent' );

	/**
	 * Param keys treated as credential-bearing (redacted on export).
	 *
	 * @since 1.2.13
	 * @var string
	 */
	const CREDENTIAL_PATTERN = '/(credential|api_key|api-key|token|secret|password)/i';

	/**
	 * Redaction placeholder (matches the proposal's `‹redacted›`).
	 *
	 * @since 1.2.13
	 * @var string
	 */
	const REDACTED = '‹redacted›';

	/**
	 * Encode a canonical workflow array into the DSL v1 envelope.
	 *
	 * Credential-bearing node params are redacted unless
	 * `$options['include_credentials']` is true (WP-CLI
	 * `--include-credentials` / REST `include_credentials`).
	 *
	 * @since 1.2.13
	 *
	 * @param array $workflow Canonical workflow array (CPT export shape).
	 * @param array $options  Optional flags: `include_credentials` (bool).
	 * @return array|WP_Error DSL envelope array, or WP_Error on invalid input.
	 */
	public static function encode_workflow( array $workflow, array $options = array() ) {
		$workflow = self::canonicalize( $workflow );
		$findings = self::validate_workflow( $workflow );
		if ( self::has_blockers( $findings ) ) {
			return new WP_Error(
				'wp_mcp_ai_workflow_dsl_invalid',
				__( 'Workflow failed validation and cannot be exported.', 'mcp-ai-wpoos' ),
				array(
					'status'   => 422,
					'findings' => $findings,
				)
			);
		}

		$include_credentials = ! empty( $options['include_credentials'] );
		if ( ! $include_credentials ) {
			$workflow = self::redact_credentials( $workflow );
		}

		return array_merge(
			$workflow,
			array(
				'dsl_version' => self::DSL_VERSION,
				'exported_at' => gmdate( 'c' ),
			)
		);
	}

	/**
	 * Decode a DSL envelope (or a legacy raw export) into a canonical array.
	 *
	 * Accepts both the DSL v1 envelope (`dsl_version` present) and the legacy
	 * `schema_version: "1.0"` shape so pre-upgrade exports keep importing
	 * (backward-compatibility gate).
	 *
	 * @since 1.2.13
	 *
	 * @param array $dsl     DSL envelope or legacy export array.
	 * @param bool  $dry_run When true, returns findings + canonical preview
	 *                       without requiring blockers to be absent.
	 * @return array|WP_Error Canonical workflow array, or WP_Error carrying
	 *                        `findings` when blockers exist.
	 */
	public static function decode_workflow( array $dsl, $dry_run = false ) {
		$dsl      = self::canonicalize( $dsl );
		$findings = self::validate_workflow( $dsl );

		if ( self::has_blockers( $findings ) && ! $dry_run ) {
			return new WP_Error(
				'wp_mcp_ai_workflow_dsl_invalid',
				__( 'Workflow DSL failed validation.', 'mcp-ai-wpoos' ),
				array(
					'status'   => 422,
					'findings' => $findings,
				)
			);
		}

		return array_merge( $dsl, array( 'findings' => $findings ) );
	}

	/**
	 * Accept a legacy raw export array (alias of decode_workflow for the
	 * backward-compatibility import path).
	 *
	 * @since 1.2.13
	 *
	 * @param array $json Legacy export array.
	 * @return array|WP_Error Canonical workflow array, or WP_Error.
	 */
	public static function accept_legacy_json( array $json ) {
		return self::decode_workflow( $json );
	}

	/**
	 * Validate a canonical workflow array.
	 *
	 * @since 1.2.13
	 *
	 * @param array $workflow Canonical workflow array.
	 * @return array[] Findings: `{ path, message, severity: error|warning }`.
	 */
	public static function validate_workflow( array $workflow ) {
		$findings = array();

		if ( empty( $workflow['name'] ) ) {
			$findings[] = array(
				'path'     => 'name',
				'message'  => __( 'Workflow name is required.', 'mcp-ai-wpoos' ),
				'severity' => 'error',
			);
		}

		$graph = isset( $workflow['graph'] ) && is_array( $workflow['graph'] ) ? $workflow['graph'] : array();
		$nodes = isset( $graph['nodes'] ) && is_array( $graph['nodes'] ) ? $graph['nodes'] : array();
		$edges = isset( $graph['edges'] ) && is_array( $graph['edges'] ) ? $graph['edges'] : array();

		if ( empty( $nodes ) ) {
			$findings[] = array(
				'path'     => 'graph.nodes',
				'message'  => __( 'Workflow graph has no nodes.', 'mcp-ai-wpoos' ),
				'severity' => 'warning',
			);
		}

		$node_ids = array();
		foreach ( $nodes as $index => $node ) {
			$path = 'graph.nodes[' . $index . ']';

			if ( ! is_array( $node ) ) {
				$findings[] = array(
					'path'     => $path,
					'message'  => __( 'Node entry must be an object.', 'mcp-ai-wpoos' ),
					'severity' => 'error',
				);
				continue;
			}

			if ( empty( $node['id'] ) ) {
				$findings[] = array(
					'path'     => $path . '.id',
					'message'  => __( 'Node id is required.', 'mcp-ai-wpoos' ),
					'severity' => 'error',
				);
			} else {
				$node_ids[] = (string) $node['id'];
			}

			$type = isset( $node['type'] ) ? (string) $node['type'] : '';
			if ( ! in_array( $type, self::NODE_TYPES, true ) ) {
				$findings[] = array(
					'path'     => $path . '.type',
					'message'  => sprintf(
						/* translators: 1: node type, 2: allowed types. */
						__( 'Unsupported node type "%1$s" (allowed: %2$s).', 'mcp-ai-wpoos' ),
						$type,
						implode( ', ', self::NODE_TYPES )
					),
					'severity' => 'error',
				);
			}
		}

		foreach ( $edges as $index => $edge ) {
			$path = 'graph.edges[' . $index . ']';
			if ( ! is_array( $edge ) || empty( $edge['source'] ) || empty( $edge['target'] ) ) {
				$findings[] = array(
					'path'     => $path,
					'message'  => __( 'Edge requires source and target.', 'mcp-ai-wpoos' ),
					'severity' => 'error',
				);
				continue;
			}
			if ( ! in_array( (string) $edge['source'], $node_ids, true ) || ! in_array( (string) $edge['target'], $node_ids, true ) ) {
				$findings[] = array(
					'path'     => $path,
					'message'  => __( 'Edge references a node that does not exist.', 'mcp-ai-wpoos' ),
					'severity' => 'warning',
				);
			}
		}

		return $findings;
	}

	/**
	 * Mask credential-bearing node params (and nested `args` objects).
	 *
	 * @since 1.2.13
	 *
	 * @param array $workflow Canonical workflow array.
	 * @return array Redacted workflow array.
	 */
	public static function redact_credentials( array $workflow ) {
		if ( ! isset( $workflow['graph']['nodes'] ) || ! is_array( $workflow['graph']['nodes'] ) ) {
			return $workflow;
		}

		$redacted_any = false;
		foreach ( $workflow['graph']['nodes'] as $index => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$redacted_node = array();
			foreach ( $node as $key => $value ) {
				if ( is_array( $value ) ) {
					$value = self::redact_array( $value, $redacted_any );
				} elseif ( is_string( $key ) && preg_match( self::CREDENTIAL_PATTERN, $key ) ) {
					$value        = self::REDACTED;
					$redacted_any = true;
				}
				$redacted_node[ $key ] = $value;
			}
			$workflow['graph']['nodes'][ $index ] = $redacted_node;
		}

		if ( $redacted_any ) {
			$workflow['redacted'] = true;
		}

		return $workflow;
	}

	/**
	 * Recursively redact credential keys inside nested arrays.
	 *
	 * @since 1.2.13
	 *
	 * @param array $values  Array to redact.
	 * @param bool  $changed Whether any value was redacted (by reference).
	 * @return array Redacted array.
	 */
	private static function redact_array( array $values, &$changed ) {
		foreach ( $values as $key => $value ) {
			if ( is_array( $value ) ) {
				$values[ $key ] = self::redact_array( $value, $changed );
			} elseif ( is_string( $key ) && preg_match( self::CREDENTIAL_PATTERN, $key ) ) {
				$values[ $key ] = self::REDACTED;
				$changed        = true;
			}
		}
		return $values;
	}

	/**
	 * Canonicalize a workflow array: keep only known envelope keys, ensure
	 * the graph array has nodes/edges lists, and drop transport-only fields.
	 *
	 * @since 1.2.13
	 *
	 * @param array $workflow Workflow array.
	 * @return array Canonical workflow array.
	 */
	private static function canonicalize( array $workflow ) {
		$graph = isset( $workflow['graph'] ) && is_array( $workflow['graph'] ) ? $workflow['graph'] : array();

		$canonical = array(
			'name'        => isset( $workflow['name'] ) ? sanitize_text_field( $workflow['name'] ) : '',
			'description' => isset( $workflow['description'] ) ? wp_kses_post( $workflow['description'] ) : '',
			'version'     => isset( $workflow['version'] ) ? sanitize_text_field( $workflow['version'] ) : '1.0.0',
			'tags'        => array(),
			'graph'       => array(
				'nodes' => isset( $graph['nodes'] ) && is_array( $graph['nodes'] ) ? $graph['nodes'] : array(),
				'edges' => isset( $graph['edges'] ) && is_array( $graph['edges'] ) ? $graph['edges'] : array(),
			),
		);

		if ( isset( $workflow['tags'] ) && is_array( $workflow['tags'] ) ) {
			foreach ( $workflow['tags'] as $tag ) {
				$canonical['tags'][] = sanitize_text_field( $tag );
			}
		}

		return $canonical;
	}

	/**
	 * Whether the findings list contains blocking (error-severity) items.
	 *
	 * @since 1.2.13
	 *
	 * @param array[] $findings Findings list.
	 * @return bool
	 */
	private static function has_blockers( array $findings ) {
		foreach ( $findings as $finding ) {
			if ( 'error' === $finding['severity'] ) {
				return true;
			}
		}
		return false;
	}
}
