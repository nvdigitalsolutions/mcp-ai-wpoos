<?php
/**
 * Tool for suggesting internal links based on knowledge graph analysis.
 *
 * Analyzes content relationships and recommends internal links that
 * would strengthen the site's content structure and improve SEO.
 *
 * @package NV_oOS_Graphify
 * @since   0.2.0
 * @author  NV Digital Solutions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Suggest Links Tool.
 *
 * Uses the Graphify analyzer to identify knowledge gaps and recommend
 * internal links between content that should be connected.
 *
 * @since 0.2.0
 */
class NV_oOS_Graphify_Tool_Suggest_Links implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'graphify_suggest_links';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Suggest Internal Links', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( "Analyze knowledge gaps and suggest internal links to add to strengthen the site's content structure.", 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'limit' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of link suggestions to return. Defaults to 20, maximum 50.', 'mcp-ai-wpoos' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 50,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',
			'local-only',
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 0.2.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'knowledge_graph',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'content_strategist', 'seo_specialist', 'editor' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $arguments Parsed arguments from the assistant.
	 * @param array $context   Contextual data about the request.
	 * @return array|WP_Error Result array on success, WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to view link suggestions.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! NV_oOS_Graphify::is_enabled() ) {
			return new WP_Error(
				'graphify_disabled',
				__( 'The Graphify addon is not enabled.', 'mcp-ai-wpoos' )
			);
		}

		$limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 20;
		$limit = max( 1, min( 50, $limit ) );

		$recommendations = NV_oOS_Graphify_Analyzer::get_content_recommendations();

		if ( is_wp_error( $recommendations ) ) {
			return $recommendations;
		}

		// Filter to link-type suggestions only.
		$link_suggestions = array();
		if ( is_array( $recommendations ) ) {
			foreach ( $recommendations as $rec ) {
				$type = isset( $rec['type'] ) ? $rec['type'] : '';
				if ( 'link' !== $type && 'internal_link' !== $type ) {
					continue;
				}
				$link_suggestions[] = array(
					'source_post' => isset( $rec['source_post'] ) ? $rec['source_post'] : '',
					'target_post' => isset( $rec['target_post'] ) ? $rec['target_post'] : '',
					'reason'      => isset( $rec['reason'] ) ? $rec['reason'] : '',
					'confidence'  => isset( $rec['confidence'] ) ? $rec['confidence'] : '',
				);

				if ( count( $link_suggestions ) >= $limit ) {
					break;
				}
			}
		}

		if ( empty( $link_suggestions ) ) {
			return array(
				'success' => true,
				'message' => __( 'No link suggestions found. The content graph may already be well-connected.', 'mcp-ai-wpoos' ),
				'data'    => array(
					'limit'       => $limit,
					'suggestions' => array(),
				),
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of link suggestions */
				__( 'Found %d internal link suggestions.', 'mcp-ai-wpoos' ),
				count( $link_suggestions )
			),
			'data'    => array(
				'limit'       => $limit,
				'suggestions' => $link_suggestions,
			),
		);
	}
}
