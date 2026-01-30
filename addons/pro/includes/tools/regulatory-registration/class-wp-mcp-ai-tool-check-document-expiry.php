<?php
/**
 * Tool for checking document expiry in the Regulatory Registration system.
 *
 * Allows AI assistants to check document expiry status and get expiry alerts.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks document expiry status.
 */
class WP_MCP_AI_Tool_Check_Document_Expiry implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'check_document_expiry';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Check Document Expiry', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Checks expiry status of documents and returns expired or soon-to-expire documents that need renewal. Critical for maintaining compliance.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'warning_days'     => array(
					'type'        => 'integer',
					'description' => __( 'Days ahead to warn about expiry (optional, default: 90)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 365,
					'default'     => 90,
				),
				'product_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Check only documents for specific product (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'registration_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Check only documents for specific registration (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-read',        // Reads from database.
			'read-only',            // Does not modify state.
			'cacheable',            // Results can be cached.
			'idempotent',           // Can be called multiple times safely with same result.
		);
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
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to check documents.', 'mcp-ai-wpoos-pro' ) );
		}

		$warning_days = isset( $arguments['warning_days'] ) ? absint( $arguments['warning_days'] ) : 90;
		$today = time();
		$warning_threshold = $today + ( $warning_days * DAY_IN_SECONDS );

		// Build query args.
		$query_args = array(
			'post_type'      => 'mcp_ai_reg_document',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_query'     => array(
				array(
					'key'     => 'expiry_date',
					'value'   => '',
					'compare' => '!=',
				),
			),
		);

		// Filter by product or registration.
		if ( ! empty( $arguments['product_id'] ) || ! empty( $arguments['registration_id'] ) ) {
			$query_args['meta_query']['relation'] = 'AND';

			if ( ! empty( $arguments['product_id'] ) ) {
				$query_args['meta_query'][] = array(
					'key'   => 'product_id',
					'value' => absint( $arguments['product_id'] ),
				);
			}

			if ( ! empty( $arguments['registration_id'] ) ) {
				$query_args['meta_query'][] = array(
					'key'   => 'registration_id',
					'value' => absint( $arguments['registration_id'] ),
				);
			}
		}

		$query = new WP_Query( $query_args );

		$expired_documents = array();
		$expiring_soon = array();
		$valid_documents = array();

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$expiry_date = get_post_meta( $post->ID, 'expiry_date', true );
				if ( ! $expiry_date ) {
					continue;
				}

				$expiry = strtotime( $expiry_date );
				$days_to_expiry = floor( ( $expiry - $today ) / DAY_IN_SECONDS );

				$doc_data = array(
					'id'              => $post->ID,
					'title'           => $post->post_title,
					'document_type'   => get_post_meta( $post->ID, 'document_type', true ),
					'expiry_date'     => $expiry_date,
					'days_to_expiry'  => $days_to_expiry,
					'product_id'      => absint( get_post_meta( $post->ID, 'product_id', true ) ),
					'registration_id' => absint( get_post_meta( $post->ID, 'registration_id', true ) ),
				);

				// Get document type from taxonomy.
				$doc_types = wp_get_post_terms( $post->ID, 'mcp_ai_doc_type' );
				if ( ! empty( $doc_types ) && ! is_wp_error( $doc_types ) ) {
					$doc_data['document_type'] = $doc_types[0]->name;
				}

				if ( $expiry < $today ) {
					// Already expired.
					$doc_data['status'] = 'expired';
					$expired_documents[] = $doc_data;
				} elseif ( $expiry < $warning_threshold ) {
					// Expiring soon (within warning period).
					$doc_data['status'] = 'expiring_soon';
					$expiring_soon[] = $doc_data;
				} else {
					// Still valid.
					$doc_data['status'] = 'valid';
					$valid_documents[] = $doc_data;
				}
			}
		}

		// Sort by days to expiry (most urgent first).
		usort( $expired_documents, function( $a, $b ) {
			return $b['days_to_expiry'] - $a['days_to_expiry'];
		});

		usort( $expiring_soon, function( $a, $b ) {
			return $a['days_to_expiry'] - $b['days_to_expiry'];
		});

		$alert_level = 'ok';
		if ( count( $expired_documents ) > 0 ) {
			$alert_level = 'critical';
		} elseif ( count( $expiring_soon ) > 0 ) {
			$alert_level = 'warning';
		}

		return array(
			'success'           => true,
			'alert_level'       => $alert_level,
			'expired_count'     => count( $expired_documents ),
			'expiring_soon_count' => count( $expiring_soon ),
			'valid_count'       => count( $valid_documents ),
			'expired_documents' => $expired_documents,
			'expiring_soon'     => $expiring_soon,
			'warning_days'      => $warning_days,
			'summary'           => sprintf(
				/* translators: 1: expired count, 2: expiring soon count, 3: valid count */
				__( '%1$d expired, %2$d expiring soon (within %4$d days), %3$d valid', 'mcp-ai-wpoos-pro' ),
				count( $expired_documents ),
				count( $expiring_soon ),
				count( $valid_documents ),
				$warning_days
			),
		);
	}
}
