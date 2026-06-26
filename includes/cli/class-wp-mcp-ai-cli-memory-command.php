<?php
/**
 * WP-CLI command for agent memory management.
 *
 * @package WP_MCP_AI
 * @since   1.1.30
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-cli-base-command.php';

/**
 * Manage agent memory: recall, store, forget, and audit.
 *
 * @since 1.1.30
 */
class WP_MCP_AI_CLI_Memory_Command extends WP_MCP_AI_CLI_Base_Command {

	/**
	 * Recall agent memories.
	 *
	 * ## OPTIONS
	 *
	 * [--assistant=<id>]
	 * : Assistant post ID (default: site default assistant).
	 *
	 * [--query=<text>]
	 * : Search query to filter memories.
	 *
	 * [--context-type=<type>]
	 * : Filter by context type (learning, fact, preference, pattern, etc.).
	 *
	 * [--importance=<level>]
	 * : Filter by importance (low, medium, high, critical).
	 *
	 * [--limit=<number>]
	 * : Maximum results (default: 20).
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format (table, json, yaml, csv).
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai memory recall --query="project deadlines"
	 *     $ wp mcp-ai memory recall --importance=high --format=json
	 *
	 * @when after_wp_load
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function recall( $args, $assoc_args ) {
		$assistant_id = isset( $assoc_args['assistant'] ) ? absint( $assoc_args['assistant'] ) : $this->default_assistant_id();

		// Accept --assistant-id as an alias for --assistant (documented in README).
		if ( 0 === $assistant_id && isset( $assoc_args['assistant-id'] ) ) {
			$assistant_id = absint( $assoc_args['assistant-id'] );
		}

		$query        = sanitize_text_field( (string) ( $assoc_args['query'] ?? '' ) );
		$context_type = isset( $assoc_args['context-type'] ) ? sanitize_key( $assoc_args['context-type'] ) : '';
		$importance   = isset( $assoc_args['importance'] ) ? sanitize_key( $assoc_args['importance'] ) : '';
		$limit        = min( 100, absint( $assoc_args['limit'] ?? 20 ) );
		$format       = $assoc_args['format'] ?? 'table';

		if ( ! $this->tool_available( 'retrieve_agent_memory' ) ) {
			$this->error( __( 'Memory tools are not available. Ensure the plugin is properly configured.', 'mcp-ai-wpoos' ) );
		}

		if ( 0 === $assistant_id ) {
			$this->error( __( 'Agent ID is required. Use --assistant=<id> or configure a site default assistant.', 'mcp-ai-wpoos' ) );
		}

		$tool_args = array(
			'agent_id' => $assistant_id,
			'limit'    => $limit,
		);

		if ( '' !== $query ) {
			$tool_args['query'] = $query;
		}
		if ( '' !== $context_type ) {
			$tool_args['context_types'] = array( $context_type );
		}
		if ( '' !== $importance ) {
			$tool_args['importance'] = $importance;
		}

		$context = array( 'assistant_id' => $assistant_id );
		$result  = $this->execute_tool( 'retrieve_agent_memory', $tool_args, $context );

		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		}

		$memories = $result['memories'] ?? $result['results'] ?? array();
		if ( empty( $memories ) ) {
			$this->warning( __( 'No memories found.', 'mcp-ai-wpoos' ) );
			return;
		}

		$items = array();
		foreach ( $memories as $mem ) {
			// Safe truncation: mb_strimwidth requires mbstring; fall back to substr.
			$content_short = function_exists( 'mb_strimwidth' )
				? mb_strimwidth( $mem['content'] ?? $mem['text'] ?? '', 0, 120, '…' )
				: ( strlen( (string) ( $mem['content'] ?? $mem['text'] ?? '' ) ) > 120
					? substr( (string) ( $mem['content'] ?? $mem['text'] ?? '' ), 0, 119 ) . '…'
					: (string) ( $mem['content'] ?? $mem['text'] ?? '' ) );

			$items[] = array(
				'ID'         => $mem['context_id'] ?? $mem['id'] ?? '',
				'Type'       => $mem['context_type'] ?? $mem['type'] ?? '',
				'Importance' => $mem['importance'] ?? '',
				'Content'    => $content_short,
				'Created'    => isset( $mem['created_at'] ) ? wp_date( 'Y-m-d H:i', $mem['created_at'] ) : '',
			);
		}

		$this->format_output( $items, $format );
		$this->success(
			sprintf(
				/* translators: %d: number of memories */
				__( 'Found %d memories.', 'mcp-ai-wpoos' ),
				count( $memories )
			)
		);
	}

	/**
	 * Store a new agent memory.
	 *
	 * ## OPTIONS
	 *
	 * <content>
	 * : The content to remember.
	 *
	 * [--assistant=<id>]
	 * : Assistant post ID.
	 *
	 * [--context-type=<type>]
	 * : Context type (learning, fact, preference, pattern, workflow, decision,
	 *   result, insight, note, generic).
	 * ---
	 * default: generic
	 * ---
	 *
	 * [--importance=<level>]
	 * : Importance level (low, medium, high, critical).
	 * ---
	 * default: medium
	 * ---
	 *
	 * [--ttl=<seconds>]
	 * : Time-to-live in seconds (default: 2592000 = 30 days).
	 * ---
	 * default: 2592000
	 * ---
	 *
	 * [--tags=<tags>]
	 * : Comma-separated tags.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai memory store "The client prefers email communication" --importance=high
	 *
	 * @when after_wp_load
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function store( $args, $assoc_args ) {
		$content      = sanitize_textarea_field( (string) ( $args[0] ?? '' ) );
		$assistant_id = isset( $assoc_args['assistant'] ) ? absint( $assoc_args['assistant'] ) : $this->default_assistant_id();
		$context_type = sanitize_key( $assoc_args['context-type'] ?? 'generic' );
		$importance   = sanitize_key( $assoc_args['importance'] ?? 'medium' );
		$ttl          = max( 3600, absint( $assoc_args['ttl'] ?? 2592000 ) );
		$tags         = isset( $assoc_args['tags'] ) ? array_map( 'sanitize_key', explode( ',', $assoc_args['tags'] ) ) : array();

		if ( '' === $content ) {
			$this->error( __( 'Content is required.', 'mcp-ai-wpoos' ) );
		}

		if ( ! $this->tool_available( 'store_agent_context' ) ) {
			$this->error( __( 'Memory tools are not available.', 'mcp-ai-wpoos' ) );
		}

		$tool_args = array(
			'content'      => $content,
			'context_type' => $context_type,
			'importance'   => $importance,
			'ttl'          => $ttl,
		);

		if ( ! empty( $tags ) ) {
			$tool_args['tags'] = $tags;
		}

		$context = array( 'assistant_id' => $assistant_id );
		$result  = $this->execute_tool( 'store_agent_context', $tool_args, $context );

		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		}

		$context_id = $result['context_id'] ?? $result['id'] ?? '';
		$this->success(
			sprintf(
				/* translators: %s: context ID */
				__( 'Memory stored with ID: %s', 'mcp-ai-wpoos' ),
				$context_id
			)
		);
	}

	/**
	 * Forget (delete) a stored memory by context ID.
	 *
	 * ## OPTIONS
	 *
	 * <context-id>
	 * : The context ID to delete.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai memory forget abc123def456
	 *
	 * @when after_wp_load
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function forget( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$context_id = sanitize_text_field( (string) ( $args[0] ?? '' ) );

		if ( '' === $context_id ) {
			$this->error( __( 'Context ID is required.', 'mcp-ai-wpoos' ) );
		}

		if ( ! $this->tool_available( 'manage_context_lifecycle' ) ) {
			$this->error( __( 'Memory management tools are not available.', 'mcp-ai-wpoos' ) );
		}

		$result = $this->execute_tool(
			'manage_context_lifecycle',
			array(
				'action'     => 'delete',
				'context_id' => $context_id,
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		}

		$this->success( __( 'Memory deleted.', 'mcp-ai-wpoos' ) );
	}

	/**
	 * View the memory audit trail.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Maximum entries (default: 50).
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai memory audit --limit=20
	 *
	 * @when after_wp_load
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function audit( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$limit  = min( 100, absint( $assoc_args['limit'] ?? 50 ) );
		$format = $assoc_args['format'] ?? 'table';

		if ( ! $this->tool_available( 'memory_audit_trail' ) ) {
			$this->error( __( 'Memory audit tools are not available.', 'mcp-ai-wpoos' ) );
		}

		$result = $this->execute_tool( 'memory_audit_trail', array( 'limit' => $limit ) );

		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		}

		$entries = $result['entries'] ?? $result['audit_trail'] ?? array();
		if ( empty( $entries ) ) {
			$this->warning( __( 'No audit entries found.', 'mcp-ai-wpoos' ) );
			return;
		}

		$items = array();
		foreach ( $entries as $entry ) {
			$items[] = array(
				'ID'      => $entry['event_id'] ?? $entry['id'] ?? '',
				'Action'  => $entry['action'] ?? $entry['event'] ?? '',
				'Context' => function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $entry['context_id'] ?? $entry['summary'] ?? '', 0, 60, '…' ) : ( strlen( (string) ( $entry['context_id'] ?? $entry['summary'] ?? '' ) ) > 60 ? substr( (string) ( $entry['context_id'] ?? $entry['summary'] ?? '' ), 0, 59 ) . '…' : (string) ( $entry['context_id'] ?? $entry['summary'] ?? '' ) ),
				'Time'    => isset( $entry['timestamp'] ) ? wp_date( 'Y-m-d H:i:s', $entry['timestamp'] ) : '',
			);
		}

		$this->format_output( $items, $format );
		$this->success(
			sprintf(
				/* translators: %d: number of audit entries */
				__( 'Found %d audit entries.', 'mcp-ai-wpoos' ),
				count( $entries )
			)
		);
	}

	/**
	 * Display memory statistics.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai memory stats
	 *
	 * @when after_wp_load
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function stats( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$format = $assoc_args['format'] ?? 'table';

		if ( ! function_exists( 'wp_mcp_ai_get_agent_memory_stats' ) ) {
			// Try to load the helper.
			$helper_file = WP_MCP_AI_PATH . 'includes/helpers/agent-memory-helpers.php';
			if ( file_exists( $helper_file ) ) {
				require_once $helper_file;
			}
		}

		if ( function_exists( 'wp_mcp_ai_get_agent_memory_stats' ) ) {
			$stats = wp_mcp_ai_get_agent_memory_stats();
		} else {
			// Fallback: query directly.
			$stats = array(
				'total_memories'   => 0,
				'by_type'          => array(),
				'by_importance'    => array(),
				'persistent_count' => 0,
				'cache_only'       => true,
			);
		}

		if ( 'json' === $format || 'yaml' === $format || 'csv' === $format ) {
			$this->format_output( array( $stats ), $format );
		} else {
			WP_CLI::log( WP_CLI::colorize( '%G' . __( 'Memory Statistics:', 'mcp-ai-wpoos' ) . '%n' ) );
			WP_CLI::log(
				sprintf(
					/* translators: %d: total memory count */
					__( '  Total memories: %d', 'mcp-ai-wpoos' ),
					$stats['total_memories'] ?? 0
				)
			);
			WP_CLI::log(
				sprintf(
					/* translators: %s: storage type (Yes or Cache only) */
					__( '  Persistent storage: %s', 'mcp-ai-wpoos' ),
					empty( $stats['cache_only'] ) ? __( 'Yes', 'mcp-ai-wpoos' ) : __( 'Cache only', 'mcp-ai-wpoos' )
				)
			);

			if ( ! empty( $stats['by_type'] ) ) {
				WP_CLI::log( __( '  By type:', 'mcp-ai-wpoos' ) );
				foreach ( $stats['by_type'] as $type => $count ) {
					WP_CLI::log( sprintf( '    %s: %d', $type, $count ) );
				}
			}

			if ( ! empty( $stats['by_importance'] ) ) {
				WP_CLI::log( __( '  By importance:', 'mcp-ai-wpoos' ) );
				foreach ( $stats['by_importance'] as $level => $count ) {
					WP_CLI::log( sprintf( '    %s: %d', $level, $count ) );
				}
			}
		}
	}

	/**
	 * Check if a tool is available in the registry.
	 *
	 * @param string $slug Tool slug.
	 * @return bool
	 */
	private function tool_available( $slug ) {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return false;
		}
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		return null !== $registry->get_tool( $slug );
	}

	/**
	 * Execute a tool and return its result.
	 *
	 * @param string $slug      Tool slug.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return array|WP_Error
	 */
	private function execute_tool( $slug, $arguments, $context = array() ) {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return new WP_Error( 'registry_missing', __( 'Tool registry not available.', 'mcp-ai-wpoos' ) );
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( $slug );

		if ( ! $tool ) {
			return new WP_Error(
				'tool_not_found',
				sprintf(
					/* translators: %s: tool slug */
					__( 'Tool "%s" not found.', 'mcp-ai-wpoos' ),
					$slug
				)
			);
		}

		return $tool->execute( $arguments, $context );
	}

	/**
	 * Get the default assistant ID from plugin settings.
	 *
	 * @return int
	 */
	private function default_assistant_id() {
		if ( class_exists( 'WP_MCP_AI_Settings_Registry' ) ) {
			return absint( WP_MCP_AI_Settings_Registry::get_setting( 'default_assistant', 0 ) );
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return absint( $settings['default_assistant'] ?? 0 );
	}
}

WP_CLI::add_command( 'mcp-ai memory', 'WP_MCP_AI_CLI_Memory_Command' );
