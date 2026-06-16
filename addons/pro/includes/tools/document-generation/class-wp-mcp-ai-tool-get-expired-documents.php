<?php
/**
 * Tool for retrieving expired documents from the document generation system.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Document_Generation_Toolkit
 * @since 2.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves documents that have passed their expiry date.
 *
 * Queries document template posts (mcp_ai_doc_tpl) whose expiry date
 * meta has already passed, optionally filtered by document type.
 *
 * @since 2.9.0
 */
class WP_MCP_AI_Tool_Get_Expired_Documents implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_expired_documents';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Expired Documents', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves documents that have passed their expiry date, optionally filtered by document type. Returns document details including expiry date, type, and associated metadata.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'document_type'    => array(
					'type'        => 'string',
					'description' => __( 'Filter by document type slug (e.g. "policy", "procedure", "certificate").', 'mcp-ai-wpoos-pro' ),
				),
				'days_past_expiry' => array(
					'type'        => 'integer',
					'description' => __( 'Only return documents expired at least this many days ago. Default: 0 (all expired).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
					'minimum'     => 0,
				),
				'limit'            => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of documents to return. Default: 100.', 'mcp-ai-wpoos-pro' ),
					'default'     => 100,
					'minimum'     => 1,
					'maximum'     => 1000,
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'document_generation',
			'post_type'             => 'mcp_ai_doc_tpl',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'administrator', 'document_manager' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
			'local-only',
			'requires-capability',
			'cacheable',
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * Requires the Document Generation Toolkit to be enabled in plugin settings.
	 *
	 * @since 2.9.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_document_generation_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.9.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Get Expired Documents tool requires the Document Generation Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Expired documents result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_document_generation_toolkit'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Document Generation Toolkit is not enabled. Please enable it in Settings → NV oOS → Tools & Features.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$document_type    = isset( $arguments['document_type'] ) ? sanitize_text_field( $arguments['document_type'] ) : '';
		$days_past_expiry = isset( $arguments['days_past_expiry'] ) ? absint( $arguments['days_past_expiry'] ) : 0;
		$limit            = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 100;
		$limit            = min( max( $limit, 1 ), 1000 );

		$today = gmdate( 'Y-m-d' );

		$query_args = array(
			'post_type'      => 'mcp_ai_doc_tpl',
			'posts_per_page' => $limit,
			'post_status'    => 'publish',
			'orderby'        => 'meta_value',
			'meta_key'       => '_document_expiry_date',
			'order'          => 'ASC',
			'meta_query'     => array(
				array(
					'key'     => '_document_expiry_date',
					'value'   => $today,
					'compare' => '<=',
					'type'    => 'DATE',
				),
			),
		);

		if ( ! empty( $document_type ) ) {
			$query_args['meta_query'][] = array(
				'key'   => '_document_type',
				'value' => $document_type,
			);
		}

		// Filter by minimum days past expiry.
		if ( $days_past_expiry > 0 ) {
			$cutoff_date = gmdate( 'Y-m-d', strtotime( "-{$days_past_expiry} days" ) );
			$query_args['meta_query'][0]['value'] = $cutoff_date;
		}

		$documents = array();
		$query     = new WP_Query( $query_args );

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$expiry_date = get_post_meta( $post->ID, '_document_expiry_date', true );
				$doc_type    = get_post_meta( $post->ID, '_document_type', true );
				$documents[] = array(
					'id'          => $post->ID,
					'title'       => get_the_title( $post ),
					'type'        => $doc_type ? $doc_type : '',
					'expiry_date' => $expiry_date ? $expiry_date : '',
					'status'      => get_post_status( $post ),
					'edit_url'    => get_edit_post_link( $post->ID, 'raw' ),
				);
			}
		}

		wp_reset_postdata();

		return array(
			'success'        => true,
			'message'        => sprintf(
				/* translators: %d: number of expired documents found */
				__( 'Found %d expired documents.', 'mcp-ai-wpoos-pro' ),
				count( $documents )
			),
			'total_count'    => count( $documents ),
			'documents'      => $documents,
			'query_date'     => $today,
			'days_past_cutoff' => $days_past_expiry,
		);
	}
}
