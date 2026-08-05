<?php
/**
 * Tool for enriching a place with Google Places API data.
 *
 * Fetches ratings, phone numbers, websites, and Google Place IDs
 * via the Google Places API (requires a configured API key).
 *
 * @package WP_MCP_AI_Pro
 * @since   1.4.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enriches places with data from Google Places API.
 *
 * @since 1.4.2
 */
class WP_MCP_AI_Tool_Enrich_Place_Details implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'enrich_place_details';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Enrich Place Details', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Enrich a place with data from the Google Places API: ratings, phone numbers, websites, and Google Place IDs. Requires a Google Maps API key configured in plugin settings.', 'mcp-ai-wpoos-pro' );
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
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'place_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Place post ID to enrich.', 'mcp-ai-wpoos-pro' ),
				),
				'batch_size' => array(
					'type'        => 'integer',
					'default'     => 5,
					'minimum'     => 1,
					'maximum'     => 20,
					'description' => __( 'Max places when enriching in batch mode.', 'mcp-ai-wpoos-pro' ),
				),
				'fields'     => array(
					'type'        => 'array',
					'description' => __( 'Fields to fetch: rating, place_id, phone, website, price_level. Default: all.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'rating', 'place_id', 'phone', 'website', 'price_level' ),
					),
				),
				'dry_run'    => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Preview results without saving.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'additionalProperties' => false,
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
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_toolkit_disabled', self::get_unavailable_reason() );
		}

		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_api_key',
				__( 'Google Maps API key not configured. Add it in NV oOS → Settings → Providers → Google Maps.', 'mcp-ai-wpoos-pro' )
			);
		}

		$pid     = isset( $arguments['place_id'] ) ? absint( $arguments['place_id'] ) : 0;
		$batch   = isset( $arguments['batch_size'] ) ? absint( $arguments['batch_size'] ) : 5;
		$fields  = isset( $arguments['fields'] ) ? (array) $arguments['fields'] : array( 'rating', 'place_id', 'phone', 'website', 'price_level' );
		$dry_run = isset( $arguments['dry_run'] ) && $arguments['dry_run'];

		$results = array(
			'success'  => true,
			'dry_run'  => $dry_run,
			'enriched' => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'items'    => array(),
		);

		if ( $pid > 0 ) {
			$place = get_post( $pid );
			if ( ! $place || 'mcp_ai_place' !== $place->post_type ) {
				return new WP_Error( 'wp_mcp_ai_place_not_found', __( 'Place not found.', 'mcp-ai-wpoos-pro' ) );
			}
			$item               = $this->enrich( $place, $fields, $api_key, $dry_run );
			$results['items'][] = $item;
			$results            = $this->tally( $results, $item );
			$results['message'] = $item['message'];
			return $results;
		}

		$places = get_posts(
			array(
				'post_type'      => 'mcp_ai_place',
				'posts_per_page' => $batch,
				'post_status'    => 'publish',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => '_place_google_place_id',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_place_rating',
						'compare' => 'NOT EXISTS',
					),
				),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		foreach ( $places as $place ) {
			$item               = $this->enrich( $place, $fields, $api_key, $dry_run );
			$results['items'][] = $item;
			$results            = $this->tally( $results, $item );
		}

		$results['message'] = sprintf(
			/* translators: 1: enriched count, 2: total processed */
			__( 'Enriched %1$d of %2$d places.', 'mcp-ai-wpoos-pro' ),
			$results['enriched'],
			count( $results['items'] )
		);

		return $results;
	}

	/**
	 * Tally result counts.
	 *
	 * @param array $results Running results.
	 * @param array $item    Single item result.
	 * @return array Updated results.
	 */
	private function tally( $results, $item ) {
		if ( ! empty( $item['enriched'] ) ) {
			++$results['enriched'];
		} elseif ( isset( $item['skipped'] ) ) {
			++$results['skipped'];
		} else {
			++$results['failed'];
		}
		return $results;
	}

	/**
	 * Get the Google Maps API key.
	 *
	 * @return string API key or empty string.
	 */
	private function get_api_key() {
		if ( ! class_exists( 'WP_MCP_AI_Google_Maps_Client' ) ) {
			return '';
		}
		$maps = new WP_MCP_AI_Google_Maps_Client();
		return $maps->get_api_key();
	}

	/**
	 * Enrich a single place with Google Places data.
	 *
	 * @param WP_Post $place   Place post object.
	 * @param array   $fields  Fields to fetch.
	 * @param string  $api_key Google API key.
	 * @param bool    $dry_run Dry run mode.
	 * @return array Result item.
	 */
	private function enrich( $place, $fields, $api_key, $dry_run ) {
		$item = array(
			'place_id' => $place->ID,
			'name'     => $place->post_title,
			'enriched' => false,
		);

		if ( $dry_run ) {
			$item['message'] = sprintf(
				/* translators: %s: place name */
				__( 'Would search: %s', 'mcp-ai-wpoos-pro' ),
				$place->post_title
			);
			return $item;
		}

		// Build search query.
		$components = get_post_meta( $place->ID, '_place_address_components', true );
		$city       = is_array( $components ) && ! empty( $components['city'] ) ? $components['city'] : '';
		$query      = $place->post_title;
		if ( ! empty( $city ) && false === stripos( $query, $city ) ) {
			$query .= ' ' . $city;
		}
		$query .= ' Sri Lanka';

		// Text Search.
		$search = $this->text_search( $query, $api_key );
		if ( ! $search ) {
			$item['message'] = __( 'Not found in Google Places.', 'mcp-ai-wpoos-pro' );
			return $item;
		}

		$added = array();
		$id    = $place->ID;

		if ( in_array( 'place_id', $fields, true ) && ! get_post_meta( $id, '_place_google_place_id', true ) ) {
			update_post_meta( $id, '_place_google_place_id', $search['place_id'] );
			$added[]          = 'place_id';
			$item['place_id'] = $search['place_id'];
		}

		if ( in_array( 'rating', $fields, true ) && ! get_post_meta( $id, '_place_rating', true ) && ! empty( $search['rating'] ) ) {
			update_post_meta( $id, '_place_rating', floatval( $search['rating'] ) );
			$added[]        = 'rating';
			$item['rating'] = floatval( $search['rating'] );
		}

		// Fetch details if phone/website needed.
		if ( ( in_array( 'phone', $fields, true ) || in_array( 'website', $fields, true ) ) && ! empty( $search['place_id'] ) ) {
			$details = $this->place_details( $search['place_id'], $api_key );
			if ( $details ) {
				if ( in_array( 'phone', $fields, true ) && ! get_post_meta( $id, '_place_phone', true ) && ! empty( $details['formatted_phone_number'] ) ) {
					update_post_meta( $id, '_place_phone', $details['formatted_phone_number'] );
					$added[]       = 'phone';
					$item['phone'] = $details['formatted_phone_number'];
				}
				if ( in_array( 'website', $fields, true ) && ! get_post_meta( $id, '_place_website', true ) && ! empty( $details['website'] ) ) {
					update_post_meta( $id, '_place_website', $details['website'] );
					$added[]         = 'website';
					$item['website'] = $details['website'];
				}
				if ( in_array( 'price_level', $fields, true ) && ! get_post_meta( $id, '_place_price_level', true ) && isset( $details['price_level'] ) ) {
					update_post_meta( $id, '_place_price_level', absint( $details['price_level'] ) );
					$added[] = 'price_level';
				}
			}
		}

		if ( ! empty( $added ) ) {
			$item['enriched'] = true;
			$item['added']    = $added;
			$item['message']  = sprintf(
				/* translators: %s: comma-separated field names */
				__( 'Added: %s', 'mcp-ai-wpoos-pro' ),
				implode( ', ', $added )
			);
		} else {
			$item['skipped'] = true;
			$item['message'] = __( 'All requested fields already present.', 'mcp-ai-wpoos-pro' );
		}

		return $item;
	}

	/**
	 * Perform a Google Places Text Search.
	 *
	 * @param string $query   Search query.
	 * @param string $api_key Google API key.
	 * @return array|null First result or null.
	 */
	private function text_search( $query, $api_key ) {
		if ( class_exists( 'WP_MCP_AI_Google_Maps_Client' ) ) {
			$maps = new WP_MCP_AI_Google_Maps_Client();
			$r    = $maps->text_search( $query, array( 'language' => 'en' ) );
			if ( ! is_wp_error( $r ) && ! empty( $r['results'][0] ) ) {
				return $r['results'][0];
			}
		}

		// Fallback: direct API call.
		$url  = 'https://maps.googleapis.com/maps/api/place/textsearch/json?' . http_build_query(
			array(
				'query'    => $query,
				'key'      => $api_key,
				'language' => 'en',
			)
		);
		$resp = wp_remote_get( $url, array( 'timeout' => 10 ) );
		if ( is_wp_error( $resp ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		return ! empty( $data['results'][0] ) ? $data['results'][0] : null;
	}

	/**
	 * Fetch Place Details from Google API.
	 *
	 * @param string $place_id Google Place ID.
	 * @param string $api_key  Google API key.
	 * @return array|null Details or null.
	 */
	private function place_details( $place_id, $api_key ) {
		$url  = 'https://maps.googleapis.com/maps/api/place/details/json?' . http_build_query(
			array(
				'place_id' => $place_id,
				'fields'   => 'formatted_phone_number,website,price_level',
				'key'      => $api_key,
				'language' => 'en',
			)
		);
		$resp = wp_remote_get( $url, array( 'timeout' => 10 ) );
		if ( is_wp_error( $resp ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		return ! empty( $data['result'] ) ? $data['result'] : null;
	}
}
