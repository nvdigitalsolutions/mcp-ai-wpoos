<?php
/**
 * Place Location Metabox.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Place Location metabox for place posts.
 */
class WP_MCP_AI_Place_Metabox_Location extends WP_MCP_AI_Place_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_place_location';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Location & Address', 'wp-mcp-ai' );
	}

	/**
	 * Render the metabox content.
	 *
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public function render( $post ) {
		if ( ! $this->can_view() ) {
			$this->render_permission_denied();
			return;
		}

		$address    = get_post_meta( $post->ID, '_place_address', true );
		$latitude   = get_post_meta( $post->ID, '_place_latitude', true );
		$longitude  = get_post_meta( $post->ID, '_place_longitude', true );
		$components = get_post_meta( $post->ID, '_place_address_components', true );

		if ( ! is_array( $components ) ) {
			$components = array();
		}

		wp_nonce_field( 'wp_mcp_ai_place_location_nonce', 'wp_mcp_ai_place_location_nonce' );
		?>
		<div class="wp-mcp-ai-place-location">
			<table class="form-table">
				<tr>
					<th scope="row"><label for="place_address"><?php esc_html_e( 'Full Address', 'wp-mcp-ai' ); ?></label></th>
					<td>
						<input type="text" id="place_address" name="place_address" value="<?php echo esc_attr( $address ); ?>" class="large-text" />
						<p class="description"><?php esc_html_e( 'Complete address including street, city, state, country', 'wp-mcp-ai' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="place_latitude"><?php esc_html_e( 'Latitude', 'wp-mcp-ai' ); ?></label></th>
					<td>
						<input type="text" id="place_latitude" name="place_latitude" value="<?php echo esc_attr( $latitude ); ?>" class="regular-text" step="any" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="place_longitude"><?php esc_html_e( 'Longitude', 'wp-mcp-ai' ); ?></label></th>
					<td>
						<input type="text" id="place_longitude" name="place_longitude" value="<?php echo esc_attr( $longitude ); ?>" class="regular-text" step="any" />
						<p class="description"><?php esc_html_e( 'GPS coordinates (auto-filled via geocoding or Google Maps)', 'wp-mcp-ai' ); ?></p>
					</td>
				</tr>
			</table>

			<h4><?php esc_html_e( 'Address Components', 'wp-mcp-ai' ); ?></h4>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="place_street"><?php esc_html_e( 'Street', 'wp-mcp-ai' ); ?></label></th>
					<td><input type="text" id="place_street" name="place_street" value="<?php echo esc_attr( isset( $components['street'] ) ? $components['street'] : '' ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="place_city"><?php esc_html_e( 'City', 'wp-mcp-ai' ); ?></label></th>
					<td><input type="text" id="place_city" name="place_city" value="<?php echo esc_attr( isset( $components['city'] ) ? $components['city'] : '' ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="place_state"><?php esc_html_e( 'State/Province', 'wp-mcp-ai' ); ?></label></th>
					<td><input type="text" id="place_state" name="place_state" value="<?php echo esc_attr( isset( $components['state'] ) ? $components['state'] : '' ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="place_country"><?php esc_html_e( 'Country', 'wp-mcp-ai' ); ?></label></th>
					<td><input type="text" id="place_country" name="place_country" value="<?php echo esc_attr( isset( $components['country'] ) ? $components['country'] : '' ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="place_postal_code"><?php esc_html_e( 'Postal Code', 'wp-mcp-ai' ); ?></label></th>
					<td><input type="text" id="place_postal_code" name="place_postal_code" value="<?php echo esc_attr( isset( $components['postal_code'] ) ? $components['postal_code'] : '' ); ?>" class="regular-text" /></td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Save metabox data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['wp_mcp_ai_place_location_nonce'] ) || ! wp_verify_nonce( $_POST['wp_mcp_ai_place_location_nonce'], 'wp_mcp_ai_place_location_nonce' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['place_address'] ) ) {
			update_post_meta( $post_id, '_place_address', sanitize_text_field( $_POST['place_address'] ) );
		}

		if ( isset( $_POST['place_latitude'] ) ) {
			update_post_meta( $post_id, '_place_latitude', floatval( $_POST['place_latitude'] ) );
		}

		if ( isset( $_POST['place_longitude'] ) ) {
			update_post_meta( $post_id, '_place_longitude', floatval( $_POST['place_longitude'] ) );
		}

		$components = array();
		if ( isset( $_POST['place_street'] ) ) {
			$components['street'] = sanitize_text_field( $_POST['place_street'] );
		}
		if ( isset( $_POST['place_city'] ) ) {
			$components['city'] = sanitize_text_field( $_POST['place_city'] );
		}
		if ( isset( $_POST['place_state'] ) ) {
			$components['state'] = sanitize_text_field( $_POST['place_state'] );
		}
		if ( isset( $_POST['place_country'] ) ) {
			$components['country'] = sanitize_text_field( $_POST['place_country'] );
		}
		if ( isset( $_POST['place_postal_code'] ) ) {
			$components['postal_code'] = sanitize_text_field( $_POST['place_postal_code'] );
		}

		if ( ! empty( $components ) ) {
			update_post_meta( $post_id, '_place_address_components', $components );
		}
	}
}
