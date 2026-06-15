<?php
/**
 * Billing Compliance Checker Tool
 *
 * Checks billing entries for UTBMS compliance, block billing, and rate issues.
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
 * Validates billing entries against compliance standards.
 */
class WP_MCP_AI_Tool_LF_Billing_Compliance_Checker implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

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
		return __( 'Law Firm toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'lf_billing_compliance_checker'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Billing Compliance Checker', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Checks billing entries for UTBMS code compliance, block billing patterns, and rate compliance issues.', 'mcp-ai-wpoos-pro' ); }


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'matter_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Matter ID to check.', 'mcp-ai-wpoos-pro' ),
				),
				'check_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of compliance check.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'utbms', 'block_billing', 'rate_compliance', 'all' ),
				),
			),
			'required'   => array( 'matter_id' ),
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only', 'cacheable' ); }

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
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		require_once dirname( __DIR__ ) . '/class-wp-mcp-ai-law-firm-calculator.php';

		$matter_id  = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;
		$check_type = isset( $arguments['check_type'] ) ? sanitize_text_field( $arguments['check_type'] ) : 'all';

		if ( ! $matter_id ) {
			return new WP_Error( 'missing_required', __( 'Matter ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$entries = get_posts(
			array(
				'post_type'      => 'mcp_ai_lf_time_entry',
				'posts_per_page' => 500,
				'meta_query'     => array(
					array(
						'key'   => '_lf_matter_id',
						'value' => $matter_id,
					),
				), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);

		if ( empty( $entries ) ) {
			return new WP_Error( 'no_entries', __( 'No time entries found for this matter.', 'mcp-ai-wpoos-pro' ) );
		}

		$issues = array();

		foreach ( $entries as $entry ) {
			$entry_id = $entry->ID;
			$utbms    = get_post_meta( $entry_id, '_lf_utbms_code', true );
			$hours    = (float) get_post_meta( $entry_id, '_lf_hours', true );
			$rate     = (float) get_post_meta( $entry_id, '_lf_rate', true );
			$desc     = $entry->post_content;

			if ( 'all' === $check_type || 'utbms' === $check_type ) {
				if ( empty( $utbms ) ) {
					$issues[] = array(
						'entry_id' => $entry_id,
						'type'     => 'missing_utbms',
						'message'  => __( 'Missing UTBMS code.', 'mcp-ai-wpoos-pro' ),
					);
				} elseif ( ! WP_MCP_AI_Law_Firm_Calculator::validate_utbms_code( $utbms )['is_valid'] ) {
					$issues[] = array(
						'entry_id' => $entry_id,
						'type'     => 'invalid_utbms',
						'message'  => sprintf(
							/* translators: %s: UTBMS code */
							__( 'Invalid UTBMS code: %s', 'mcp-ai-wpoos-pro' ),
							$utbms
						),
					);
				}
			}

			if ( 'all' === $check_type || 'block_billing' === $check_type ) {
				$block = WP_MCP_AI_Law_Firm_Calculator::detect_block_billing( $desc );
				if ( $block['is_block_billed'] ) {
					$issues[] = array(
						'entry_id' => $entry_id,
						'type'     => 'block_billing',
						'message'  => $block['suggestion'],
					);
				}
			}

			if ( 'all' === $check_type || 'rate_compliance' === $check_type ) {
				if ( $hours > 12 ) {
					$issues[] = array(
						'entry_id' => $entry_id,
						'type'     => 'excessive_hours',
						'message'  => sprintf(
							/* translators: %s: number of hours */
							__( 'Excessive hours in single entry: %s', 'mcp-ai-wpoos-pro' ),
							$hours
						),
					);
				}
				if ( $rate <= 0 && 'billable' === get_post_meta( $entry_id, '_lf_billing_type', true ) ) {
					$issues[] = array(
						'entry_id' => $entry_id,
						'type'     => 'zero_rate',
						'message'  => __( 'Billable entry with zero rate.', 'mcp-ai-wpoos-pro' ),
					);
				}
			}
		}

		$status = empty( $issues ) ? 'compliant' : ( count( $issues ) > 5 ? 'non_compliant' : 'needs_review' );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %1$d: number of issues found, %2$s: compliance status */
				__( 'Compliance check complete: %1$d issues found. Status: %2$s. ', 'mcp-ai-wpoos-pro' ),
				count( $issues ),
				$status
			) . self::DISCLAIMER,
			'data'       => array(
				'matter_id'         => $matter_id,
				'entries_checked'   => count( $entries ),
				'compliance_issues' => $issues,
				'total_issues'      => count( $issues ),
				'overall_status'    => $status,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
