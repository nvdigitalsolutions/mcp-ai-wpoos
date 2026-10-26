<?php
/**
 * Assistant prompt versioning (lite) — Proposal 091, Track B.
 *
 * Immutable prompt snapshots on assistant save, restore, and an explicit
 * `production` label. The industry pattern (Langfuse-style prompt registry)
 * distilled to the WordPress surface: versions are append-only, promotion is
 * the only way the production label moves, and restore is itself versioned
 * (undoable).
 *
 * Storage (assistant post meta):
 *   - `_wp_mcp_ai_prompt_versions`   — JSON array of immutable version entries.
 *   - `_wp_mcp_ai_prompt_production` — integer version number (0 = none).
 *
 * @package WP_MCP_AI
 * @since   1.2.13
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assistant prompt versioning (lite).
 *
 * @since 1.2.13
 */
class WP_MCP_AI_Prompt_Versions {

	/**
	 * Meta key holding the JSON-encoded version list.
	 *
	 * @since 1.2.13
	 * @var string
	 */
	const VERSION_META = '_wp_mcp_ai_prompt_versions';

	/**
	 * Meta key holding the promoted (production) version number.
	 *
	 * @since 1.2.13
	 * @var string
	 */
	const PRODUCTION_META = '_wp_mcp_ai_prompt_production';

	/**
	 * Maximum retained versions per assistant.
	 *
	 * @since 1.2.13
	 * @var int
	 */
	const MAX_VERSIONS = 50;

	/**
	 * System prompt meta key (owned by WP_MCP_AI_Assistant_CPT).
	 *
	 * @since 1.2.13
	 * @var string
	 */
	const META_SYSTEM_PROMPT = '_wp_mcp_ai_system_prompt';

	/**
	 * Temperature meta key (owned by WP_MCP_AI_Assistant_CPT).
	 *
	 * @since 1.2.13
	 * @var string
	 */
	const META_TEMPERATURE = '_wp_mcp_ai_temperature';

	/**
	 * Register the snapshot hook on the assistant CPT.
	 *
	 * @since 1.2.13
	 * @return void
	 */
	public static function init() {
		$post_type = class_exists( 'WP_MCP_AI_Assistant_CPT' ) ? WP_MCP_AI_Assistant_CPT::POST_TYPE : 'mcp_ai_assistant';
		add_action( 'save_post_' . $post_type, array( __CLASS__, 'maybe_snapshot' ), 20, 2 );
	}

	/**
	 * Snapshot the prompt on assistant save when it changed.
	 *
	 * Hooks `save_post_{post_type}` at priority 20 so the CPT's own save
	 * logic has already written the meta. Autosaves, revisions, trashed and
	 * auto-draft posts are skipped.
	 *
	 * @since 1.2.13
	 *
	 * @param int     $post_id Assistant post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public static function maybe_snapshot( $post_id, $post ) {
		if ( wp_is_post_autosave( $post ) || wp_is_post_revision( $post ) ) {
			return;
		}
		if ( in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return;
		}

		$prompt      = get_post_meta( $post_id, self::META_SYSTEM_PROMPT, true );
		$prompt      = is_string( $prompt ) ? $prompt : '';
		$temperature = get_post_meta( $post_id, self::META_TEMPERATURE, true );
		$temperature = is_numeric( $temperature ) ? (string) $temperature : '';

		$versions = self::get_versions( $post_id );

		if ( empty( $versions ) ) {
			// Baseline: first save with a non-empty prompt becomes v1.
			if ( '' === $prompt ) {
				return;
			}
		} else {
			$latest = $versions[ count( $versions ) - 1 ];
			if ( (string) $latest['prompt'] === $prompt && (string) $latest['temperature'] === $temperature ) {
				return;
			}
		}

		self::append( $post_id, $prompt, $temperature );
	}

	/**
	 * List all versions for an assistant, oldest first.
	 *
	 * @since 1.2.13
	 *
	 * @param int $post_id Assistant post ID.
	 * @return array[] Version entries: { v, prompt, temperature, created, author }.
	 */
	public static function get_versions( $post_id ) {
		$raw  = get_post_meta( $post_id, self::VERSION_META, true );
		$list = json_decode( (string) $raw, true );
		if ( ! is_array( $list ) ) {
			return array();
		}

		$clean = array();
		foreach ( $list as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['v'], $entry['prompt'] ) ) {
				continue;
			}
			$clean[] = array(
				'v'           => absint( $entry['v'] ),
				'prompt'      => (string) $entry['prompt'],
				'temperature' => isset( $entry['temperature'] ) && is_numeric( $entry['temperature'] ) ? (string) $entry['temperature'] : '',
				'created'     => isset( $entry['created'] ) ? absint( $entry['created'] ) : 0,
				'author'      => isset( $entry['author'] ) ? absint( $entry['author'] ) : 0,
			);
		}

		return $clean;
	}

	/**
	 * Get a single version entry.
	 *
	 * @since 1.2.13
	 *
	 * @param int $post_id Assistant post ID.
	 * @param int $version Version number.
	 * @return array|null Version entry or null when missing.
	 */
	public static function get_version( $post_id, $version ) {
		$version = absint( $version );
		foreach ( self::get_versions( $post_id ) as $entry ) {
			if ( $entry['v'] === $version ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Restore a version: writes its exact prompt/temperature back and
	 * snapshots the restore itself so it is undoable.
	 *
	 * @since 1.2.13
	 *
	 * @param int $post_id Assistant post ID.
	 * @param int $version Version number.
	 * @return array|WP_Error Success envelope `{ success, version, restored }` or WP_Error (404).
	 */
	public static function restore( $post_id, $version ) {
		$entry = self::get_version( $post_id, $version );
		if ( null === $entry ) {
			return new WP_Error(
				'wp_mcp_ai_prompt_version_not_found',
				__( 'Prompt version not found.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		update_post_meta( $post_id, self::META_SYSTEM_PROMPT, $entry['prompt'] );
		update_post_meta( $post_id, self::META_TEMPERATURE, $entry['temperature'] );

		$new_version = self::append( $post_id, $entry['prompt'], $entry['temperature'] );

		/**
		 * Fires after a prompt version is restored (and re-snapshotted).
		 *
		 * @since 1.2.13
		 *
		 * @param int   $post_id     Assistant post ID.
		 * @param int   $version     Restored version number.
		 * @param int   $new_version Newly created undo snapshot version.
		 */
		do_action( 'wp_mcp_ai_prompt_version_restored', $post_id, absint( $version ), $new_version );

		return array(
			'success'  => true,
			'version'  => $new_version,
			'restored' => absint( $version ),
		);
	}

	/**
	 * Promote a version to production — the only way the label moves.
	 *
	 * @since 1.2.13
	 *
	 * @param int $post_id Assistant post ID.
	 * @param int $version Version number.
	 * @return array|WP_Error Success envelope or WP_Error (404).
	 */
	public static function promote( $post_id, $version ) {
		$entry = self::get_version( $post_id, $version );
		if ( null === $entry ) {
			return new WP_Error(
				'wp_mcp_ai_prompt_version_not_found',
				__( 'Prompt version not found.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		update_post_meta( $post_id, self::PRODUCTION_META, $entry['v'] );

		/**
		 * Fires after a prompt version is promoted to production.
		 *
		 * @since 1.2.13
		 *
		 * @param int   $post_id Assistant post ID.
		 * @param array $entry   The promoted version entry.
		 */
		do_action( 'wp_mcp_ai_prompt_version_promoted', $post_id, $entry );

		return array(
			'success' => true,
			'version' => $entry['v'],
		);
	}

	/**
	 * Get the currently promoted (production) version number.
	 *
	 * @since 1.2.13
	 *
	 * @param int $post_id Assistant post ID.
	 * @return int Version number (0 = none promoted).
	 */
	public static function get_production( $post_id ) {
		return absint( get_post_meta( $post_id, self::PRODUCTION_META, true ) );
	}

	/**
	 * Append an immutable version entry (capped at MAX_VERSIONS).
	 *
	 * The first version is auto-promoted so `get_production()` always has a
	 * sane default; later promotions are explicit only.
	 *
	 * @since 1.2.13
	 *
	 * @param int    $post_id     Assistant post ID.
	 * @param string $prompt      System prompt snapshot.
	 * @param string $temperature Temperature snapshot (string to preserve exact bytes).
	 * @return int New version number.
	 */
	private static function append( $post_id, $prompt, $temperature ) {
		$versions = self::get_versions( $post_id );
		$next     = empty( $versions ) ? 1 : ( max( wp_list_pluck( $versions, 'v' ) ) + 1 );

		$entry = array(
			'v'           => $next,
			'prompt'      => $prompt,
			'temperature' => $temperature,
			'created'     => time(),
			'author'      => get_current_user_id(),
		);

		$versions[] = $entry;
		if ( count( $versions ) > self::MAX_VERSIONS ) {
			$versions = array_slice( $versions, count( $versions ) - self::MAX_VERSIONS );
		}

		// wp_slash() the JSON: update_metadata() unslashes on write, and the
		// read path unslashes again — without the double compensation the
		// escaped "\n" inside the JSON would be silently destroyed.
		update_post_meta( $post_id, self::VERSION_META, wp_slash( wp_json_encode( $versions ) ) );

		if ( 0 === self::get_production( $post_id ) ) {
			update_post_meta( $post_id, self::PRODUCTION_META, $next );
		}

		/**
		 * Fires after a new prompt version is snapshotted.
		 *
		 * @since 1.2.13
		 *
		 * @param int   $post_id Assistant post ID.
		 * @param array $entry   The new version entry.
		 */
		do_action( 'wp_mcp_ai_prompt_version_created', $post_id, $entry );

		return $next;
	}
}
