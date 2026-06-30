<?php
/**
 * Tool for bulk importing places from structured data (JSON/CSV).
 *
 * Allows AI assistants and CLI commands to import many places at once
 * with deduplication, skip-existing, update-existing, dry-run, and
 * image sideloading support.
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
 * Bulk imports places from JSON array or CSV data with deduplication.
 *
 * @since 1.4.0
 */
class WP_MCP_AI_Tool_Import_Places implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'import_places';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import Places', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Bulk import places from JSON or CSV data with deduplication, skip-existing, update-existing, dry-run preview, and image sideloading.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'source'               => array(
					'type'        => 'string',
					'enum'        => array( 'json', 'json_array', 'csv' ),
					'default'     => 'json_array',
					'description' => __( 'Data source format. json_array: pass items directly. json: pass JSON string. csv: pass CSV string.', 'mcp-ai-wpoos-pro' ),
				),
				'data'                 => array(
					'type'        => 'array',
					'description' => __( 'Array of place data objects (used when source is json_array). Each item matches create_place parameters.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'object' ),
				),
				'json_string'          => array(
					'type'        => 'string',
					'description' => __( 'Raw JSON string containing array of place objects (used when source is json).', 'mcp-ai-wpoos-pro' ),
				),
				'csv_string'           => array(
					'type'        => 'string',
					'description' => __( 'Raw CSV string with header row (used when source is csv).', 'mcp-ai-wpoos-pro' ),
				),
				'skip_existing'        => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Skip places that already exist in the database.', 'mcp-ai-wpoos-pro' ),
				),
				'update_existing'      => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Update existing places with fresh data instead of skipping.', 'mcp-ai-wpoos-pro' ),
				),
				'dedup_strategy'       => array(
					'type'        => 'string',
					'enum'        => array( 'name', 'google_place_id', 'source_url', 'name_and_city', 'name_and_lat_lng' ),
					'default'     => 'name_and_city',
					'description' => __( 'How to determine if a place already exists.', 'mcp-ai-wpoos-pro' ),
				),
				'dry_run'              => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Preview the import without creating or updating anything.', 'mcp-ai-wpoos-pro' ),
				),
				'batch_size'           => array(
					'type'        => 'integer',
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 200,
					'description' => __( 'Number of items to process per batch.', 'mcp-ai-wpoos-pro' ),
				),
				'auto_geocode'         => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Automatically geocode addresses that lack coordinates.', 'mcp-ai-wpoos-pro' ),
				),
				'parent_place_id'      => array(
					'type'        => 'integer',
					'description' => __( 'Default parent place ID for all imported items.', 'mcp-ai-wpoos-pro' ),
				),
				'image_sideload'       => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Download and attach images from URLs in the data.', 'mcp-ai-wpoos-pro' ),
				),
				'image_url_field'      => array(
					'type'        => 'string',
					'default'     => 'image_url',
					'description' => __( 'Field name containing the image URL(s). Can be a single URL string or an array of URLs.', 'mcp-ai-wpoos-pro' ),
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
		return array( 'pro', 'database-write', 'requires-capability' );
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
			'profession_tags'       => array( 'travel_agent', 'content_creator', 'researcher' ),
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

		// Ensure helper is available.
		if ( ! class_exists( 'WP_MCP_AI_Place_Helper' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/helpers/class-wp-mcp-ai-place-helper.php';
		}

		// Parse input data.
		$items = $this->parse_input( $arguments );
		if ( is_wp_error( $items ) ) {
			return $items;
		}

		if ( empty( $items ) ) {
			return new WP_Error( 'wp_mcp_ai_empty_data', __( 'No place data provided or data is empty.', 'mcp-ai-wpoos-pro' ) );
		}

		// Options.
		$dry_run         = isset( $arguments['dry_run'] ) && $arguments['dry_run'];
		$skip_existing   = isset( $arguments['skip_existing'] ) ? (bool) $arguments['skip_existing'] : true;
		$update_existing = isset( $arguments['update_existing'] ) && $arguments['update_existing'];
		$dedup_strategy  = isset( $arguments['dedup_strategy'] ) ? $arguments['dedup_strategy'] : 'name_and_city';
		$auto_geocode    = isset( $arguments['auto_geocode'] ) ? (bool) $arguments['auto_geocode'] : true;
		$image_sideload  = isset( $arguments['image_sideload'] ) ? (bool) $arguments['image_sideload'] : true;
		$image_url_field = isset( $arguments['image_url_field'] ) ? $arguments['image_url_field'] : 'image_url';
		$batch_size      = isset( $arguments['batch_size'] ) ? absint( $arguments['batch_size'] ) : 50;

		// Place→Service bridge settings.
		$auto_create_services = isset( $arguments['auto_create_services'] ) && $arguments['auto_create_services'];
		$service_place_types  = isset( $arguments['service_place_types'] ) ? (array) $arguments['service_place_types'] : array( 'experience' );
		$service_defaults     = isset( $arguments['service_defaults'] ) ? (array) $arguments['service_defaults'] : array();

		// Default parent.
		$default_parent = isset( $arguments['parent_place_id'] ) ? absint( $arguments['parent_place_id'] ) : 0;

		$results = array(
			'success'          => true,
			'total'            => count( $items ),
			'created'          => 0,
			'updated'          => 0,
			'skipped'          => 0,
			'failed'           => 0,
			'services_created' => 0,
			'service_errors'   => 0,
			'ids'              => array(),
			'errors'           => array(),
			'service_failures' => array(),
			'dry_run'          => $dry_run,
		);

		$processed = 0;

		foreach ( $items as $index => $item ) {
			// Ensure item is an array and has a name.
			if ( ! is_array( $item ) || empty( $item['name'] ) ) {
				++$results['failed'];
				$results['errors'][] = array(
					'index' => $index,
					'error' => __( 'Missing required field: name', 'mcp-ai-wpoos-pro' ),
				);
				continue;
			}

			// Apply default parent if not set on item.
			if ( $default_parent && empty( $item['parent_place_id'] ) ) {
				$item['parent_place_id'] = $default_parent;
			}

			// Auto-geocode flag.
			if ( $auto_geocode && ! isset( $item['auto_geocode'] ) ) {
				$item['auto_geocode'] = true;
			}

			// Check for existing place.
			$existing_id = null;
			if ( $skip_existing || $update_existing ) {
				$existing_id = WP_MCP_AI_Place_Helper::find_existing( $dedup_strategy, $item );
			}

			if ( $existing_id && $skip_existing && ! $update_existing ) {
				++$results['skipped'];
				continue;
			}

			// Extract image URLs before saving.
			$image_urls = array();
			if ( $image_sideload && ! empty( $item[ $image_url_field ] ) ) {
				$raw_urls   = $item[ $image_url_field ];
				$image_urls = is_array( $raw_urls ) ? $raw_urls : array( $raw_urls );
				unset( $item[ $image_url_field ] );
			}

			if ( $dry_run ) {
				++$results['created'];
				continue;
			}

			// Geocode if needed.
			WP_MCP_AI_Place_Helper::maybe_geocode( $item );

			if ( $existing_id && $update_existing ) {
				$result = WP_MCP_AI_Place_Helper::update_place( $existing_id, $item );
				if ( is_wp_error( $result ) ) {
					++$results['failed'];
					$results['errors'][] = array(
						'index' => $index,
						'name'  => $item['name'],
						'error' => $result->get_error_message(),
					);
				} else {
					++$results['updated'];
					$results['ids'][] = $existing_id;

					// Sideload images for updated place too.
					if ( ! empty( $image_urls ) ) {
						WP_MCP_AI_Place_Helper::sideload_images( $existing_id, $image_urls );
					}

					// ── Auto-create bookable service (place→service bridge).
					if ( $auto_create_services && ! empty( $item['place_type'] ) && in_array( $item['place_type'], $service_place_types, true ) ) {
						$svc_defaults = $service_defaults;
						if ( empty( $svc_defaults['category'] ) && ! empty( $item['city'] ) ) {
							$svc_defaults['category'] = $item['city'];
						}
						$service_id = WP_MCP_AI_Place_Helper::create_service_from_place( $existing_id, $svc_defaults );
						if ( is_wp_error( $service_id ) ) {
							++$results['service_errors'];
							$results['service_failures'][] = array(
								'place_id' => $existing_id,
								'place'    => $item['name'],
								'error'    => $service_id->get_error_message(),
							);
						} else {
							++$results['services_created'];
						}
					}
				}
			} else {
				$place_id = WP_MCP_AI_Place_Helper::create_place( $item, $current_user_id );
				if ( is_wp_error( $place_id ) ) {
					++$results['failed'];
					$results['errors'][] = array(
						'index' => $index,
						'name'  => $item['name'],
						'error' => $place_id->get_error_message(),
					);
				} else {
					++$results['created'];
					$results['ids'][] = $place_id;

					// Sideload images.
					if ( ! empty( $image_urls ) ) {
						WP_MCP_AI_Place_Helper::sideload_images( $place_id, $image_urls );
					}

					// ── Auto-create bookable service (place→service bridge).
					if ( $auto_create_services && ! empty( $item['place_type'] ) && in_array( $item['place_type'], $service_place_types, true ) ) {
						$svc_defaults = $service_defaults;
						if ( empty( $svc_defaults['category'] ) && ! empty( $item['city'] ) ) {
							$svc_defaults['category'] = $item['city'];
						}
						$service_id = WP_MCP_AI_Place_Helper::create_service_from_place( $place_id, $svc_defaults );
						if ( is_wp_error( $service_id ) ) {
							++$results['service_errors'];
							$results['service_failures'][] = array(
								'place_id' => $place_id,
								'place'    => $item['name'],
								'error'    => $service_id->get_error_message(),
							);
						} else {
							++$results['services_created'];
						}
					}
				}
			}

			++$processed;

			// Periodic cache flush to prevent memory issues.
			if ( 0 === $processed % $batch_size ) {
				wp_cache_flush();
				if ( class_exists( 'WP_MCP_AI_Memory_Manager' ) ) {
					WP_MCP_AI_Memory_Manager::stop_the_insanity();
				}
			}
		}

		$results['message'] = $dry_run
			? sprintf(
				/* translators: %d: number of items that would be imported */
				__( 'Dry run complete: %d items would be imported.', 'mcp-ai-wpoos-pro' ),
				$results['created']
			)
			: sprintf(
				/* translators: 1: created, 2: updated, 3: failed, 4: skipped, 5: services created, 6: service errors */
				__( 'Import complete: %1$d created, %2$d updated, %3$d failed, %4$d skipped, %5$d services created, %6$d service errors.', 'mcp-ai-wpoos-pro' ),
				$results['created'],
				$results['updated'],
				$results['failed'],
				$results['skipped'],
				$results['services_created'],
				$results['service_errors']
			);

		return $results;
	}

	/**
	 * Parse input data from various source formats.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Parsed items array or error.
	 */
	private function parse_input( array $arguments ) {
		$source = isset( $arguments['source'] ) ? $arguments['source'] : 'json_array';

		switch ( $source ) {
			case 'json_array':
				return isset( $arguments['data'] ) && is_array( $arguments['data'] )
					? $arguments['data']
					: array();

			case 'json':
				if ( empty( $arguments['json_string'] ) ) {
					return new WP_Error( 'wp_mcp_ai_missing_data', __( 'json_string is required when source is json.', 'mcp-ai-wpoos-pro' ) );
				}
				$decoded = json_decode( $arguments['json_string'], true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					return new WP_Error(
						'wp_mcp_ai_invalid_json',
						sprintf(
						/* translators: %s: JSON error message */
							__( 'Invalid JSON: %s', 'mcp-ai-wpoos-pro' ),
							json_last_error_msg()
						)
					);
				}
				return is_array( $decoded ) ? $decoded : array();

			case 'csv':
				if ( empty( $arguments['csv_string'] ) ) {
					return new WP_Error( 'wp_mcp_ai_missing_data', __( 'csv_string is required when source is csv.', 'mcp-ai-wpoos-pro' ) );
				}
				return $this->parse_csv_string( $arguments['csv_string'] );

			default:
				return new WP_Error(
					'wp_mcp_ai_unknown_source',
					sprintf(
					/* translators: %s: source type */
						__( 'Unknown source format: %s', 'mcp-ai-wpoos-pro' ),
						$source
					)
				);
		}
	}

	/**
	 * Parse a CSV string into an array of associative rows.
	 *
	 * Uses the Contact Importer Service if available, otherwise falls back
	 * to native PHP fgetcsv parsing.
	 *
	 * @param string $csv_string Raw CSV content.
	 * @return array|WP_Error Parsed rows or error.
	 */
	private function parse_csv_string( $csv_string ) {
		// Try the Contact Importer Service first (handles Node.js csv-parse).
		if ( class_exists( 'WP_MCP_AI_Contact_Importer_Service' ) ) {
			// Write to temp file for the service.
			$temp_file = wp_tempnam( 'places-import-' );
			if ( $temp_file ) {
				file_put_contents( $temp_file, $csv_string );
				$importer = new WP_MCP_AI_Contact_Importer_Service();
				$result   = $importer->parse_csv( $temp_file, array( 'columns' => true ) );
				@unlink( $temp_file );
				if ( ! is_wp_error( $result ) ) {
					return $result;
				}
				// Fall through to PHP fallback on error.
			}
		}

		// PHP fallback.
		$data    = array();
		$headers = array();
		$handle  = fopen( 'php://temp', 'r+' );

		if ( ! $handle ) {
			return new WP_Error( 'wp_mcp_ai_csv_error', __( 'Failed to open temporary file for CSV parsing.', 'mcp-ai-wpoos-pro' ) );
		}

		fwrite( $handle, $csv_string );
		rewind( $handle );

		$row_index = 0;
		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			if ( 0 === $row_index ) {
				$headers = $row;
				++$row_index;
				continue;
			}

			if ( empty( array_filter( $row ) ) ) {
				continue;
			}

			$row_data = array();
			foreach ( $headers as $i => $header ) {
				$row_data[ trim( $header ) ] = isset( $row[ $i ] ) ? trim( $row[ $i ] ) : '';
			}

			// Convert numeric-looking strings.
			foreach ( $row_data as $key => $value ) {
				if ( 'latitude' === $key || 'longitude' === $key || 'rating' === $key ) {
					$row_data[ $key ] = is_numeric( $value ) ? floatval( $value ) : null;
				} elseif ( 'price_level' === $key ) {
					$row_data[ $key ] = is_numeric( $value ) ? absint( $value ) : null;
				}
			}

			$data[] = $row_data;
			++$row_index;
		}

		fclose( $handle );

		return $data;
	}
}
