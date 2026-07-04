<?php
/**
 * Tool for bulk importing bookable services from structured data (JSON/CSV).
 *
 * Allows AI assistants and CLI commands to import many services at once
 * with deduplication, skip-existing, update-existing, dry-run, and
 * image sideloading support.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage Calendar_Booking_Toolkit
 * @since     1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bulk imports services from JSON array or CSV data with deduplication.
 *
 * @since 1.4.0
 */
class WP_MCP_AI_Tool_Import_Services implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_calendar_booking_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'Calendar Booking Toolkit required.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'import_services';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import Services', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Bulk import bookable services from JSON or CSV data with deduplication, skip-existing, update-existing, dry-run preview, and optional place linking.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'source'                 => array(
					'type'        => 'string',
					'enum'        => array( 'json', 'json_array', 'csv' ),
					'default'     => 'json_array',
					'description' => __( 'Data source format.', 'mcp-ai-wpoos-pro' ),
				),
				'data'                   => array(
					'type'        => 'array',
					'description' => __( 'Array of service data objects.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'object' ),
				),
				'json_string'            => array(
					'type'        => 'string',
					'description' => __( 'Raw JSON string containing array of service objects.', 'mcp-ai-wpoos-pro' ),
				),
				'csv_string'             => array(
					'type'        => 'string',
					'description' => __( 'Raw CSV string with header row.', 'mcp-ai-wpoos-pro' ),
				),
				'skip_existing'          => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Skip services that already exist.', 'mcp-ai-wpoos-pro' ),
				),
				'update_existing'        => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Update existing services with fresh data.', 'mcp-ai-wpoos-pro' ),
				),
				'dedup_strategy'         => array(
					'type'        => 'string',
					'enum'        => array( 'name', 'name_and_place' ),
					'default'     => 'name',
					'description' => __( 'How to determine if a service already exists.', 'mcp-ai-wpoos-pro' ),
				),
				'dry_run'                => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Preview the import without creating or updating.', 'mcp-ai-wpoos-pro' ),
				),
				'batch_size'             => array(
					'type'        => 'integer',
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 200,
					'description' => __( 'Number of items per batch.', 'mcp-ai-wpoos-pro' ),
				),
				'default_place_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Default place ID to link all imported services to.', 'mcp-ai-wpoos-pro' ),
				),
				'auto_create_categories' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Auto-create service categories that do not exist.', 'mcp-ai-wpoos-pro' ),
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
			'toolkit'               => 'calendar-booking',
			'post_type'             => 'mcp_service',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'travel_agent', 'business_owner' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to import services.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_toolkit_disabled', self::get_unavailable_reason() );
		}

		// Parse input.
		$items = $this->parse_input( $arguments );
		if ( is_wp_error( $items ) ) {
			return $items;
		}

		if ( empty( $items ) ) {
			return new WP_Error( 'wp_mcp_ai_empty_data', __( 'No service data provided or data is empty.', 'mcp-ai-wpoos-pro' ) );
		}

		$dry_run          = isset( $arguments['dry_run'] ) && $arguments['dry_run'];
		$skip_existing    = isset( $arguments['skip_existing'] ) ? (bool) $arguments['skip_existing'] : true;
		$update_existing  = isset( $arguments['update_existing'] ) && $arguments['update_existing'];
		$dedup_strategy   = isset( $arguments['dedup_strategy'] ) ? $arguments['dedup_strategy'] : 'name';
		$batch_size       = isset( $arguments['batch_size'] ) ? absint( $arguments['batch_size'] ) : 50;
		$default_place_id = isset( $arguments['default_place_id'] ) ? absint( $arguments['default_place_id'] ) : 0;
		$auto_create_cats = isset( $arguments['auto_create_categories'] ) ? (bool) $arguments['auto_create_categories'] : true;

		$results = array(
			'success' => true,
			'total'   => count( $items ),
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'failed'  => 0,
			'ids'     => array(),
			'errors'  => array(),
			'dry_run' => $dry_run,
		);

		$processed = 0;

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || empty( $item['name'] ) ) {
				++$results['failed'];
				$results['errors'][] = array(
					'index' => $index,
					'error' => __( 'Missing required field: name', 'mcp-ai-wpoos-pro' ),
				);
				continue;
			}

			// Apply default place if not on item.
			if ( $default_place_id && empty( $item['place_id'] ) ) {
				$item['place_id'] = $default_place_id;
			}

			// Check for existing.
			$existing_id = null;
			if ( $skip_existing || $update_existing ) {
				$existing_id = $this->find_existing_service( $dedup_strategy, $item );
			}

			if ( $existing_id && $skip_existing && ! $update_existing ) {
				++$results['skipped'];
				continue;
			}

			if ( $dry_run ) {
				++$results['created'];
				continue;
			}

			// Create or update via delegating to create_service logic.
			$service_args = $item;
			if ( $existing_id && $update_existing ) {
				$service_args['service_id'] = $existing_id;
			}

			$service_result = $this->persist_service( $service_args, $current_user_id, $auto_create_cats );

			if ( is_wp_error( $service_result ) ) {
				++$results['failed'];
				$results['errors'][] = array(
					'index' => $index,
					'name'  => $item['name'],
					'error' => $service_result->get_error_message(),
				);
			} elseif ( $existing_id && $update_existing ) {
					++$results['updated'];
					$results['ids'][] = $existing_id;
			} else {
				++$results['created'];
				$results['ids'][] = $service_result;
			}

			++$processed;

			if ( 0 === $processed % $batch_size ) {
				wp_cache_flush();
				if ( class_exists( 'WP_MCP_AI_Memory_Manager' ) ) {
					WP_MCP_AI_Memory_Manager::stop_the_insanity();
				}
			}
		}

		$results['message'] = $dry_run
			/* translators: %d: number of services that would be imported */
			? sprintf( __( 'Dry run complete: %d services would be imported.', 'mcp-ai-wpoos-pro' ), $results['created'] )
			: sprintf(
				/* translators: 1: created, 2: updated, 3: failed, 4: skipped */
				__( 'Import complete: %1$d created, %2$d updated, %3$d failed, %4$d skipped.', 'mcp-ai-wpoos-pro' ),
				$results['created'],
				$results['updated'],
				$results['failed'],
				$results['skipped']
			);

		return $results;
	}

	/**
	 * Parse input data from various source formats.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function parse_input( array $arguments ) {
		$source = isset( $arguments['source'] ) ? $arguments['source'] : 'json_array';

		switch ( $source ) {
			case 'json_array':
				return isset( $arguments['data'] ) && is_array( $arguments['data'] ) ? $arguments['data'] : array();

			case 'json':
				if ( empty( $arguments['json_string'] ) ) {
					return new WP_Error( 'wp_mcp_ai_missing_data', __( 'json_string is required.', 'mcp-ai-wpoos-pro' ) );
				}
				$decoded = json_decode( $arguments['json_string'], true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					return new WP_Error( 'wp_mcp_ai_invalid_json', sprintf( /* translators: %s: JSON error message */ __( 'Invalid JSON: %s', 'mcp-ai-wpoos-pro' ), json_last_error_msg() ) );
				}
				return is_array( $decoded ) ? $decoded : array();

			case 'csv':
				if ( empty( $arguments['csv_string'] ) ) {
					return new WP_Error( 'wp_mcp_ai_missing_data', __( 'csv_string is required.', 'mcp-ai-wpoos-pro' ) );
				}
				return $this->parse_csv_string( $arguments['csv_string'] );

			default:
				return new WP_Error( 'wp_mcp_ai_unknown_source', __( 'Unknown source format.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Parse a CSV string into associative rows.
	 *
	 * @param string $csv_string Raw CSV.
	 * @return array|WP_Error
	 */
	private function parse_csv_string( $csv_string ) {
		if ( class_exists( 'WP_MCP_AI_Contact_Importer_Service' ) ) {
			$temp_file = wp_tempnam( 'services-import-' );
			if ( $temp_file ) {
				file_put_contents( $temp_file, $csv_string );
				$importer = new WP_MCP_AI_Contact_Importer_Service();
				$result   = $importer->parse_csv( $temp_file, array( 'columns' => true ) );
				@unlink( $temp_file );
				if ( ! is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		// PHP fallback.
		$data    = array();
		$headers = array();
		$handle  = fopen( 'php://temp', 'r+' );
		if ( ! $handle ) {
			return new WP_Error( 'wp_mcp_ai_csv_error', __( 'Failed to parse CSV.', 'mcp-ai-wpoos-pro' ) );
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
			// Type-cast numeric fields.
			if ( isset( $row_data['duration_minutes'] ) ) {
				$row_data['duration_minutes'] = absint( $row_data['duration_minutes'] );
			}
			if ( isset( $row_data['price'] ) ) {
				$row_data['price'] = floatval( $row_data['price'] );
			}
			if ( isset( $row_data['buffer_time_minutes'] ) ) {
				$row_data['buffer_time_minutes'] = absint( $row_data['buffer_time_minutes'] );
			}
			$data[] = $row_data;
			++$row_index;
		}

		fclose( $handle );
		return $data;
	}

	/**
	 * Find an existing service by dedup strategy.
	 *
	 * @param string $strategy Dedup strategy.
	 * @param array  $item     Service data.
	 * @return int|null
	 */
	private function find_existing_service( $strategy, array $item ) {
		$name = isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : '';
		if ( empty( $name ) ) {
			return null;
		}

		$meta_query = array();

		if ( 'name_and_place' === $strategy && ! empty( $item['place_id'] ) ) {
			$meta_query[] = array(
				'key'   => '_service_place_id',
				'value' => absint( $item['place_id'] ),
			);
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_service',
				'title'          => $name,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => $meta_query,
			)
		);

		return ! empty( $query->posts ) ? $query->posts[0] : null;
	}

	/**
	 * Persist a single service record.
	 *
	 * @param array $args              Service arguments.
	 * @param int   $user_id           WordPress user ID.
	 * @param bool  $auto_create_cats  Whether to auto-create categories.
	 * @return int|WP_Error Service ID or error.
	 */
	private function persist_service( array $args, $user_id, $auto_create_cats ) {
		$name        = sanitize_text_field( $args['name'] );
		$description = isset( $args['description'] ) ? wp_kses_post( $args['description'] ) : '';
		$service_id  = isset( $args['service_id'] ) ? absint( $args['service_id'] ) : 0;

		$post_data = array(
			'post_type'    => 'mcp_service',
			'post_title'   => $name,
			'post_content' => $description,
			'post_status'  => 'publish',
			'post_author'  => $user_id,
		);

		if ( $service_id ) {
			$post_data['ID'] = $service_id;
			$result          = wp_update_post( $post_data, true );
		} else {
			$result = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$service_id = $service_id ? $service_id : $result;

		// Save meta.
		if ( isset( $args['duration_minutes'] ) ) {
			update_post_meta( $service_id, '_service_duration', absint( $args['duration_minutes'] ) );
		}
		if ( isset( $args['price'] ) ) {
			update_post_meta( $service_id, '_service_price', floatval( $args['price'] ) );
		}
		if ( isset( $args['buffer_time_minutes'] ) ) {
			update_post_meta( $service_id, '_service_buffer_time', absint( $args['buffer_time_minutes'] ) );
		}
		if ( isset( $args['place_id'] ) ) {
			update_post_meta( $service_id, '_service_place_id', absint( $args['place_id'] ) );
		}
		if ( isset( $args['max_participants'] ) ) {
			update_post_meta( $service_id, '_service_max_participants', absint( $args['max_participants'] ) );
		}
		if ( ! empty( $args['source_url'] ) ) {
			update_post_meta( $service_id, '_service_source_url', esc_url_raw( $args['source_url'] ) );
		}

		// Category.
		if ( ! empty( $args['category'] ) ) {
			$category = sanitize_text_field( $args['category'] );
			if ( $auto_create_cats && ! term_exists( $category, 'mcp_service_category' ) ) {
				wp_insert_term( $category, 'mcp_service_category' );
			}
			wp_set_object_terms( $service_id, $category, 'mcp_service_category', false );
		}

		// Image sideloading.
		if ( ! empty( $args['image_urls'] ) && is_array( $args['image_urls'] ) ) {
			if ( ! function_exists( 'media_sideload_image' ) ) {
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}
			foreach ( $args['image_urls'] as $i => $url ) {
				if ( empty( $url ) ) {
					continue;
				}
				$att_id = media_sideload_image( $url, $service_id, null, 'id' );
				if ( ! is_wp_error( $att_id ) && 0 === $i ) {
					set_post_thumbnail( $service_id, $att_id );
				}
			}
		}

		return $service_id;
	}
}
