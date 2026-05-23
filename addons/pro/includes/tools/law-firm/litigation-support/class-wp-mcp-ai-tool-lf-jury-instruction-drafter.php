<?php
/**
 * Jury Instruction Drafter Tool
 *
 * Generates draft jury instructions based on claim type, jurisdiction,
 * elements of the claim, and party role.
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
		return 'lf_jury_instruction_drafter';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Jury Instruction Drafter', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Generates draft jury instructions based on claim type, jurisdiction, elements of the claim, and party role. Returns numbered instructions with legal basis citations.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Type of legal claim (e.g., negligence, breach_of_contract, fraud).', 'mcp-ai-wpoos-pro' ),
				),
				'jurisdiction' => array(
					'type'        => 'string',
					'description' => __( 'Jurisdiction for the instructions (e.g., state abbreviation or federal).', 'mcp-ai-wpoos-pro' ),
				),
				'elements'     => array(
					'type'        => 'array',
					'description' => __( 'Specific elements of the claim to include in instructions.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'party_role'   => array(
					'type'        => 'string',
					'description' => __( 'The role of the party requesting instructions.', 'mcp-ai-wpoos-pro' ),
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
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
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
			return new WP_Error( 'missing_required', __( 'Claim type and jurisdiction are required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Standard claim elements by type.
		$claim_elements = array(
			'negligence'         => array(
				'duty'      => __( 'The defendant owed a duty of care to the plaintiff.', 'mcp-ai-wpoos-pro' ),
				'breach'    => __( 'The defendant breached that duty of care.', 'mcp-ai-wpoos-pro' ),
				'causation' => __( 'The breach was the proximate cause of the plaintiff\'s injuries.', 'mcp-ai-wpoos-pro' ),
				'damages'   => __( 'The plaintiff suffered actual damages as a result.', 'mcp-ai-wpoos-pro' ),
			),
			'breach_of_contract' => array(
				'existence'   => __( 'A valid and enforceable contract existed between the parties.', 'mcp-ai-wpoos-pro' ),
				'performance' => __( 'The plaintiff performed its obligations under the contract or was excused from performance.', 'mcp-ai-wpoos-pro' ),
				'breach'      => __( 'The defendant failed to perform its obligations under the contract.', 'mcp-ai-wpoos-pro' ),
				'damages'     => __( 'The plaintiff suffered damages as a result of the breach.', 'mcp-ai-wpoos-pro' ),
			),
			'fraud'              => array(
				'representation' => __( 'The defendant made a false representation of a material fact.', 'mcp-ai-wpoos-pro' ),
				'knowledge'      => __( 'The defendant knew the representation was false or made it recklessly.', 'mcp-ai-wpoos-pro' ),
				'intent'         => __( 'The defendant intended to induce the plaintiff to act in reliance on the representation.', 'mcp-ai-wpoos-pro' ),
				'reliance'       => __( 'The plaintiff justifiably relied on the representation.', 'mcp-ai-wpoos-pro' ),
				'damages'        => __( 'The plaintiff suffered damages as a result of the reliance.', 'mcp-ai-wpoos-pro' ),
			),
			'product_liability'  => array(
				'defect'    => __( 'The product was defective when it left the defendant\'s control.', 'mcp-ai-wpoos-pro' ),
				'use'       => __( 'The product was used in a reasonably foreseeable manner.', 'mcp-ai-wpoos-pro' ),
				'causation' => __( 'The defect was a proximate cause of the plaintiff\'s injuries.', 'mcp-ai-wpoos-pro' ),
				'damages'   => __( 'The plaintiff suffered damages as a result.', 'mcp-ai-wpoos-pro' ),
			),
			'defamation'         => array(
				'publication' => __( 'The defendant published a statement to a third party.', 'mcp-ai-wpoos-pro' ),
				'falsity'     => __( 'The statement was false.', 'mcp-ai-wpoos-pro' ),
				'fault'       => __( 'The defendant acted with the required degree of fault.', 'mcp-ai-wpoos-pro' ),
				'damages'     => __( 'The plaintiff suffered damages as a result.', 'mcp-ai-wpoos-pro' ),
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
			__( 'Applicable %1$s law and precedent', 'mcp-ai-wpoos-pro' ),
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
					__( 'The plaintiff has established a valid %1$s claim.', 'mcp-ai-wpoos-pro' ),
					str_replace( '_', ' ', $claim_type )
				),
				'element_2' => __( 'The defendant\'s conduct was a proximate cause of the plaintiff\'s damages.', 'mcp-ai-wpoos-pro' ),
				'element_3' => __( 'The plaintiff suffered actual damages.', 'mcp-ai-wpoos-pro' ),
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
				__( 'Members of the jury, you are now going to hear the instructions of law that apply to this case involving a claim of %1$s. You must follow these instructions and apply them to the facts as you find them.', 'mcp-ai-wpoos-pro' ),
				str_replace( '_', ' ', $claim_type )
			),
			'legal_basis'        => __( 'General preliminary instruction', 'mcp-ai-wpoos-pro' ),
		);

		// Burden of proof instruction.
		if ( 'plaintiff' === $party_role ) {
			$instructions[] = array(
				'instruction_number' => $instruction_num++,
				'text'               => __( 'The plaintiff has the burden of proving each element of the claim by a preponderance of the evidence. This means the plaintiff must show that it is more likely than not that each element is true.', 'mcp-ai-wpoos-pro' ),
				'legal_basis'        => sprintf(
					/* translators: 1: jurisdiction */
					__( 'Standard burden of proof — %1$s civil procedure', 'mcp-ai-wpoos-pro' ),
					strtoupper( $jurisdiction )
				),
			);
		} else {
			$instructions[] = array(
				'instruction_number' => $instruction_num++,
				'text'               => __( 'The plaintiff bears the burden of proving each element of the claim by a preponderance of the evidence. If the plaintiff fails to prove any element, you must find in favor of the defendant.', 'mcp-ai-wpoos-pro' ),
				'legal_basis'        => sprintf(
					/* translators: 1: jurisdiction */
					__( 'Standard burden of proof — %1$s civil procedure', 'mcp-ai-wpoos-pro' ),
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
					__( 'As to the element of %1$s: %2$s', 'mcp-ai-wpoos-pro' ),
					str_replace( '_', ' ', $key ),
					$element_text
				),
				'legal_basis'        => $legal_basis,
			);
		}

		// Damages instruction.
		$instructions[] = array(
			'instruction_number' => $instruction_num++,
			'text'               => __( 'If you find that the plaintiff has proven all elements of the claim, you must then determine the amount of damages to which the plaintiff is entitled. Damages must be proven with reasonable certainty and may include both economic and non-economic losses.', 'mcp-ai-wpoos-pro' ),
			'legal_basis'        => sprintf(
				/* translators: 1: jurisdiction */
				__( 'General damages instruction — %1$s civil jury instructions', 'mcp-ai-wpoos-pro' ),
				strtoupper( $jurisdiction )
			),
		);

		// Credibility instruction.
		$instructions[] = array(
			'instruction_number' => $instruction_num++,
			'text'               => __( 'In evaluating the testimony of witnesses, you may consider their demeanor, opportunity to observe, interest in the outcome, consistency of their testimony, and any other factors that bear on credibility.', 'mcp-ai-wpoos-pro' ),
			'legal_basis'        => __( 'Standard credibility instruction', 'mcp-ai-wpoos-pro' ),
		);

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: instruction count, 2: claim type, 3: jurisdiction */
				__( 'Generated %1$d draft jury instructions for %2$s claim in %3$s jurisdiction. ', 'mcp-ai-wpoos-pro' ),
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
