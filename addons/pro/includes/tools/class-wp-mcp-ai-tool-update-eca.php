<?php
/**
 * Tool for updating ECAs.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates an existing ECA.
 */
class WP_MCP_AI_Tool_Update_ECA implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_eca';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update ECA', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates an existing Extra-Curricular Activity. Provide only the fields you want to update.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'eca_id'         => array(
					'type'        => 'integer',
					'description' => __( 'ECA ID to update (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'name'           => array(
					'type'        => 'string',
					'description' => __( 'New ECA name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'eca_code'       => array(
					'type'        => 'string',
					'description' => __( 'New ECA code (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 50,
				),
				'description'    => array(
					'type'        => 'string',
					'description' => __( 'New description (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 10000,
				),
				'eca_type'       => array(
					'type'        => 'string',
					'description' => __( 'New ECA type (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'club', 'society', 'sport_squad', 'sport_academy', 'activity' ),
				),
				'day'            => array(
					'type'        => 'string',
					'description' => __( 'New day of the week (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ),
				),
				'start_time'     => array(
					'type'        => 'string',
					'description' => __( 'New start time (HH:MM AM/PM) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{1,2}:\d{2}\s?(AM|PM|am|pm)$',
				),
				'end_time'       => array(
					'type'        => 'string',
					'description' => __( 'New end time (HH:MM AM/PM) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{1,2}:\d{2}\s?(AM|PM|am|pm)$',
				),
				'venue'          => array(
					'type'        => 'string',
					'description' => __( 'New venue/location (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'year_groups'    => array(
					'type'        => 'array',
					'description' => __( 'New eligible year groups (optional)', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'max_students'   => array(
					'type'        => 'integer',
					'description' => __( 'New maximum students (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 200,
				),
				'teachers'       => array(
					'type'        => 'array',
					'description' => __( 'New teacher names (optional)', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'cost'           => array(
					'type'        => 'number',
					'description' => __( 'New cost (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'currency'       => array(
					'type'        => 'string',
					'description' => __( 'New currency code (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'GBP', 'USD', 'EUR', 'AED', 'INR', 'AUD', 'CAD', 'SGD', 'ZAR' ),
				),
				'term'           => array(
					'type'        => 'string',
					'description' => __( 'New term (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'Term 1', 'Term 2', 'Term 3', 'Yearly' ),
				),
			),
			'required'             => array( 'eca_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_eca_management'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update ECAs.', 'mcp-ai-wpoos-pro' ) );
		}

		$eca_id = isset( $arguments['eca_id'] ) ? absint( $arguments['eca_id'] ) : 0;

		if ( ! $eca_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'ECA ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$eca = get_post( $eca_id );

		if ( ! $eca || 'mcp_ai_eca' !== $eca->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_eca', __( 'Invalid ECA ID.', 'mcp-ai-wpoos-pro' ) );
		}

		// Update post data if provided.
		$post_data = array( 'ID' => $eca_id );

		if ( isset( $arguments['name'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $arguments['name'] );
		}

		if ( isset( $arguments['description'] ) ) {
			$post_data['post_content'] = wp_kses_post( $arguments['description'] );
		}

		if ( count( $post_data ) > 1 ) {
			$result = wp_update_post( $post_data, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Update metadata fields.
		$meta_fields = array(
			'eca_code'      => '_eca_code',
			'eca_type'      => '_eca_type',
			'day'           => '_eca_day',
			'start_time'    => '_eca_start_time',
			'end_time'      => '_eca_end_time',
			'venue'         => '_eca_venue',
			'year_groups'   => '_eca_year_groups',
			'max_students'  => '_eca_max_students',
			'teachers'      => '_eca_teachers',
			'cost'          => '_eca_cost',
			'currency'      => '_eca_currency',
			'term'          => '_eca_term',
		);

		foreach ( $meta_fields as $arg_key => $meta_key ) {
			if ( isset( $arguments[ $arg_key ] ) ) {
				$value = $arguments[ $arg_key ];
				
				if ( is_array( $value ) ) {
					// For arrays, sanitize each element.
					$value = array_map( 'sanitize_text_field', $value );
				} else {
					$value = sanitize_text_field( $value );
				}
				
				update_post_meta( $eca_id, $meta_key, $value );
			}
		}

		return array(
			'success' => true,
			'message' => __( 'ECA updated successfully.', 'mcp-ai-wpoos-pro' ),
			'eca_id'  => $eca_id,
		);
	}
}
