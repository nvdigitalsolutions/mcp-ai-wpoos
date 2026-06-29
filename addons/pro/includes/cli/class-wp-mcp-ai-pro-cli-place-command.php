<?php
/**
 * WP-CLI commands for Places Management.
 *
 * Provides bulk import, export, and deduplication commands for the
 * mcp_ai_place custom post type.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage CLI
 * @since     1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manages Place CPT records from the command line.
 *
 * ## EXAMPLES
 *
 *     # Import places from a JSON file.
 *     wp mcp place import /path/to/places.json --format=json
 *
 *     # Import from CSV with dry-run.
 *     wp mcp place import /path/to/places.csv --format=csv --dry-run
 *
 *     # Import from an HTML directory.
 *     wp mcp place import-html /path/to/site-mirror --country="Sri Lanka"
 *
 *     # Export all places to CSV.
 *     wp mcp place export --format=csv --output=/tmp/places.csv
 *
 *     # Find duplicate places.
 *     wp mcp place dedup --strategy=name_and_city --dry-run
 *
 *     # Multi-phase site mirror import (cities then children).
 *     wp mcp place site-import /path/to/site-mirror --country="Sri Lanka"
 *
 *     # Site mirror import with dry-run.
 *     wp mcp place site-import /path/to/site-mirror --country="Sri Lanka" --dry-run
 *
 * @since 1.4.0
 */
class WP_MCP_AI_Pro_CLI_Place_Command extends WP_MCP_AI_Pro_CLI_Base_Command {

	/**
	 * Import places from a JSON or CSV file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the import file.
	 *
	 * [--format=<format>]
	 * : File format. One of json, csv.
	 * ---
	 * default: json
	 * ---
	 *
	 * [--parent-id=<id>]
	 * : Default parent place ID for all imported items.
	 *
	 * [--country=<country>]
	 * : Default country.
	 *
	 * [--skip-existing]
	 * : Skip places that already exist. (default: true)
	 *
	 * [--update-existing]
	 * : Update existing places with fresh data.
	 *
	 * [--dedup-strategy=<strategy>]
	 * : Deduplication strategy.
	 * ---
	 * default: name_and_city
	 * options:
	 *   - name
	 *   - google_place_id
	 *   - source_url
	 *   - name_and_city
	 *   - name_and_lat_lng
	 * ---
	 *
	 * [--dry-run]
	 * : Preview without creating anything.
	 *
	 * [--batch-size=<size>]
	 * : Items per batch.
	 * ---
	 * default: 50
	 * ---
	 *
	 * @param array $args        Positional arguments.
	 * @param array $assoc_args  Associative arguments.
	 * @return void
	 */
	public function import( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_places_management', 'Places Management' );

		$file = isset( $args[0] ) ? $args[0] : '';
		if ( empty( $file ) || ! file_exists( $file ) ) {
			WP_CLI::error( __( 'File not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$format = $this->get_format( $assoc_args, 'json' );
		if ( ! in_array( $format, array( 'json', 'csv' ), true ) ) {
			WP_CLI::error( __( 'Format must be json or csv.', 'mcp-ai-wpoos-pro' ) );
		}

		$is_dry_run = $this->is_dry_run( $assoc_args );
		if ( $is_dry_run ) {
			$this->dry_run_notice();
		}

		// Build arguments for the import_places tool.
		$tool_args = array(
			'skip_existing'   => ! WP_CLI\Utils\get_flag_value( $assoc_args, 'update-existing', false ),
			'update_existing' => WP_CLI\Utils\get_flag_value( $assoc_args, 'update-existing', false ),
			'dedup_strategy'  => WP_CLI\Utils\get_flag_value( $assoc_args, 'dedup-strategy', 'name_and_city' ),
			'dry_run'         => $is_dry_run,
			'batch_size'      => absint( WP_CLI\Utils\get_flag_value( $assoc_args, 'batch-size', 50 ) ),
		);

		$parent_id = WP_CLI\Utils\get_flag_value( $assoc_args, 'parent-id' );
		if ( $parent_id ) {
			$tool_args['parent_place_id'] = absint( $parent_id );
		}

		$raw = file_get_contents( $file );
		if ( false === $raw ) {
			WP_CLI::error( __( 'Failed to read file.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( 'csv' === $format ) {
			$tool_args['source']     = 'csv';
			$tool_args['csv_string'] = $raw;
		} else {
			$tool_args['source']      = 'json';
			$tool_args['json_string'] = $raw;
		}

		// Apply country default.
		$country = WP_CLI\Utils\get_flag_value( $assoc_args, 'country' );
		if ( $country ) {
			if ( 'csv' === $format ) {
				WP_CLI::warning( __( '--country is not automatically applied to CSV imports; include a country column in the CSV.', 'mcp-ai-wpoos-pro' ) );
			}
		}

		$this->start_timer();

		if ( ! class_exists( 'WP_MCP_AI_Tool_Import_Places' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/places/class-wp-mcp-ai-tool-import-places.php';
		}

		$tool   = new WP_MCP_AI_Tool_Import_Places();
		$result = $tool->execute( $tool_args, array( 'user_id' => get_current_user_id() ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$this->display_summary(
			array(
				'total'         => $result['total'],
				'success_count' => $result['created'] + $result['updated'],
				'error_count'   => $result['failed'] + $result['skipped'],
				'errors'        => $result['errors'],
			)
		);
	}

	/**
	 * Import places from a directory of HTML files.
	 *
	 * ## OPTIONS
	 *
	 * <directory>
	 * : Path to the directory containing HTML files.
	 *
	 * [--recursive]
	 * : Recurse into subdirectories. (default: true)
	 *
	 * [--max-pages=<n>]
	 * : Maximum pages to process.
	 * ---
	 * default: 500
	 * ---
	 *
	 * [--country=<country>]
	 * : Default country for all imported places.
	 *
	 * [--parent-page=<path>]
	 * : Path to a parent page whose place should be the parent of all imports.
	 *
	 * [--type-mapping=<json>]
	 * : JSON object mapping URL path patterns to place types.
	 *
	 * [--default-type=<type>]
	 * : Default place type.
	 * ---
	 * default: attraction
	 * ---
	 *
	 * [--dry-run]
	 * : Preview without importing.
	 *
	 * @param array $args        Positional arguments.
	 * @param array $assoc_args  Associative arguments.
	 * @return void
	 */
	public function import_html( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_places_management', 'Places Management' );

		$dir = isset( $args[0] ) ? $args[0] : '';
		if ( empty( $dir ) || ! is_dir( $dir ) ) {
			WP_CLI::error( __( 'Directory not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$is_dry_run = $this->is_dry_run( $assoc_args );
		if ( $is_dry_run ) {
			$this->dry_run_notice();
		}

		$tool_args = array(
			'source_directory'   => $dir,
			'recursive'          => ! WP_CLI\Utils\get_flag_value( $assoc_args, 'no-recursive', false ),
			'max_pages'          => absint( WP_CLI\Utils\get_flag_value( $assoc_args, 'max-pages', 500 ) ),
			'default_country'    => WP_CLI\Utils\get_flag_value( $assoc_args, 'country', '' ),
			'parent_page_path'   => WP_CLI\Utils\get_flag_value( $assoc_args, 'parent-page', '' ),
			'default_place_type' => WP_CLI\Utils\get_flag_value( $assoc_args, 'default-type', 'attraction' ),
			'dry_run'            => $is_dry_run,
			'skip_existing'      => true,
		);

		$type_mapping_json = WP_CLI\Utils\get_flag_value( $assoc_args, 'type-mapping' );
		if ( $type_mapping_json ) {
			$mapping = json_decode( $type_mapping_json, true );
			if ( is_array( $mapping ) ) {
				$tool_args['place_type_mapping'] = $mapping;
			}
		}

		$this->start_timer();

		if ( ! class_exists( 'WP_MCP_AI_Tool_Import_Places_From_Html' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/places/class-wp-mcp-ai-tool-import-places-from-html.php';
		}

		$tool   = new WP_MCP_AI_Tool_Import_Places_From_Html();
		$result = $tool->execute( $tool_args, array( 'user_id' => get_current_user_id() ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%G' . __( 'HTML Import Summary:', 'mcp-ai-wpoos-pro' ) . '%n' ) );
		/* translators: %d: number of files found */
		WP_CLI::log( sprintf( __( '  Files found: %d', 'mcp-ai-wpoos-pro' ), $result['files_found'] ) );
		/* translators: %d: number of places created */
		WP_CLI::log( sprintf( __( '  Created: %d', 'mcp-ai-wpoos-pro' ), $result['created'] ) );
		/* translators: %d: number of places skipped */
		WP_CLI::log( sprintf( __( '  Skipped: %d', 'mcp-ai-wpoos-pro' ), $result['skipped'] ) );
		/* translators: %d: number of places failed */
		WP_CLI::log( sprintf( __( '  Failed: %d', 'mcp-ai-wpoos-pro' ), $result['failed'] ) );

		if ( ! $is_dry_run && ! empty( $result['ids'] ) ) {
			WP_CLI::success(
				sprintf(
					/* translators: %d: number of places imported */
					__( 'Successfully imported %d places.', 'mcp-ai-wpoos-pro' ),
					count( $result['ids'] )
				)
			);
		}
	}

	/**
	 * Export places to a file.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - csv
	 * ---
	 *
	 * [--output=<file>]
	 * : Output file path. If omitted, prints to STDOUT.
	 *
	 * [--place-type=<type>]
	 * : Filter by place type.
	 *
	 * [--city=<city>]
	 * : Filter by city.
	 *
	 * [--limit=<n>]
	 * : Maximum number of places to export.
	 * ---
	 * default: 1000
	 * ---
	 *
	 * @param array $args        Positional arguments.
	 * @param array $assoc_args  Associative arguments.
	 * @return void
	 */
	public function export( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_places_management', 'Places Management' );

		$format     = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'json' );
		$output     = WP_CLI\Utils\get_flag_value( $assoc_args, 'output', '' );
		$place_type = WP_CLI\Utils\get_flag_value( $assoc_args, 'place-type', '' );
		$city       = WP_CLI\Utils\get_flag_value( $assoc_args, 'city', '' );
		$limit      = absint( WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 1000 ) );

		$query_args = array(
			'post_type'      => 'mcp_ai_place',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);

		if ( ! empty( $place_type ) ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'mcp_ai_place_type',
					'field'    => 'slug',
					'terms'    => $place_type,
				),
			);
		}

		if ( ! empty( $city ) ) {
			$query_args['meta_query'] = array(
				array(
					'key'   => '_place_city',
					'value' => $city,
				),
			);
		}

		$query  = new WP_Query( $query_args );
		$places = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id = get_the_ID();

				$places[] = array(
					'id'          => $post_id,
					'name'        => get_the_title(),
					'description' => get_the_content(),
					'place_type'  => implode( ', ', wp_get_object_terms( $post_id, 'mcp_ai_place_type', array( 'fields' => 'names' ) ) ),
					'address'     => get_post_meta( $post_id, '_place_address', true ),
					'city'        => get_post_meta( $post_id, '_place_city', true ),
					'country'     => get_post_meta( $post_id, '_place_country', true ),
					'latitude'    => get_post_meta( $post_id, '_place_latitude', true ),
					'longitude'   => get_post_meta( $post_id, '_place_longitude', true ),
					'phone'       => get_post_meta( $post_id, '_place_phone', true ),
					'email'       => get_post_meta( $post_id, '_place_email', true ),
					'website'     => get_post_meta( $post_id, '_place_website', true ),
					'rating'      => get_post_meta( $post_id, '_place_rating', true ),
					'price_level' => get_post_meta( $post_id, '_place_price_level', true ),
					'source_url'  => get_post_meta( $post_id, '_place_source_url', true ),
				);
			}
			wp_reset_postdata();
		}

		if ( empty( $places ) ) {
			WP_CLI::log( __( 'No places found.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		if ( 'csv' === $format ) {
			$csv = $this->places_to_csv( $places );
			if ( $output ) {
				file_put_contents( $output, $csv );
				/* translators: 1: number of places, 2: output file path */
				WP_CLI::success( sprintf( __( 'Exported %1$d places to %2$s.', 'mcp-ai-wpoos-pro' ), count( $places ), $output ) );
			} else {
				WP_CLI::log( $csv );
			}
		} else {
			$json = wp_json_encode( $places, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
			if ( $output ) {
				file_put_contents( $output, $json );
				/* translators: 1: number of places, 2: output file path */
				WP_CLI::success( sprintf( __( 'Exported %1$d places to %2$s.', 'mcp-ai-wpoos-pro' ), count( $places ), $output ) );
			} else {
				WP_CLI::log( $json );
			}
		}
	}

	/**
	 * Multi-phase site mirror import: cities first, then children auto-linked.
	 *
	 * Imports a static HTML site export (HTTrack, Wayback Machine, etc.) in
	 * two phases so that child pages (attractions, hotels, tales) are
	 * automatically linked to their parent city via parent_place_id.
	 *
	 * ## OPTIONS
	 *
	 * <source-dir>
	 * : Root directory of the site mirror (e.g. the www.example.com folder).
	 *
	 * [--country=<country>]
	 * : Default country for all imported places.
	 * ---
	 * default: ""
	 * ---
	 *
	 * [--city-subdir=<path>]
	 * : Subdirectory containing city/destination pages.
	 * ---
	 * default: destinations
	 * ---
	 *
	 * [--experience-subdir=<path>]
	 * : Subdirectory containing experience pages (optional).
	 * ---
	 * default: experiences
	 * ---
	 *
	 * [--city-type-mapping=<json>]
	 * : JSON object mapping URL path patterns to place types for city pages.
	 * ---
	 * default: {"/destinations/":"city"}
	 * ---
	 *
	 * [--child-type-mapping=<json>]
	 * : JSON object mapping URL path patterns to place types for child pages.
	 * ---
	 * default: {"/attractions-in-":"attraction","/hotels-in-":"hotel","/tales-of-":"tale","/experiences-in-":"experience","/plan-your-holiday-in-":"itinerary"}
	 * ---
	 *
	 * [--import-experiences]
	 * : Also import the experiences subdirectory (phase 3).
	 *
	 * [--max-pages=<n>]
	 * : Maximum pages per phase.
	 * ---
	 * default: 2000
	 * ---
	 *
	 * [--dry-run]
	 * : Preview without creating anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp place site-import wp-content/uploads/Tales\ of\ Celon/www.talesofceylon.com --country="Sri Lanka"
	 *     wp mcp place site-import ./site-mirror --country="France" --dry-run
	 *
	 * @param array $args        Positional arguments.
	 * @param array $assoc_args  Associative arguments.
	 * @return void
	 */
	public function site_import( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_places_management', 'Places Management' );

		$source_dir = isset( $args[0] ) ? rtrim( $args[0], '/\\' ) : '';
		if ( empty( $source_dir ) || ! is_dir( $source_dir ) ) {
			WP_CLI::error( __( 'Source directory not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$is_dry_run = $this->is_dry_run( $assoc_args );
		if ( $is_dry_run ) {
			$this->dry_run_notice();
		}

		$country             = WP_CLI\Utils\get_flag_value( $assoc_args, 'country', '' );
		$city_subdir         = WP_CLI\Utils\get_flag_value( $assoc_args, 'city-subdir', 'destinations' );
		$experience_subdir   = WP_CLI\Utils\get_flag_value( $assoc_args, 'experience-subdir', 'experiences' );
		$import_experiences  = WP_CLI\Utils\get_flag_value( $assoc_args, 'import-experiences', false );
		$max_pages           = absint( WP_CLI\Utils\get_flag_value( $assoc_args, 'max-pages', 2000 ) );

		// Default type mappings.
		$city_type_mapping_json  = WP_CLI\Utils\get_flag_value(
			$assoc_args,
			'city-type-mapping',
			'{"/destinations/":"city"}'
		);
		$child_type_mapping_json = WP_CLI\Utils\get_flag_value(
			$assoc_args,
			'child-type-mapping',
			'{"/attractions-in-":"attraction","/hotels-in-":"hotel","/tales-of-":"tale","/experiences-in-":"experience","/plan-your-holiday-in-":"itinerary"}'
		);

		$city_mapping_decoded  = json_decode( $city_type_mapping_json, true );
		$city_type_mapping     = is_array( $city_mapping_decoded ) ? $city_mapping_decoded : array();
		$child_mapping_decoded = json_decode( $child_type_mapping_json, true );
		$child_type_mapping    = is_array( $child_mapping_decoded ) ? $child_mapping_decoded : array();

		// Ensure tool classes are loaded.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Import_Places_From_Html' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/places/class-wp-mcp-ai-tool-import-places-from-html.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Place_Helper' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/helpers/class-wp-mcp-ai-place-helper.php';
		}

		$grand_total = array(
			'created' => 0,
			'skipped' => 0,
			'failed'  => 0,
		);

		$this->start_timer();

		// -----------------------------------------------------------------
		// Phase 1: Import cities from the cities subdirectory.
		// -----------------------------------------------------------------
		$city_dir = $source_dir . '/' . $city_subdir;

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%G' . __( 'Phase 1: Importing cities...', 'mcp-ai-wpoos-pro' ) . '%n' ) );
		/* translators: %s: directory path */
		WP_CLI::log( sprintf( __( '  Directory: %s', 'mcp-ai-wpoos-pro' ), $city_dir ) );

		if ( ! is_dir( $city_dir ) ) {
			/* translators: %s: directory path */
			WP_CLI::warning( sprintf( __( 'City subdirectory not found: %s', 'mcp-ai-wpoos-pro' ), $city_dir ) );
		} else {
			$city_result = $this->run_html_import(
				$city_dir,
				$country,
				$city_type_mapping,
				'city',
				'', // no parent for cities.
				false, // non-recursive: only top-level city index files.
				$max_pages,
				$is_dry_run
			);

			$this->add_to_totals( $grand_total, $city_result );
			$this->print_phase_summary( __( 'Phase 1 (cities)', 'mcp-ai-wpoos-pro' ), $city_result );
		}

		// -----------------------------------------------------------------
		// Phase 2: Import child pages for each city.
		// -----------------------------------------------------------------
		if ( ! $is_dry_run ) {
			$city_places = $this->get_imported_cities( $source_dir );
		} else {
			// In dry-run, discover city subdirs from filesystem.
			$city_places = $this->discover_city_dirs( $city_dir );
		}

		if ( empty( $city_places ) ) {
			WP_CLI::warning( __( 'No cities found for child import. Did Phase 1 complete?', 'mcp-ai-wpoos-pro' ) );
		} else {
			WP_CLI::log( '' );
			WP_CLI::log( WP_CLI::colorize( '%G' . __( 'Phase 2: Importing children for each city…', 'mcp-ai-wpoos-pro' ) . '%n' ) );
			/* translators: %d: number of cities */
			WP_CLI::log( sprintf( __( '  Cities to process: %d', 'mcp-ai-wpoos-pro' ), count( $city_places ) ) );

			$city_number = 0;
			foreach ( $city_places as $city ) {
				$city_number++;
				$city_name  = $city['name'];
				$city_dir   = $city['dir'];
				$parent_id  = $city['place_id'];
				$parent_page = $city['index_page'];

				WP_CLI::log( '' );
				WP_CLI::log(
					sprintf(
						/* translators: 1: city number, 2: total cities, 3: city name */
						__( '  City %1$d/%2$d: %3$s', 'mcp-ai-wpoos-pro' ),
						$city_number,
						count( $city_places ),
						$city_name
					)
				);

				$child_result = $this->run_html_import(
					$city_dir,
					$country,
					$child_type_mapping,
					'attraction',
					$parent_page,
					true, // recursive: walk into subdirs.
					$max_pages,
					$is_dry_run
				);

				$this->add_to_totals( $grand_total, $child_result );
			}

			$this->print_phase_summary( __( 'Phase 2 (children)', 'mcp-ai-wpoos-pro' ), $grand_total );
		}

		// -----------------------------------------------------------------
		// Phase 3 (optional): Import experiences.
		// -----------------------------------------------------------------
		if ( $import_experiences ) {
			$exp_dir = $source_dir . '/' . $experience_subdir;

			WP_CLI::log( '' );
			WP_CLI::log( WP_CLI::colorize( '%G' . __( 'Phase 3: Importing experiences...', 'mcp-ai-wpoos-pro' ) . '%n' ) );
			/* translators: %s: directory path */
			WP_CLI::log( sprintf( __( '  Directory: %s', 'mcp-ai-wpoos-pro' ), $exp_dir ) );

			if ( ! is_dir( $exp_dir ) ) {
				/* translators: %s: directory path */
				WP_CLI::warning( sprintf( __( 'Experiences subdirectory not found: %s', 'mcp-ai-wpoos-pro' ), $exp_dir ) );
			} else {
				$exp_result = $this->run_html_import(
					$exp_dir,
					$country,
					array( '/experiences/' => 'experience' ),
					'experience',
					'',
					true,
					$max_pages,
					$is_dry_run
				);

				$this->add_to_totals( $grand_total, $exp_result );
				$this->print_phase_summary( __( 'Phase 3 (experiences)', 'mcp-ai-wpoos-pro' ), $exp_result );
			}
		}

		// -----------------------------------------------------------------
		// Grand total.
		// -----------------------------------------------------------------
		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%G' . str_repeat( '─', 50 ) . '%n' ) );
		WP_CLI::log( WP_CLI::colorize( '%G' . __( 'Grand Total:', 'mcp-ai-wpoos-pro' ) . '%n' ) );
		/* translators: %d: number of places */
		WP_CLI::log( sprintf( __( '  Created: %d', 'mcp-ai-wpoos-pro' ), $grand_total['created'] ) );
		/* translators: %d: number of places */
		WP_CLI::log( sprintf( __( '  Skipped: %d', 'mcp-ai-wpoos-pro' ), $grand_total['skipped'] ) );
		/* translators: %d: number of places */
		WP_CLI::log( sprintf( __( '  Failed: %d', 'mcp-ai-wpoos-pro' ), $grand_total['failed'] ) );

		$elapsed = $this->get_elapsed_time();
		/* translators: %s: elapsed time */
		WP_CLI::log( sprintf( __( '  Time: %s seconds', 'mcp-ai-wpoos-pro' ), number_format( $elapsed, 2 ) ) );

		if ( $is_dry_run ) {
			WP_CLI::success( __( 'Dry run complete. No places were created.', 'mcp-ai-wpoos-pro' ) );
		} else {
			/* translators: %d: number of places */
			WP_CLI::success( sprintf( __( 'Site import complete. %d places imported across all phases.', 'mcp-ai-wpoos-pro' ), $grand_total['created'] ) );
		}
	}

	// ---------------------------------------------------------------------
	// Helpers for site_import
	// ---------------------------------------------------------------------

	/**
	 * Run a single HTML import and return result counts.
	 *
	 * @param string $dir          Directory to scan.
	 * @param string $country      Default country.
	 * @param array  $type_mapping Path → type mapping.
	 * @param string $default_type Default place type.
	 * @param string $parent_page  Parent page path (empty for none).
	 * @param bool   $recursive    Whether to recurse.
	 * @param int    $max_pages    Max pages.
	 * @param bool   $dry_run      Dry run mode.
	 * @return array{created:int, skipped:int, failed:int}
	 */
	private function run_html_import( $dir, $country, $type_mapping, $default_type, $parent_page, $recursive, $max_pages, $dry_run ) {
		$tool_args = array(
			'source_directory'   => $dir,
			'recursive'          => $recursive,
			'max_pages'          => $max_pages,
			'default_country'    => $country,
			'default_place_type' => $default_type,
			'parent_page_path'   => $parent_page,
			'place_type_mapping' => $type_mapping,
			'dry_run'            => $dry_run,
			'skip_existing'      => true,
		);

		$tool   = new WP_MCP_AI_Tool_Import_Places_From_Html();
		$result = $tool->execute( $tool_args, array( 'user_id' => get_current_user_id() ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::warning( $result->get_error_message() );
			return array(
				'created' => 0,
				'skipped' => 0,
				'failed'  => 0,
			);
		}

		return array(
			'created' => $result['created'],
			'skipped' => $result['skipped'],
			'failed'  => $result['failed'],
		);
	}

	/**
	 * Get imported cities from the Places CPT, matching by source URL to the
	 * site mirror directory. Returns city name, filesystem directory, place ID,
	 * and index page path.
	 *
	 * @param string $source_dir Root of the site mirror.
	 * @return array[] Each entry: name, dir, place_id, index_page.
	 */
	private function get_imported_cities( $source_dir ) {
		$cities = array();

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_place',
				'posts_per_page' => 100,
				'no_found_rows'  => true,
				'tax_query'      => array(
					array(
						'taxonomy' => 'mcp_ai_place_type',
						'field'    => 'slug',
						'terms'    => 'city',
					),
				),
			)
		);

		if ( ! $query->have_posts() ) {
			wp_reset_postdata();
			return $cities;
		}

		while ( $query->have_posts() ) {
			$query->the_post();
			$post_id    = get_the_ID();
			$name       = get_the_title();
			$source_url = get_post_meta( $post_id, '_place_source_url', true );

			if ( empty( $source_url ) ) {
				continue;
			}

			// Determine the city's subdirectory from the source URL.
			// Source URL format: https://www.example.com/destinations/city-name/.
			$city_slug = $this->extract_city_slug_from_url( $source_url );
			if ( empty( $city_slug ) ) {
				continue;
			}

			$city_dir     = $source_dir . '/destinations/' . $city_slug;
			$city_index   = $city_dir . '/index.html';

			// Best-effort: if index.html doesn't exist, try without.
			if ( ! file_exists( $city_index ) ) {
				// Try the directory itself.
				if ( ! is_dir( $city_dir ) ) {
					continue;
				}
			}

			$cities[] = array(
				'name'       => $name,
				'dir'        => $city_dir,
				'place_id'   => $post_id,
				'index_page' => file_exists( $city_index ) ? $city_index : '',
			);
		}

		wp_reset_postdata();
		return $cities;
	}

	/**
	 * Extract city slug from a source URL.
	 *
	 * "https://www.example.com/destinations/kandy/" → "kandy"
	 *
	 * @param string $url Source URL.
	 * @return string City slug or empty string.
	 */
	private function extract_city_slug_from_url( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( empty( $path ) ) {
			return '';
		}

		$path = trim( $path, '/' );
		$parts = explode( '/', $path );

		// Look for pattern: destinations/{slug} or just the last segment.
		foreach ( $parts as $i => $part ) {
			if ( 'destinations' === strtolower( $part ) && isset( $parts[ $i + 1 ] ) ) {
				return sanitize_title( $parts[ $i + 1 ] );
			}
		}

		// Fallback: last segment.
		$last = end( $parts );
		return sanitize_title( $last );
	}

	/**
	 * Fallback for dry-run: discover city directories from the filesystem.
	 *
	 * @param string $city_dir Path to the cities directory.
	 * @return array[] Same shape as get_imported_cities().
	 */
	private function discover_city_dirs( $city_dir ) {
		$cities = array();

		if ( ! is_dir( $city_dir ) ) {
			return $cities;
		}

		$iterator = new DirectoryIterator( $city_dir );
		foreach ( $iterator as $item ) {
			if ( ! $item->isDir() || $item->isDot() ) {
				continue;
			}

			$slug       = $item->getFilename();
			$dir        = $item->getRealPath();
			$index_page = $dir . '/index.html';

			$cities[] = array(
				'name'       => ucfirst( str_replace( '-', ' ', $slug ) ),
				'dir'        => $dir,
				'place_id'   => 0, // unknown in dry-run.
				'index_page' => file_exists( $index_page ) ? $index_page : '',
			);
		}

		return $cities;
	}

	/**
	 * Add result counts to a running total.
	 *
	 * @param array $totals Running total (by reference).
	 * @param array $result Single result.
	 * @return void
	 */
	private function add_to_totals( &$totals, $result ) {
		$totals['created'] += $result['created'];
		$totals['skipped'] += $result['skipped'];
		$totals['failed']  += $result['failed'];
	}

	/**
	 * Print a phase summary.
	 *
	 * @param string $label  Phase label.
	 * @param array  $result Result counts.
	 * @return void
	 */
	private function print_phase_summary( $label, $result ) {
		WP_CLI::log( '' );
		/* translators: %s: phase label */
		WP_CLI::log( sprintf( __( '%s summary:', 'mcp-ai-wpoos-pro' ), $label ) );
		/* translators: %d: count */
		WP_CLI::log( sprintf( __( '  Created: %d', 'mcp-ai-wpoos-pro' ), $result['created'] ) );
		/* translators: %d: count */
		WP_CLI::log( sprintf( __( '  Skipped: %d', 'mcp-ai-wpoos-pro' ), $result['skipped'] ) );
		/* translators: %d: count */
		WP_CLI::log( sprintf( __( '  Failed: %d', 'mcp-ai-wpoos-pro' ), $result['failed'] ) );
	}

	/**
	 * Convert places array to CSV string.
	 *
	 * @param array $places Places data.
	 * @return string CSV.
	 */
	private function places_to_csv( array $places ) {
		if ( empty( $places ) ) {
			return '';
		}

		$output = '';
		$handle = fopen( 'php://temp', 'r+' );

		// Header.
		fputcsv( $handle, array_keys( $places[0] ) );

		// Rows.
		foreach ( $places as $place ) {
			fputcsv( $handle, $place );
		}

		rewind( $handle );
		$output = stream_get_contents( $handle );
		fclose( $handle );

		return $output;
	}
}

// Register the command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp place', 'WP_MCP_AI_Pro_CLI_Place_Command' );
}
