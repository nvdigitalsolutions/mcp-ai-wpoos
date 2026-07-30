<?php
/**
 * Tool: okf_validate_attestation — Validate an OKF v0.2 Attested Computation.
 *
 * Reads an Attested Computation concept and mechanically verifies its
 * structure, trust signals, and supporting files. Does NOT execute the
 * computation itself — it validates that the concept is ready to be
 * trusted and tells the caller how to run the attester.
 *
 * @package WP_MCP_AI
 * @since   2.5.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 *
 * @link https://github.com/GoogleCloudPlatform/knowledge-catalog/blob/main/okf/SPEC.md
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OKF — Validate Attested Computation tool.
 *
 * @since 2.5.0
 */
class WP_MCP_AI_Tool_OKF_Validate_Attestation implements WP_MCP_AI_Tool_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Required frontmatter fields for an Attested Computation.
	 *
	 * @since 2.5.0
	 * @var string[]
	 */
	const REQUIRED_FM_FIELDS = array( 'runtime', 'executor', 'attester' );

	/**
	 * Expected concept type.
	 *
	 * @since 2.5.0
	 * @var string
	 */
	const CONCEPT_TYPE = 'Attested Computation';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'okf_validate_attestation';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'OKF — Validate Attested Computation', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __(
			'Validates an OKF v0.2 Attested Computation concept without executing it. Confirms the concept has the required structure (type, runtime, executor, attester), checks trust signals (status, trust tier, staleness), and verifies that referenced attester/executor files exist within the bundle. Returns the sanctioned computation body and a verdict on whether the computation is ready to be trusted. Use this before relying on a computed value — always validate the definition before accepting its output.',
			'mcp-ai-wpoos'
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'bundle'     => array(
					'type'        => 'string',
					'description' => __( 'OKF bundle name containing the Attested Computation.', 'mcp-ai-wpoos' ),
				),
				'concept_id' => array(
					'type'        => 'string',
					'description' => __( 'Concept ID of the Attested Computation (e.g. "computations/revenue-ytd").', 'mcp-ai-wpoos' ),
				),
			),
			'required'   => array( 'bundle', 'concept_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Gate 1 — Sanitize at entry.
		$bundle     = sanitize_text_field( $arguments['bundle'] );
		$concept_id = sanitize_text_field( $arguments['concept_id'] );

		if ( empty( $bundle ) || empty( $concept_id ) ) {
			return new WP_Error(
				'missing_params',
				__( 'Bundle and concept_id are required.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
		}

		$bundle_root = $this->resolve_bundle_root( $bundle );
		if ( is_wp_error( $bundle_root ) ) {
			return $bundle_root;
		}

		$reader  = new WP_MCP_AI_OKF_Reader( $bundle_root );
		$concept = $reader->get_concept( $concept_id );

		if ( is_wp_error( $concept ) ) {
			return $concept;
		}

		$fm = $concept['frontmatter'];

		// Validate this is an Attested Computation.
		$type_check = $this->validate_type( $fm, $concept_id );
		if ( is_wp_error( $type_check ) ) {
			return $type_check;
		}

		// Validate required frontmatter fields.
		$structure_issues = $this->validate_structure( $fm, $concept_id );
		$all_issues       = $structure_issues;

		// Validate referenced files exist in the bundle.
		$file_issues = $this->validate_referenced_files( $fm, $bundle_root, $reader );
		$all_issues  = array_merge( $all_issues, $file_issues );

		// Assess trust signals.
		$trust_tier = $reader->get_trust_tier( $fm );
		$is_stale   = $reader->is_stale( $fm );
		$status     = isset( $fm['status'] ) ? $fm['status'] : 'stable';

		// Build the verdict.
		$ready = $this->build_verdict( $all_issues, $trust_tier, $is_stale, $status );

		// Extract computation body.
		$computation_body = $this->extract_computation_body( $concept['body'], $fm );

		// Build executor summary.
		$executor_summary = $this->build_executor_summary( $fm, $bundle_root, $reader );

		// Build attester summary.
		$attester_summary = $this->build_attester_summary( $fm, $bundle_root, $reader );

		// Gate 2 — Escape at exit.
		return $this->format_success_response(
			$ready['ready']
				? sprintf(
					/* translators: %s: concept ID */
					__( 'Attested Computation "%s" is valid and ready to trust.', 'mcp-ai-wpoos' ),
					$concept_id
				)
				: sprintf(
					/* translators: %s: concept ID */
					__( 'Attested Computation "%s" has issues that should be reviewed before trusting.', 'mcp-ai-wpoos' ),
					$concept_id
				),
			array(
				'bundle'           => esc_html( $bundle ),
				'concept_id'       => esc_html( $concept['concept_id'] ),
				'type'             => esc_html( $fm['type'] ),
				'title'            => isset( $fm['title'] ) ? esc_html( $fm['title'] ) : '',
				'runtime'          => isset( $fm['runtime'] ) ? esc_html( $fm['runtime'] ) : '',
				// Trust signals.
				'status'           => esc_html( $status ),
				'trust_tier'       => esc_html( $trust_tier ),
				'stale'            => $is_stale,
				'stale_after'      => isset( $fm['stale_after'] ) ? esc_html( $fm['stale_after'] ) : '',
				// Verdict.
				'verdict'          => array(
					'ready'  => $ready['ready'],
					'reason' => esc_html( $ready['reason'] ),
				),
				'issues'           => array_map( 'esc_html', $all_issues ),
				// Computation metadata.
				'computation_body' => wp_kses_post( $computation_body ),
				'parameters'       => isset( $fm['parameters'] ) ? $fm['parameters'] : array(),
				'executor'         => $executor_summary,
				'attester'         => $attester_summary,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Validation helpers
	// -------------------------------------------------------------------------

	/**
	 * Validate the concept has the correct type.
	 *
	 * @since 2.5.0
	 *
	 * @param array  $fm         Parsed frontmatter.
	 * @param string $concept_id Concept ID for error messages.
	 * @return true|WP_Error
	 */
	private function validate_type( $fm, $concept_id ) {
		if ( ! isset( $fm['type'] ) || self::CONCEPT_TYPE !== $fm['type'] ) {
			return new WP_Error(
				'okf_wrong_type',
				sprintf(
					/* translators: 1: concept ID, 2: actual type, 3: expected type */
					__( 'Concept "%1$s" has type "%2$s" — expected "%3$s".', 'mcp-ai-wpoos' ),
					$concept_id,
					isset( $fm['type'] ) ? $fm['type'] : 'none',
					self::CONCEPT_TYPE
				)
			);
		}
		return true;
	}

	/**
	 * Validate required frontmatter fields are present.
	 *
	 * @since 2.5.0
	 *
	 * @param array  $fm         Parsed frontmatter.
	 * @param string $concept_id Concept ID for error messages.
	 * @return string[] List of human-readable issues.
	 */
	private function validate_structure( $fm, $concept_id ) {
		$issues = array();

		foreach ( self::REQUIRED_FM_FIELDS as $field ) {
			if ( empty( $fm[ $field ] ) ) {
				$issues[] = sprintf(
					/* translators: 1: concept ID, 2: field name */
					__( 'Concept "%1$s" is missing required field "%2$s".', 'mcp-ai-wpoos' ),
					$concept_id,
					$field
				);
				continue;
			}

			// executor and attester must each have a `resource` sub-key.
			if ( ( 'executor' === $field || 'attester' === $field ) && is_array( $fm[ $field ] ) ) {
				if ( empty( $fm[ $field ]['resource'] ) ) {
					$issues[] = sprintf(
						/* translators: 1: concept ID, 2: field name */
						__( 'Concept "%1$s": "%2$s" is present but missing the required "resource" sub-field.', 'mcp-ai-wpoos' ),
						$concept_id,
						$field
					);
				}
			}
		}

		return $issues;
	}

	/**
	 * Check that bundle-relative resource files exist on disk.
	 *
	 * @since 2.5.0
	 *
	 * @param array                $fm          Parsed frontmatter.
	 * @param string               $bundle_root Absolute bundle root path.
	 * @param WP_MCP_AI_OKF_Reader $reader    Reader instance.
	 * @return string[] List of file-not-found issues.
	 */
	private function validate_referenced_files( $fm, $bundle_root, $reader ) {
		$issues = array();

		foreach ( array( 'executor', 'attester' ) as $field ) {
			if ( empty( $fm[ $field ]['resource'] ) ) {
				continue;
			}

			$resource = $fm[ $field ]['resource'];

			// Only check bundle-relative paths (not URLs).
			if ( preg_match( '#^https?://#', $resource ) ) {
				continue;
			}

			// Resolve the path relative to the bundle root.
			$resolved = wp_normalize_path( $bundle_root . '/' . ltrim( $resource, '/' ) );

			if ( ! file_exists( $resolved ) ) {
				$issues[] = sprintf(
					/* translators: 1: field name, 2: resource path */
					__( '%1$s resource file not found in bundle: "%2$s".', 'mcp-ai-wpoos' ),
					$field,
					$resource
				);
			}
		}

		return $issues;
	}

	/**
	 * Build a verdict on whether the computation is ready to trust.
	 *
	 * @since 2.5.0
	 *
	 * @param string[] $issues    Validation issues found.
	 * @param string   $trust_tier Trust tier.
	 * @param bool     $is_stale   Whether the concept is stale.
	 * @param string   $status     Lifecycle status.
	 * @return array{ready: bool, reason: string}
	 */
	private function build_verdict( $issues, $trust_tier, $is_stale, $status ) {
		// Structural issues are hard blockers.
		if ( ! empty( $issues ) ) {
			return array(
				'ready'  => false,
				'reason' => __( 'The concept has structural issues that must be fixed before attestation.', 'mcp-ai-wpoos' ),
			);
		}

		// Deprecated concepts should not be used for new work.
		if ( 'deprecated' === strtolower( $status ) ) {
			return array(
				'ready'  => false,
				'reason' => __( 'The concept is deprecated. Use the replacement computation instead.', 'mcp-ai-wpoos' ),
			);
		}

		// Stale concepts need re-verification.
		if ( $is_stale ) {
			return array(
				'ready'  => false,
				'reason' => __( 'The concept is past its stale_after date and needs re-verification before use.', 'mcp-ai-wpoos' ),
			);
		}

		// Draft concepts are not yet approved.
		if ( 'draft' === strtolower( $status ) ) {
			return array(
				'ready'  => false,
				'reason' => __( 'The concept is in draft status and has not been finalised.', 'mcp-ai-wpoos' ),
			);
		}

		// Warn about unverified but don't block — machine-confirmed and human-reviewed are both fine.
		$warnings = array();
		if ( 'unverified' === $trust_tier ) {
			$warnings[] = __( 'The concept is unverified — no independent confirmation exists.', 'mcp-ai-wpoos' );
		}

		if ( ! empty( $warnings ) ) {
			return array(
				'ready'  => true,
				'reason' => implode( ' ', $warnings ),
			);
		}

		return array(
			'ready'  => true,
			'reason' => __( 'The Attested Computation is structurally valid, verified, and current.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Extract the computation body from a concept's markdown.
	 *
	 * Returns everything after the `# Computation` heading (or the full body
	 * if no such heading exists).
	 *
	 * @since 2.5.0
	 *
	 * @param string $body Markdown body content.
	 * @param array  $fm   Frontmatter (unused; reserved for future extraction hints).
	 * @return string
	 */
	private function extract_computation_body( $body, $fm ) {
		unset( $fm ); // Reserved for future use.

		// Try to extract just the computation section.
		if ( preg_match( '/^#+\s*Computation\s*$(.+)/ms', $body, $m ) ) {
			return trim( $m[1] );
		}

		return trim( $body );
	}

	/**
	 * Build a summary of the executor configuration.
	 *
	 * @since 2.5.0
	 *
	 * @param array                $fm          Parsed frontmatter.
	 * @param string               $bundle_root Absolute bundle root path.
	 * @param WP_MCP_AI_OKF_Reader $reader    Reader instance.
	 * @return array
	 */
	private function build_executor_summary( $fm, $bundle_root, $reader ) {
		unset( $reader ); // Reserved for future concept resolution.

		if ( empty( $fm['executor'] ) || ! is_array( $fm['executor'] ) ) {
			return array();
		}

		$summary = array(
			'resource'    => isset( $fm['executor']['resource'] ) ? esc_html( $fm['executor']['resource'] ) : '',
			'is_external' => false,
		);

		if ( ! empty( $fm['executor']['resource'] ) ) {
			$resource                   = $fm['executor']['resource'];
			$summary['is_external']     = (bool) preg_match( '#^https?://#', $resource );
			$summary['resource_exists'] = $summary['is_external']
				? null
				: file_exists( wp_normalize_path( $bundle_root . '/' . ltrim( $resource, '/' ) ) );
		}

		if ( ! empty( $fm['executor']['receipt'] ) ) {
			$summary['expected_receipt_fields'] = $fm['executor']['receipt'];
		}

		return $summary;
	}

	/**
	 * Build a summary of the attester configuration.
	 *
	 * @since 2.5.0
	 *
	 * @param array                $fm          Parsed frontmatter.
	 * @param string               $bundle_root Absolute bundle root path.
	 * @param WP_MCP_AI_OKF_Reader $reader    Reader instance.
	 * @return array
	 */
	private function build_attester_summary( $fm, $bundle_root, $reader ) {
		unset( $reader ); // Reserved for future concept resolution.

		if ( empty( $fm['attester'] ) || ! is_array( $fm['attester'] ) ) {
			return array();
		}

		$summary = array(
			'resource'    => isset( $fm['attester']['resource'] ) ? esc_html( $fm['attester']['resource'] ) : '',
			'is_external' => false,
		);

		if ( ! empty( $fm['attester']['resource'] ) ) {
			$resource                   = $fm['attester']['resource'];
			$summary['is_external']     = (bool) preg_match( '#^https?://#', $resource );
			$summary['resource_exists'] = $summary['is_external']
				? null
				: file_exists( wp_normalize_path( $bundle_root . '/' . ltrim( $resource, '/' ) ) );
		}

		return $summary;
	}

	// -------------------------------------------------------------------------
	// Bundle resolution (shared pattern across all OKF tools)
	// -------------------------------------------------------------------------

	/**
	 * Resolve a bundle name to an absolute directory path.
	 *
	 * @since 2.5.0
	 *
	 * @param string $bundle Bundle name or relative path.
	 * @return string|WP_Error
	 */
	private function resolve_bundle_root( $bundle ) {
		if ( false !== strpos( $bundle, '..' ) ) {
			return new WP_Error(
				'okf_invalid_bundle',
				__( 'Invalid bundle name.', 'mcp-ai-wpoos' )
			);
		}

		$upload_dir = wp_upload_dir();
		$base       = $upload_dir['basedir'] . '/mcp-ai-wpoos/knowledge';
		$path       = wp_normalize_path( $base . '/' . $bundle );

		if ( is_dir( $path ) ) {
			return $path;
		}

		return new WP_Error(
			'okf_bundle_not_found',
			sprintf(
				/* translators: %s: bundle name */
				__( 'OKF bundle not found: %s', 'mcp-ai-wpoos' ),
				$bundle
			)
		);
	}
}
