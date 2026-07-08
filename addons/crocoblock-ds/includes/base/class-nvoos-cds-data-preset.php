<?php
/**
 * NV oOS Crocoblock DS — Data Preset (Interface)
 *
 * @package NV_oOS_Crocoblock_DS
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for design token presets.
 *
 * Each preset provides a complete set of token definitions (for seeding the
 * registry) and optionally a set of initial values that differ from the
 * factory defaults.
 *
 * @since 0.1.0
 */
interface NV_oOS_Crocoblock_DS_Data_Preset {

	/**
	 * Human-readable preset name (shown in the admin UI).
	 *
	 * @return string
	 */
	public function name();

	/**
	 * Optional preset description.
	 *
	 * @return string
	 */
	public function description();

	/**
	 * Token definitions — the canonical list of all tokens in this preset.
	 *
	 * Each entry is an associative array:
	 *   - id:          string  Unique token ID (e.g. 'color_surface')
	 *   - label:       string  Human-readable label
	 *   - group:       string  Token group ('colors', 'spacing', …)
	 *   - type:        string  Input type ('color', 'size', 'font', …)
	 *   - default:     string  Factory default value
	 *   - description: string  Optional help text
	 *
	 * @return array<int, array<string, string>>
	 */
	public function definitions();

	/**
	 * Preset-specific token values (overrides the factory defaults).
	 *
	 * Keys are token IDs; values are the preset value. Only include tokens
	 * that differ from the factory default — the registry will fall back to
	 * the default value for any omitted key.
	 *
	 * @return array<string, string>
	 */
	public function token_values();
}
