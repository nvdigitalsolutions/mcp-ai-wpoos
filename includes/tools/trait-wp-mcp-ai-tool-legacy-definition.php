<?php
/**
 * Trait bridging legacy-format tool classes to WP_MCP_AI_Tool_Interface.
 *
 * Some tool classes predate the interface split and only expose
 * get_slug() / get_definition() / get_required_capability() / execute().
 * The registry requires WP_MCP_AI_Tool_Interface (which additionally demands
 * get_name(), get_description() and get_parameters_schema()). This trait
 * derives those three from the legacy get_definition() array so legacy
 * classes can implement the interface without restructuring their metadata.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Derives the interface metadata accessors from a legacy get_definition().
 *
 * @since 1.2.0
 */
trait WP_MCP_AI_Tool_Legacy_Definition {

	/**
	 * Human readable name derived from the legacy definition.
	 *
	 * @return string
	 */
	public function get_name() {
		$definition = $this->get_definition();

		if ( isset( $definition['name'] ) && is_string( $definition['name'] ) && '' !== $definition['name'] ) {
			return sanitize_text_field( $definition['name'] );
		}

		return $this->get_slug();
	}

	/**
	 * Description derived from the legacy definition.
	 *
	 * @return string
	 */
	public function get_description() {
		$definition = $this->get_definition();

		if ( isset( $definition['description'] ) && is_string( $definition['description'] ) ) {
			return $definition['description'];
		}

		return '';
	}

	/**
	 * Parameter schema derived from the legacy definition.
	 *
	 * Accepts the legacy `input_schema` / `parameters` keys and the current
	 * `parameters_schema` key, falling back to an open object schema so the
	 * tool remains invocable even when no schema was declared.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		$definition = $this->get_definition();

		foreach ( array( 'parameters_schema', 'input_schema', 'parameters' ) as $key ) {
			if ( isset( $definition[ $key ] ) && is_array( $definition[ $key ] ) ) {
				return $definition[ $key ];
			}
		}

		return array(
			'type'       => 'object',
			// Empty stdClass encodes as `{}`; an empty PHP array would encode
			// as `[]`, which strict providers (DeepSeek) reject.
			'properties' => new stdClass(),
		);
	}
}
