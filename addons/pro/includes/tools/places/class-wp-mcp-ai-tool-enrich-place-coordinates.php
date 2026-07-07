<?php
/**
 * Tool for geocoding a place to fill in missing coordinates.
 *
 * Uses Google Maps Geocoding API (if configured) or falls back
 * to Nominatim (OpenStreetMap, free, no key required).
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.4.2
 */

if ( ! defined( "ABSPATH" ) ) {
	exit;
}

class WP_MCP_AI_Tool_Enrich_Place_Coordinates implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	public static function is_available() {
		if ( function_exists( "wp_mcp_ai_is_base_version" ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( "wp_mcp_ai_settings", array() );
		return ! empty( $settings["enable_places_management"] );
	}

	public static function get_unavailable_reason() {
		return __( "Places Management toolkit required.", "mcp-ai-wpoos-pro" );
	}

	public function get_slug() { return "enrich_place_coordinates"; }
	public function get_name() { return __( "Enrich Place Coordinates", "mcp-ai-wpoos-pro" ); }
	public function get_description() { return __( "Geocode a place to fill in missing latitude and longitude. Uses Google Maps if a key is configured, otherwise falls back to the free Nominatim (OpenStreetMap) service. Supports single place or batch mode.", "mcp-ai-wpoos-pro" ); }
	public function get_required_capability() { return "edit_posts"; }
	public function get_capability_flags() { return array( "pro", "database-read", "database-write", "requires-capability" ); }
	public function get_definition() { return array( "name" => $this->get_name(), "description" => $this->get_description(), "toolkit" => "places", "post_type" => "mcp_ai_place", "pattern_compatibility" => array( "orchestrator", "sequential" ), "profession_tags" => array( "travel_agent", "content_creator", "developer" ), "risk_level" => "standard" ); }

	public function get_parameters_schema() {
		return array(
			"type"                 => "object",
			"properties"           => array(
				"place_id"           => array(
					"type"        => "integer",
					"description" => __( "Place ID to geocode. Omit to run in batch mode.", "mcp-ai-wpoos-pro" ),
				),
				"provider"           => array(
					"type"        => "string",
					"enum"        => array( "auto", "google", "nominatim" ),
					"default"     => "auto",
					"description" => __( "Geocoding provider. auto: prefer Google if key exists, else Nominatim.", "mcp-ai-wpoos-pro" ),
				),
				"batch_size"         => array(
					"type"        => "integer",
					"default"     => 10,
					"minimum"     => 1,
					"maximum"     => 50,
					"description" => __( "Max places to process in batch mode.", "mcp-ai-wpoos-pro" ),
				),
				"dry_run"            => array(
					"type"        => "boolean",
					"default"     => false,
					"description" => __( "Preview geocoding without saving to the database.", "mcp-ai-wpoos-pro" ),
				),
			),
			"additionalProperties" => false,
		);
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = ! empty( $context["user_id"] ) ? absint( $context["user_id"] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, "edit_posts" ) ) {
			return new WP_Error( "wp_mcp_ai_forbidden", __( "You do not have permission to enrich places.", "mcp-ai-wpoos-pro" ) );
		}

		if ( ! self::is_available() ) {
			return new WP_Error( "wp_mcp_ai_toolkit_disabled", self::get_unavailable_reason() );
		}

		$place_id = isset( $arguments["place_id"] ) ? absint( $arguments["place_id"] ) : 0;
		$provider = isset( $arguments["provider"] ) ? $arguments["provider"] : "auto";
		$batch    = isset( $arguments["batch_size"] ) ? absint( $arguments["batch_size"] ) : 10;
		$dry_run  = isset( $arguments["dry_run"] ) && $arguments["dry_run"];

		if ( "auto" === $provider ) {
			$provider = $this->resolve_provider();
		}

		$results = array(
			"success"  => true,
			"provider" => $provider,
			"dry_run"  => $dry_run,
			"enriched" => 0,
			"skipped"  => 0,
			"failed"   => 0,
			"items"    => array(),
		);

		// Single place
		if ( $place_id > 0 ) {
			$place = get_post( $place_id );
			if ( ! $place || "mcp_ai_place" !== $place->post_type ) {
				return new WP_Error( "wp_mcp_ai_place_not_found", __( "Place not found.", "mcp-ai-wpoos-pro" ) );
			}
			$item = $this->geocode_place( $place, $provider, $dry_run );
			$results["items"][] = $item;
			$results = $this->tally( $results, $item );
			$results["message"] = $item["message"];
			return $results;
		}

		// Batch
		$places = get_posts( array(
			"post_type"      => "mcp_ai_place",
			"posts_per_page" => $batch,
			"post_status"    => "publish",
			"meta_query"     => array(
				array( "key" => "_place_latitude", "compare" => "NOT EXISTS" ),
			),
			"orderby"        => "ID",
			"order"          => "ASC",
			"no_found_rows"  => true,
		) );

		foreach ( $places as $place ) {
			$item = $this->geocode_place( $place, $provider, $dry_run );
			$results["items"][] = $item;
			$results = $this->tally( $results, $item );
		}

		$results["message"] = sprintf(
			__( "Enriched %1$d of %2$d places with coordinates.", "mcp-ai-wpoos-pro" ),
			$results["enriched"],
			count( $results["items"] )
		);

		return $results;
	}

	private function tally( $r, $i ) {
		if ( ! empty( $i["latitude"] ) ) { $r["enriched"]++; }
		elseif ( isset( $i["skipped"] ) ) { $r["skipped"]++; }
		else { $r["failed"]++; }
		return $r;
	}

	private function resolve_provider() {
		if ( ! class_exists( "WP_MCP_AI_Google_Maps_Client" ) ) {
			return "nominatim";
		}
		$maps = new WP_MCP_AI_Google_Maps_Client();
		return ! empty( $maps->get_api_key() ) ? "google" : "nominatim";
	}

	private function geocode_place( $place, $provider, $dry_run ) {
		$item = array(
			"place_id"  => $place->ID,
			"name"      => $place->post_title,
			"latitude"  => null,
			"longitude" => null,
		);

		$existing_lat = get_post_meta( $place->ID, "_place_latitude", true );
		if ( ! empty( $existing_lat ) ) {
			$item["latitude"]  = floatval( $existing_lat );
			$item["longitude"] = floatval( get_post_meta( $place->ID, "_place_longitude", true ) );
			$item["skipped"]   = true;
			$item["message"]   = __( "Already has coordinates.", "mcp-ai-wpoos-pro" );
			return $item;
		}

		$name       = $place->post_title;
		$components = get_post_meta( $place->ID, "_place_address_components", true );
		$city       = is_array( $components ) && ! empty( $components["city"] ) ? $components["city"] : "";
		$country    = is_array( $components ) && ! empty( $components["country"] ) ? $components["country"] : "";

		$address = $name;
		if ( ! empty( $city ) && false === stripos( $address, $city ) ) {
			$address .= ", " . $city;
		}
		if ( ! empty( $country ) ) {
			$address .= ", " . $country;
		}

		if ( $dry_run ) {
			$item["message"] = sprintf( __( "Would geocode: %s", "mcp-ai-wpoos-pro" ), $address );
			return $item;
		}

		$coords = ( "google" === $provider )
			? $this->geocode_google( $address )
			: $this->geocode_nominatim( $address );

		if ( $coords ) {
			update_post_meta( $place->ID, "_place_latitude", $coords["lat"] );
			update_post_meta( $place->ID, "_place_longitude", $coords["lng"] );
			$item["latitude"]  = $coords["lat"];
			$item["longitude"] = $coords["lng"];
			$item["message"]   = sprintf( __( "Geocoded: %.6f, %.6f", "mcp-ai-wpoos-pro" ), $coords["lat"], $coords["lng"] );
		} else {
			$item["message"] = __( "Geocoding failed: the address could not be resolved.", "mcp-ai-wpoos-pro" );
		}

		return $item;
	}

	private function geocode_google( $address ) {
		if ( ! class_exists( "WP_MCP_AI_Google_Maps_Client" ) ) {
			return null;
		}
		$maps   = new WP_MCP_AI_Google_Maps_Client();
		$result = $maps->geocode( $address );
		if ( is_wp_error( $result ) || empty( $result["results"][0]["latitude"] ) ) {
			return null;
		}
		return array(
			"lat" => floatval( $result["results"][0]["latitude"] ),
			"lng" => floatval( $result["results"][0]["longitude"] ),
		);
	}

	private function geocode_nominatim( $address ) {
		$url = "https://nominatim.openstreetmap.org/search?" . http_build_query( array(
			"q"      => $address,
			"format" => "json",
			"limit"  => 1,
		) );

		$response = wp_remote_get( $url, array(
			"headers" => array(
				"User-Agent" => "NVoOS-Places/1.0",
				"Accept"     => "application/json",
			),
			"timeout" => 15,
		) );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( empty( $data[0]["lat"] ) || empty( $data[0]["lon"] ) ) {
			return null;
		}

		return array(
			"lat" => floatval( $data[0]["lat"] ),
			"lng" => floatval( $data[0]["lon"] ),
		);
	}
}
