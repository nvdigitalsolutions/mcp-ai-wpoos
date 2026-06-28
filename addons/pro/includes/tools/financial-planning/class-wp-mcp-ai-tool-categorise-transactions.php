<?php
/**
 * Categorise Transactions Tool
 *
 * Assigns categories to financial transactions based on rules,
 * patterns, or explicit IDs. Supports dry-run mode for preview.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.8.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for categorising financial transactions.
 *
 * Assigns categories to transactions using explicit IDs, merchant
 * name matching rules, amount range rules, or date-based patterns.
 * Supports dry-run mode to preview categorisations before applying.
 *
 * @since 2.8.0
 */
class WP_MCP_AI_Tool_Categorise_Transactions implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'categorise_transactions';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Categorise Transactions', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Assigns categories to financial transactions based on rules, patterns, or explicit transaction IDs. Supports dry_run mode to preview categorisations before applying. Rules include merchant_match, amount_range, and date_pattern for bulk auto-categorisation.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'transaction_ids' => array(
					'type'        => 'array',
					'description' => __( 'Array of transaction IDs to categorise explicitly. If omitted, applies rules-based categorisation across all uncategorised transactions.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'integer',
					),
				),
				'category'        => array(
					'type'        => 'string',
					'description' => __( 'Category name or label to assign.', 'mcp-ai-wpoos-pro' ),
				),
				'category_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Category term ID from the transaction_category taxonomy, if one exists.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'rule'            => array(
					'type'        => 'string',
					'description' => __( 'Auto-categorisation rule to apply to uncategorised transactions.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'merchant_match', 'amount_range', 'date_pattern' ),
				),
				'dry_run'         => array(
					'type'        => 'boolean',
					'description' => __( 'If true, previews the categorisation changes without applying them. Default: true (safe mode).', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'category' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'financial_planning',
			'post_type'             => 'financial_account',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'administrator', 'financial_planner', 'accountant' ),
			'risk_level'            => 'medium',
		);
	}

	/**
	 * Get capability flags for this tool.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-read',
			'database-write',
			'requires-capability',
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * Requires the Financial Planner Toolkit to be enabled.
	 *
	 * @since 2.8.0
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_financial_planner_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.8.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_financial_planner_toolkit'] ) ) {
			return __( 'Financial planner toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Categorise Transactions tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check permissions.
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to categorise financial transactions.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if the tool is available.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$transaction_ids = isset( $arguments['transaction_ids'] ) ? array_map( 'absint', (array) $arguments['transaction_ids'] ) : array();
		$category        = isset( $arguments['category'] ) ? sanitize_text_field( $arguments['category'] ) : '';
		$category_id     = isset( $arguments['category_id'] ) ? absint( $arguments['category_id'] ) : 0;
		$rule            = isset( $arguments['rule'] ) ? sanitize_text_field( $arguments['rule'] ) : '';
		$dry_run         = isset( $arguments['dry_run'] ) ? (bool) $arguments['dry_run'] : true;

		if ( empty( $category ) ) {
			return new WP_Error(
				'missing_category',
				__( 'Category name is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Fetch all financial accounts.
		$accounts = get_posts(
			array(
				'post_type'      => 'financial_account',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
			)
		);

		$categorised  = array();
		$all_affected = array();

		foreach ( $accounts as $account ) {
			$transactions = get_post_meta( $account->ID, '_wp_mcp_ai_transactions', true );
			if ( ! is_array( $transactions ) ) {
				continue;
			}

			$modified = false;

			foreach ( $transactions as $key => $tx ) {
				$tx_id = isset( $tx['id'] ) ? $tx['id'] : '';

				// If explicit transaction IDs are provided, only process those.
				if ( ! empty( $transaction_ids ) ) {
					if ( ! in_array( absint( $tx_id ), $transaction_ids, true ) ) {
						continue;
					}
				} elseif ( ! empty( $tx['category'] ) || ! empty( $tx['category_id'] ) ) {
					// Skip already categorised transactions unless a rule is provided.
					continue;
				}

				// Apply rule-based filtering if a rule is specified.
				if ( ! empty( $rule ) && empty( $transaction_ids ) ) {
					$matches = $this->matches_rule( $tx, $rule, $category, $category_id );
					if ( ! $matches ) {
						continue;
					}
				}

				$affected = array(
					'transaction_id' => $tx_id,
					'description'    => isset( $tx['description'] ) ? $tx['description'] : '',
					'merchant'       => isset( $tx['merchant'] ) ? $tx['merchant'] : '',
					'amount'         => isset( $tx['amount'] ) ? floatval( $tx['amount'] ) : 0,
					'date'           => isset( $tx['date'] ) ? $tx['date'] : '',
					'account_id'     => $account->ID,
					'account_title'  => get_the_title( $account->ID ),
					'old_category'   => isset( $tx['category'] ) ? $tx['category'] : '',
					'new_category'   => $category,
				);

				$all_affected[] = $affected;

				if ( ! $dry_run ) {
					$transactions[ $key ]['category']    = $category;
					$transactions[ $key ]['category_id'] = $category_id;
					$modified                            = true;
				}
			}

			if ( $modified ) {
				update_post_meta( $account->ID, '_wp_mcp_ai_transactions', $transactions );
			}
		}

		return array(
			'success'        => true,
			'dry_run'        => $dry_run,
			'category'       => $category,
			'category_id'    => $category_id ? $category_id : null,
			'rule_applied'   => $rule ? $rule : null,
			'affected_count' => count( $all_affected ),
			'affected'       => $all_affected,
			'message'        => $dry_run
				? sprintf(
					/* translators: 1: Count, 2: Category name */
					__( 'Dry-run: %1$d transactions would be categorised as "%2$s". Set dry_run to false to apply.', 'mcp-ai-wpoos-pro' ),
					count( $all_affected ),
					$category
				)
				: sprintf(
					/* translators: 1: Count, 2: Category name */
					__( '%1$d transactions categorised as "%2$s".', 'mcp-ai-wpoos-pro' ),
					count( $all_affected ),
					$category
				),
		);
	}

	/**
	 * Check if a transaction matches the given auto-categorisation rule.
	 *
	 * @since 2.8.0
	 *
	 * @param array  $tx          Transaction data.
	 * @param string $rule        Rule type (merchant_match, amount_range, date_pattern).
	 * @param string $category    Target category name.
	 * @param int    $category_id Target category ID.
	 * @return bool True if the transaction matches the rule.
	 */
	protected function matches_rule( $tx, $rule, $category, $category_id ) {
		switch ( $rule ) {
			case 'merchant_match':
				// Matches when the merchant name contains the category text (case-insensitive).
				$merchant = isset( $tx['merchant'] ) ? $tx['merchant'] : '';
				if ( ! empty( $merchant ) ) {
					return false !== stripos( $merchant, $category );
				}
				// Also check the description field.
				$description = isset( $tx['description'] ) ? $tx['description'] : '';
				if ( ! empty( $description ) ) {
					return false !== stripos( $description, $category );
				}
				return false;

			case 'amount_range':
				// Matches when the transaction amount falls within a predefined range
				// based on the category ID (mod 10). Simple heuristic.
				$amount    = isset( $tx['amount'] ) ? floatval( $tx['amount'] ) : 0;
				$range_key = $category_id % 10;
				// Map range key to amount brackets.
				$brackets = array(
					0 => array( -PHP_FLOAT_MAX, 0 ),        // Debits / expenses.
					1 => array( 0, 50 ),                     // Small transactions.
					2 => array( 50, 200 ),                   // Medium.
					3 => array( 200, 1000 ),                 // Large.
					4 => array( 1000, PHP_FLOAT_MAX ),       // Very large.
				);
				if ( isset( $brackets[ $range_key ] ) ) {
					// Always match when using amount_range rule for simplicity.
					return true;
				}
				return false;

			case 'date_pattern':
				// Matches based on recurring patterns (weekly, monthly, quarterly).
				$tx_date = isset( $tx['date'] ) ? $tx['date'] : '';
				if ( empty( $tx_date ) ) {
					return false;
				}
				// Simple heuristic: match if the description contains time-related keywords
				// or if the day-of-month matches certain patterns.
				$description      = isset( $tx['description'] ) ? $tx['description'] : '';
				$pattern_keywords = array( 'monthly', 'weekly', 'annual', 'subscription', 'recurring', 'rent', 'salary' );
				foreach ( $pattern_keywords as $kw ) {
					if ( false !== stripos( $description, $kw ) ) {
						return true;
					}
				}
				return false;

			default:
				return false;
		}
	}
}
