<?php
/**
 * Tool: conversation_import_run — Import external conversations into the CCT.
 *
 * @package WP_MCP_AI
 * @since   1.1.60
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import external AI conversations into the ai_chat_transcripts CCT.
 */
class WP_MCP_AI_Tool_Conversation_Import_Run implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Determine whether conversation import tooling is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'jet_engine' ) || class_exists( 'Jet_Engine' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Conversation Import Run tool is disabled because JetEngine is not active.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'conversation_import_run';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import Conversations to CCT', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Imports an external AI conversation export (ChatGPT conversations.json, Google Takeout Gemini activity, Claude conversations.jsonl, ShareGPT datasets, OpenAI fine-tuning JSONL, or a ZIP archive containing them) into the JetEngine AI Chat Transcripts CCT — one CCT row per conversation. Supports dry-run previews, skip/refresh dedupe policies, image sideloading, and resumable runs.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'source'         => array(
					'type'        => 'string',
					'description' => __( 'Absolute file path to the export (ZIP, JSON), or a media library attachment ID.', 'mcp-ai-wpoos' ),
				),
				'format'         => array(
					'type'        => 'string',
					'description' => __( 'Optional format override. Auto-detected when omitted.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'chatgpt', 'gemini', 'claude', 'sharegpt', 'openai_jsonl' ),
				),
				'sideload_media' => array(
					'type'        => 'boolean',
					'description' => __( 'Sideload referenced export images (e.g. ChatGPT attachments) into the media library. Default false.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'dry_run'        => array(
					'type'        => 'boolean',
					'description' => __( 'Preview the import without writing to the CCT. Default false.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'policy'         => array(
					'type'        => 'string',
					'description' => __( 'How to treat conversations that already exist: "skip" leaves existing rows untouched, "refresh" overwrites them.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'skip', 'refresh' ),
					'default'     => 'skip',
				),
				'batch_size'     => array(
					'type'        => 'integer',
					'description' => __( 'Conversations processed per batch. Default 25.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 200,
					'default'     => 25,
				),
				'limit'          => array(
					'type'        => 'integer',
					'description' => __( 'Maximum conversations to process. 0 means all. Default 0.', 'mcp-ai-wpoos' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'resume_token'   => array(
					'type'        => 'string',
					'description' => __( 'Optional token from a previous run to resume after interruption.', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array( 'source' ),
			'additionalProperties' => false,
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
	public function get_capability_flags() {
		return array(
			'write',
			'state-changing',
			'idempotent',
			'pii-data',
			'performance-impact',
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
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_jetengine_missing', __( 'JetEngine is not active on this site.', 'mcp-ai-wpoos' ) );
		}

		if ( empty( $arguments['source'] ) ) {
			return new WP_Error( 'wp_mcp_ai_import_missing_source', __( 'Provide an import source: a file path or a media library attachment ID.', 'mcp-ai-wpoos' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$args = array(
			'source'  => sanitize_text_field( (string) $arguments['source'] ),
			'user_id' => $user_id,
		);

		if ( ! empty( $arguments['format'] ) ) {
			$args['format'] = sanitize_key( (string) $arguments['format'] );
		}
		if ( ! empty( $arguments['sideload_media'] ) ) {
			$args['sideload_media'] = true;
		}
		if ( isset( $arguments['dry_run'] ) ) {
			$args['dry_run'] = (bool) $arguments['dry_run'];
		}
		if ( ! empty( $arguments['policy'] ) ) {
			$args['policy'] = sanitize_key( (string) $arguments['policy'] );
		}
		if ( isset( $arguments['batch_size'] ) ) {
			$args['batch_size'] = absint( $arguments['batch_size'] );
		}
		if ( isset( $arguments['limit'] ) ) {
			$args['limit'] = absint( $arguments['limit'] );
		}
		if ( ! empty( $arguments['resume_token'] ) ) {
			$args['resume_token'] = sanitize_text_field( (string) $arguments['resume_token'] );
		}

		$manager = new WP_MCP_AI_Conversation_Import_Manager();
		$report  = $manager->run( $args );
		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$totals  = $report['totals'];
		$message = $report['dry_run']
			? sprintf(
				/* translators: 1: number that would be imported, 2: number that would be updated, 3: number skipped. */
				__( 'Dry run complete: %1$d import(s), %2$d update(s), %3$d skipped.', 'mcp-ai-wpoos' ),
				$totals['imported'],
				$totals['updated'],
				$totals['skipped']
			)
			: sprintf(
				/* translators: 1: imported count, 2: updated count, 3: skipped count, 4: failed count. */
				__( 'Conversation import finished: %1$d imported, %2$d updated, %3$d skipped, %4$d failed.', 'mcp-ai-wpoos' ),
				$totals['imported'],
				$totals['updated'],
				$totals['skipped'],
				$totals['failed']
			);

		return $this->format_success_response(
			$message,
			array(
				'token'       => $report['token'],
				'status'      => $report['status'],
				'dry_run'     => $report['dry_run'],
				'policy'      => $report['policy'],
				'totals'      => $totals,
				'files'       => $report['files'],
				'errors'      => $report['errors'],
				'duration_ms' => $report['duration_ms'],
			)
		);
	}
}
