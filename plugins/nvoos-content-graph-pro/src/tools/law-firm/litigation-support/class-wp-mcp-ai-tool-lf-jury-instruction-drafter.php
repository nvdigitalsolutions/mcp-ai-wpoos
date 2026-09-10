<?php
/**
 * litigation-support/class-wp-mcp-ai-tool-lf-jury-instruction-drafter.php (ecosystem port — Wave F4, law-firm litigation-support batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-jury-instruction-drafter.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the `__DIR__` calculator require resolves from the addon's already-ported
 * `src/tools/law-firm/` copy.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Drafts jury instructions based on claim type and jurisdiction.
 */
class WP_MCP_AI_Tool_LF_Jury_Instruction_Drafter implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

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
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
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
		return __( 'Law Firm toolkit is not enabled.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'lf_jury_instruction_drafter';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Jury Instruction Drafter', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Generates draft jury instructions based on claim type, jurisdiction, elements of the claim, and party role. Returns numbered instructions with legal basis citations.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'claim_type'   => array(
					'type'        => 'string',
					'description' => __( 'Type of legal claim (e.g., negligence, breach_of_contract, fraud).', 'nvoos-content-graph-pro' ),
				),
				'jurisdiction' => array(
					'type'        => 'string',
					'description' => __( 'Jurisdiction for the instructions (e.g., state abbreviation or federal).', 'nvoos-content-graph-pro' ),
				),
				'elements'     => array(
					'type'        => 'array',
					'description' => __( 'Specific elements of the claim to include in instructions.', 'nvoos-content-graph-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'party_role'   => array(
					'type'        => 'string',
					'description' => __( 'The role of the party requesting instructions.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'plaintiff', 'defendant' ),
				),
			),
			'required'   => array( 'claim_type', 'jurisdiction' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only' );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'nvoos-content-graph-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$claim_type   = isset( $arguments['claim_type'] ) ? sanitize_text_field( $arguments['claim_type'] ) : '';
		$jurisdiction = isset( $arguments['jurisdiction'] ) ? sanitize_text_field( $arguments['jurisdiction'] ) : '';
		$elements     = array();
		if ( ! empty( $arguments['elements'] ) && is_array( $arguments['elements'] ) ) {
			$elements = array_map( 'sanitize_text_field', $arguments['elements'] );
		}
		$party_role = isset( $arguments['party_role'] ) ? sanitize_text_field( $arguments['party_role'] ) : 'plaintiff';

		if ( empty( $claim_type ) || empty( $jurisdiction ) ) {
			return new WP_Error( 'missing_required', __( 'Claim type and jurisdiction are required.', 'nvoos-content-graph-pro' ) );
		}

		// Standard claim elements by type.
		$claim_elements = array(
			'negligence'         => array(
				'duty'      => __( 'The defendant owed a duty of care to the plaintiff.', 'nvoos-content-graph-pro' ),
				'breach'    => __( 'The defendant breached that duty of care.', 'nvoos-content-graph-pro' ),
				'causation' => __( 'The breach was the proximate cause of the plaintiff\'s injuries.', 'nvoos-content-graph-pro' ),
				'damages'   => __( 'The plaintiff suffered actual damages as a result.', 'nvoos-content-graph-pro' ),
			),
			'breach_of_contract' => array(
				'existence'   => __( 'A valid and enforceable contract existed between the parties.', 'nvoos-content-graph-pro' ),
				'performance' => __( 'The plaintiff performed its obligations under the contract or was excused from performance.', 'nvoos-content-graph-pro' ),
				'breach'      => __( 'The defendant failed to perform its obligations under the contract.', 'nvoos-content-graph-pro' ),
				'damages'     => __( 'The plaintiff suffered damages as a result of the breach.', 'nvoos-content-graph-pro' ),
			),
			'fraud'              => array(
				'representation' => __( 'The defendant made a false representation of a material fact.', 'nvoos-content-graph-pro' ),
				'knowledge'      => __( 'The defendant knew the representation was false or made it recklessly.', 'nvoos-content-graph-pro' ),
				'intent'         => __( 'The defendant intended to induce the plaintiff to act in reliance on the representation.', 'nvoos-content-graph-pro' ),
				'reliance'       => __( 'The plaintiff justifiably relied on the representation.', 'nvoos-content-graph-pro' ),
				'damages'        => __( 'The plaintiff suffered damages as a result of the reliance.', 'nvoos-content-graph-pro' ),
			),
			'product_liability'  => array(
				'defect'    => __( 'The product was defective when it left the defendant\'s control.', 'nvoos-content-graph-pro' ),
				'use'       => __( 'The product was used in a reasonably foreseeable manner.', 'nvoos-content-graph-pro' ),
				'causation' => __( 'The defect was a proximate cause of the plaintiff\'s injuries.', 'nvoos-content-graph-pro' ),
				'damages'   => __( 'The plaintiff suffered damages as a result.', 'nvoos-content-graph-pro' ),
			),
			'defamation'         => array(
				'publication' => __( 'The defendant published a statement to a third party.', 'nvoos-content-graph-pro' ),
				'falsity'     => __( 'The statement was false.', 'nvoos-content-graph-pro' ),
				'fault'       => __( 'The defendant acted with the required degree of fault.', 'nvoos-content-graph-pro' ),
				'damages'     => __( 'The plaintiff suffered damages as a result.', 'nvoos-content-graph-pro' ),
			),
		);

		// Legal basis references by claim type.
		$legal_bases = array(
			'negligence'         => 'Restatement (Second) of Torts §§ 281-328',
			'breach_of_contract' => 'Restatement (Second) of Contracts §§ 235-243',
			'fraud'              => 'Restatement (Second) of Torts §§ 525-551',
			'product_liability'  => 'Restatement (Third) of Torts: Products Liability §§ 1-8',
			'defamation'         => 'Restatement (Second) of Torts §§ 558-623',
		);

		$active_elements = $claim_elements[ $claim_type ] ?? array();
		$legal_basis     = $legal_bases[ $claim_type ] ?? sprintf(
			/* translators: 1: claim type */
			__( 'Applicable %1$s law and precedent', 'nvoos-content-graph-pro' ),
			$claim_type
		);

		// If custom elements provided, use them instead or merge.
		if ( ! empty( $elements ) ) {
			$custom_elements = array();
			foreach ( $elements as $idx => $element ) {
				$custom_elements[ 'element_' . ( $idx + 1 ) ] = $element;
			}
			if ( empty( $active_elements ) ) {
				$active_elements = $custom_elements;
			} else {
				$active_elements = array_merge( $active_elements, $custom_elements );
			}
		}

		if ( empty( $active_elements ) ) {
			$active_elements = array(
				'element_1' => sprintf(
					/* translators: 1: claim type */
					__( 'The plaintiff has established a valid %1$s claim.', 'nvoos-content-graph-pro' ),
					str_replace( '_', ' ', $claim_type )
				),
				'element_2' => __( 'The defendant\'s conduct was a proximate cause of the plaintiff\'s damages.', 'nvoos-content-graph-pro' ),
				'element_3' => __( 'The plaintiff suffered actual damages.', 'nvoos-content-graph-pro' ),
			);
		}

		// Build instructions.
		$instructions    = array();
		$instruction_num = 1;

		// Preliminary instruction.
		$instructions[] = array(
			'instruction_number' => $instruction_num++,
			'text'               => sprintf(
				/* translators: 1: claim type readable */
				__( 'Members of the jury, you are now going to hear the instructions of law that apply to this case involving a claim of %1$s. You must follow these instructions and apply them to the facts as you find them.', 'nvoos-content-graph-pro' ),
				str_replace( '_', ' ', $claim_type )
			),
			'legal_basis'        => __( 'General preliminary instruction', 'nvoos-content-graph-pro' ),
		);

		// Burden of proof instruction.
		if ( 'plaintiff' === $party_role ) {
			$instructions[] = array(
				'instruction_number' => $instruction_num++,
				'text'               => __( 'The plaintiff has the burden of proving each element of the claim by a preponderance of the evidence. This means the plaintiff must show that it is more likely than not that each element is true.', 'nvoos-content-graph-pro' ),
				'legal_basis'        => sprintf(
					/* translators: 1: jurisdiction */
					__( 'Standard burden of proof — %1$s civil procedure', 'nvoos-content-graph-pro' ),
					strtoupper( $jurisdiction )
				),
			);
		} else {
			$instructions[] = array(
				'instruction_number' => $instruction_num++,
				'text'               => __( 'The plaintiff bears the burden of proving each element of the claim by a preponderance of the evidence. If the plaintiff fails to prove any element, you must find in favor of the defendant.', 'nvoos-content-graph-pro' ),
				'legal_basis'        => sprintf(
					/* translators: 1: jurisdiction */
					__( 'Standard burden of proof — %1$s civil procedure', 'nvoos-content-graph-pro' ),
					strtoupper( $jurisdiction )
				),
			);
		}

		// Element instructions.
		foreach ( $active_elements as $key => $element_text ) {
			$instructions[] = array(
				'instruction_number' => $instruction_num++,
				'text'               => sprintf(
					/* translators: 1: element key, 2: element text */
					__( 'As to the element of %1$s: %2$s', 'nvoos-content-graph-pro' ),
					str_replace( '_', ' ', $key ),
					$element_text
				),
				'legal_basis'        => $legal_basis,
			);
		}

		// Damages instruction.
		$instructions[] = array(
			'instruction_number' => $instruction_num++,
			'text'               => __( 'If you find that the plaintiff has proven all elements of the claim, you must then determine the amount of damages to which the plaintiff is entitled. Damages must be proven with reasonable certainty and may include both economic and non-economic losses.', 'nvoos-content-graph-pro' ),
			'legal_basis'        => sprintf(
				/* translators: 1: jurisdiction */
				__( 'General damages instruction — %1$s civil jury instructions', 'nvoos-content-graph-pro' ),
				strtoupper( $jurisdiction )
			),
		);

		// Credibility instruction.
		$instructions[] = array(
			'instruction_number' => $instruction_num++,
			'text'               => __( 'In evaluating the testimony of witnesses, you may consider their demeanor, opportunity to observe, interest in the outcome, consistency of their testimony, and any other factors that bear on credibility.', 'nvoos-content-graph-pro' ),
			'legal_basis'        => __( 'Standard credibility instruction', 'nvoos-content-graph-pro' ),
		);

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: instruction count, 2: claim type, 3: jurisdiction */
				__( 'Generated %1$d draft jury instructions for %2$s claim in %3$s jurisdiction. ', 'nvoos-content-graph-pro' ),
				count( $instructions ),
				str_replace( '_', ' ', $claim_type ),
				strtoupper( $jurisdiction )
			) . self::DISCLAIMER,
			'data'       => array(
				'claim_type'   => $claim_type,
				'jurisdiction' => $jurisdiction,
				'party_role'   => $party_role,
				'instructions' => $instructions,
				'total_count'  => count( $instructions ),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
