<?php
/**
 * JetEngine Create Meta Field Tool
 *
 * Creates meta fields via JetEngine MCP Server.
 *
 * @package WP_MCP_AI_Pro
 * @since   2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for creating JetEngine meta fields via MCP.
 *
 * @since 2.1.0
 */
class WP_MCP_AI_Pro_Tool_JetEngine_Create_Meta_Field implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if this tool is available.
	 *
	 * @return bool True if JetEngine 3.8+ MCP server is available.
	 */
	public static function is_available() {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Compat' ) ) {
			return false;
		}
		return WP_MCP_AI_JetEngine_Compat::has_mcp_server();
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		return __( 'Requires JetEngine 3.8+ with MCP Server enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'jetengine_create_meta_field';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'JetEngine Create Meta Field', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Create a new meta field for a JetEngine custom post type, taxonomy, or user via the MCP Server. Specify the field name, label, type (text, number, select, media, etc.), and the context where it should appear.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'name'          => array(
					'type'        => 'string',
					'description' => __( 'Meta field key (lowercase, underscores, e.g., "event_date").', 'mcp-ai-wpoos-pro' ),
				),
				'label'         => array(
					'type'        => 'string',
					'description' => __( 'Human-readable field label (e.g., "Event Date").', 'mcp-ai-wpoos-pro' ),
				),
				'field_type'    => array(
					'type'        => 'string',
					'description' => __( 'Field type: text, textarea, number, date, datetime-local, time, select, checkbox, radio, media, gallery, repeater, colorpicker, wysiwyg, switcher, iconpicker.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'text', 'textarea', 'number', 'date', 'datetime-local', 'time', 'select', 'checkbox', 'radio', 'media', 'gallery', 'repeater', 'colorpicker', 'wysiwyg', 'switcher', 'iconpicker' ),
				),
				'context'       => array(
					'type'        => 'string',
					'description' => __( 'Context where the field appears: post_type, taxonomy, or user.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'post_type', 'taxonomy', 'user' ),
				),
				'context_slug'  => array(
					'type'        => 'string',
					'description' => __( 'Slug of the post type or taxonomy to attach the field to.', 'mcp-ai-wpoos-pro' ),
				),
				'description'   => array(
					'type'        => 'string',
					'description' => __( 'Optional field description shown below the field in the editor.', 'mcp-ai-wpoos-pro' ),
				),
				'is_required'   => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the field is required. Default: false.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'default_value' => array(
					'type'        => 'string',
					'description' => __( 'Default value for the field.', 'mcp-ai-wpoos-pro' ),
				),
				'options'       => array(
					'type'        => 'array',
					'description' => __( 'Options for select, checkbox, or radio fields. Each item should have "value" and "label" keys.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'value' => array( 'type' => 'string' ),
							'label' => array( 'type' => 'string' ),
						),
					),
				),
			),
			'required'   => array( 'name', 'label', 'field_type', 'context', 'context_slug' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'requires-plugin', 'local-only' );
	}

	/**
	 * Get tool definition.
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => $this->get_name(),
			'description'         => $this->get_description(),
			'parameters'          => $this->get_parameters_schema(),
			'required_capability' => 'manage_options',
			'toolkit'             => 'jetengine_mcp_bridge',
			'risk_level'          => 'elevated',
			'capability_flags'    => $this->get_capability_flags(),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Result or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'insufficient_permissions', __( 'Requires manage_options capability.', 'mcp-ai-wpoos-pro' ) );
		}

		$name         = isset( $arguments['name'] ) ? sanitize_key( $arguments['name'] ) : '';
		$label        = isset( $arguments['label'] ) ? sanitize_text_field( $arguments['label'] ) : '';
		$field_type   = isset( $arguments['field_type'] ) ? sanitize_key( $arguments['field_type'] ) : '';
		$field_ctx    = isset( $arguments['context'] ) ? sanitize_key( $arguments['context'] ) : '';
		$context_slug = isset( $arguments['context_slug'] ) ? sanitize_key( $arguments['context_slug'] ) : '';

		if ( empty( $name ) || empty( $label ) || empty( $field_type ) || empty( $field_ctx ) || empty( $context_slug ) ) {
			return new WP_Error( 'missing_params', __( 'name, label, field_type, context, and context_slug are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$mcp_args = array(
			'name'         => $name,
			'label'        => $label,
			'field_type'   => $field_type,
			'context'      => $field_ctx,
			'context_slug' => $context_slug,
		);

		if ( ! empty( $arguments['description'] ) ) {
			$mcp_args['description'] = sanitize_text_field( $arguments['description'] );
		}
		if ( isset( $arguments['is_required'] ) ) {
			$mcp_args['is_required'] = (bool) $arguments['is_required'];
		}
		if ( isset( $arguments['default_value'] ) ) {
			$mcp_args['default_value'] = sanitize_text_field( $arguments['default_value'] );
		}
		if ( ! empty( $arguments['options'] ) && is_array( $arguments['options'] ) ) {
			$mcp_args['options'] = $arguments['options'];
		}

		$client = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$result = $client->tools_call( 'create_meta_field', $mcp_args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: field name, 2: context type, 3: context slug */
				__( 'Meta field "%1$s" created for %2$s "%3$s" via JetEngine MCP.', 'mcp-ai-wpoos-pro' ),
				$name,
				$field_ctx,
				$context_slug
			),
			'result'  => $result,
		);
	}

	/**
	 * Get MCP client instance.
	 *
	 * @return WP_MCP_AI_JetEngine_MCP_Client|WP_Error Client or error.
	 */
	private function get_client() {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_MCP_Client' ) ) {
			$client_file = defined( 'WP_MCP_AI_PRO_PATH' )
				? WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-jetengine-mcp-client.php'
				: '';
			if ( ! empty( $client_file ) && file_exists( $client_file ) ) {
				require_once $client_file;
			} else {
				return new WP_Error( 'mcp_client_missing', __( 'MCP client class is not available.', 'mcp-ai-wpoos-pro' ) );
			}
		}
		return new WP_MCP_AI_JetEngine_MCP_Client();
	}
}
