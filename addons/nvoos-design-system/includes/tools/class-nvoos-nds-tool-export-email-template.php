<?php
/**
 * NV oOS Design System — Tool: Export Email Template
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
 * Exports an email template as a JSON payload (HTML + meta + settings).
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_Tool_Export_Email_Template implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'nds_export_email_template';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Export Email Template', 'nvoos-design-system' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Export an NV oOS Design System email template as a JSON payload (HTML, scope, settings) for backup or transfer to another site. The matching nds_import_email_template tool imports the payload as a draft.', 'nvoos-design-system' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'slug'                   => array(
					'type'        => 'string',
					'description' => __( 'Template slug to export. Defaults to the active template.', 'nvoos-design-system' ),
					'maxLength'   => 120,
				),
				'mirror_to_paper_store'  => array(
					'type'        => 'boolean',
					'description' => __( 'Also mirror the template into the Paper Store (collection "email-templates") for cross-site reuse. Requires the NV oOS base plugin.', 'nvoos-design-system' ),
					'default'     => false,
				),
				'paper_store_collection' => array(
					'type'        => 'string',
					'description' => __( 'Paper Store collection to mirror into (default "email-templates").', 'nvoos-design-system' ),
					'maxLength'   => 60,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$post = null;

		$slug = isset( $arguments['slug'] ) && '' !== trim( (string) $arguments['slug'] )
			? sanitize_title( $arguments['slug'] )
			: NV_oOS_Design_System_Email_Template_Registry::get_active_slug();

		$post = NV_oOS_Design_System_Email_Template_CPT::get_by_slug( $slug );

		if ( ! $post instanceof WP_Post ) {
			$builtin = NV_oOS_Design_System_Email_Template_Registry::get_builtin_html( $slug );
			if ( '' === trim( $builtin ) ) {
				return new WP_Error(
					'tool_error',
					sprintf(
						/* translators: %s: template slug */
						__( 'Email template "%s" was not found.', 'nvoos-design-system' ),
						$slug
					)
				);
			}

			$payload = array(
				'format'   => 'nvoos-design-system/email-template',
				'version'  => 1,
				'slug'     => $slug,
				'title'    => $slug,
				'scope'    => 'full',
				'source'   => 'builtin',
				'settings' => array(),
				'html'     => $builtin,
			);
		} else {
			$payload = array(
				'format'   => 'nvoos-design-system/email-template',
				'version'  => 1,
				'slug'     => $post->post_name,
				'title'    => $post->post_title,
				'scope'    => NV_oOS_Design_System_Email_Template_CPT::get_scope( $post->ID ),
				'source'   => NV_oOS_Design_System_Email_Template_CPT::get_source( $post->ID ),
				'settings' => NV_oOS_Design_System_Email_Template_CPT::get_settings( $post->ID ),
				'html'     => $post->post_content,
			);
		}

		$response = array(
			'success' => true,
			'json'    => wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'slug'    => $slug,
		);

		// Optional Paper Store mirror (stored templates only — built-ins
		// without a backing post cannot be mirrored).
		if ( ! empty( $arguments['mirror_to_paper_store'] ) ) {
			if ( ! $post instanceof WP_Post ) {
				return new WP_Error(
					'tool_error',
					__( 'Only stored templates can be mirrored to the Paper Store — seed the built-ins first.', 'nvoos-design-system' )
				);
			}

			$collection = isset( $arguments['paper_store_collection'] ) && '' !== trim( (string) $arguments['paper_store_collection'] )
				? $arguments['paper_store_collection']
				: NV_oOS_Design_System_Email_Paper_Store::DEFAULT_COLLECTION;

			$mirror = NV_oOS_Design_System_Email_Paper_Store::mirror( $post->ID, $collection );
			if ( is_wp_error( $mirror ) ) {
				return new WP_Error( 'tool_error', $mirror->get_error_message() );
			}

			$response['paper_store'] = array(
				'record_id'  => $mirror['record_id'],
				'collection' => $mirror['collection'],
			);
		}

		return $response;
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
