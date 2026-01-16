<?php
/**
 * Quiz Custom Post Type for managing tutor quizzes.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the Quiz custom post type.
 */
class WP_MCP_AI_Quiz_CPT {
	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_quiz';

	/**
	 * Submission post type slug.
	 *
	 * @var string
	 */
	const SUBMISSION_POST_TYPE = 'mcp_ai_submission';

	/**
	 * Sync lock timeout in seconds.
	 *
	 * @var int
	 */
	const SYNC_LOCK_TIMEOUT = 5;

	/**
	 * Metabox instances.
	 *
	 * @var array
	 */
	protected static $metaboxes = array();

	/**
	 * Initialize the class.
	 */
	public static function init() {
		// Only available in Full Version (not Base Version).
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			// Still show notice if accessing quiz pages.
			add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );
			return;
		}

		// Only initialize if quiz system is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_quiz_system'] ) ) {
			// Show notice if trying to access quiz pages when disabled.
			add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );
			return;
		}

		add_action( 'init', array( __CLASS__, 'register_post_types' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_quiz_meta' ), 5, 2 );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'sync_quiz_to_cct' ), 10, 2 );
		add_action( 'save_post_' . self::SUBMISSION_POST_TYPE, array( __CLASS__, 'sync_submission_to_cct' ), 10, 2 );
		add_action( 'delete_post', array( __CLASS__, 'handle_post_deletion' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'show_info_notice' ) );

		// Load metabox classes.
		self::load_metabox_classes();
	}

	/**
	 * Show admin notice when quiz system is disabled but user tries to access quiz pages.
	 */
	public static function show_disabled_notice() {
		// Only show on quiz-related pages.
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// Check if we're on a quiz or submission post type page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking URL parameter for display logic.
		$post_type    = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';
		$is_quiz_page = ( $post_type === self::POST_TYPE || $post_type === self::SUBMISSION_POST_TYPE );
		if ( ! $is_quiz_page ) {
			return;
		}

		// Check if in Base Version.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Quiz System Not Available', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						__( 'The Quiz System is a <strong>Full Version</strong> feature and is not available in Base Version mode.', 'mcp-ai-wpoos-pro' )
					);
					?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Code snippet */
							__( 'To use the Quiz System, remove or set to <code>false</code> the following constant in your <code>wp-config.php</code>: %s', 'mcp-ai-wpoos-pro' ),
							'<code>define( \'WP_MCP_AI_BASE_VERSION\', true );</code>'
						)
					);
					?>
				</p>
			</div>
			<?php
			return;
		}

		// Check if feature is disabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_quiz_system'] ) ) {
			$settings_url = admin_url( 'admin.php?page=wp_mcp_ai_settings&tab=tools' );
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Quiz System Disabled', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'The Quiz System is currently disabled. Enable it to create and manage quizzes.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Link to settings page */
							__( 'To enable the Quiz System, go to <a href="%s">Settings &rarr; NV oOS &rarr; Tools &amp; Features</a>, click the <strong>Features</strong> tab, check <strong>"Enable Quiz System"</strong>, and save your changes.', 'mcp-ai-wpoos-pro' ),
							esc_url( $settings_url )
						)
					);
					?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Load metabox classes.
	 */
	protected static function load_metabox_classes() {
		// Load base metabox class.
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-quiz-metabox-base.php';

		// Load metabox implementations.
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-quiz-metabox-details.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-quiz-metabox-questions.php';

		// Initialize metabox instances.
		self::$metaboxes['details']   = new WP_MCP_AI_Quiz_Metabox_Details();
		self::$metaboxes['questions'] = new WP_MCP_AI_Quiz_Metabox_Questions();
	}

	/**
	 * Register meta boxes for quiz editing.
	 */
	public static function register_meta_boxes() {
		$screen = get_current_screen();

		// Only add metaboxes on quiz edit screen.
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		// Register each metabox.
		foreach ( self::$metaboxes as $metabox ) {
			add_meta_box(
				$metabox->get_id(),
				$metabox->get_title(),
				array( $metabox, 'render' ),
				self::POST_TYPE,
				$metabox->get_context(),
				$metabox->get_priority()
			);
		}
	}

	/**
	 * Save quiz meta data from metaboxes.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_quiz_meta( $post_id, $post ) {
		// Check if this is an autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check post type.
		if ( self::POST_TYPE !== $post->post_type ) {
			return;
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Call save method on each metabox.
		foreach ( self::$metaboxes as $metabox ) {
			$metabox->save( $post_id, $post );
		}
	}

	/**
	 * Show informational notice on quiz edit screen.
	 */
	public static function show_info_notice() {
		$screen = get_current_screen();

		// Only show on quiz edit screens.
		if ( ! $screen || ! in_array( $screen->id, array( self::POST_TYPE, 'edit-' . self::POST_TYPE ), true ) ) {
			return;
		}

		// Don't show if feature is disabled (other notice will show).
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_quiz_system'] ) ) {
			return;
		}
		?>
		<div class="notice notice-info quiz-info-notice">
			<p>
				<strong><?php esc_html_e( 'Quiz Management', 'mcp-ai-wpoos-pro' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'Quizzes can be created and managed both manually here in the WordPress admin and via AI assistant tools.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>Manual Management:</strong> Use the editor below to add a description, and the "Quiz Questions" metabox to add/edit questions.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>AI Tools:</strong> AI assistants can create quizzes using the <code>create_quiz</code> tool, and you can edit them here afterwards.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Register Quiz and Submission custom post types.
	 */
	public static function register_post_types() {
		// Register Quiz CPT.
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => array(
					'name'               => _x( 'Quizzes', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => _x( 'Quiz', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'menu_name'          => _x( 'Quizzes', 'admin menu', 'mcp-ai-wpoos-pro' ),
					'name_admin_bar'     => _x( 'Quiz', 'add new on admin bar', 'mcp-ai-wpoos-pro' ),
					'add_new'            => _x( 'Add New', 'quiz', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add New Quiz', 'mcp-ai-wpoos-pro' ),
					'new_item'           => __( 'New Quiz', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit Quiz', 'mcp-ai-wpoos-pro' ),
					'view_item'          => __( 'View Quiz', 'mcp-ai-wpoos-pro' ),
					'all_items'          => __( 'All Quizzes', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search Quizzes', 'mcp-ai-wpoos-pro' ),
					'parent_item_colon'  => __( 'Parent Quizzes:', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No quizzes found.', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No quizzes found in Trash.', 'mcp-ai-wpoos-pro' ),
				),
				'description'        => __( 'Quizzes created by tutors for students.', 'mcp-ai-wpoos-pro' ),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'menu_icon'          => 'dashicons-welcome-learn-more',
				'query_var'          => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'has_archive'        => false,
				'hierarchical'       => false,
				'menu_position'      => null,
				'supports'           => array( 'title', 'editor', 'author' ),
				'show_in_rest'       => false,
			)
		);

		// Register Submission CPT.
		register_post_type(
			self::SUBMISSION_POST_TYPE,
			array(
				'labels'             => array(
					'name'               => _x( 'Quiz Submissions', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => _x( 'Quiz Submission', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'menu_name'          => _x( 'Quiz Submissions', 'admin menu', 'mcp-ai-wpoos-pro' ),
					'name_admin_bar'     => _x( 'Submission', 'add new on admin bar', 'mcp-ai-wpoos-pro' ),
					'add_new'            => _x( 'Add New', 'submission', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add New Submission', 'mcp-ai-wpoos-pro' ),
					'new_item'           => __( 'New Submission', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit Submission', 'mcp-ai-wpoos-pro' ),
					'view_item'          => __( 'View Submission', 'mcp-ai-wpoos-pro' ),
					'all_items'          => __( 'All Submissions', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search Submissions', 'mcp-ai-wpoos-pro' ),
					'parent_item_colon'  => __( 'Parent Submissions:', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No submissions found.', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No submissions found in Trash.', 'mcp-ai-wpoos-pro' ),
				),
				'description'        => __( 'User submissions for quizzes.', 'mcp-ai-wpoos-pro' ),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'menu_icon'          => 'dashicons-clipboard',
				'query_var'          => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'has_archive'        => false,
				'hierarchical'       => false,
				'menu_position'      => null,
				'supports'           => array( 'author' ),
				'show_in_rest'       => false,
			)
		);
	}

	/**
	 * Synchronize quiz CPT data to the JetEngine quizzes CCT.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function sync_quiz_to_cct( $post_id, $post ) {
		// Only sync in Full Version when JetEngine is available.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Quizzes_CCT' ) ) {
			return;
		}

		// Only sync published quizzes to CCT.
		if ( 'publish' !== $post->post_status ) {
			self::delete_quiz_cct_item( $post_id );
			return;
		}

		// Prevent concurrent sync operations using a transient lock.
		$lock_key = 'wp_mcp_ai_quiz_sync_lock_' . $post_id;
		if ( get_transient( $lock_key ) ) {
			return;
		}

		// Set a short-lived lock.
		set_transient( $lock_key, true, self::SYNC_LOCK_TIMEOUT );

		try {
			// Get the CCT item handler.
			$handler = WP_MCP_AI_JetEngine_Quizzes_CCT::get_item_handler();

			if ( ! $handler ) {
				return;
			}

			// Validate handler has required methods.
			if ( ! method_exists( $handler, 'update_item' ) ) {
				return;
			}

			// Get quiz metadata.
			$description   = get_post_meta( $post_id, '_mcp_ai_quiz_description', true );
			$time_limit    = get_post_meta( $post_id, '_mcp_ai_quiz_time_limit', true );
			$questions     = get_post_meta( $post_id, '_mcp_ai_quiz_questions', true );
			$total_points  = get_post_meta( $post_id, '_mcp_ai_quiz_total_points', true );
			$passing_score = get_post_meta( $post_id, '_mcp_ai_quiz_passing_score', true );

			// Map CPT data to CCT fields.
			$cct_data = array(
				'title'          => $post->post_title,
				'description'    => $description ? $description : '',
				'author_id'      => absint( $post->post_author ),
				'time_limit'     => absint( $time_limit ),
				'question_count' => is_array( $questions ) ? count( $questions ) : 0,
				'total_points'   => absint( $total_points ),
				'passing_score'  => absint( $passing_score ),
				'cpt_post_id'    => $post_id,
			);

			// Check if a CCT item already exists for this CPT post ID.
			$cct_item_id = get_post_meta( $post_id, '_wp_mcp_ai_quiz_cct_item_id', true );

			if ( $cct_item_id ) {
				// Update existing CCT item.
				$cct_data['_ID'] = absint( $cct_item_id );
				$result          = $handler->update_item( $cct_data );

				if ( ! $result ) {
					// If update failed, the item might have been deleted. Clear the link and create new.
					delete_post_meta( $post_id, '_wp_mcp_ai_quiz_cct_item_id' );
					$cct_item_id = 0;
				}
			}

			if ( ! $cct_item_id ) {
				// Create new CCT item.
				$new_item_id = $handler->update_item( $cct_data );

				if ( $new_item_id ) {
					// Store the link between CPT post ID and CCT item ID.
					update_post_meta( $post_id, '_wp_mcp_ai_quiz_cct_item_id', $new_item_id );
				}
			}
		} catch ( Exception $e ) {
			// Intentionally empty - error handled elsewhere.
			// Log error but don't block the save process.
			if ( function_exists( 'error_log' ) ) {
				error_log( 'WP MCP AI: Quiz CCT sync failed for post ' . $post_id . ': ' . $e->getMessage() );
			}
		} finally {
			// Always release the lock.
			delete_transient( $lock_key );
		}
	}

	/**
	 * Synchronize submission CPT data to the JetEngine submissions CCT.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function sync_submission_to_cct( $post_id, $post ) {
		// Only sync in Full Version when JetEngine is available.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Submissions_CCT' ) ) {
			return;
		}

		// Sync both pending and published (graded) submissions.
		if ( ! in_array( $post->post_status, array( 'pending', 'publish' ), true ) ) {
			self::delete_submission_cct_item( $post_id );
			return;
		}

		// Prevent concurrent sync operations using a transient lock.
		$lock_key = 'wp_mcp_ai_submission_sync_lock_' . $post_id;
		if ( get_transient( $lock_key ) ) {
			return;
		}

		// Set a short-lived lock.
		set_transient( $lock_key, true, self::SYNC_LOCK_TIMEOUT );

		try {
			// Get the CCT item handler.
			$handler = WP_MCP_AI_JetEngine_Submissions_CCT::get_item_handler();

			if ( ! $handler ) {
				return;
			}

			// Validate handler has required methods.
			if ( ! method_exists( $handler, 'update_item' ) ) {
				return;
			}

			// Get submission metadata.
			$quiz_id         = get_post_meta( $post_id, '_mcp_ai_submission_quiz_id', true );
			$status          = get_post_meta( $post_id, '_mcp_ai_submission_status', true );
			$earned_points   = get_post_meta( $post_id, '_mcp_ai_submission_earned_points', true );
			$total_points    = get_post_meta( $post_id, '_mcp_ai_submission_total_points', true );
			$percentage      = get_post_meta( $post_id, '_mcp_ai_submission_percentage', true );
			$passed          = get_post_meta( $post_id, '_mcp_ai_submission_passed', true );
			$graded_by       = get_post_meta( $post_id, '_mcp_ai_submission_graded_by', true );
			$started_at      = get_post_meta( $post_id, '_mcp_ai_submission_started_at', true );
			$completion_time = get_post_meta( $post_id, '_mcp_ai_submission_completion_time', true );

			// Map CPT data to CCT fields.
			$cct_data = array(
				'quiz_id'     => absint( $quiz_id ),
				'student_id'  => absint( $post->post_author ),
				'status'      => $status ? $status : 'pending',
				'cpt_post_id' => $post_id,
			);

			// Add time tracking data if available.
			if ( $started_at ) {
				$cct_data['started_at'] = sanitize_text_field( $started_at );
			}
			if ( $completion_time ) {
				$cct_data['completion_time'] = floatval( $completion_time );
			}

			// Add grading data if available.
			if ( 'graded' === $status ) {
				$cct_data['earned_points'] = floatval( $earned_points );
				$cct_data['total_points']  = floatval( $total_points );
				$cct_data['percentage']    = floatval( $percentage );
				$cct_data['passed']        = (bool) $passed;
				if ( $graded_by ) {
					$cct_data['graded_by'] = absint( $graded_by );
				}
			}

			// Check if a CCT item already exists for this CPT post ID.
			$cct_item_id = get_post_meta( $post_id, '_wp_mcp_ai_submission_cct_item_id', true );

			if ( $cct_item_id ) {
				// Update existing CCT item.
				$cct_data['_ID'] = absint( $cct_item_id );
				$result          = $handler->update_item( $cct_data );

				if ( ! $result ) {
					// If update failed, the item might have been deleted. Clear the link and create new.
					delete_post_meta( $post_id, '_wp_mcp_ai_submission_cct_item_id' );
					$cct_item_id = 0;
				}
			}

			if ( ! $cct_item_id ) {
				// Create new CCT item.
				$new_item_id = $handler->update_item( $cct_data );

				if ( $new_item_id ) {
					// Store the link between CPT post ID and CCT item ID.
					update_post_meta( $post_id, '_wp_mcp_ai_submission_cct_item_id', $new_item_id );
				}
			}
		} catch ( Exception $e ) {
			// Intentionally empty - error handled elsewhere.
			// Log error but don't block the save process.
			if ( function_exists( 'error_log' ) ) {
				error_log( 'WP MCP AI: Submission CCT sync failed for post ' . $post_id . ': ' . $e->getMessage() );
			}
		} finally {
			// Always release the lock.
			delete_transient( $lock_key );
		}
	}

	/**
	 * Delete quiz CCT item when quiz is unpublished or deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	protected static function delete_quiz_cct_item( $post_id ) {
		$cct_item_id = get_post_meta( $post_id, '_wp_mcp_ai_quiz_cct_item_id', true );

		if ( ! $cct_item_id ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Quizzes_CCT' ) ) {
			return;
		}

		$handler = WP_MCP_AI_JetEngine_Quizzes_CCT::get_item_handler();

		if ( ! $handler || ! method_exists( $handler, 'delete_item' ) ) {
			return;
		}

		$handler->delete_item( absint( $cct_item_id ) );
		delete_post_meta( $post_id, '_wp_mcp_ai_quiz_cct_item_id' );
	}

	/**
	 * Delete submission CCT item when submission is deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	protected static function delete_submission_cct_item( $post_id ) {
		$cct_item_id = get_post_meta( $post_id, '_wp_mcp_ai_submission_cct_item_id', true );

		if ( ! $cct_item_id ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Submissions_CCT' ) ) {
			return;
		}

		$handler = WP_MCP_AI_JetEngine_Submissions_CCT::get_item_handler();

		if ( ! $handler || ! method_exists( $handler, 'delete_item' ) ) {
			return;
		}

		$handler->delete_item( absint( $cct_item_id ) );
		delete_post_meta( $post_id, '_wp_mcp_ai_submission_cct_item_id' );
	}

	/**
	 * Handle post deletion to clean up CCT items and cascade delete submissions.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function handle_post_deletion( $post_id, $post ) {
		if ( self::POST_TYPE === $post->post_type ) {
			// Delete quiz CCT item.
			self::delete_quiz_cct_item( $post_id );

			// Cascade delete all submissions for this quiz.
			self::delete_quiz_submissions( $post_id );
		} elseif ( self::SUBMISSION_POST_TYPE === $post->post_type ) {
			self::delete_submission_cct_item( $post_id );
		}
	}

	/**
	 * Delete all submissions associated with a quiz.
	 *
	 * @param int $quiz_id Quiz post ID.
	 */
	protected static function delete_quiz_submissions( $quiz_id ) {
		$submissions = get_posts(
			array(
				'post_type'   => self::SUBMISSION_POST_TYPE,
				'meta_key'    => '_mcp_ai_submission_quiz_id',
				'meta_value'  => $quiz_id,
				'post_status' => array( 'publish', 'pending', 'trash' ),
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);

		foreach ( $submissions as $submission_id ) {
			// Force delete (bypass trash).
			wp_delete_post( $submission_id, true );
		}
	}
}

WP_MCP_AI_Quiz_CPT::init();
