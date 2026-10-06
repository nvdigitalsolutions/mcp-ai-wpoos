<?php
/**
 * Abstract base for the Fashion Studio transform tools (Pro, Phase 5).
 *
 * Wraps the Media Studio addon's `NV_oOS_Media_Studio_AI_Service::execute_transform()`
 * as registry tools. Concrete subclasses declare the transform slug and its
 * schema extras; the base owns the shared schema, availability gating, the
 * ack recording, and the canonical envelope passthrough.
 *
 * Availability: the tools only register when the NV oOS Media Studio addon is
 * active (its classes load at plugin load time, before the tool-registration
 * hook fires), and every execute() carries the same belt-and-braces gate.
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
 * Shared implementation for fashion transform tools.
 */
abstract class WP_MCP_AI_Fashion_Transform_Tool implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * The Media Studio transform slug this tool drives.
	 *
	 * @return string
	 */
	abstract protected function get_transform_slug();

	/**
	 * Display name.
	 *
	 * @return string
	 */
	abstract protected function get_transform_name();

	/**
	 * Description for the model.
	 *
	 * @return string
	 */
	abstract protected function get_transform_description();

	/**
	 * Extra JSON-schema properties beyond the shared set.
	 *
	 * @return array
	 */
	abstract protected function get_extra_schema_properties();

	/**
	 * Per-property sanitizer map for the extra schema properties.
	 *
	 * Keys are the argument names; values are callable sanitizers.
	 *
	 * @return array
	 */
	abstract protected function get_extra_sanitizers();

	/**
	 * Whether this tool is available (the Media Studio addon must be active).
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
	public function get_name() {
		return $this->get_transform_name();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return $this->get_transform_description();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		$properties = array(
			'attachment_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'WordPress attachment ID of the product image to transform.', 'mcp-ai-wpoos-pro' ),
			),
			'description'   => array(
				'type'        => 'string',
				'description' => __( 'Optional guidance: style, scene, or garment details.', 'mcp-ai-wpoos-pro' ),
			),
			'count'         => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 4,
				'default'     => 1,
				'description' => __( 'Planned variants for the cost estimate (one generation runs per call).', 'mcp-ai-wpoos-pro' ),
			),
			'seed'          => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Optional seed for reproducible variants.', 'mcp-ai-wpoos-pro' ),
			),
			'confirmed'     => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Set true to proceed when a cost-review gate is triggered.', 'mcp-ai-wpoos-pro' ),
			),
			'acknowledged'  => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Set true to record the one-time AI disclosure acknowledgment for face transforms.', 'mcp-ai-wpoos-pro' ),
			),
		);

		return array(
			'type'                 => 'object',
			'properties'           => array_merge( $properties, $this->get_extra_schema_properties() ),
			'required'             => array( 'attachment_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		// Transforms sideload generated media into the Media Library.
		return 'upload_files';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => $this->get_when_to_use(),
			'when_not_to_use' => $this->get_when_not_to_use(),
			'related_tools'   => $this->get_related_tools(),
			'notes'           => $this->get_usage_notes(),
		);
	}

	/**
	 * When-to-use guidance.
	 *
	 * @return string
	 */
	protected function get_when_to_use() {
		return sprintf(
			/* translators: %s: transform display name */
			__( 'Generating %s imagery for product pages and campaigns from a Media Library source.', 'mcp-ai-wpoos-pro' ),
			$this->get_transform_name()
		);
	}

	/**
	 * When-not-to-use guidance.
	 *
	 * @return string
	 */
	protected function get_when_not_to_use() {
		return __( 'Plain image edits unrelated to fashion merchandising; use the dedicated image-production tools. Multi-source runs; use fashion_batch_job.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Related tool slugs.
	 *
	 * @return string[]
	 */
	protected function get_related_tools() {
		return array( 'fashion_batch_job', 'fashion_packshot', 'extract_video_frames' );
	}

	/**
	 * Usage notes.
	 *
	 * @return string
	 */
	protected function get_usage_notes() {
		return __( 'Face transforms require a one-time disclosure acknowledgment and always watermark outputs. Unknown provider pricing requires confirmed=true.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Execute the transform.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Canonical envelope or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! class_exists( 'NV_oOS_Media_Studio_AI_Service' ) ) {
			return new WP_Error(
				'nvoos_ms_service_missing',
				__( 'The NV oOS Media Studio addon is not active.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 503 )
			);
		}

		// Gate 1 — sanitize at entry.
		$attachment_id = isset( $arguments['attachment_id'] ) ? absint( $arguments['attachment_id'] ) : 0;
		if ( $attachment_id < 1 ) {
			return new WP_Error(
				'nvoos_ms_invalid_attachment',
				__( 'Provide the attachment ID of the product image to transform.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$args = array(
			'description' => isset( $arguments['description'] ) ? sanitize_textarea_field( (string) $arguments['description'] ) : '',
			'count'       => isset( $arguments['count'] ) ? min( 4, max( 1, absint( $arguments['count'] ) ) ) : 1,
			'seed'        => isset( $arguments['seed'] ) ? absint( $arguments['seed'] ) : 0,
			'confirmed'   => ! empty( $arguments['confirmed'] ),
		);

		foreach ( $this->get_extra_sanitizers() as $key => $sanitizer ) {
			if ( isset( $arguments[ $key ] ) ) {
				$args[ $key ] = call_user_func( $sanitizer, $arguments[ $key ] );
			}
		}

		// The acknowledgment arrives with the same call that proceeds (mirrors the REST flow).
		if ( ! empty( $arguments['acknowledged'] ) && $user_id > 0 ) {
			NV_oOS_Media_Studio_AI_Service::record_ack( $user_id );
		}

		$result = NV_oOS_Media_Studio_AI_Service::execute_transform(
			$this->get_transform_slug(),
			$attachment_id,
			$args,
			$user_id
		);

		// Gate 2 — the service escapes every output value in the envelope.
		return $result;
	}
}
