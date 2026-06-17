<?php
/**
 * Graphify Tool — Content Gaps
 *
 * Identifies thin communities, orphan nodes, and content creation suggestions.
 *
 * @package NV_oOS_Graphify
 * @since   0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool: graphify_content_gaps
 *
 * @since 0.5.0
 */
class NV_oOS_Graphify_Tool_Content_Gaps implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/** {@inheritdoc} */
	public function get_slug() {
		return 'graphify_content_gaps';
	}

	/** {@inheritdoc} */
	public function get_name() {
		return __( 'Content Gaps Analysis', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_description() {
		return __( 'Identify knowledge gaps in the site content: orphan nodes (no connections), thin communities (under-developed topic clusters), high ambiguity in AI-extracted relationships, and hubless communities that lack a strong central piece. Returns actionable content creation suggestions.', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		);
	}

	/** {@inheritdoc} */
	public function get_capability_flags() {
		return array( 'read-only' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$gaps        = NV_oOS_Graphify_Analyzer::get_knowledge_gaps();
		$suggestions = NV_oOS_Graphify_Analyzer::get_recommendations( 10 );
		$surprising  = NV_oOS_Graphify_Analyzer::get_surprising_connections( 5 );

		$summary_parts = array();
		$orphan_count  = count( $gaps['orphans'] );
		$thin_count    = count( $gaps['thin_communities'] );

		if ( $orphan_count > 0 ) {
			$summary_parts[] = sprintf(
				/* translators: %d: orphan count */
				_n( '%d isolated node', '%d isolated nodes', $orphan_count, 'nvoos-graphify' ),
				$orphan_count
			);
		}
		if ( $thin_count > 0 ) {
			$summary_parts[] = sprintf(
				/* translators: %d: thin community count */
				_n( '%d under-developed community', '%d under-developed communities', $thin_count, 'nvoos-graphify' ),
				$thin_count
			);
		}
		if ( $gaps['ambiguity_rate'] > 0.1 ) {
			$summary_parts[] = sprintf(
				/* translators: %s: ambiguity percentage */
				__( '%s ambiguous relationships', 'nvoos-graphify' ),
				round( $gaps['ambiguity_rate'] * 100, 1 ) . '%'
			);
		}

		$summary = empty( $summary_parts )
			? __( 'No significant knowledge gaps found. Graph is well-connected.', 'nvoos-graphify' )
			: implode( ', ', $summary_parts ) . '.';

		return array(
			'success'         => true,
			'gaps'            => $gaps,
			'recommendations' => $suggestions,
			'surprising'      => $surprising,
			'summary'         => $summary,
		);
	}
}
