<?php
/**
 * wellness/class-wp-mcp-ai-tool-verify-prescription-interactions.php (ecosystem port — Wave F4, healthcare wellness breadth batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/healthcare/wellness/class-wp-mcp-ai-tool-verify-prescription-interactions.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps (the capture-tool-base require resolves from the
 * addon's already-ported `src/tools/capture/` copy; the media-worker-client trait resolves via
 * the entry's `src/traits/` probe).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verify prescription interactions tool.
 */
class WP_MCP_AI_Tool_Verify_Prescription_Interactions implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Return the curated baseline interaction pairs.  Each entry uses
	 * lower-case canonical drug names so the lookup is case-insensitive.
	 *
	 * The list is intentionally conservative: only well-established,
	 * widely-cited contraindications.  Partner integrations should extend
	 * via the `wp_mcp_ai_healthcare_interaction_pairs` filter.
	 *
	 * @return array
	 */
	public static function get_pairs() {
		$pairs = array(
			array(
				'a'        => 'warfarin',
				'b'        => 'aspirin',
				'severity' => 'major',
				'note'     => __( 'Concurrent use significantly increases bleeding risk; monitor INR and consider alternatives.', 'nvoos-content-graph-pro' ),
			),
			array(
				'a'        => 'warfarin',
				'b'        => 'ibuprofen',
				'severity' => 'major',
				'note'     => __( 'NSAIDs increase the anticoagulant effect of warfarin and the risk of GI bleeding.', 'nvoos-content-graph-pro' ),
			),
			array(
				'a'        => 'warfarin',
				'b'        => 'naproxen',
				'severity' => 'major',
				'note'     => __( 'NSAIDs increase the anticoagulant effect of warfarin and the risk of GI bleeding.', 'nvoos-content-graph-pro' ),
			),
			array(
				'a'        => 'clopidogrel',
				'b'        => 'omeprazole',
				'severity' => 'moderate',
				'note'     => __( 'Strong CYP2C19 inhibitors (e.g. omeprazole) reduce activation of clopidogrel; consider pantoprazole.', 'nvoos-content-graph-pro' ),
			),
			array(
				'a'        => 'simvastatin',
				'b'        => 'clarithromycin',
				'severity' => 'major',
				'note'     => __( 'Strong CYP3A4 inhibition markedly raises simvastatin levels; risk of rhabdomyolysis.', 'nvoos-content-graph-pro' ),
			),
			array(
				'a'        => 'sildenafil',
				'b'        => 'nitroglycerin',
				'severity' => 'contraindicated',
				'note'     => __( 'PDE5 inhibitors with nitrates can cause severe hypotension; combination is contraindicated.', 'nvoos-content-graph-pro' ),
			),
			array(
				'a'        => 'tadalafil',
				'b'        => 'nitroglycerin',
				'severity' => 'contraindicated',
				'note'     => __( 'PDE5 inhibitors with nitrates can cause severe hypotension; combination is contraindicated.', 'nvoos-content-graph-pro' ),
			),
			array(
				'a'        => 'sertraline',
				'b'        => 'tramadol',
				'severity' => 'major',
				'note'     => __( 'Increased risk of serotonin syndrome; avoid or monitor closely.', 'nvoos-content-graph-pro' ),
			),
			array(
				'a'        => 'fluoxetine',
				'b'        => 'tramadol',
				'severity' => 'major',
				'note'     => __( 'Increased risk of serotonin syndrome; avoid or monitor closely.', 'nvoos-content-graph-pro' ),
			),
			array(
				'a'        => 'lisinopril',
				'b'        => 'spironolactone',
				'severity' => 'moderate',
				'note'     => __( 'Risk of hyperkalemia; monitor potassium and renal function.', 'nvoos-content-graph-pro' ),
			),
			array(
				'a'        => 'metformin',
				'b'        => 'iodinated contrast',
				'severity' => 'moderate',
				'note'     => __( 'Hold metformin around iodinated contrast administration in patients with renal impairment.', 'nvoos-content-graph-pro' ),
			),
			array(
				'a'        => 'methotrexate',
				'b'        => 'trimethoprim',
				'severity' => 'major',
				'note'     => __( 'Concurrent antifolates raise risk of methotrexate toxicity (pancytopenia).', 'nvoos-content-graph-pro' ),
			),
		);

		/**
		 * Filter the curated drug-drug interaction pairs.
		 *
		 * @since 1.4.0
		 *
		 * @param array $pairs List of interaction pairs.
		 */
		return apply_filters( 'wp_mcp_ai_healthcare_interaction_pairs', $pairs );
	}

	/**
	 * Whether the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_health_wellness_management'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'verify_prescription_interactions';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Verify Prescription Interactions', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Screen a list of medications (or all active prescriptions for a member) for known drug-drug interactions using a curated, RxNorm-aligned offline registry. Extend with the wp_mcp_ai_healthcare_interaction_pairs filter for site-specific or external sources.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'member_id'   => array(
					'type'        => 'integer',
					'description' => __( 'When provided, also pulls active prescriptions for this member.', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'medications' => array(
					'type'        => 'array',
					'description' => __( 'Additional medication names to screen alongside the member\'s prescriptions.', 'nvoos-content-graph-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'read-only', 'pii-data', 'cacheable' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to verify prescription interactions.', 'nvoos-content-graph-pro' ) );
		}

		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;

		$meds = array();
		if ( isset( $arguments['medications'] ) && is_array( $arguments['medications'] ) ) {
			foreach ( $arguments['medications'] as $m ) {
				$clean = trim( sanitize_text_field( (string) $m ) );
				if ( '' !== $clean ) {
					$meds[] = $clean;
				}
			}
		}

		$considered = array();
		foreach ( $meds as $name ) {
			$considered[] = array(
				'name'       => $name,
				'rxcui'      => $this->lookup_rxcui( $name ),
				'source'     => 'argument',
				'related_id' => 0,
			);
		}

		if ( $member_id > 0 ) {
			$query = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_prescription',
					'post_status'    => 'publish',
					'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'verify_prescription_interactions', 0, 1000 ) : 1000,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'AND',
						array(
							'key'   => '_prescription_member_id',
							'value' => $member_id,
						),
						array(
							'key'     => '_prescription_status',
							'value'   => array( 'active', 'refilled' ),
							'compare' => 'IN',
						),
					),
					'no_found_rows'  => true,
				)
			);
			if ( $query->have_posts() ) {
				while ( $query->have_posts() ) {
					$query->the_post();
					$id   = get_the_ID();
					$name = (string) get_post_meta( $id, '_prescription_medication_name', true );
					if ( '' === $name ) {
						$name = get_the_title();
					}
					$considered[] = array(
						'name'       => $name,
						'rxcui'      => $this->lookup_rxcui( $name ),
						'source'     => 'prescription',
						'related_id' => $id,
					);
				}
				wp_reset_postdata();
			}
		}

		if ( count( $considered ) < 2 ) {
			return array(
				'success'      => true,
				'member_id'    => $member_id,
				'medications'  => $considered,
				'interactions' => array(),
				'message'      => __( 'Need at least two medications to screen for interactions.', 'nvoos-content-graph-pro' ),
			);
		}

		$pairs        = self::get_pairs();
		$interactions = array();
		$count        = count( $considered );

		for ( $i = 0; $i < $count; $i++ ) {
			$na = strtolower( $considered[ $i ]['name'] );
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$nb = strtolower( $considered[ $j ]['name'] );
				foreach ( $pairs as $pair ) {
					$pa = isset( $pair['a'] ) ? strtolower( (string) $pair['a'] ) : '';
					$pb = isset( $pair['b'] ) ? strtolower( (string) $pair['b'] ) : '';
					if ( '' === $pa || '' === $pb ) {
						continue;
					}
					$hit = ( false !== strpos( $na, $pa ) && false !== strpos( $nb, $pb ) )
						|| ( false !== strpos( $na, $pb ) && false !== strpos( $nb, $pa ) );
					if ( $hit ) {
						$interactions[] = array(
							'drug_a'      => $considered[ $i ]['name'],
							'drug_b'      => $considered[ $j ]['name'],
							'severity'    => isset( $pair['severity'] ) ? (string) $pair['severity'] : 'unknown',
							'description' => isset( $pair['note'] ) ? (string) $pair['note'] : '',
						);
						break;
					}
				}
			}
		}

		if ( class_exists( 'WP_MCP_AI_Healthcare_Audit' ) ) {
			WP_MCP_AI_Healthcare_Audit::record(
				'read',
				'prescription_interactions',
				$member_id,
				array(
					'user_id'      => $current_user_id,
					'tool'         => $this->get_slug(),
					'count'        => count( $considered ),
					'interactions' => count( $interactions ),
				)
			);
		}

		return array(
			'success'      => true,
			'member_id'    => $member_id,
			'medications'  => $considered,
			'interactions' => $interactions,
			'sources'      => array( 'curated-offline' ),
		);
	}

	/**
	 * Look up an RxCUI for a medication name.  Default implementation returns
	 * an empty string; partner code can hook `wp_mcp_ai_healthcare_rxnorm_lookup`
	 * to call out to RxNav (the public RxNorm API still works for name→RxCUI
	 * lookups).
	 *
	 * @param string $name Drug name.
	 * @return string
	 */
	private function lookup_rxcui( $name ) {
		/**
		 * Filter the RxNorm RxCUI lookup for a drug name.
		 *
		 * @since 1.4.0
		 *
		 * @param string $rxcui Resolved RxCUI; default empty string.
		 * @param string $name  Drug name being looked up.
		 */
		return (string) apply_filters( 'wp_mcp_ai_healthcare_rxnorm_lookup', '', $name );
	}
}
