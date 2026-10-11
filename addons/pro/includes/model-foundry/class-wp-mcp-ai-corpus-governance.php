<?php
/**
 * Corpus governance engine — Model Foundry (Pro, Phase 1).
 *
 * The single owner of the Corpus Foundry pipeline: consent gate → schema
 * validation → row cap → dedup → PII scrub → deterministic holdout split →
 * R12 provenance manifest → guarded sharded write. Exporters build rows;
 * this class decides what is trustworthy enough to ship and records why.
 *
 * Output layout (guarded uploads dir):
 *
 *     wp-content/uploads/mcp-ai/model-foundry/<assistant_id>/<corpus_id>/
 *         train.jsonl       — training rows (never contains holdout rows)
 *         holdout.jsonl     — holdout rows (when the split is enabled)
 *         manifest.json     — provenance + stats (R12 fields)
 *
 * Decontamination (R7): the holdout split is a content-hash modulo computed
 * *after* dedup, so a row is deterministically train-or-holdout forever and
 * a duplicate can never straddle the split.
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
 * Corpus governance engine.
 */
class WP_MCP_AI_Corpus_Governance {

	/**
	 * Post-meta key granting training consent per assistant. Value '1'
	 * means consented; anything else (including absence) is denied.
	 */
	const CONSENT_META = '_wp_mcp_ai_training_consent';

	/**
	 * Relative uploads subdirectory for corpus output.
	 */
	const EXPORT_SUBDIR = 'mcp-ai/model-foundry/';

	/**
	 * Hard row ceiling for a single corpus build.
	 */
	const HARD_MAX_ROWS = 5000;

	/**
	 * Default holdout ratio (20% — R7).
	 */
	const DEFAULT_HOLDOUT_RATIO = 0.2;

	/**
	 * Holdout modulus: a row whose content hash modulo this value is zero
	 * lands in the holdout shard.
	 */
	const HOLDOUT_MODULUS = 5;

	/**
	 * Assert the assistant has granted training consent. Fails closed.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return true|WP_Error True when consented, WP_Error otherwise.
	 */
	public function assert_consent( $assistant_id ) {
		$assistant_id = (int) $assistant_id;

		/**
		 * Filter: override the per-assistant consent gate. Return true to
		 * consent the assistant regardless of meta, false to deny.
		 *
		 * @param bool|null $override     Null = use the meta gate.
		 * @param int       $assistant_id Assistant post ID.
		 */
		$override = apply_filters( 'wp_mcp_ai_model_foundry_assistant_consented', null, $assistant_id );
		if ( null !== $override ) {
			return $override ? true : new WP_Error(
				'wp_mcp_ai_training_consent_required',
				__( 'Training consent has not been granted for this assistant (filter override denied it).', 'mcp-ai-wpoos-pro' )
			);
		}

		$meta = get_post_meta( $assistant_id, self::CONSENT_META, true );
		if ( '1' === (string) $meta ) {
			return true;
		}

		return new WP_Error(
			'wp_mcp_ai_training_consent_required',
			__( 'Training consent has not been granted for this assistant. Set the training-consent meta before exporting a corpus.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Run the full corpus pipeline and write the sharded output.
	 *
	 * @param int                            $assistant_id Assistant post ID.
	 * @param string                         $schema_key   Corpus schema key.
	 * @param array<int,array<string,mixed>> $rows         Row payloads.
	 * @param array<string,mixed>            $options      {
	 *          Optional.
	 *
	 *     @type bool   $semantic         Enable semantic dedup. Default false.
	 *     @type bool   $holdout_split    Enable the holdout split. Default true.
	 *     @type float  $holdout_ratio    Holdout ratio (0–1). Default 0.2.
	 *     @type string $provenance_tool  Tool slug recorded in the manifest.
	 *     @type array  $sources          Free-form source description array.
	 *     @type int    $max_rows         Row ceiling (≤ HARD_MAX_ROWS).
	 * }
	 * @return array<string,mixed>|WP_Error Envelope or error.
	 */
	public function build( $assistant_id, $schema_key, array $rows, array $options = array() ) {
		$consent = $this->assert_consent( $assistant_id );
		if ( is_wp_error( $consent ) ) {
			return $consent;
		}

		$schema = WP_MCP_AI_Corpus_Schema_Registry::get( $schema_key );
		if ( null === $schema ) {
			return new WP_Error(
				'wp_mcp_ai_unknown_corpus_schema',
				/* translators: %s: schema key */
				sprintf( __( 'Unknown corpus schema "%s".', 'mcp-ai-wpoos-pro' ), sanitize_key( (string) $schema_key ) )
			);
		}

		// Row cap (hard ceiling).
		$input_rows = count( $rows );
		$max_rows   = isset( $options['max_rows'] ) ? (int) $options['max_rows'] : self::HARD_MAX_ROWS;
		if ( $max_rows <= 0 || $max_rows > self::HARD_MAX_ROWS ) {
			$max_rows = self::HARD_MAX_ROWS;
		}
		$truncated = 0;
		if ( $input_rows > $max_rows ) {
			$truncated = $input_rows - $max_rows;
			$rows      = array_slice( $rows, 0, $max_rows );
		}

		// Schema validation (before any write; invalid rows are skipped).
		$valid_rows          = array();
		$skipped_invalid_row = 0;
		foreach ( $rows as $row ) {
			$valid = WP_MCP_AI_Corpus_Schema_Registry::validate_row( $schema_key, $row );
			if ( true === $valid ) {
				$valid_rows[] = $row;
			} else {
				++$skipped_invalid_row;
			}
		}

		// Dedup.
		$deduper = new WP_MCP_AI_Corpus_Deduper();
		$deduped = $deduper->dedup(
			$valid_rows,
			array(
				'semantic'          => ! empty( $options['semantic'] ),
				'semantic_max_rows' => isset( $options['semantic_max_rows'] ) ? (int) $options['semantic_max_rows'] : WP_MCP_AI_Corpus_Deduper::DEFAULT_SEMANTIC_MAX_ROWS,
				'semantic_threshold' => isset( $options['semantic_threshold'] ) ? (float) $options['semantic_threshold'] : WP_MCP_AI_Corpus_Deduper::DEFAULT_SEMANTIC_THRESHOLD,
			)
		);

		// PII scrub.
		$pii      = new WP_MCP_AI_Corpus_Pii();
		$scrubbed = $pii->scrub_rows( $deduped['rows'] );

		// Deterministic holdout split (content-hash modulo).
		$holdout_split = true;
		if ( array_key_exists( 'holdout_split', $options ) ) {
			$holdout_split = (bool) $options['holdout_split'];
		}
		$holdout_ratio = isset( $options['holdout_ratio'] ) ? (float) $options['holdout_ratio'] : self::DEFAULT_HOLDOUT_RATIO;
		if ( $holdout_ratio < 0.0 || $holdout_ratio >= 1.0 ) {
			$holdout_ratio = self::DEFAULT_HOLDOUT_RATIO;
		}
		if ( $holdout_ratio <= 0.0 ) {
			$holdout_split = false;
		}

		$train_rows   = array();
		$holdout_rows = array();
		foreach ( $scrubbed['rows'] as $row ) {
			$hash = md5( (string) wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			if ( $holdout_split && ( (int) hexdec( substr( $hash, 0, 8 ) ) % self::HOLDOUT_MODULUS ) === 0 ) {
				$holdout_rows[] = $row;
			} else {
				$train_rows[] = $row;
			}
		}

		if ( empty( $train_rows ) && empty( $holdout_rows ) ) {
			return new WP_Error(
				'wp_mcp_ai_corpus_empty',
				__( 'No rows survived the corpus pipeline. Check the skip and dedup counts before retrying.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Manifest (R12 fields).
		$corpus_id = sprintf( '%s-%s', sanitize_key( (string) $schema_key ), gmdate( 'Ymd-His' ) );
		$manifest  = array(
			'schema'         => sanitize_key( (string) $schema_key ),
			'schema_format'  => (string) $schema['format'],
			'corpus_id'      => $corpus_id,
			'assistant_id'   => (int) $assistant_id,
			'created_at'     => gmdate( 'c' ),
			'created_at_ts'  => time(),
			'dataset_version' => $corpus_id,
			'rows'           => array(
				'input'    => $input_rows,
				'train'    => count( $train_rows ),
				'holdout'  => count( $holdout_rows ),
				'truncated_over_cap' => $truncated,
				'skipped'  => array(
					'skipped_invalid_row' => $skipped_invalid_row,
				),
			),
			'dedup'          => array(
				'by_tier'                 => $deduped['by_tier'],
				'removed'                 => (int) $deduped['removed'],
				'semantic_skipped_reason' => (string) $deduped['semantic_skipped_reason'],
			),
			'pii'            => array(
				'redactions'           => (int) $scrubbed['redactions'],
				'rows_with_redactions' => (int) $scrubbed['rows_with_redactions'],
				'extra_scrub_active'   => (bool) $scrubbed['extra_scrub_active'],
			),
			'holdout'        => array(
				'split_enabled' => $holdout_split,
				'ratio_target'  => $holdout_ratio,
				'method'        => 'content_hash_modulo',
				'modulus'       => self::HOLDOUT_MODULUS,
			),
			'consent'        => array(
				'status'   => 'granted',
				'meta_key' => self::CONSENT_META,
			),
			'sources'        => isset( $options['sources'] ) && is_array( $options['sources'] ) ? $options['sources'] : array(),
			'shards'         => array(),
			'retention'      => array(
				'policy' => 'site-controlled',
				'note'   => __( 'Corpora live in the guarded uploads directory until the site owner removes them.', 'mcp-ai-wpoos-pro' ),
			),
			'provenance'     => array(
				'tool'                  => isset( $options['provenance_tool'] ) ? sanitize_key( (string) $options['provenance_tool'] ) : 'unknown',
				'arguments_fingerprint' => hash(
					'sha256',
					(string) wp_json_encode(
						array(
							'assistant_id' => (int) $assistant_id,
							'schema' => sanitize_key( (string) $schema_key ),
						)
					)
				),
			),
		);

		// Encode shards.
		$train_jsonl   = $this->encode_jsonl( $train_rows );
		$holdout_jsonl = $this->encode_jsonl( $holdout_rows );
		if ( is_wp_error( $train_jsonl ) ) {
			return $train_jsonl;
		}
		if ( is_wp_error( $holdout_jsonl ) ) {
			return $holdout_jsonl;
		}

		// Write.
		$write = $this->write_corpus( (int) $assistant_id, $corpus_id, $manifest, $train_jsonl, $holdout_jsonl );
		if ( is_wp_error( $write ) ) {
			return $write;
		}

		$manifest = $write['manifest'];

		return array(
			'success'      => true,
			'assistant_id' => (int) $assistant_id,
			'schema'       => sanitize_key( (string) $schema_key ),
			'corpus_id'    => $corpus_id,
			'rows'         => $manifest['rows'],
			'dedup'        => $manifest['dedup'],
			'pii'          => $manifest['pii'],
			'holdout'      => $manifest['holdout'],
			'files'        => $write['files'],
			'manifest'     => $manifest,
		);
	}

	/**
	 * Encode rows as JSONL payload.
	 *
	 * @param array<int,array<string,mixed>> $rows Row payloads.
	 * @return string|WP_Error JSONL string or error.
	 */
	private function encode_jsonl( array $rows ) {
		$lines = array();
		foreach ( $rows as $row ) {
			$encoded = wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( false === $encoded ) {
				return new WP_Error( 'wp_mcp_ai_corpus_encode_failed', __( 'A corpus row could not be JSON-encoded.', 'mcp-ai-wpoos-pro' ) );
			}
			$lines[] = $encoded;
		}
		return '' === implode( '', $lines ) ? '' : implode( "\n", $lines ) . "\n";
	}

	/**
	 * Write shards + manifest into the guarded corpus directory.
	 *
	 * @param int    $assistant_id  Assistant post ID.
	 * @param string $corpus_id     Corpus ID (already sanitized).
	 * @param array  $manifest      Manifest payload — `shards` is populated here by reference before the manifest file is written.
	 * @param string $train_jsonl   Encoded train shard.
	 * @param string $holdout_jsonl Encoded holdout shard (may be empty).
	 * @return array{files:array<int,array{name:string,path:string,url:string,bytes:int}>,manifest:array<string,mixed>}|WP_Error
	 */
	private function write_corpus( $assistant_id, $corpus_id, array &$manifest, $train_jsonl, $holdout_jsonl ) {
		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) ) {
			return new WP_Error( 'wp_mcp_ai_no_upload_dir', __( 'wp-content/uploads is not writable.', 'mcp-ai-wpoos-pro' ) );
		}

		$dir = trailingslashit( (string) $upload_dir['basedir'] ) . self::EXPORT_SUBDIR . $assistant_id . '/' . $corpus_id . '/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Upload dir writability check; WP_Filesystem is not loaded in tool context.
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return new WP_Error( 'wp_mcp_ai_export_dir_unwritable', __( 'Corpus export directory is not writable.', 'mcp-ai-wpoos-pro' ) );
		}

		// Web-server guards (Layer-H pattern).
		if ( ! file_exists( $dir . '.htaccess' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Guard file write.
			file_put_contents( $dir . '.htaccess', "Deny from all\n" );
		}
		if ( ! file_exists( $dir . 'index.php' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Guard file write.
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
		}

		$files = array();
		$shards = array();
		$baseurl = isset( $upload_dir['baseurl'] ) ? trailingslashit( (string) $upload_dir['baseurl'] ) : '';

		$shard_defs = array(
			array(
				'name' => 'train.jsonl',
				'payload' => $train_jsonl,
				'rows' => substr_count( $train_jsonl, "\n" ),
			),
			array(
				'name' => 'holdout.jsonl',
				'payload' => $holdout_jsonl,
				'rows' => substr_count( $holdout_jsonl, "\n" ),
			),
		);

		foreach ( $shard_defs as $def ) {
			if ( '' === $def['payload'] ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Corpus shard write to guarded dir.
			$bytes = file_put_contents( $dir . $def['name'], $def['payload'] );
			if ( false === $bytes ) {
				return new WP_Error( 'wp_mcp_ai_corpus_write_failed', __( 'Failed to write corpus shard.', 'mcp-ai-wpoos-pro' ) );
			}
			$files[] = array(
				'name'  => $def['name'],
				'path'  => $dir . $def['name'],
				'url'   => '' !== $baseurl ? $baseurl . self::EXPORT_SUBDIR . $assistant_id . '/' . $corpus_id . '/' . $def['name'] : '',
				'bytes' => (int) $bytes,
			);
			$shards[] = array(
				'file'   => $def['name'],
				'rows'   => (int) $def['rows'],
				'sha256' => hash( 'sha256', $def['payload'] ),
			);
		}

		// Manifest last so its presence signals a complete corpus. Shards are
		// attached before encoding so the file and the envelope agree.
		$manifest['shards'] = $shards;
		$manifest_json      = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $manifest_json ) {
			return new WP_Error( 'wp_mcp_ai_manifest_encode_failed', __( 'The provenance manifest could not be encoded.', 'mcp-ai-wpoos-pro' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Manifest write to guarded dir.
		$bytes = file_put_contents( $dir . 'manifest.json', $manifest_json );
		if ( false === $bytes ) {
			return new WP_Error( 'wp_mcp_ai_manifest_write_failed', __( 'Failed to write the provenance manifest.', 'mcp-ai-wpoos-pro' ) );
		}
		$files[] = array(
			'name'  => 'manifest.json',
			'path'  => $dir . 'manifest.json',
			'url'   => '' !== $baseurl ? $baseurl . self::EXPORT_SUBDIR . $assistant_id . '/' . $corpus_id . '/manifest.json' : '',
			'bytes' => (int) $bytes,
		);

		return array(
			'files'    => $files,
			'manifest' => $manifest,
		);
	}
}
