<?php
/**
 * Tool: seo_validate_schema — Validates JSON-LD structured data locally.
 *
 * Port of mcp-wordpress wp_seo_validate_schema tool.
 *
 * @link    https://github.com/docdyhr/mcp-wordpress
 * @credit  mcp-wordpress by Aionda GmbH (MIT)
 * @package WP_MCP_AI
 * @since   1.1.87
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO Validate Schema — checks JSON-LD against schema.org conventions.
 *
 * Validates @context, @type, and per-type required fields locally. It never
 * makes network calls — the Google Rich Results validator is intentionally
 * not used.
 *
 * @since 1.1.87
 */
class WP_MCP_AI_Tool_SEO_Validate_Schema implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'seo_validate_schema';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'SEO Schema Validator', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Validates a JSON-LD schema object against schema.org conventions: @context, @type, @id, and per-type required fields. Runs entirely locally — it never calls the Google Rich Results validator or any external service.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Checking JSON-LD markup before publishing: verifying required fields per schema type and catching a missing or wrong @context/@type.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Creating markup from scratch; use seo_generate_schema. For Google-specific rich-result eligibility, use Google\'s Rich Results Test manually.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'seo_generate_schema', 'seo_meta_optimizer', 'get_rankmath_seo' ),
			'notes'           => __( 'Pass the schema as an object under the "schema" argument. Omit schema_type to validate whatever @type the object declares.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'schema'      => array(
					'type'                 => 'object',
					'description'          => __( 'The JSON-LD schema object to validate (an associative array decoded from JSON).', 'mcp-ai-wpoos' ),
					'additionalProperties' => true,
				),
				'schema_type' => array(
					'type'        => 'string',
					'description' => __( 'Optional expected @type. When provided, the declared @type must match or an error is reported.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'Article', 'Product', 'FAQPage', 'HowTo', 'Organization', 'Website', 'BreadcrumbList', 'Event', 'Recipe', 'Person', 'LocalBusiness', 'Review', 'VideoObject', 'Course' ),
				),
			),
			'required'             => array( 'schema' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.87
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'content_publishing',
			'pattern_compatibility' => array( 'orchestrator' ),
			'profession_tags'       => array( 'seo_specialist', 'content_strategist' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',           // Only reads data, does not modify state.
			'local-only',          // No external API calls.
			'requires-capability', // Requires user capabilities.
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$acting_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $acting_user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to use this tool.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $acting_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to validate schema.', 'mcp-ai-wpoos' ) );
		}

		$schema = isset( $arguments['schema'] ) && is_array( $arguments['schema'] ) ? $this->sanitize_schema_input( $arguments['schema'] ) : array();
		if ( empty( $schema ) ) {
			return new WP_Error( 'wp_mcp_ai_schema_required', __( 'A schema object is required to validate.', 'mcp-ai-wpoos' ) );
		}

		$expected_type = isset( $arguments['schema_type'] ) ? sanitize_text_field( $arguments['schema_type'] ) : '';

		$errors   = array();
		$warnings = array();
		$detected = null;

		// @context check (warning level).
		if ( ! array_key_exists( '@context', $schema ) || '' === $schema['@context'] ) {
			$warnings[] = __( 'Missing @context; JSON-LD should declare "@context": "https://schema.org".', 'mcp-ai-wpoos' );
		} elseif ( ! is_string( $schema['@context'] ) || 'https://schema.org' !== $schema['@context'] ) {
			$context_label = is_string( $schema['@context'] ) ? $schema['@context'] : __( 'a non-string value', 'mcp-ai-wpoos' );
			$warnings[]    = sprintf(
				/* translators: %s: the provided @context value */
				__( 'Unexpected @context value "%s"; "https://schema.org" is recommended.', 'mcp-ai-wpoos' ),
				esc_html( $context_label )
			);
		}

		// @type check (error level).
		if ( ! array_key_exists( '@type', $schema ) ) {
			$errors[] = __( 'Missing @type; the schema type must be declared.', 'mcp-ai-wpoos' );
		} elseif ( ! is_string( $schema['@type'] ) || '' === trim( $schema['@type'] ) ) {
			$errors[] = __( 'Invalid @type; it must be a non-empty string.', 'mcp-ai-wpoos' );
		} else {
			$detected = $schema['@type'];
			if ( '' !== $expected_type && $detected !== $expected_type ) {
				$errors[] = sprintf(
					/* translators: 1: expected type, 2: detected type */
					__( '@type mismatch: expected "%1$s" but found "%2$s".', 'mcp-ai-wpoos' ),
					esc_html( $expected_type ),
					esc_html( $detected )
				);
			}
			$this->validate_required_fields( $schema, $detected, $errors, $warnings );
		}

		// @id check (warning level).
		if ( ! array_key_exists( '@id', $schema ) || '' === $schema['@id'] ) {
			$warnings[] = __( 'Missing @id; a unique identifier helps search engines merge entities.', 'mcp-ai-wpoos' );
		}

		$valid = empty( $errors );

		$message = sprintf(
			/* translators: 1: number of errors, 2: number of warnings */
			__( 'Schema validation completed with %1$d error(s) and %2$d warning(s).', 'mcp-ai-wpoos' ),
			count( $errors ),
			count( $warnings )
		);

		return array(
			'message'     => $message,
			'valid'       => $valid,
			'errors'      => $errors,
			'warnings'    => $warnings,
			'schema_type' => $detected,
		);
	}

	/**
	 * Validates required fields for a given schema type.
	 *
	 * @since 1.1.87
	 *
	 * @param array  $schema   The sanitized schema.
	 * @param string $type     The declared @type.
	 * @param array  $errors   Error list (modified in place).
	 * @param array  $warnings Warning list (modified in place).
	 */
	private function validate_required_fields( $schema, $type, &$errors, &$warnings ) {
		$required = $this->get_required_fields( $type );

		// Course.description is advisory: warn, never error.
		if ( 'Course' === $type && in_array( 'description', $required, true ) ) {
			$required = array_diff( $required, array( 'description' ) );
			if ( ! array_key_exists( 'description', $schema ) || '' === $schema['description'] ) {
				$warnings[] = __( 'Course schema is missing a description.', 'mcp-ai-wpoos' );
			}
		}

		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $schema ) ) {
				$errors[] = sprintf(
					/* translators: %s: field name */
					__( 'Required field "%s" is missing for this schema type.', 'mcp-ai-wpoos' ),
					esc_html( $field )
				);
				continue;
			}

			$value = $schema[ $field ];
			if ( '' === $value || null === $value || ( is_array( $value ) && empty( $value ) ) ) {
				$warnings[] = sprintf(
					/* translators: %s: field name */
					__( 'Field "%s" is empty.', 'mcp-ai-wpoos' ),
					esc_html( $field )
				);
			}
		}

		// Structural checks for types with nested requirements.
		if ( 'FAQPage' === $type ) {
			$this->validate_faq( $schema, $errors, $warnings );
		} elseif ( 'HowTo' === $type ) {
			$this->validate_howto( $schema, $errors, $warnings );
		} elseif ( 'Website' === $type ) {
			if ( ! array_key_exists( 'url', $schema ) && ! array_key_exists( 'name', $schema ) ) {
				$errors[] = __( 'Website schema requires at least a url or a name.', 'mcp-ai-wpoos' );
			} elseif ( ! array_key_exists( 'url', $schema ) ) {
				$warnings[] = __( 'Website schema is missing a url.', 'mcp-ai-wpoos' );
			}
		}
	}

	/**
	 * Validates the mainEntity structure of a FAQPage schema.
	 *
	 * @since 1.1.87
	 *
	 * @param array $schema   The sanitized schema.
	 * @param array $errors   Error list (modified in place).
	 * @param array $warnings Warning list (modified in place).
	 */
	private function validate_faq( $schema, &$errors, &$warnings ) {
		if ( ! array_key_exists( 'mainEntity', $schema ) || ! is_array( $schema['mainEntity'] ) || empty( $schema['mainEntity'] ) ) {
			$errors[] = __( 'FAQPage mainEntity must be a non-empty array of Question objects.', 'mcp-ai-wpoos' );
			return;
		}

		foreach ( $schema['mainEntity'] as $question ) {
			if ( ! is_array( $question ) || ! array_key_exists( 'acceptedAnswer', $question ) ) {
				$warnings[] = __( 'A FAQPage question is missing its acceptedAnswer.', 'mcp-ai-wpoos' );
			}
		}
	}

	/**
	 * Validates the step structure of a HowTo schema.
	 *
	 * @since 1.1.87
	 *
	 * @param array $schema   The sanitized schema.
	 * @param array $errors   Error list (modified in place).
	 * @param array $warnings Warning list (modified in place).
	 */
	private function validate_howto( $schema, &$errors, &$warnings ) {
		if ( ! array_key_exists( 'step', $schema ) || ! is_array( $schema['step'] ) || empty( $schema['step'] ) ) {
			$errors[] = __( 'HowTo step must be a non-empty array of HowToStep objects.', 'mcp-ai-wpoos' );
		}
	}

	/**
	 * Returns the required fields per schema type.
	 *
	 * @since 1.1.87
	 *
	 * @param string $type Schema type.
	 * @return array Required field names.
	 */
	private function get_required_fields( $type ) {
		$map = array(
			'Article'        => array( 'headline', 'datePublished' ),
			'Product'        => array( 'name' ),
			'FAQPage'        => array( 'mainEntity' ),
			'HowTo'          => array( 'step' ),
			'Organization'   => array( 'name' ),
			'Website'        => array(),
			'BreadcrumbList' => array( 'itemListElement' ),
			'Event'          => array( 'name', 'startDate' ),
			'Recipe'         => array( 'name', 'recipeIngredient', 'recipeInstructions' ),
			'Person'         => array( 'name' ),
			'LocalBusiness'  => array( 'name', 'address' ),
			'Review'         => array( 'itemReviewed', 'reviewRating' ),
			'VideoObject'    => array( 'name', 'contentUrl', 'thumbnailUrl', 'uploadDate' ),
			'Course'         => array( 'name', 'description' ),
		);

		return isset( $map[ $type ] ) ? $map[ $type ] : array();
	}

	/**
	 * Recursively sanitizes the incoming schema object.
	 *
	 * Keys are preserved as-is (they legitimately contain "@"). String values
	 * are sanitized with wp_kses_post; arrays are recursed.
	 *
	 * @since 1.1.87
	 *
	 * @param mixed $schema Raw schema value.
	 * @return mixed Sanitized schema value.
	 */
	private function sanitize_schema_input( $schema ) {
		if ( is_array( $schema ) ) {
			$clean = array();
			foreach ( $schema as $key => $value ) {
				$clean[ $key ] = $this->sanitize_schema_input( $value );
			}
			return $clean;
		}

		if ( is_string( $schema ) ) {
			return wp_kses_post( trim( wp_unslash( $schema ) ) );
		}

		return $schema;
	}
}
