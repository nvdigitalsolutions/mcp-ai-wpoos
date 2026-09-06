<?php
/**
 * Blueprint Validator.
 *
 * Structural validation for blueprint definitions: required keys, kind and
 * version checks, and destructive/irreversible-field guards.
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
 * Validates blueprint definition JSON.
 */
final class BlueprintValidator {

	/**
	 * Supported blueprint kinds.
	 *
	 * @since 2.0.0
	 *
	 * @var string[]
	 */
	public const KINDS = array( 'agent', 'workflow', 'prompt_pack' );

	/**
	 * Validate a blueprint definition.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $definition Decoded definition (array expected).
	 * @return true|\WP_Error True when valid, WP_Error otherwise.
	 */
	public function validate( $definition ) {
		if ( ! is_array( $definition ) ) {
			return new \WP_Error( 'blueprint_invalid_definition', __( 'Blueprint definition must be an object.', 'nvoos-content-graph-ai-platform' ) );
		}

		// Required envelope keys.
		foreach ( array( 'blueprint_version', 'kind' ) as $required ) {
			if ( empty( $definition[ $required ] ) ) {
				return new \WP_Error(
					'blueprint_missing_field',
					sprintf(
						/* translators: %s: field name */
						__( 'Blueprint definition is missing required field: %s.', 'nvoos-content-graph-ai-platform' ),
						$required
					)
				);
			}
		}

		// Kind whitelist.
		if ( ! in_array( $definition['kind'], self::KINDS, true ) ) {
			return new \WP_Error(
				'blueprint_invalid_kind',
				sprintf(
					/* translators: %s: comma-separated supported kinds */
					__( 'Unsupported blueprint kind. Supported kinds: %s.', 'nvoos-content-graph-ai-platform' ),
					implode( ', ', self::KINDS )
				)
			);
		}

		// Version guard: refuse definitions newer than this registry.
		$version_check = $this->validate_version( (string) $definition['blueprint_version'] );
		if ( is_wp_error( $version_check ) ) {
			return $version_check;
		}

		// Kind-specific required payloads.
		if ( 'agent' === $definition['kind'] ) {
			if ( empty( $definition['agent'] ) || ! is_array( $definition['agent'] ) ) {
				return new \WP_Error( 'blueprint_invalid_agent', __( 'Agent blueprints require an "agent" payload object.', 'nvoos-content-graph-ai-platform' ) );
			}
			if ( empty( $definition['agent']['name'] ) ) {
				return new \WP_Error( 'blueprint_invalid_agent', __( 'Agent blueprints require an agent name.', 'nvoos-content-graph-ai-platform' ) );
			}
		}

		if ( 'workflow' === $definition['kind'] && ( empty( $definition['workflow'] ) || ! is_array( $definition['workflow'] ) ) ) {
			return new \WP_Error( 'blueprint_invalid_workflow', __( 'Workflow blueprints require a "workflow" payload object.', 'nvoos-content-graph-ai-platform' ) );
		}

		if ( 'prompt_pack' === $definition['kind'] && ( empty( $definition['prompts'] ) || ! is_array( $definition['prompts'] ) ) ) {
			return new \WP_Error( 'blueprint_invalid_prompts', __( 'Prompt-pack blueprints require a "prompts" array.', 'nvoos-content-graph-ai-platform' ) );
		}

		// Irreversible-field guard: a definition that mutates stored
		// irreversible fields (e.g. credentials) must be rejected.
		$irreversible = $this->guard_irreversible_fields( $definition );
		if ( is_wp_error( $irreversible ) ) {
			return $irreversible;
		}

		return true;
	}

	/**
	 * Validate the blueprint schema version string.
	 *
	 * @since 2.0.0
	 *
	 * @param string $version Version string (semver-ish, e.g. "1.0").
	 * @return true|\WP_Error True when acceptable, WP_Error otherwise.
	 */
	public function validate_version( string $version ) {
		if ( ! preg_match( '/^\d+\.\d+(\.\d+)?$/', $version ) ) {
			return new \WP_Error( 'blueprint_invalid_version', __( 'Blueprint version must be numeric semver-like (e.g. "1.0").', 'nvoos-content-graph-ai-platform' ) );
		}

		$current   = explode( '.', BlueprintRegistry::SCHEMA_VERSION );
		$candidate = explode( '.', $version );
		$major     = (int) ( $candidate[0] ?? 0 );

		if ( $major > (int) $current[0] ) {
			return new \WP_Error(
				'blueprint_version_too_new',
				sprintf(
					/* translators: %s: supported schema version */
					__( 'Blueprint schema version is newer than this plugin supports (supported: %s).', 'nvoos-content-graph-ai-platform' ),
					BlueprintRegistry::SCHEMA_VERSION
				)
			);
		}

		return true;
	}

	/**
	 * Reject definitions that carry irreversible/credential fields.
	 *
	 * Importers must never accept API keys or signing secrets through
	 * blueprint payloads — those are site-local and never exported.
	 *
	 * @since 2.0.0
	 *
	 * @param array $definition Blueprint definition.
	 * @return true|\WP_Error True when clean, WP_Error when a guarded field is present.
	 */
	private function guard_irreversible_fields( array $definition ) {
		$guarded = array(
			'api_key',
			'api_keys',
			'secret',
			'secrets',
			'credentials',
			'password',
			'token',
		);

		$flattened = wp_json_encode( $definition );
		$haystack  = strtolower( (string) $flattened );

		foreach ( $guarded as $field ) {
			// Guard keys specifically (string search on '"field":' keeps
			// false positives low while staying cheap for record-sized JSON).
			if ( false !== strpos( $haystack, '"' . $field . '":' ) ) {
				return new \WP_Error(
					'blueprint_irreversible_field',
					sprintf(
						/* translators: %s: guarded field name */
						__( 'Blueprint contains an irreversible field that is never exported or imported: %s.', 'nvoos-content-graph-ai-platform' ),
						$field
					)
				);
			}
		}

		return true;
	}
}
