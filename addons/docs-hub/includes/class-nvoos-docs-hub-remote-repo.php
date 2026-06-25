<?php
/**
 * NV oOS Docs Hub — Remote Repository Fetcher
 *
 * Discovers and fetches Markdown documentation files from public Git
 * repositories (currently GitHub) using the GitHub Trees API and the
 * raw-content CDN.
 *
 * Security model (mirrors WP_MCP_AI_Skill_Catalogue_Service::safe_get()):
 *  - HTTPS only.
 *  - Domain allowlist: api.github.com, raw.githubusercontent.com.
 *  - Resolves the hostname and rejects private / loopback / reserved IPs.
 *  - Pins the resolved IP via CURLOPT_RESOLVE (DNS-rebind defence) while
 *    keeping the hostname in the URL so TLS SNI + certificate SAN validation
 *    continue to work.
 *  - Redirects are disabled so the pinned host cannot be bypassed.
 *  - Response-size cap (4 MB) protects against memory exhaustion.
 *  - Fetched file content is stored locally in the uploads cache directory
 *    so subsequent rebuilds reuse the local copy without re-fetching.
 *
 * @package NV_oOS_Docs_Hub
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches documentation files from a public GitHub repository.
 *
 * @since 1.1.0
 */
class NV_oOS_Docs_Hub_Remote_Repo {

	/**
	 * Allowed fetch hosts (domain allowlist).
	 *
	 * Only these hosts may be contacted at runtime.
	 *
	 * @var string[]
	 */
	const ALLOWED_HOSTS = array(
		'api.github.com',
		'raw.githubusercontent.com',
	);

	/**
	 * Maximum response body accepted from a remote host (bytes).
	 *
	 * @var int
	 */
	const MAX_RESPONSE_BYTES = 4194304; // 4 MB.

	/**
	 * HTTP timeout for remote fetches (seconds).
	 *
	 * @var int
	 */
	const HTTP_TIMEOUT = 20;

	/**
	 * Local cache TTL for fetched file content (seconds).
	 * Defaults to 24 hours. Override via nvoos_docs_hub_remote_cache_ttl filter.
	 *
	 * @var int
	 */
	const CACHE_TTL = 86400; // 24 hours.

	/**
	 * Maximum number of .md files accepted from a single repository tree.
	 *
	 * Prevents a runaway repo from indexing thousands of files.
	 *
	 * @var int
	 */
	const MAX_FILES_PER_REPO = 500;

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Fetch all indexable entries from a remote GitHub repository.
	 *
	 * Each returned entry has the same shape as local scanner entries plus
	 * a `'content'` key containing the Markdown text (already written to the
	 * local cache) and a `'remote_url'` key for the "View on GitHub" link.
	 *
	 * @since 1.1.0
	 *
	 * @param array $repo_config {
	 *     Configuration for the remote repository.
	 *
	 *     @type string $owner  GitHub username or org.
	 *     @type string $repo   Repository name.
	 *     @type string $ref    Branch, tag, or commit SHA. Default 'HEAD'.
	 *     @type string $label  Human-readable label shown in the sidebar.
	 *     @type string $token  Optional GitHub personal access token (raises rate limits).
	 *     @type string $path   Optional subdirectory within the repo to restrict scanning.
	 *     @type bool   $force  Set true to bypass the local cache and re-fetch.
	 * }
	 * @return array|WP_Error Array of file entries on success, WP_Error on failure.
	 */
	public function fetch_entries( $repo_config ) {
		$owner = isset( $repo_config['owner'] ) ? sanitize_text_field( $repo_config['owner'] ) : '';
		$repo  = isset( $repo_config['repo'] ) ? sanitize_text_field( $repo_config['repo'] ) : '';
		$ref   = isset( $repo_config['ref'] ) ? sanitize_text_field( $repo_config['ref'] ) : 'HEAD';
		$label = isset( $repo_config['label'] ) ? sanitize_text_field( $repo_config['label'] ) : $owner . '/' . $repo;
		$token = isset( $repo_config['token'] ) ? (string) $repo_config['token'] : '';
		$path  = isset( $repo_config['path'] ) ? trim( sanitize_text_field( $repo_config['path'] ), '/' ) : '';
		$force = ! empty( $repo_config['force'] );

		if ( '' === $owner || '' === $repo ) {
			return new WP_Error(
				'nvoos_docs_hub_bad_repo',
				__( 'Remote repo config requires owner and repo fields.', 'nvoos-docs-hub' )
			);
		}

		// Validate that owner + repo only contain safe characters (letters, digits, hyphens, underscores, dots).
		if ( ! preg_match( '/^[a-zA-Z0-9_.\-]+$/', $owner ) || ! preg_match( '/^[a-zA-Z0-9_.\-]+$/', $repo ) ) {
			return new WP_Error(
				'nvoos_docs_hub_bad_repo',
				__( 'Remote repo owner/repo contains invalid characters.', 'nvoos-docs-hub' )
			);
		}

		// Step 1: resolve the default branch / commit for 'HEAD' refs.
		$resolved_ref = $this->resolve_ref( $owner, $repo, $ref, $token );
		if ( is_wp_error( $resolved_ref ) ) {
			return $resolved_ref;
		}

		// Step 2: fetch the Git tree.
		// When a path prefix is configured, fetch only the subtree rooted at that
		// path to avoid GitHub's tree-truncation limit on large monorepos.
		if ( '' !== $path ) {
			$subtree_sha = $this->resolve_subtree_sha( $owner, $repo, $resolved_ref, $token, $path );
			if ( is_wp_error( $subtree_sha ) ) {
				return $subtree_sha;
			}
			if ( null === $subtree_sha ) {
				// The configured path prefix directory does not exist in this ref.
				return array();
			}
			$tree = $this->fetch_tree( $owner, $repo, $subtree_sha, $token );
			if ( is_wp_error( $tree ) ) {
				return $tree;
			}
			// Tree items are already scoped to $path; no additional prefix filtering.
			$md_files = $this->filter_md_files( $tree, '', $repo_config, $path );
		} else {
			$tree = $this->fetch_tree( $owner, $repo, $resolved_ref, $token );
			if ( is_wp_error( $tree ) ) {
				return $tree;
			}
			$md_files = $this->filter_md_files( $tree, '', $repo_config, '' );
		}

		if ( empty( $md_files ) ) {
			return array(); // No markdown files — not an error.
		}

		// Step 3: fetch (or load from local cache) each file's content.
		$entries      = array();
		$count        = 0;
		$fetch_errors = array();
		$max          = (int) apply_filters( 'nvoos_docs_hub_remote_max_files', self::MAX_FILES_PER_REPO, $owner, $repo );

		foreach ( $md_files as $file_info ) {
			if ( $count >= $max ) {
				break;
			}

			// $file_path is relative to the fetched tree's root (i.e. relative to $path when set,
			// or relative to the repo root when no path prefix was configured).
			$file_path = $file_info['path'];
			$file_size = isset( $file_info['size'] ) ? (int) $file_info['size'] : 0;

			// Skip oversized files based on the size reported by the tree API.
			if ( $file_size > NV_oOS_Docs_Hub_Scanner::MAX_FILE_SIZE ) {
				continue;
			}

			// Full repo-relative path (needed for raw URL, cache key, and remote_url).
			$full_repo_path = '' !== $path ? $path . '/' . $file_path : $file_path;

			$cache_key     = $this->local_cache_key( $owner, $repo, $resolved_ref, $full_repo_path );
			$local_content = $force ? false : $this->get_cached_content( $cache_key );

			if ( false === $local_content ) {
				// $full_repo_path segments come from the GitHub tree API and are already URL-safe.
				$raw_url = 'https://raw.githubusercontent.com/'
					. rawurlencode( $owner ) . '/'
					. rawurlencode( $repo ) . '/'
					. rawurlencode( $resolved_ref ) . '/'
					. $full_repo_path;

				$headers = array();
				if ( '' !== $token ) {
					$headers['Authorization'] = 'Bearer ' . $token;
				}

				$content = $this->safe_get( $raw_url, $headers );
				if ( is_wp_error( $content ) ) {
					// Accumulate fetch failures so the admin gets a summary
					// rather than silently missing files.
					$code = $content->get_error_code();
					if ( ! isset( $fetch_errors[ $code ] ) ) {
						$fetch_errors[ $code ] = array(
							'code'    => $code,
							'message' => $content->get_error_message(),
							'count'   => 0,
						);
					}
					$fetch_errors[ $code ]['count']++;
					continue;
				}

				$this->set_cached_content( $cache_key, $content );
				$local_content = $content;
			}

			$entries[] = array(
				'path'          => $this->local_cache_path( $cache_key ),
				'source'        => 'remote',
				'plugin_name'   => $label,
				'relative_path' => $file_path, // Already relative to $path (prefix already stripped).
				'content'       => $local_content,
				'remote_url'    => 'https://github.com/'
					. rawurlencode( $owner ) . '/'
					. rawurlencode( $repo ) . '/blob/'
					. rawurlencode( $resolved_ref ) . '/'
					. $full_repo_path,
				'repo_owner'    => $owner,
				'repo_name'     => $repo,
				'repo_ref'      => $resolved_ref,
			);

			++$count;
		}

		// Surface accumulated fetch errors via an action so callers
		// (the scanner and rebuild pipeline) can log or display them.
		if ( ! empty( $fetch_errors ) ) {
			do_action(
				'nvoos_docs_hub_remote_fetch_warnings',
				$owner . '/' . $repo,
				$fetch_errors
			);
		}

		return $entries;
	}

	// -------------------------------------------------------------------------
	// GitHub API helpers
	// -------------------------------------------------------------------------

	/**
	 * Resolve a ref (branch name, tag, or 'HEAD') to a concrete commit SHA.
	 *
	 * Uses the GitHub Repos API: GET /repos/{owner}/{repo}/commits/{ref}
	 * This also validates that the owner/repo combination exists and is public
	 * (or accessible with the provided token) before we attempt tree traversal.
	 *
	 * @since 1.1.0
	 *
	 * @param string $owner GitHub owner.
	 * @param string $repo  GitHub repo name.
	 * @param string $ref   Branch name, tag, or 'HEAD'.
	 * @param string $token Optional bearer token.
	 * @return string|WP_Error Resolved SHA on success.
	 */
	private function resolve_ref( $owner, $repo, $ref, $token ) {
		$url = 'https://api.github.com/repos/'
			. rawurlencode( $owner ) . '/'
			. rawurlencode( $repo ) . '/commits/'
			. rawurlencode( $ref );

		$headers = array(
			'Accept' => 'application/vnd.github.sha',
		);
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$body = $this->safe_get( $url, $headers );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$sha = trim( $body );

		// The SHA endpoint returns a plain 40-char hex string.
		if ( ! preg_match( '/^[0-9a-f]{40}$/i', $sha ) ) {
			// Fallback: it may have returned JSON — try decoding.
			$decoded = json_decode( $body, true );
			if ( is_array( $decoded ) && ! empty( $decoded['sha'] ) ) {
				$sha = $decoded['sha'];
			}
		}

		if ( ! preg_match( '/^[0-9a-f]{40}$/i', $sha ) ) {
			// Cannot resolve to SHA — use the original ref as-is.
			return $ref;
		}

		return $sha;
	}

	/**
	 * Fetch the recursive Git tree for a repository at a given ref.
	 *
	 * Uses the GitHub Git Trees API: GET /repos/{owner}/{repo}/git/trees/{sha}?recursive=1
	 *
	 * @since 1.1.0
	 *
	 * @param string $owner     GitHub owner.
	 * @param string $repo      GitHub repo name.
	 * @param string $ref       Commit SHA or ref.
	 * @param string $token     Optional bearer token.
	 * @param bool   $recursive Whether to fetch the tree recursively (default true).
	 * @return array|WP_Error Array of tree items on success.
	 */
	/**
	 * Fetch a Git tree from the GitHub API.
	 *
	 * When `$recursive` is true and the response is truncated (GitHub's
	 * 1 000-item limit on recursive trees), a WP_Error with code
	 * `nvoos_docs_hub_tree_truncated` is returned so callers can surface
	 * a warning rather than silently presenting an incomplete file list.
	 *
	 * @since 1.1.0
	 *
	 * @param string $owner     GitHub owner.
	 * @param string $repo      GitHub repo name.
	 * @param string $ref       Commit SHA or ref.
	 * @param string $token     Optional bearer token.
	 * @param bool   $recursive Whether to fetch the tree recursively (default true).
	 * @return array|WP_Error Array of tree items on success.
	 */
	private function fetch_tree( $owner, $repo, $ref, $token, $recursive = true ) {
		$url = 'https://api.github.com/repos/'
			. rawurlencode( $owner ) . '/'
			. rawurlencode( $repo ) . '/git/trees/'
			. rawurlencode( $ref );

		if ( $recursive ) {
			$url .= '?recursive=1';
		}

		$headers = array(
			'Accept' => 'application/vnd.github+json',
		);
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$body = $this->safe_get( $url, $headers );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'nvoos_docs_hub_tree_parse_failed',
				__( 'Failed to parse GitHub tree API response.', 'nvoos-docs-hub' )
			);
		}

		// Detect rate-limiting (403 with specific message).
		if ( ! empty( $decoded['message'] ) ) {
			$msg = (string) $decoded['message'];
			if ( false !== stripos( $msg, 'API rate limit exceeded' ) || false !== stripos( $msg, 'rate limit' ) ) {
				return new WP_Error(
					'nvoos_docs_hub_rate_limited',
					sprintf(
						/* translators: %s: GitHub API error message */
						__( 'GitHub API rate limit reached: %s Add a Personal Access Token in Docs Hub settings for a higher limit (5 000 req/hr).', 'nvoos-docs-hub' ),
						esc_html( $msg )
					)
				);
			}
			return new WP_Error(
				'nvoos_docs_hub_tree_api_error',
				/* translators: %s: GitHub API error message */
				sprintf( __( 'GitHub API error: %s', 'nvoos-docs-hub' ), esc_html( $msg ) )
			);
		}

		// GitHub's recursive tree API truncates at 1 000 entries. When
		// truncated, the `tree` array is still returned but incomplete.
		// Surface this so admins can narrow the path prefix or split repos.
		if ( $recursive && ! empty( $decoded['truncated'] ) ) {
			$tree_count = isset( $decoded['tree'] ) && is_array( $decoded['tree'] ) ? count( $decoded['tree'] ) : 0;
			return new WP_Error(
				'nvoos_docs_hub_tree_truncated',
				sprintf(
					/* translators: 1: tree item count, 2: owner/repo name */
					__( 'The Git tree for %2$s is too large (%1$d entries) and was truncated by GitHub. Set a "Path prefix" in the repo settings to restrict scanning to a subdirectory (e.g. "docs"), or split the repository across multiple rows with different prefixes.', 'nvoos-docs-hub' ),
					$tree_count,
					$owner . '/' . $repo
				)
			);
		}

		return isset( $decoded['tree'] ) && is_array( $decoded['tree'] ) ? $decoded['tree'] : array();
	}

	/**
	 * Resolve the SHA of a subtree at a given path within the repository.
	 *
	 * Traverses the path segment by segment, fetching only the top-level of
	 * each tree to avoid the GitHub API's recursive-tree truncation limit that
	 * affects large monorepos. Returns null (not an error) when the path does
	 * not exist in the tree.
	 *
	 * @since 1.1.0
	 *
	 * @param string $owner GitHub owner.
	 * @param string $repo  GitHub repo name.
	 * @param string $ref   Resolved commit SHA or ref.
	 * @param string $token Optional bearer token.
	 * @param string $path  Slash-separated path (e.g. "docs" or "addons/pro/docs").
	 * @return string|null|WP_Error Subtree SHA on success, null if path not found, WP_Error on API failure.
	 */
	private function resolve_subtree_sha( $owner, $repo, $ref, $token, $path ) {
		$parts       = explode( '/', trim( $path, '/' ) );
		$current_sha = $ref;

		foreach ( $parts as $part ) {
			// Fetch the current level non-recursively.
			$tree = $this->fetch_tree( $owner, $repo, $current_sha, $token, false );
			if ( is_wp_error( $tree ) ) {
				return $tree;
			}

			$found_sha = null;
			foreach ( $tree as $item ) {
				$item_path = $item['path'] ?? '';
				if ( 'tree' === ( $item['type'] ?? '' ) && $item_path === $part ) {
					$found_sha = $item['sha'];
					break;
				}
			}

			if ( null === $found_sha ) {
				return null; // This path segment does not exist.
			}

			$current_sha = $found_sha;
		}

		return $current_sha;
	}

	/**
	 * Filter tree items to Markdown (and optionally .txt) files.
	 *
	 * @since 1.1.0
	 * @since 0.3.0 Added $repo_config / $path_in_repo arguments to honour
	 *              `selection_mode` ('all' | 'prefix' | 'selected') and the
	 *              user-configured `selected_paths` / `excluded_paths`.
	 *
	 * @param array  $tree         Git tree items from the API.
	 * @param string $path_prefix  Optional path prefix to restrict to.
	 * @param array  $repo_config  Full repo config (for selection_mode + path lists).
	 * @param string $path_in_repo Repo-relative path of the fetched subtree
	 *                             (so item paths can be reconstructed for
	 *                             selected_paths matching). Empty when the
	 *                             whole repo was fetched.
	 * @return array Filtered items.
	 */
	private function filter_md_files( $tree, $path_prefix, $repo_config = array(), $path_in_repo = '' ) {
		$selection_mode = isset( $repo_config['selection_mode'] ) ? (string) $repo_config['selection_mode'] : 'all';
		if ( ! in_array( $selection_mode, array( 'all', 'prefix', 'selected' ), true ) ) {
			$selection_mode = 'all';
		}
		$selected_paths = isset( $repo_config['selected_paths'] ) && is_array( $repo_config['selected_paths'] )
			? $repo_config['selected_paths']
			: array();
		$excluded_paths = isset( $repo_config['excluded_paths'] ) && is_array( $repo_config['excluded_paths'] )
			? $repo_config['excluded_paths']
			: array();

		$path_in_repo = trim( (string) $path_in_repo, '/' );

		$results = array();

		foreach ( $tree as $item ) {
			// Only blobs (files), not trees (directories).
			if ( 'blob' !== ( $item['type'] ?? '' ) ) {
				continue;
			}

			$item_path = $item['path'] ?? '';
			$ext       = strtolower( pathinfo( $item_path, PATHINFO_EXTENSION ) );

			if ( ! in_array( $ext, array( 'md', 'txt' ), true ) ) {
				continue;
			}

			// Apply optional path prefix restriction.
			if ( '' !== $path_prefix && 0 !== strpos( $item_path, $path_prefix . '/' ) ) {
				continue;
			}

			// Reconstruct the full repo-relative path so user-configured
			// `selected_paths` / `excluded_paths` (which are repo-relative)
			// match correctly even when a `path` prefix is in use.
			$full_repo_path = '' !== $path_in_repo ? $path_in_repo . '/' . $item_path : $item_path;

			// `selected` mode: only keep files matching the selection list.
			if ( 'selected' === $selection_mode ) {
				if ( empty( $selected_paths ) ) {
					continue;
				}
				if ( ! self::matches_path_list( $full_repo_path, $selected_paths ) ) {
					continue;
				}
			}

			// `excluded_paths` always applied (useful for 'all' and 'prefix' modes).
			if ( ! empty( $excluded_paths ) && self::matches_path_list( $full_repo_path, $excluded_paths ) ) {
				continue;
			}

			// Apply exclusions: defaults + filterable. Force-include filter
			// allows opting-in to vendored docs that the operator wants.
			$default_excluded = NV_oOS_Docs_Hub_Scanner::DEFAULT_EXCLUDED_GLOBS;
			$excluded         = apply_filters( 'nvoos_docs_hub_excluded_globs', $default_excluded );
			$force_included   = apply_filters( 'nvoos_docs_hub_force_include_globs', array() );

			$skip = false;
			foreach ( (array) $force_included as $glob ) {
				if ( fnmatch( $glob, $item_path ) || fnmatch( $glob, basename( $item_path ) ) ) {
					$skip      = false;
					$results[] = $item;
					continue 2;
				}
			}
			foreach ( (array) $excluded as $glob ) {
				if ( fnmatch( $glob, $item_path ) || fnmatch( $glob, basename( $item_path ) ) ) {
					$skip = true;
					break;
				}
				// Cheap path-segment check for `**/dir/*` style.
				if ( 0 === strpos( $glob, '**/' ) ) {
					$inner = substr( $glob, 3 );
					if ( fnmatch( $inner, $item_path ) ) {
						$skip = true;
						break;
					}
					$tail = $item_path;
					while ( false !== ( $pos = strpos( $tail, '/' ) ) ) {
						$tail = substr( $tail, $pos + 1 );
						if ( '' !== $tail && fnmatch( $inner, $tail ) ) {
							$skip = true;
							break 2;
						}
					}
				}
			}
			if ( $skip ) {
				continue;
			}

			$results[] = $item;
		}

		return $results;
	}

	/**
	 * Test whether a repo-relative path matches any entry in a path list.
	 *
	 * Each list entry is either:
	 *  - a literal file path (e.g. `docs/intro.md`) — exact match required;
	 *  - a directory (trailing `/`, e.g. `docs/guides/`) — recursive match
	 *    (any file beneath that directory matches).
	 *
	 * @since 0.3.0
	 *
	 * @param string   $path  Repo-relative file path.
	 * @param string[] $list  List of selected/excluded paths.
	 * @return bool
	 */
	public static function matches_path_list( $path, $list ) {
		$path = ltrim( (string) $path, '/' );
		foreach ( (array) $list as $entry ) {
			$entry = ltrim( (string) $entry, '/' );
			if ( '' === $entry ) {
				continue;
			}
			if ( '/' === substr( $entry, -1 ) ) {
				// Directory — recursive match.
				$dir = rtrim( $entry, '/' );
				if ( $path === $dir || 0 === strpos( $path, $dir . '/' ) ) {
					return true;
				}
			} elseif ( $path === $entry ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Public admin helper: list Markdown/txt files in a repo for the picker UI.
	 *
	 * Resolves the repo + ref, fetches the (recursive) Git tree, filters to
	 * `.md` / `.txt` blobs (with default exclusions applied), and returns a
	 * lightweight payload suitable for rendering in the admin tree picker.
	 *
	 * Results are cached in a transient for 10 minutes keyed by
	 * owner/repo/ref/path so repeated UI clicks don't hammer the GitHub API.
	 * Tokens are NOT included in the cache key — tokens only affect rate
	 * limits, not the tree contents (auth-gated repos return 404 without a
	 * valid token, which fails fast and is not cached).
	 *
	 * @since 0.3.0
	 *
	 * @param array $repo_config {
	 *     Configuration for the remote repository picker.
	 *
	 *     @type string $owner GitHub owner.
	 *     @type string $repo  GitHub repo name.
	 *     @type string $ref   Branch / tag / SHA (default 'HEAD').
	 *     @type string $path  Optional repo-relative subdirectory.
	 *     @type string $token Optional GitHub PAT.
	 *     @type bool   $force Bypass the transient cache.
	 * }
	 * @return array|WP_Error {
	 *     @type string   resolved_ref  Concrete commit SHA (or echo of $ref).
	 *     @type string   path          Echoed `path` config (may be '').
	 *     @type array[]  files         List of `{ path, size }` repo-relative entries.
	 * }
	 */
	public function fetch_tree_for_admin( $repo_config ) {
		$owner = isset( $repo_config['owner'] ) ? sanitize_text_field( $repo_config['owner'] ) : '';
		$repo  = isset( $repo_config['repo'] ) ? sanitize_text_field( $repo_config['repo'] ) : '';
		$ref   = isset( $repo_config['ref'] ) ? sanitize_text_field( $repo_config['ref'] ) : 'HEAD';
		$token = isset( $repo_config['token'] ) ? (string) $repo_config['token'] : '';
		$path  = isset( $repo_config['path'] ) ? trim( sanitize_text_field( $repo_config['path'] ), '/' ) : '';
		$force = ! empty( $repo_config['force'] );

		if ( '' === $owner || '' === $repo ) {
			return new WP_Error(
				'nvoos_docs_hub_bad_repo',
				__( 'Owner and repo are required.', 'nvoos-docs-hub' )
			);
		}
		if ( ! preg_match( '/^[a-zA-Z0-9_.\-]+$/', $owner ) || ! preg_match( '/^[a-zA-Z0-9_.\-]+$/', $repo ) ) {
			return new WP_Error(
				'nvoos_docs_hub_bad_repo',
				__( 'Owner / repo contain invalid characters.', 'nvoos-docs-hub' )
			);
		}

		$transient_key = 'nvoos_docs_hub_tree_' . md5(
			implode( '|', array( $owner, $repo, $ref, $path ) )
		);

		if ( ! $force ) {
			$cached = get_transient( $transient_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		// Step 1: resolve ref.
		$resolved_ref = $this->resolve_ref( $owner, $repo, $ref, $token );
		if ( is_wp_error( $resolved_ref ) ) {
			return $resolved_ref;
		}

		// Step 2: fetch tree (subtree if path is set).
		if ( '' !== $path ) {
			$subtree_sha = $this->resolve_subtree_sha( $owner, $repo, $resolved_ref, $token, $path );
			if ( is_wp_error( $subtree_sha ) ) {
				return $subtree_sha;
			}
			if ( null === $subtree_sha ) {
				$payload = array(
					'resolved_ref' => $resolved_ref,
					'path'         => $path,
					'files'        => array(),
				);
				set_transient( $transient_key, $payload, 10 * MINUTE_IN_SECONDS );
				return $payload;
			}
			$tree = $this->fetch_tree( $owner, $repo, $subtree_sha, $token );
		} else {
			$tree = $this->fetch_tree( $owner, $repo, $resolved_ref, $token );
		}

		if ( is_wp_error( $tree ) ) {
			return $tree;
		}

		// Filter to md/txt blobs honouring default exclusions only — admin
		// picker should see every candidate file, regardless of the user's
		// per-repo `selection_mode` (the picker is what populates that
		// list in the first place).
		$md_files = $this->filter_md_files( $tree, '', array( 'selection_mode' => 'all' ), $path );

		$files = array();
		foreach ( $md_files as $item ) {
			$rel = isset( $item['path'] ) ? (string) $item['path'] : '';
			if ( '' === $rel ) {
				continue;
			}
			$size = isset( $item['size'] ) ? (int) $item['size'] : 0;
			// Reconstruct the repo-relative path (consistent with how
			// `selected_paths` are stored — always repo-relative).
			$full    = '' !== $path ? $path . '/' . $rel : $rel;
			$files[] = array(
				'path' => $full,
				'size' => $size,
			);
		}

		usort(
			$files,
			static function ( $a, $b ) {
				return strcmp( $a['path'], $b['path'] );
			}
		);

		$payload = array(
			'resolved_ref' => $resolved_ref,
			'path'         => $path,
			'files'        => $files,
		);

		set_transient( $transient_key, $payload, 10 * MINUTE_IN_SECONDS );

		// When the caller forced a refresh we also drop the per-file content cache
		// for these files at this resolved ref, so the next rebuild will re-fetch
		// the latest blob content from the remote rather than reuse a stale copy.
		if ( $force ) {
			$this->clear_local_cache_for_files( $owner, $repo, $resolved_ref, $files );
		}

		return $payload;
	}

	/**
	 * Delete the per-file local content cache for a specific (owner, repo, ref) tuple.
	 *
	 * Used by the admin "Refresh" picker action so a user can force the indexer
	 * to re-fetch fresh blob content on the next rebuild.
	 *
	 * @since 0.3.6
	 *
	 * @param string $owner        GitHub owner.
	 * @param string $repo         GitHub repo name.
	 * @param string $resolved_ref Resolved commit SHA.
	 * @param array  $files        List of `{ path, size }` entries (`path` is repo-relative).
	 * @return int Number of cache files deleted.
	 */
	public function clear_local_cache_for_files( $owner, $repo, $resolved_ref, $files ) {
		$deleted = 0;
		if ( ! is_array( $files ) ) {
			return $deleted;
		}
		foreach ( $files as $file ) {
			$rel = is_array( $file ) && isset( $file['path'] ) ? (string) $file['path'] : '';
			if ( '' === $rel ) {
				continue;
			}
			$key  = $this->local_cache_key( $owner, $repo, $resolved_ref, $rel );
			$path = $this->local_cache_path( $key );
			if ( file_exists( $path ) && is_file( $path ) && @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				++$deleted;
			}
		}
		return $deleted;
	}

	// -------------------------------------------------------------------------
	// SSRF-safe HTTP fetch
	// -------------------------------------------------------------------------

	/**
	 * SSRF-safe HTTPS GET.
	 *
	 * Enforces:
	 *  - HTTPS-only scheme.
	 *  - Domain allowlist (api.github.com, raw.githubusercontent.com).
	 *  - Hostname resolves to a public (non-private/reserved) IP.
	 *  - DNS-rebind defence via CURLOPT_RESOLVE.
	 *  - Redirects disabled.
	 *  - Response size cap (4 MB).
	 *
	 * Mirrors the pattern from WP_MCP_AI_Skill_Catalogue_Service::safe_get().
	 *
	 * @since 1.1.0
	 *
	 * @param string   $url     Full HTTPS URL to fetch.
	 * @param string[] $headers Additional HTTP request headers.
	 * @return string|WP_Error Response body on success.
	 */
	public function safe_get( $url, $headers = array() ) {
		// --- Scheme check ---
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( 'https' !== $scheme ) {
			return new WP_Error(
				'nvoos_docs_hub_https_required',
				__( 'Only HTTPS URLs are supported for remote repository fetches.', 'nvoos-docs-hub' )
			);
		}

		// --- Domain allowlist ---
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( empty( $host ) ) {
			return new WP_Error(
				'nvoos_docs_hub_bad_host',
				__( 'Could not determine host for remote URL.', 'nvoos-docs-hub' )
			);
		}

		/**
		 * Filter the list of allowed hosts for remote documentation fetches.
		 *
		 * Use this only to add further public Git hosts (e.g. raw.github.com).
		 * Never add private/internal hosts.
		 *
		 * @since 1.1.0
		 *
		 * @param string[] $allowed_hosts List of allowed hostnames.
		 */
		$allowed_hosts = apply_filters( 'nvoos_docs_hub_remote_allowed_hosts', self::ALLOWED_HOSTS );
		if ( ! in_array( strtolower( $host ), $allowed_hosts, true ) ) {
			return new WP_Error(
				'nvoos_docs_hub_host_not_allowed',
				/* translators: %s: hostname */
				sprintf( __( 'Remote host "%s" is not in the docs-hub allowlist.', 'nvoos-docs-hub' ), esc_html( $host ) )
			);
		}

		// --- SSRF: DNS resolution + private-IP rejection ---
			// Resolve via dns_get_record so we honour both A (IPv4) and AAAA (IPv6) records.
			// Every returned address must pass the public-range filter, and we then pin
			// the first valid candidate via CURLOPT_RESOLVE to defeat DNS rebinding.
		try {
			$resolved_ip = $this->resolve_public_ip( $host );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'nvoos_docs_hub_dns_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'DNS resolution error for %s.', 'nvoos-docs-hub' ),
					esc_html( $host )
				)
			);
		}
		if ( is_wp_error( $resolved_ip ) ) {
			return $resolved_ip;
		}

		// --- DNS-rebind defence: pin the resolved IP at the cURL level ---
		$port = wp_parse_url( $url, PHP_URL_PORT );
		$port = $port ? (int) $port : 443;
		// CURLOPT_RESOLVE syntax differs for IPv6: hostname:port:[ipv6] (the IP must be bracketed).
		$is_ipv6       = false !== strpos( $resolved_ip, ':' );
		$resolve_entry = $host . ':' . $port . ':' . ( $is_ipv6 ? '[' . $resolved_ip . ']' : $resolved_ip );
		$curl_pin      = static function ( $handle ) use ( $resolve_entry ) {
			if ( is_resource( $handle ) || ( is_object( $handle ) && $handle instanceof \CurlHandle ) ) {
				curl_setopt( $handle, CURLOPT_RESOLVE, array( $resolve_entry ) );
			}
		};
		add_action( 'http_api_curl', $curl_pin, 10, 1 );

		$default_headers = array(
			'User-Agent' => 'NV-oOS-Docs-Hub/' . NVOOS_DOCS_HUB_VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . '; +https://nvdigitalsolutions.com/wpoos)',
			'Accept'     => 'text/plain, application/json, */*;q=0.5',
		);
		$request_headers = array_merge( $default_headers, is_array( $headers ) ? $headers : array() );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => self::HTTP_TIMEOUT,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_RESPONSE_BYTES,
				'headers'             => $request_headers,
				'sslverify'           => true,
			)
		);

		remove_action( 'http_api_curl', $curl_pin, 10 );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'nvoos_docs_hub_fetch_failed',
				/* translators: %s: error message */
				sprintf( __( 'Remote documentation fetch failed: %s', 'nvoos-docs-hub' ), $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'nvoos_docs_hub_fetch_http_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'Remote documentation host returned HTTP %d.', 'nvoos-docs-hub' ), $code ),
				array( 'status' => $code )
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::MAX_RESPONSE_BYTES ) {
			return new WP_Error(
				'nvoos_docs_hub_response_too_large',
				__( 'Remote documentation response exceeded the size cap.', 'nvoos-docs-hub' )
			);
		}

		return $body;
	}

	/**
	 * Resolve a hostname to a single public IP (A or AAAA), rejecting private,
	 * reserved, loopback, link-local, and unique-local-address (ULA) ranges.
	 *
	 * Uses `dns_get_record()` when available so AAAA/IPv6 addresses are also
	 * inspected; falls back to `gethostbyname()` (A-only) when DNS records
	 * cannot be queried (e.g. tests, restricted environments).
	 *
	 * Every returned address must pass FILTER_FLAG_NO_PRIV_RANGE and
	 * FILTER_FLAG_NO_RES_RANGE — if any record points at a private/reserved
	 * range we refuse the whole request rather than silently picking a
	 * "safe" sibling, which is a defence-in-depth measure against DNS
	 * rebinding tricks where one record is public and another is private.
	 *
	 * @since 0.3.6
	 *
	 * @param string $host Hostname (already validated against the allowlist).
	 * @return string|WP_Error Resolved public IP literal on success.
	 */
	private function resolve_public_ip( $host ) {
		// If the host is already an IP literal, just validate it.
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			if ( false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return new WP_Error(
					'nvoos_docs_hub_ssrf_blocked',
					__( 'Remote documentation host resolves to a private or reserved address.', 'nvoos-docs-hub' )
				);
			}
			return $host;
		}

		$candidates = array();

		if ( function_exists( 'dns_get_record' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- dns_get_record emits a warning on lookup failure; we want to fall through cleanly.
			$records = @dns_get_record( $host, DNS_A | DNS_AAAA );
			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( ! is_array( $record ) ) {
						continue;
					}
					if ( isset( $record['ip'] ) && is_string( $record['ip'] ) ) {
						$candidates[] = $record['ip'];
					}
					if ( isset( $record['ipv6'] ) && is_string( $record['ipv6'] ) ) {
						$candidates[] = $record['ipv6'];
					}
				}
			}
		}

		// Fallback: A-record only via gethostbyname().
		if ( empty( $candidates ) ) {
			$resolved = gethostbyname( $host );
			if ( $resolved !== $host ) {
				$candidates[] = $resolved;
			}
		}

		if ( empty( $candidates ) ) {
			return new WP_Error(
				'nvoos_docs_hub_dns_failed',
				__( 'Remote documentation host did not resolve.', 'nvoos-docs-hub' )
			);
		}

		// Every candidate must be a valid public IP. If ANY record points at a
		// private/reserved range we refuse — defence in depth against rebinding.
		$flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
		foreach ( $candidates as $ip ) {
			if ( false === filter_var( $ip, FILTER_VALIDATE_IP, $flags ) ) {
				return new WP_Error(
					'nvoos_docs_hub_ssrf_blocked',
					__( 'Remote documentation host resolves to a private or reserved address.', 'nvoos-docs-hub' )
				);
			}
		}

		// Prefer IPv4 when both are available (more reliable on legacy networks).
		foreach ( $candidates as $ip ) {
			if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				return $ip;
			}
		}
		return $candidates[0];
	}

	// -------------------------------------------------------------------------
	// Local content cache
	// -------------------------------------------------------------------------

	/**
	 * Derive a cache key for a specific remote file.
	 *
	 * @since 1.1.0
	 *
	 * @param string $owner    GitHub owner.
	 * @param string $repo     GitHub repo name.
	 * @param string $ref      Resolved commit SHA.
	 * @param string $filepath File path within the repo.
	 * @return string Cache key (filesystem-safe).
	 */
	private function local_cache_key( $owner, $repo, $ref, $filepath ) {
		return md5( $owner . '/' . $repo . '@' . $ref . ':' . $filepath );
	}

	/**
	 * Get the absolute path where a cached remote file is stored locally.
	 *
	 * @since 1.1.0
	 *
	 * @param string $cache_key MD5 cache key.
	 * @return string Absolute path to the local `.md` file.
	 */
	public function local_cache_path( $cache_key ) {
		$upload_info = wp_upload_dir();
		return $upload_info['basedir']
			. DIRECTORY_SEPARATOR . 'nvoos-docs-hub'
			. DIRECTORY_SEPARATOR . 'remote'
			. DIRECTORY_SEPARATOR . $cache_key . '.md';
	}

	/**
	 * Read cached remote file content from local storage.
	 *
	 * Returns false if the cache entry is absent or older than the TTL.
	 *
	 * @since 1.1.0
	 *
	 * @param string $cache_key MD5 cache key.
	 * @return string|false Content string on hit, false on miss.
	 */
	private function get_cached_content( $cache_key ) {
		$local_path = $this->local_cache_path( $cache_key );

		if ( ! file_exists( $local_path ) ) {
			return false;
		}

		$ttl   = (int) apply_filters( 'nvoos_docs_hub_remote_cache_ttl', self::CACHE_TTL );
		$mtime = filemtime( $local_path );
		if ( false !== $mtime && ( time() - $mtime ) > $ttl ) {
			// Cache expired.
			return false;
		}

		$content = file_get_contents( $local_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return ( false !== $content ) ? $content : false;
	}

	/**
	 * Store fetched remote file content in local storage.
	 *
	 * @since 1.1.0
	 *
	 * @param string $cache_key MD5 cache key.
	 * @param string $content   Markdown file content.
	 * @return bool True on success.
	 */
	private function set_cached_content( $cache_key, $content ) {
		$local_path = $this->local_cache_path( $cache_key );
		$dir        = dirname( $local_path );

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		// Write .htaccess guard once for the remote cache sub-directory.
		$htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$result = file_put_contents( $local_path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false !== $result;
	}
}
