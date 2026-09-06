<?php
/**
 * Conversation import orchestration (Wave E4, sub-cluster 6).
 *
 * Aligned port of the base plugin's
 * `WP_MCP_AI_Conversation_Import_Manager`: byte-identical run
 * lifecycle (source resolution, archive preparation, per-file format
 * detection, adapter extraction, sideload media, dedupe policy
 * skip/refresh, batch processing with the 25/1/200 batch-size clamp,
 * the 200/500 caps, per-batch progress emission, checkpoint
 * persistence via the `wp_mcp_ai_conversation_import_checkpoint`
 * option, resume tokens, bounded error collection, the
 * `wp_mcp_ai_conversation_import_completed` action, inspect and
 * status helpers, and the completion audit log.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Class name/namespace — the platform's PSR-4 tree.
	 *  - Collaborators (`Archive`, `FormatDetector`, `Media`, `CctWriter`,
	 *    `Conversation`) resolve to this package's classes; the per-mode
	 *    seams live inside those classes, so the manager is mode-agnostic.
	 *  - Canonical conversation checks go through
	 *    `is_conversation_instance()` — the per-mode seam — because
	 *    monolith installs flow the base conversation class (base
	 *    adapters) while standalone installs flow this package's class.
 *  - The `WP_MCP_AI_Logger` completion audit is gated on
 *    `defined( 'WP_MCP_AI_PATH' )` — the base logger only exists
 *    monolith, and the platform addon logs through its own services
 *    (dormant standalone).
 *  - `WP_Error` is fully qualified (`\WP_Error`).
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\ConversationImport
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\ConversationImport;

/**
 * Runs conversation imports end to end.
 *
 * @since 2.1.0
 */
class Manager {

	const CHECKPOINT_OPTION = 'wp_mcp_ai_conversation_import_checkpoint';
	const MAX_REPORT_ERRORS = 50;

	const POLICY_SKIP    = 'skip';
	const POLICY_REFRESH = 'refresh';

	/**
	 * CCT writer used for persistence (injectable for tests).
	 *
	 * @var CctWriter|null
	 */
	protected $writer;

	/**
	 * Optional progress callback invoked after each batch.
	 *
	 * Receives an array with `processed`, `estimated_total`, and `totals` keys.
	 *
	 * @var callable|null
	 */
	protected $progress_callback;

	/**
	 * Constructor.
	 *
	 * @param CctWriter|null $writer Optional writer override (tests).
	 */
	public function __construct( $writer = null ) {
		if ( $writer instanceof CctWriter ) {
			$this->writer = $writer;
		}
	}

	/**
	 * Register a progress callback.
	 *
	 * The callback fires after every processed batch with the processed
	 * conversation count, the estimated total (0 when unknown), and the
	 * running totals. Used by the async queue bridge to update job progress.
	 *
	 * @param callable $callback Callback accepting one array argument.
	 * @return void
	 */
	public function set_progress_callback( $callback ) {
		if ( is_callable( $callback ) ) {
			$this->progress_callback = $callback;
		}
	}

	/**
	 * Invoke the progress callback, if registered.
	 *
	 * @param int   $processed       Conversations processed so far.
	 * @param int   $estimated_total Estimated total (0 = unknown).
	 * @param array $report          Report so far.
	 * @return void
	 */
	protected function emit_progress( $processed, $estimated_total, array $report ) {
		if ( null === $this->progress_callback ) {
			return;
		}

		call_user_func(
			$this->progress_callback,
			array(
				'processed'       => (int) $processed,
				'estimated_total' => (int) $estimated_total,
				'totals'          => isset( $report['totals'] ) ? $report['totals'] : array(),
			)
		);
	}

	/**
	 * Resolve the CCT writer instance.
	 *
	 * @return CctWriter
	 */
	protected function get_writer() {
		if ( null === $this->writer ) {
			$this->writer = new CctWriter();
		}

		return $this->writer;
	}

	/**
	 * Import a conversation export.
	 *
	 * @param array $args {
	 *     Import arguments.
	 *
	 *     @type string|int $source       File path (string) or attachment ID (int).
	 *     @type string     $format       Optional adapter slug override (e.g. "chatgpt").
	 *     @type bool       $dry_run      Preview only; no CCT writes. Default false.
	 *     @type string     $policy       "skip" (default) or "refresh" existing rows.
	 *     @type int        $batch_size   Conversations per write batch. Default 25.
	 *     @type int        $limit        Max conversations to process; 0 = all. Default 0.
	 *     @type int        $user_id      Importing WordPress user ID. Default current user.
	 *     @type string     $resume_token Optional checkpoint token to resume from.
	 *     @type int        $estimate     Optional estimated total conversations (progress reporting).
	 *     @type bool       $sideload_media Optional: sideload referenced export images
	 *                                       into the media library before writing.
	 * }
	 * @return array|\WP_Error Report array, or a WP_Error when the run cannot start.
	 */
	public function run( array $args = array() ) {
		$started_at = microtime( true );

		$report = array(
			'status'                => 'running',
			'token'                 => '',
			'dry_run'               => false,
			'policy'                => self::POLICY_SKIP,
			'files'                 => array(),
			'imported_session_keys' => array(),
			'totals'                => array(
				'detected'  => 0,
				'imported'  => 0,
				'updated'   => 0,
				'skipped'   => 0,
				'failed'    => 0,
				'processed' => 0,
			),
			'errors'                => array(),
			'duration_ms'           => 0,
		);

		$dry_run = ! empty( $args['dry_run'] );
		$policy  = isset( $args['policy'] ) ? sanitize_key( (string) $args['policy'] ) : self::POLICY_SKIP;
		if ( ! in_array( $policy, array( self::POLICY_SKIP, self::POLICY_REFRESH ), true ) ) {
			$policy = self::POLICY_SKIP;
		}

		$batch_size = isset( $args['batch_size'] ) ? absint( $args['batch_size'] ) : 25;
		$batch_size = min( max( $batch_size, 1 ), 200 );

		$limit           = isset( $args['limit'] ) ? absint( $args['limit'] ) : 0;
		$user_id         = isset( $args['user_id'] ) ? absint( $args['user_id'] ) : get_current_user_id();
		$estimated_total = isset( $args['estimate'] ) ? absint( $args['estimate'] ) : 0;
		$sideload_media  = ! empty( $args['sideload_media'] );

		$resume_token = isset( $args['resume_token'] ) ? sanitize_text_field( (string) $args['resume_token'] ) : '';
		$resume_state = '' !== $resume_token ? $this->get_checkpoint( $resume_token ) : array();
		if ( '' !== $resume_token && empty( $resume_state ) ) {
			return new \WP_Error(
				'wp_mcp_ai_import_resume_not_found',
				__( 'The resume token does not match an existing import checkpoint.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$report['token']   = '' !== $resume_token ? $resume_token : $this->generate_token();
		$report['dry_run'] = $dry_run;
		$report['policy']  = $policy;

		$source_path = $this->resolve_source_path( $args );
		if ( is_wp_error( $source_path ) ) {
			return $source_path;
		}

		$archive = new Archive();
		$files   = $archive->prepare( $source_path );
		if ( is_wp_error( $files ) ) {
			return $files;
		}

		$detector  = new FormatDetector();
		$writer    = $this->get_writer();
		$media     = $sideload_media ? new Media() : null;
		$processed = 0;

		if ( ! empty( $resume_state ) && isset( $resume_state['processed'] ) ) {
			$processed                     = absint( $resume_state['processed'] );
			$report['totals']['processed'] = $processed;
		}

		foreach ( $files as $file_index => $file_path ) {
			if ( $limit > 0 && $processed >= $limit ) {
				break;
			}

			$file_report = array(
				'file'     => wp_basename( $file_path ),
				'platform' => '',
				'detected' => 0,
				'imported' => 0,
				'updated'  => 0,
				'skipped'  => 0,
				'failed'   => 0,
				'error'    => '',
			);

			$detection = $detector->detect( $file_path );
			if ( is_wp_error( $detection ) ) {
				$file_report['error'] = $detection->get_error_message();
				$this->add_error( $report, $detection->get_error_message(), array( 'file' => $file_path ) );
				$report['files'][] = $file_report;
				continue;
			}

			$adapter                 = $detection['adapter'];
			$platform                = $detection['platform'];
			$file_report['platform'] = $platform;

			// Honor an explicit format override by skipping mismatched files.
			if ( isset( $args['format'] ) && '' !== sanitize_key( (string) $args['format'] ) ) {
				$requested = sanitize_key( (string) $args['format'] );
				if ( $requested !== $platform ) {
					$file_report['error'] = sprintf(
						/* translators: 1: requested format, 2: detected format. */
						__( 'Skipped: requested format "%1$s" but file detected as "%2$s".', 'nvoos-content-graph-ai-platform' ),
						$requested,
						$platform
					);
					$report['files'][] = $file_report;
					continue;
				}
			}

			$extraction = $adapter->extract( $this->decode_payload( $detector, $file_path ) );
			if ( is_wp_error( $extraction ) ) {
				$file_report['error'] = $extraction->get_error_message();
				$this->add_error( $report, $extraction->get_error_message(), array( 'file' => $file_path ) );
				$report['files'][] = $file_report;
				continue;
			}

			$batch = array();
			foreach ( $extraction as $conversation ) {
				if ( ! $this->is_conversation_instance( $conversation ) ) {
					continue;
				}

				if ( $limit > 0 && $processed >= $limit ) {
					break;
				}

				if ( null !== $media && 'chatgpt' === $conversation->get_platform() ) {
					$payload_dir = dirname( $file_path );
					$resolved    = $media->sideload( $conversation, $payload_dir );
					if ( $this->is_conversation_instance( $resolved ) ) {
						$conversation = $resolved;
					}
				}

				$batch[] = $conversation;

				if ( count( $batch ) >= $batch_size ) {
					$batch = $this->cap_batch( $batch, $processed, $limit );
					if ( empty( $batch ) ) {
						break;
					}
					$outcome    = $this->process_batch( $writer, $batch, $user_id, $dry_run, $policy, $report, $file_report );
					$processed += $outcome;
					$batch      = array();

					$this->emit_progress( $processed, $estimated_total, $report );

					if ( $limit > 0 && $processed >= $limit ) {
						break;
					}

					$this->save_checkpoint( $report, $processed );
				}
			}

			if ( ! empty( $batch ) ) {
				$batch = $this->cap_batch( $batch, $processed, $limit );
			}
			if ( ! empty( $batch ) ) {
				$outcome    = $this->process_batch( $writer, $batch, $user_id, $dry_run, $policy, $report, $file_report );
				$processed += $outcome;
			}

			$this->emit_progress( $processed, $estimated_total, $report );

			$report['files'][] = $file_report;
			$this->save_checkpoint( $report, $processed );
		}

		$archive->cleanup();

		$report['totals']['processed'] = $processed;
		$report['status']              = 'completed';
		if ( $report['totals']['failed'] > 0 ) {
			$report['status'] = 'completed_with_errors';
		}
		$report['duration_ms'] = (int) round( ( microtime( true ) - $started_at ) * 1000 );

		$this->delete_checkpoint( $report['token'] );
		$this->emit_progress( $processed, $estimated_total, $report );
		$this->audit( $report, $source_path );

		/**
		 * Fires when a conversation import run completes.
		 *
		 * Downstream integrations (memory mining, analytics) hook this to
		 * react to freshly imported conversations. The report carries the
		 * imported session keys (capped) under `imported_session_keys`.
		 *
		 * @since 2.1.0
		 *
		 * @param array $report  Final import report.
		 * @param int   $user_id Importing WordPress user ID (0 for CLI/system).
		 */
		do_action( 'wp_mcp_ai_conversation_import_completed', $report, $user_id );

		return $report;
	}

	/**
	 * Slice a batch down to the remaining conversation quota.
	 *
	 * @param array $batch     Candidate batch.
	 * @param int   $processed Conversations already processed.
	 * @param int   $limit     Total conversation limit (0 = unlimited).
	 * @return array
	 */
	protected function cap_batch( array $batch, $processed, $limit ) {
		if ( $limit <= 0 ) {
			return $batch;
		}

		$remaining = $limit - (int) $processed;
		if ( $remaining <= 0 ) {
			return array();
		}

		return array_slice( $batch, 0, $remaining );
	}

	/**
	 * Process one batch of canonical conversations.
	 *
	 * @param CctWriter $writer      CCT writer.
	 * @param array     $batch       Canonical conversations.
	 * @param int       $user_id     Importing user ID.
	 * @param bool      $dry_run     Whether writes are simulated.
	 * @param string    $policy      Dedupe policy.
	 * @param array     $report      Report array (by reference).
	 * @param array     $file_report Per-file stats (by reference).
	 * @return int Number of conversations consumed.
	 */
	protected function process_batch( $writer, array $batch, $user_id, $dry_run, $policy, &$report, &$file_report ) {
		$session_keys = array();
		foreach ( $batch as $conversation ) {
			$session_keys[] = $conversation->get_session_key();
		}

		$existing = $writer->find_existing_ids( $session_keys );
		if ( is_wp_error( $existing ) ) {
			foreach ( $batch as $conversation ) {
				++$report['totals']['failed'];
				++$file_report['failed'];
				$this->add_error(
					$report,
					$existing->get_error_message(),
					array( 'session_key' => $conversation->get_session_key() )
				);
			}

			return count( $batch );
		}

		foreach ( $batch as $conversation ) {
			++$report['totals']['detected'];
			++$file_report['detected'];

			$session_key = $conversation->get_session_key();
			$existing_id = isset( $existing[ $session_key ] ) ? absint( $existing[ $session_key ] ) : 0;

			if ( 0 !== $existing_id && self::POLICY_SKIP === $policy ) {
				++$report['totals']['skipped'];
				++$file_report['skipped'];
				continue;
			}

			if ( $dry_run ) {
				++$report['totals'][ 0 !== $existing_id ? 'updated' : 'imported' ];
				++$file_report[ 0 !== $existing_id ? 'updated' : 'imported' ];
				continue;
			}

			$result = $writer->write( $conversation, $user_id, $existing_id );
			if ( is_wp_error( $result ) ) {
				++$report['totals']['failed'];
				++$file_report['failed'];
				$this->add_error(
					$report,
					$result->get_error_message(),
					array( 'session_key' => $session_key )
				);
				continue;
			}

			$action = isset( $result['action'] ) && 'updated' === $result['action'] ? 'updated' : 'imported';
			++$report['totals'][ $action ];
			++$file_report[ $action ];

			// Record the session key (capped) so downstream hooks — memory
			// mining, analytics — can scope work to exactly what was imported.
			if ( count( $report['imported_session_keys'] ) < 500 ) {
				$report['imported_session_keys'][] = $session_key;
			}
		}

		return count( $batch );
	}

	/**
	 * Inspect a payload file without importing anything.
	 *
	 * Used by the detect tool. Returns platform, byte size, and an estimated
	 * conversation count.
	 *
	 * @param string $file_path Absolute path to the payload file.
	 * @return array|\WP_Error
	 */
	public function inspect( $file_path ) {
		$detector  = new FormatDetector();
		$detection = $detector->detect( $file_path );
		if ( is_wp_error( $detection ) ) {
			return $detection;
		}

		$decoded = $detector->decode_file( $file_path );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$estimate = 0;
		if ( is_array( $decoded ) ) {
			$estimate = count( $decoded );
		}

		return array(
			'file'            => wp_basename( $file_path ),
			'platform'        => $detection['platform'],
			'bytes'           => (int) filesize( $file_path ),
			'estimated_count' => $estimate,
			'adapters'        => array_keys( $detector->get_adapters() ),
		);
	}

	/**
	 * Retrieve checkpoint state for a run token.
	 *
	 * @param string $token Run token.
	 * @return array
	 */
	public function get_status( $token ) {
		$state = $this->get_checkpoint( $token );
		if ( empty( $state ) ) {
			return array(
				'token'   => $token,
				'status'  => 'not_found',
				'message' => __( 'No checkpoint found for this token. Completed runs are not retained; use the run report instead.', 'nvoos-content-graph-ai-platform' ),
			);
		}

		return $state;
	}

	/**
	 * Whether a candidate satisfies the canonical conversation class for
	 * this install mode.
	 *
	 * The discriminator is `defined( 'WP_MCP_AI_PATH' )` — never bare
	 * `instanceof` — because monolith installs flow the base conversation
	 * class (base adapters) while standalone installs flow this package's
	 * class. Both share a byte-identical method surface.
	 *
	 * @param object $candidate Candidate conversation instance.
	 * @return bool
	 */
	protected function is_conversation_instance( $candidate ): bool {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return $candidate instanceof \WP_MCP_AI_Conversation_Import_Conversation;
		}

		return $candidate instanceof Conversation;
	}

	/**
	 * Decode a payload file for adapter extraction.
	 *
	 * Re-decodes so the detector stays stateless and reusable.
	 *
	 * @param FormatDetector $detector  Detector instance.
	 * @param string         $file_path Payload file path.
	 * @return mixed|\WP_Error
	 */
	protected function decode_payload( $detector, $file_path ) {
		return $detector->decode_file( $file_path );
	}

	/**
	 * Resolve the source argument into an absolute file path.
	 *
	 * Numeric sources are treated as WordPress attachment IDs.
	 *
	 * @param array $args Run arguments.
	 * @return string|\WP_Error
	 */
	protected function resolve_source_path( array $args ) {
		if ( empty( $args['source'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_import_missing_source',
				__( 'Provide an import source: a file path or a media library attachment ID.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$source = $args['source'];

		if ( is_numeric( $source ) ) {
			$attachment_id = absint( $source );
			$path          = get_attached_file( $attachment_id );

			if ( false === $path || '' === $path || ! file_exists( $path ) ) {
				return new \WP_Error(
					'wp_mcp_ai_import_attachment_missing',
					__( 'The media library attachment could not be found.', 'nvoos-content-graph-ai-platform' )
				);
			}

			return wp_normalize_path( $path );
		}

		return wp_normalize_path( sanitize_text_field( (string) $source ) );
	}

	/**
	 * Generate a unique run token.
	 *
	 * @return string
	 */
	protected function generate_token() {
		return 'import-' . gmdate( 'YmdHis' ) . '-' . substr( wp_hash( uniqid( '', true ) ), 0, 12 );
	}

	/**
	 * Persist checkpoint state for a running import.
	 *
	 * @param array $report    Report so far.
	 * @param int   $processed Conversations processed so far.
	 * @return void
	 */
	protected function save_checkpoint( array $report, $processed ) {
		update_option(
			self::CHECKPOINT_OPTION,
			array(
				'token'      => $report['token'],
				'status'     => 'running',
				'processed'  => $processed,
				'dry_run'    => $report['dry_run'],
				'policy'     => $report['policy'],
				'totals'     => $report['totals'],
				'files'      => $report['files'],
				'updated_at' => gmdate( 'c' ),
			),
			false
		);
	}

	/**
	 * Retrieve checkpoint state for a token.
	 *
	 * @param string $token Run token.
	 * @return array
	 */
	protected function get_checkpoint( $token ) {
		$state = get_option( self::CHECKPOINT_OPTION, array() );

		if ( ! is_array( $state ) || empty( $state['token'] ) || $state['token'] !== $token ) {
			return array();
		}

		return $state;
	}

	/**
	 * Remove the checkpoint once a run completes.
	 *
	 * @param string $token Run token.
	 * @return void
	 */
	protected function delete_checkpoint( $token ) {
		$state = get_option( self::CHECKPOINT_OPTION, array() );
		if ( is_array( $state ) && isset( $state['token'] ) && $state['token'] === $token ) {
			delete_option( self::CHECKPOINT_OPTION );
		}
	}

	/**
	 * Append a bounded error entry to the report.
	 *
	 * @param array  $report  Report array (by reference).
	 * @param string $message Human-readable error message.
	 * @param array  $context Optional context.
	 * @return void
	 */
	protected function add_error( &$report, $message, array $context = array() ) {
		if ( count( $report['errors'] ) >= self::MAX_REPORT_ERRORS ) {
			return;
		}

		$report['errors'][] = array_merge(
			array( 'message' => sanitize_text_field( $message ) ),
			$context
		);
	}

	/**
	 * Write an audit log entry for a completed run.
	 *
	 * Monolith-only: the base `WP_MCP_AI_Logger` does not exist standalone,
	 * and the platform addon logs through its own services.
	 *
	 * @param array  $report      Final report.
	 * @param string $source_path Resolved source path.
	 * @return void
	 */
	protected function audit( array $report, $source_path ) {
		if ( ! defined( 'WP_MCP_AI_PATH' ) || ! class_exists( 'WP_MCP_AI_Logger' ) ) {
			return;
		}

		\WP_MCP_AI_Logger::log_event(
			'info',
			'Conversation import completed',
			array(
				'token'       => $report['token'],
				'source'      => $source_path,
				'dry_run'     => $report['dry_run'],
				'totals'      => $report['totals'],
				'error_count' => count( $report['errors'] ),
			)
		);
	}
}
