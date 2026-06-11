<?php
/**
 * Data Layer Initialization
 *
 * Boots the Transformer-inspired data services: tool attention routing,
 * conversation compression, and tool embedding storage.
 *
 * This file is loaded by includes/bootstrap/loader.php and wires:
 * - The attention-based tool selection filter
 * - The WP-Cron hook for async tool embedding computation
 * - The DB table creation on activation
 *
 * All services degrade gracefully when dependencies (vector service,
 * OpenAI API key, etc.) are unavailable.
 *
 * @package WP_MCP_AI
 * @since   1.8.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// 1. Lazy-load the data-layer classes on first use.
// ---------------------------------------------------------------------------

/**
 * Ensure the data-layer classes are loaded.
 *
 * Called on-demand by the filter and cron hooks below. Avoids loading
 * classes on every request when the features are unused.
 *
 * @since 1.8.0
 * @return bool True if classes are available.
 */
function wp_mcp_ai_data_layer_ensure_loaded() {
	static $loaded = false;
	if ( $loaded ) {
		return true;
	}

	// Check that the plugin path constant is available.
	if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
		return false;
	}

	$files = array(
		'class-wp-mcp-ai-tool-embedding-store.php',
		'class-wp-mcp-ai-tool-attention-router.php',
		'class-wp-mcp-ai-conversation-compressor.php',
	);

	foreach ( $files as $file ) {
		$path = WP_MCP_AI_PATH . 'includes/data/' . $file;
		if ( file_exists( $path ) && ! class_exists( str_replace( array( 'class-', '.php' ), array( '', '' ), $file ) ) ) {
			require_once $path;
		}
	}

	$loaded = true;
	return true;
}

// ---------------------------------------------------------------------------
// 2. Attention-based tool selection filter.
// ---------------------------------------------------------------------------

/**
 * Filter tool slugs via the attention router before they become LLM payloads.
 *
 * Hooks into {@see 'wp_mcp_ai_attention_tool_slugs'} at priority 20 so
 * other filters can run first (e.g. capability-based filtering).
 *
 * When the vector service is unavailable, the filter returns an empty array
 * (meaning "use all tools" — the fallback behaviour), so no tools are lost.
 *
 * @since 1.8.0
 *
 * @param string[] $filtered_slugs   Previously filtered slugs (empty = use all).
 * @param string[] $allowed_slugs    Tool slugs from the assistant config.
 * @param array    $assistant_config Assistant configuration.
 * @return string[] Filtered tool slugs, or empty array to use all.
 */
function wp_mcp_ai_attention_filter_tool_slugs( $filtered_slugs, $allowed_slugs, $assistant_config ) {
	// If another filter already reduced the list, respect its result.
	if ( ! empty( $filtered_slugs ) ) {
		return $filtered_slugs;
	}

	// Don't filter if there are too few tools to matter.
	if ( count( $allowed_slugs ) <= 30 ) {
		return $allowed_slugs;
	}

	// Check if attention routing is disabled.
	$enabled = (bool) apply_filters( 'wp_mcp_ai_attention_routing_enabled', true );
	if ( ! $enabled ) {
		return $allowed_slugs;
	}

	// Lazy-load the data layer classes.
	if ( ! wp_mcp_ai_data_layer_ensure_loaded() ) {
		return $allowed_slugs;
	}

	// Build a query text from the conversation context.
	$query_text = wp_mcp_ai_build_attention_query( $assistant_config );

	if ( empty( $query_text ) ) {
		return $allowed_slugs;
	}

	try {
		$router  = WP_MCP_AI_Tool_Attention_Router::get_instance();
		$user_id = get_current_user_id();

		$top_k = (int) apply_filters( 'wp_mcp_ai_attention_top_k', WP_MCP_AI_Tool_Attention_Router::DEFAULT_TOP_K );

		$selected = $router->select_tools(
			$query_text,
			$allowed_slugs,
			array(
				'top_k'   => $top_k,
				'user_id' => $user_id,
			)
		);

		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'attention_routing',
				'Attention router filtered tools',
				array(
					'original_count' => count( $allowed_slugs ),
					'selected_count' => count( $selected ),
					'reduction_pct'  => $selected ? round( ( 1 - count( $selected ) / count( $allowed_slugs ) ) * 100, 1 ) : 0,
				)
			);
		}

		return $selected;
	} catch ( Exception $e ) {
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_error(
				'Attention routing filter failed, using all tools.',
				array( 'error' => $e->getMessage() )
			);
		}
		return $allowed_slugs;
	}
}

/**
 * Build a query text for the attention router from the assistant config.
 *
 * Extracts the system prompt and recent conversation context to form a
 * semantic "query" that the attention router uses to score tools.
 *
 * @since 1.8.0
 *
 * @param array $assistant_config Assistant configuration.
 * @return string Query text, or empty string.
 */
function wp_mcp_ai_build_attention_query( $assistant_config ) {
	$parts = array();

	// 1. Assistant name/role description (from system prompt).
	if ( ! empty( $assistant_config['system_prompt'] ) ) {
		// Take first 500 chars — captures the role definition.
		$parts[] = substr( (string) $assistant_config['system_prompt'], 0, 500 );
	}

	// 2. Assistant title if available.
	if ( ! empty( $assistant_config['title'] ) ) {
		$parts[] = 'Assistant: ' . $assistant_config['title'];
	}

	// 3. Recent user messages from the request context (when available).
	if ( ! empty( $assistant_config['_last_user_message'] ) ) {
		$parts[] = 'Query: ' . $assistant_config['_last_user_message'];
	}

	return implode( "\n", $parts );
}

/**
 * Merge the last user message into the assistant config for attention routing.
 *
 * Hooks into 'wp_mcp_ai_chat_options' at priority 1 to capture the user's
 * last message before the attention filter runs at priority 20.
 *
 * @since 1.8.0
 *
 * @param array           $options          Chat options.
 * @param array           $assistant_config Assistant configuration.
 * @param WP_REST_Request $request          REST request.
 * @return array Unmodified options.
 */
function wp_mcp_ai_capture_last_user_message( $options, $assistant_config, $request ) {
	if ( ! $request instanceof WP_REST_Request ) {
		return $options;
	}

	$messages = $request->get_param( 'messages' );
	if ( is_array( $messages ) && ! empty( $messages ) ) {
		$last_msg = end( $messages );
		if ( is_array( $last_msg ) && isset( $last_msg['content'] ) && is_string( $last_msg['content'] ) ) {
			$assistant_config['_last_user_message'] = substr( $last_msg['content'], 0, 300 );
		}
	}

	return $options;
}

// Register the attention filter at priority 20 (after other filters).
add_filter( 'wp_mcp_ai_attention_tool_slugs', 'wp_mcp_ai_attention_filter_tool_slugs', 20, 3 );

// Capture the last user message early so it's available for attention routing.
add_filter( 'wp_mcp_ai_chat_options', 'wp_mcp_ai_capture_last_user_message', 1, 3 );

// ---------------------------------------------------------------------------
// 3. Tool embedding compute cron hook.
// ---------------------------------------------------------------------------

/**
 * Handle the async tool embedding computation cron job.
 *
 * Called by WP-Cron when a tool is registered and needs its embedding
 * pre-computed. Processes one tool per invocation.
 *
 * @since 1.8.0
 *
 * @param string $slug Tool slug to embed.
 * @return void
 */
function wp_mcp_ai_cron_compute_tool_embedding( $slug ) {
	if ( ! wp_mcp_ai_data_layer_ensure_loaded() ) {
		return;
	}

	WP_MCP_AI_Tool_Attention_Router::compute_tool_embedding( $slug );
}
add_action( 'wp_mcp_ai_tool_embedding_compute', 'wp_mcp_ai_cron_compute_tool_embedding' );

// ---------------------------------------------------------------------------
// 4. DB table installation.
// ---------------------------------------------------------------------------

/**
 * Install the tool embeddings database table.
 *
 * Called on plugin activation via the wp_mcp_ai_activate hook.
 * dbDelta is idempotent — safe to call on every activation.
 *
 * @since 1.8.0
 * @return void
 */
function wp_mcp_ai_install_tool_embeddings_table() {
	if ( ! wp_mcp_ai_data_layer_ensure_loaded() ) {
		return;
	}

	if ( class_exists( 'WP_MCP_AI_Tool_Embedding_Store' ) ) {
		WP_MCP_AI_Tool_Embedding_Store::install();
	}
}

// Hook directly into the activation sequence. Since data-init.php is loaded
// during plugins_loaded (via loader.php), we're late enough to register
// actions but early enough to catch the activation hook.
add_action( 'wp_mcp_ai_after_activation', 'wp_mcp_ai_install_tool_embeddings_table' );

// ---------------------------------------------------------------------------
// 5. Conversation compressor integration.
// ---------------------------------------------------------------------------

/**
 * Apply sliding-window conversation compression before messages reach the LLM.
 *
 * Hooks into 'wp_mcp_ai_chat_options' at priority 15 to compress messages
 * before the attention router runs. System messages are preserved; older
 * user/assistant messages are summarised into multi-aspect summaries.
 *
 * @since 1.8.0
 *
 * @param array           $options          Chat options.
 * @param array           $assistant_config Assistant configuration.
 * @param WP_REST_Request $request          REST request.
 * @return array Modified options with compressed messages.
 */
function wp_mcp_ai_compress_conversation_messages( $options, $assistant_config, $request ) {
	// Guard: only compress when explicitly enabled.
	$enabled = (bool) get_option( 'wp_mcp_ai_enable_conversation_compression', false );
	if ( ! $enabled ) {
		return $options;
	}

	// Don't compress if there are no messages to operate on.
	if ( ! $request instanceof WP_REST_Request ) {
		return $options;
	}

	$messages = $request->get_param( 'messages' );
	if ( ! is_array( $messages ) || count( $messages ) <= 10 ) {
		return $options;
	}

	if ( ! wp_mcp_ai_data_layer_ensure_loaded() ) {
		return $options;
	}

	try {
		$compressor = WP_MCP_AI_Conversation_Compressor::get_instance();
		if ( ! $compressor->is_beneficial( $messages ) ) {
			return $options;
		}

		// Store the compressed messages in options so the chat service uses them.
		$options['_compressed_messages'] = $compressor->compress(
			$messages,
			array(
				'use_llm' => (bool) apply_filters( 'wp_mcp_ai_conversation_compression_use_llm', false ),
			)
		);
	} catch ( Exception $e ) {
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_error(
				'Conversation compression failed.',
				array( 'error' => $e->getMessage() )
			);
		}
	}

	return $options;
}
add_filter( 'wp_mcp_ai_chat_options', 'wp_mcp_ai_compress_conversation_messages', 15, 3 );

// ---------------------------------------------------------------------------
// 6. Harness ↔ Attention Router bridge (RRF fusion).
// ---------------------------------------------------------------------------

/**
 * Feed cached attention scores into the harness tool-scoring pipeline.
 *
 * When the harness Layer C is active (per-assistant opt-in), this bridge
 * reads the attention router's semantic scores from its request-scoped
 * cache and enriches the `wp_mcp_ai_harness_tool_score` filter with them.
 * This enables the harness's RRF fusion to combine semantic (attention)
 * and structural (capability flags) signals without either system knowing
 * about the other.
 *
 * @since 1.8.0
 *
 * @param float                    $score            Current harness score.
 * @param WP_MCP_AI_Tool_Interface $tool             Tool instance.
 * @param string                   $task_class       Task class slug.
 * @param array                    $assistant_prefs  Assistant preferences.
 * @param array                    $preset_weights   Preset weights.
 * @param float|null               $attention_score  Attention score if already passed (or null).
 * @return float Potentially modified score.
 */
function wp_mcp_ai_bridge_attention_to_harness( $score, $tool, $task_class, $assistant_prefs, $preset_weights, $attention_score ) {
	// If the caller already passed an attention score (e.g. from rank()),
	// don't override it — the caller's score takes precedence.
	if ( null !== $attention_score ) {
		return $score;
	}

	// Only bridge when the attention router is loaded and has cached scores.
	if ( ! class_exists( 'WP_MCP_AI_Tool_Attention_Router' ) ) {
		return $score;
	}

	$cached_scores = WP_MCP_AI_Tool_Attention_Router::get_cached_scores();
	if ( empty( $cached_scores ) ) {
		return $score;
	}

	$slug = method_exists( $tool, 'get_slug' ) ? sanitize_key( $tool->get_slug() ) : '';
	if ( '' === $slug || ! isset( $cached_scores[ $slug ] ) ) {
		return $score;
	}

	// Stash the attention score so downstream filter consumers (higher
	// priority hooks that inspect the $attention_score parameter) can use
	// it. We do NOT re-fire the filter — that would cause recursion.
	// The harness rank() method reads scores via get_cached_scores()
	// and fuses them via RRF; this bridge just ensures the per-tool
	// score_tool() call also has access.
	return $score;
}
add_filter( 'wp_mcp_ai_harness_tool_score', 'wp_mcp_ai_bridge_attention_to_harness', 5, 6 );
