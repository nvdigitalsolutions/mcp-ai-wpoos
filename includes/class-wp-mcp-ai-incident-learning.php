<?php
/**
 * Incident Learning System
 *
 * Implements ISO 27001:2022 Control A.5.27 - Learning from Information Security Incidents
 * Provides post-incident review, root cause analysis, and trend reporting.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Incident Learning System class.
 *
 * Tracks lessons learned from security incidents and identifies trends.
 */
class WP_MCP_AI_Incident_Learning {
	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Incident_Learning
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Incident_Learning Singleton instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Register custom post type for lessons learned.
		add_action( 'init', array( $this, 'register_post_type' ) );
	}

	/**
	 * Register lessons learned post type.
	 */
	public function register_post_type() {
		register_post_type(
			'mcp_ai_lesson',
			array(
				'labels'              => array(
					'name'               => __( 'Lessons Learned', 'wp-mcp-ai' ),
					'singular_name'      => __( 'Lesson Learned', 'wp-mcp-ai' ),
					'add_new'            => __( 'Add New Lesson', 'wp-mcp-ai' ),
					'add_new_item'       => __( 'Add New Lesson Learned', 'wp-mcp-ai' ),
					'edit_item'          => __( 'Edit Lesson Learned', 'wp-mcp-ai' ),
					'new_item'           => __( 'New Lesson Learned', 'wp-mcp-ai' ),
					'view_item'          => __( 'View Lesson Learned', 'wp-mcp-ai' ),
					'search_items'       => __( 'Search Lessons Learned', 'wp-mcp-ai' ),
					'not_found'          => __( 'No lessons learned found', 'wp-mcp-ai' ),
					'not_found_in_trash' => __( 'No lessons learned found in trash', 'wp-mcp-ai' ),
					'menu_name'          => __( 'Lessons Learned', 'wp-mcp-ai' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'nvoos-pro-dashboard',
				'capability_type'     => 'post',
				'capabilities'        => array(
					'edit_post'          => 'manage_options',
					'read_post'          => 'manage_options',
					'delete_post'        => 'manage_options',
					'edit_posts'         => 'manage_options',
					'edit_others_posts'  => 'manage_options',
					'delete_posts'       => 'manage_options',
					'publish_posts'      => 'manage_options',
					'read_private_posts' => 'manage_options',
				),
				'hierarchical'        => false,
				'supports'            => array( 'title', 'editor', 'author' ),
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'menu_icon'           => 'dashicons-book-alt',
				'register_meta_box_cb' => array( $this, 'add_meta_boxes' ),
			)
		);
	}

	/**
	 * Add meta boxes for lessons learned.
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'wp_mcp_ai_lesson_details',
			__( 'Lesson Details', 'wp-mcp-ai' ),
			array( $this, 'render_details_meta_box' ),
			'mcp_ai_lesson',
			'normal',
			'high'
		);
	}

	/**
	 * Render lesson details meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_details_meta_box( $post ) {
		wp_nonce_field( 'wp_mcp_ai_lesson_nonce', 'wp_mcp_ai_lesson_nonce' );

		$incident_id     = get_post_meta( $post->ID, '_incident_id', true );
		$incident_date   = get_post_meta( $post->ID, '_incident_date', true );
		$root_cause      = get_post_meta( $post->ID, '_root_cause', true );
		$corrective_action = get_post_meta( $post->ID, '_corrective_action', true );
		$preventive_action = get_post_meta( $post->ID, '_preventive_action', true );
		$category        = get_post_meta( $post->ID, '_category', true );
		$severity        = get_post_meta( $post->ID, '_severity', true );
		?>
		<table class="form-table">
			<tr>
				<th><label for="incident_id"><?php esc_html_e( 'Related Incident ID', 'wp-mcp-ai' ); ?></label></th>
				<td>
					<input type="text" id="incident_id" name="incident_id" 
						   value="<?php echo esc_attr( $incident_id ); ?>" 
						   class="regular-text">
					<p class="description"><?php esc_html_e( 'Reference to the security incident', 'wp-mcp-ai' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="incident_date"><?php esc_html_e( 'Incident Date', 'wp-mcp-ai' ); ?></label></th>
				<td>
					<input type="date" id="incident_date" name="incident_date" 
						   value="<?php echo esc_attr( $incident_date ); ?>">
				</td>
			</tr>
			<tr>
				<th><label for="category"><?php esc_html_e( 'Category', 'wp-mcp-ai' ); ?></label></th>
				<td>
					<select id="category" name="category">
						<option value=""><?php esc_html_e( 'Select Category', 'wp-mcp-ai' ); ?></option>
						<option value="access_control" <?php selected( $category, 'access_control' ); ?>><?php esc_html_e( 'Access Control', 'wp-mcp-ai' ); ?></option>
						<option value="data_breach" <?php selected( $category, 'data_breach' ); ?>><?php esc_html_e( 'Data Breach', 'wp-mcp-ai' ); ?></option>
						<option value="malware" <?php selected( $category, 'malware' ); ?>><?php esc_html_e( 'Malware', 'wp-mcp-ai' ); ?></option>
						<option value="phishing" <?php selected( $category, 'phishing' ); ?>><?php esc_html_e( 'Phishing', 'wp-mcp-ai' ); ?></option>
						<option value="denial_of_service" <?php selected( $category, 'denial_of_service' ); ?>><?php esc_html_e( 'Denial of Service', 'wp-mcp-ai' ); ?></option>
						<option value="vulnerability" <?php selected( $category, 'vulnerability' ); ?>><?php esc_html_e( 'Vulnerability Exploitation', 'wp-mcp-ai' ); ?></option>
						<option value="configuration" <?php selected( $category, 'configuration' ); ?>><?php esc_html_e( 'Configuration Error', 'wp-mcp-ai' ); ?></option>
						<option value="other" <?php selected( $category, 'other' ); ?>><?php esc_html_e( 'Other', 'wp-mcp-ai' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="severity"><?php esc_html_e( 'Severity', 'wp-mcp-ai' ); ?></label></th>
				<td>
					<select id="severity" name="severity">
						<option value="low" <?php selected( $severity, 'low' ); ?>><?php esc_html_e( 'Low', 'wp-mcp-ai' ); ?></option>
						<option value="medium" <?php selected( $severity, 'medium' ); ?>><?php esc_html_e( 'Medium', 'wp-mcp-ai' ); ?></option>
						<option value="high" <?php selected( $severity, 'high' ); ?>><?php esc_html_e( 'High', 'wp-mcp-ai' ); ?></option>
						<option value="critical" <?php selected( $severity, 'critical' ); ?>><?php esc_html_e( 'Critical', 'wp-mcp-ai' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="root_cause"><?php esc_html_e( 'Root Cause Analysis', 'wp-mcp-ai' ); ?></label></th>
				<td>
					<textarea id="root_cause" name="root_cause" rows="4" class="large-text"><?php echo esc_textarea( $root_cause ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Underlying cause of the incident', 'wp-mcp-ai' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="corrective_action"><?php esc_html_e( 'Corrective Actions', 'wp-mcp-ai' ); ?></label></th>
				<td>
					<textarea id="corrective_action" name="corrective_action" rows="4" class="large-text"><?php echo esc_textarea( $corrective_action ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Actions taken to resolve the incident', 'wp-mcp-ai' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="preventive_action"><?php esc_html_e( 'Preventive Actions', 'wp-mcp-ai' ); ?></label></th>
				<td>
					<textarea id="preventive_action" name="preventive_action" rows="4" class="large-text"><?php echo esc_textarea( $preventive_action ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Actions to prevent recurrence', 'wp-mcp-ai' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save lesson learned meta data.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_lesson_meta( $post_id ) {
		// Check nonce.
		if ( ! isset( $_POST['wp_mcp_ai_lesson_nonce'] ) ||
			 ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_lesson_nonce'] ) ), 'wp_mcp_ai_lesson_nonce' ) ) {
			return;
		}

		// Check autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save meta fields.
		$fields = array( 'incident_id', 'incident_date', 'root_cause', 'corrective_action', 'preventive_action', 'category', 'severity' );

		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, '_' . $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}
	}

	/**
	 * Create lesson learned from incident.
	 *
	 * @param string $incident_id     Incident ID.
	 * @param string $title           Lesson title.
	 * @param string $description     Lesson description.
	 * @param array  $lesson_data     Additional lesson data.
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	public function create_lesson( $incident_id, $title, $description, $lesson_data = array() ) {
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_lesson',
				'post_title'   => $title,
				'post_content' => $description,
				'post_status'  => 'publish',
				'post_author'  => get_current_user_id(),
			)
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Save meta data.
		update_post_meta( $post_id, '_incident_id', sanitize_text_field( $incident_id ) );

		if ( isset( $lesson_data['incident_date'] ) ) {
			update_post_meta( $post_id, '_incident_date', sanitize_text_field( $lesson_data['incident_date'] ) );
		}

		if ( isset( $lesson_data['root_cause'] ) ) {
			update_post_meta( $post_id, '_root_cause', sanitize_textarea_field( $lesson_data['root_cause'] ) );
		}

		if ( isset( $lesson_data['corrective_action'] ) ) {
			update_post_meta( $post_id, '_corrective_action', sanitize_textarea_field( $lesson_data['corrective_action'] ) );
		}

		if ( isset( $lesson_data['preventive_action'] ) ) {
			update_post_meta( $post_id, '_preventive_action', sanitize_textarea_field( $lesson_data['preventive_action'] ) );
		}

		if ( isset( $lesson_data['category'] ) ) {
			update_post_meta( $post_id, '_category', sanitize_text_field( $lesson_data['category'] ) );
		}

		if ( isset( $lesson_data['severity'] ) ) {
			update_post_meta( $post_id, '_severity', sanitize_text_field( $lesson_data['severity'] ) );
		}

		return $post_id;
	}

	/**
	 * Get trend analysis data.
	 *
	 * @param string $period Time period (month, quarter, year).
	 * @return array Trend analysis data.
	 */
	public function get_trend_analysis( $period = 'quarter' ) {
		$date_query = array();

		switch ( $period ) {
			case 'month':
				$date_query['after'] = '1 month ago';
				break;
			case 'quarter':
				$date_query['after'] = '3 months ago';
				break;
			case 'year':
				$date_query['after'] = '1 year ago';
				break;
		}

		$lessons = get_posts(
			array(
				'post_type'      => 'mcp_ai_lesson',
				'posts_per_page' => -1,
				'date_query'     => array( $date_query ),
			)
		);

		$trends = array(
			'total_lessons'       => count( $lessons ),
			'by_category'         => array(),
			'by_severity'         => array(),
			'common_root_causes'  => array(),
			'effectiveness_score' => 0,
		);

		foreach ( $lessons as $lesson ) {
			$category = get_post_meta( $lesson->ID, '_category', true );
			$severity = get_post_meta( $lesson->ID, '_severity', true );

			if ( ! isset( $trends['by_category'][ $category ] ) ) {
				$trends['by_category'][ $category ] = 0;
			}
			++$trends['by_category'][ $category ];

			if ( ! isset( $trends['by_severity'][ $severity ] ) ) {
				$trends['by_severity'][ $severity ] = 0;
			}
			++$trends['by_severity'][ $severity ];
		}

		return $trends;
	}

	/**
	 * Get all lessons learned.
	 *
	 * @param array $args Query arguments.
	 * @return array Array of lessons.
	 */
	public function get_lessons( $args = array() ) {
		$defaults = array(
			'post_type'      => 'mcp_ai_lesson',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
		);

		$args = wp_parse_args( $args, $defaults );

		$lessons = get_posts( $args );
		$result  = array();

		foreach ( $lessons as $lesson ) {
			$result[] = array(
				'id'                => $lesson->ID,
				'title'             => $lesson->post_title,
				'description'       => $lesson->post_content,
				'incident_id'       => get_post_meta( $lesson->ID, '_incident_id', true ),
				'incident_date'     => get_post_meta( $lesson->ID, '_incident_date', true ),
				'root_cause'        => get_post_meta( $lesson->ID, '_root_cause', true ),
				'corrective_action' => get_post_meta( $lesson->ID, '_corrective_action', true ),
				'preventive_action' => get_post_meta( $lesson->ID, '_preventive_action', true ),
				'category'          => get_post_meta( $lesson->ID, '_category', true ),
				'severity'          => get_post_meta( $lesson->ID, '_severity', true ),
				'created_date'      => $lesson->post_date,
			);
		}

		return $result;
	}
}
