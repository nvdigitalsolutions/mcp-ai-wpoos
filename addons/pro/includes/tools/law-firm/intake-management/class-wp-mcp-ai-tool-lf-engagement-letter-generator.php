<?php
/**
 * Engagement Letter Generator Tool
 *
 * Generates engagement letter templates based on client and matter details.
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
 * Generates engagement letter templates for client matters.
 */
class WP_MCP_AI_Tool_LF_Engagement_Letter_Generator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'lf_engagement_letter_generator';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Engagement Letter Generator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Generates an engagement letter template based on client name, matter description, practice area, fee arrangement, billing rate, retainer amount, and jurisdiction.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'client_name'        => array(
					'type'        => 'string',
					'description' => __( 'Full name of the client.', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
				),
				'matter_description' => array(
					'type'        => 'string',
					'description' => __( 'Description of the legal matter.', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
				),
				'practice_area'      => array(
					'type'        => 'string',
					'description' => __( 'Area of law for the engagement.', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
				),
				'fee_arrangement'    => array(
					'type'        => 'string',
					'description' => __( 'Fee arrangement type.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'hourly', 'contingency', 'flat_fee', 'retainer' ),
				),
				'billing_rate'       => array(
					'type'        => 'number',
					'description' => __( 'Hourly billing rate in dollars.', 'mcp-ai-wpoos-pro' ),
				),
				'retainer_amount'    => array(
					'type'        => 'number',
					'description' => __( 'Retainer amount in dollars.', 'mcp-ai-wpoos-pro' ),
				),
				'jurisdiction'       => array(
					'type'        => 'string',
					'description' => __( 'Jurisdiction for the engagement (e.g., state name).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'client_name', 'matter_description', 'practice_area', 'fee_arrangement' ),
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

		$client_name  = isset( $arguments['client_name'] ) ? sanitize_text_field( $arguments['client_name'] ) : '';
		$matter_desc  = isset( $arguments['matter_description'] ) ? sanitize_text_field( $arguments['matter_description'] ) : '';
		$practice     = isset( $arguments['practice_area'] ) ? sanitize_text_field( $arguments['practice_area'] ) : '';
		$fee_type     = isset( $arguments['fee_arrangement'] ) ? sanitize_text_field( $arguments['fee_arrangement'] ) : '';
		$billing_rate = isset( $arguments['billing_rate'] ) ? floatval( $arguments['billing_rate'] ) : 0;
		$retainer     = isset( $arguments['retainer_amount'] ) ? floatval( $arguments['retainer_amount'] ) : 0;
		$jurisdiction = isset( $arguments['jurisdiction'] ) ? sanitize_text_field( $arguments['jurisdiction'] ) : '';

		if ( empty( $client_name ) || empty( $matter_desc ) || empty( $practice ) || empty( $fee_type ) ) {
			return new WP_Error( 'missing_required', __( 'Client name, matter description, practice area, and fee arrangement are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$valid_fee_types = array( 'hourly', 'contingency', 'flat_fee', 'retainer' );
		if ( ! in_array( $fee_type, $valid_fee_types, true ) ) {
			return new WP_Error( 'invalid_param', __( 'Invalid fee arrangement type.', 'mcp-ai-wpoos-pro' ) );
		}

		$date_str  = wp_date( 'F j, Y' );
		$firm_name = get_bloginfo( 'name' );
		$sections  = array();

		// Header section.
		$sections['header'] = sprintf( "%s\n\n%s\n\nDear %s,", $date_str, $firm_name, $client_name );

		// Scope section.
		$sections['scope'] = sprintf(
			"RE: Engagement for Legal Representation — %s\n\nThis letter confirms that %s (the \"Firm\") has been retained to represent you in connection with the following matter:\n\n%s\n\nOur representation will be limited to the %s matter described above%s.",
			esc_html( $practice ),
			esc_html( $firm_name ),
			esc_html( $matter_desc ),
			esc_html( $practice ),
			$jurisdiction ? sprintf( ' in the jurisdiction of %s', esc_html( $jurisdiction ) ) : ''
		);

		// Fee arrangement section.
		switch ( $fee_type ) {
			case 'hourly':
				$rate_text        = $billing_rate > 0
					? sprintf( '$%s per hour', number_format( $billing_rate, 2 ) )
					: '[RATE TO BE DETERMINED]';
				$sections['fees'] = sprintf(
					"FEES AND BILLING\n\nOur fees for this matter will be based on an hourly rate of %s. Time will be billed in increments of one-tenth (0.1) of an hour. You will receive monthly itemized invoices, and payment is due within thirty (30) days of the invoice date.",
					$rate_text
				);
				break;

			case 'contingency':
				$sections['fees'] = "FEES AND BILLING\n\nOur fees for this matter will be based on a contingency arrangement. The Firm will receive a percentage of any recovery obtained on your behalf: one-third (33.33%) if resolved before filing suit, forty percent (40%) after filing, and forty-five percent (45%) if resolved after trial commences. If no recovery is obtained, you will not owe any attorney fees. You remain responsible for costs and expenses regardless of outcome.";
				break;

			case 'flat_fee':
				$sections['fees'] = "FEES AND BILLING\n\nOur fee for the described scope of services will be a flat fee of [AMOUNT]. This fee covers all attorney time for the described services. Any matters outside the scope described above will be billed separately.";
				break;

			case 'retainer':
				$retainer_text    = $retainer > 0
					? sprintf( '$%s', number_format( $retainer, 2 ) )
					: '[RETAINER AMOUNT]';
				$sections['fees'] = sprintf(
					"FEES AND BILLING\n\nA retainer deposit of %s is required before the Firm commences work on this matter. This retainer will be deposited into our client trust account and applied against fees and costs as they are incurred. You will receive regular statements showing charges against the retainer. Additional retainer deposits may be required as the matter progresses.",
					$retainer_text
				);
				break;
		}

		// Responsibilities section.
		$sections['responsibilities'] = "CLIENT RESPONSIBILITIES\n\nYou agree to cooperate fully with the Firm, provide all relevant documents and information promptly, keep the Firm informed of any developments, and pay invoices in a timely manner.";

		// Termination section.
		$sections['termination'] = "TERMINATION\n\nEither party may terminate this engagement at any time upon written notice. Upon termination, you will be responsible for all fees and costs incurred up to the date of termination. The Firm will take reasonable steps to protect your interests during the transition period.";

		// Closing section.
		$sections['closing'] = sprintf(
			"If this letter accurately reflects your understanding of our engagement, please sign and return a copy to our office.\n\nSincerely,\n%s\n\n\nAGREED AND ACCEPTED:\n\n___________________________\n%s\nDate: _______________",
			esc_html( $firm_name ),
			esc_html( $client_name )
		);

		$letter_text = implode( "\n\n", $sections );

		return array(
			'success'    => true,
			'message'    => __( 'Engagement letter template generated successfully. Review and customize before sending. ', 'mcp-ai-wpoos-pro' ) . self::DISCLAIMER,
			'data'       => array(
				'letter_text'     => $letter_text,
				'sections'        => $sections,
				'fee_arrangement' => $fee_type,
				'practice_area'   => $practice,
				'client_name'     => $client_name,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
