<?php
/**
 * document-automation/class-wp-mcp-ai-tool-lf-document-template-manager.php (ecosystem port — Wave F4, law-firm document-automation batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-document-template-manager.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD manager for document templates used in legal document assembly.
 */
class WP_MCP_AI_Tool_LF_Document_Template_Manager implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';
	const OPTION_KEY = 'wp_mcp_ai_lf_document_templates';

	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_law_firm_toolkit'] );
	}

	/**
	 * Get unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason(): string {
		return __( 'Law Firm toolkit is not enabled.', 'nvoos-content-graph-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'lf_document_template_manager'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Document Template Manager', 'nvoos-content-graph-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Manages reusable document templates for legal document assembly. Supports create, list, get, and delete operations.', 'nvoos-content-graph-pro' ); }


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'        => array(
					'type'        => 'string',
					'description' => __( 'Template action.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'create', 'list', 'get', 'delete' ),
				),
				'template_name' => array(
					'type'        => 'string',
					'description' => __( 'Name of the template.', 'nvoos-content-graph-pro' ),
				),
				'template_type' => array(
					'type'        => 'string',
					'description' => __( 'Document type for the template.', 'nvoos-content-graph-pro' ),
				),
				'content'       => array(
					'type'        => 'string',
					'description' => __( 'Template content.', 'nvoos-content-graph-pro' ),
				),
				'practice_area' => array(
					'type'        => 'string',
					'description' => __( 'Practice area.', 'nvoos-content-graph-pro' ),
				),
				'template_id'   => array(
					'type'        => 'string',
					'description' => __( 'Template ID (for get/delete).', 'nvoos-content-graph-pro' ),
				),
			),
			'required'   => array( 'action' ),
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags(): array {
		return array( 'pro', 'write', 'state-changing' ); }

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'nvoos-content-graph-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$action    = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';
		$templates = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $templates ) ) {
			$templates = array();
		}

		switch ( $action ) {
			case 'create':
				$name = isset( $arguments['template_name'] ) ? sanitize_text_field( $arguments['template_name'] ) : '';
				if ( empty( $name ) ) {
					return new WP_Error( 'missing_required', __( 'Template name is required.', 'nvoos-content-graph-pro' ) );
				}
				$id               = 'tmpl_' . wp_generate_uuid4();
				$templates[ $id ] = array(
					'id'            => $id,
					'name'          => $name,
					'type'          => isset( $arguments['template_type'] ) ? sanitize_text_field( $arguments['template_type'] ) : '',
					'content'       => isset( $arguments['content'] ) ? wp_kses_post( $arguments['content'] ) : '',
					'practice_area' => isset( $arguments['practice_area'] ) ? sanitize_text_field( $arguments['practice_area'] ) : '',
					'created_by'    => $uid,
					'created_at'    => current_time( 'Y-m-d H:i:s' ),
				);
				update_option( self::OPTION_KEY, $templates, false );
				return array(
					'success'    => true,
					'message'    => __( 'Template created. ', 'nvoos-content-graph-pro' ) . self::DISCLAIMER,
					'data'       => array(
						'template_id' => $id,
						'template'    => $templates[ $id ],
					),
					'disclaimer' => self::DISCLAIMER,
				);

			case 'list':
				$list = array_values( $templates );
				$pa   = isset( $arguments['practice_area'] ) ? sanitize_text_field( $arguments['practice_area'] ) : '';
				if ( $pa ) {
					$list = array_values(
						array_filter(
							$list,
							function ( $t ) use ( $pa ) {
								return ( $t['practice_area'] ?? '' ) === $pa;
							}
						)
					);
				}
				return array(
					'success'    => true,
					'message'    => sprintf(
						/* translators: %d: number of templates */
						__( '%d templates found. ', 'nvoos-content-graph-pro' ),
						count( $list )
					) . self::DISCLAIMER,
					'data'       => array(
						'templates' => $list,
						'total'     => count( $list ),
					),
					'disclaimer' => self::DISCLAIMER,
				);

			case 'get':
				$tid = isset( $arguments['template_id'] ) ? sanitize_text_field( $arguments['template_id'] ) : '';
				if ( empty( $tid ) || ! isset( $templates[ $tid ] ) ) {
					return new WP_Error( 'not_found', __( 'Template not found.', 'nvoos-content-graph-pro' ) );
				}
				return array(
					'success'    => true,
					'message'    => __( 'Template retrieved. ', 'nvoos-content-graph-pro' ) . self::DISCLAIMER,
					'data'       => array( 'template' => $templates[ $tid ] ),
					'disclaimer' => self::DISCLAIMER,
				);

			case 'delete':
				$tid = isset( $arguments['template_id'] ) ? sanitize_text_field( $arguments['template_id'] ) : '';
				if ( empty( $tid ) || ! isset( $templates[ $tid ] ) ) {
					return new WP_Error( 'not_found', __( 'Template not found.', 'nvoos-content-graph-pro' ) );
				}
				unset( $templates[ $tid ] );
				update_option( self::OPTION_KEY, $templates, false );
				return array(
					'success'    => true,
					'message'    => __( 'Template deleted. ', 'nvoos-content-graph-pro' ) . self::DISCLAIMER,
					'data'       => array( 'deleted_template_id' => $tid ),
					'disclaimer' => self::DISCLAIMER,
				);

			default:
				return new WP_Error( 'invalid_action', __( 'Invalid action.', 'nvoos-content-graph-pro' ) );
		}
	}
}
