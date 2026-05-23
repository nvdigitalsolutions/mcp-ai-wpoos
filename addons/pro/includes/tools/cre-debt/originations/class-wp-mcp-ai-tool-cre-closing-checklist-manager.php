<?php
/**
 * CRE Closing Checklist Manager — Generate and track closing checklists for CRE loans
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates loan-type-specific closing checklists and tracks item completion.
 * Supports CMBS, balance sheet, agency, and debt fund loan types with standard
 * CRE closing items (appraisal, environmental, title, insurance, legal, etc.).
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Closing_Checklist_Manager implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Performs the operation.
	const OPTION_KEY = 'wp_mcp_ai_cre_closing_checklists';

	/**
	 * {@inheritdoc}
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_cre_debt_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason(): string {
		return __( 'CRE Debt & Securitization toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug(): string {
		return 'cre_closing_checklist_manager';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Closing Checklist Manager', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Generate and manage CRE loan closing checklists by loan type (CMBS, balance sheet, agency, debt fund). Track item completion percentage across standard closing requirements: appraisal, environmental, title, survey, insurance, legal, UCC, borrower docs, financials, rent roll, and estoppels.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'                 => array(
					'type'        => 'string',
					'description' => __( 'Checklist action.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'generate', 'update_item', 'get_status' ),
				),
				'deal_id'                => array(
					'type'        => 'string',
					'description' => __( 'Deal identifier to associate checklist with.', 'mcp-ai-wpoos-pro' ),
				),
				'loan_type'              => array(
					'type'        => 'string',
					'description' => __( 'Loan type for checklist generation.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'cmbs', 'balance_sheet', 'agency', 'debt_fund' ),
				),
				'checklist_items_status' => array(
					'type'        => 'object',
					'description' => __( 'Map of item_id => status (pending/in_progress/received/approved/waived) for bulk updates.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'action', 'deal_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'write', 'state-changing' );
	}

	/**
	 * Get required capability.
	 *
	 * @return string
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
	public function execute( array $arguments = array(), array $context = array() ): array|WP_Error {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$action  = sanitize_text_field( $arguments['action'] ?? '' );
		$deal_id = sanitize_text_field( $arguments['deal_id'] ?? '' );

		if ( empty( $deal_id ) ) {
			return new WP_Error( 'missing_field', __( 'deal_id is required.', 'mcp-ai-wpoos-pro' ) );
		}

		switch ( $action ) {
			case 'generate':
				return $this->generate_checklist( $arguments, $deal_id );
			case 'update_item':
				return $this->update_items( $arguments, $deal_id );
			case 'get_status':
				return $this->get_status( $deal_id );
			default:
				return new WP_Error( 'invalid_action', __( 'Invalid action. Use: generate, update_item, or get_status.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Generate a closing checklist for a deal.
	 *
	 * @param array  $arguments Tool arguments.
	 * @param string $deal_id   Deal identifier.
	 * @return array|WP_Error
	 */
	private function generate_checklist( array $arguments, string $deal_id ): array|WP_Error {
		$loan_type = sanitize_text_field( $arguments['loan_type'] ?? '' );
		if ( empty( $loan_type ) ) {
			return new WP_Error( 'missing_field', __( 'loan_type is required for generate action.', 'mcp-ai-wpoos-pro' ) );
		}

		$items      = $this->get_checklist_template( $loan_type );
		$checklists = get_option( self::OPTION_KEY, array() );

		$checklist = array(
			'deal_id'    => $deal_id,
			'loan_type'  => $loan_type,
			'items'      => $items,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		);

		$checklists[ $deal_id ] = $checklist;
		update_option( self::OPTION_KEY, $checklists );

		$summary = $this->calculate_completion( $items );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %1$d: item count, %2$s: loan type */
				__( 'Closing checklist generated with %1$d items for %2$s loan. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
				count( $items ),
				strtoupper( $loan_type )
			),
			'data'    => array(
				'deal_id'    => $deal_id,
				'loan_type'  => $loan_type,
				'items'      => $items,
				'completion' => $summary,
			),
		);
	}

	/**
	 * Update checklist item statuses.
	 *
	 * @param array  $arguments Tool arguments.
	 * @param string $deal_id   Deal identifier.
	 * @return array|WP_Error
	 */
	private function update_items( array $arguments, string $deal_id ): array|WP_Error {
		$checklists = get_option( self::OPTION_KEY, array() );
		if ( ! isset( $checklists[ $deal_id ] ) ) {
			return new WP_Error( 'not_found', __( 'Checklist not found for this deal. Generate one first.', 'mcp-ai-wpoos-pro' ) );
		}

		$status_updates = $arguments['checklist_items_status'] ?? array();
		if ( empty( $status_updates ) || ! is_array( $status_updates ) ) {
			return new WP_Error( 'missing_field', __( 'checklist_items_status is required for update_item action.', 'mcp-ai-wpoos-pro' ) );
		}

		$valid_statuses = array( 'pending', 'in_progress', 'received', 'approved', 'waived' );
		$updated_count  = 0;

		foreach ( $checklists[ $deal_id ]['items'] as &$item ) {
			if ( isset( $status_updates[ $item['item_id'] ] ) ) {
				$new_status = sanitize_text_field( $status_updates[ $item['item_id'] ] );
				if ( in_array( $new_status, $valid_statuses, true ) ) {
					$item['status']     = $new_status;
					$item['updated_at'] = current_time( 'mysql' );
					++$updated_count;
				}
			}
		}
		unset( $item );

		$checklists[ $deal_id ]['updated_at'] = current_time( 'mysql' );
		update_option( self::OPTION_KEY, $checklists );

		$summary = $this->calculate_completion( $checklists[ $deal_id ]['items'] );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of items updated */
				__( '%d item(s) updated. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
				$updated_count
			),
			'data'    => array(
				'deal_id'    => $deal_id,
				'updated'    => $updated_count,
				'items'      => $checklists[ $deal_id ]['items'],
				'completion' => $summary,
			),
		);
	}

	/**
	 * Get current checklist status and completion.
	 *
	 * @param string $deal_id Deal identifier.
	 * @return array|WP_Error
	 */
	private function get_status( string $deal_id ): array|WP_Error {
		$checklists = get_option( self::OPTION_KEY, array() );
		if ( ! isset( $checklists[ $deal_id ] ) ) {
			return new WP_Error( 'not_found', __( 'Checklist not found for this deal.', 'mcp-ai-wpoos-pro' ) );
		}

		$checklist = $checklists[ $deal_id ];
		$summary   = $this->calculate_completion( $checklist['items'] );

		// Group by category.
		$by_category = array();
		foreach ( $checklist['items'] as $item ) {
			$cat = $item['category'];
			if ( ! isset( $by_category[ $cat ] ) ) {
				$by_category[ $cat ] = array(
					'items'    => array(),
					'complete' => 0,
					'total'    => 0,
				);
			}
			$by_category[ $cat ]['items'][] = $item;
			++$by_category[ $cat ]['total'];
			if ( in_array( $item['status'], array( 'approved', 'waived' ), true ) ) {
				++$by_category[ $cat ]['complete'];
			}
		}

		$category_summary = array();
		foreach ( $by_category as $cat => $data ) {
			$pct                      = ( $data['total'] > 0 ) ? round( ( $data['complete'] / $data['total'] ) * 100 ) : 0;
			$category_summary[ $cat ] = $pct . '% (' . $data['complete'] . '/' . $data['total'] . ')';
		}

		// Outstanding items.
		$outstanding = array();
		foreach ( $checklist['items'] as $item ) {
			if ( ! in_array( $item['status'], array( 'approved', 'waived' ), true ) ) {
				$outstanding[] = array(
					'item_id'  => $item['item_id'],
					'name'     => $item['name'],
					'category' => $item['category'],
					'status'   => $item['status'],
				);
			}
		}

		return array(
			'success' => true,
			'message' => __( 'Checklist status retrieved. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'deal_id'           => $deal_id,
				'loan_type'         => $checklist['loan_type'],
				'completion'        => $summary,
				'category_progress' => $category_summary,
				'outstanding_items' => $outstanding,
			),
		);
	}

	/**
	 * Calculate completion percentage.
	 *
	 * @param array $items Checklist items.
	 * @return array
	 */
	private function calculate_completion( array $items ): array {
		$total         = count( $items );
		$complete      = 0;
		$status_counts = array(
			'pending'     => 0,
			'in_progress' => 0,
			'received'    => 0,
			'approved'    => 0,
			'waived'      => 0,
		);

		foreach ( $items as $item ) {
			$s = $item['status'] ?? 'pending';
			if ( isset( $status_counts[ $s ] ) ) {
				++$status_counts[ $s ];
			}
			if ( in_array( $s, array( 'approved', 'waived' ), true ) ) {
				++$complete;
			}
		}

		$pct = ( $total > 0 ) ? round( ( $complete / $total ) * 100, 1 ) : 0;

		return array(
			'total_items'      => $total,
			'complete'         => $complete,
			'remaining'        => $total - $complete,
			'completion_pct'   => $pct . '%',
			'status_breakdown' => $status_counts,
			'ready_to_close'   => ( $complete === $total ) ? __( 'Yes', 'mcp-ai-wpoos-pro' ) : __( 'No', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Get checklist template for a loan type.
	 *
	 * @param string $loan_type Loan type.
	 * @return array Array of checklist items.
	 */
	private function get_checklist_template( string $loan_type ): array {
		// Base items common to all loan types.
		$base_items = array(
			array(
				'item_id'     => 'appraisal',
				'name'        => __( 'Appraisal Report (MAI)', 'mcp-ai-wpoos-pro' ),
				'category'    => 'valuation',
				'required'    => true,
				'description' => __( 'Full appraisal by MAI-designated appraiser, FIRREA compliant.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'item_id'     => 'environmental_phase1',
				'name'        => __( 'Phase I Environmental Site Assessment', 'mcp-ai-wpoos-pro' ),
				'category'    => 'environmental',
				'required'    => true,
				'description' => __( 'ASTM E1527-21 compliant Phase I ESA.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'item_id'     => 'title_commitment',
				'name'        => __( 'Title Insurance Commitment', 'mcp-ai-wpoos-pro' ),
				'category'    => 'title',
				'required'    => true,
				'description' => __( 'Preliminary title report with all exceptions listed.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'item_id'     => 'title_policy',
				'name'        => __( 'Lender Title Insurance Policy', 'mcp-ai-wpoos-pro' ),
				'category'    => 'title',
				'required'    => true,
				'description' => __( 'ALTA lender title insurance policy.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'item_id'     => 'survey',
				'name'        => __( 'ALTA/NSPS Survey', 'mcp-ai-wpoos-pro' ),
				'category'    => 'title',
				'required'    => true,
				'description' => __( 'Current ALTA/NSPS land title survey.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'item_id'     => 'insurance_coi',
				'name'        => __( 'Property Insurance (COI)', 'mcp-ai-wpoos-pro' ),
				'category'    => 'insurance',
				'required'    => true,
				'description' => __( 'Certificate of insurance with lender named as loss payee/additional insured.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'item_id'     => 'legal_opinion',
				'name'        => __( 'Legal Opinion Letter', 'mcp-ai-wpoos-pro' ),
				'category'    => 'legal',
				'required'    => true,
				'description' => __( 'Enforceability, authority, and non-consolidation opinion.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'item_id'     => 'ucc_filing',
				'name'        => __( 'UCC-1 Financing Statement', 'mcp-ai-wpoos-pro' ),
				'category'    => 'legal',
				'required'    => true,
				'description' => __( 'Filed UCC-1 perfecting security interest in personal property.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'item_id'     => 'borrower_org_docs',
				'name'        => __( 'Borrower Organizational Documents', 'mcp-ai-wpoos-pro' ),
				'category'    => 'borrower',
				'required'    => true,
				'description' => __( 'Operating agreement, certificate of formation, good standing certificates.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'item_id'     => 'borrower_auth',
				'name'        => __( 'Borrowing Authorization Resolution', 'mcp-ai-wpoos-pro' ),
				'category'    => 'borrower',
				'required'    => true,
				'description' => __( 'Resolution authorizing the loan transaction.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'item_id'     => 'financial_statements',
				'name'        => __( 'Borrower Financial Statements (3 years)', 'mcp-ai-wpoos-pro' ),
				'category'    => 'financial',
				'required'    => true,
				'description' => __( 'Three years of audited or CPA-prepared financial statements.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'item_id'     => 'property_financials',
				'name'        => __( 'Property Operating Statements (3 years)', 'mcp-ai-wpoos-pro' ),
				'category'    => 'financial',
				'required'    => true,
				'description' => __( 'Three years of historical operating statements plus T-12 and YTD.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'item_id'     => 'rent_roll',
				'name'        => __( 'Current Rent Roll', 'mcp-ai-wpoos-pro' ),
				'category'    => 'financial',
				'required'    => true,
				'description' => __( 'Current certified rent roll with lease terms and expirations.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'item_id'     => 'estoppels',
				'name'        => __( 'Tenant Estoppel Certificates', 'mcp-ai-wpoos-pro' ),
				'category'    => 'financial',
				'required'    => true,
				'description' => __( 'Signed estoppels from major tenants confirming lease terms.', 'mcp-ai-wpoos-pro' ),
			),
		);

		// Loan-type-specific additions.
		$type_items = array();

		switch ( $loan_type ) {
			case 'cmbs':
				$type_items = array(
					array(
						'item_id'     => 'cmbs_rating_letter',
						'name'        => __( 'Rating Agency Confirmation', 'mcp-ai-wpoos-pro' ),
						'category'    => 'cmbs_specific',
						'required'    => true,
						'description' => __( 'Rating agency confirmation for securitization.', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'item_id'     => 'cmbs_spv_docs',
						'name'        => __( 'SPE/SPV Documentation', 'mcp-ai-wpoos-pro' ),
						'category'    => 'cmbs_specific',
						'required'    => true,
						'description' => __( 'Single-purpose entity documentation and separateness covenants.', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'item_id'     => 'cmbs_servicer_agreement',
						'name'        => __( 'Servicing Agreement', 'mcp-ai-wpoos-pro' ),
						'category'    => 'cmbs_specific',
						'required'    => true,
						'description' => __( 'Master and special servicer agreements.', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'item_id'     => 'cmbs_seismic',
						'name'        => __( 'Seismic Risk Assessment (if applicable)', 'mcp-ai-wpoos-pro' ),
						'category'    => 'engineering',
						'required'    => false,
						'description' => __( 'PML assessment for properties in seismic zones.', 'mcp-ai-wpoos-pro' ),
					),
				);
				break;

			case 'agency':
				$type_items = array(
					array(
						'item_id'     => 'agency_pca',
						'name'        => __( 'Property Condition Assessment (PCA)', 'mcp-ai-wpoos-pro' ),
						'category'    => 'engineering',
						'required'    => true,
						'description' => __( 'ASTM E2018 compliant property condition report.', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'item_id'     => 'agency_replacement_reserve',
						'name'        => __( 'Replacement Reserve Schedule', 'mcp-ai-wpoos-pro' ),
						'category'    => 'agency_specific',
						'required'    => true,
						'description' => __( 'Annual replacement reserve escrow schedule.', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'item_id'     => 'agency_lura',
						'name'        => __( 'LURA/Regulatory Agreement (if applicable)', 'mcp-ai-wpoos-pro' ),
						'category'    => 'agency_specific',
						'required'    => false,
						'description' => __( 'Land use restriction agreement for affordable housing.', 'mcp-ai-wpoos-pro' ),
					),
				);
				break;

			case 'debt_fund':
				$type_items = array(
					array(
						'item_id'     => 'df_business_plan',
						'name'        => __( 'Borrower Business Plan', 'mcp-ai-wpoos-pro' ),
						'category'    => 'debt_fund_specific',
						'required'    => true,
						'description' => __( 'Detailed business plan with renovation budget and timeline.', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'item_id'     => 'df_construction_budget',
						'name'        => __( 'Construction/Renovation Budget', 'mcp-ai-wpoos-pro' ),
						'category'    => 'debt_fund_specific',
						'required'    => true,
						'description' => __( 'Detailed itemized budget with contractor bids.', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'item_id'     => 'df_exit_strategy',
						'name'        => __( 'Exit Strategy Analysis', 'mcp-ai-wpoos-pro' ),
						'category'    => 'debt_fund_specific',
						'required'    => true,
						'description' => __( 'Documented exit strategy (sale, refinance, or hold).', 'mcp-ai-wpoos-pro' ),
					),
				);
				break;

			case 'balance_sheet':
				$type_items = array(
					array(
						'item_id'     => 'bs_guaranty',
						'name'        => __( 'Personal/Corporate Guaranty', 'mcp-ai-wpoos-pro' ),
						'category'    => 'balance_sheet_specific',
						'required'    => true,
						'description' => __( 'Guaranty agreement from sponsor/principals.', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'item_id'     => 'bs_deposit_relationship',
						'name'        => __( 'Deposit Relationship Documentation', 'mcp-ai-wpoos-pro' ),
						'category'    => 'balance_sheet_specific',
						'required'    => false,
						'description' => __( 'Evidence of deposit relationship for pricing benefit.', 'mcp-ai-wpoos-pro' ),
					),
				);
				break;
		}

		// Merge and set default status.
		$all_items = array_merge( $base_items, $type_items );
		foreach ( $all_items as &$item ) {
			$item['status']     = 'pending';
			$item['updated_at'] = '';
		}
		unset( $item );

		return $all_items;
	}
}
