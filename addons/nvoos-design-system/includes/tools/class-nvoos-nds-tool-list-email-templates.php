<?php
/**
 * NV oOS Design System — Tool: List Email Templates
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists built-in and custom email templates with status, source, and audit scores.
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_Tool_List_Email_Templates implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'nds_list_email_templates';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Email Templates', 'nvoos-design-system' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'List the NV oOS Design System email templates: built-in templates, custom templates, and AI-generated drafts. Returns the slug, title, source, status, and audit score of each template. Use this before activating, previewing, or auditing a template.', 'nvoos-design-system' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'status' => array(
					'type'        => 'string',
					'description' => __( 'Filter by status: "publish", "draft", or empty for all.', 'nvoos-design-system' ),
					'enum'        => array( '', 'publish', 'draft' ),
					'default'     => '',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$status = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : '';
		if ( ! in_array( $status, array( 'publish', 'draft' ), true ) ) {
			$status = '';
		}

		$templates = NV_oOS_Design_System_Email_Template_CPT::get_all_templates();
		$active    = NV_oOS_Design_System_Email_Template_Registry::get_active_slug();
		$auditor   = NV_oOS_Design_System_Plugin::email_auditor();

		$items = array();
		foreach ( $templates as $template ) {
			if ( '' !== $status && $template->post_status !== $status ) {
				continue;
			}

			$audit = $auditor->audit(
				$template->post_content,
				array(),
				NV_oOS_Design_System_Email_Template_CPT::get_scope( $template->ID )
			);

			$items[] = array(
				'slug'    => $template->post_name,
				'title'   => $template->post_title,
				'source'  => NV_oOS_Design_System_Email_Template_CPT::get_source( $template->ID ),
				'status'  => $template->post_status,
				'active'  => $template->post_name === $active,
				'audit'   => array(
					'score'           => $audit['score'],
					'passes_required' => $audit['passes_required'],
				),
			);
		}

		return array(
			'success'    => true,
			'templates'  => $items,
			'active'     => $active,
			'total'      => count( $items ),
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
