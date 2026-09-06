<?php
/**
 * Settings section contract for the Content Graph AI addon.
 *
 * Port of the base plugin's `WP_MCP_AI_Settings_Section` validation
 * contract (Wave D-UI-5). Extends the parent plugin's `Section` so the
 * parent's SettingsRegistry and renderer keep working unchanged (the
 * core registry is consumed, never modified) while adding the base's
 * `validate()` step: sections may override `validate()` to return a
 * WP_Error; `sanitize()` then records the error as a settings error and
 * returns an empty array so the parent's merge-on-existing flow keeps
 * the previous values for this section's fields.
 *
 * @package NvoosContentGraphAi\Admin\Settings
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Admin\Settings;

use NvoosContentGraph\Admin\Section;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Section base class with the base plugin's validate-then-sanitize flow.
 *
 * @since 1.1.0
 */
abstract class AiSection extends Section {

	/**
	 * Validate input for this section.
	 *
	 * Default implementation passes input through unchanged; override to
	 * return a WP_Error (rejecting the section's submitted values) using
	 * the {@see SettingsValidator} helpers.
	 *
	 * @param array<string,mixed> $input Raw submitted values keyed by setting key.
	 * @return array<string,mixed>|\WP_Error Validated input or error.
	 */
	public function validate( array $input ) {
		return $input;
	}

	/**
	 * Sanitize input for this section — validate first, then sanitize.
	 *
	 * On validation failure the previous values for this section's keys
	 * are preserved (empty merge) and the error is surfaced through
	 * WordPress' settings_errors() on the settings page.
	 *
	 * @param array<string,mixed> $input Raw submitted values keyed by setting key.
	 * @return array<string,mixed> Sanitized values.
	 */
	public function sanitize( array $input ): array {
		$validated = $this->validate( $input );

		if ( is_wp_error( $validated ) ) {
			add_settings_error(
				'nvoos_content_graph_settings',
				'nvoos_cg_ai_' . $this->get_id(),
				$validated->get_error_message()
			);

			return array();
		}

		return parent::sanitize( $input );
	}
}
