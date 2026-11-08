<?php
/**
 * REST — stored redirect rules for the forged static site (Proposal 132).
 *
 * WordPress sites accumulate redirects in a plugin's own table (Redirection,
 * Rank Math). A static rebuild that ignores them silently breaks inbound
 * links, legacy URLs, and hard-won search equity on cutover day. This route
 * normalizes the plugin-stored rules into the host-agnostic
 * `{ source, target, type }` shape the forge's redirect map consumes.
 *
 * Read-only, opt-in (Settings → Forge Bridge), and optionally token-gated.
 * It never writes, never proxies the plugin's own admin REST API, and never
 * exposes row ids, hit counts, group ids, or timestamps.
 *
 * @package NvoosForgeBridge
 */

namespace NvoosForgeBridge\Rest;

use NvoosForgeBridge\Settings;

/**
 * Redirect export route.
 */
class Redirects extends \WP_REST_Controller {

	/**
	 * Namespace.
	 */
	private const NS = 'nvoos-forge/v1';

	/**
	 * Hard cap on exported rules. Bounded by design: an export is a build
	 * input, never a full-table dump. Exceeding it reports `truncated: true`.
	 */
	private const MAX_ENTRIES = 500;

	/**
	 * Redirect codes a static host can express natively.
	 *
	 * @var int[]
	 */
	private const REDIRECT_CODES = array( 301, 302, 303, 307, 308 );

	/**
	 * Error codes a static host can express natively (Netlify/Cloudflare).
	 *
	 * @var int[]
	 */
	private const ERROR_CODES = array( 404, 410 );

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/redirects',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_redirects' ),
				'permission_callback' => array( $this, 'check_access' ),
			)
		);
	}

	/**
	 * Optional shared-token gate. An empty token keeps the route public (the
	 * operator opted in); a non-empty token requires an exact match via the
	 * `X-Forge-Redirects-Token` header or `Authorization: Bearer …`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function check_access( \WP_REST_Request $request ) {
		$expected = Settings::redirects_token();
		if ( '' === $expected ) {
			return true;
		}

		$provided = (string) $request->get_header( 'x-forge-redirects-token' );
		if ( '' === $provided ) {
			$authorization = (string) $request->get_header( 'authorization' );
			if ( 0 === stripos( $authorization, 'bearer ' ) ) {
				$provided = substr( $authorization, 7 );
			}
		}
		$provided = trim( $provided );

		if ( '' !== $provided && hash_equals( $expected, $provided ) ) {
			return true;
		}

		return new \WP_Error(
			'nvoos_forge_bridge_redirects_forbidden',
			__( 'A valid redirects token is required.', 'nvoos-forge-bridge' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Normalized, bounded redirect export.
	 *
	 * Always 200: a missing plugin, a disabled toggle, or a missing table is
	 * a normal "nothing to export" state for a build, never an error.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_redirects(): \WP_REST_Response {
		$enabled   = Settings::redirects_enabled();
		$providers = self::active_providers();

		$empty = array(
			'available' => array() !== $providers,
			'enabled'   => $enabled,
			'count'     => 0,
			'truncated' => false,
			'providers' => array(
				'redirection' => 0,
				'rankmath'    => 0,
			),
			'skipped'   => self::empty_skipped(),
			'redirects' => array(),
		);

		if ( ! $enabled || array() === $providers ) {
			return new \WP_REST_Response( $empty );
		}

		$collected = array(
			'redirects' => array(),
			'providers' => array(
				'redirection' => 0,
				'rankmath'    => 0,
			),
			'truncated' => false,
			'skipped'   => self::empty_skipped(),
		);

		if ( in_array( 'redirection', $providers, true ) ) {
			$collected = self::collect_redirection( $collected );
		}
		if ( in_array( 'rankmath', $providers, true ) ) {
			$collected = self::collect_rankmath( $collected );
		}

		$rules = self::dedupe( $collected['redirects'], $collected['skipped'] );
		usort(
			$rules,
			static function ( $a, $b ) {
				return strcmp( $a['source'], $b['source'] );
			}
		);

		return new \WP_REST_Response(
			array(
				'available' => true,
				'enabled'   => true,
				'count'     => count( $rules ),
				'truncated' => $collected['truncated'],
				'providers' => $collected['providers'],
				'skipped'   => $collected['skipped'],
				'redirects' => $rules,
			)
		);
	}

	/**
	 * Whether any supported redirect provider is installed.
	 *
	 * @return bool
	 */
	public static function redirection_active(): bool {
		return in_array( 'redirection', self::active_providers(), true );
	}

	/**
	 * Detected provider slugs (`redirection`, `rankmath`).
	 *
	 * Detection is cheap and cache-friendly: a plugin class/function, then the
	 * provider's options row (autoloaded), and only then the table itself.
	 *
	 * @return string[]
	 */
	public static function active_providers(): array {
		global $wpdb;

		$providers = array();

		$redirection = class_exists( 'Redirection\\Plugin' )
			|| function_exists( 'red_get_options' )
			|| false !== get_option( 'redirection_options', false )
			|| self::table_exists( $wpdb->prefix . 'redirection_items' );
		if ( $redirection ) {
			$providers[] = 'redirection';
		}

		$rankmath = defined( 'RANK_MATH_VERSION' )
			|| class_exists( 'RankMath\\Helper' )
			|| false !== get_option( 'rank-math-options-general', false )
			|| self::table_exists( $wpdb->prefix . 'rank_math_redirections' );
		if ( $rankmath ) {
			$providers[] = 'rankmath';
		}

		/**
		 * Filter the detected redirect providers.
		 *
		 * Lets a site register an additional adapter (or suppress a provider
		 * whose schema changed) without patching the bridge. The value must be a
		 * list of provider slugs the collector understands.
		 *
		 * @param string[] $providers Detected provider slugs.
		 */
		return array_values( (array) apply_filters( 'nvoos_forge_bridge_redirect_providers', $providers ) );
	}

	/**
	 * The zeroed skip counters.
	 *
	 * @return array<string, int>
	 */
	private static function empty_skipped(): array {
		return array(
			'regex'     => 0,
			'query'     => 0,
			'match'     => 0,
			'action'    => 0,
			'invalid'   => 0,
			'duplicate' => 0,
		);
	}

	/**
	 * Whether a table exists in this site's database.
	 *
	 * @param string $table Fully-qualified table name (from `$wpdb->prefix`).
	 * @return bool
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- existence probe, no cacheable data.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return is_string( $found ) && $found === $table;
	}

	/**
	 * Read Redirection's `redirection_items` rows.
	 *
	 * Only enabled items in enabled groups are live on WordPress, so only
	 * those are exported. `match_type = 'url'` is required: referrer, agent,
	 * cookie, IP, role, and header matches have no static-host equivalent.
	 *
	 * @param array<string, mixed> $collected Accumulator.
	 * @return array<string, mixed>
	 */
	private static function collect_redirection( array $collected ): array {
		global $wpdb;

		$items  = $wpdb->prefix . 'redirection_items';
		$groups = $wpdb->prefix . 'redirection_groups';

		if ( ! self::table_exists( $items ) ) {
			return $collected;
		}

		$has_groups = self::table_exists( $groups );
		$join       = $has_groups ? "INNER JOIN {$groups} g ON g.id = i.group_id" : '';
		$where      = "i.status = 'enabled'";
		if ( $has_groups ) {
			$where .= " AND g.status = 'enabled'";
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a bounded read of the redirect plugin's own table; the data changes with edits, so a stale cache is worse.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names come from $wpdb->prefix + a fixed suffix; only the limit is dynamic and bound through prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.url, i.regex, i.action_type, i.action_code, i.action_data, i.match_type
					FROM {$items} i
					{$join}
					WHERE {$where}
					ORDER BY i.position ASC, i.id ASC
					LIMIT %d",
				self::MAX_ENTRIES + 1
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $rows ) ) {
			return $collected;
		}
		if ( count( $rows ) > self::MAX_ENTRIES ) {
			$rows                   = array_slice( $rows, 0, self::MAX_ENTRIES );
			$collected['truncated'] = true;
		}

		foreach ( $rows as $row ) {
			$match_type = isset( $row['match_type'] ) ? (string) $row['match_type'] : '';
			if ( 'url' !== $match_type ) {
				++$collected['skipped']['match'];
				continue;
			}
			if ( ! empty( $row['regex'] ) ) {
				++$collected['skipped']['regex'];
				continue;
			}

			$action_type = isset( $row['action_type'] ) ? (string) $row['action_type'] : '';
			$code        = isset( $row['action_code'] ) ? (int) $row['action_code'] : 0;

			$target = '';
			if ( 'url' === $action_type ) {
				if ( ! in_array( $code, self::REDIRECT_CODES, true ) ) {
					++$collected['skipped']['action'];
					continue;
				}
				$target = self::action_data_url( isset( $row['action_data'] ) ? (string) $row['action_data'] : '' );
				if ( '' === $target ) {
					++$collected['skipped']['invalid'];
					continue;
				}
			} elseif ( 'error' === $action_type ) {
				if ( ! in_array( $code, self::ERROR_CODES, true ) ) {
					++$collected['skipped']['action'];
					continue;
				}
			} else {
				++$collected['skipped']['action'];
				continue;
			}

			$added = self::append_rule(
				$collected,
				isset( $row['url'] ) ? (string) $row['url'] : '',
				$target,
				$code
			);
			if ( $added ) {
				++$collected['providers']['redirection'];
			}
		}

		return $collected;
	}

	/**
	 * Read Rank Math's `rank_math_redirections` rows.
	 *
	 * `sources` is a serialized (or JSON) array of
	 * `{ pattern, comparison }` entries. Only exact/absent comparisons are
	 * portable to a static host; contains/starts/ends/regex are counted and
	 * skipped rather than silently widened.
	 *
	 * @param array<string, mixed> $collected Accumulator.
	 * @return array<string, mixed>
	 */
	private static function collect_rankmath( array $collected ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'rank_math_redirections';
		if ( ! self::table_exists( $table ) ) {
			return $collected;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a bounded read of the redirect plugin's own table; the data changes with edits, so a stale cache is worse.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the table name comes from $wpdb->prefix + a fixed suffix; only the limit is dynamic and bound through prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sources, url_to, header_code
					FROM {$table}
					WHERE status = 'active'
					ORDER BY id ASC
					LIMIT %d",
				self::MAX_ENTRIES + 1
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $rows ) ) {
			return $collected;
		}
		if ( count( $rows ) > self::MAX_ENTRIES ) {
			$rows                   = array_slice( $rows, 0, self::MAX_ENTRIES );
			$collected['truncated'] = true;
		}

		foreach ( $rows as $row ) {
			$code = isset( $row['header_code'] ) ? (int) $row['header_code'] : 0;
			if ( ! in_array( $code, self::REDIRECT_CODES, true ) && ! in_array( $code, self::ERROR_CODES, true ) ) {
				++$collected['skipped']['action'];
				continue;
			}

			$target = isset( $row['url_to'] ) && is_string( $row['url_to'] ) ? $row['url_to'] : '';
			if ( in_array( $code, self::REDIRECT_CODES, true ) && '' === trim( $target ) ) {
				++$collected['skipped']['invalid'];
				continue;
			}
			// Error codes carry no destination — a stray `url_to` is not a target.
			if ( in_array( $code, self::ERROR_CODES, true ) ) {
				$target = '';
			}

			$sources = self::rankmath_sources( isset( $row['sources'] ) ? (string) $row['sources'] : '' );
			if ( array() === $sources ) {
				++$collected['skipped']['invalid'];
				continue;
			}

			foreach ( $sources as $source ) {
				$added = self::append_rule( $collected, $source, $target, $code );
				if ( $added ) {
					++$collected['providers']['rankmath'];
				}
			}
		}

		return $collected;
	}

	/**
	 * Decode Rank Math's `sources` column into exact-match patterns only.
	 *
	 * @param string $raw Serialized or JSON sources column.
	 * @return string[]
	 */
	private static function rankmath_sources( string $raw ): array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			$decoded = maybe_unserialize( $raw );
		}
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$patterns = array();
		foreach ( $decoded as $entry ) {
			if ( is_string( $entry ) ) {
				$patterns[] = $entry;
				continue;
			}
			if ( ! is_array( $entry ) || ! isset( $entry['pattern'] ) || ! is_string( $entry['pattern'] ) ) {
				continue;
			}
			$comparison = isset( $entry['comparison'] ) ? (string) $entry['comparison'] : 'exact';
			if ( '' !== $comparison && 'exact' !== $comparison ) {
				continue;
			}
			$patterns[] = $entry['pattern'];
		}

		return $patterns;
	}

	/**
	 * Decode Redirection's `action_data` column (JSON or PHP-serialized).
	 *
	 * @param string $raw Raw column.
	 * @return string
	 */
	private static function action_data_url( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			$decoded = maybe_unserialize( $raw );
		}
		if ( ! is_array( $decoded ) || ! isset( $decoded['url'] ) || ! is_string( $decoded['url'] ) ) {
			return '';
		}
		return trim( $decoded['url'] );
	}

	/**
	 * Normalize one provider row into a rule and append it.
	 *
	 * @param array<string, mixed> $collected Accumulator, mutated by reference.
	 * @param string               $source    Provider source (path or URL).
	 * @param string               $target    Provider target (path or URL; '' for 404/410).
	 * @param int                  $code      HTTP status.
	 * @return bool Whether a rule was appended.
	 */
	private static function append_rule( array &$collected, string $source, string $target, int $code ): bool {
		$source = self::normalize_source( $source );
		if ( '' === $source ) {
			++$collected['skipped']['invalid'];
			return false;
		}
		// A query-scoped or fragment-scoped rule cannot be expressed portably
		// by `_redirects`; keeping the path alone would over-match, so skip it
		// and report the count instead.
		if ( false !== strpos( $source, '?' ) || false !== strpos( $source, '#' ) ) {
			++$collected['skipped']['query'];
			return false;
		}

		$target = self::normalize_target( $target );
		if ( $source === $target ) {
			++$collected['skipped']['invalid'];
			return false;
		}

		$collected['redirects'][] = array(
			'source' => $source,
			'target' => $target,
			'type'   => $code,
		);

		return true;
	}

	/**
	 * Normalize a provider source to a site-relative path.
	 *
	 * @param string $source Raw source.
	 * @return string
	 */
	private static function normalize_source( string $source ): string {
		$source = trim( $source );
		if ( '' === $source ) {
			return '';
		}

		if ( 1 === preg_match( '#^https?://#i', $source ) || 0 === strpos( $source, '//' ) ) {
			$absolute = 0 === strpos( $source, '//' ) ? ( is_ssl() ? 'https:' : 'http:' ) . $source : $source;
			$path     = wp_parse_url( $absolute, PHP_URL_PATH );
			$source   = is_string( $path ) && '' !== $path ? $path : '';
		} elseif ( '/' !== $source[0] ) {
			// Rank Math stores patterns without a leading slash.
			$source = '/' . $source;
		}

		// The home path is not a redirect source (Redirection refuses it too).
		if ( '' === $source || '/' === $source ) {
			return '';
		}
		return $source;
	}

	/**
	 * Normalize a provider target: same-site URLs become paths, external
	 * URLs stay absolute (valid in `_redirects`), paths get a leading slash.
	 *
	 * @param string $target Raw target.
	 * @return string
	 */
	private static function normalize_target( string $target ): string {
		$target = trim( $target );
		if ( '' === $target ) {
			return '';
		}
		if ( 1 === preg_match( '#^https?://#i', $target ) ) {
			$home_host   = wp_parse_url( home_url(), PHP_URL_HOST );
			$target_host = wp_parse_url( $target, PHP_URL_HOST );
			if ( is_string( $home_host ) && '' !== $home_host && is_string( $target_host ) && 0 === strcasecmp( $home_host, $target_host ) ) {
				$path  = wp_parse_url( $target, PHP_URL_PATH );
				$query = wp_parse_url( $target, PHP_URL_QUERY );
				$path  = is_string( $path ) && '' !== $path ? $path : '/';
				return is_string( $query ) && '' !== $query ? $path . '?' . $query : $path;
			}
			return $target;
		}
		if ( '/' !== $target[0] ) {
			$target = '/' . $target;
		}
		return $target;
	}

	/**
	 * First-wins dedupe by source (provider order = Redirection then Rank
	 * Math; within a provider, stored order).
	 *
	 * @param array<int, array<string, mixed>> $rules   Collected rules.
	 * @param array<string, int>               $skipped Skip counters (appended to).
	 * @return array<int, array<string, mixed>>
	 */
	private static function dedupe( array $rules, array &$skipped ): array {
		$seen  = array();
		$clean = array();
		foreach ( $rules as $rule ) {
			$source = isset( $rule['source'] ) ? (string) $rule['source'] : '';
			if ( '' === $source ) {
				continue;
			}
			if ( isset( $seen[ $source ] ) ) {
				++$skipped['duplicate'];
				continue;
			}
			$seen[ $source ] = true;
			$clean[]         = $rule;
		}
		return $clean;
	}
}
