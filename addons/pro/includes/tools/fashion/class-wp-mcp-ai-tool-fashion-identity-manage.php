<?php
/**
 * Fashion Studio — Identity management tool (Pro, Phase 5).
 *
 * Assistant-facing CRUD over the managed fashion identity library
 * (`WP_MCP_AI_Fashion_Model_CPT`). Actions: list, create, update, delete,
 * set_consent. Consent changes and deletes are admin-only.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage fashion identity models from the assistant.
 */
class WP_MCP_AI_Tool_Fashion_Identity_Manage implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * Editable identity meta fields.
	 *
	 * @var string[]
	 */
	const EDITABLE_META = array( 'gender', 'skin_tone', 'body_type', 'age_group', 'height' );

	/**
	 * Whether this tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'NV_oOS_Media_Studio_AI_Service' );
	}

	/**
	 * Why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The NV oOS Media Studio addon is not active.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fashion_identity_manage';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Fashion Identity Manage', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists and manages the managed fashion identity library: create or update model identities, change consent status, and remove identities. Consent and deletion are restricted to administrators.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Maintaining the identity library that model-swap and try-on transforms reference, and granting or revoking consent per identity.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Generating imagery; use the fashion_* transform tools. Managing batch runs; use fashion_batch_job.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'fashion_model_swap', 'fashion_virtual_tryon', 'fashion_batch_job' ),
			'notes'           => __( 'Consent status gates face transforms: only identities with consent "granted" can drive face swaps and try-ons.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'         => array(
					'type'        => 'string',
					'enum'        => array( 'list', 'create', 'update', 'delete', 'set_consent' ),
					'description' => __( 'Operation to perform.', 'mcp-ai-wpoos-pro' ),
				),
				'identity_id'    => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Identity post ID (update, delete, set_consent).', 'mcp-ai-wpoos-pro' ),
				),
				'name'           => array(
					'type'        => 'string',
					'description' => __( 'Identity display name (create, update).', 'mcp-ai-wpoos-pro' ),
				),
				'gender'         => array(
					'type'        => 'string',
					'description' => __( 'Gender attribute (create, update).', 'mcp-ai-wpoos-pro' ),
				),
				'skin_tone'      => array(
					'type'        => 'string',
					'description' => __( 'Skin tone attribute (create, update).', 'mcp-ai-wpoos-pro' ),
				),
				'body_type'      => array(
					'type'        => 'string',
					'description' => __( 'Body type attribute (create, update).', 'mcp-ai-wpoos-pro' ),
				),
				'age_group'      => array(
					'type'        => 'string',
					'description' => __( 'Age group attribute (create, update).', 'mcp-ai-wpoos-pro' ),
				),
				'height'         => array(
					'type'        => 'string',
					'description' => __( 'Height attribute (create, update).', 'mcp-ai-wpoos-pro' ),
				),
				'thumbnail_id'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Attachment ID for the identity thumbnail (create, update).', 'mcp-ai-wpoos-pro' ),
				),
				'consent_status' => array(
					'type'        => 'string',
					'enum'        => array( 'none', 'granted', 'revoked' ),
					'description' => __( 'Consent status (create, set_consent).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

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
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Canonical envelope or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! class_exists( 'NV_oOS_Media_Studio_AI_Service' ) || ! class_exists( 'WP_MCP_AI_Fashion_Model_CPT' ) ) {
			return new WP_Error(
				'nvoos_ms_service_missing',
				__( 'The Media Studio fashion pipeline is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 503 )
			);
		}

		$cpt    = 'WP_MCP_AI_Fashion_Model_CPT';
		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : '';
		if ( ! in_array( $action, array( 'list', 'create', 'update', 'delete', 'set_consent' ), true ) ) {
			return new WP_Error(
				'nvoos_ms_invalid_action',
				__( 'Provide a valid action: list, create, update, delete, or set_consent.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Gate 1 — sanitize at entry.
		$identity_id = isset( $arguments['identity_id'] ) ? absint( $arguments['identity_id'] ) : 0;
		$name        = isset( $arguments['name'] ) ? sanitize_text_field( (string) $arguments['name'] ) : '';
		$meta_input  = array();
		foreach ( self::EDITABLE_META as $field ) {
			if ( isset( $arguments[ $field ] ) ) {
				$meta_input[ $field ] = sanitize_text_field( (string) $arguments[ $field ] );
			}
		}
		$thumbnail_id = isset( $arguments['thumbnail_id'] ) ? absint( $arguments['thumbnail_id'] ) : 0;
		$consent      = isset( $arguments['consent_status'] ) ? $cpt::sanitize_consent( $arguments['consent_status'] ) : '';

		switch ( $action ) {
			case 'list':
				return array(
					'models' => $cpt::get_models(),
				);

			case 'create':
				if ( ! user_can( $user_id, 'upload_files' ) ) {
					return new WP_Error( 'nvoos_ms_forbidden', __( 'Creating identities requires the upload_files capability.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
				}
				if ( '' === $name ) {
					return new WP_Error( 'nvoos_ms_invalid_identity', __( 'Provide a name for the identity.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
				}
				$post_id = wp_insert_post(
					array(
						'post_type'   => $cpt::POST_TYPE,
						'post_status' => 'publish',
						'post_title'  => $name,
						'post_author' => $user_id > 0 ? $user_id : 0,
						'meta_input'  => $this->build_meta_input( $cpt, $meta_input, $consent, true ),
					),
					true
				);
				if ( is_wp_error( $post_id ) ) {
					return $post_id;
				}
				if ( $thumbnail_id > 0 ) {
					set_post_thumbnail( $post_id, $thumbnail_id );
				}
				return $this->identity_payload( $cpt, $post_id );

			case 'update':
				$post = $this->get_identity_post( $cpt, $identity_id );
				if ( is_wp_error( $post ) ) {
					return $post;
				}
				if ( ! user_can( $user_id, 'upload_files' ) ) {
					return new WP_Error( 'nvoos_ms_forbidden', __( 'Updating identities requires the upload_files capability.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
				}
				$update = array( 'ID' => $identity_id );
				if ( '' !== $name ) {
					$update['post_title'] = $name;
				}
				$saved = wp_update_post( $update, true );
				if ( is_wp_error( $saved ) ) {
					return $saved;
				}
				foreach ( $meta_input as $field => $value ) {
					update_post_meta( $identity_id, constant( $cpt . '::META_' . strtoupper( $field ) ), $value );
				}
				if ( $thumbnail_id > 0 ) {
					set_post_thumbnail( $identity_id, $thumbnail_id );
				}
				return $this->identity_payload( $cpt, $identity_id );

			case 'delete':
				if ( ! user_can( $user_id, 'manage_options' ) ) {
					return new WP_Error( 'nvoos_ms_forbidden', __( 'Deleting identities requires the manage_options capability.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
				}
				$post = $this->get_identity_post( $cpt, $identity_id );
				if ( is_wp_error( $post ) ) {
					return $post;
				}
				$deleted = wp_trash_post( $identity_id );
				if ( ! $deleted ) {
					return new WP_Error( 'nvoos_ms_identity_delete_failed', __( 'Could not delete the identity.', 'mcp-ai-wpoos-pro' ), array( 'status' => 500 ) );
				}
				return array(
					'identity_id' => $identity_id,
					'deleted'     => true,
				);

			case 'set_consent':
				if ( ! user_can( $user_id, 'manage_options' ) ) {
					return new WP_Error( 'nvoos_ms_forbidden', __( 'Changing consent status requires the manage_options capability.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
				}
				$post = $this->get_identity_post( $cpt, $identity_id );
				if ( is_wp_error( $post ) ) {
					return $post;
				}
				if ( '' === $consent ) {
					return new WP_Error( 'nvoos_ms_invalid_consent', __( 'Provide a valid consent status: none, granted, or revoked.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
				}
				update_post_meta( $identity_id, $cpt::META_CONSENT, $consent );
				return $this->identity_payload( $cpt, $identity_id );
		}

		return new WP_Error( 'nvoos_ms_invalid_action', __( 'Provide a valid action.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
	}

	/**
	 * Build the meta_input array for identity inserts.
	 *
	 * @param string $cpt        CPT class name.
	 * @param array  $fields     Field name => sanitized value.
	 * @param string $consent    Consent status (may be empty).
	 * @param bool   $is_custom  Mark as a custom identity.
	 * @return array
	 */
	private function build_meta_input( $cpt, $fields, $consent, $is_custom ) {
		$meta = array(
			$cpt::META_PROMPT_EMBED => '',
			$cpt::META_IS_CUSTOM    => $is_custom,
		);
		foreach ( $fields as $field => $value ) {
			$meta[ constant( $cpt . '::META_' . strtoupper( $field ) ) ] = $value;
		}
		if ( '' !== $consent ) {
			$meta[ $cpt::META_CONSENT ] = $consent;
		}
		return $meta;
	}

	/**
	 * Fetch an identity post or return a WP_Error.
	 *
	 * @param string $cpt         CPT class name.
	 * @param int    $identity_id Identity post ID.
	 * @return WP_Post|WP_Error
	 */
	private function get_identity_post( $cpt, $identity_id ) {
		if ( $identity_id < 1 ) {
			return new WP_Error( 'nvoos_ms_invalid_identity', __( 'Provide the identity post ID.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
		}
		$post = get_post( $identity_id );
		if ( ! $post || $cpt::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'nvoos_ms_identity_not_found', __( 'Fashion identity not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}
		return $post;
	}

	/**
	 * Build an escaped identity payload.
	 *
	 * @param string $cpt         CPT class name.
	 * @param int    $identity_id Identity post ID.
	 * @return array
	 */
	private function identity_payload( $cpt, $identity_id ) {
		$thumb_id = get_post_thumbnail_id( $identity_id );
		return array(
			'id'             => $identity_id,
			'name'           => sanitize_text_field( get_the_title( $identity_id ) ),
			'thumb_url'      => $thumb_id ? esc_url_raw( wp_get_attachment_url( $thumb_id ) ) : '',
			'gender'         => sanitize_text_field( (string) get_post_meta( $identity_id, $cpt::META_GENDER, true ) ),
			'skin_tone'      => sanitize_text_field( (string) get_post_meta( $identity_id, $cpt::META_SKIN_TONE, true ) ),
			'body_type'      => sanitize_text_field( (string) get_post_meta( $identity_id, $cpt::META_BODY_TYPE, true ) ),
			'age_group'      => sanitize_text_field( (string) get_post_meta( $identity_id, $cpt::META_AGE_GROUP, true ) ),
			'consent_status' => $cpt::sanitize_consent( get_post_meta( $identity_id, $cpt::META_CONSENT, true ) ),
			'is_custom'      => (bool) get_post_meta( $identity_id, $cpt::META_IS_CUSTOM, true ),
		);
	}
}
