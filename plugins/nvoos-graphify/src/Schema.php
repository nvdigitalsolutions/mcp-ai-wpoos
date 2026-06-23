<?php
declare(strict_types=1);

namespace NvoosGraphify;

/**
 * Centralized constants for the NV oOS Graphify plugin.
 *
 * Every option key, table name, hook name, nonce action,
 * and capability slug lives here. No magic strings anywhere else.
 *
 * @since 1.0.0
 */
final class Schema {

	// ─── Option keys ───────────────────────────────────────────
	public const OPTION_SETTINGS   = 'nvoos_graphify_settings';
	public const OPTION_DB_VERSION = 'nvoos_graphify_db_version';

	// ─── Custom tables ─────────────────────────────────────────
	public const TABLE_NODES          = 'nvoos_graphify_nodes';
	public const TABLE_EDGES          = 'nvoos_graphify_edges';
	public const TABLE_META           = 'nvoos_graphify_meta';
	public const TABLE_REMOTE_SOURCES = 'nvoos_graphify_remote_sources';
	public const TABLE_EMBEDDINGS     = 'nvoos_graphify_embeddings';

	// ─── Action hooks ──────────────────────────────────────────
	public const ACTION_REGISTER_TOOLS          = 'nvoos_graphify/register_tools';
	public const ACTION_REGISTER_REMOTE_SOURCES = 'nvoos_graphify/register_remote_sources';
	public const ACTION_BEFORE_BUILD            = 'nvoos_graphify/before_build';
	public const ACTION_AFTER_BUILD             = 'nvoos_graphify/after_build';
	public const ACTION_SETTINGS_SAVED          = 'nvoos_graphify/after_settings_saved';
	public const ACTION_MEMORY_STORED           = 'nvoos_graphify/memory_stored';

	// ─── Filter hooks ──────────────────────────────────────────
	public const FILTER_DEFAULT_SETTINGS   = 'nvoos_graphify/default_settings';
	public const FILTER_ALLOW_PRIVATE_URLS = 'nvoos_graphify/allow_private_urls';
	public const FILTER_ENRICH_BUDGET      = 'nvoos_graphify/enrich_budget';
	public const FILTER_RAG_CANDIDATES     = 'nvoos_graphify/rag_candidates';

	// ─── Cron hooks ────────────────────────────────────────────
	public const CRON_BUILD  = 'nvoos_graphify/cron_build';
	public const CRON_ENRICH = 'nvoos_graphify/cron_enrich';

	// ─── Capabilities ──────────────────────────────────────────
	public const CAP_MANAGE_GRAPH = 'manage_options';

	// ─── Nonce actions ─────────────────────────────────────────
	public const NONCE_BUILD  = 'nvoos_graphify_build_graph';
	public const NONCE_EXPORT = 'nvoos_graphify_export';

	// ─── REST namespace ────────────────────────────────────────
	public const REST_NAMESPACE = 'nvoos-graphify/v1';

	// ─── Transient prefix ──────────────────────────────────────
	public const TRANSIENT_PREFIX = 'nvoos_graphify_';

	/**
	 * Return the default settings array.
	 *
	 * Note: AI-related settings (API keys, models, providers) are
	 * NOT in core defaults. They are registered by the respective
	 * AI addon plugins via the `nvoos_graphify/default_settings` filter.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,mixed>
	 */
	public static function defaultSettings(): array {
		$defaults = array(
			'enabled'                  => true,
			'auto_rebuild'             => true,
			'rebuild_schedule'         => 'weekly',
			'post_types'               => array( 'post', 'page' ),
			'include_terms'            => true,
			'include_users'            => true,
			'schema_injection'         => true,
			'related_content'          => true,
			'semantic_extraction'      => false,
			'incremental_builds'       => false,
			'openai_api_key'           => '',
			'cytoscape_height'         => '600px',
			'max_display_nodes'        => 300,
			'max_related'              => 5,
			'remote_enrich_enabled'    => false,
			'remote_enrich_budget'     => 50,
			'remote_enrich_async'      => false,
			'embeddings_enabled'       => false,
			'embeddings_model'         => 'text-embedding-3-small',
			'excluded_post_types'      => array(),
			'extra_post_types'         => array(),
			'external_tables'          => array(),
			'disabled_external_tables' => array(),
		);

		return apply_filters( self::FILTER_DEFAULT_SETTINGS, $defaults );
	}

	/** Private constructor — not instantiable. */
	private function __construct() {}
}
