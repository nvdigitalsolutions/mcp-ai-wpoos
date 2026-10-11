<?php
/**
 * Plugin-docs corpus exporter — Model Foundry (Pro, Phase 1).
 *
 * Distils markdown/text documentation into a `docs_v1` plain-text corpus
 * for domain adaptation / continued SFT:
 *
 *     {"text": <document body>, "meta": {"source":…, "path":…, "hash":…, "bytes":…}}
 *
 * Default source is the Docs Hub uploads content folder
 * (`wp_upload_dir()['basedir'] . '/nvoos-docs-hub/content'`, resolved via
 * `NV_oOS_Docs_Hub_Plugin::uploads_docs_dir()` when the addon is active).
 * An explicit `dir` argument is accepted but must resolve — after
 * `realpath()` — to a location inside the uploads basedir, mirroring the
 * docs-hub symlink-escape hardening. Only `.md`, `.markdown`, and `.txt`
 * files are read; dotfiles and `vendor`/`node_modules`/`.git` directories
 * are skipped.
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
 * Export plugin documentation as a docs corpus.
 */
class WP_MCP_AI_Tool_Export_Plugin_Docs_Corpus implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Data_Contract_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * Hard cap on emitted rows per export.
	 */
	const HARD_MAX_FILES = 1000;

	/**
	 * Default number of documents walked per export.
	 */
	const DEFAULT_MAX_FILES = 500;

	/**
	 * Default per-document size cap in characters.
	 */
	const DEFAULT_PER_FILE_CHAR_CAP = 100000;

	/**
	 * Allowed document extensions.
	 */
	const ALLOWED_EXTENSIONS = array( 'md', 'markdown', 'txt' );

	/**
	 * Directory names always skipped during scanning.
	 */
	const EXCLUDED_DIRS = array( 'vendor', 'node_modules', '.git', '.svn' );

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'export_plugin_docs_corpus';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Export Plugin Docs Corpus (Model Foundry)', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Distil the site\'s documentation (docs-hub content folder by default, or a validated directory inside uploads) into a trainer-agnostic plain-text corpus (docs_v1) for domain adaptation. Requires the assistant\'s training consent. Use dry_run to preview counts before writing.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Building a domain-adaptation corpus from plugin wikis and docs markdown for continued fine-tuning.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Exporting behaviour data; use export_trajectory_corpus or export_fine_tune_curriculum. Exporting DPO data; use export_preference_pairs.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'export_fine_tune_curriculum', 'export_trajectory_corpus', 'export_preference_pairs' ),
			'notes'           => __( 'Only .md/.markdown/.txt files are read; the explicit dir argument must resolve inside the uploads basedir (symlink-safe). Output lands under wp-content/uploads/mcp-ai/model-foundry/. Requires manage_options and training consent.', 'mcp-ai-wpoos-pro' ),
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
					'description' => __( 'Assistant CPT post ID the corpus is exported for (consent + output namespace).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'dir'              => array(
					'type'        => 'string',
					'description' => __( 'Optional absolute directory to scan. Defaults to the docs-hub content folder. Must resolve inside the uploads basedir.', 'mcp-ai-wpoos-pro' ),
				),
				'max_files'        => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of documents to walk. Default 500, hard ceiling 1000.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => self::HARD_MAX_FILES,
					'default'     => self::DEFAULT_MAX_FILES,
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
			'produces' => 'docs_v1',
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
	 * Execute the plugin-docs corpus export.
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

		// Consent fails closed before any document is read.
		$governance = new WP_MCP_AI_Corpus_Governance();
		$consent    = $governance->assert_consent( $assistant_id );
		if ( is_wp_error( $consent ) ) {
			return $consent;
		}

		$dir = $this->resolve_scan_dir( $arguments );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$max_files = isset( $arguments['max_files'] ) ? absint( $arguments['max_files'] ) : self::DEFAULT_MAX_FILES;
		if ( $max_files <= 0 || $max_files > self::HARD_MAX_FILES ) {
			$max_files = self::DEFAULT_MAX_FILES;
		}

		$dry_run  = ! empty( $arguments['dry_run'] );
		$semantic = ! empty( $arguments['semantic_dedup'] );

		$per_file_cap = (int) apply_filters(
			'wp_mcp_ai_model_foundry_docs_per_file_char_cap',
			self::DEFAULT_PER_FILE_CHAR_CAP,
			$assistant_id
		);
		if ( $per_file_cap < 1024 ) {
			$per_file_cap = 1024;
		}

		$rows               = array();
		$skipped_too_large  = 0;
		$skipped_unreadable = 0;
		$preview_row        = '';

		foreach ( $this->walk_docs( $dir, $max_files ) as $doc ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading corpus source documents from a validated directory.
			$body = file_get_contents( $doc['path'] );
			if ( false === $body ) {
				++$skipped_unreadable;
				continue;
			}
			$body = (string) $body;
			if ( strlen( $body ) > $per_file_cap ) {
				++$skipped_too_large;
				continue;
			}

			$row = array(
				'text' => $body,
				'meta' => array(
					'source' => 'docs-hub',
					'path'   => $doc['relative'],
					'hash'   => hash( 'sha256', $body ),
					'bytes'  => strlen( $body ),
				),
			);
			$rows[] = $row;
			if ( '' === $preview_row ) {
				$preview_row = (string) wp_json_encode(
					array(
						'text' => substr( $body, 0, 400 ),
						'meta' => $row['meta'],
					),
					JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				);
			}
		}

		if ( empty( $rows ) ) {
			return new WP_Error(
				'wp_mcp_ai_docs_corpus_empty',
				__( 'No documents were found in the scanned directory. Drop markdown files into the docs-hub content folder or pass a valid dir.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( $dry_run ) {
			return array(
				'success'            => true,
				'dry_run'            => true,
				'assistant_id'       => $assistant_id,
				'schema'             => 'docs_v1',
				'dir'                => $dir,
				'rows'               => count( $rows ),
				'skipped_too_large'  => $skipped_too_large,
				'skipped_unreadable' => $skipped_unreadable,
				'preview'            => $preview_row,
			);
		}

		$result = $governance->build(
			$assistant_id,
			'docs_v1',
			$rows,
			array(
				'semantic'        => $semantic,
				'provenance_tool' => $this->get_slug(),
				'sources'         => array(
					array(
						'kind' => 'uploads_docs_dir',
						'dir'  => $dir,
					),
				),
				'max_rows'        => $max_files,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['skipped_too_large']  = $skipped_too_large;
		$result['skipped_unreadable'] = $skipped_unreadable;
		$result['preview']            = $preview_row;

		return $result;
	}

	/**
	 * Resolve and validate the scan directory.
	 *
	 * The explicit `dir` argument must realpath to a location inside the
	 * uploads basedir (symlink-escape hardening). The default source is the
	 * docs-hub content folder.
	 *
	 * @param array $arguments Execution arguments.
	 * @return string|WP_Error Normalized absolute path or error.
	 */
	private function resolve_scan_dir( array $arguments ) {
		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) ) {
			return new WP_Error( 'wp_mcp_ai_no_upload_dir', __( 'wp-content/uploads is not available.', 'mcp-ai-wpoos-pro' ) );
		}
		$uploads_root = (string) wp_normalize_path( realpath( (string) $upload_dir['basedir'] ) );

		if ( isset( $arguments['dir'] ) && is_string( $arguments['dir'] ) && '' !== trim( $arguments['dir'] ) ) {
			$candidate = (string) wp_normalize_path( (string) realpath( trim( $arguments['dir'] ) ) );
			if ( '' === $candidate || false === realpath( $candidate ) ) {
				return new WP_Error( 'wp_mcp_ai_docs_dir_not_found', __( 'The requested docs directory does not exist.', 'mcp-ai-wpoos-pro' ) );
			}
			if ( '' !== $uploads_root && 0 !== strpos( $candidate, $uploads_root ) ) {
				return new WP_Error( 'wp_mcp_ai_docs_dir_outside_uploads', __( 'The requested docs directory must reside inside the uploads directory.', 'mcp-ai-wpoos-pro' ) );
			}
			if ( ! is_dir( $candidate ) ) {
				return new WP_Error( 'wp_mcp_ai_docs_dir_not_found', __( 'The requested docs directory is not a directory.', 'mcp-ai-wpoos-pro' ) );
			}
			return $candidate;
		}

		if ( class_exists( 'NV_oOS_Docs_Hub_Plugin' ) && method_exists( 'NV_oOS_Docs_Hub_Plugin', 'uploads_docs_dir' ) ) {
			$default = (string) wp_normalize_path( (string) NV_oOS_Docs_Hub_Plugin::uploads_docs_dir() );
		} else {
			$default = (string) wp_normalize_path( trailingslashit( (string) $upload_dir['basedir'] ) . 'nvoos-docs-hub/content' );
		}

		if ( ! is_dir( $default ) ) {
			return new WP_Error(
				'wp_mcp_ai_docs_dir_not_found',
				__( 'The docs-hub content folder does not exist yet. Install docs content or pass an explicit dir.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $default;
	}

	/**
	 * Yield scannable documents (extension-filtered, symlink-safe, bounded).
	 *
	 * @param string $dir      Validated absolute directory.
	 * @param int    $max_files Maximum files to yield.
	 * @return \Generator<int,array{path:string,relative:string}>
	 */
	private function walk_docs( $dir, $max_files ) {
		$root  = rtrim( (string) wp_normalize_path( (string) realpath( $dir ) ), '/' );
		$count = 0;

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $count >= $max_files ) {
				return;
			}
			if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
				continue;
			}

			$relative = ltrim( substr( (string) wp_normalize_path( $file->getPathname() ), strlen( $root ) ), '/' );

			// Skip excluded directory segments anywhere in the relative path.
			$segments = explode( '/', $relative );
			$excluded = false;
			foreach ( $segments as $segment ) {
				if ( in_array( $segment, self::EXCLUDED_DIRS, true ) ) {
					$excluded = true;
					break;
				}
			}
			if ( $excluded ) {
				continue;
			}

			// Skip dotfiles.
			if ( isset( $relative[0] ) && '.' === $relative[0] ) {
				continue;
			}

			$ext = strtolower( pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, self::ALLOWED_EXTENSIONS, true ) ) {
				continue;
			}

			// Symlink-escape hardening: the real path must stay inside the root.
			$real = (string) wp_normalize_path( (string) realpath( $file->getPathname() ) );
			if ( '' === $real || 0 !== strpos( $real, $root . '/' ) ) {
				continue;
			}

			++$count;
			yield array(
				'path'     => $real,
				'relative' => $relative,
			);
		}
	}
}
