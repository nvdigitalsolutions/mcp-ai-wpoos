<?php
/**
 * WPCode Snippet Repository — shared persistence for the developer toolkit.
 *
 * Extracted from class-wp-mcp-ai-pro-tool-create-wpcode-snippet.php so other
 * toolkits (e.g. gtmetrix) can create, read and list WPCode snippets without
 * duplicating the save path. All methods delegate to WPCode plugin APIs and
 * fail closed when WPCode is unavailable.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.5
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WPCode snippet persistence repository.
 *
 * @since 1.2.5
 */
class WP_MCP_AI_Pro_WPCode_Snippet_Repository {

	/**
	 * Whether WPCode is active and usable.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'wpcode' ) && class_exists( 'WPCode_Snippet' );
	}

	/**
	 * Validate a requested auto-insert location against the active WPCode
	 * configuration (shared with the create tool).
	 *
	 * @param string $location  Location slug.
	 * @param string $code_type Code type slug requested for the snippet.
	 * @return true|WP_Error
	 */
	public static function validate_location( $location, $code_type ) {
		if ( ! self::is_available() || ! isset( wpcode()->auto_insert ) ) {
			return new WP_Error( 'wp_mcp_ai_wpcode_unavailable', __( 'WPCode auto-insert locations are unavailable.', 'mcp-ai-wpoos-pro' ) );
		}

		$types = wpcode()->auto_insert->get_types();
		foreach ( $types as $type ) {
			$locations = $type->get_locations();
			if ( isset( $locations[ $location ] ) ) {
				if ( isset( $type->code_type ) && 'all' !== $type->code_type ) {
					$allowed_type = $type->code_type;
					if ( 'php' === $allowed_type && 'universal' === $code_type ) {
						return true;
					}
					if ( $allowed_type !== $code_type ) {
						return new WP_Error( 'wp_mcp_ai_location_not_supported', __( 'The requested location is not available for this code type.', 'mcp-ai-wpoos-pro' ) );
					}
				}

				return true;
			}
		}

		return new WP_Error( 'wp_mcp_ai_unknown_location', __( 'The provided auto-insert location is not recognised by WPCode.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Persist a snippet through WPCode APIs.
	 *
	 * @param array $snippet_data WPCode_Snippet constructor data (code, code_type,
	 *                            auto_insert, location, title, active, tags,
	 *                            note, priority, insert_number, custom_shortcode,
	 *                            device_type, conditional_logic).
	 * @return int|WP_Error Post ID or WP_Error.
	 */
	public function save( $snippet_data ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_wpcode_missing', __( 'WPCode must be installed and active to manage snippets.', 'mcp-ai-wpoos-pro' ) );
		}

		$conditional_logic = null;
		if ( isset( $snippet_data['conditional_logic'] ) ) {
			$conditional_logic = $snippet_data['conditional_logic'];
			unset( $snippet_data['conditional_logic'] );
		}

		$snippet = new WPCode_Snippet( $snippet_data );
		$result  = $snippet->save();

		if ( false === $result ) {
			$message = '';
			if ( method_exists( wpcode()->error, 'get_last_error_message' ) ) {
				$message = wpcode()->error->get_last_error_message();
			}

			if ( ! $message ) {
				$message = __( 'The WPCode snippet could not be saved.', 'mcp-ai-wpoos-pro' );
			}

			return new WP_Error( 'wp_mcp_ai_wpcode_save_failed', $message );
		}

		if ( null !== $conditional_logic ) {
			$this->store_conditional_logic( $result, $conditional_logic );
		}

		return $result;
	}

	/**
	 * Store conditional-logic groups for a snippet.
	 *
	 * Uses WPCode_Conditional_Logic when available (verified against the live
	 * plugin during implementation); falls back to snippet meta so the tool
	 * contract stays stable. The stored JSON is returned by get().
	 *
	 * @param int   $post_id Snippet post ID.
	 * @param array $groups  Conditional-logic groups (each a list of rules).
	 * @return void
	 */
	public function store_conditional_logic( $post_id, $groups ) {
		if ( class_exists( 'WPCode_Conditional_Logic' ) ) {
			$logic = new WPCode_Conditional_Logic();
			if ( method_exists( $logic, 'set_groups' ) && method_exists( $logic, 'store' ) ) {
				$logic->set_groups( $groups );
				$logic->store( absint( $post_id ) );

				return;
			}
		}

		update_post_meta( absint( $post_id ), '_wpcode_conditional_logic', wp_json_encode( $groups ) );
	}

	/**
	 * Read the conditional-logic JSON stored for a snippet.
	 *
	 * @param int $post_id Snippet post ID.
	 * @return array
	 */
	public function get_conditional_logic( $post_id ) {
		if ( class_exists( 'WPCode_Conditional_Logic' ) ) {
			$logic = new WPCode_Conditional_Logic();
			if ( method_exists( $logic, 'get_groups' ) ) {
				$groups = $logic->get_groups( absint( $post_id ) );
				if ( is_array( $groups ) ) {
					return $groups;
				}
			}
		}

		$meta = get_post_meta( absint( $post_id ), '_wpcode_conditional_logic', true );
		$json = json_decode( (string) $meta, true );

		return is_array( $json ) ? $json : array();
	}

	/**
	 * Fetch a single snippet by ID.
	 *
	 * @param int $snippet_id WPCode snippet ID.
	 * @return WPCode_Snippet|WP_Error
	 */
	public function get( $snippet_id ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_wpcode_missing', __( 'WPCode must be installed and active to manage snippets.', 'mcp-ai-wpoos-pro' ) );
		}

		$existing = new WPCode_Snippet( absint( $snippet_id ) );
		if ( empty( $existing->post_data ) || 'wpcode' !== $existing->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_snippet', __( 'The requested WPCode snippet could not be found.', 'mcp-ai-wpoos-pro' ) );
		}

		return $existing;
	}

	/**
	 * List snippets (optionally filtered by tag slug or active state).
	 *
	 * @param array $args Filters: tag, active, limit.
	 * @return array|WP_Error
	 */
	public function list_snippets( $args = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_wpcode_missing', __( 'WPCode must be installed and active to manage snippets.', 'mcp-ai-wpoos-pro' ) );
		}

		$query = array(
			'post_type'      => 'wpcode',
			'post_status'    => 'any',
			'posts_per_page' => isset( $args['limit'] ) ? min( 100, absint( $args['limit'] ) ) : 50,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $args['tag'] ) ) {
			$query['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- small wpcode snippet index, bounded by posts_per_page.
				array(
					'taxonomy' => 'wpcode_tags',
					'field'    => 'slug',
					'terms'    => sanitize_title( $args['tag'] ),
				),
			);
		}

		if ( isset( $args['active'] ) ) {
			$query['post_status'] = $args['active'] ? 'publish' : 'draft';
		}

		$posts = get_posts( $query );
		$rows  = array();
		foreach ( $posts as $post ) {
			$snippet = new WPCode_Snippet( $post );
			$rows[]  = array(
				'id'                => $post->ID,
				'title'             => $snippet->get_title(),
				'active'            => 'publish' === $post->post_status,
				'code_type'         => $snippet->get_code_type(),
				'location'          => $snippet->get_location(),
				'auto_insert'       => (bool) $snippet->get_auto_insert(),
				'tags'              => $snippet->get_tags(),
				'conditional_logic' => $this->get_conditional_logic( $post->ID ),
			);
		}

		return $rows;
	}

	/**
	 * Build the shortcode string for a snippet.
	 *
	 * @param WPCode_Snippet $snippet Snippet instance.
	 * @return string
	 */
	public function build_shortcode( $snippet ) {
		$custom = $snippet->get_custom_shortcode();
		if ( ! empty( $custom ) ) {
			return '[' . sanitize_key( $custom ) . ']';
		}

		$id = $snippet->get_id();
		if ( $id ) {
			return '[wpcode id="' . absint( $id ) . '"]';
		}

		return '';
	}
}
