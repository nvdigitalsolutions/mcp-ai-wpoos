<?php
/**
 * Embedding Layer Initialization
 *
 * Boots the content and context embedding storage services and wires their
 * DB table installation to the plugin activation hook.
 *
 * This file is loaded by includes/bootstrap/loader.php and wires:
 * - DB table creation for content embeddings on activation
 * - DB table creation for context embeddings on activation
 * - Auto-embedding of agent contexts when stored
 * - Auto-embedding of WordPress content when saved
 *
 * All services degrade gracefully when dependencies (vector service,
 * OpenAI API key, etc.) are unavailable.
 *
 * @package WP_MCP_AI
 * @since   1.9.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// 1. Lazy-load the content embedding store class on first use.
// ---------------------------------------------------------------------------

/**
 * Ensure the content embedding store class is loaded.
 *
 * Called on-demand by the activation hook below. Avoids loading
 * the class on every request when the feature is unused.
 *
 * @since 1.9.0
 * @return bool True if the class is available.
 */
function wp_mcp_ai_content_embedding_ensure_loaded() {
	static $loaded = false;
	if ( $loaded ) {
		return true;
	}

	// Check that the plugin path constant is available.
	if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
		return false;
	}

	$path = WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-content-embedding-store.php';
	if ( file_exists( $path ) && ! class_exists( 'WP_MCP_AI_Content_Embedding_Store' ) ) {
		require_once $path;
	}

	$loaded = true;
	return class_exists( 'WP_MCP_AI_Content_Embedding_Store' );
}

// ---------------------------------------------------------------------------
// 2. DB table installation.
// ---------------------------------------------------------------------------

/**
 * Install the content embeddings database table.
 *
 * Called on plugin activation via the wp_mcp_ai_after_activation hook.
 * dbDelta is idempotent — safe to call on every activation.
 *
 * @since 1.9.0
 * @return void
 */
function wp_mcp_ai_install_content_embeddings_table() {
	if ( ! wp_mcp_ai_content_embedding_ensure_loaded() ) {
		return;
	}

	if ( class_exists( 'WP_MCP_AI_Content_Embedding_Store' ) ) {
		WP_MCP_AI_Content_Embedding_Store::install();
	}
}

// Hook directly into the activation sequence. Since content-embedding-init.php
// is loaded during plugins_loaded (via loader.php), we're late enough to register
// actions but early enough to catch the activation hook.
add_action( 'wp_mcp_ai_after_activation', 'wp_mcp_ai_install_content_embeddings_table' );

// ---------------------------------------------------------------------------
// 3. Context embeddings table installation.
// ---------------------------------------------------------------------------

/**
 * Ensure the context embedding store class is loaded.
 *
 * Called on-demand by the activation hook below.
 *
 * @since 1.9.0
 * @return bool True if the class is available.
 */
function wp_mcp_ai_context_embedding_ensure_loaded() {
	static $loaded = false;
	if ( $loaded ) {
		return true;
	}

	if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
		return false;
	}

	$path = WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-context-embedding-store.php';
	if ( file_exists( $path ) && ! class_exists( 'WP_MCP_AI_Context_Embedding_Store' ) ) {
		require_once $path;
	}

	$loaded = true;
	return class_exists( 'WP_MCP_AI_Context_Embedding_Store' );
}

/**
 * Install the context embeddings database table.
 *
 * Called on plugin activation via the wp_mcp_ai_after_activation hook.
 * dbDelta is idempotent — safe to call on every activation.
 *
 * @since 1.9.0
 * @return void
 */
function wp_mcp_ai_install_context_embeddings_table() {
	if ( ! wp_mcp_ai_context_embedding_ensure_loaded() ) {
		return;
	}

	if ( class_exists( 'WP_MCP_AI_Context_Embedding_Store' ) ) {
		WP_MCP_AI_Context_Embedding_Store::install();
	}
}
add_action( 'wp_mcp_ai_after_activation', 'wp_mcp_ai_install_context_embeddings_table' );

// ---------------------------------------------------------------------------
// 4. Auto-embed agent contexts on storage.
// ---------------------------------------------------------------------------

/**
 * Automatically generate and store an embedding when a context is saved.
 *
 * Hooks into the wp_mcp_ai_after_context_stored action to asynchronously
 * pre-compute the embedding vector for the stored context.
 *
 * @since 1.9.0
 *
 * @param array $context_record The stored context record.
 * @return void
 */
function wp_mcp_ai_auto_embed_context( $context_record ) {
	if ( ! wp_mcp_ai_context_embedding_ensure_loaded() ) {
		return;
	}

	if ( ! class_exists( 'WP_MCP_AI_Vector_Context_Service' ) ) {
		return;
	}

	$context_id = isset( $context_record['context_id'] ) ? sanitize_text_field( $context_record['context_id'] ) : '';
	$agent_id   = isset( $context_record['agent_id'] ) ? absint( $context_record['agent_id'] ) : 0;

	if ( '' === $context_id || 0 === $agent_id ) {
		return;
	}

	// Build the text to embed from context data.
	$text = '';
	if ( isset( $context_record['data']['title'] ) ) {
		$text .= $context_record['data']['title'] . ' ';
	}
	if ( isset( $context_record['data']['content'] ) ) {
		$text .= $context_record['data']['content'];
	}
	$text = trim( $text );

	if ( '' === $text ) {
		return;
	}

	// Use Action Scheduler for async embedding when available, otherwise
	// do it synchronously (acceptable for single context storage).
	if ( function_exists( 'as_enqueue_async_action' ) ) {
		as_enqueue_async_action(
			'wp_mcp_ai_embed_context_async',
			array(
				'context_id' => $context_id,
				'agent_id'   => $agent_id,
				'text'       => $text,
			),
			'wp_mcp_ai_embeddings'
		);
	} else {
		wp_mcp_ai_embed_context_sync( $context_id, $agent_id, $text );
	}
}

/**
 * Synchronously embed a context and store the vector.
 *
 * @since 1.9.0
 *
 * @param string $context_id Context identifier.
 * @param int    $agent_id   Agent identifier.
 * @param string $text       Text to embed.
 * @return bool True on success.
 */
function wp_mcp_ai_embed_context_sync( $context_id, $agent_id, $text ) {
	try {
		$svc = WP_MCP_AI_Vector_Context_Service::get_instance();

		// Resolve the active provider for cache-key scoping.
		$provider = $svc->get_embedding_provider();
		if ( is_wp_error( $provider ) ) {
			return false;
		}

		$provider_id = $provider->get_id();
		$model       = $provider->get_model();

		// Skip if already fresh.
		if ( WP_MCP_AI_Context_Embedding_Store::is_fresh( $context_id, $agent_id, $provider_id, $model, $text ) ) {
			return true;
		}

		$vector = $svc->embed_context( $text );
		if ( is_wp_error( $vector ) || ! is_array( $vector ) ) {
			return false;
		}

		return WP_MCP_AI_Context_Embedding_Store::store(
			$context_id,
			$agent_id,
			$vector,
			$provider_id,
			$model,
			$text
		);
	} catch ( \Throwable $e ) {
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_error(
				'Failed to embed context synchronously.',
				array(
					'context_id' => $context_id,
					'error'      => $e->getMessage(),
				)
			);
		}
		return false;
	}
}

/**
 * Async cron handler for context embedding.
 *
 * @since 1.9.0
 *
 * @param string $context_id Context identifier.
 * @param int    $agent_id   Agent identifier.
 * @param string $text       Text to embed.
 * @return void
 */
function wp_mcp_ai_handle_embed_context_async( $context_id, $agent_id, $text ) {
	wp_mcp_ai_embed_context_sync( $context_id, $agent_id, $text );
}
add_action( 'wp_mcp_ai_embed_context_async', 'wp_mcp_ai_handle_embed_context_async', 10, 3 );

// Hook auto-embedding into the context storage lifecycle.
// The Agent Context Manager fires this action after a successful store.
add_action( 'wp_mcp_ai_after_context_stored', 'wp_mcp_ai_auto_embed_context', 10, 1 );

// ---------------------------------------------------------------------------
// 5. Content auto-embedding on post save/delete.
// ---------------------------------------------------------------------------

/**
 * Bootstrap the content embedding service.
 *
 * Lazy-loads the class and registers its WordPress hooks (save_post,
 * delete_post, Action Scheduler batch hook). Called once on init.
 *
 * @since 1.9.0
 * @return void
 */
function wp_mcp_ai_bootstrap_content_embedding_service() {
	if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
		return;
	}

	$path = WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-content-embedding-service.php';
	if ( file_exists( $path ) && ! class_exists( 'WP_MCP_AI_Content_Embedding_Service' ) ) {
		require_once $path;
	}

	if ( class_exists( 'WP_MCP_AI_Content_Embedding_Service' ) ) {
		WP_MCP_AI_Content_Embedding_Service::get_instance()->register_hooks();
	}
}

// Register hooks on init, but only when embeddings are enabled.
// This avoids loading the service on every frontend request.
$wp_mcp_ai_embedding_enabled = (bool) apply_filters( 'wp_mcp_ai_content_embeddings_enabled', true );
if ( $wp_mcp_ai_embedding_enabled ) {
	add_action( 'init', 'wp_mcp_ai_bootstrap_content_embedding_service', 20 );
}

// ---------------------------------------------------------------------------
// 6. HNSW index bootstrap (lazy, on first use).
// ---------------------------------------------------------------------------

/**
 * Ensure the HNSW index class is available.
 *
 * Called lazily by the vector context service when HNSW-accelerated
 * search is requested.
 *
 * @since 1.9.0
 * @return bool True if the class is available.
 */
function wp_mcp_ai_hnsw_ensure_loaded() {
	static $loaded = false;
	if ( $loaded ) {
		return true;
	}

	if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
		return false;
	}

	$path = WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-hnsw-index.php';
	if ( file_exists( $path ) && ! class_exists( 'WP_MCP_AI_HNSW_Index' ) ) {
		require_once $path;
	}

	$loaded = true;
	return class_exists( 'WP_MCP_AI_HNSW_Index' );
}
