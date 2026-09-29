<?php
/**
 * NV oOS Design System — Tool: Import Email Template
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The NV oOS tool contracts (interface, capability-flags interface, and the
// default-capability trait) live in the base plugin. Guard so the addon stays
// loadable — and its autoloader stays safe — when it runs standalone without
// the base plugin active.
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) || ! trait_exists( 'WP_MCP_AI_Tool_Default_Capability' ) ) {
	return;
}

/**
 * Imports a template JSON payload (from nds_export_email_template) as a draft.
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_Tool_Import_Email_Template implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'nds_import_email_template';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import Email Template', 'nvoos-design-system' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Import an NV oOS Design System email template from a JSON payload produced by nds_export_email_template. The template is saved as a DRAFT — audit and activate it separately. Use when migrating templates between sites.', 'nvoos-design-system' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'json'                   => array(
					'type'        => 'string',
					'description' => __( 'JSON payload from nds_export_email_template. Provide this OR (paper_store_collection + paper_store_record_id).', 'nvoos-design-system' ),
					'minLength'   => 1,
					'maxLength'   => 200000,
				),
				'paper_store_collection' => array(
					'type'        => 'string',
					'description' => __( 'Paper Store collection to import from (default "email-templates"). Takes precedence over "json" when combined with paper_store_record_id. Requires the NV oOS base plugin.', 'nvoos-design-system' ),
					'maxLength'   => 60,
				),
				'paper_store_record_id'  => array(
					'type'        => 'string',
					'description' => __( 'Paper Store record ID to import (e.g. "nds-letterhead"). Takes precedence over "json" when combined with paper_store_collection.', 'nvoos-design-system' ),
					'maxLength'   => 120,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Paper Store import takes precedence when both identifiers are present.
		$paper_collection = isset( $arguments['paper_store_collection'] ) ? sanitize_key( $arguments['paper_store_collection'] ) : '';
		$paper_record_id  = isset( $arguments['paper_store_record_id'] ) ? sanitize_key( $arguments['paper_store_record_id'] ) : '';

		if ( '' !== $paper_collection || '' !== $paper_record_id ) {
			$paper_collection = '' === $paper_collection ? NV_oOS_Design_System_Email_Paper_Store::DEFAULT_COLLECTION : $paper_collection;

			$result = NV_oOS_Design_System_Email_Paper_Store::import_record( $paper_collection, $paper_record_id );
			if ( is_wp_error( $result ) ) {
				return new WP_Error( 'tool_error', $result->get_error_message() );
			}

			return $result;
		}

		$json    = isset( $arguments['json'] ) ? (string) $arguments['json'] : '';
		$payload = json_decode( $json, true );

		if ( ! is_array( $payload ) || empty( $payload['html'] ) || empty( $payload['title'] ) ) {
			return new WP_Error( 'tool_error', __( 'Invalid template JSON — "html" and "title" are required (or provide paper_store_collection + paper_store_record_id).', 'nvoos-design-system' ) );
		}

		$slug     = ! empty( $payload['slug'] ) ? sanitize_title( $payload['slug'] ) : 'imported-' . strtolower( sanitize_title( wp_generate_uuid4() ) );
		$title    = sanitize_text_field( $payload['title'] );
		$scope    = ! empty( $payload['scope'] ) && 'body' === $payload['scope'] ? 'body' : 'full';
		$settings = ! empty( $payload['settings'] ) && is_array( $payload['settings'] ) ? $payload['settings'] : array();

		$result = NV_oOS_Design_System_Email_Template_CPT::upsert(
			$slug,
			$title,
			$payload['html'],
			'custom',
			$scope,
			$settings,
			'draft'
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'tool_error', $result->get_error_message() );
		}

		return array(
			'success'     => true,
			'template_id' => $result,
			'slug'        => $slug,
			'status'      => 'draft',
			'message'     => __( 'Email template imported as a draft. Audit and activate it separately.', 'nvoos-design-system' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'admin-surface' );
	}
}
