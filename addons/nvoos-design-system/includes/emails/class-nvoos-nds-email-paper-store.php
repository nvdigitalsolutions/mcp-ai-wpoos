<?php
/**
 * NV oOS Design System — Paper Store Template Bridge
 *
 * Mirrors email templates into the Paper Store knowledge base (collection
 * "email-templates") and imports records back as draft templates. Enables
 * cross-site template reuse through Paper Store's existing remote-proxy
 * support and search tools.
 *
 * Every Paper Store call is gated on the manager class existing, so the
 * addon keeps working standalone (without the NV oOS base plugin).
 *
 * @package NV_oOS_Design_System
 * @since   0.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Paper Store bridge for email templates.
 *
 * @since 0.3.0
 */
class NV_oOS_Design_System_Email_Paper_Store {

	/**
	 * Default collection name.
	 *
	 * @var string
	 */
	const DEFAULT_COLLECTION = 'email-templates';

	/**
	 * Whether the Paper Store subsystem is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'WP_MCP_AI_Paper_Store_Manager' );
	}

	/**
	 * Mirror a template post into the Paper Store as a record.
	 *
	 * Creates or updates a record with id "nds-{slug}" in the given
	 * collection. The record body carries the portable template payload
	 * (html, scope, settings); meta carries source and audit score.
	 *
	 * @param int    $post_id    Template post ID.
	 * @param string $collection Collection name (default 'email-templates').
	 * @return array|WP_Error Success array with record/collection, or WP_Error.
	 */
	public static function mirror( $post_id, $collection = self::DEFAULT_COLLECTION ) {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'nds_paper_store_unavailable',
				__( 'Paper Store is not available — activate the NV oOS base plugin to use template mirroring.', 'nvoos-design-system' )
			);
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || NV_oOS_Design_System_Email_Template_CPT::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'nds_paper_store_invalid_template', __( 'The provided ID is not an email template.', 'nvoos-design-system' ) );
		}

		$collection = self::sanitize_collection( $collection );
		$record_id  = 'nds-' . sanitize_key( $post->post_name );

		$auditor  = NV_oOS_Design_System_Plugin::email_auditor();
		$audit    = $auditor->audit( $post->post_content, array(), NV_oOS_Design_System_Email_Template_CPT::get_scope( $post->ID ) );
		$source   = NV_oOS_Design_System_Email_Template_CPT::get_source( $post->ID );
		$settings = NV_oOS_Design_System_Email_Template_CPT::get_settings( $post->ID );

		$record = array(
			'id'          => $record_id,
			'type'        => $collection,
			'title'       => $post->post_title,
			'description' => sprintf(
				/* translators: %s: template slug */
				__( 'NV oOS Design System email template (%s).', 'nvoos-design-system' ),
				$post->post_name
			),
			'tags'        => array( 'email-template', $source, 'publish' === $post->post_status ? 'published' : 'draft' ),
			'status'      => 'publish' === $post->post_status ? 'published' : 'draft',
			'body'        => array(
				'html'     => $post->post_content,
				'scope'    => NV_oOS_Design_System_Email_Template_CPT::get_scope( $post->ID ),
				'settings' => $settings,
			),
			'meta'        => array(
				'source'      => $source,
				'audit_score' => $audit['score'],
			),
		);

		$repo = WP_MCP_AI_Paper_Store_Manager::get_instance()->get_repository( $collection );

		if ( $repo->exists( $record_id ) ) {
			$saved = $repo->update( $record_id, $record );
		} else {
			$saved = $repo->save( $record );
		}

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'success'    => true,
			'record_id'  => $record_id,
			'collection' => $collection,
			'message'    => sprintf(
				/* translators: 1: record id, 2: collection */
				__( 'Template mirrored to Paper Store record "%1$s" in collection "%2$s".', 'nvoos-design-system' ),
				$record_id,
				$collection
			),
		);
	}

	/**
	 * Import a Paper Store record as a draft email template.
	 *
	 * @param string $collection Collection name.
	 * @param string $record_id  Record ID (sanitised with sanitize_key()).
	 * @return array|WP_Error Success array with template_id/slug, or WP_Error.
	 */
	public static function import_record( $collection, $record_id ) {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'nds_paper_store_unavailable',
				__( 'Paper Store is not available — activate the NV oOS base plugin to import templates.', 'nvoos-design-system' )
			);
		}

		$collection = self::sanitize_collection( $collection );
		$record_id  = sanitize_key( $record_id );

		if ( '' === $record_id ) {
			return new WP_Error( 'nds_paper_store_invalid_record', __( 'A valid Paper Store record ID is required.', 'nvoos-design-system' ) );
		}

		$repo   = WP_MCP_AI_Paper_Store_Manager::get_instance()->get_repository( $collection );
		$record = $repo->find( $record_id );

		if ( is_wp_error( $record ) ) {
			return $record;
		}

		if ( empty( $record ) || empty( $record['body']['html'] ) ) {
			return new WP_Error(
				'nds_paper_store_record_not_found',
				sprintf(
					/* translators: 1: record id, 2: collection */
					__( 'Paper Store record "%1$s" was not found in collection "%2$s", or it does not contain a template body.', 'nvoos-design-system' ),
					$record_id,
					$collection
				)
			);
		}

		$slug     = str_replace( 'nds-', '', $record_id );
		$slug     = 'ps-' . $slug;
		$title    = ! empty( $record['title'] ) ? sanitize_text_field( $record['title'] ) : $record_id;
		$scope    = ! empty( $record['body']['scope'] ) && 'body' === $record['body']['scope'] ? 'body' : 'full';
		$settings = ! empty( $record['body']['settings'] ) && is_array( $record['body']['settings'] ) ? $record['body']['settings'] : array();

		$result = NV_oOS_Design_System_Email_Template_CPT::upsert(
			$slug,
			$title,
			$record['body']['html'],
			'custom',
			$scope,
			$settings,
			'draft'
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'     => true,
			'template_id' => $result,
			'slug'        => $slug,
			'status'      => 'draft',
			'message'     => __( 'Template imported from Paper Store as a draft. Audit and activate it separately.', 'nvoos-design-system' ),
		);
	}

	/**
	 * Sanitize a collection name.
	 *
	 * @param string $collection Collection name.
	 * @return string
	 */
	private static function sanitize_collection( $collection ) {
		$collection = sanitize_key( $collection );
		return '' === $collection ? self::DEFAULT_COLLECTION : $collection;
	}
}
