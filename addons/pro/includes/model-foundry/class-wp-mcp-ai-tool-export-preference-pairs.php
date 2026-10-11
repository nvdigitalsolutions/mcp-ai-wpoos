<?php
/**
 * Preference-pairs exporter — Model Foundry (Pro, Phase 1).
 *
 * Distils the per-assistant `WP_MCP_AI_Preference_Pair_Store` (plus any
 * pairs contributed by other plugins through the
 * `wp_mcp_ai_model_foundry_preference_pairs` filter) into a
 * `preference_v1` DPO corpus:
 *
 *     {"prompt": <user prompt>, "chosen": <preferred>, "rejected": <dispreferred>}
 *
 * Rows honour the researched DPO shape (R4): exactly three non-empty string
 * fields, no lists. Pairs below the requested judge margin are skipped with
 * `skipped_low_margin`. The store is populated by other systems
 * (chat-UI thumbs and eval-based rejection sampling land in later phases);
 * this tool is the export half of that contract.
 *
 * The exporter exports; it does not train and does not call the chat model.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export stored preference pairs as a DPO corpus.
 */
class WP_MCP_AI_Tool_Export_Preference_Pairs implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Data_Contract_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * Hard cap on emitted rows per export.
	 */
	const HARD_MAX_PAIRS = 1000;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'export_preference_pairs';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Export Preference Pairs (Model Foundry)', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Distil an assistant\'s stored preference pairs into a trainer-agnostic DPO corpus (preference_v1): one prompt/chosen/rejected JSON line per pair, sourced from the preference-pair store and the wp_mcp_ai_model_foundry_preference_pairs filter. Requires the assistant\'s training consent. Use dry_run to preview counts before writing.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Building a DPO/GRPO training set from chosen/rejected pairs the site has accumulated for an assistant.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Exporting tool-calling behaviour; use export_trajectory_corpus. Exporting static eval-suite curricula; use export_fine_tune_curriculum.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'export_fine_tune_curriculum', 'export_trajectory_corpus', 'export_plugin_docs_corpus' ),
			'notes'           => __( 'Pairs with a stored judge margin below min_margin are skipped (skipped_low_margin). Output lands under wp-content/uploads/mcp-ai/model-foundry/. Requires manage_options and training consent.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'assistant_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Assistant CPT post ID whose stored preference pairs are exported.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'max_pairs'      => array(
					'type'        => 'integer',
					'description' => __( 'Cap on emitted rows. Hard ceiling 1000.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => self::HARD_MAX_PAIRS,
				),
				'min_margin'     => array(
					'type'        => 'number',
					'description' => __( 'Skip pairs whose stored judge margin is below this value (0–1). Pairs without a margin always pass. Default 0.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 1,
					'default'     => 0,
				),
				'semantic_dedup' => array(
					'type'        => 'boolean',
					'description' => __( 'Enable the embedding-based semantic dedup tier (opt-in; bounded, uses the site embedding provider).', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'dry_run'        => array(
					'type'        => 'boolean',
					'description' => __( 'When true, returns counts and a preview of the first row without writing any file.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'   => array( 'assistant_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_data_contract() {
		return array(
			'produces' => 'preference_v1',
			'consumes' => array( 'assistant_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'read-only', 'local-only', 'idempotent', 'cacheable' );
	}

	/**
	 * Execute the preference-pairs export.
	 *
	 * @param array $arguments Execution arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Canonical envelope or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability resolved via get_required_capability(), a stable 'manage_options'.
		if ( ! current_user_can( $this->get_required_capability() ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		$assistant_id = isset( $arguments['assistant_id'] ) ? absint( $arguments['assistant_id'] ) : 0;
		if ( $assistant_id <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_invalid_assistant', __( 'A valid assistant_id is required.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( get_post_type( $assistant_id ) !== 'mcp_ai_assistant' ) {
			return new WP_Error( 'wp_mcp_ai_unknown_assistant', __( 'Assistant not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Consent fails closed before any stored pair is read.
		$governance = new WP_MCP_AI_Corpus_Governance();
		$consent    = $governance->assert_consent( $assistant_id );
		if ( is_wp_error( $consent ) ) {
			return $consent;
		}

		$max_pairs = isset( $arguments['max_pairs'] ) ? absint( $arguments['max_pairs'] ) : self::HARD_MAX_PAIRS;
		if ( $max_pairs <= 0 || $max_pairs > self::HARD_MAX_PAIRS ) {
			$max_pairs = self::HARD_MAX_PAIRS;
		}

		$min_margin = isset( $arguments['min_margin'] ) ? (float) $arguments['min_margin'] : 0.0;
		if ( $min_margin < 0.0 || $min_margin > 1.0 ) {
			$min_margin = 0.0;
		}

		$dry_run  = ! empty( $arguments['dry_run'] );
		$semantic = ! empty( $arguments['semantic_dedup'] );

		$pairs = WP_MCP_AI_Preference_Pair_Store::get_all( $assistant_id );

		/**
		 * Filter: contribute additional preference pairs at export time.
		 *
		 * @param array<int,array{chosen:string,rejected:string,margin:?float,source:string}> $pairs Pairs from the store.
		 * @param int    $assistant_id Assistant post ID.
		 */
		$pairs = apply_filters( 'wp_mcp_ai_model_foundry_preference_pairs', $pairs, $assistant_id );
		if ( ! is_array( $pairs ) ) {
			$pairs = array();
		}

		$rows                = array();
		$skipped_invalid     = 0;
		$skipped_low_margin  = 0;
		$preview_row         = '';

		foreach ( $pairs as $pair ) {
			if ( count( $rows ) >= $max_pairs ) {
				break;
			}
			if ( ! is_array( $pair ) ) {
				++$skipped_invalid;
				continue;
			}

			$prompt   = isset( $pair['prompt'] ) ? trim( (string) $pair['prompt'] ) : '';
			$chosen   = isset( $pair['chosen'] ) ? trim( (string) $pair['chosen'] ) : '';
			$rejected = isset( $pair['rejected'] ) ? trim( (string) $pair['rejected'] ) : '';
			if ( '' === $prompt || '' === $chosen || '' === $rejected ) {
				++$skipped_invalid;
				continue;
			}

			if ( $min_margin > 0.0 && isset( $pair['margin'] ) && null !== $pair['margin'] && (float) $pair['margin'] < $min_margin ) {
				++$skipped_low_margin;
				continue;
			}

			$row = array(
				'prompt'   => $prompt,
				'chosen'   => $chosen,
				'rejected' => $rejected,
			);
			$rows[] = $row;
			if ( '' === $preview_row ) {
				$preview_row = (string) wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}
		}

		if ( empty( $rows ) ) {
			return new WP_Error(
				'wp_mcp_ai_preference_corpus_empty',
				__( 'No preference pairs are stored for this assistant. Record pairs via WP_MCP_AI_Preference_Pair_Store::record() or the wp_mcp_ai_model_foundry_preference_pairs filter first.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( $dry_run ) {
			return array(
				'success'             => true,
				'dry_run'             => true,
				'assistant_id'        => $assistant_id,
				'schema'              => 'preference_v1',
				'rows'                => count( $rows ),
				'skipped_invalid'     => $skipped_invalid,
				'skipped_low_margin'  => $skipped_low_margin,
				'preview'             => $preview_row,
			);
		}

		$result = $governance->build(
			$assistant_id,
			'preference_v1',
			$rows,
			array(
				'semantic'        => $semantic,
				'provenance_tool' => $this->get_slug(),
				'sources'         => array(
					array(
						'kind'         => 'preference_pair_store',
						'pairs_loaded' => count( $pairs ),
					),
				),
				'max_rows'        => $max_pairs,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['skipped_invalid']    = $skipped_invalid;
		$result['skipped_low_margin'] = $skipped_low_margin;
		$result['preview']            = $preview_row;

		return $result;
	}
}
