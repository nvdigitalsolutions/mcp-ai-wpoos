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
	 * Initialize the class.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_types' ) );
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
}

WP_MCP_AI_Quiz_CPT::init();
