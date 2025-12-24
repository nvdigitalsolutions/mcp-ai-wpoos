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
	 * Initialize the class.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_types' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'sync_quiz_to_cct' ), 10, 2 );
		add_action( 'save_post_' . self::SUBMISSION_POST_TYPE, array( __CLASS__, 'sync_submission_to_cct' ), 10, 2 );
		add_action( 'delete_post', array( __CLASS__, 'handle_post_deletion' ), 10, 2 );
	}

	/**
	 * Register Quiz and Submission custom post types.
	 */
	public static function register_post_types() {
		// Register Quiz CPT.
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => _x( 'Quizzes', 'post type general name', 'wp-mcp-ai' ),
					'singular_name'      => _x( 'Quiz', 'post type singular name', 'wp-mcp-ai' ),
					'menu_name'          => _x( 'Quizzes', 'admin menu', 'wp-mcp-ai' ),
					'name_admin_bar'     => _x( 'Quiz', 'add new on admin bar', 'wp-mcp-ai' ),
					'add_new'            => _x( 'Add New', 'quiz', 'wp-mcp-ai' ),
					'add_new_item'       => __( 'Add New Quiz', 'wp-mcp-ai' ),
					'new_item'           => __( 'New Quiz', 'wp-mcp-ai' ),
					'edit_item'          => __( 'Edit Quiz', 'wp-mcp-ai' ),
					'view_item'          => __( 'View Quiz', 'wp-mcp-ai' ),
					'all_items'          => __( 'All Quizzes', 'wp-mcp-ai' ),
					'search_items'       => __( 'Search Quizzes', 'wp-mcp-ai' ),
					'parent_item_colon'  => __( 'Parent Quizzes:', 'wp-mcp-ai' ),
					'not_found'          => __( 'No quizzes found.', 'wp-mcp-ai' ),
					'not_found_in_trash' => __( 'No quizzes found in Trash.', 'wp-mcp-ai' ),
				),
				'description'         => __( 'Quizzes created by tutors for students.', 'wp-mcp-ai' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => true,
				'show_in_menu'        => 'wp-mcp-ai',
				'query_var'           => false,
				'rewrite'             => false,
				'capability_type'     => 'post',
				'has_archive'         => false,
				'hierarchical'        => false,
				'menu_position'       => null,
				'supports'            => array( 'title', 'author' ),
				'show_in_rest'        => false,
			)
		);

		// Register Submission CPT.
		register_post_type(
			self::SUBMISSION_POST_TYPE,
			array(
				'labels'              => array(
					'name'               => _x( 'Quiz Submissions', 'post type general name', 'wp-mcp-ai' ),
					'singular_name'      => _x( 'Quiz Submission', 'post type singular name', 'wp-mcp-ai' ),
					'menu_name'          => _x( 'Submissions', 'admin menu', 'wp-mcp-ai' ),
					'name_admin_bar'     => _x( 'Submission', 'add new on admin bar', 'wp-mcp-ai' ),
					'add_new'            => _x( 'Add New', 'submission', 'wp-mcp-ai' ),
					'add_new_item'       => __( 'Add New Submission', 'wp-mcp-ai' ),
					'new_item'           => __( 'New Submission', 'wp-mcp-ai' ),
					'edit_item'          => __( 'Edit Submission', 'wp-mcp-ai' ),
					'view_item'          => __( 'View Submission', 'wp-mcp-ai' ),
					'all_items'          => __( 'All Submissions', 'wp-mcp-ai' ),
					'search_items'       => __( 'Search Submissions', 'wp-mcp-ai' ),
					'parent_item_colon'  => __( 'Parent Submissions:', 'wp-mcp-ai' ),
					'not_found'          => __( 'No submissions found.', 'wp-mcp-ai' ),
					'not_found_in_trash' => __( 'No submissions found in Trash.', 'wp-mcp-ai' ),
				),
				'description'         => __( 'User submissions for quizzes.', 'wp-mcp-ai' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => true,
				'show_in_menu'        => 'wp-mcp-ai',
				'query_var'           => false,
				'rewrite'             => false,
				'capability_type'     => 'post',
				'has_archive'         => false,
				'hierarchical'        => false,
				'menu_position'       => null,
				'supports'            => array( 'author' ),
				'show_in_rest'        => false,
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
			$description    = get_post_meta( $post_id, '_mcp_ai_quiz_description', true );
			$time_limit     = get_post_meta( $post_id, '_mcp_ai_quiz_time_limit', true );
			$questions      = get_post_meta( $post_id, '_mcp_ai_quiz_questions', true );
			$total_points   = get_post_meta( $post_id, '_mcp_ai_quiz_total_points', true );
			$passing_score  = get_post_meta( $post_id, '_mcp_ai_quiz_passing_score', true );

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
			$quiz_id        = get_post_meta( $post_id, '_mcp_ai_submission_quiz_id', true );
			$status         = get_post_meta( $post_id, '_mcp_ai_submission_status', true );
			$earned_points  = get_post_meta( $post_id, '_mcp_ai_submission_earned_points', true );
			$total_points   = get_post_meta( $post_id, '_mcp_ai_submission_total_points', true );
			$percentage     = get_post_meta( $post_id, '_mcp_ai_submission_percentage', true );
			$passed         = get_post_meta( $post_id, '_mcp_ai_submission_passed', true );
			$graded_by      = get_post_meta( $post_id, '_mcp_ai_submission_graded_by', true );

			// Map CPT data to CCT fields.
			$cct_data = array(
				'quiz_id'     => absint( $quiz_id ),
				'student_id'  => absint( $post->post_author ),
				'status'      => $status ? $status : 'pending',
				'cpt_post_id' => $post_id,
			);

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
	 * Handle post deletion to clean up CCT items.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function handle_post_deletion( $post_id, $post ) {
		if ( self::POST_TYPE === $post->post_type ) {
			self::delete_quiz_cct_item( $post_id );
		} elseif ( self::SUBMISSION_POST_TYPE === $post->post_type ) {
			self::delete_submission_cct_item( $post_id );
		}
	}
}

WP_MCP_AI_Quiz_CPT::init();
