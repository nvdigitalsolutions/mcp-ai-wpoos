<?php
/**
 * QMS Controlled Document Record CPT.
 *
 * Represents a controlled-document instance. Distinct from `mcp_ai_doc_tpl`
 * (template) — a record is the physical, hashable, signable artifact released
 * to the organization.
 *
 * Post meta keys (per the implementation plan):
 *   _qms_document_id       Immutable controlled-doc ID, e.g. "SOP-001".
 *   _qms_revision          Semantic revision, e.g. "1.0", "1.1", "2.0".
 *   _qms_status            draft|in_review|approved|released|superseded|obsolete.
 *   _qms_owner_id          User ID of the document owner.
 *   _qms_reviewer_ids      Array of user IDs.
 *   _qms_approver_ids      Array of user IDs.
 *   _qms_effective_date    Y-m-d the document took effect.
 *   _qms_next_review_date  Y-m-d when the document must be reviewed next.
 *   _qms_retention_years   Integer retention period.
 *   _qms_disposition       archive|destroy.
 *   _qms_external_origin   Array { source, identifier } or empty.
 *   _qms_change_reason     Per-revision change reason.
 *   _qms_change_summary    Per-revision change summary.
 *   _qms_signatures        Array of signature objects.
 *   _qms_content_hash      SHA-256 of the controlled content.
 *   _qms_artifact_attachment_id Optional attachment ID for generated PDF/Word.
 *   _qms_template_id       Source template post ID.
 *   _qms_supersedes        Post ID of the superseded record.
 *   _qms_superseded_by     Post ID of the new record that supersedes this one.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controlled document CPT registration.
 */
class WP_MCP_AI_QMS_Doc_Record_CPT {

	const POST_TYPE = 'mcp_ai_doc_record';

	const STATUS_DRAFT      = 'draft';
	const STATUS_IN_REVIEW  = 'in_review';
	const STATUS_APPROVED   = 'approved';
	const STATUS_RELEASED   = 'released';
	const STATUS_SUPERSEDED = 'superseded';
	const STATUS_OBSOLETE   = 'obsolete';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 10 );
		add_action( 'init', array( __CLASS__, 'register_meta' ), 11 );
	}

	/**
	 * Get all valid statuses.
	 *
	 * @return array<int,string>
	 */
	public static function get_statuses() {
		return array(
			self::STATUS_DRAFT,
			self::STATUS_IN_REVIEW,
			self::STATUS_APPROVED,
			self::STATUS_RELEASED,
			self::STATUS_SUPERSEDED,
			self::STATUS_OBSOLETE,
		);
	}

	/**
	 * Register the CPT.
	 */
	public static function register() {
		if ( ! WP_MCP_AI_QMS_Capabilities::is_enabled() ) {
			return;
		}
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => array(
					'name'               => __( 'Controlled Documents', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => __( 'Controlled Document', 'mcp-ai-wpoos-pro' ),
					'add_new'            => __( 'Add New', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add Controlled Document', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit Controlled Document', 'mcp-ai-wpoos-pro' ),
					'view_item'          => __( 'View Controlled Document', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search Controlled Documents', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No controlled documents found', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No controlled documents found in trash', 'mcp-ai-wpoos-pro' ),
					'menu_name'          => __( 'QMS Register', 'mcp-ai-wpoos-pro' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => 'edit.php?post_type=mcp_ai_doc_tpl',
				'show_in_rest'       => true,
				'has_archive'        => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'supports'           => array( 'title', 'editor', 'author', 'revisions' ),
				'menu_icon'          => 'dashicons-shield-alt',
			)
		);
	}

	/**
	 * Register post meta for REST exposure.
	 */
	public static function register_meta() {
		if ( ! WP_MCP_AI_QMS_Capabilities::is_enabled() ) {
			return;
		}

		$single_strings = array(
			'_qms_document_id'      => 'string',
			'_qms_revision'         => 'string',
			'_qms_status'           => 'string',
			'_qms_effective_date'   => 'string',
			'_qms_next_review_date' => 'string',
			'_qms_disposition'      => 'string',
			'_qms_change_reason'    => 'string',
			'_qms_change_summary'   => 'string',
			'_qms_content_hash'     => 'string',
		);
		foreach ( $single_strings as $key => $type ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'show_in_rest'  => true,
					'single'        => true,
					'type'          => $type,
					'auth_callback' => function () {
						return current_user_can( WP_MCP_AI_QMS_Capabilities::CAP );
					},
				)
			);
		}

		$single_ints = array(
			'_qms_owner_id'               => 'integer',
			'_qms_retention_years'        => 'integer',
			'_qms_artifact_attachment_id' => 'integer',
			'_qms_template_id'            => 'integer',
			'_qms_supersedes'             => 'integer',
			'_qms_superseded_by'          => 'integer',
		);
		foreach ( $single_ints as $key => $type ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'show_in_rest'  => true,
					'single'        => true,
					'type'          => $type,
					'auth_callback' => function () {
						return current_user_can( WP_MCP_AI_QMS_Capabilities::CAP );
					},
				)
			);
		}
	}

	/**
	 * Get a controlled document as an array.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function get_record( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		return array(
			'id'               => (int) $post->ID,
			'title'            => $post->post_title,
			'content'          => $post->post_content,
			'document_id'      => (string) get_post_meta( $post->ID, '_qms_document_id', true ),
			'revision'         => (string) get_post_meta( $post->ID, '_qms_revision', true ),
			'status'           => (string) get_post_meta( $post->ID, '_qms_status', true ),
			'owner_id'         => (int) get_post_meta( $post->ID, '_qms_owner_id', true ),
			'reviewer_ids'     => (array) ( get_post_meta( $post->ID, '_qms_reviewer_ids', true ) ? get_post_meta( $post->ID, '_qms_reviewer_ids', true ) : array() ),
			'approver_ids'     => (array) ( get_post_meta( $post->ID, '_qms_approver_ids', true ) ? get_post_meta( $post->ID, '_qms_approver_ids', true ) : array() ),
			'effective_date'   => (string) get_post_meta( $post->ID, '_qms_effective_date', true ),
			'next_review_date' => (string) get_post_meta( $post->ID, '_qms_next_review_date', true ),
			'retention_years'  => (int) get_post_meta( $post->ID, '_qms_retention_years', true ),
			'disposition'      => (string) get_post_meta( $post->ID, '_qms_disposition', true ),
			'external_origin'  => (array) ( get_post_meta( $post->ID, '_qms_external_origin', true ) ? get_post_meta( $post->ID, '_qms_external_origin', true ) : array() ),
			'change_reason'    => (string) get_post_meta( $post->ID, '_qms_change_reason', true ),
			'change_summary'   => (string) get_post_meta( $post->ID, '_qms_change_summary', true ),
			'signatures'       => (array) ( get_post_meta( $post->ID, '_qms_signatures', true ) ? get_post_meta( $post->ID, '_qms_signatures', true ) : array() ),
			'content_hash'     => (string) get_post_meta( $post->ID, '_qms_content_hash', true ),
			'template_id'      => (int) get_post_meta( $post->ID, '_qms_template_id', true ),
			'supersedes'       => (int) get_post_meta( $post->ID, '_qms_supersedes', true ),
			'superseded_by'    => (int) get_post_meta( $post->ID, '_qms_superseded_by', true ),
		);
	}

	/**
	 * Compute and persist the content hash for a record.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function recompute_hash( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$payload = wp_json_encode(
			array(
				'title'       => $post->post_title,
				'content'     => $post->post_content,
				'document_id' => get_post_meta( $post_id, '_qms_document_id', true ),
				'revision'    => get_post_meta( $post_id, '_qms_revision', true ),
			)
		);
		$hash    = hash( 'sha256', (string) $payload );
		update_post_meta( $post_id, '_qms_content_hash', $hash );
		return $hash;
	}
}
