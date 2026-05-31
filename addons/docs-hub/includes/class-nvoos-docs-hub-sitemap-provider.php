<?php
/**
 * NV oOS Docs Hub — WordPress Sitemap Provider
 *
 * Registers all indexed documentation pages with the WordPress core sitemap
 * system (introduced in WP 5.5) so they are included in the site's auto-
 * generated sitemap files.
 *
 * Sitemap URLs have the form:
 *   /wp-sitemap-nvoos-docs-1.xml
 *   /wp-sitemap-nvoos-docs-2.xml
 *   … (one XML file per 50 pages, WordPress default)
 *
 * The provider is only registered when:
 *  1. The WordPress Sitemaps API is available (WP ≥ 5.5).
 *  2. The Docs Hub plugin is enabled (docs_hub_enabled setting).
 *  3. At least one docs page has been indexed (manifest exists in cache).
 *
 * Site admins can opt-out by filtering `nvoos_docs_hub_sitemap_enabled`:
 *
 *   add_filter( 'nvoos_docs_hub_sitemap_enabled', '__return_false' );
 *
 * @package NV_oOS_Docs_Hub
 * @since   0.3.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sitemap provider that exposes documentation pages via the WP Sitemaps API.
 *
 * @since 0.3.8
 */
class NV_oOS_Docs_Hub_Sitemap_Provider extends WP_Sitemaps_Provider {

	/**
	 * Constructor.
	 *
	 * Sets the provider name (used as the `<name>` segment in the sitemap index).
	 *
	 * @since 0.3.8
	 */
	public function __construct() {
		$this->name        = 'nvoos-docs';
		$this->object_type = 'docs_page';
	}

	/**
	 * Return the list of sitemap entries for a given page number.
	 *
	 * Each entry must have a `loc` key (absolute URL).  We also add
	 * `lastmod` when `last_modified` is present in the page payload.
	 *
	 * @since 0.3.8
	 *
	 * @param int    $page_num       1-based page number.
	 * @param string $object_subtype Unused (docs hub has no subtypes).
	 * @return array Array of sitemap entries for this batch.
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		$entries = $this->get_all_doc_entries();

		if ( empty( $entries ) ) {
			return array();
		}

		$page_size = $this->get_max_num_pages( $object_subtype ) > 0 ? wp_sitemaps_get_max_urls( 'docs_page' ) : count( $entries );
		$offset    = ( $page_num - 1 ) * $page_size;
		$batch     = array_slice( $entries, $offset, $page_size );

		$urls          = array();
		$shortcode_url = $this->get_docs_page_url();

		if ( ! $shortcode_url ) {
			return array();
		}

		foreach ( $batch as $entry ) {
			$slug = isset( $entry['slug'] ) ? (string) $entry['slug'] : '';
			if ( '' === $slug ) {
				continue;
			}

			$url = trailingslashit( $shortcode_url ) . '#/' . $slug;

			$item = array( 'loc' => $url );

			// Add lastmod when the page payload is available and has a valid timestamp.
			if ( isset( $entry['last_modified'] ) && $entry['last_modified'] > 0 ) {
				$item['lastmod'] = gmdate( 'c', (int) $entry['last_modified'] );
			}

			$urls[] = $item;
		}

		return $urls;
	}

	/**
	 * Return the total number of sitemap pages (batches) for this provider.
	 *
	 * @since 0.3.8
	 *
	 * @param string $object_subtype Unused.
	 * @return int Number of sitemap pages.
	 */
	public function get_max_num_pages( $object_subtype = '' ) {
		$entries = $this->get_all_doc_entries();
		if ( empty( $entries ) ) {
			return 0;
		}

		$per_page = wp_sitemaps_get_max_urls( 'docs_page' );
		return (int) ceil( count( $entries ) / max( 1, $per_page ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Retrieve all indexed documentation page entries from the manifest cache.
	 *
	 * Returns a flat array of entry arrays (each has at least `slug`).
	 * Returns an empty array when the manifest is not yet built.
	 *
	 * @since 0.3.8
	 *
	 * @return array Flat array of page entry arrays.
	 */
	private function get_all_doc_entries() {
		$cache = new NV_oOS_Docs_Hub_Cache();

		$manifest = $cache->get_manifest();
		if ( ! is_array( $manifest ) || empty( $manifest['tree'] ) ) {
			return array();
		}

		$entries = array();
		foreach ( (array) $manifest['tree'] as $group ) {
			if ( ! is_array( $group ) || empty( $group['pages'] ) ) {
				continue;
			}
			foreach ( (array) $group['pages'] as $page ) {
				if ( is_array( $page ) && ! empty( $page['slug'] ) ) {
					$entries[] = $page;
				}
			}
		}

		return $entries;
	}

	/**
	 * Find the URL of the WordPress page (or post) that displays the
	 * Docs Hub shortcode / block.
	 *
	 * Looks up the `docs_hub_page_id` option first (set by the installer / user).
	 * Falls back to scanning all published `page` posts for the `[nvoos_docs]`
	 * or `[nvoos_docs_hub]` shortcode.  The result is cached in a transient for
	 * 1 hour so this scan doesn't run on every sitemap request.
	 *
	 * @since 0.3.8
	 *
	 * @return string|null Absolute URL, or null if no page was found.
	 */
	private function get_docs_page_url() {
		// Check the explicitly configured page ID first.
		$settings = NV_oOS_Docs_Hub_Plugin::get_settings();
		if ( ! empty( $settings['docs_page_id'] ) ) {
			$page_url = get_permalink( (int) $settings['docs_page_id'] );
			if ( $page_url ) {
				return $page_url;
			}
		}

		// Cached scan result.
		$cached = get_transient( 'nvoos_docs_hub_sitemap_page_url' );
		if ( false !== $cached ) {
			return $cached ? $cached : null;
		}

		// Scan published pages for the shortcode.
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$found_url = null;
		foreach ( (array) $pages as $page_id ) {
			$content = get_post_field( 'post_content', (int) $page_id );
			if (
				false !== strpos( $content, '[nvoos_docs]' ) ||
				false !== strpos( $content, '[nvoos_docs_hub]' )
			) {
				$found_url = get_permalink( (int) $page_id );
				break;
			}
		}

		// Cache for 1 hour; store an empty string to represent "not found".
		set_transient( 'nvoos_docs_hub_sitemap_page_url', $found_url ? $found_url : '', HOUR_IN_SECONDS );

		return $found_url;
	}
}

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- file intentionally contains a class and a standalone function.
/**
 * Register the Docs Hub sitemap provider with the WordPress sitemap registry.
 *
 * Hooked to `wp_sitemaps_init`, which fires once when the WP_Sitemaps server
 * boots and passes the WP_Sitemaps instance. The instance exposes a public
 * `registry` property (a WP_Sitemaps_Registry) on which custom providers are
 * registered via `add_provider( $name, $provider )`.
 *
 * Note: an earlier revision of this file mistakenly hooked into the
 * `wp_sitemaps_add_provider` filter, whose first argument is the provider
 * being added (e.g. WP_Sitemaps_Posts) — not the registry. Calling
 * `add_provider()` on that object produced a fatal error because
 * WP_Sitemaps_Posts has no such method.
 *
 * @since 0.3.8
 *
 * @param WP_Sitemaps $wp_sitemaps WordPress sitemaps server instance.
 * @return void
 */
function nvoos_docs_hub_register_sitemap_provider( $wp_sitemaps ) {
	if ( ! $wp_sitemaps instanceof WP_Sitemaps || ! isset( $wp_sitemaps->registry ) ) {
		return;
	}

	$settings = NV_oOS_Docs_Hub_Plugin::get_settings();

	// Respect the docs_hub_enabled toggle.
	if ( empty( $settings['enabled'] ) ) {
		return;
	}

	/**
	 * Filter whether the Docs Hub sitemap entries are included in the WordPress
	 * sitemap index.
	 *
	 * @since 0.3.8
	 *
	 * @param bool $enabled Whether to register the provider. Default true.
	 */
	if ( ! apply_filters( 'nvoos_docs_hub_sitemap_enabled', true ) ) {
		return;
	}

	$wp_sitemaps->registry->add_provider( 'nvoos-docs', new NV_oOS_Docs_Hub_Sitemap_Provider() );
}
add_action( 'wp_sitemaps_init', 'nvoos_docs_hub_register_sitemap_provider' );
// phpcs:enable Universal.Files.SeparateFunctionsFromOO.Mixed
