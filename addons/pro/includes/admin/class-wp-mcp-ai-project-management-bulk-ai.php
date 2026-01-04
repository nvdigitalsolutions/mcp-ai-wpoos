<?php
/**
 * Bulk AI Operations for Project Management
 *
 * Provides bulk AI-enhanced operations on projects, tasks, and events.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles bulk AI operations for project management.
 */
class WP_MCP_AI_Project_Management_Bulk_AI {

	/**
	 * Initialize bulk AI operations.
	 */
	public static function init() {
		// Add bulk actions.
		add_filter( 'bulk_actions-edit-mcp_ai_project', array( __CLASS__, 'register_bulk_actions' ) );
		add_filter( 'bulk_actions-edit-mcp_ai_task', array( __CLASS__, 'register_bulk_actions' ) );
		add_filter( 'bulk_actions-edit-mcp_ai_event', array( __CLASS__, 'register_bulk_actions' ) );

		// Handle bulk actions.
		add_filter( 'handle_bulk_actions-edit-mcp_ai_project', array( __CLASS__, 'handle_bulk_actions' ), 10, 3 );
		add_filter( 'handle_bulk_actions-edit-mcp_ai_task', array( __CLASS__, 'handle_bulk_actions' ), 10, 3 );
		add_filter( 'handle_bulk_actions-edit-mcp_ai_event', array( __CLASS__, 'handle_bulk_actions' ), 10, 3 );

		// Display admin notices.
		add_action( 'admin_notices', array( __CLASS__, 'bulk_action_notices' ) );

		// Register AJAX handler for async processing.
		add_action( 'wp_ajax_wp_mcp_ai_pm_bulk_process', array( __CLASS__, 'ajax_bulk_process' ) );
	}

	/**
	 * Register bulk AI actions.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array Modified bulk actions.
	 */
	public static function register_bulk_actions( $actions ) {
		$actions['ai_generate_descriptions'] = __( '🤖 AI: Generate Descriptions', 'wp-mcp-ai' );
		$actions['ai_analyze']               = __( '🤖 AI: Analyze Selected', 'wp-mcp-ai' );
		$actions['ai_optimize']              = __( '🤖 AI: Optimize & Improve', 'wp-mcp-ai' );
		return $actions;
	}

	/**
	 * Handle bulk AI actions.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Action name.
	 * @param array  $post_ids    Selected post IDs.
	 * @return string Modified redirect URL.
	 */
	public static function handle_bulk_actions( $redirect_to, $action, $post_ids ) {
		if ( ! in_array( $action, array( 'ai_generate_descriptions', 'ai_analyze', 'ai_optimize' ), true ) ) {
			return $redirect_to;
		}

		if ( empty( $post_ids ) ) {
			return $redirect_to;
		}

		// Process items.
		$processed = 0;
		foreach ( $post_ids as $post_id ) {
			$result = self::process_single_item( $post_id, $action );
			if ( ! is_wp_error( $result ) ) {
				$processed++;
			}
		}

		// Add query args for notice.
		$redirect_to = add_query_arg(
			array(
				'bulk_ai_action' => $action,
				'processed'      => $processed,
				'total'          => count( $post_ids ),
			),
			$redirect_to
		);

		return $redirect_to;
	}

	/**
	 * Process a single item with AI.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $action  Action to perform.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private static function process_single_item( $post_id, $action ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'invalid_post', __( 'Invalid post.', 'wp-mcp-ai' ) );
		}

		switch ( $action ) {
			case 'ai_generate_descriptions':
				return self::generate_description( $post );

			case 'ai_analyze':
				return self::analyze_item( $post );

			case 'ai_optimize':
				return self::optimize_item( $post );

			default:
				return new WP_Error( 'invalid_action', __( 'Invalid action.', 'wp-mcp-ai' ) );
		}
	}

	/**
	 * Generate description for an item using AI.
	 *
	 * @param WP_Post $post The post object.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private static function generate_description( $post ) {
		// Skip if description already exists.
		if ( ! empty( $post->post_content ) ) {
			return true;
		}

		if ( ! class_exists( 'WP_MCP_AI_Assistant_Service' ) ) {
			return new WP_Error( 'service_unavailable', __( 'AI service unavailable.', 'wp-mcp-ai' ) );
		}

		$assistants = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'posts_per_page' => 1,
				'post_status'    => 'publish',
			)
		);

		if ( empty( $assistants ) ) {
			return new WP_Error( 'no_assistant', __( 'No assistant available.', 'wp-mcp-ai' ) );
		}

		$post_type_labels = array(
			'mcp_ai_project' => 'project',
			'mcp_ai_task'    => 'task',
			'mcp_ai_event'   => 'event',
		);
		$type_label       = isset( $post_type_labels[ $post->post_type ] ) ? $post_type_labels[ $post->post_type ] : 'item';

		$prompt = sprintf(
			'Generate a professional description (2-3 sentences) for this %s: "%s"',
			$type_label,
			$post->post_title
		);

		try {
			$assistant_service = WP_MCP_AI_Assistant_Service::get_instance();
			$response          = $assistant_service->chat(
				$assistants[0]->ID,
				$prompt,
				array( 'max_tokens' => 150 )
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$description = isset( $response['content'] ) ? trim( $response['content'] ) : '';

			if ( ! empty( $description ) ) {
				wp_update_post(
					array(
						'ID'           => $post->ID,
						'post_content' => $description,
					)
				);
			}

			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'ai_error', $e->getMessage() );
		}
	}

	/**
	 * Analyze an item using AI.
	 *
	 * @param WP_Post $post The post object.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private static function analyze_item( $post ) {
		// Store analysis as post meta.
		$analysis_key = '_ai_analysis_' . gmdate( 'Y-m-d' );

		// Skip if already analyzed today.
		if ( get_post_meta( $post->ID, $analysis_key, true ) ) {
			return true;
		}

		if ( ! class_exists( 'WP_MCP_AI_Assistant_Service' ) ) {
			return new WP_Error( 'service_unavailable', __( 'AI service unavailable.', 'wp-mcp-ai' ) );
		}

		$assistants = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'posts_per_page' => 1,
				'post_status'    => 'publish',
			)
		);

		if ( empty( $assistants ) ) {
			return new WP_Error( 'no_assistant', __( 'No assistant available.', 'wp-mcp-ai' ) );
		}

		$prompt = sprintf(
			'Briefly analyze this %s titled "%s" with description: "%s". Provide 1-2 actionable insights.',
			get_post_type( $post ),
			$post->post_title,
			wp_trim_words( $post->post_content, 50 )
		);

		try {
			$assistant_service = WP_MCP_AI_Assistant_Service::get_instance();
			$response          = $assistant_service->chat(
				$assistants[0]->ID,
				$prompt,
				array( 'max_tokens' => 200 )
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$analysis = isset( $response['content'] ) ? trim( $response['content'] ) : '';

			if ( ! empty( $analysis ) ) {
				update_post_meta( $post->ID, $analysis_key, $analysis );
			}

			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'ai_error', $e->getMessage() );
		}
	}

	/**
	 * Optimize an item using AI.
	 *
	 * @param WP_Post $post The post object.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private static function optimize_item( $post ) {
		if ( ! class_exists( 'WP_MCP_AI_Assistant_Service' ) ) {
			return new WP_Error( 'service_unavailable', __( 'AI service unavailable.', 'wp-mcp-ai' ) );
		}

		$assistants = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'posts_per_page' => 1,
				'post_status'    => 'publish',
			)
		);

		if ( empty( $assistants ) ) {
			return new WP_Error( 'no_assistant', __( 'No assistant available.', 'wp-mcp-ai' ) );
		}

		$prompt = sprintf(
			'Improve and optimize this %s title and description:\n\nTitle: "%s"\nDescription: "%s"\n\nProvide an improved version focusing on clarity, actionability, and professionalism. Format as: TITLE: [improved title]\nDESCRIPTION: [improved description]',
			get_post_type( $post ),
			$post->post_title,
			wp_trim_words( $post->post_content, 100 )
		);

		try {
			$assistant_service = WP_MCP_AI_Assistant_Service::get_instance();
			$response          = $assistant_service->chat(
				$assistants[0]->ID,
				$prompt,
				array( 'max_tokens' => 300 )
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$content = isset( $response['content'] ) ? trim( $response['content'] ) : '';

			// Parse improved content.
			if ( preg_match( '/TITLE:\s*(.+?)(?:\n|$)/i', $content, $title_match ) ) {
				$improved_title = trim( $title_match[1] );
			}

			if ( preg_match( '/DESCRIPTION:\s*(.+)/is', $content, $desc_match ) ) {
				$improved_description = trim( $desc_match[1] );
			}

			// Update post if improvements found.
			$update_data = array( 'ID' => $post->ID );

			if ( ! empty( $improved_title ) ) {
				$update_data['post_title'] = $improved_title;
			}

			if ( ! empty( $improved_description ) ) {
				$update_data['post_content'] = $improved_description;
			}

			if ( count( $update_data ) > 1 ) {
				wp_update_post( $update_data );
			}

			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'ai_error', $e->getMessage() );
		}
	}

	/**
	 * Display bulk action admin notices.
	 */
	public static function bulk_action_notices() {
		if ( empty( $_REQUEST['bulk_ai_action'] ) ) {
			return;
		}

		$action    = sanitize_key( $_REQUEST['bulk_ai_action'] );
		$processed = isset( $_REQUEST['processed'] ) ? absint( $_REQUEST['processed'] ) : 0;
		$total     = isset( $_REQUEST['total'] ) ? absint( $_REQUEST['total'] ) : 0;

		$action_labels = array(
			'ai_generate_descriptions' => __( 'descriptions generated', 'wp-mcp-ai' ),
			'ai_analyze'               => __( 'items analyzed', 'wp-mcp-ai' ),
			'ai_optimize'              => __( 'items optimized', 'wp-mcp-ai' ),
		);

		$action_label = isset( $action_labels[ $action ] ) ? $action_labels[ $action ] : __( 'items processed', 'wp-mcp-ai' );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			sprintf(
				/* translators: 1: number of items processed, 2: total items, 3: action label */
				esc_html__( '🤖 AI processed %1$d of %2$d items: %3$s', 'wp-mcp-ai' ),
				esc_html( $processed ),
				esc_html( $total ),
				esc_html( $action_label )
			)
		);
	}

	/**
	 * AJAX handler for async bulk processing.
	 */
	public static function ajax_bulk_process() {
		check_ajax_referer( 'wp_mcp_ai_pm_bulk', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-mcp-ai' ) ) );
		}

		$post_ids = isset( $_POST['post_ids'] ) ? array_map( 'absint', $_POST['post_ids'] ) : array();
		$action   = isset( $_POST['bulk_action'] ) ? sanitize_key( $_POST['bulk_action'] ) : '';

		if ( empty( $post_ids ) || empty( $action ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'wp-mcp-ai' ) ) );
		}

		$results = array(
			'processed' => 0,
			'failed'    => 0,
			'errors'    => array(),
		);

		foreach ( $post_ids as $post_id ) {
			$result = self::process_single_item( $post_id, $action );
			if ( is_wp_error( $result ) ) {
				$results['failed']++;
				$results['errors'][] = $result->get_error_message();
			} else {
				$results['processed']++;
			}
		}

		wp_send_json_success( $results );
	}
}
