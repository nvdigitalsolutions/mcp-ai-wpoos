<?php
/**
 * Blueprint Exporter.
 *
 * Converts an agent configuration into a versioned blueprint definition.
 * Works on a plain config array (the assistant CPT stays in the base
 * plugin by plan decision, so export is caller-driven).
 *
 * @package NvoosContentGraphAiPlatform\Blueprints
 * @since 2.0.0
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Blueprints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports agent configurations as blueprints.
 */
final class BlueprintExporter {

	/**
	 * Export an agent configuration array into a blueprint definition.
	 *
	 * @since 2.0.0
	 *
	 * @param array $agent Agent config (name, description, provider, model,
	 *                     tools, skills, system_prompt, settings).
	 * @return array|\WP_Error Blueprint definition, or WP_Error on invalid input.
	 */
	public function export( array $agent ) {
		if ( empty( $agent['name'] ) ) {
			return new \WP_Error( 'blueprint_export_invalid_agent', __( 'Agent config requires a name to export.', 'nvoos-content-graph-ai-platform' ) );
		}

		$definition = array(
			'blueprint_version' => BlueprintRegistry::SCHEMA_VERSION,
			'kind'              => 'agent',
			'source'            => array(
				'site_url'    => esc_url_raw( home_url( '/' ) ),
				'exported_at' => current_time( 'c', true ),
			),
			'agent'             => array(
				'name'          => sanitize_text_field( $agent['name'] ),
				'description'   => isset( $agent['description'] ) ? sanitize_textarea_field( (string) $agent['description'] ) : '',
				'provider'      => isset( $agent['provider'] ) ? sanitize_key( (string) $agent['provider'] ) : '',
				'model'         => isset( $agent['model'] ) ? sanitize_text_field( (string) $agent['model'] ) : '',
				'system_prompt' => isset( $agent['system_prompt'] ) ? sanitize_textarea_field( (string) $agent['system_prompt'] ) : '',
				'tools'         => $this->sanitize_string_list( $agent['tools'] ?? array() ),
				'skills'        => $this->sanitize_string_list( $agent['skills'] ?? array() ),
				'settings'      => isset( $agent['settings'] ) && is_array( $agent['settings'] ) ? $this->sanitize_settings( $agent['settings'] ) : array(),
			),
		);

		// The exporter is the last line of defence: a guarded field must
		// never reach a stored blueprint.
		$validator = new BlueprintValidator();
		$check     = $validator->validate( $definition );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		return $definition;
	}

	/**
	 * Sanitize a list of string identifiers.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $items Input list.
	 * @return string[] Sanitized unique list.
	 */
	private function sanitize_string_list( $items ): array {
		if ( ! is_array( $items ) ) {
			return array();
		}

		$clean = array();
		foreach ( $items as $item ) {
			if ( is_string( $item ) && '' !== trim( $item ) ) {
				$clean[] = sanitize_key( $item );
			}
		}

		return array_values( array_unique( array_filter( $clean ) ) );
	}

	/**
	 * Sanitize a flat settings map (values become strings/numbers only).
	 *
	 * @since 2.0.0
	 *
	 * @param array $settings Settings map.
	 * @return array Sanitized settings map.
	 */
	private function sanitize_settings( array $settings ): array {
		$clean = array();
		foreach ( $settings as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			if ( is_scalar( $value ) ) {
				$clean[ $key ] = is_numeric( $value ) ? $value + 0 : sanitize_text_field( (string) $value );
			} elseif ( is_array( $value ) ) {
				$clean[ $key ] = $this->sanitize_string_list( $value );
			}
		}

		return $clean;
	}
}
