<?php
/**
 * Skill Catalogue Service.
 *
 * Provides remote-catalogue support for the Agent Skills system. A "catalogue"
 * is a public Git repository (initially GitHub) that ships SKILL.md files.
 * This service:
 *
 *  - Stores the list of registered catalogue sources in a WordPress option.
 *  - Fetches a manifest for each source — either via a top-level
 *    `catalogue.json` if the repo provides one, or by walking the GitHub
 *    Git Tree API to discover SKILL.md files.
 *  - Caches manifests in transients to avoid repeated network calls.
 *  - Installs an individual skill (with companion files) by funneling all
 *    writes through the existing `WP_MCP_AI_Skill_Registry::install_skill()`
 *    pipeline, which already enforces an extension allowlist and the
 *    decompression-bomb cap.
 *  - Performs SSRF-safe HTTPS fetches (private/reserved IP rejection,
 *    DNS-rebinding pinning, HTTPS-only).
 *
 * Security notes:
 *  - All remote URLs are validated to be HTTPS only and to not resolve to
 *    private/loopback/reserved IP ranges.
 *  - A response-size cap is enforced for every fetched body to prevent a
 *    catalogue from filling memory with a multi-GB file.
 *  - Skill installation goes through the registry's `install_skill()` so the
 *    extension allowlist and decompression cap apply identically to user
 *    uploads.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.11.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages skill catalogues — registered remote sources of SKILL.md files.
 *
 * @since 1.11.0
 */
class WP_MCP_AI_Skill_Catalogue_Service {

	/**
	 * Option name storing the registered catalogue sources.
	 *
	 * Stored shape: array<int, array{ id:string, label:string, type:string,
	 * owner:string, repo:string, ref:string, manifest_path:string,
	 * last_refreshed:int }>.
	 *
	 * @var string
	 */
	const OPTION_SOURCES = 'wp_mcp_ai_skill_catalogue_sources';

	/**
	 * Transient prefix used for cached manifests, suffixed with the source id.
	 *
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'wp_mcp_ai_skill_cat_';

	/**
	 * Default transient TTL for cached manifests (24 hours).
	 *
	 * @var int
	 */
	const DEFAULT_MANIFEST_TTL = DAY_IN_SECONDS;

	/**
	 * WP-Cron hook name for the daily refresh job.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'wp_mcp_ai_skill_catalogue_refresh';

	/**
	 * Maximum HTTP response body size (bytes) accepted from a catalogue host.
	 *
	 * Defends against a hostile catalogue serving a very large body. Tree
	 * API responses for typical skill repos are well under 1 MB; SKILL.md
	 * files even smaller.
	 *
	 * @var int
	 */
	const MAX_RESPONSE_BYTES = 4 * 1024 * 1024; // 4 MB.

	/**
	 * HTTP timeout used for every catalogue fetch (seconds).
	 *
	 * @var int
	 */
	const HTTP_TIMEOUT = 20;

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @since 1.11.0
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reset the singleton (test helper).
	 *
	 * @since 1.11.0
	 * @return void
	 */
	public static function reset() {
		self::$instance = null;
	}

	/**
	 * Built-in source definitions used to seed the option on first run.
	 *
	 * @since 1.11.0
	 * @return array
	 */
	public static function get_default_sources() {
		return array(
			array(
				'id'             => 'wp-agent-skills',
				'label'          => 'WordPress Developer Skills (Lonsdale201)',
				'type'           => 'github',
				'owner'          => 'Lonsdale201',
				'repo'           => 'wp-agent-skills',
				'ref'            => 'main',
				'manifest_path'  => '',
				'last_refreshed' => 0,
			),
			array(
				'id'             => 'anthropics-skills',
				'label'          => 'Anthropic Skills',
				'type'           => 'github',
				'owner'          => 'anthropics',
				'repo'           => 'skills',
				'ref'            => 'main',
				'manifest_path'  => '',
				'last_refreshed' => 0,
			),
			array(
				'id'             => 'awesome-agent-skills',
				'label'          => 'Awesome Agent Skills (VoltAgent)',
				'type'           => 'github',
				'owner'          => 'VoltAgent',
				'repo'           => 'awesome-agent-skills',
				'ref'            => 'main',
				'manifest_path'  => '',
				'last_refreshed' => 0,
			),
			array(
				'id'             => 'wsmits-agent-skills',
				'label'          => 'Agent Skills (WesleySmits)',
				'type'           => 'github',
				'owner'          => 'WesleySmits',
				'repo'           => 'agent-skills',
				'ref'            => 'main',
				'manifest_path'  => '',
				'last_refreshed' => 0,
			),
			array(
				'id'             => 'pm-skills',
				'label'          => 'PM Skills (phuryn)',
				'type'           => 'github',
				'owner'          => 'phuryn',
				'repo'           => 'pm-skills',
				'ref'            => 'main',
				'manifest_path'  => '',
				'last_refreshed' => 0,
			),
			array(
				'id'             => 'google-skills',
				'label'          => 'Google Agent Skills',
				'type'           => 'github',
				'owner'          => 'google',
				'repo'           => 'skills',
				'ref'            => 'main',
				'manifest_path'  => '',
				'last_refreshed' => 0,
			),
			array(
				'id'             => 'openai-skills',
				'label'          => 'OpenAI Agent Skills',
				'type'           => 'github',
				'owner'          => 'openai',
				'repo'           => 'skills',
				'ref'            => 'main',
				'manifest_path'  => '',
				'last_refreshed' => 0,
			),
			array(
				'id'             => 'googleworkspace-cli',
				'label'          => 'Google Workspace CLI',
				'type'           => 'github',
				'owner'          => 'googleworkspace',
				'repo'           => 'cli',
				'ref'            => 'main',
				'manifest_path'  => '',
				'last_refreshed' => 0,
			),
			array(
				'id'             => 'cloudflare-skills',
				'label'          => 'Cloudflare Agent Skills',
				'type'           => 'github',
				'owner'          => 'cloudflare',
				'repo'           => 'skills',
				'ref'            => 'main',
				'manifest_path'  => '',
				'last_refreshed' => 0,
			),
			array(
				'id'             => 'wordpress-agent-skills',
				'label'          => 'WordPress Agent Skills',
				'type'           => 'github',
				'owner'          => 'WordPress',
				'repo'           => 'agent-skills',
				'ref'            => 'trunk',
				'manifest_path'  => '',
				'last_refreshed' => 0,
			),
			array(
				'id'             => 'brave-search-skills',
				'label'          => 'Brave Search Skills',
				'type'           => 'github',
				'owner'          => 'brave',
				'repo'           => 'brave-search-skills',
				'ref'            => 'main',
				'manifest_path'  => '',
				'last_refreshed' => 0,
			),
		);
	}

	/**
	 * Return the registered catalogue sources, seeding defaults the first
	 * time the option is read.
	 *
	 * @since 1.11.0
	 * @return array
	 */
	public function get_sources() {
		$sources = get_option( self::OPTION_SOURCES, null );
		if ( null === $sources ) {
			$sources = self::get_default_sources();
			update_option( self::OPTION_SOURCES, $sources, false );
		}

		// Sources are filterable so site owners can add internal catalogues
		// without exposing the editor UI.
		$sources = apply_filters( 'wp_mcp_ai_skill_catalogue_sources', $sources );

		// Be defensive: enforce the expected shape on each entry.
		$sources = is_array( $sources ) ? array_values( array_filter( array_map( array( $this, 'normalize_source' ), $sources ) ) ) : array();

		return $sources;
	}

	/**
	 * Replace the persisted source list. Each entry is normalised and the
	 * resulting list deduplicated by id (last write wins).
	 *
	 * @since 1.11.0
	 * @param array $sources Sources to persist.
	 * @return array The normalised list that was saved.
	 */
	public function save_sources( $sources ) {
		if ( ! is_array( $sources ) ) {
			$sources = array();
		}

		$normalised = array();
		foreach ( $sources as $src ) {
			$norm = $this->normalize_source( $src );
			if ( null === $norm ) {
				continue;
			}
			$normalised[ $norm['id'] ] = $norm;
		}

		$values = array_values( $normalised );
		update_option( self::OPTION_SOURCES, $values, false );

		return $values;
	}

	/**
	 * Look up a source by id.
	 *
	 * @since 1.11.0
	 * @param string $id Source id.
	 * @return array|null
	 */
	public function get_source( $id ) {
		$id = sanitize_key( $id );
		foreach ( $this->get_sources() as $src ) {
			if ( $src['id'] === $id ) {
				return $src;
			}
		}
		return null;
	}

	/**
	 * Validate and normalise a single source entry. Returns null when the
	 * input is unrecoverably malformed (missing required fields).
	 *
	 * @since 1.11.0
	 * @param mixed $src Raw source.
	 * @return array|null
	 */
	public function normalize_source( $src ) {
		if ( ! is_array( $src ) ) {
			return null;
		}

		$type = isset( $src['type'] ) ? sanitize_key( $src['type'] ) : 'github';
		if ( 'github' !== $type ) {
			// Only github is supported in Phase 2.
			return null;
		}

		$owner = isset( $src['owner'] ) ? trim( (string) $src['owner'] ) : '';
		$repo  = isset( $src['repo'] ) ? trim( (string) $src['repo'] ) : '';
		// GitHub usernames/repo names: alphanumerics, hyphens, underscores, dots.
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $owner ) ||
			! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $repo ) ) {
			return null;
		}

		// ref: branch, tag, or commit SHA. Allow alphanum, hyphen, underscore,
		// dot, slash (for refs/heads/foo). Default to "main".
		$ref = isset( $src['ref'] ) ? trim( (string) $src['ref'] ) : '';
		if ( '' === $ref ) {
			$ref = 'main';
		}
		if ( ! preg_match( '#^[A-Za-z0-9._\-/]{1,100}$#', $ref ) ) {
			return null;
		}

		$id = isset( $src['id'] ) ? sanitize_key( $src['id'] ) : '';
		if ( '' === $id ) {
			$id = sanitize_key( $owner . '-' . $repo );
		}
		if ( '' === $id ) {
			return null;
		}

		$label = isset( $src['label'] ) ? sanitize_text_field( (string) $src['label'] ) : '';
		if ( '' === $label ) {
			$label = $owner . '/' . $repo;
		}

		$manifest_path = isset( $src['manifest_path'] ) ? ltrim( (string) $src['manifest_path'], '/' ) : '';
		// Block traversal anywhere in the path.
		if ( '' !== $manifest_path && ( false !== strpos( $manifest_path, '..' ) || ! preg_match( '#^[A-Za-z0-9._/\-]{1,200}$#', $manifest_path ) ) ) {
			$manifest_path = '';
		}

		$last_refreshed = isset( $src['last_refreshed'] ) ? (int) $src['last_refreshed'] : 0;

		return array(
			'id'             => $id,
			'label'          => $label,
			'type'           => $type,
			'owner'          => $owner,
			'repo'           => $repo,
			'ref'            => $ref,
			'manifest_path'  => $manifest_path,
			'last_refreshed' => $last_refreshed,
		);
	}

	/**
	 * Return the manifest for a source. Reads from cache by default; pass
	 * $force=true to bypass and re-fetch.
	 *
	 * Manifest shape: array{ source_id:string, ref_resolved:string,
	 * fetched_at:int, skills: array<int, array{ name:string, description:string,
	 * path:string, sha:string }> }
	 *
	 * @since 1.11.0
	 * @param string $source_id Source id.
	 * @param bool   $force     When true, bypass the transient cache.
	 * @return array|WP_Error
	 */
	public function get_manifest( $source_id, $force = false ) {
		$source = $this->get_source( $source_id );
		if ( null === $source ) {
			return new WP_Error(
				'wp_mcp_ai_skill_catalogue_unknown_source',
				/* translators: %s: source id */
				sprintf( __( 'Unknown skill catalogue source: %s', 'mcp-ai-wpoos-pro' ), $source_id )
			);
		}

		$cache_key = self::TRANSIENT_PREFIX . md5( $source['id'] . '|' . $source['ref'] . '|' . $source['manifest_path'] );
		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$manifest = $this->build_manifest( $source );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		// Persist last-refreshed timestamp on the source entry.
		$sources = $this->get_sources();
		foreach ( $sources as $idx => $src ) {
			if ( $src['id'] === $source['id'] ) {
				$sources[ $idx ]['last_refreshed'] = $manifest['fetched_at'];
				break;
			}
		}
		update_option( self::OPTION_SOURCES, $sources, false );

		$ttl = (int) apply_filters( 'wp_mcp_ai_skill_catalogue_manifest_ttl', self::DEFAULT_MANIFEST_TTL, $source );
		if ( $ttl < MINUTE_IN_SECONDS ) {
			$ttl = MINUTE_IN_SECONDS;
		}
		set_transient( $cache_key, $manifest, $ttl );

		return $manifest;
	}

	/**
	 * Build the manifest by either reading a `catalogue.json` (preferred when
	 * the source ships one) or by walking the GitHub Git Tree API for
	 * SKILL.md files.
	 *
	 * @since 1.11.0
	 * @param array $source Source array.
	 * @return array|WP_Error
	 */
	protected function build_manifest( $source ) {
		// 1) If the source declares a manifest path, try to fetch that first.
		// 2) Otherwise probe `catalogue.json` at the repo root.
		$candidate_paths = array();
		if ( ! empty( $source['manifest_path'] ) ) {
			$candidate_paths[] = $source['manifest_path'];
		} else {
			$candidate_paths[] = 'catalogue.json';
		}

		foreach ( $candidate_paths as $path ) {
			$json = $this->fetch_raw( $source, $path );
			if ( is_wp_error( $json ) ) {
				continue;
			}
			$decoded = json_decode( $json, true );
			if ( is_array( $decoded ) && isset( $decoded['skills'] ) && is_array( $decoded['skills'] ) ) {
				return array(
					'source_id'    => $source['id'],
					'ref_resolved' => $source['ref'],
					'fetched_at'   => time(),
					'discovered'   => false,
					'skills'       => $this->normalise_manifest_skills( $decoded['skills'] ),
				);
			}
		}

		// Fallback: discover via the GitHub tree API.
		return $this->discover_via_tree_api( $source );
	}

	/**
	 * Walk the GitHub Git Tree API to discover SKILL.md files, then fetch the
	 * frontmatter of each so the manifest carries real names + descriptions.
	 *
	 * @since 1.11.0
	 * @param array $source Source array.
	 * @return array|WP_Error
	 */
	protected function discover_via_tree_api( $source ) {
		$endpoint = sprintf(
			'https://api.github.com/repos/%s/%s/git/trees/%s?recursive=1',
			rawurlencode( $source['owner'] ),
			rawurlencode( $source['repo'] ),
			rawurlencode( $source['ref'] )
		);

		$body = $this->safe_get( $endpoint, array( 'Accept' => 'application/vnd.github+json' ) );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || empty( $decoded['tree'] ) || ! is_array( $decoded['tree'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_skill_catalogue_invalid_tree',
				__( 'GitHub Git Tree API returned an unexpected response.', 'mcp-ai-wpoos-pro' )
			);
		}

		$skills = array();
		foreach ( $decoded['tree'] as $entry ) {
			if ( ! isset( $entry['type'], $entry['path'] ) || 'blob' !== $entry['type'] ) {
				continue;
			}
			$path = (string) $entry['path'];
			if ( ! preg_match( '#(^|/)SKILL\.md$#', $path ) ) {
				continue;
			}
			// Only top-level skill folders: a path like `wordpress/wp-rest-api/SKILL.md` has the
			// skill folder as the parent of SKILL.md. We accept any depth as long as the parent
			// exists; the manifest exposes both the slug and the full path.
			$folder = trim( dirname( $path ), '.' );
			if ( '' === $folder || '.' === $folder ) {
				continue;
			}
			$slug = sanitize_key( basename( $folder ) );
			if ( '' === $slug ) {
				continue;
			}
			$skills[] = array(
				'name'        => $slug,
				'description' => '',
				'path'        => $folder,
				'sha'         => isset( $entry['sha'] ) ? sanitize_text_field( (string) $entry['sha'] ) : '',
			);
		}

		// Optionally enrich descriptions from frontmatter for the first N skills
		// to keep the manifest lightweight on huge repos. The cap is filterable.
		$enrich_cap = (int) apply_filters( 'wp_mcp_ai_skill_catalogue_enrich_cap', 60, $source );
		$enriched   = array();
		$count      = 0;
		foreach ( $skills as $skill ) {
			if ( $count < $enrich_cap ) {
				$desc = $this->fetch_description( $source, $skill['path'] . '/SKILL.md' );
				if ( ! is_wp_error( $desc ) ) {
					$skill['description'] = $desc;
				}
				++$count;
			}
			$enriched[] = $skill;
		}

		return array(
			'source_id'    => $source['id'],
			'ref_resolved' => $source['ref'],
			'fetched_at'   => time(),
			'discovered'   => true,
			'skills'       => $this->normalise_manifest_skills( $enriched ),
		);
	}

	/**
	 * Pull only the description out of a SKILL.md without keeping the full
	 * body in memory. Reads only the frontmatter section.
	 *
	 * @since 1.11.0
	 * @param array  $source Source array.
	 * @param string $path   Repo-relative path to SKILL.md.
	 * @return string|WP_Error
	 */
	protected function fetch_description( $source, $path ) {
		$body = $this->fetch_raw( $source, $path );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		// Only the first ~2 KB is needed for frontmatter parsing.
		$head = substr( (string) $body, 0, 4096 );
		if ( ! preg_match( '/\A---\s*\n(.*?)\n---/s', $head, $m ) ) {
			return '';
		}
		$fm = $m[1];
		if ( preg_match( '/^description\s*:\s*(.*)$/mi', $fm, $dm ) ) {
			$desc = trim( $dm[1] );
			$desc = trim( $desc, "\"'" );
			return wp_strip_all_tags( $desc );
		}
		return '';
	}

	/**
	 * Coerce a list of manifest skill entries into a stable shape.
	 *
	 * @since 1.11.0
	 * @param array $skills Raw entries.
	 * @return array
	 */
	protected function normalise_manifest_skills( $skills ) {
		$out = array();
		foreach ( $skills as $sk ) {
			if ( ! is_array( $sk ) ) {
				continue;
			}
			$name = isset( $sk['name'] ) ? sanitize_key( $sk['name'] ) : '';
			$path = isset( $sk['path'] ) ? trim( (string) $sk['path'] ) : '';
			if ( '' === $name || '' === $path ) {
				continue;
			}
			// Block traversal: paths must be relative and not contain "..".
			if ( false !== strpos( $path, '..' ) || 0 === strpos( $path, '/' ) ) {
				continue;
			}
			$out[] = array(
				'name'        => $name,
				'description' => isset( $sk['description'] ) ? wp_strip_all_tags( (string) $sk['description'] ) : '',
				'path'        => $path,
				'sha'         => isset( $sk['sha'] ) ? sanitize_text_field( (string) $sk['sha'] ) : '',
			);
		}
		return $out;
	}

	/**
	 * Install a single skill identified by its catalogue path. Funnels into
	 * `WP_MCP_AI_Skill_Registry::install_skill( $content, $extra_files )` so
	 * the existing extension allowlist + decompression cap apply.
	 *
	 * @since 1.11.0
	 * @param string $source_id Source id.
	 * @param string $skill_path Repo-relative path to the skill folder
	 *                           (e.g. `wordpress/wp-rest-api`).
	 * @return array|WP_Error Installed skill data or error.
	 */
	public function install_from_catalogue( $source_id, $skill_path ) {
		$source = $this->get_source( $source_id );
		if ( null === $source ) {
			return new WP_Error(
				'wp_mcp_ai_skill_catalogue_unknown_source',
				/* translators: %s: source id */
				sprintf( __( 'Unknown skill catalogue source: %s', 'mcp-ai-wpoos-pro' ), $source_id )
			);
		}

		$skill_path = trim( (string) $skill_path, "/ \t\n\r\0\x0B" );
		if ( '' === $skill_path
			|| false !== strpos( $skill_path, '..' )
			|| ! preg_match( '#^[A-Za-z0-9._/\-]{1,200}$#', $skill_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_skill_catalogue_bad_path',
				__( 'Invalid skill path.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Confirm the path is in the manifest so we never blindly fetch user-supplied paths.
		$manifest = $this->get_manifest( $source_id, false );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}
		$entry = null;
		foreach ( $manifest['skills'] as $sk ) {
			if ( $sk['path'] === $skill_path ) {
				$entry = $sk;
				break;
			}
		}
		if ( null === $entry ) {
			return new WP_Error(
				'wp_mcp_ai_skill_catalogue_not_in_manifest',
				__( 'The requested skill path is not present in the catalogue manifest. Refresh the catalogue and try again.', 'mcp-ai-wpoos-pro' )
			);
		}

		// 1) Fetch SKILL.md.
		$skill_md = $this->fetch_raw( $source, $skill_path . '/SKILL.md' );
		if ( is_wp_error( $skill_md ) ) {
			return $skill_md;
		}

		// 2) Optionally fetch known companion files. We only fetch a small
		// set of well-known names rather than walking the tree per-skill,
		// because doing so would multiply the API surface and is not
		// necessary for the v1 install flow.
		$companion_names = (array) apply_filters(
			'wp_mcp_ai_skill_catalogue_companion_files',
			array( 'reference.md', 'examples.md', 'NOTES.md', 'LICENSE' )
		);
		$extra_files     = array();
		foreach ( $companion_names as $companion ) {
			$companion = ltrim( (string) $companion, '/' );
			if ( '' === $companion || false !== strpos( $companion, '..' ) ) {
				continue;
			}
			$body = $this->fetch_raw( $source, $skill_path . '/' . $companion );
			if ( ! is_wp_error( $body ) && '' !== $body ) {
				$extra_files[ $companion ] = $body;
			}
		}

		// 3) Install via the registry's hardened pipeline.
		if ( ! class_exists( 'WP_MCP_AI_Skill_Registry' ) ) {
			return new WP_Error(
				'wp_mcp_ai_skill_registry_missing',
				__( 'Skill registry is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$registry = WP_MCP_AI_Skill_Registry::instance();
		$result   = $registry->install_skill( $skill_md, $extra_files );

		// Refresh the in-memory cache so the new skill is immediately
		// visible to callers (e.g. the Browse tab) without a page reload.
		if ( ! is_wp_error( $result ) ) {
			$registry->load_skills( true );
		}

		return $result;
	}

	/**
	 * Fetch a repo-relative file as a string from the source.
	 *
	 * @since 1.11.0
	 * @param array  $source Source array.
	 * @param string $path   Repo-relative path.
	 * @return string|WP_Error
	 */
	protected function fetch_raw( $source, $path ) {
		$path = ltrim( (string) $path, '/' );
		if ( '' === $path || false !== strpos( $path, '..' ) ) {
			return new WP_Error(
				'wp_mcp_ai_skill_catalogue_bad_path',
				__( 'Invalid file path.', 'mcp-ai-wpoos-pro' )
			);
		}

		$url = sprintf(
			'https://raw.githubusercontent.com/%s/%s/%s/%s',
			rawurlencode( $source['owner'] ),
			rawurlencode( $source['repo'] ),
			rawurlencode( $source['ref'] ),
			implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) )
		);

		return $this->safe_get( $url );
	}

	/**
	 * SSRF-safe HTTPS GET. Mirrors the controller's existing protections:
	 *  - HTTPS only.
	 *  - Resolve hostname; reject private/loopback/reserved IPs.
	 *  - Pin the resolved IP at the cURL level via CURLOPT_RESOLVE (DNS-rebind
	 *    defence) while preserving the hostname in the URL so TLS SNI and
	 *    certificate SAN validation continue to work.
	 *  - Disallow redirects so the pinned host cannot be bypassed.
	 *  - Enforce response-size cap.
	 *
	 * @since 1.11.0
	 * @param string $url     Full URL to fetch.
	 * @param array  $headers Extra headers.
	 * @return string|WP_Error Response body on success.
	 */
	public function safe_get( $url, $headers = array() ) {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( 'https' !== $scheme ) {
			return new WP_Error( 'wp_mcp_ai_skill_catalogue_https_required', __( 'Only HTTPS URLs are supported.', 'mcp-ai-wpoos-pro' ) );
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( empty( $host ) ) {
			return new WP_Error( 'wp_mcp_ai_skill_catalogue_bad_host', __( 'Could not determine host for catalogue URL.', 'mcp-ai-wpoos-pro' ) );
		}

		$resolved_ip = gethostbyname( $host );
		if ( $resolved_ip === $host && false === filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return new WP_Error( 'wp_mcp_ai_skill_catalogue_dns_failed', __( 'Catalogue host did not resolve.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( false === filter_var( $resolved_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return new WP_Error( 'wp_mcp_ai_skill_catalogue_ssrf', __( 'Catalogue host resolves to a private or reserved address.', 'mcp-ai-wpoos-pro' ) );
		}

		// DNS-rebind defence: keep the hostname in the URL (so TLS SNI and
		// certificate SAN matching work) but pin the DNS resolution at the
		// cURL level via CURLOPT_RESOLVE. Previous versions rewrote the URL
		// to use the resolved IP directly, which caused
		// `cURL error 60: SSL: no alternative certificate subject name`
		// because cURL validated the certificate against the IP literal.
		$port = wp_parse_url( $url, PHP_URL_PORT );
		$port = $port ? (int) $port : 443;

		$default_headers = array(
			'User-Agent' => 'WP-MCP-AI-Skill-Catalogue/' . ( defined( 'WP_MCP_AI_PRO_VERSION' ) ? WP_MCP_AI_PRO_VERSION : '1.0.0' ) . ' (WordPress/' . get_bloginfo( 'version' ) . ')',
			'Accept'     => 'text/plain, application/json, */*;q=0.5',
		);
		$headers         = array_merge( $default_headers, is_array( $headers ) ? $headers : array() );

		$resolve_entry = $host . ':' . $port . ':' . $resolved_ip;
		$curl_pin      = static function ( $handle ) use ( $resolve_entry ) {
			if ( is_resource( $handle ) || ( is_object( $handle ) && $handle instanceof \CurlHandle ) ) {
				curl_setopt( $handle, CURLOPT_RESOLVE, array( $resolve_entry ) );
			}
		};
		add_action( 'http_api_curl', $curl_pin, 10, 1 );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => self::HTTP_TIMEOUT,
				'redirection'         => 0, // Disallow redirects to keep DNS pinning effective.
				'limit_response_size' => self::MAX_RESPONSE_BYTES,
				'headers'             => $headers,
				'sslverify'           => true,
			)
		);

		remove_action( 'http_api_curl', $curl_pin, 10 );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_skill_catalogue_fetch_failed',
				/* translators: %s: error message */
				sprintf( __( 'Catalogue fetch failed: %s', 'mcp-ai-wpoos-pro' ), $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'wp_mcp_ai_skill_catalogue_http_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'Catalogue host returned HTTP %d.', 'mcp-ai-wpoos-pro' ), $code ),
				array( 'status' => $code )
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::MAX_RESPONSE_BYTES ) {
			return new WP_Error(
				'wp_mcp_ai_skill_catalogue_too_large',
				__( 'Catalogue response exceeded the size cap.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $body;
	}

	/**
	 * Refresh every registered source's manifest. Intended to be called from
	 * the daily WP-Cron handler.
	 *
	 * @since 1.11.0
	 * @return array Per-source result map.
	 */
	public function refresh_all() {
		$results = array();
		foreach ( $this->get_sources() as $src ) {
			$res                   = $this->get_manifest( $src['id'], true );
			$results[ $src['id'] ] = is_wp_error( $res ) ? array(
				'ok'    => false,
				'error' => $res->get_error_message(),
			) : array(
				'ok'    => true,
				'count' => count( $res['skills'] ),
			);
		}
		return $results;
	}

	/**
	 * Compare an installed skill against a catalogue entry to surface a
	 * "version available" hint. Returns true when the catalogue lists a
	 * different SHA than the one stored in the index, indicating an update.
	 *
	 * @since 1.11.0
	 * @param string $source_id  Source id.
	 * @param string $skill_name Installed skill slug.
	 * @return bool|null True if an update is available, false if up-to-date,
	 *                   null when the skill is not found in the catalogue.
	 */
	public function has_update( $source_id, $skill_name ) {
		$manifest = $this->get_manifest( $source_id, false );
		if ( is_wp_error( $manifest ) ) {
			return null;
		}
		foreach ( $manifest['skills'] as $entry ) {
			if ( $entry['name'] === $skill_name ) {
				if ( ! class_exists( 'WP_MCP_AI_Skill_Registry' ) ) {
					return null;
				}
				$registry  = WP_MCP_AI_Skill_Registry::instance();
				$installed = $registry->get_skill( $skill_name );
				if ( null === $installed ) {
					return null;
				}
				$installed_sha = isset( $installed['metadata']['source-sha'] ) ? (string) $installed['metadata']['source-sha'] : '';
				if ( '' === $entry['sha'] || '' === $installed_sha ) {
					// Cannot compare without both sides — treat as up-to-date.
					return false;
				}
				return $installed_sha !== $entry['sha'];
			}
		}
		return null;
	}

	/**
	 * Schedule (or re-schedule) the daily refresh cron.
	 *
	 * @since 1.11.0
	 * @return void
	 */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$cadence = (string) apply_filters( 'wp_mcp_ai_skill_catalogue_refresh_cadence', 'daily' );
			wp_schedule_event( time() + HOUR_IN_SECONDS, $cadence, self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the daily refresh cron.
	 *
	 * @since 1.11.0
	 * @return void
	 */
	public static function unschedule_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * WP-Cron handler — refresh all registered sources.
	 *
	 * @since 1.11.0
	 * @return void
	 */
	public static function handle_cron() {
		self::instance()->refresh_all();
	}
}
