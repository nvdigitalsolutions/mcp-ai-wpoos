<?php
/**
 * Place Details Metabox.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Place Details metabox for ratings, hours, and amenities.
 */
class WP_MCP_AI_Place_Metabox_Details extends WP_MCP_AI_Place_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_place_details';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Place Details', 'mcp-ai-wpoos-pro' );
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

		$rating          = get_post_meta( $post->ID, '_place_rating', true );
		$price_level     = get_post_meta( $post->ID, '_place_price_level', true );
		$business_hours  = get_post_meta( $post->ID, '_place_business_hours', true );
		$amenities       = get_post_meta( $post->ID, '_place_amenities', true );
		$google_place_id = get_post_meta( $post->ID, '_place_google_place_id', true );

		if ( ! is_array( $business_hours ) ) {
			$business_hours = array();
		}

		if ( ! is_array( $amenities ) ) {
			$amenities = array();
		}

		wp_nonce_field( 'wp_mcp_ai_place_details_nonce', 'wp_mcp_ai_place_details_nonce' );
		?>
		<div class="wp-mcp-ai-place-details">
			<table class="form-table">
				<tr>
					<th scope="row"><label for="place_rating"><?php esc_html_e( 'Rating', 'mcp-ai-wpoos-pro' ); ?></label></th>
					<td>
						<input type="number" id="place_rating" name="place_rating" value="<?php echo esc_attr( $rating ); ?>" min="0" max="5" step="0.1" class="small-text" />
						<span class="description"><?php esc_html_e( 'Out of 5.0', 'mcp-ai-wpoos-pro' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="place_price_level"><?php esc_html_e( 'Price Level', 'mcp-ai-wpoos-pro' ); ?></label></th>
					<td>
						<select id="place_price_level" name="place_price_level">
							<option value=""><?php esc_html_e( '-- Select --', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="1" <?php selected( $price_level, '1' ); ?>>$ <?php esc_html_e( '(Budget)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="2" <?php selected( $price_level, '2' ); ?>>$$ <?php esc_html_e( '(Moderate)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="3" <?php selected( $price_level, '3' ); ?>>$$$ <?php esc_html_e( '(Expensive)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="4" <?php selected( $price_level, '4' ); ?>>$$$$ <?php esc_html_e( '(Very Expensive)', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="place_google_place_id"><?php esc_html_e( 'Google Place ID', 'mcp-ai-wpoos-pro' ); ?></label></th>
					<td>
						<input type="text" id="place_google_place_id" name="place_google_place_id" value="<?php echo esc_attr( $google_place_id ); ?>" class="regular-text" readonly />
						<p class="description"><?php esc_html_e( 'Auto-populated from Google Places API', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
			</table>

			<h4><?php esc_html_e( 'Business Hours', 'mcp-ai-wpoos-pro' ); ?></h4>
			<p class="description"><?php esc_html_e( 'Enter hours in format: 9:00 AM - 5:00 PM or "Closed"', 'mcp-ai-wpoos-pro' ); ?></p>
			<table class="form-table">
				<?php
				$days = array(
					'monday'    => __( 'Monday', 'mcp-ai-wpoos-pro' ),
					'tuesday'   => __( 'Tuesday', 'mcp-ai-wpoos-pro' ),
					'wednesday' => __( 'Wednesday', 'mcp-ai-wpoos-pro' ),
					'thursday'  => __( 'Thursday', 'mcp-ai-wpoos-pro' ),
					'friday'    => __( 'Friday', 'mcp-ai-wpoos-pro' ),
					'saturday'  => __( 'Saturday', 'mcp-ai-wpoos-pro' ),
					'sunday'    => __( 'Sunday', 'mcp-ai-wpoos-pro' ),
				);

				foreach ( $days as $day_key => $day_label ) :
					$day_value = isset( $business_hours[ $day_key ] ) ? $business_hours[ $day_key ] : '';
					?>
					<tr>
						<th scope="row"><label for="business_hours_<?php echo esc_attr( $day_key ); ?>"><?php echo esc_html( $day_label ); ?></label></th>
						<td>
							<input type="text" id="business_hours_<?php echo esc_attr( $day_key ); ?>" name="business_hours[<?php echo esc_attr( $day_key ); ?>]" value="<?php echo esc_attr( $day_value ); ?>" class="regular-text" placeholder="9:00 AM - 5:00 PM" />
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<h4><?php esc_html_e( 'Amenities', 'mcp-ai-wpoos-pro' ); ?></h4>
			<p class="description"><?php esc_html_e( 'Enter one amenity per line (e.g., wifi, parking, wheelchair_accessible)', 'mcp-ai-wpoos-pro' ); ?></p>
			<textarea id="place_amenities" name="place_amenities" rows="5" class="large-text"><?php echo esc_textarea( implode( "\n", $amenities ) ); ?></textarea>
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
		if ( ! isset( $_POST['wp_mcp_ai_place_details_nonce'] ) || ! wp_verify_nonce( $_POST['wp_mcp_ai_place_details_nonce'], 'wp_mcp_ai_place_details_nonce' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['place_rating'] ) ) {
			$rating = floatval( $_POST['place_rating'] );
			$rating = max( 0, min( 5, $rating ) );
			update_post_meta( $post_id, '_place_rating', $rating );
		}

		if ( isset( $_POST['place_price_level'] ) ) {
			$price_level = absint( $_POST['place_price_level'] );
			if ( $price_level >= 1 && $price_level <= 4 ) {
				update_post_meta( $post_id, '_place_price_level', $price_level );
			}
		}

		if ( isset( $_POST['place_google_place_id'] ) ) {
			update_post_meta( $post_id, '_place_google_place_id', sanitize_text_field( $_POST['place_google_place_id'] ) );
		}

		if ( isset( $_POST['business_hours'] ) && is_array( $_POST['business_hours'] ) ) {
			$sanitized_hours = array();
			foreach ( $_POST['business_hours'] as $day => $hours ) {
				$sanitized_hours[ sanitize_key( $day ) ] = sanitize_text_field( $hours );
			}
			update_post_meta( $post_id, '_place_business_hours', $sanitized_hours );
		}

		if ( isset( $_POST['place_amenities'] ) ) {
			$amenities_text = sanitize_textarea_field( $_POST['place_amenities'] );
			$amenities      = array_filter( array_map( 'trim', explode( "\n", $amenities_text ) ) );
			update_post_meta( $post_id, '_place_amenities', $amenities );
		}
	}
}
