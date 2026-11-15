<?php
/**
 * CRM Sequence Presets
 *
 * Benchmark-aligned cadence presets. Each preset is a set of step objects
 * that the sequence tool can persist directly; missing keys fall back to the
 * gate settings.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.3.0
 *
 * @license Proprietary
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sequence presets.
 *
 * @since 3.3.0
 */
class WP_MCP_AI_CRM_Sequence_Presets {

	/**
	 * Available presets.
	 *
	 * @return array
	 */
	public static function all() {
		$presets = array(
			'short'    => array(
				'label'       => __( 'Short (4 touches / 14 days)', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Four touches over two weeks. Email 1 has no link.', 'mcp-ai-wpoos-pro' ),
				'steps'       => array(
					self::step( 'observation', 0, 75, 0 ),
					self::step( 'angle', 72, 60, 1 ),
					self::step( 'proof', 72, 60, 1 ),
					self::step( 'breakup', 96, 40, 0 ),
				),
			),
			'standard' => array(
				'label'       => __( 'Standard (6 touches / 28 days)', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Six touches over four weeks, with a bump and a new angle.', 'mcp-ai-wpoos-pro' ),
				'steps'       => array(
					self::step( 'observation', 0, 75, 0 ),
					self::step( 'bump', 72, 40, 0 ),
					self::step( 'angle', 96, 60, 1 ),
					self::step( 'proof', 96, 60, 1 ),
					self::step( 'final_angle', 96, 60, 1 ),
					self::step( 'breakup', 120, 40, 0 ),
				),
			),
			'breakup'  => array(
				'label'       => __( 'Breakup only (2 touches / 7 days)', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'A short observation and a clean close-the-loop.', 'mcp-ai-wpoos-pro' ),
				'steps'       => array(
					self::step( 'observation', 0, 75, 0 ),
					self::step( 'breakup', 168, 40, 0 ),
				),
			),
		);

		/**
		 * Filter sequence presets.
		 *
		 * @param array $presets Presets.
		 */
		return apply_filters( 'wp_mcp_ai_crm_sequence_presets', $presets );
	}

	/**
	 * Get one preset.
	 *
	 * @param string $name Preset name.
	 * @return array|WP_Error
	 */
	public static function get( $name ) {
		$name    = sanitize_key( $name );
		$presets = self::all();
		if ( ! isset( $presets[ $name ] ) ) {
			return new WP_Error( 'unknown_preset', __( 'Unknown sequence preset.', 'mcp-ai-wpoos-pro' ) );
		}
		return $presets[ $name ];
	}

	/**
	 * Build a step.
	 *
	 * @param string $purpose   Purpose.
	 * @param int    $wait      Wait hours.
	 * @param int    $max_words Word ceiling.
	 * @param int    $links     Link budget.
	 * @return array
	 */
	private static function step( $purpose, $wait, $max_words, $links ) {
		return array(
			'channel'          => 'email',
			'template_id'      => '',
			'wait_hours'       => absint( $wait ),
			'branch_on_reply'  => true,
			'purpose'          => sanitize_key( $purpose ),
			'max_words'        => absint( $max_words ),
			'link_budget'      => absint( $links ),
			'require_evidence' => true,
		);
	}
}
