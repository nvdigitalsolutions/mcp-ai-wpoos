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
