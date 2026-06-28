<?php
/**
 * CLE Credit Tracker Tool
 *
 * Manages Continuing Legal Education credit records for attorneys.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages CLE credit records for attorneys via user meta.
 */
class WP_MCP_AI_Tool_LF_CLE_Credit_Tracker implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * User meta key for CLE credits.
	 *
	 * @var string
	 */
	const META_KEY = '_lf_cle_credits';

	/**
	 * Check if the tool is available.
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
	 * Get the reason the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason(): string {
		return __( 'Law Firm toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'lf_cle_credit_tracker';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'CLE Credit Tracker', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Manages Continuing Legal Education (CLE) credit records for attorneys, including adding, listing, summarizing, and deleting credit entries stored in user meta.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'      => array(
					'type'        => 'string',
					'description' => __( 'Action to perform on CLE credit records.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'add', 'list', 'get_summary', 'delete' ),
				),
				'attorney_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress user ID of the attorney.', 'mcp-ai-wpoos-pro' ),
				),
				'course_name' => array(
					'type'        => 'string',
					'description' => __( 'Name of the CLE course (required for add action).', 'mcp-ai-wpoos-pro' ),
				),
				'credits'     => array(
					'type'        => 'number',
					'description' => __( 'Number of CLE credits earned (required for add action).', 'mcp-ai-wpoos-pro' ),
				),
				'date'        => array(
					'type'        => 'string',
					'description' => __( 'Date the course was completed in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ),
				),
				'category'    => array(
					'type'        => 'string',
					'description' => __( 'Category of CLE credit.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'general', 'ethics', 'diversity', 'technology', 'substance_abuse' ),
				),
				'provider'    => array(
					'type'        => 'string',
					'description' => __( 'Name of the CLE provider or accrediting organization.', 'mcp-ai-wpoos-pro' ),
				),
				'credit_id'   => array(
					'type'        => 'string',
					'description' => __( 'Unique credit entry ID (required for delete action).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'action', 'attorney_id' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'write', 'state-changing' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied. Requires manage_options capability.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$action      = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';
		$attorney_id = isset( $arguments['attorney_id'] ) ? absint( $arguments['attorney_id'] ) : 0;

		if ( empty( $action ) ) {
			return new WP_Error( 'missing_required', __( 'Action is required.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! $attorney_id ) {
			return new WP_Error( 'missing_required', __( 'Attorney ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$attorney_user = get_userdata( $attorney_id );
		if ( ! $attorney_user ) {
			return new WP_Error( 'invalid_attorney', __( 'Attorney user not found.', 'mcp-ai-wpoos-pro' ) );
		}

		switch ( $action ) {
			case 'add':
				return $this->add_credit( $attorney_id, $arguments );
			case 'list':
				return $this->list_credits( $attorney_id );
			case 'get_summary':
				return $this->get_summary( $attorney_id );
			case 'delete':
				return $this->delete_credit( $attorney_id, $arguments );
			default:
				return new WP_Error( 'invalid_action', __( 'Invalid action specified.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Add a CLE credit entry.
	 *
	 * @param int   $attorney_id Attorney user ID.
	 * @param array $arguments   Tool arguments.
	 * @return array|WP_Error
	 */
	private function add_credit( $attorney_id, $arguments ) {
		$course_name = isset( $arguments['course_name'] ) ? sanitize_text_field( $arguments['course_name'] ) : '';
		$credits     = isset( $arguments['credits'] ) ? floatval( $arguments['credits'] ) : 0;
		$date        = isset( $arguments['date'] ) ? sanitize_text_field( $arguments['date'] ) : gmdate( 'Y-m-d' );
		$category    = isset( $arguments['category'] ) ? sanitize_text_field( $arguments['category'] ) : 'general';
		$provider    = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : '';

		if ( empty( $course_name ) ) {
			return new WP_Error( 'missing_required', __( 'Course name is required for add action.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $credits <= 0 ) {
			return new WP_Error( 'invalid_credits', __( 'Credits must be a positive number.', 'mcp-ai-wpoos-pro' ) );
		}

		$valid_categories = array( 'general', 'ethics', 'diversity', 'technology', 'substance_abuse' );
		if ( ! in_array( $category, $valid_categories, true ) ) {
			$category = 'general';
		}

		$existing_credits = get_user_meta( $attorney_id, self::META_KEY, true );
		if ( ! is_array( $existing_credits ) ) {
			$existing_credits = array();
		}

		$credit_id = 'cle_' . wp_generate_password( 12, false );

		$new_credit = array(
			'id'          => $credit_id,
			'course_name' => $course_name,
			'credits'     => $credits,
			'date'        => $date,
			'category'    => $category,
			'provider'    => $provider,
			'added_by'    => get_current_user_id(),
			'added_at'    => gmdate( 'Y-m-d H:i:s' ),
		);

		$existing_credits[] = $new_credit;
		update_user_meta( $attorney_id, self::META_KEY, $existing_credits );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: credits count, 2: course name */
				__( 'Added %1$s CLE credits for "%2$s".', 'mcp-ai-wpoos-pro' ),
				$credits,
				$course_name
			) . ' ' . self::DISCLAIMER,
			'data'       => array(
				'credit_id'     => $credit_id,
				'credit_entry'  => $new_credit,
				'total_credits' => $this->calculate_total_credits( $existing_credits ),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * List all CLE credits for an attorney.
	 *
	 * @param int $attorney_id Attorney user ID.
	 * @return array
	 */
	private function list_credits( $attorney_id ) {
		$credits = get_user_meta( $attorney_id, self::META_KEY, true );
		if ( ! is_array( $credits ) ) {
			$credits = array();
		}

		// Sort by date descending.
		usort(
			$credits,
			function ( $a, $b ) {
				return strcmp( $b['date'], $a['date'] );
			}
		);

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %d: number of credits */
				__( 'Found %d CLE credit entries.', 'mcp-ai-wpoos-pro' ),
				count( $credits )
			) . ' ' . self::DISCLAIMER,
			'data'       => array(
				'credits'       => $credits,
				'total_entries' => count( $credits ),
				'total_credits' => $this->calculate_total_credits( $credits ),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * Get a summary of CLE credits by category and year.
	 *
	 * @param int $attorney_id Attorney user ID.
	 * @return array
	 */
	private function get_summary( $attorney_id ) {
		$credits = get_user_meta( $attorney_id, self::META_KEY, true );
		if ( ! is_array( $credits ) ) {
			$credits = array();
		}

		$by_category = array();
		$by_year     = array();

		foreach ( $credits as $credit ) {
			$cat  = isset( $credit['category'] ) ? $credit['category'] : 'general';
			$year = substr( $credit['date'], 0, 4 );
			$amt  = floatval( $credit['credits'] );

			if ( ! isset( $by_category[ $cat ] ) ) {
				$by_category[ $cat ] = 0;
			}
			$by_category[ $cat ] += $amt;

			if ( ! isset( $by_year[ $year ] ) ) {
				$by_year[ $year ] = 0;
			}
			$by_year[ $year ] += $amt;
		}

		krsort( $by_year );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %s: total credits */
				__( 'CLE credit summary: %s total credits on record.', 'mcp-ai-wpoos-pro' ),
				$this->calculate_total_credits( $credits )
			) . ' ' . self::DISCLAIMER,
			'data'       => array(
				'total_credits' => $this->calculate_total_credits( $credits ),
				'total_entries' => count( $credits ),
				'by_category'   => $by_category,
				'by_year'       => $by_year,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * Delete a specific CLE credit entry.
	 *
	 * @param int   $attorney_id Attorney user ID.
	 * @param array $arguments   Tool arguments.
	 * @return array|WP_Error
	 */
	private function delete_credit( $attorney_id, $arguments ) {
		$credit_id = isset( $arguments['credit_id'] ) ? sanitize_text_field( $arguments['credit_id'] ) : '';

		if ( empty( $credit_id ) ) {
			return new WP_Error( 'missing_required', __( 'Credit ID is required for delete action.', 'mcp-ai-wpoos-pro' ) );
		}

		$credits = get_user_meta( $attorney_id, self::META_KEY, true );
		if ( ! is_array( $credits ) ) {
			return new WP_Error( 'not_found', __( 'No CLE credits found for this attorney.', 'mcp-ai-wpoos-pro' ) );
		}

		$found_index = -1;
		$deleted     = null;
		foreach ( $credits as $index => $credit ) {
			if ( isset( $credit['id'] ) && $credit['id'] === $credit_id ) {
				$found_index = $index;
				$deleted     = $credit;
				break;
			}
		}

		if ( -1 === $found_index ) {
			return new WP_Error( 'not_found', __( 'Credit entry not found.', 'mcp-ai-wpoos-pro' ) );
		}

		array_splice( $credits, $found_index, 1 );
		update_user_meta( $attorney_id, self::META_KEY, $credits );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %s: course name */
				__( 'Deleted CLE credit entry for "%s".', 'mcp-ai-wpoos-pro' ),
				$deleted['course_name']
			) . ' ' . self::DISCLAIMER,
			'data'       => array(
				'deleted_entry'     => $deleted,
				'remaining_credits' => $this->calculate_total_credits( $credits ),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * Calculate total credits from an array of credit entries.
	 *
	 * @param array $credits Array of credit entries.
	 * @return float
	 */
	private function calculate_total_credits( $credits ) {
		$total = 0;
		foreach ( $credits as $credit ) {
			$total += floatval( $credit['credits'] );
		}
		return round( $total, 1 );
	}
}
