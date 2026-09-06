<?php
/**
 * Adapter contract for external conversation export formats (Wave E4,
 * sub-cluster 6).
 *
 * Aligned port of the base plugin's
 * `WP_MCP_AI_Conversation_Import_Adapter_Interface`: byte-identical
 * contract — each adapter recognises one export shape and turns it into
 * canonical `Conversation` objects, operating on already-decoded JSON
 * so the format detector owns decoding and size guards.
 *
 * Documented deviations:
 *  - Class name/namespace — the platform's PSR-4 tree.
 *  - `WP_Error` is fully qualified (`\WP_Error`).
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\ConversationImport
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\ConversationImport;

/**
 * Contract implemented by every conversation import adapter.
 *
 * Adapters operate on an already-decoded JSON structure (arrays/objects)
 * produced by {@see FormatDetector}. Keeping decoding in one place lets
 * the detector enforce size/memory guards before any adapter logic runs.
 *
 * @since 2.1.0
 */
interface AdapterInterface {

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
	 * @param mixed $decoded Result of `json_decode( $contents, true )`.
	 * @param array $options Extraction options (e.g. "keep_hidden").
	 * @return \Traversable|\WP_Error Yields `Conversation` instances, or a
	 *                                WP_Error when the payload is structurally
	 *                                invalid for this format.
	 */
	public function extract( $decoded, array $options = array() );
}
