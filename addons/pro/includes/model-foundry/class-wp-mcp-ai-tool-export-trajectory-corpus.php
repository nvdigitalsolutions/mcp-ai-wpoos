<?php
/**
 * Trajectory corpus exporter — Model Foundry (Pro, Phase 1).
 *
 * Distils an assistant's harness trace runs into a `trajectory_v1` corpus:
 * OpenAI tool-calling chat rows where each row is one captured run —
 *
 *     {"messages":[
 *       {"role":"system","content": <system prompt>},
 *       {"role":"user","content": <user prompt>},
 *       {"role":"assistant","content":null,"tool_calls":[{...},…]},
 *       {"role":"tool","tool_call_id":…,"content": <result summary>},
 *       …,
 *       {"role":"assistant","content": <final model response>}
 *     ]}
 *
 * Sources per run (Base harness artifacts): `tool_calls.jsonl` (the call
 * sequence), `model_response.txt` (final response), `retrieval.json`
 * (`query` — the user prompt proxy when retrieval ran). Runs without a
 * user prompt are skipped (`skipped_no_user_input`); the
 * `wp_mcp_ai_model_foundry_run_user_prompt` filter lets other plugins supply
 * the prompt for runs where retrieval never fired.
 *
 * The exporter exports; it does not train and does not call the chat model.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.4
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export an assistant's trace runs as a tool-calling trajectory corpus.
 */
class WP_MCP_AI_Tool_Export_Trajectory_Corpus implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Data_Contract_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * Hard cap on emitted rows per export.
	 */
	const HARD_MAX_CASES = 5000;

	/**
	 * Default per-row payload size cap in characters.
	 */
	const DEFAULT_PER_CASE_CHAR_CAP = 16000;

	/**
	 * Default number of trace runs walked per export.
	 */
	const DEFAULT_MAX_RUNS = 100;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'export_trajectory_corpus';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Export Trajectory Corpus (Model Foundry)', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Distil an assistant\'s harness trace runs into a trainer-agnostic tool-calling corpus (trajectory_v1): one OpenAI tool-calling chat row per captured run, built from tool_calls.jsonl, model_response.txt, and the retrieval query. Requires the assistant\'s training consent. Use dry_run to preview counts before writing.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Building a tool-calling SFT corpus from an assistant\'s real execution history for fine-tuning.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Exporting static eval-suite curricula; use export_fine_tune_curriculum. Building DPO data; use export_preference_pairs. Exporting plugin documentation; use export_plugin_docs_corpus.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'export_fine_tune_curriculum', 'export_preference_pairs', 'export_plugin_docs_corpus' ),
			'notes'           => __( 'Runs without a retrievable user prompt are skipped (skipped_no_user_input). Output lands under wp-content/uploads/mcp-ai/model-foundry/. Requires manage_options and training consent.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'assistant_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Assistant CPT post ID whose trace runs are exported.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'max_runs'         => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of trace runs to walk (newest first; the trace store caps at 100). Default 100.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => self::DEFAULT_MAX_RUNS,
				),
				'max_cases'        => array(
					'type'        => 'integer',
					'description' => __( 'Cap on emitted rows. Hard ceiling 5000.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => self::HARD_MAX_CASES,
				),
				'system_prompt'    => array(
					'type'        => 'string',
					'description' => __( 'Optional system message for every row. Defaults to the assistant\'s system prompt.', 'mcp-ai-wpoos-pro' ),
				),
				'semantic_dedup'   => array(
					'type'        => 'boolean',
					'description' => __( 'Enable the embedding-based semantic dedup tier (opt-in; bounded, uses the site embedding provider).', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'dry_run'          => array(
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
			'produces' => 'trajectory_v1',
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
	 * Execute the trajectory corpus export.
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

		if ( ! class_exists( 'WP_MCP_AI_Harness_Trace_Store' ) ) {
			return new WP_Error(
				'wp_mcp_ai_trace_store_unavailable',
				__( 'The harness trace store is not available, so there are no trajectory runs to export.', 'mcp-ai-wpoos-pro' )
			);
		}

		$assistant_id = isset( $arguments['assistant_id'] ) ? absint( $arguments['assistant_id'] ) : 0;
		if ( $assistant_id <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_invalid_assistant', __( 'A valid assistant_id is required.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( get_post_type( $assistant_id ) !== 'mcp_ai_assistant' ) {
			return new WP_Error( 'wp_mcp_ai_unknown_assistant', __( 'Assistant not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Consent fails closed before any trace data is read.
		$governance = new WP_MCP_AI_Corpus_Governance();
		$consent    = $governance->assert_consent( $assistant_id );
		if ( is_wp_error( $consent ) ) {
			return $consent;
		}

		$max_runs = isset( $arguments['max_runs'] ) ? absint( $arguments['max_runs'] ) : self::DEFAULT_MAX_RUNS;
		if ( $max_runs <= 0 || $max_runs > 100 ) {
			$max_runs = self::DEFAULT_MAX_RUNS;
		}

		$max_cases = isset( $arguments['max_cases'] ) ? absint( $arguments['max_cases'] ) : self::HARD_MAX_CASES;
		if ( $max_cases <= 0 || $max_cases > self::HARD_MAX_CASES ) {
			$max_cases = self::HARD_MAX_CASES;
		}

		$system_prompt = isset( $arguments['system_prompt'] ) && is_string( $arguments['system_prompt'] )
			? trim( wp_strip_all_tags( $arguments['system_prompt'] ) )
			: '';
		if ( '' === $system_prompt ) {
			$system_prompt = $this->resolve_default_system_prompt( $assistant_id );
		}

		$dry_run  = ! empty( $arguments['dry_run'] );
		$semantic = ! empty( $arguments['semantic_dedup'] );

		$per_case_cap = (int) apply_filters(
			'wp_mcp_ai_model_foundry_trajectory_per_case_char_cap',
			self::DEFAULT_PER_CASE_CHAR_CAP,
			$assistant_id
		);
		if ( $per_case_cap < 256 ) {
			$per_case_cap = 256;
		}

		$runs = WP_MCP_AI_Harness_Trace_Store::list_runs( $assistant_id, $max_runs );
		if ( empty( $runs ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_trace_runs',
				__( 'No harness trace runs were found for this assistant. Enable trace capture on the assistant\'s harness profile first.', 'mcp-ai-wpoos-pro' )
			);
		}

		$rows                 = array();
		$skipped_no_user_input = 0;
		$skipped_no_tool_calls = 0;
		$skipped_too_large    = 0;
		$skipped_bad_artifact = 0;
		$preview_row          = '';
		$walked_runs          = 0;

		foreach ( $runs as $run ) {
			if ( count( $rows ) >= $max_cases ) {
				break;
			}
			$run_id = isset( $run['run_id'] ) ? (string) $run['run_id'] : '';
			if ( '' === $run_id ) {
				++$skipped_bad_artifact;
				continue;
			}
			++$walked_runs;

			$messages = $this->build_messages( $run, $run_id, $assistant_id, $system_prompt );
			if ( is_wp_error( $messages ) ) {
				if ( 'wp_mcp_ai_trajectory_no_user_input' === $messages->get_error_code() ) {
					++$skipped_no_user_input;
				} elseif ( 'wp_mcp_ai_trajectory_no_tool_calls' === $messages->get_error_code() ) {
					++$skipped_no_tool_calls;
				} else {
					++$skipped_bad_artifact;
				}
				continue;
			}

			$row = array( 'messages' => $messages );
			if ( strlen( (string) wp_json_encode( $row ) ) > $per_case_cap ) {
				++$skipped_too_large;
				continue;
			}

			$rows[] = $row;
			if ( '' === $preview_row ) {
				$preview_row = (string) wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}
		}

		if ( empty( $rows ) ) {
			return new WP_Error(
				'wp_mcp_ai_trajectory_corpus_empty',
				__( 'No trajectory rows could be built. Review the skipped_* counts — most commonly no run has a retrievable user prompt.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( $dry_run ) {
			return array(
				'success'              => true,
				'dry_run'              => true,
				'assistant_id'         => $assistant_id,
				'schema'               => 'trajectory_v1',
				'walked_runs'          => $walked_runs,
				'rows'                 => count( $rows ),
				'skipped_no_user_input' => $skipped_no_user_input,
				'skipped_no_tool_calls' => $skipped_no_tool_calls,
				'skipped_too_large'    => $skipped_too_large,
				'skipped_bad_artifact' => $skipped_bad_artifact,
				'preview'              => $preview_row,
			);
		}

		$result = $governance->build(
			$assistant_id,
			'trajectory_v1',
			$rows,
			array(
				'semantic'        => $semantic,
				'provenance_tool' => $this->get_slug(),
				'sources'         => array(
					array(
						'kind'      => 'harness_trace_store',
						'runs_walked' => $walked_runs,
					),
				),
				'max_rows'        => $max_cases,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['skipped_no_user_input'] = $skipped_no_user_input;
		$result['skipped_no_tool_calls'] = $skipped_no_tool_calls;
		$result['skipped_too_large']     = $skipped_too_large;
		$result['skipped_bad_artifact']  = $skipped_bad_artifact;
		$result['walked_runs']           = $walked_runs;
		$result['preview']               = $preview_row;

		return $result;
	}

	/**
	 * Build the tool-calling message sequence for one trace run.
	 *
	 * @param array  $run            Run meta array.
	 * @param string $run_id         Run ID.
	 * @param int    $assistant_id   Assistant post ID.
	 * @param string $system_prompt  Resolved system prompt (may be empty).
	 * @return array<int,array<string,mixed>>|WP_Error Message list or skip error.
	 */
	private function build_messages( array $run, $run_id, $assistant_id, $system_prompt ) {
		$user_prompt = $this->resolve_user_prompt( $run, $run_id, $assistant_id );
		if ( '' === $user_prompt ) {
			return new WP_Error( 'wp_mcp_ai_trajectory_no_user_input', __( 'Run has no retrievable user prompt.', 'mcp-ai-wpoos-pro' ) );
		}

		$records = WP_MCP_AI_Harness_Trace_Store::read_artifact( $run_id, 'tool_calls.jsonl', $assistant_id );
		if ( ! is_array( $records ) || empty( $records ) ) {
			return new WP_Error( 'wp_mcp_ai_trajectory_no_tool_calls', __( 'Run recorded no tool calls.', 'mcp-ai-wpoos-pro' ) );
		}

		$messages = array();
		if ( '' !== $system_prompt ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => $system_prompt,
			);
		}
		$messages[] = array(
			'role'    => 'user',
			'content' => $user_prompt,
		);

		$call_id_prefix = 'mf_' . substr( md5( $run_id ), 0, 8 );

		$tool_calls = array();
		$tool_msgs  = array();
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) || empty( $record['slug'] ) ) {
				continue;
			}
			$seq          = isset( $record['seq'] ) ? (int) $record['seq'] : count( $tool_calls ) + 1;
			$call_id      = $call_id_prefix . '_' . $seq;
			$args_summary = isset( $record['args_summary'] ) && is_string( $record['args_summary'] ) ? $record['args_summary'] : '{}';

			$tool_calls[] = array(
				'id'       => $call_id,
				'type'     => 'function',
				'function' => array(
					'name'      => (string) $record['slug'],
					'arguments' => $args_summary,
				),
			);

			$success = ! empty( $record['result_success'] );
			$summary = isset( $record['result_summary'] ) ? (string) $record['result_summary'] : '';
			$tool_msgs[] = array(
				'role'         => 'tool',
				'tool_call_id' => $call_id,
				'content'      => ( $success ? 'success: ' : 'error: ' ) . $summary,
			);
		}

		if ( empty( $tool_calls ) ) {
			return new WP_Error( 'wp_mcp_ai_trajectory_no_tool_calls', __( 'Run recorded no usable tool calls.', 'mcp-ai-wpoos-pro' ) );
		}

		$messages[] = array(
			'role'      => 'assistant',
			'content'   => null,
			'tool_calls' => $tool_calls,
		);
		foreach ( $tool_msgs as $tool_msg ) {
			$messages[] = $tool_msg;
		}

		$final = WP_MCP_AI_Harness_Trace_Store::read_artifact( $run_id, 'model_response.txt', $assistant_id );
		if ( is_string( $final ) && '' !== trim( $final ) ) {
			$messages[] = array(
				'role'    => 'assistant',
				'content' => trim( $final ),
			);
		}

		return $messages;
	}

	/**
	 * Resolve the user prompt for a run: the retrieval query when present,
	 * otherwise the filter seam.
	 *
	 * @param array  $run          Run meta array.
	 * @param string $run_id       Run ID.
	 * @param int    $assistant_id Assistant post ID.
	 * @return string Prompt or empty string.
	 */
	private function resolve_user_prompt( array $run, $run_id, $assistant_id ) {
		$retrieval = WP_MCP_AI_Harness_Trace_Store::read_artifact( $run_id, 'retrieval.json', $assistant_id );
		if ( is_array( $retrieval ) && isset( $retrieval['query'] ) && is_string( $retrieval['query'] ) && '' !== trim( $retrieval['query'] ) ) {
			return trim( $retrieval['query'] );
		}

		/**
		 * Filter: supply the user prompt for a trace run that recorded no
		 * retrieval query (e.g. a future base-plugin prompt artifact).
		 *
		 * @param string $prompt       Prompt (empty by default).
		 * @param array  $run          Run meta array.
		 * @param string $run_id       Run ID.
		 * @param int    $assistant_id Assistant post ID.
		 */
		$prompt = apply_filters( 'wp_mcp_ai_model_foundry_run_user_prompt', '', $run, $run_id, $assistant_id );
		return is_string( $prompt ) ? trim( $prompt ) : '';
	}

	/**
	 * Resolve the assistant's default system prompt.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return string
	 */
	private function resolve_default_system_prompt( $assistant_id ) {
		$prompt = get_post_meta( $assistant_id, '_wp_mcp_ai_assistant_instructions', true );
		if ( ! is_string( $prompt ) ) {
			return '';
		}
		return trim( wp_strip_all_tags( $prompt ) );
	}
}
