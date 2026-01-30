<?php
/**
 * Tool that manages customer data in Flowhub API (create/update).
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-flowhub-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for creating and updating customer profiles in Flowhub.
 */
class WP_MCP_AI_Tool_Flowhub_Manage_Customer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'flowhub_manage_customer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Flowhub Manage Customer', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create or update customer profiles in Flowhub dispensary system. Supports managing contact information, medical cannabis credentials, loyalty data, and preferences.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Optional Remote Sites connection ID for Flowhub. If not provided, will use settings-based configuration.', 'mcp-ai-wpoos' ),
				),
				'action'        => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: "create" or "update".', 'mcp-ai-wpoos' ),
					'enum'        => array( 'create', 'update' ),
				),
				'customer_id'   => array(
					'type'        => 'string',
					'description' => __( 'Customer ID (required for update action).', 'mcp-ai-wpoos' ),
				),
				'first_name'    => array(
					'type'        => 'string',
					'description' => __( 'Customer first name.', 'mcp-ai-wpoos' ),
				),
				'last_name'     => array(
					'type'        => 'string',
					'description' => __( 'Customer last name.', 'mcp-ai-wpoos' ),
				),
				'email'         => array(
					'type'        => 'string',
					'description' => __( 'Customer email address.', 'mcp-ai-wpoos' ),
					'format'      => 'email',
				),
				'phone'         => array(
					'type'        => 'string',
					'description' => __( 'Customer phone number.', 'mcp-ai-wpoos' ),
				),
				'address'       => array(
					'type'        => 'object',
					'description' => __( 'Customer address details.', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'street'  => array( 'type' => 'string' ),
						'city'    => array( 'type' => 'string' ),
						'state'   => array( 'type' => 'string' ),
						'zip'     => array( 'type' => 'string' ),
						'country' => array( 'type' => 'string' ),
					),
				),
				'date_of_birth' => array(
					'type'        => 'string',
					'description' => __( 'Date of birth (YYYY-MM-DD format).', 'mcp-ai-wpoos' ),
					'format'      => 'date',
				),
				'medical_id'    => array(
					'type'        => 'string',
					'description' => __( 'Medical cannabis ID number.', 'mcp-ai-wpoos' ),
				),
				'timeout'       => array(
					'type'        => 'integer',
					'description' => __( 'Request timeout in seconds (5-60).', 'mcp-ai-wpoos' ),
					'minimum'     => 5,
					'maximum'     => 60,
					'default'     => 30,
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		$has_token = ! empty( $context['token_authenticated'] );

		if ( ! $user_id && ! $has_token ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to manage Flowhub customers.', 'mcp-ai-wpoos' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id ) {
			// Require manage_woocommerce or manage_options capability.
			if ( ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'manage_options' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage customer data.', 'mcp-ai-wpoos' ) );
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
			}
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';

		if ( ! in_array( $action, array( 'create', 'update' ), true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_action',
				__( 'Action must be either "create" or "update".', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		if ( 'update' === $action && empty( $arguments['customer_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_customer_id',
				__( 'A customer_id is required for update action.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Get connection_id if provided.
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : null;

		// Validate connection if provided.
		if ( ! empty( $connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

			if ( null === $connection ) {
				return new WP_Error(
					'wp_mcp_ai_pro_connection_not_found',
					__( 'Connection not found. Please check the connection ID.', 'mcp-ai-wpoos' )
				);
			}

			// Validate connection type.
			if ( empty( $connection['connection_type'] ) || 'flowhub' !== $connection['connection_type'] ) {
				return new WP_Error(
					'wp_mcp_ai_pro_wrong_connection_type',
					__( 'This connection is not a Flowhub connection.', 'mcp-ai-wpoos' )
				);
			}

			// Check if connection is enabled.
			if ( empty( $connection['enabled'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_connection_disabled',
					__( 'This connection is disabled. Please enable it in Remote Sites settings.', 'mcp-ai-wpoos' )
				);
			}
		}

		$client        = new WP_MCP_AI_Flowhub_Client( $connection_id );
		$customer_data = array();

		if ( isset( $arguments['first_name'] ) ) {
			$customer_data['first_name'] = sanitize_text_field( $arguments['first_name'] );
		}
		if ( isset( $arguments['last_name'] ) ) {
			$customer_data['last_name'] = sanitize_text_field( $arguments['last_name'] );
		}
		if ( isset( $arguments['email'] ) ) {
			$customer_data['email'] = sanitize_email( $arguments['email'] );
		}
		if ( isset( $arguments['phone'] ) ) {
			$customer_data['phone'] = sanitize_text_field( $arguments['phone'] );
		}
		if ( isset( $arguments['address'] ) && is_array( $arguments['address'] ) ) {
			$customer_data['address'] = array_map( 'sanitize_text_field', $arguments['address'] );
		}
		if ( isset( $arguments['date_of_birth'] ) ) {
			$customer_data['date_of_birth'] = sanitize_text_field( $arguments['date_of_birth'] );
		}
		if ( isset( $arguments['medical_id'] ) ) {
			$customer_data['medical_id'] = sanitize_text_field( $arguments['medical_id'] );
		}

		$options = array();
		if ( isset( $arguments['timeout'] ) ) {
			$options['timeout'] = max( 5, min( 60, absint( $arguments['timeout'] ) ) );
		}

		if ( 'create' === $action ) {
			$result = $client->create_customer( $customer_data, $options );
		} else {
			$customer_id = sanitize_text_field( $arguments['customer_id'] );
			$result      = $client->update_customer( $customer_id, $customer_data, $options );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Add summary for frontend display.
		$customer_id = isset( $result['id'] ) ? $result['id'] : '';
		$summary     = sprintf(
			/* translators: 1: action, 2: customer ID */
			__( 'Successfully %1$s customer: %2$s', 'mcp-ai-wpoos' ),
			'create' === $action ? __( 'created', 'mcp-ai-wpoos' ) : __( 'updated', 'mcp-ai-wpoos' ),
			$customer_id
		);

		$result = array_merge(
			array(
				'message' => $summary,
				'summary' => $summary,
			),
			$result
		);

		/**
		 * Allow third parties to filter the Flowhub manage customer result.
		 *
		 * @param array $result    Final response payload.
		 * @param array $arguments Original tool arguments.
		 * @param array $context   Invocation context.
		 */
		$result = apply_filters( 'wp_mcp_ai_flowhub_manage_customer_result', $result, $arguments, $context );

		return $result;
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'ecommerce_business',

			'pattern_compatibility' => array( 'orchestrator' ),

			'profession_tags'       => array( 'customer_service_rep', 'sales_manager' ),

			'risk_level'            => 'standard',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'external-api',         // Makes external API calls.
			'requires-credentials', // Requires Flowhub API credentials.
			'requires-capability',  // Requires user capabilities.
			'write',                // Creates or modifies data.
			'state-changing',       // Modifies external system state.
			'pii-data',             // Handles personally identifiable information.
			'rate-limited',         // Subject to Flowhub API rate limits.
		);
	}
}
