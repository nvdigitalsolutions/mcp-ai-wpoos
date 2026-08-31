<?php
/**
 * Blueprint Importer.
 *
 * Converts a validated blueprint definition back into a normalized agent
 * configuration array. Capability/tool validation is delegated to the
 * target registry at deploy time; this class guarantees shape and safety.
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
 * Imports blueprint definitions into agent configurations.
 */
final class BlueprintImporter {

	/**
	 * Import a blueprint definition into a normalized agent config.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $definition Decoded blueprint definition.
	 * @return array|\WP_Error Normalized agent config array, or WP_Error.
	 */
	public function import( $definition ) {
		$validator = new BlueprintValidator();
		$check     = $validator->validate( $definition );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		if ( 'agent' !== $definition['kind'] ) {
			return new \WP_Error(
				'blueprint_unsupported_kind',
				/* translators: %s: blueprint kind */
				sprintf( __( 'Blueprint kind "%s" cannot be imported as an agent configuration.', 'nvoos-content-graph-ai-platform' ), $definition['kind'] )
			);
		}

		$agent = $definition['agent'];

		return array(
			'name'          => sanitize_text_field( $agent['name'] ),
			'description'   => isset( $agent['description'] ) ? sanitize_textarea_field( (string) $agent['description'] ) : '',
			'provider'      => isset( $agent['provider'] ) ? sanitize_key( (string) $agent['provider'] ) : '',
			'model'         => isset( $agent['model'] ) ? sanitize_text_field( (string) $agent['model'] ) : '',
			'system_prompt' => isset( $agent['system_prompt'] ) ? sanitize_textarea_field( (string) $agent['system_prompt'] ) : '',
			'tools'         => isset( $agent['tools'] ) && is_array( $agent['tools'] ) ? array_map( 'sanitize_key', $agent['tools'] ) : array(),
			'skills'        => isset( $agent['skills'] ) && is_array( $agent['skills'] ) ? array_map( 'sanitize_key', $agent['skills'] ) : array(),
			'settings'      => isset( $agent['settings'] ) && is_array( $agent['settings'] ) ? $agent['settings'] : array(),
			'blueprint'     => array(
				'version'     => sanitize_text_field( (string) $definition['blueprint_version'] ),
				'imported_at' => current_time( 'c', true ),
			),
		);
	}
}
