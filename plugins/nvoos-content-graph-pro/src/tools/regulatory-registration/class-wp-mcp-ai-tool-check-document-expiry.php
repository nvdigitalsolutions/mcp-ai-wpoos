<?php
/**
 * WP_MCP_AI_Tool_Check_Document_Expiry (ecosystem port - Wave F2, regulatory-registration tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/regulatory-registration/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger seams resolve from the addon's D8-compat `src/` copies (the monorepo root classmap serves the base copies monolith).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Regulatory_Registration
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface and
// Logger requires gain exists-check seams resolving from the addon's
// D8-compat copies (the monorepo root classmap serves the base copies
// monolith).
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
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
		return __( 'Check Document Expiry', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Checks expiry status of documents and returns expired or soon-to-expire documents that need renewal. Critical for maintaining compliance.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'warning_days'    => array(
					'type'        => 'integer',
					'description' => __( 'Days ahead to warn about expiry (optional, default: 90)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
					'maximum'     => 365,
					'default'     => 90,
				),
				'product_id'      => array(
					'type'        => 'integer',
					'description' => __( 'Check only documents for specific product (optional)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'registration_id' => array(
					'type'        => 'integer',
					'description' => __( 'Check only documents for specific registration (optional)', 'nvoos-content-graph-pro' ),
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
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to check documents.', 'nvoos-content-graph-pro' ) );
		}

		$warning_days      = isset( $arguments['warning_days'] ) ? absint( $arguments['warning_days'] ) : 90;
		$today             = time();
		$warning_threshold = $today + ( $warning_days * DAY_IN_SECONDS );

		// Build query args.
		$query_args = array(
			'post_type'      => 'mcp_ai_reg_document',
			'post_status'    => 'publish',
			'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'check_document_expiry', 0, 1000 ) : 1000,
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
		$expiring_soon     = array();
		$valid_documents   = array();

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$expiry_date = get_post_meta( $post->ID, 'expiry_date', true );
				if ( ! $expiry_date ) {
					continue;
				}

				$expiry         = strtotime( $expiry_date );
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
					$doc_data['status']  = 'expired';
					$expired_documents[] = $doc_data;
				} elseif ( $expiry < $warning_threshold ) {
					// Expiring soon (within warning period).
					$doc_data['status'] = 'expiring_soon';
					$expiring_soon[]    = $doc_data;
				} else {
					// Still valid.
					$doc_data['status'] = 'valid';
					$valid_documents[]  = $doc_data;
				}
			}
		}

		// Sort by days to expiry (most urgent first).
		usort(
			$expired_documents,
			function ( $a, $b ) {
				return $b['days_to_expiry'] - $a['days_to_expiry'];
			}
		);

		usort(
			$expiring_soon,
			function ( $a, $b ) {
				return $a['days_to_expiry'] - $b['days_to_expiry'];
			}
		);

		$alert_level = 'ok';
		if ( count( $expired_documents ) > 0 ) {
			$alert_level = 'critical';
		} elseif ( count( $expiring_soon ) > 0 ) {
			$alert_level = 'warning';
		}

		return array(
			'success'             => true,
			'alert_level'         => $alert_level,
			'expired_count'       => count( $expired_documents ),
			'expiring_soon_count' => count( $expiring_soon ),
			'valid_count'         => count( $valid_documents ),
			'expired_documents'   => $expired_documents,
			'expiring_soon'       => $expiring_soon,
			'warning_days'        => $warning_days,
			'summary'             => sprintf(
				/* translators: 1: expired count, 2: expiring soon count, 3: valid count */
				__( '%1$d expired, %2$d expiring soon (within %4$d days), %3$d valid', 'nvoos-content-graph-pro' ),
				count( $expired_documents ),
				count( $expiring_soon ),
				count( $valid_documents ),
				$warning_days
			),
		);
	}
}
