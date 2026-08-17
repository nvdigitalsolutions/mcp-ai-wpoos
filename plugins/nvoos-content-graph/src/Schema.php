<?php
declare(strict_types=1);

namespace NvoosContentGraph;

/**
 * Centralized constants for the NV oOS Content Graph plugin.
 *
 * Every option key, table name, hook name, nonce action,
 * and capability slug lives here. No magic strings anywhere else.
 *
 * @since 1.0.0
 */
final class Schema {

	// ─── Option keys ───────────────────────────────────────────
	public const OPTION_SETTINGS   = 'nvoos_content_graph_settings';
	public const OPTION_DB_VERSION = 'nvoos_content_graph_db_version';

	// ─── Custom tables ─────────────────────────────────────────
	public const TABLE_NODES          = 'nvoos_content_graph_nodes';
	public const TABLE_EDGES          = 'nvoos_content_graph_edges';
	public const TABLE_META           = 'nvoos_content_graph_meta';
	public const TABLE_REMOTE_SOURCES = 'nvoos_content_graph_remote_sources';
	public const TABLE_EMBEDDINGS     = 'nvoos_content_graph_embeddings';

	// ─── Action hooks ──────────────────────────────────────────
	public const ACTION_REGISTER_TOOLS          = 'nvoos_content_graph/register_tools';
	public const ACTION_REGISTER_REMOTE_SOURCES = 'nvoos_content_graph/register_remote_sources';
	public const ACTION_BEFORE_BUILD            = 'nvoos_content_graph/before_build';
	public const ACTION_AFTER_BUILD             = 'nvoos_content_graph/after_build';
	public const ACTION_SETTINGS_SAVED          = 'nvoos_content_graph/after_settings_saved';
	public const ACTION_MEMORY_STORED           = 'nvoos_content_graph/memory_stored';

	// ─── Filter hooks ──────────────────────────────────────────
	public const FILTER_DEFAULT_SETTINGS   = 'nvoos_content_graph/default_settings';
	public const FILTER_ALLOW_PRIVATE_URLS = 'nvoos_content_graph/allow_private_urls';
	public const FILTER_ENRICH_BUDGET      = 'nvoos_content_graph/enrich_budget';
	public const FILTER_RAG_CANDIDATES     = 'nvoos_content_graph/rag_candidates';

	// ─── Cron hooks ────────────────────────────────────────────
	public const CRON_BUILD  = 'nvoos_content_graph/cron_build';
	public const CRON_ENRICH = 'nvoos_content_graph/cron_enrich';

	// ─── Capabilities ──────────────────────────────────────────
	public const CAP_MANAGE_GRAPH = 'manage_options';

	// ─── Nonce actions ─────────────────────────────────────────
	public const NONCE_BUILD  = 'nvoos_content_graph_build_graph';
	public const NONCE_EXPORT = 'nvoos_content_graph_export';

	// ─── REST namespace ────────────────────────────────────────
	public const REST_NAMESPACE = 'nvoos-content-graph/v1';

	// ─── Transient prefix ──────────────────────────────────────
	public const TRANSIENT_PREFIX = 'nvoos_content_graph_';

	/**
	 * Return the default settings array.
	 *
	 * Note: AI-related settings (API keys, models, providers) are
	 * NOT in core defaults. They are registered by the respective
	 * AI addon plugins via the `nvoos_content_graph/default_settings` filter.
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
