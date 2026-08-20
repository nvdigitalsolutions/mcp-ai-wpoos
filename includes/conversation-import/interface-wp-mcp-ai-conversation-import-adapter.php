<?php
/**
 * Adapter contract for external conversation export formats.
 *
 * Each adapter knows how to recognise one export shape and how to turn it
 * into canonical {@see WP_MCP_AI_Conversation_Import_Conversation} objects.
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
 * Contract implemented by every conversation import adapter.
 *
 * Adapters operate on an already-decoded JSON structure (arrays/objects)
 * produced by {@see WP_MCP_AI_Conversation_Import_Format_Detector}. Keeping
 * decoding in one place lets the detector enforce size/memory guards before
 * any adapter logic runs.
 */
interface WP_MCP_AI_Conversation_Import_Adapter_Interface {

	/**
	 * Stable platform slug for this adapter (e.g. "chatgpt").
	 *
	 * Used in session keys, provenance metadata, and tool output.
	 *
	 * @return string
	 */
	public function get_platform();

	/**
	 * Whether this adapter can parse the given decoded JSON structure.
	 *
	 * Detection is deliberately cheap and structural (key presence / shapes),
	 * never a full validation pass.
	 *
	 * @param mixed $decoded Result of `json_decode( $contents, true )`.
	 * @return bool True when this adapter recognises the structure.
	 */
	public function supports_decoded( $decoded );

	/**
	 * Extract canonical conversations from the decoded export.
	 *
	 * Implementations should yield lazily so the manager can batch writes
	 * without materialising the full canonical list in memory.
	 *
	 * @param mixed $decoded    Result of `json_decode( $contents, true )`.
	 * @param array $options    Extraction options (e.g. "keep_hidden").
	 * @return \Traversable|\WP_Error Yields `WP_MCP_AI_Conversation_Import_Conversation`
	 *                                instances, or a WP_Error when the payload is
	 *                                structurally invalid for this format.
	 */
	public function extract( $decoded, array $options = array() );
}
