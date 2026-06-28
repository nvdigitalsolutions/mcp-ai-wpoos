<?php
/**
 * Financial Logic Visualizer Tool
 *
 * Generates Mermaid diagram markup for financial transmission chains,
 * decision trees, impact flows, and correlation maps.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for generating Mermaid diagrams of financial logic flows.
 *
 * Supports:
 * - Transmission chains (cause → effect flows)
 * - Decision trees (investment decisions with conditions)
 * - Impact flows (event → multi-sector impacts)
 * - Correlation maps (instrument relationship diagrams)
 * - Configurable direction and node styling
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Financial_Logic_Visualizer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if financial planner toolkit is enabled.
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_financial_planner_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_financial_planner_toolkit'] ) ) {
			return __( 'Financial planner toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Financial logic visualizer tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'financial_logic_visualizer';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Financial Logic Visualizer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Generate Mermaid diagram markup for financial transmission chains, decision trees, impact flows, and correlation maps. Visualize cause-effect relationships, investment decision paths, and instrument correlations. EDUCATIONAL ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'chain_type'  => array(
					'type'        => 'string',
					'description' => __( 'Type of diagram to generate.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'transmission_chain', 'decision_tree', 'impact_flow', 'correlation_map' ),
				),
				'nodes'       => array(
					'type'        => 'array',
					'description' => __( 'Array of diagram nodes.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'    => array(
								'type'        => 'string',
								'description' => __( 'Unique node identifier.', 'mcp-ai-wpoos-pro' ),
							),
							'label' => array(
								'type'        => 'string',
								'description' => __( 'Display label for the node.', 'mcp-ai-wpoos-pro' ),
							),
							'type'  => array(
								'type'        => 'string',
								'description' => __( 'Node type for styling.', 'mcp-ai-wpoos-pro' ),
								'enum'        => array( 'event', 'factor', 'outcome', 'decision', 'risk' ),
							),
						),
					),
				),
				'connections' => array(
					'type'        => 'array',
					'description' => __( 'Array of connections between nodes.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'from'     => array(
								'type'        => 'string',
								'description' => __( 'Source node ID.', 'mcp-ai-wpoos-pro' ),
							),
							'to'       => array(
								'type'        => 'string',
								'description' => __( 'Target node ID.', 'mcp-ai-wpoos-pro' ),
							),
							'label'    => array(
								'type'        => 'string',
								'description' => __( 'Connection label.', 'mcp-ai-wpoos-pro' ),
							),
							'strength' => array(
								'type'        => 'string',
								'description' => __( 'Connection strength.', 'mcp-ai-wpoos-pro' ),
								'enum'        => array( 'strong', 'moderate', 'weak' ),
							),
						),
					),
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Diagram title.', 'mcp-ai-wpoos-pro' ),
				),
				'direction'   => array(
					'type'        => 'string',
					'description' => __( 'Diagram direction.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'TD', 'LR', 'BT', 'RL' ),
					'default'     => 'TD',
				),
			),
			'required'   => array( 'chain_type', 'nodes', 'connections' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'computation',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @since 1.1.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use the logic visualizer.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$chain_type  = isset( $arguments['chain_type'] ) ? sanitize_text_field( $arguments['chain_type'] ) : '';
		$nodes       = isset( $arguments['nodes'] ) && is_array( $arguments['nodes'] ) ? $arguments['nodes'] : array();
		$connections = isset( $arguments['connections'] ) && is_array( $arguments['connections'] ) ? $arguments['connections'] : array();
		$title       = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$direction   = isset( $arguments['direction'] ) ? sanitize_text_field( $arguments['direction'] ) : 'TD';

		$valid_chain_types = array( 'transmission_chain', 'decision_tree', 'impact_flow', 'correlation_map' );
		if ( ! in_array( $chain_type, $valid_chain_types, true ) ) {
			return new WP_Error(
				'invalid_chain_type',
				__( 'Invalid chain type. Must be one of: transmission_chain, decision_tree, impact_flow, correlation_map.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $nodes ) ) {
			return new WP_Error( 'empty_nodes', __( 'At least one node is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $connections ) ) {
			return new WP_Error( 'empty_connections', __( 'At least one connection is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$valid_directions = array( 'TD', 'LR', 'BT', 'RL' );
		if ( ! in_array( $direction, $valid_directions, true ) ) {
			$direction = 'TD';
		}

		// Sanitize nodes.
		$sanitized_nodes = array();
		foreach ( $nodes as $node ) {
			$node_id    = isset( $node['id'] ) ? sanitize_text_field( $node['id'] ) : '';
			$node_label = isset( $node['label'] ) ? sanitize_text_field( $node['label'] ) : '';
			$node_type  = isset( $node['type'] ) ? sanitize_text_field( $node['type'] ) : 'factor';

			if ( empty( $node_id ) || empty( $node_label ) ) {
				continue;
			}

			$valid_types = array( 'event', 'factor', 'outcome', 'decision', 'risk' );
			if ( ! in_array( $node_type, $valid_types, true ) ) {
				$node_type = 'factor';
			}

			$sanitized_nodes[ $node_id ] = array(
				'id'    => $node_id,
				'label' => $node_label,
				'type'  => $node_type,
			);
		}

		// Sanitize connections.
		$sanitized_connections = array();
		foreach ( $connections as $conn ) {
			$from     = isset( $conn['from'] ) ? sanitize_text_field( $conn['from'] ) : '';
			$to       = isset( $conn['to'] ) ? sanitize_text_field( $conn['to'] ) : '';
			$label    = isset( $conn['label'] ) ? sanitize_text_field( $conn['label'] ) : '';
			$strength = isset( $conn['strength'] ) ? sanitize_text_field( $conn['strength'] ) : 'moderate';

			if ( empty( $from ) || empty( $to ) ) {
				continue;
			}

			$valid_strengths = array( 'strong', 'moderate', 'weak' );
			if ( ! in_array( $strength, $valid_strengths, true ) ) {
				$strength = 'moderate';
			}

			$sanitized_connections[] = array(
				'from'     => $from,
				'to'       => $to,
				'label'    => $label,
				'strength' => $strength,
			);
		}

		if ( empty( $sanitized_nodes ) || empty( $sanitized_connections ) ) {
			return new WP_Error(
				'invalid_diagram_data',
				__( 'No valid nodes or connections found after sanitization.', 'mcp-ai-wpoos-pro' )
			);
		}

		$mermaid_code = $this->generate_mermaid( $chain_type, $sanitized_nodes, $sanitized_connections, $direction, $title );

		return array(
			'success'          => true,
			'diagram_type'     => $chain_type,
			'mermaid_code'     => $mermaid_code,
			'node_count'       => count( $sanitized_nodes ),
			'connection_count' => count( $sanitized_connections ),
			'direction'        => $direction,
			'title'            => $title,
			'disclaimer'       => __( 'EDUCATIONAL ONLY. Financial logic diagrams are simplified representations and may not capture all market dynamics. Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Generate Mermaid diagram syntax.
	 *
	 * @since 1.1.0
	 *
	 * @param string $chain_type  Diagram type.
	 * @param array  $nodes       Sanitized nodes.
	 * @param array  $connections Sanitized connections.
	 * @param string $direction   Diagram direction.
	 * @param string $title       Diagram title.
	 * @return string Mermaid diagram code.
	 */
	private function generate_mermaid( $chain_type, $nodes, $connections, $direction, $title ) {
		$lines = array();

		// Add title if provided.
		if ( ! empty( $title ) ) {
			$lines[] = '---';
			$lines[] = 'title: ' . $title;
			$lines[] = '---';
		}

		// Start flowchart.
		$lines[] = 'flowchart ' . $direction;
		$lines[] = '';

		// Add node definitions with type-based shapes.
		foreach ( $nodes as $node ) {
			$shape   = $this->get_node_shape( $node['type'], $chain_type );
			$lines[] = '    ' . $node['id'] . $shape['open'] . $node['label'] . $shape['close'];
		}

		$lines[] = '';

		// Add connections with appropriate arrow styles.
		foreach ( $connections as $conn ) {
			$arrow = $this->get_arrow_style( $conn['strength'] );

			if ( ! empty( $conn['label'] ) ) {
				$lines[] = '    ' . $conn['from'] . ' ' . $arrow . '|' . $conn['label'] . '| ' . $conn['to'];
			} else {
				$lines[] = '    ' . $conn['from'] . ' ' . $arrow . ' ' . $conn['to'];
			}
		}

		$lines[] = '';

		// Add CSS class definitions for node types.
		$lines[] = '    %% Style definitions';
		$lines[] = '    classDef event fill:#e1f5fe,stroke:#0288d1,stroke-width:2px,color:#01579b';
		$lines[] = '    classDef factor fill:#fff3e0,stroke:#f57c00,stroke-width:2px,color:#e65100';
		$lines[] = '    classDef outcome fill:#e8f5e9,stroke:#388e3c,stroke-width:2px,color:#1b5e20';
		$lines[] = '    classDef decision fill:#f3e5f5,stroke:#7b1fa2,stroke-width:2px,color:#4a148c';
		$lines[] = '    classDef risk fill:#ffebee,stroke:#d32f2f,stroke-width:2px,color:#b71c1c';

		// Assign classes to nodes.
		$type_groups = array();
		foreach ( $nodes as $node ) {
			$type_groups[ $node['type'] ][] = $node['id'];
		}

		foreach ( $type_groups as $type => $ids ) {
			$lines[] = '    class ' . implode( ',', $ids ) . ' ' . $type;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Get Mermaid node shape characters based on node type.
	 *
	 * @since 1.1.0
	 *
	 * @param string $type       Node type.
	 * @param string $chain_type Diagram type for context.
	 * @return array Array with 'open' and 'close' shape characters.
	 */
	private function get_node_shape( $type, $chain_type ) {
		switch ( $type ) {
			case 'event':
				// Rounded rectangle for events.
				return array(
					'open'  => '(',
					'close' => ')',
				);

			case 'decision':
				// Diamond for decisions.
				return array(
					'open'  => '{',
					'close' => '}',
				);

			case 'outcome':
				// Double brackets for outcomes.
				return array(
					'open'  => '[[',
					'close' => ']]',
				);

			case 'risk':
				// Hexagon for risks.
				return array(
					'open'  => '{{',
					'close' => '}}',
				);

			case 'factor':
			default:
				// Standard rectangle for factors.
				return array(
					'open'  => '[',
					'close' => ']',
				);
		}
	}

	/**
	 * Get Mermaid arrow style based on connection strength.
	 *
	 * @since 1.1.0
	 *
	 * @param string $strength Connection strength.
	 * @return string Mermaid arrow syntax.
	 */
	private function get_arrow_style( $strength ) {
		switch ( $strength ) {
			case 'strong':
				return '==>';
			case 'weak':
				return '-.->';
			case 'moderate':
			default:
				return '-->';
		}
	}
}
