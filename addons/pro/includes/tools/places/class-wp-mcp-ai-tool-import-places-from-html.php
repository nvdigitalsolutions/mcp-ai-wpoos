<?php
/**
 * Tool for importing places by scraping a directory of static HTML files.
 *
 * Parses HTTrack exports, Wayback Machine snapshots, or site mirrors to
 * extract place data (title, description, images, coordinates, etc.) and
 * import them as Place CPT records.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports places from a directory of HTML files using DOM-based extraction.
 *
 * Walks a directory tree, identifies content pages, parses HTML with
 * DOMDocument/DOMXPath, extracts structured data, and creates Place CPT
 * records via WP_MCP_AI_Place_Helper.
 *
 * @since 1.4.0
 */
class WP_MCP_AI_Tool_Import_Places_From_Html implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Default extraction selectors.
	 *
	 * @var array
	 */
	const DEFAULT_RULES = array(
		'title_selector'        => '//title',
		'description_selector'  => '//meta[@name="description"]/@content',
		'image_selector'        => '//meta[@property="og:image"]/@content',
		'h1_selector'           => '//h1',
		'main_content_selector' => '//section[contains(@class,"main-text")]//div[contains(@class,"wrapper")]',
		'hero_image_selector'   => '//div[contains(@class,"hero-banner")]//img',
		'maps_iframe_selector'  => '//iframe[contains(@src,"google.com/maps")]',
		'json_ld_selector'      => '//script[@type="application/ld+json"]',
		'breadcrumb_selector'   => '//div[contains(@class,"breadcrumbs")]//li',
		'tips_selector'         => '//div[contains(@class,"tips")]//ul/li',
	);

	/**
	 * File extensions to consider as HTML content pages.
	 *
	 * @var array
	 */
	const CONTENT_EXTENSIONS = array( 'html', 'htm' );

	/**
	 * Directories to skip during traversal.
	 *
	 * @var array
	 */
	const SKIP_DIRS = array(
		'css',
		'js',
		'fonts',
		'images',
		'img',
		'assets',
		'wp-admin',
		'wp-includes',
		'hts-cache',
		'comments',
		'feed',
	);

	/**
	 * Regex for matching HTTrack flat index files (index.html, index-2.html, etc.).
	 *
	 * @var string
	 */
	const HTTRACK_INDEX_PATTERN = '/^index[0-9a-f]*\.html?$/i';

	/**
	 * HTTrack URL → local-path map built from hts-cache.
	 *
	 * Keys are resolved local file paths, values are original URLs.
	 *
	 * @var array<string,string>
	 */
	private $httrack_url_map = array();

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_places_management'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'Places Management toolkit required.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'import_places_from_html';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import Places from HTML', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Import places by scraping a directory of static HTML files (HTTrack exports, site mirrors, Wayback Machine archives). Parses HTML to extract titles, descriptions, images, coordinates, and structured data.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'source_directory'     => array(
					'type'        => 'string',
					'description' => __( 'Server path to the directory containing HTML files.', 'mcp-ai-wpoos-pro' ),
				),
				'recursive'            => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Recurse into subdirectories.', 'mcp-ai-wpoos-pro' ),
				),
				'url_pattern'          => array(
					'type'        => 'string',
					'description' => __( 'Optional regex pattern to filter which pages to process (matched against the file path).', 'mcp-ai-wpoos-pro' ),
				),
				'max_pages'            => array(
					'type'        => 'integer',
					'default'     => 500,
					'minimum'     => 1,
					'maximum'     => 5000,
					'description' => __( 'Maximum number of HTML pages to process.', 'mcp-ai-wpoos-pro' ),
				),
				'extraction_rules'     => array(
					'type'        => 'object',
					'description' => __( 'Custom XPath selectors for data extraction. Keys: title_selector, description_selector, image_selector, h1_selector, main_content_selector, hero_image_selector, maps_iframe_selector, json_ld_selector, breadcrumb_selector, tips_selector.', 'mcp-ai-wpoos-pro' ),
				),
				'place_type_mapping'   => array(
					'type'        => 'object',
					'description' => __( 'Map URL path patterns to place types. Example: {"/destinations/": "city", "/attractions/": "attraction", "/hotels/": "hotel"}', 'mcp-ai-wpoos-pro' ),
				),
				'default_place_type'   => array(
					'type'        => 'string',
					'default'     => 'attraction',
					'description' => __( 'Default place type when no mapping matches.', 'mcp-ai-wpoos-pro' ),
				),
				'default_country'      => array(
					'type'        => 'string',
					'description' => __( 'Default country for all imported places.', 'mcp-ai-wpoos-pro' ),
				),
				'parent_page_path'     => array(
					'type'        => 'string',
					'description' => __( 'Path to a specific HTML page whose URL should be set as parent for all imported places (matched via canonical URL).', 'mcp-ai-wpoos-pro' ),
				),
				'skip_existing'        => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Skip places that already exist (matched by source URL).', 'mcp-ai-wpoos-pro' ),
				),
				'dry_run'              => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Preview discovered pages without importing.', 'mcp-ai-wpoos-pro' ),
				),
				'batch_size'           => array(
					'type'        => 'integer',
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
					'description' => __( 'Pages to process per batch.', 'mcp-ai-wpoos-pro' ),
				),
				'auto_create_services' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Automatically create bookable services (mcp_service) for imported places whose place_type matches service_place_types.', 'mcp-ai-wpoos-pro' ),
				),
				'service_place_types'  => array(
					'type'        => 'array',
					'default'     => array( 'experience' ),
					'description' => __( 'Which place types should trigger auto service creation (requires auto_create_services: true).', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'service_defaults'     => array(
					'type'        => 'object',
					'description' => __( 'Default values for auto-created services: duration_minutes (default 180), price (default 0), buffer_time_minutes (default 30), category.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'duration_minutes'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'price'               => array(
							'type'    => 'number',
							'minimum' => 0,
						),
						'buffer_time_minutes' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'category'            => array( 'type' => 'string' ),
					),
				),
			),
			'required'             => array( 'source_directory' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read', 'database-write', 'requires-capability' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'places',
			'post_type'             => 'mcp_ai_place',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'travel_agent', 'content_creator', 'developer' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to import places.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_toolkit_disabled', self::get_unavailable_reason() );
		}

		$source_dir = isset( $arguments['source_directory'] ) ? rtrim( $arguments['source_directory'], '/\\' ) : '';
		if ( empty( $source_dir ) || ! is_dir( $source_dir ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_directory', __( 'Source directory does not exist or is not accessible.', 'mcp-ai-wpoos-pro' ) );
		}

		// Ensure helper is available.
		if ( ! class_exists( 'WP_MCP_AI_Place_Helper' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/helpers/class-wp-mcp-ai-place-helper.php';
		}

		$recursive     = isset( $arguments['recursive'] ) ? (bool) $arguments['recursive'] : true;
		$max_pages     = isset( $arguments['max_pages'] ) ? absint( $arguments['max_pages'] ) : 500;
		$url_pattern   = isset( $arguments['url_pattern'] ) ? $arguments['url_pattern'] : '';
		$dry_run       = isset( $arguments['dry_run'] ) && $arguments['dry_run'];
		$skip_existing = isset( $arguments['skip_existing'] ) ? (bool) $arguments['skip_existing'] : true;
		$batch_size    = isset( $arguments['batch_size'] ) ? absint( $arguments['batch_size'] ) : 20;

		// Place→Service bridge settings.
		$auto_create_services = isset( $arguments['auto_create_services'] ) && $arguments['auto_create_services'];
		$service_place_types  = isset( $arguments['service_place_types'] ) ? (array) $arguments['service_place_types'] : array( 'experience' );
		$service_defaults     = isset( $arguments['service_defaults'] ) ? (array) $arguments['service_defaults'] : array();

		// Extraction rules: merge defaults with any overrides.
		$rules = self::DEFAULT_RULES;
		if ( isset( $arguments['extraction_rules'] ) && is_array( $arguments['extraction_rules'] ) ) {
			$rules = array_merge( $rules, $arguments['extraction_rules'] );
		}

		// Place type mapping.
		$type_mapping     = isset( $arguments['place_type_mapping'] ) ? (array) $arguments['place_type_mapping'] : array();
		$default_type     = isset( $arguments['default_place_type'] ) ? $arguments['default_place_type'] : 'attraction';
		$default_country  = isset( $arguments['default_country'] ) ? $arguments['default_country'] : '';
		$parent_page_path = isset( $arguments['parent_page_path'] ) ? $arguments['parent_page_path'] : '';

		// Resolve parent place ID from parent page path.
		$parent_place_id = 0;
		if ( ! empty( $parent_page_path ) && is_file( $parent_page_path ) ) {
			$parent_data = $this->extract_page_data( $parent_page_path, $rules, $type_mapping, $default_type, $default_country );
			if ( ! empty( $parent_data['source_url'] ) ) {
				$parent_place_id = WP_MCP_AI_Place_Helper::find_by_source_url( $parent_data['source_url'] );
			}
			if ( ! $parent_place_id && ! empty( $parent_data['name'] ) ) {
				$parent_place_id = WP_MCP_AI_Place_Helper::find_by_name( $parent_data['name'] );
			}
		}

		// Discover HTML files.
		$files = $this->discover_html_files( $source_dir, $recursive, $max_pages, $url_pattern );
		if ( is_wp_error( $files ) ) {
			return $files;
		}

		$results = array(
			'success'          => true,
			'files_found'      => count( $files ),
			'created'          => 0,
			'skipped'          => 0,
			'failed'           => 0,
			'services_created' => 0,
			'service_errors'   => 0,
			'ids'              => array(),
			'errors'           => array(),
			'service_failures' => array(),
			'discovered'       => array(),
			'dry_run'          => $dry_run,
			'parent_place_id'  => $parent_place_id,
		);

		$processed = 0;

		foreach ( $files as $file_path ) {
			if ( $processed >= $max_pages ) {
				break;
			}

			// Extract data from the HTML file.
			$place_data = $this->extract_page_data( $file_path, $rules, $type_mapping, $default_type, $default_country );

			// If canonical URL was missing, relative, or fake-absolute (HTTrack),
			// backfill from the cache map.
			$is_relative = ! empty( $place_data['source_url'] ) && ! preg_match( '#^https?://#', $place_data['source_url'] );
			$host        = $is_relative ? '' : parse_url( $place_data['source_url'], PHP_URL_HOST );
			$is_fake_abs = ! $is_relative && ! empty( $host )
				&& preg_match( '/\.(html?|php\d*|asp|jsp|cfm|aspx)$/i', $host );
			if ( ( empty( $place_data['source_url'] ) || $is_relative || $is_fake_abs ) && isset( $this->httrack_url_map[ $file_path ] ) ) {
				$place_data['source_url'] = $this->httrack_url_map[ $file_path ];
				// Re-classify with the resolved URL.
				$place_data['place_type'] = $this->classify_page_type( $place_data['source_url'], $type_mapping, $default_type );
			}

			if ( empty( $place_data['name'] ) ) {
				continue; // Skip pages without a meaningful title.
			}

			// Set parent.
			if ( $parent_place_id && empty( $place_data['parent_place_id'] ) ) {
				$place_data['parent_place_id'] = $parent_place_id;
			}

			// Check dedup by source URL.
			if ( $skip_existing && ! empty( $place_data['source_url'] ) ) {
				$existing = WP_MCP_AI_Place_Helper::find_by_source_url( $place_data['source_url'] );
				if ( $existing ) {
					++$results['skipped'];
					++$processed;
					continue;
				}
			}

			$results['discovered'][] = array(
				'name'       => $place_data['name'],
				'place_type' => isset( $place_data['place_type'] ) ? $place_data['place_type'] : $default_type,
				'source_url' => isset( $place_data['source_url'] ) ? $place_data['source_url'] : '',
				'file'       => $file_path,
			);

			if ( $dry_run ) {
				++$processed;
				continue;
			}

			// Create the place.
			$place_id = WP_MCP_AI_Place_Helper::create_place( $place_data, $current_user_id );

			if ( is_wp_error( $place_id ) ) {
				++$results['failed'];
				$results['errors'][] = array(
					'file'  => $file_path,
					'name'  => $place_data['name'],
					'error' => $place_id->get_error_message(),
				);
			} else {
				++$results['created'];
				$results['ids'][] = $place_id;

				// Sideload hero image if present.
				$image_urls = array();
				if ( ! empty( $place_data['hero_image_url'] ) ) {
					$image_urls[] = $place_data['hero_image_url'];
				}
				if ( ! empty( $image_urls ) ) {
					WP_MCP_AI_Place_Helper::sideload_images( $place_id, $image_urls );
				}

				// ── Auto-create bookable service (place→service bridge).
				if ( $auto_create_services && ! empty( $place_data['place_type'] ) ) {
					$place_type = $place_data['place_type'];
					if ( in_array( $place_type, $service_place_types, true ) ) {
						$svc_defaults = $service_defaults;
						// Default category from city if not provided.
						if ( empty( $svc_defaults['category'] ) && ! empty( $place_data['city'] ) ) {
							$svc_defaults['category'] = $place_data['city'];
						}
						$service_id = WP_MCP_AI_Place_Helper::create_service_from_place( $place_id, $svc_defaults );
						if ( is_wp_error( $service_id ) ) {
							++$results['service_errors'];
							$results['service_failures'][] = array(
								'place_id' => $place_id,
								'place'    => $place_data['name'],
								'error'    => $service_id->get_error_message(),
							);
						} else {
							++$results['services_created'];
						}
					}
				}
			}

			++$processed;

			// Periodic cache flush.
			if ( 0 === $processed % $batch_size ) {
				wp_cache_flush();
				if ( class_exists( 'WP_MCP_AI_Memory_Manager' ) ) {
					WP_MCP_AI_Memory_Manager::stop_the_insanity();
				}
			}
		}

		$results['processed'] = $processed;
		$results['message']   = $dry_run
			? sprintf(
				/* translators: 1: files found, 2: pages discovered, 3: pages with name */
				__( 'Dry run complete: %1$d files found, %2$d pages discovered.', 'mcp-ai-wpoos-pro' ),
				$results['files_found'],
				count( $results['discovered'] )
			)
			: sprintf(
							/* translators: 1: created, 2: skipped, 3: failed, 4: services created, 5: service errors */
				__( 'HTML import complete: %1$d created, %2$d skipped, %3$d failed, %4$d services created, %5$d service errors.', 'mcp-ai-wpoos-pro' ),
				$results['created'],
				$results['skipped'],
				$results['failed'],
				$results['services_created'],
				$results['service_errors']
			);

		return $results;
	}

	// -------------------------------------------------------------------------
	// File discovery
	// -------------------------------------------------------------------------

	/**
	 * Recursively discover HTML files in a directory.
	 *
	 * @param string $dir        Directory path.
	 * @param bool   $recursive  Whether to recurse into subdirectories.
	 * @param int    $max        Maximum files to return.
	 * @param string $pattern    Optional regex to filter paths.
	 * @return array|WP_Error Array of file paths or error.
	 */
	private function discover_html_files( $dir, $recursive, $max, $pattern ) {
		// Detect HTTrack mirrors: flat index*.html files + hts-cache/ directory.
		if ( $this->is_httrack_mirror( $dir ) ) {
			return $this->discover_httrack_files( $dir, $max, $pattern );
		}

		$files = array();

		try {
			$iterator = $recursive
				? new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $dir ),
					RecursiveIteratorIterator::SELF_FIRST
				)
				: new DirectoryIterator( $dir );

			foreach ( $iterator as $item ) {
				if ( count( $files ) >= $max ) {
					break;
				}

				// Skip dot files and directories (SKIP_DOTS can be unreliable on some filesystems).
				if ( in_array( $item->getFilename(), array( '.', '..' ), true ) ) {
					continue;
				}

				// Defensive: also skip when any parent path segment is a dot directory.
				// Some filesystems expose '..' via RecursiveDirectoryIterator even
				// with SKIP_DOTS.
				if ( $recursive ) {
					$path_segments = explode( DIRECTORY_SEPARATOR, $item->getPath() );
					$has_dot_seg   = false;
					foreach ( $path_segments as $seg ) {
						if ( '.' === $seg || '..' === $seg ) {
							$has_dot_seg = true;
							break;
						}
					}
					if ( $has_dot_seg ) {
						continue;
					}
				}

				if ( $item->isDir() ) {
					continue;
				}

				$ext = strtolower( $item->getExtension() );
				if ( ! in_array( $ext, self::CONTENT_EXTENSIONS, true ) ) {
					continue;
				}

				$path = $item->getRealPath();

				// Skip known non-content directories.
				$path_parts = explode( DIRECTORY_SEPARATOR, $path );
				$skip_found = false;
				foreach ( self::SKIP_DIRS as $skip ) {
					if ( in_array( $skip, $path_parts, true ) ) {
						$skip_found = true;
						break;
					}
				}
				if ( $skip_found ) {
					continue;
				}

				// Apply user pattern filter.
				if ( ! empty( $pattern ) && ! preg_match( $pattern, $path ) ) {
					continue;
				}

				$files[] = $path;
			}
		} catch ( Exception $e ) {
			return new WP_Error(
				'wp_mcp_ai_directory_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Error reading directory: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				)
			);
		}

		return $files;
	}

	// -------------------------------------------------------------------------
	// HTTrack mirror support
	// -------------------------------------------------------------------------

	/**
	 * Determine whether a directory is an HTTrack site mirror.
	 *
	 * Heuristic: the directory contains an hts-cache/ subdirectory and at
	 * least one flat index*.html file at the top level.
	 *
	 * @since  1.4.1
	 *
	 * @param  string $dir Root directory.
	 * @return bool
	 */
	private function is_httrack_mirror( $dir ) {
		// Flat index*.html files at root level are the primary signal.
		$files = glob( $dir . DIRECTORY_SEPARATOR . 'index*.{html,htm}', GLOB_BRACE );
		if ( empty( $files ) ) {
			return false;
		}

		// Try to locate hts-cache for URL map building.
		$cache_dir    = $dir . DIRECTORY_SEPARATOR . 'hts-cache';
		$parent_cache = $dir . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'hts-cache';
		$parent_cache = realpath( $parent_cache );

		if ( is_dir( $cache_dir ) ) {
			$this->httrack_cache_dir = $cache_dir;
		} elseif ( is_dir( $parent_cache ) ) {
			$this->httrack_cache_dir = $parent_cache;
		} else {
			// No cache file available; URL map will be populated from
			// <!-- Mirrored from --> comments in the flat HTML files.
			$this->httrack_cache_dir = '';
		}

		return true;
	}

	/**
	 * Discover content files inside an HTTrack mirror.
	 *
	 * HTTrack stores pages as flat index*.html files at the mirror root while
	 * subdirectories are empty placeholders.  This method collects only the
	 * flat content files and builds a URL map from hts-cache so that
	 * extract_page_data() can assign the correct source_url.
	 *
	 * @since  1.4.1
	 *
	 * @param  string $dir     Mirror root directory.
	 * @param  int    $max     Maximum files to return.
	 * @param  string $pattern Optional regex to filter paths.
	 * @return array|WP_Error
	 */
	private function discover_httrack_files( $dir, $max, $pattern ) {
		$files                 = array();
		$cache_dir             = isset( $this->httrack_cache_dir )
			? $this->httrack_cache_dir
			: $dir . DIRECTORY_SEPARATOR . 'hts-cache';
		$this->httrack_url_map = $this->build_httrack_url_map( $cache_dir, $dir );

		// Collect content from the directory tree first (these are the real pages).
		$this->collect_httrack_tree_files( $dir, $max, $pattern, $files );

		// Supplement with flat index*.html files from root (fallback).
		// When the tree scan already found most of what we need, skip flat
		// scan entirely — flat index*.html files at root are mostly
		// redirect pages and rarely contain real content.
		if ( count( $files ) < $max * 0.8 ) {
			$this->collect_httrack_flat_files( $dir, $max, $pattern, $files );
		}

		// Enrich the URL map from HTTrack comments in the flat files.
		$this->enrich_url_map_from_comments( $files, $this->httrack_url_map );

		return $files;
	}

	/**
	 * Collect flat index*.html files from the mirror root.
	 *
	 * @since  1.4.2
	 *
	 * @param string $dir     Mirror root directory.
	 * @param int    $max     Max files to collect.
	 * @param string $pattern Optional regex filter.
	 * @param array  $files   File list (by reference).
	 */
	private function collect_httrack_flat_files( $dir, $max, $pattern, &$files ) {
		try {
			$iterator = new DirectoryIterator( $dir );
			foreach ( $iterator as $item ) {
				if ( count( $files ) >= $max ) {
					break;
				}
				if ( $item->isDir() || in_array( $item->getFilename(), array( '.', '..' ), true ) ) {
					continue;
				}
				$filename = $item->getFilename();
				if ( ! preg_match( self::HTTRACK_INDEX_PATTERN, $filename ) ) {
					continue;
				}
				$ext = strtolower( $item->getExtension() );
				if ( ! in_array( $ext, self::CONTENT_EXTENSIONS, true ) ) {
					continue;
				}
				$path = $item->getRealPath();
				if ( false === $path ) {
					continue;
				}
				if ( ! empty( $pattern ) ) {
					$candidate = isset( $this->httrack_url_map[ $path ] ) ? $this->httrack_url_map[ $path ] : $path;
					if ( ! preg_match( $pattern, $candidate ) ) {
						continue;
					}
				}
				$files[] = $path;
			}
		} catch ( Exception $e ) {
			// Flat files are supplementary; failures are non-fatal.
			error_log( 'WP MCP AI: HTTrack flat file scan failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Walk the directory tree for content pages (HTTrack mirrors may store
	 * content in both flat files and subdirectory tree).
	 *
	 * @since  1.4.2
	 *
	 * @param string $dir     Mirror root directory.
	 * @param int    $max     Max files to collect.
	 * @param string $pattern Optional regex filter.
	 * @param array  $files   File list (by reference).
	 */
	private function collect_httrack_tree_files( $dir, $max, $pattern, &$files ) {
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir ),
				RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ( $iterator as $item ) {
				if ( count( $files ) >= $max ) {
					break;
				}

				// Skip dot files and directories (SKIP_DOTS can be unreliable).
				if ( in_array( $item->getFilename(), array( '.', '..' ), true ) ) {
					continue;
				}

				// Defensive: also scan parent path segments for stray dots.
				$tree_segments = explode( DIRECTORY_SEPARATOR, $item->getPath() );
				$tree_dot_seg  = false;
				foreach ( $tree_segments as $seg ) {
					if ( '.' === $seg || '..' === $seg ) {
						$tree_dot_seg = true;
						break;
					}
				}
				if ( $tree_dot_seg ) {
					continue;
				}

				if ( $item->isDir() ) {
					continue;
				}

				$ext = strtolower( $item->getExtension() );
				if ( ! in_array( $ext, self::CONTENT_EXTENSIONS, true ) ) {
					continue;
				}

				$path = $item->getRealPath();
				if ( false === $path ) {
					continue;
				}

				// Skip known non-content directories using exact name match.
				$path_parts = explode( DIRECTORY_SEPARATOR, $path );
				$skip_found = false;
				foreach ( self::SKIP_DIRS as $skip ) {
					if ( in_array( $skip, $path_parts, true ) ) {
						$skip_found = true;
						break;
					}
				}
				if ( $skip_found ) {
					continue;
				}

				// Apply user pattern filter.
				if ( ! empty( $pattern ) ) {
					$candidate = isset( $this->httrack_url_map[ $path ] )
						? $this->httrack_url_map[ $path ]
						: $path;
					if ( ! preg_match( $pattern, $candidate ) ) {
						continue;
					}
				}

				$files[] = $path;
			}
		} catch ( Exception $e ) {
			// Tree scan failures are non-fatal.
			error_log( 'WP MCP AI: HTTrack tree file scan failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Build a path → URL map from the HTTrack hts-cache directory.
	 *
	 * Tries multiple cache formats:
	 *   1. Tab-separated new.txt  (URL \t relative_path)
	 *   2. Plain one-URL-per-line new.txt (sequential → index.html, index-2.html, …)
	 *
	 * Falls back gracefully — pages whose URL cannot be resolved will still
	 * be discovered and can fall back on in-page <link rel="canonical">.
	 *
	 * @since  1.4.1
	 *
	 * @param  string $cache_dir Path to hts-cache/.
	 * @param  string $root_dir  Mirror root (parent of hts-cache/).
	 * @return array<string,string>  local_path => original_url
	 */
	private function build_httrack_url_map( $cache_dir, $root_dir ) {
		$map = array();

		// Find a cache listing file.
		$candidates = array(
			'new.txt',
			'new.dat',
		);

		$cache_file = '';
		foreach ( $candidates as $name ) {
			$candidate = $cache_dir . DIRECTORY_SEPARATOR . $name;
			if ( is_file( $candidate ) && is_readable( $candidate ) ) {
				$cache_file = $candidate;
				break;
			}
		}

		if ( empty( $cache_file ) ) {
			return $map;
		}

		if ( is_readable( $cache_file ) ) {
			$lines = file( $cache_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		} else {
			$lines = false;
		}
		if ( false === $lines ) {
			return $map;
		}

		// Detect format: if any line contains a tab, treat as key→value.
		$is_tsv = false;
		foreach ( $lines as $line ) {
			if ( false !== strpos( $line, "\t" ) ) {
				$is_tsv = true;
				break;
			}
		}

		if ( $is_tsv ) {
			// HTTrack new.txt format: multi-column TSV.
			// Columns: date, size, flags, statuscode, status, MIME, etag, URL, localfile, referrer.
			// URL is column 7 (0-indexed), localfile is column 8.
			// Skip header line (first line).
			$is_header = true;
			foreach ( $lines as $line ) {
				if ( $is_header ) {
					$is_header = false;
					continue;
				}
				$parts = explode( "\t", $line );
				if ( count( $parts ) < 9 ) {
					continue;
				}
				$url       = trim( $parts[7] );
				$local_raw = trim( $parts[8] );
				if ( empty( $url ) || empty( $local_raw ) ) {
					continue;
				}
				// localfile may be an absolute path from the original machine.
				// Try to find it relative to the mirror root.
				$local_file = $this->resolve_httrack_local_path( $local_raw, $root_dir );
				if ( null !== $local_file && is_file( $local_file ) ) {
					$map[ $local_file ] = $url;
				}
			}
		} else {
			// Format: one URL per line, sequential → index.html, index-2.html, ...
			$counter = 0;
			foreach ( $lines as $line ) {
				$url = trim( $line );
				if ( empty( $url ) || 0 === strpos( $url, '#' ) ) {
					continue;
				}
				++$counter;
				$filename = 1 === $counter ? 'index.html' : "index-{$counter}.html";
				$local    = $root_dir . DIRECTORY_SEPARATOR . $filename;
				// Also check .htm variant.
				if ( ! is_file( $local ) ) {
					$local = $root_dir . DIRECTORY_SEPARATOR . 'index-' . $counter . '.htm';
				}
				if ( ! is_file( $local ) && 1 === $counter ) {
					$local = $root_dir . DIRECTORY_SEPARATOR . 'index.htm';
				}
				if ( is_file( $local ) ) {
					$map[ $local ] = $url;
				}
			}
		}

		return $map;
	}

	/**
	 * Resolve an HTTrack local path to a real filesystem path.
	 *
	 * HTTrack caches absolute paths from the original machine (e.g.
	 * C:/My Web Sites/.../www.example.com/index.html).  This method
	 * strips everything up to and including the mirror root directory
	 * name and rebuilds the path relative to the current root_dir.
	 *
	 * @since  1.4.1
	 *
	 * @param  string $raw_path  Raw local path from new.txt (may be URL-encoded).
	 * @param  string $root_dir  Current mirror root directory.
	 * @return string|null       Resolved absolute path, or null if unresolvable.
	 */
	private function resolve_httrack_local_path( $raw_path, $root_dir ) {
		$decoded = rawurldecode( $raw_path );
		$decoded = str_replace( '\\', '/', $decoded );

		// Find the mirror root directory name in the path and strip everything before it.
		$root_name = basename( $root_dir );
		$pos       = strrpos( $decoded, '/' . $root_name . '/' );
		if ( false !== $pos ) {
			$relative = substr( $decoded, $pos + strlen( $root_name ) + 1 );
			return $root_dir . DIRECTORY_SEPARATOR . $relative;
		}

		// Fallback: try matching just by filename inside root_dir.
		$filename  = basename( $decoded );
		$candidate = $root_dir . DIRECTORY_SEPARATOR . $filename;
		if ( is_file( $candidate ) ) {
			return $candidate;
		}

		return null;
	}

	/**
	 * For HTTrack mirrors: after discovering flat files, map each file to
	 * its original URL by scanning the <!-- Mirrored from ... --> comment.
	 *
	 * Called from discover_httrack_files() after collect_httrack_flat_files().
	 *
	 * @since  1.4.1
	 *
	 * @param array $files    Flat file paths.
	 * @param array $url_map  Existing URL map (populated from cache).
	 * @return void           $url_map is modified in place.
	 */
	private function enrich_url_map_from_comments( array $files, array &$url_map ) {
		foreach ( $files as $file_path ) {
			if ( isset( $url_map[ $file_path ] ) ) {
				continue;
			}
			// Read just the first 500 bytes to find the HTTrack comment.
			$head = @file_get_contents( $file_path, false, null, 0, 500 );
			if ( false === $head ) {
				continue;
			}
			// <!-- Mirrored from www.talesofceylon.com/destinations/kandy/ by HTTrack ... -->
			if ( preg_match( '#Mirrored from\s+(\S+)#i', $head, $m ) ) {
				$mirror_url = rtrim( $m[1], '/' );
				// Add https:// if no protocol.
				if ( ! preg_match( '#^https?://#', $mirror_url ) ) {
					$mirror_url = 'https://' . $mirror_url;
				}
				$url_map[ $file_path ] = $mirror_url . '/';
			}
		}
	}

	// -------------------------------------------------------------------------
	// HTML extraction
	// -------------------------------------------------------------------------

	/**
	 * Extract place data from a single HTML file.
	 *
	 * @param string $file_path     Path to HTML file.
	 * @param array  $rules         Extraction selectors.
	 * @param array  $type_mapping  URL path → place type mapping.
	 * @param string $default_type  Default place type.
	 * @param string $default_country Default country.
	 * @return array Extracted place data.
	 */
	public function extract_page_data( $file_path, array $rules, array $type_mapping, $default_type, $default_country ) {
		$data = array(
			'name'           => '',
			'description'    => '',
			'place_type'     => $default_type,
			'city'           => '',
			'country'        => $default_country,
			'source_url'     => '',
			'hero_image_url' => '',
			'image_url'      => '',
			'latitude'       => null,
			'longitude'      => null,
			'tags'           => array(),
		);

		// Suppress DOM warnings for malformed HTML.
		$libxml_use_internal = libxml_use_internal_errors( true );

		if ( ! is_readable( $file_path ) ) {
			libxml_use_internal_errors( $libxml_use_internal );
			return $data;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions -- local file read, not remote URL
		$html = file_get_contents( $file_path );
		if ( false === $html ) {
			libxml_use_internal_errors( $libxml_use_internal );
			return $data;
		}

		$doc = new DOMDocument();
		$doc->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ) );
		$xpath = new DOMXPath( $doc );

		// --- Follow HTTrack meta-refresh redirect stubs ---
		// Flat index*.html files are often just redirect stubs.
		// Load the real tree file for content and source URL extraction.
		$redirect_target = $this->detect_httrack_redirect( $html, $file_path );
		if ( $redirect_target ) {
			$real_html = @file_get_contents( $redirect_target );
			if ( false !== $real_html ) {
				$html = $real_html;
				$doc  = new DOMDocument();
				@$doc->loadHTML( mb_convert_encoding( $real_html, 'HTML-ENTITIES', 'UTF-8' ) );
				$xpath = new DOMXPath( $doc );
			}
		}

		// --- Title ---
		$title_nodes = $xpath->query( $rules['title_selector'] );
		if ( $title_nodes->length > 0 ) {
			$title = trim( $title_nodes->item( 0 )->textContent );
			// Strip site name suffix (e.g. "Kandy - Tales of Ceylon" → "Kandy").
			$title        = preg_replace( '/\s*[-–|—]\s*[^-–|—]+$/', '', $title );
			$data['name'] = sanitize_text_field( $title );
		}

		// --- Skip HTTrack redirect pages ---
		// HTTrack stores WordPress redirect pages with title "Page has moved".
		// These are empty pages that should be skipped entirely.
		if ( 'Page has moved' === $data['name'] ) {
			$data['name'] = '';
			libxml_use_internal_errors( $libxml_use_internal );
			return $data;
		}

		// Fall back to H1 if title not useful.
		if ( empty( $data['name'] ) && ! empty( $rules['h1_selector'] ) ) {
			$h1_nodes = $xpath->query( $rules['h1_selector'] );
			if ( $h1_nodes->length > 0 ) {
				$h1_text      = trim( $h1_nodes->item( 0 )->textContent );
				$data['name'] = sanitize_text_field( $h1_text );
			}
		}

		// --- Meta description ---
		if ( ! empty( $rules['description_selector'] ) ) {
			// This may be a direct @content attribute query.
			$desc_result = $xpath->query( $rules['description_selector'] );
			if ( $desc_result->length > 0 ) {
				$first               = $desc_result->item( 0 );
				$desc_value          = $first instanceof DOMAttr ? $first->value : trim( $first->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$data['description'] = sanitize_text_field( $desc_value );
			}
		}

		// --- Main content (longer description) ---
		if ( ! empty( $rules['main_content_selector'] ) ) {
			$content_nodes = $xpath->query( $rules['main_content_selector'] );
			if ( $content_nodes->length > 0 ) {
				$paragraphs = array();
				foreach ( $content_nodes->item( 0 )->getElementsByTagName( 'p' ) as $p ) {
					$text = trim( $p->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					if ( ! empty( $text ) ) {
						$paragraphs[] = $text;
					}
					if ( count( $paragraphs ) >= 5 ) {
						break; // Limit to first 5 paragraphs.
					}
				}
				if ( ! empty( $paragraphs ) ) {
					$data['description'] = sanitize_text_field( implode( "\n\n", $paragraphs ) );
				}
			}
		}

		// --- OG Image ---
		if ( ! empty( $rules['image_selector'] ) ) {
			$img_result = $xpath->query( $rules['image_selector'] );
			if ( $img_result->length > 0 ) {
				$first                  = $img_result->item( 0 );
				$data['image_url']      = $first instanceof DOMAttr ? $first->value : trim( $first->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$data['hero_image_url'] = $data['image_url'];
			}
		}

		// --- Hero image fallback ---
		if ( empty( $data['hero_image_url'] ) && ! empty( $rules['hero_image_selector'] ) ) {
			$hero_nodes = $xpath->query( $rules['hero_image_selector'] );
			if ( $hero_nodes->length > 0 ) {
				$hero = $hero_nodes->item( 0 );
				$src  = $hero->getAttribute( 'data-src' );
				if ( empty( $src ) ) {
					$src = $hero->getAttribute( 'src' );
				}
				if ( ! empty( $src ) ) {
					$data['hero_image_url'] = $this->resolve_url( $src, $data['source_url'] );
				}
			}
		}

		// --- Canonical / source URL ---
		$canonical_nodes = $xpath->query( '//link[@rel="canonical"]/@href' );
		if ( $canonical_nodes->length > 0 ) {
			$data['source_url'] = trim( $canonical_nodes->item( 0 )->value );
		}

		// --- HTTrack mirrored-from comment fallback ---
		// HTTrack stores the original URL inside an HTML comment:
		// <!-- Mirrored from https://www.example.com/page/ by HTTrack ... -->
		if ( empty( $data['source_url'] ) || ! preg_match( '#^https?://#', $data['source_url'] ) ) {
			if ( preg_match( '/Mirrored from (\S+)/', $html, $m ) ) {
				$url = trim( $m[1] );
				if ( ! preg_match( '#^https?://#', $url ) ) {
					$url = 'https://' . $url;
				}
				$data['source_url'] = $url;
			}
		}

		// --- Google Maps iframe → coordinates ---
		if ( ! empty( $rules['maps_iframe_selector'] ) ) {
			$iframe_nodes = $xpath->query( $rules['maps_iframe_selector'] );
			if ( $iframe_nodes->length > 0 ) {
				$src = $iframe_nodes->item( 0 )->getAttribute( 'src' );
				if ( ! empty( $src ) ) {
					$coords = $this->extract_coords_from_maps_url( $src );
					if ( $coords ) {
						$data['latitude']  = $coords['lat'];
						$data['longitude'] = $coords['lng'];
					}
				}
			}
		}

		// --- JSON-LD structured data ---
		if ( ! empty( $rules['json_ld_selector'] ) ) {
			$ld_nodes = $xpath->query( $rules['json_ld_selector'] );
			foreach ( $ld_nodes as $node ) {
				$ld = json_decode( trim( $node->textContent ), true ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( $ld && isset( $ld['@graph'] ) ) {
					foreach ( $ld['@graph'] as $item ) {
						if ( isset( $item['@type'] ) && 'WebPage' === $item['@type'] ) {
							if ( ! empty( $item['description'] ) && empty( $data['description'] ) ) {
								$data['description'] = sanitize_text_field( $item['description'] );
							}
						}
						if ( isset( $item['@type'] ) && 'BreadcrumbList' === $item['@type'] ) {
							$data = $this->extract_breadcrumb_context( $item, $data, $default_country );
						}
					}
				}
			}
		}

		// --- Breadcrumbs (HTML) ---
		if ( ! empty( $rules['breadcrumb_selector'] ) ) {
			$bc_nodes = $xpath->query( $rules['breadcrumb_selector'] );
			if ( $bc_nodes->length > 0 ) {
				foreach ( $bc_nodes as $li ) {
					$text = trim( $li->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					if ( empty( $data['city'] ) && ! empty( $text ) ) {
						// Try to infer city from breadcrumb context.
						$text_lower = strtolower( $text );
						if ( ! in_array( $text_lower, array( 'home', 'destinations', 'experiences', 'sri lanka' ), true ) ) {
							$data['city'] = sanitize_text_field( $text );
						}
					}
				}
			}
		}

		// --- Classify place type from URL ---
		$data['place_type'] = $this->classify_page_type( $data['source_url'], $type_mapping, $default_type );

		// --- Tips as tags/amenities ---
		if ( ! empty( $rules['tips_selector'] ) ) {
			$tip_nodes = $xpath->query( $rules['tips_selector'] );
			foreach ( $tip_nodes as $li ) {
				$text = trim( $li->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( ! empty( $text ) ) {
					$data['tags'][] = sanitize_text_field( $text );
				}
			}
		}

		libxml_use_internal_errors( $libxml_use_internal );

		return $data;
	}

	/**
	 * Detect HTTrack meta-refresh redirect stub and resolve target file.
	 *
	 * @param string $html      Raw HTML of the flat file.
	 * @param string $file_path Path to the flat file.
	 * @return string|null Resolved path to real content file, or null.
	 */
	private function detect_httrack_redirect( $html, $file_path ) {
		if ( false === stripos( $html, 'Page has moved' ) ) {
			return null;
		}
		if ( ! preg_match( '/<meta[^>]+http-equiv\s*=\s*["\']?\s*refresh\s*["\']?[^>]+url\s*=\s*([^"\'\s>]+)/i', $html, $m ) ) {
			return null;
		}
		$target = trim( $m[1], '"\' ' );
		if ( empty( $target ) ) {
			return null;
		}
		$dir      = dirname( $file_path );
		$resolved = $dir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $target );
		$resolved = realpath( $resolved );
		return ( $resolved && is_readable( $resolved ) ) ? $resolved : null;
	}

	// -------------------------------------------------------------------------
	// Utilities
	// -------------------------------------------------------------------------

	/**
	 * Extract latitude and longitude from a Google Maps embed URL.
	 *
	 * Handles patterns like:
	 *   !3d7.2906!4d80.6337  (new format)
	 *   ll=7.2906,80.6337    (old format)
	 *
	 *   @7.2906,80.6337      (place URL)
	 *
	 * @param string $url Maps URL.
	 * @return array|null {lat, lng} or null.
	 */
	private function extract_coords_from_maps_url( $url ) {
		// Handle the newer embed format (!3d LAT !4d LNG).
		if ( preg_match( '/!3d([-\d.]+)!4d([-\d.]+)/', $url, $m ) ) {
			return array(
				'lat' => floatval( $m[1] ),
				'lng' => floatval( $m[2] ),
			);
		}

		// Handle alternative embed format (!2d LNG !3d LAT).
		if ( preg_match( '/!2d([-\d.]+)!3d([-\d.]+)/', $url, $m ) ) {
			return array(
				'lat' => floatval( $m[2] ),
				'lng' => floatval( $m[1] ),
			);
		}

		// Handle the older query-string format.
		if ( preg_match( '/ll=([-\d.]+),([-\d.]+)/', $url, $m ) ) {
			return array(
				'lat' => floatval( $m[1] ),
				'lng' => floatval( $m[2] ),
			);
		}

		// Handle place URLs with at-sign coordinates.
		if ( preg_match( '/@([-\d.]+),([-\d.]+)/', $url, $m ) ) {
			return array(
				'lat' => floatval( $m[1] ),
				'lng' => floatval( $m[2] ),
			);
		}

		return null;
	}

	/**
	 * Classify a page's place type based on its URL path.
	 *
	 * @param string $url          Canonical URL or file path.
	 * @param array  $mapping      Path pattern → place type mapping.
	 * @param string $default_type Fallback type.
	 * @return string Place type.
	 */
	private function classify_page_type( $url, array $mapping, $default_type ) {
		if ( empty( $url ) || empty( $mapping ) ) {
			return $default_type;
		}

		// Score patterns by position in URL (later position = more specific).
		// This ensures /hotels-in-kandy/ wins over /destinations/ when both match
		// the URL /destinations/kandy/hotels-in-kandy/luxury-hotels/.
		$best_type  = $default_type;
		$best_score = -1;

		foreach ( $mapping as $pattern => $type ) {
			$pos = strpos( $url, $pattern );
			if ( false !== $pos && $pos >= $best_score ) {
				$best_score = $pos;
				$best_type  = $type;
			}
		}

		return $best_type;
	}

	/**
	 * Resolve a relative URL to absolute using the page's source URL as base.
	 *
	 * @param string $src        Image src attribute.
	 * @param string $source_url Canonical URL of the page.
	 * @return string Resolved URL.
	 */
	private function resolve_url( $src, $source_url ) {
		if ( empty( $src ) ) {
			return '';
		}

		// Already absolute.
		if ( preg_match( '#^https?://#', $src ) ) {
			return $src;
		}

		// Relative to source URL.
		if ( ! empty( $source_url ) ) {
			$parts = wp_parse_url( $source_url );
			if ( ! $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
				return $src;
			}

			$base_path = isset( $parts['path'] ) ? dirname( $parts['path'] ) : '/';
			$base_path = rtrim( $base_path, '/' );

			// Handle leading ../ by walking up the base path.
			while ( strpos( $src, '../' ) === 0 ) {
				$src       = substr( $src, 3 );
				$base_path = dirname( $base_path );
			}

			// Handle leading ./ or just path.
			if ( strpos( $src, './' ) === 0 ) {
				$src = substr( $src, 2 );
			}

			return $parts['scheme'] . '://' . $parts['host']
				. $base_path . '/' . ltrim( $src, '/' );
		}

		return $src;
	}

	/**
	 * Extract context (city/country) from BreadcrumbList JSON-LD.
	 *
	 * @param array  $breadcrumb     BreadcrumbList structured data.
	 * @param array  $data           Current place data.
	 * @param string $default_country Default country.
	 * @return array Updated place data.
	 */
	private function extract_breadcrumb_context( $breadcrumb, array $data, $default_country ) {
		if ( ! isset( $breadcrumb['itemListElement'] ) ) {
			return $data;
		}

		$names = array();
		foreach ( $breadcrumb['itemListElement'] as $element ) {
			if ( isset( $element['item']['name'] ) ) {
				$names[] = $element['item']['name'];
			}
		}

		// First meaningful breadcrumb after "Home" is usually the city.
		$skip = array( 'home' );
		foreach ( $names as $name ) {
			$name_lower = strtolower( $name );
			if ( ! in_array( $name_lower, $skip, true ) && empty( $data['city'] ) ) {
				$data['city'] = sanitize_text_field( $name );
				break;
			}
		}

		// Set country if not already set.
		if ( empty( $data['country'] ) && ! empty( $default_country ) ) {
			$data['country'] = $default_country;
		}

		return $data;
	}
}
