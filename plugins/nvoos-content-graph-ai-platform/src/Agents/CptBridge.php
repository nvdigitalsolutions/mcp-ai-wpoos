<?php
/**
 * Agent CPT bridge — wraps the base plugin's assistant CPT.
 *
 * The actual CPT registration lives in the base plugin's
 * `WP_MCP_AI_Assistant_CPT`. This bridge provides a
 * namespace-friendly accessor for Platform code that needs
 * agent configuration data.
 *
 * Extracted from `includes/assistants/class-wp-mcp-ai-assistant-cpt.php`.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\Agents
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Agents;

/**
 * Bridge to the base plugin's assistant CPT.
 */
final class CptBridge {

	/**
	 * Get agent configuration as an associative array.
	 *
	 * Delegates to `WP_MCP_AI_Assistant_CPT::get_assistant_configuration()`
	 * when available.
	 *
	 * @param int $agent_id Agent post ID.
	 * @return array<string, mixed>
	 */
	public static function getConfig( int $agent_id ): array {
		if ( ! $agent_id ) {
			return array();
		}

		if ( class_exists( 'WP_MCP_AI_Assistant_CPT' ) && method_exists( 'WP_MCP_AI_Assistant_CPT', 'get_assistant_configuration' ) ) {
			return \WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $agent_id );
		}

		// Fallback: build from raw post meta.
		return self::buildConfigFromMeta( $agent_id );
	}

	/**
	 * Build agent config from raw post meta when the CPT class is unavailable.
	 *
	 * @param int $agent_id Agent post ID.
	 * @return array<string, mixed>
	 */
	private static function buildConfigFromMeta( int $agent_id ): array {
		$config = array();

		$config['provider']    = get_post_meta( $agent_id, MetaKeys::PROVIDER, true );
		$config['model']       = get_post_meta( $agent_id, MetaKeys::MODEL, true );
		$config['temperature'] = get_post_meta( $agent_id, MetaKeys::TEMPERATURE, true );
		$config['tools']       = get_post_meta( $agent_id, MetaKeys::TOOLS, true );
		$config['skills']      = get_post_meta( $agent_id, MetaKeys::SKILLS, true );

		return array_filter( $config, static fn ( $v ) => '' !== $v && null !== $v );
	}

	/**
	 * Check if the agent post type is registered.
	 *
	 * @return bool
	 */
	public static function isPostTypeRegistered(): bool {
		return post_type_exists( Agents::POST_TYPE );
	}

	/** Private constructor — not instantiable. */
	private function __construct() {}
}
