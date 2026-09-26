<?php
/**
 * Outbound Appointment Booking — message template renderer.
 *
 * Renders outbound copy with {{token}} substitution. Tokens are derived from
 * the lead record, the angle, and the booking context. Unknown tokens are
 * stripped so partially-filled templates never leak placeholder syntax.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Outbound_Booking_Toolkit
 * @since 2.12.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Token-based template renderer.
 *
 * @since 2.12.0
 */
class WP_MCP_AI_OA_Templates {

	/**
	 * Build the token map for a lead.
	 *
	 * @since 2.12.0
	 * @param int   $lead_id Lead post ID.
	 * @param array $context Context: booking_url, angle_*, objection, response, sequence_name.
	 * @return array Token key => value.
	 */
	public static function get_tokens( $lead_id, $context = array() ) {
		$context = is_array( $context ) ? $context : array();
		$first   = get_post_meta( $lead_id, 'first_name', true );
		$last    = get_post_meta( $lead_id, 'last_name', true );
		$company = get_post_meta( $lead_id, 'company_name', true );
		if ( ! $company ) {
			$company = get_post_meta( $lead_id, 'company', true );
		}
		$settings = WP_MCP_AI_OA_Settings::get();

		$tokens = array(
			'first_name'       => $first,
			'last_name'        => $last,
			'full_name'        => trim( $first . ' ' . $last ),
			'company'          => $company,
			'company_name'     => $company,
			'job_title'        => get_post_meta( $lead_id, 'job_title', true ),
			'email'            => get_post_meta( $lead_id, 'email', true ),
			'sender_name'      => $settings['from_name'],
			'sender_signature' => $settings['sender_signature'],
			'booking_url'      => isset( $context['booking_url'] ) ? $context['booking_url'] : '',
			'angle_first_line' => isset( $context['angle_first_line'] ) ? $context['angle_first_line'] : '',
			'angle_body'       => isset( $context['angle_body'] ) ? $context['angle_body'] : '',
			'angle_subject'    => isset( $context['angle_subject'] ) ? $context['angle_subject'] : '',
			'objection'        => isset( $context['objection'] ) ? $context['objection'] : '',
			'response'         => isset( $context['response'] ) ? $context['response'] : '',
			'sequence_name'    => isset( $context['sequence_name'] ) ? $context['sequence_name'] : '',
		);

		/**
		 * Filter the token map before substitution.
		 *
		 * @since 2.12.0
		 * @param array $tokens   Token map.
		 * @param int   $lead_id  Lead post ID.
		 * @param array $context  Render context.
		 */
		return apply_filters( 'wp_mcp_ai_oa_tokens', $tokens, $lead_id, $context );
	}

	/**
	 * Render a template for a lead.
	 *
	 * @since 2.12.0
	 * @param string $template Template string.
	 * @param int    $lead_id  Lead post ID.
	 * @param array  $context  Render context.
	 * @return string Rendered copy.
	 */
	public static function render( $template, $lead_id, $context = array() ) {
		$tokens = self::get_tokens( $lead_id, $context );
		$out    = (string) $template;
		foreach ( $tokens as $key => $value ) {
			$out = str_replace( '{{' . $key . '}}', (string) $value, $out );
		}
		// Strip any remaining unknown tokens.
		$out = preg_replace( '/\{\{[a-z0-9_]+\}\}/i', '', $out );
		$out = trim( $out );

		/**
		 * Filter the rendered message. AI assistants can hook in here to
		 * personalise the first line before dispatch.
		 *
		 * @since 2.12.0
		 * @param string $out      Rendered copy.
		 * @param string $template Original template.
		 * @param int    $lead_id  Lead post ID.
		 * @param array  $context  Render context.
		 */
		return apply_filters( 'wp_mcp_ai_oa_render_template', $out, $template, $lead_id, $context );
	}
}
