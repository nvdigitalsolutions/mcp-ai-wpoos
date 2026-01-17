<?php
/**
 * Research Metabox for Place CPT.
 *
 * Provides AI-powered research for attractions, businesses, and locations
 * before adding them to the database.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Research metabox for places/attractions.
 */
class WP_MCP_AI_Place_Research_Metabox extends WP_MCP_AI_Research_Metabox_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'mcp_ai_place',
			__( 'AI Research Assistant', 'mcp-ai-wpoos-pro' ),
			'research_place'
		);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_intro_text() {
		return __( 'Research an attraction, location, or business before adding it. Get comprehensive information including address, contact details, hours, and more.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_search_label() {
		return __( 'Search for a place:', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_search_placeholder() {
		return __( 'e.g., "Eiffel Tower Paris" or "Central Park New York"', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_field_map() {
		return array(
			'name'            => '#title',
			'description'     => '#content',
			'place_type'      => '#mcp_ai_place_type',
			'address'         => '#_place_address',
			'latitude'        => '#_place_latitude',
			'longitude'       => '#_place_longitude',
			'phone'           => '#_place_phone',
			'email'           => '#_place_email',
			'website'         => '#_place_website',
			'rating'          => '#_place_rating',
			'price_level'     => '#_place_price_level',
			'google_place_id' => '#_place_google_place_id',
			'street'          => '#_place_street',
			'city'            => '#_place_city',
			'state'           => '#_place_state',
			'country'         => '#_place_country',
			'postal_code'     => '#_place_postal_code',
		);
	}
}
