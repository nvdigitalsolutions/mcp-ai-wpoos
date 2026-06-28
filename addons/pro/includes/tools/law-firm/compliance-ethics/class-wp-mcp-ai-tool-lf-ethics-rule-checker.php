<?php
/**
 * Ethics Rule Checker Tool
 *
 * Checks ABA Model Rules of Professional Conduct against specific scenarios.
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
 * Checks ABA Model Rules of Professional Conduct for ethical compliance.
 */
class WP_MCP_AI_Tool_LF_Ethics_Rule_Checker implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * ABA Model Rules mapped by category.
	 *
	 * @var array
	 */
	private static $aba_rules = array(
		'competence'           => array(
			array(
				'rule'    => '1.1',
				'title'   => 'Competence',
				'summary' => 'A lawyer shall provide competent representation to a client, requiring legal knowledge, skill, thoroughness and preparation.',
			),
		),
		'confidentiality'      => array(
			array(
				'rule'    => '1.6',
				'title'   => 'Confidentiality of Information',
				'summary' => 'A lawyer shall not reveal information relating to the representation of a client unless the client gives informed consent.',
			),
			array(
				'rule'    => '1.9',
				'title'   => 'Duties to Former Clients',
				'summary' => 'A lawyer who has formerly represented a client shall not use information relating to the representation to the disadvantage of the former client.',
			),
		),
		'conflict_of_interest' => array(
			array(
				'rule'    => '1.7',
				'title'   => 'Conflict of Interest: Current Clients',
				'summary' => 'A lawyer shall not represent a client if the representation involves a concurrent conflict of interest.',
			),
			array(
				'rule'    => '1.8',
				'title'   => 'Conflict of Interest: Current Clients: Specific Rules',
				'summary' => 'Specific rules regarding business transactions, literary rights, financial assistance, and other conflicts with current clients.',
			),
			array(
				'rule'    => '1.9',
				'title'   => 'Duties to Former Clients',
				'summary' => 'A lawyer shall not represent another person in the same or a substantially related matter with materially adverse interests to a former client.',
			),
			array(
				'rule'    => '1.10',
				'title'   => 'Imputation of Conflicts',
				'summary' => 'While lawyers are associated in a firm, none of them shall knowingly represent a client when any one of them would be prohibited from doing so.',
			),
		),
		'fees'                 => array(
			array(
				'rule'    => '1.5',
				'title'   => 'Fees',
				'summary' => 'A lawyer shall not make an agreement for, charge, or collect an unreasonable fee or an unreasonable amount for expenses.',
			),
		),
		'advertising'          => array(
			array(
				'rule'    => '7.1',
				'title'   => 'Communications Concerning a Lawyer\'s Services',
				'summary' => 'A lawyer shall not make a false or misleading communication about the lawyer or the lawyer\'s services.',
			),
			array(
				'rule'    => '7.2',
				'title'   => 'Communications Concerning a Lawyer\'s Services: Specific Rules',
				'summary' => 'Rules regarding advertising, solicitation, and firm names and letterheads.',
			),
			array(
				'rule'    => '7.3',
				'title'   => 'Solicitation of Clients',
				'summary' => 'A lawyer shall not solicit professional employment by live person-to-person contact when a significant motive is pecuniary gain.',
			),
		),
		'supervision'          => array(
			array(
				'rule'    => '5.1',
				'title'   => 'Responsibilities of Partners, Managers, and Supervisory Lawyers',
				'summary' => 'A partner in a law firm shall make reasonable efforts to ensure that the firm has measures giving reasonable assurance of ethical compliance.',
			),
			array(
				'rule'    => '5.2',
				'title'   => 'Responsibilities of a Subordinate Lawyer',
				'summary' => 'A lawyer is bound by the Rules even if acting at the direction of another person.',
			),
			array(
				'rule'    => '5.3',
				'title'   => 'Responsibilities Regarding Nonlawyer Assistance',
				'summary' => 'A lawyer having supervisory authority over nonlawyers shall make reasonable efforts to ensure conduct compatible with lawyer obligations.',
			),
		),
		'communications'       => array(
			array(
				'rule'    => '1.4',
				'title'   => 'Communications',
				'summary' => 'A lawyer shall promptly inform the client of any decision requiring informed consent and reasonably consult with the client.',
			),
			array(
				'rule'    => '4.1',
				'title'   => 'Truthfulness in Statements to Others',
				'summary' => 'A lawyer shall not knowingly make a false statement of material fact or law to a third person.',
			),
			array(
				'rule'    => '4.2',
				'title'   => 'Communication with Person Represented by Counsel',
				'summary' => 'A lawyer shall not communicate about the subject of the representation with a person the lawyer knows to be represented by another lawyer.',
			),
		),
		'duties_to_court'      => array(
			array(
				'rule'    => '3.1',
				'title'   => 'Meritorious Claims and Contentions',
				'summary' => 'A lawyer shall not bring or defend a proceeding unless there is a basis in law and fact for doing so.',
			),
			array(
				'rule'    => '3.3',
				'title'   => 'Candor Toward the Tribunal',
				'summary' => 'A lawyer shall not knowingly make a false statement of fact or law to a tribunal.',
			),
			array(
				'rule'    => '3.4',
				'title'   => 'Fairness to Opposing Party and Counsel',
				'summary' => 'A lawyer shall not unlawfully obstruct another party\'s access to evidence or unlawfully alter, destroy or conceal evidence.',
			),
			array(
				'rule'    => '3.5',
				'title'   => 'Impartiality and Decorum of the Tribunal',
				'summary' => 'A lawyer shall not seek to influence a judge, juror, or other official by means prohibited by law.',
			),
		),
	);

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
		return 'lf_ethics_rule_checker';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Ethics Rule Checker', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Checks ABA Model Rules of Professional Conduct against specific scenarios to identify applicable ethics rules, risk levels, and compliance recommendations.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'scenario'      => array(
					'type'        => 'string',
					'description' => __( 'Description of the ethical scenario to evaluate.', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
				),
				'rule_category' => array(
					'type'        => 'string',
					'description' => __( 'Category of ethics rules to focus the analysis on.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array(
						'competence',
						'confidentiality',
						'conflict_of_interest',
						'fees',
						'advertising',
						'supervision',
						'communications',
						'duties_to_court',
					),
				),
				'jurisdiction'  => array(
					'type'        => 'string',
					'description' => __( 'Jurisdiction for jurisdiction-specific rule variations (e.g., "California", "New York").', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'scenario' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only', 'cacheable' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$scenario      = isset( $arguments['scenario'] ) ? sanitize_textarea_field( $arguments['scenario'] ) : '';
		$rule_category = isset( $arguments['rule_category'] ) ? sanitize_text_field( $arguments['rule_category'] ) : '';
		$jurisdiction  = isset( $arguments['jurisdiction'] ) ? sanitize_text_field( $arguments['jurisdiction'] ) : '';

		if ( empty( $scenario ) ) {
			return new WP_Error( 'missing_required', __( 'Scenario description is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$scenario_lower   = strtolower( $scenario );
		$applicable_rules = array();
		$risk_indicators  = 0;
		$max_indicators   = 0;

		// Keyword-to-category mapping for automatic detection.
		$keyword_map = array(
			'competence'           => array( 'competence', 'skill', 'knowledge', 'unfamiliar', 'inexperienced', 'new area', 'learning' ),
			'confidentiality'      => array( 'confidential', 'secret', 'disclose', 'reveal', 'privacy', 'share information', 'leak' ),
			'conflict_of_interest' => array( 'conflict', 'adverse', 'opposing', 'dual representation', 'former client', 'business transaction' ),
			'fees'                 => array( 'fee', 'billing', 'charge', 'retainer', 'contingent', 'hourly rate', 'payment', 'cost' ),
			'advertising'          => array( 'advertis', 'marketing', 'solicit', 'website', 'social media', 'promotion', 'referral' ),
			'supervision'          => array( 'supervis', 'paralegal', 'associate', 'staff', 'delegate', 'nonlawyer', 'oversight' ),
			'communications'       => array( 'communicat', 'inform', 'respond', 'contact', 'represented party', 'opposing counsel' ),
			'duties_to_court'      => array( 'court', 'tribunal', 'judge', 'candor', 'evidence', 'meritorious', 'frivolous', 'perjury' ),
		);

		// Determine which categories to analyze.
		$categories_to_check = array();
		if ( ! empty( $rule_category ) && isset( self::$aba_rules[ $rule_category ] ) ) {
			$categories_to_check[] = $rule_category;
		} else {
			foreach ( $keyword_map as $category => $keywords ) {
				foreach ( $keywords as $keyword ) {
					if ( false !== strpos( $scenario_lower, $keyword ) ) {
						$categories_to_check[] = $category;
						break;
					}
				}
			}
			if ( empty( $categories_to_check ) ) {
				$categories_to_check = array_keys( self::$aba_rules );
			}
		}

		foreach ( $categories_to_check as $category ) {
			if ( ! isset( self::$aba_rules[ $category ] ) ) {
				continue;
			}
			foreach ( self::$aba_rules[ $category ] as $rule ) {
				$relevance_score = 0;
				$max_indicators += 3;

				// Score relevance based on keyword matches.
				if ( isset( $keyword_map[ $category ] ) ) {
					foreach ( $keyword_map[ $category ] as $keyword ) {
						if ( false !== strpos( $scenario_lower, $keyword ) ) {
							++$relevance_score;
						}
					}
				}

				$risk_indicators += min( $relevance_score, 3 );

				$applicable_rules[] = array(
					'rule'            => $rule['rule'],
					'title'           => $rule['title'],
					'summary'         => $rule['summary'],
					'category'        => $category,
					'relevance_score' => min( $relevance_score, 3 ),
				);
			}
		}

		// Sort by relevance score descending.
		usort(
			$applicable_rules,
			function ( $a, $b ) {
				return $b['relevance_score'] - $a['relevance_score'];
			}
		);

		// Calculate overall risk level.
		$risk_ratio = $max_indicators > 0 ? ( $risk_indicators / $max_indicators ) : 0;
		if ( $risk_ratio >= 0.6 ) {
			$risk_level = 'high';
		} elseif ( $risk_ratio >= 0.3 ) {
			$risk_level = 'medium';
		} else {
			$risk_level = 'low';
		}

		// Generate recommendations.
		$recommendations = array();
		if ( 'high' === $risk_level ) {
			$recommendations[] = __( 'Immediate ethics consultation recommended before proceeding.', 'mcp-ai-wpoos-pro' );
			$recommendations[] = __( 'Document all decisions and the reasoning behind them.', 'mcp-ai-wpoos-pro' );
		}
		if ( 'medium' === $risk_level ) {
			$recommendations[] = __( 'Review the identified rules carefully and consider seeking guidance from your firm\'s ethics counsel.', 'mcp-ai-wpoos-pro' );
		}
		$recommendations[] = __( 'Consult your jurisdiction\'s specific rules, as they may differ from the ABA Model Rules.', 'mcp-ai-wpoos-pro' );

		if ( ! empty( $jurisdiction ) ) {
			$recommendations[] = sprintf(
				/* translators: %s: jurisdiction name */
				__( 'Verify these rules against %s-specific ethics opinions and rule variations.', 'mcp-ai-wpoos-pro' ),
				$jurisdiction
			);
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: number of rules, 2: risk level */
				__( 'Found %1$d applicable rules with %2$s risk level.', 'mcp-ai-wpoos-pro' ),
				count( $applicable_rules ),
				$risk_level
			) . ' ' . self::DISCLAIMER,
			'data'       => array(
				'applicable_rules'   => $applicable_rules,
				'risk_level'         => $risk_level,
				'risk_score'         => round( $risk_ratio * 100 ),
				'categories_checked' => $categories_to_check,
				'jurisdiction'       => $jurisdiction,
				'recommendations'    => $recommendations,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
