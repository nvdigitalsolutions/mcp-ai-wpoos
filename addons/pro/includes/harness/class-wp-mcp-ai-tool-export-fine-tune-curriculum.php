<?php
/**
 * Layer H — Fine-tune curriculum exporter (Pro).
 *
 * Exports an assistant's selected harness eval suites as a JSONL file in
 * OpenAI's chat-format fine-tune corpus shape. Each row encodes a single
 * eval case as a 3-message conversation:
 *
 *     {"messages":[
 *       {"role":"system",   "content": <system prompt>},
 *       {"role":"user",     "content": <case input>},
 *       {"role":"assistant","content": <case expected>}
 *     ]}
 *
 * Source of truth for the corpus is the case bodies registered with
 * `WP_MCP_AI_Eval_Suite_Registry`. The exporter does **not** run the suite
 * — running tooling against a live model is the harness scheduler's job
 * (Layer G). Layer H simply distils the curriculum the assistant has been
 * pinned to via its `harness_profile.evals_enabled` set into a portable
 * SFT corpus that an external trainer / fine-tune job can consume.
 *
 * Inputs and expecteds that aren't already strings are JSON-encoded,
 * which keeps the row well-formed without losing structure for verifiers
 * that operate on arrays/objects. Empty input/expected structures carry no
 * learnable content and are skipped (skipped_no_input / skipped_no_expect).
 *
 * @package WP_MCP_AI_Pro
 * @since   1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export an assistant's curriculum (selected eval suites) as fine-tune JSONL.
 */
class WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Hard cap on the number of rows emitted in a single export. Keeps
	 * memory and disk bounded; admins who need more can chunk by suite.
	 */
	const HARD_MAX_CASES = 5000;

	/**
	 * Default per-case payload size cap in characters (input + expected).
	 * Cases exceeding this are skipped with a `skipped_too_large` reason
	 * so a single bloated fixture can't blow up the corpus.
	 */
	const DEFAULT_PER_CASE_CHAR_CAP = 16000;

	/**
	 * Relative subdirectory under wp-content/uploads/ where exports land.
	 */
	const EXPORT_SUBDIR = 'mcp-ai/harness-curriculum/';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'export_fine_tune_curriculum';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Export Fine-Tune Curriculum (Harness Layer H)', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Distil an assistant\'s harness eval suites (Layer G `evals_enabled`) into an OpenAI-compatible chat-format JSONL fine-tune corpus. One row per eval case, encoded as a 3-message conversation (system / user input / assistant expected). Inputs and expecteds that are not strings are JSON-encoded. Use `dry_run` to preview row counts before writing.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'assistant_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Assistant CPT post ID to export curriculum for.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'suite_slugs'   => array(
					'type'        => 'array',
					'description' => __( 'Optional explicit list of eval suite slugs. Defaults to the assistant\'s harness_profile.evals_enabled.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'system_prompt' => array(
					'type'        => 'string',
					'description' => __( 'Optional system message used for every row. Defaults to the assistant\'s system prompt.', 'mcp-ai-wpoos-pro' ),
				),
				'format'        => array(
					'type'        => 'string',
					'enum'        => array( 'openai_chat_jsonl' ),
					'description' => __( 'Output format. Currently only OpenAI chat-format JSONL is supported.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'openai_chat_jsonl',
				),
				'max_cases'     => array(
					'type'        => 'integer',
					'description' => __( 'Cap on emitted rows. Hard ceiling 5000.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => self::HARD_MAX_CASES,
				),
				'dry_run'       => array(
					'type'        => 'boolean',
					'description' => __( 'When true, returns counts and a preview of the first row without writing any file.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'   => array( 'assistant_id' ),
		);
	}

	/**
	 * Required capability — admin-only because writes a JSONL file to
	 * wp-content/uploads/.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * Whether this tool requires the Pro addon.
	 *
	 * @return bool
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
	 * Execute the fine-tune curriculum export.
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

		if ( ! class_exists( 'WP_MCP_AI_Eval_Suite_Registry' ) || ! class_exists( 'WP_MCP_AI_Harness_Profile' ) ) {
			return new WP_Error(
				'wp_mcp_ai_harness_unavailable',
				__( 'Harness subsystem is unavailable. Ensure the base plugin is active.', 'mcp-ai-wpoos-pro' )
			);
		}

		$assistant_id = isset( $arguments['assistant_id'] ) ? absint( $arguments['assistant_id'] ) : 0;
		if ( $assistant_id <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_invalid_assistant', __( 'A valid assistant_id is required.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( get_post_type( $assistant_id ) !== 'mcp_ai_assistant' ) {
			return new WP_Error( 'wp_mcp_ai_unknown_assistant', __( 'Assistant not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$profile = WP_MCP_AI_Harness_Profile::get( $assistant_id );

		// Resolve suite slugs: explicit arg wins, fall back to profile.
		$suite_slugs = array();
		if ( ! empty( $arguments['suite_slugs'] ) && is_array( $arguments['suite_slugs'] ) ) {
			foreach ( $arguments['suite_slugs'] as $slug ) {
				$slug = sanitize_key( (string) $slug );
				if ( '' !== $slug ) {
					$suite_slugs[] = $slug;
				}
			}
		} elseif ( isset( $profile['evals_enabled'] ) && is_array( $profile['evals_enabled'] ) ) {
			foreach ( $profile['evals_enabled'] as $slug ) {
				$slug = sanitize_key( (string) $slug );
				if ( '' !== $slug ) {
					$suite_slugs[] = $slug;
				}
			}
		}
		$suite_slugs = array_values( array_unique( $suite_slugs ) );

		if ( empty( $suite_slugs ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_suites_selected',
				__( 'No eval suites selected for this assistant. Enable suites in the harness profile (Layer G) or pass `suite_slugs` explicitly.', 'mcp-ai-wpoos-pro' )
			);
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

		$dry_run = ! empty( $arguments['dry_run'] );

		// Walk suites and build JSONL rows.
		$registry          = WP_MCP_AI_Eval_Suite_Registry::get_instance();
		$rows              = array();
		$skipped_no_input  = 0;
		$skipped_no_expect = 0;
		$skipped_too_large = 0;
		$missing_suites    = array();
		$per_suite_counts  = array();
		$preview_row       = '';

		$per_case_cap = (int) apply_filters(
			'wp_mcp_ai_pro_curriculum_per_case_char_cap',
			self::DEFAULT_PER_CASE_CHAR_CAP,
			$assistant_id
		);
		if ( $per_case_cap < 256 ) {
			$per_case_cap = 256;
		}

		foreach ( $suite_slugs as $suite_slug ) {
			$suite = $registry->get( $suite_slug );
			if ( ! $suite instanceof WP_MCP_AI_Eval_Suite ) {
				$missing_suites[] = $suite_slug;
				continue;
			}

			$suite_count = 0;
			foreach ( $suite->get_cases() as $case ) {
				if ( count( $rows ) >= $max_cases ) {
					break 2;
				}

				$user_content      = $this->stringify_payload( $case->get_input() );
				$assistant_content = $this->stringify_payload( $case->get_expected() );

				if ( '' === $user_content ) {
					++$skipped_no_input;
					continue;
				}
				if ( '' === $assistant_content ) {
					++$skipped_no_expect;
					continue;
				}
				if ( ( strlen( $user_content ) + strlen( $assistant_content ) ) > $per_case_cap ) {
					++$skipped_too_large;
					continue;
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
					'content' => $user_content,
				);
				$messages[] = array(
					'role'    => 'assistant',
					'content' => $assistant_content,
				);

				$encoded = wp_json_encode( array( 'messages' => $messages ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( false === $encoded ) {
					++$skipped_too_large;
					continue;
				}

				$rows[] = $encoded;
				++$suite_count;
				if ( '' === $preview_row ) {
					$preview_row = $encoded;
				}
			}

			$per_suite_counts[ $suite_slug ] = $suite_count;
		}

		$result = array(
			'success'           => true,
			'assistant_id'      => $assistant_id,
			'suite_slugs'       => $suite_slugs,
			'missing_suites'    => $missing_suites,
			'rows'              => count( $rows ),
			'per_suite_counts'  => $per_suite_counts,
			'skipped_no_input'  => $skipped_no_input,
			'skipped_no_expect' => $skipped_no_expect,
			'skipped_too_large' => $skipped_too_large,
			'format'            => 'openai_chat_jsonl',
			'preview'           => $preview_row,
		);

		if ( $dry_run || empty( $rows ) ) {
			$result['dry_run'] = true;
			if ( empty( $rows ) ) {
				$result['message'] = __( 'No rows would be written. Check missing_suites and skipped_* counts.', 'mcp-ai-wpoos-pro' );
			}
			return $result;
		}

		$write = $this->write_jsonl( $assistant_id, $rows );
		if ( is_wp_error( $write ) ) {
			return $write;
		}

		$result['file_path']  = $write['path'];
		$result['file_url']   = $write['url'];
		$result['file_bytes'] = $write['bytes'];
		$result['filename']   = $write['filename'];
		$result['dry_run']    = false;
		$result['message']    = sprintf(
			/* translators: 1: row count, 2: file path */
			__( 'Wrote %1$d curriculum rows to %2$s.', 'mcp-ai-wpoos-pro' ),
			count( $rows ),
			$write['path']
		);

		return $result;
	}

	/**
	 * Coerce a case input/expected into a non-empty user-visible string.
	 * Arrays / objects are JSON-encoded so verifiers that operate on
	 * structured data still produce a well-formed JSONL row.
	 *
	 * @param mixed $value Case payload.
	 * @return string Coerced string, or empty string if it cannot be encoded.
	 */
	private function stringify_payload( $value ) {
		if ( is_string( $value ) ) {
			return trim( $value );
		}
		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
			return (string) $value;
		}
		if ( null === $value ) {
			return '';
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			if ( is_array( $value ) && empty( $value ) ) {
				// An empty input structure carries no learnable content — skip
				// it instead of emitting a "[]" row into the fine-tune corpus.
				return '';
			}
			$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			return is_string( $encoded ) ? $encoded : '';
		}
		return '';
	}

	/**
	 * Resolve the assistant's default system prompt.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return string System prompt or empty string.
	 */
	private function resolve_default_system_prompt( $assistant_id ) {
		$prompt = get_post_meta( $assistant_id, '_wp_mcp_ai_assistant_instructions', true );
		if ( ! is_string( $prompt ) ) {
			$prompt = '';
		}
		return trim( wp_strip_all_tags( $prompt ) );
	}

	/**
	 * Persist the rendered JSONL to a guarded plugin uploads subdirectory.
	 *
	 * @param int      $assistant_id Assistant post ID.
	 * @param string[] $rows         JSONL rows (already encoded).
	 * @return array{path:string,url:string,bytes:int,filename:string}|WP_Error
	 */
	private function write_jsonl( $assistant_id, array $rows ) {
		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) ) {
			return new WP_Error( 'wp_mcp_ai_no_upload_dir', __( 'wp-content/uploads is not writable.', 'mcp-ai-wpoos-pro' ) );
		}

		$dir = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) ) . self::EXPORT_SUBDIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Upload dir writability check; WP_Filesystem is not loaded in this tool execution context.
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return new WP_Error( 'wp_mcp_ai_export_dir_unwritable', __( 'Curriculum export directory is not writable.', 'mcp-ai-wpoos-pro' ) );
		}

		// Web-server guards. Match the pattern used by the assistant CLI exporter.
		$htaccess = $dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing web-server deny rule to plugin uploads subdir.
			file_put_contents( $htaccess, "Deny from all\n" );
		}
		$index = $dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing directory listing guard.
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		$filename = sprintf(
			'curriculum-assistant-%d-%s.jsonl',
			$assistant_id,
			gmdate( 'Ymd-His' )
		);
		$filename = sanitize_file_name( $filename );
		$path     = $dir . $filename;

		$payload = implode( "\n", $rows ) . "\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing JSONL corpus to a guarded uploads subdir.
		$bytes = file_put_contents( $path, $payload );
		if ( false === $bytes ) {
			return new WP_Error( 'wp_mcp_ai_curriculum_write_failed', __( 'Failed to write curriculum file.', 'mcp-ai-wpoos-pro' ) );
		}

		$base_url = isset( $upload_dir['baseurl'] ) ? trailingslashit( $upload_dir['baseurl'] ) : '';
		$url      = '' !== $base_url ? $base_url . self::EXPORT_SUBDIR . $filename : '';

		return array(
			'path'     => $path,
			'url'      => $url,
			'bytes'    => (int) $bytes,
			'filename' => $filename,
		);
	}
}
